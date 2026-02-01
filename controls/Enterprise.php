<?php
/**
 * Enterprise Controller
 * Handles Enterprise tier features: AI Developer, GitHub integration, etc.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;
use \app\services\TierFeatures;
use \app\services\EncryptionService;
use \app\services\GitHubClient;
use \app\services\AIDevAgent;
use \app\services\AIDevJobManager;
use \app\services\RunnerService;
use \app\services\RunnerRouter;
use \app\services\LLMProviders\LLMProviderFactory;
use \app\services\ConnectionsService;
use \app\services\AnthropicKeyService;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/ConnectionsService.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/GitHubClient.php';
require_once __DIR__ . '/../services/AIDevAgent.php';
require_once __DIR__ . '/../services/AIDevJobManager.php';
require_once __DIR__ . '/../services/RunnerService.php';
require_once __DIR__ . '/../services/RunnerRouter.php';
use \app\Bean;

class Enterprise extends BaseControls\Control {

    /**
     * Check access - all features now available to logged-in users
     */
    private function requireEnterprise(): bool {
        // All features now available to all tiers
        return $this->requireLogin();
    }

    // ========================================
    // Dashboard & Settings
    // ========================================

    /**
     * Enterprise dashboard
     */
    public function index() {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;

        // Check setup status
        // Check API key (from anthropickeys table)
        $apiKeySet = AnthropicKeyService::hasApiKey($memberId);

        // Check GitHub connections (workspace-level - all members share repos)
        $githubCount = Bean::count('repoconnections', 'provider = ? AND enabled = ?', ['github', 1]);
        $githubConnected = $githubCount > 0;

        // Check credit balance errors (within last 24 hours)
        $creditSetting = Bean::findOne('enterprisesettings',
            'setting_key = ? AND (member_id = ? OR is_shared = 1) AND updated_at > ?',
            ['credit_balance_error', $memberId, date('Y-m-d H:i:s', strtotime('-24 hours'))]
        );
        $creditBalanceError = $creditSetting ? $creditSetting->setting_value : null;

        // Get boards (shared boards available to all workspace members)
        $boards = [];
        $boardBeans = Bean::findAll('boards', '(member_id = ? OR is_shared = 1) ORDER BY board_name ASC', [$memberId]);
        foreach ($boardBeans as $board) {
            $boards[] = [
                'id' => $board->id,
                'board_name' => $board->board_name,
                'project_key' => $board->project_key,
                'cloud_uid' => $board->cloud_uid
            ];
        }

        // Get Jira write scope status
        $sites = AtlassianAuth::getConnectedSites($memberId);
        $hasWriteScopes = false;
        foreach ($sites as $site) {
            if (AtlassianAuth::hasWriteScopes($memberId, $site->cloud_uid)) {
                $hasWriteScopes = true;
                break;
            }
        }

        // Get repo connections
                $repos = ConnectionsService::getRepositoryConnections();
        
        // Get recent jobs using AIDevJobManager
        $jobManager = new AIDevJobManager($memberId);
        $jobs = $jobManager->getAll(10);

        // Get active jobs
        $activeJobs = $jobManager->getActive();

        $this->render('enterprise/index', [
            'title' => 'AI Developer - Enterprise',
            'apiKeySet' => $apiKeySet,
            'githubConnected' => $githubConnected,
            'githubConfigured' => GitHubClient::isConfigured(),
            'hasWriteScopes' => $hasWriteScopes,
            'repos' => $repos,
            'boards' => $boards,
            'jobs' => $jobs,
            'activeJobs' => $activeJobs,
            'sites' => $sites,
            'creditBalanceError' => $creditBalanceError
        ]);
    }

    /**
     * Enterprise settings page
     */
    public function settings() {
        // Redirect to unified settings page
        Flight::redirect('/settings/connections');
    }

    /**
     * Re-register Jira webhook with correct workspace URL
     * POST /enterprise/reregisterwebhook
     */
    public function reregisterwebhook() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        $cloudId = $this->getParam('cloud_uid');
        if (empty($cloudId)) {
            // Try to get from member's first Atlassian token (or shared)
            $token = Bean::findOne('atlassiantoken', '(member_id = ? OR is_shared = 1)', [$this->member->id]);
            if ($token) {
                $cloudId = $token->cloud_uid;
            }
        }

        if (empty($cloudId)) {
            $this->json(['success' => false, 'error' => 'No Atlassian connection found']);
            return;
        }

        try {
            $result = AtlassianAuth::reregisterAIDevWebhook($this->member->id, $cloudId);

            if ($result) {
                $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
                $this->json([
                    'success' => true,
                    'message' => "Webhook re-registered with workspace: {$workspaceSlug}"
                ]);
            } else {
                $this->json(['success' => false, 'error' => 'Failed to re-register webhook']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Webhook re-registration failed', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Add a new API key
     */
    public function addkey() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/enterprise/settings');
            return;
        }

        if (!$this->validateCSRF()) return;

        $name = trim(Flight::request()->data->key_name ?? '');
        $apiKey = trim(Flight::request()->data->api_key ?? '');
        $model = Flight::request()->data->key_model ?? 'claude-sonnet-4-20250514';

        if (empty($name) || empty($apiKey)) {
            $this->flash('error', 'Name and API key are required.');
            Flight::redirect('/enterprise/settings');
            return;
        }

        if (!preg_match('/^sk-ant-/', $apiKey)) {
            $this->flash('error', 'Invalid API key format. Should start with sk-ant-');
            Flight::redirect('/enterprise/settings');
            return;
        }

                try {
            $encrypted = EncryptionService::encrypt($apiKey);

            $key = Bean::dispense('anthropickeys');
            $key->name = $name;
            $key->api_key = $encrypted;
            $key->model = $model;
            $key->created_at = date('Y-m-d H:i:s');
            Bean::store($key);

            $this->flash('success', 'API key added successfully.');
            $this->logger->info('Anthropic API key added', ['member_id' => $this->member->id, 'name' => $name]);

                    } catch (\Exception $e) {
                        $this->flash('error', 'Failed to save API key: ' . $e->getMessage());
        }

        Flight::redirect('/enterprise/settings');
    }

    /**
     * Delete an API key
     */
    public function deletekey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $this->opId() ?? null;
        if (!$keyId) {
            $this->flash('error', 'No key specified.');
            Flight::redirect('/enterprise/settings');
            return;
        }

                try {
            $key = Bean::load('anthropickeys', $keyId);
            if ($key && $key->id) {
                $keyName = $key->name;
                Bean::trash($key);

                // Reset any boards using this key to NULL (local runner)
                Bean::exec('UPDATE jiraboards SET aidev_anthropic_key_id = NULL WHERE aidev_anthropic_key_id = ?', [$keyId]);

                $this->flash('success', "API key '{$keyName}' deleted. Affected boards switched to local runner.");
                $this->logger->info('Anthropic API key deleted', ['member_id' => $this->member->id, 'key_id' => $keyId]);
            } else {
                $this->flash('error', 'Key not found.');
            }
                    } catch (\Exception $e) {
                        $this->flash('error', 'Failed to delete key: ' . $e->getMessage());
        }

        Flight::redirect('/enterprise/settings');
    }

    /**
     * Test an API key
     */
    public function testkey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = (int) ($this->opId() ?? 0);
        if (!$keyId) {
            Flight::json(['success' => false, 'error' => 'No key specified']);
            return;
        }

        $result = AnthropicKeyService::testKey($keyId);

        if (!$result['success']) {
            $this->logger->warning('Anthropic API key test failed', ['error' => $result['error']]);
        }

        Flight::json($result);
    }

    // ========================================
    // GitHub OAuth (consolidated to /github/*)
    // ========================================

    /**
     * Start GitHub OAuth flow - redirects to consolidated endpoint
     */
    public function github() {
        if (!$this->requireLogin()) return;

        // Store return URL so Github controller redirects back here after success
        $_SESSION['github_oauth_return'] = '/enterprise/repos';

        Flight::redirect('/github/connect');
    }

    /**
     * Alternate callback URL - redirects to consolidated endpoint
     */
    public function githubcallback() {
        // Forward query params to the consolidated callback
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $redirectUrl = '/github/callback' . ($query ? '?' . $query : '');
        Flight::redirect($redirectUrl);
    }

    // ========================================
    // Repository Management
    // ========================================

    /**
     * List repository connections
     */
    public function repos() {
        if (!$this->requireEnterprise()) return;

                $repos = ConnectionsService::getRepositoryConnections();

        // Get boards for mapping using static method
        $boards = [];
        $boardBeans = Bean::findAll('boards', ' ORDER BY board_name ASC ');
        foreach ($boardBeans as $bean) {
            $boards[] = [
                'id' => $bean->id,
                'board_name' => $bean->board_name,
                'project_key' => $bean->project_key,
                'cloud_uid' => $bean->cloud_uid
            ];
        }
        try {
            // Get board-repo mappings
            $mappings = [];
            $mappingBeans = Bean::findAll('boardrepomapping');
            foreach ($mappingBeans as $bean) {
                $boardId = $bean->boards_id;
                $repoId = $bean->repoconnections_id;

                if (!$boardId || !$repoId) continue; // Skip invalid mappings

                // Dedupe: skip if this exact mapping already exists
                $isDupe = false;
                if (isset($mappings[$boardId])) {
                    foreach ($mappings[$boardId] as $existing) {
                        if ($existing['repoconnections_id'] == $repoId) {
                            $isDupe = true;
                            break;
                        }
                    }
                }
                if ($isDupe) continue;

                $mappings[$boardId][] = [
                    'id' => $bean->id,
                    'boards_id' => $boardId,
                    'repoconnections_id' => $repoId,
                    'is_default' => $bean->is_default,
                    'created_at' => $bean->created_at
                ];
            }

            // Check if GitHub is connected
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            $githubToken = $tokenSetting ? $tokenSetting->setting_value : null;

            
            // Get available repos from GitHub if connected
            $availableRepos = [];
            if (!empty($githubToken)) {
                try {
                    $token = EncryptionService::decrypt($githubToken);
                    $github = new GitHubClient($token);
                    $availableRepos = $github->listRepositories('all', 'updated', 50);
                } catch (Exception $e) {
                    $this->logger->warning('Could not fetch GitHub repos', ['error' => $e->getMessage()]);
                }
            }

            // Get agents for dropdown (workspace-level - all members share agents)
            $agents = [];
            $agentBeans = Bean::findAll('aiagents', 'is_active = 1 ORDER BY name ASC');
            foreach ($agentBeans as $agentBean) {
                $agents[] = [
                    'id' => $agentBean->id,
                    'name' => $agentBean->name,
                    'provider' => $agentBean->provider ?: 'claude_cli',
                    'provider_label' => LLMProviderFactory::getProviderLabel($agentBean->provider),
                    'is_default' => (bool) $agentBean->is_default
                ];
            }

            $this->render('enterprise/repos', [
                'title' => 'Repository Connections',
                'repos' => $repos,
                'boards' => $boards,
                'mappings' => $mappings,
                'availableRepos' => $availableRepos,
                'githubConnected' => !empty($githubToken),
                'agents' => $agents
            ]);
        } catch (\Exception $e) {
                        throw $e;
        }
    }

    /**
     * Connect a repository
     */
    public function connectrepo() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/enterprise/repos');
            return;
        }

        if (!$this->validateCSRF()) return;

        $provider = Flight::request()->data->provider ?? 'github';
        $repoFullName = Flight::request()->data->repo ?? '';

        if (empty($repoFullName)) {
            $this->flash('error', 'Please select a repository.');
            Flight::redirect('/enterprise/repos');
            return;
        }

        try {
            
            // Get the token
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);

            if (!$tokenSetting || empty($tokenSetting->setting_value)) {
                                $this->flash('error', 'GitHub is not connected.');
                Flight::redirect('/enterprise/repos');
                return;
            }

            $token = EncryptionService::decrypt($tokenSetting->setting_value);
            $github = new GitHubClient($token);

            // Parse repo full name (owner/repo)
            list($owner, $repoName) = explode('/', $repoFullName, 2);

            // Get repo details
            $repoDetails = $github->getRepository($owner, $repoName);

            // Encrypt the token for storage
            $encryptedToken = EncryptionService::encrypt($token);

            // Find or create repo connection (workspace-level - track who created it)
            $repo = Bean::findOne('repoconnections', 'provider = ? AND repo_owner = ? AND repo_name = ?',
                [$provider, $owner, $repoName]);
            if (!$repo) {
                $repo = Bean::dispense('repoconnections');
                $repo->created_by_member_id = $this->member->id;
                $repo->created_by_name = $this->member->display_name ?? $this->member->email;
                $repo->created_at = date('Y-m-d H:i:s');
            }
            $repo->provider = $provider;
            $repo->repo_owner = $owner;
            $repo->repo_name = $repoName;
            $repo->default_branch = $repoDetails['default_branch'] ?? 'main';
            $repo->clone_url = $repoDetails['clone_url'];
            $repo->access_token = $encryptedToken;
            $repo->enabled = 1;
            $repo->updated_at = date('Y-m-d H:i:s');

            // Generate webhook secret and try to create webhook on GitHub
            $webhookSecret = bin2hex(random_bytes(20));
            $repo->webhook_secret = $webhookSecret;

            $webhookCreated = false;
            $webhookError = null;
            try {
                $baseUrl = rtrim(Flight::get('app.baseurl') ?? 'https://myctobot.ai', '/');
                $webhookUrl = $baseUrl . '/webhook/github';

                // Check if webhook already exists
                $existingHook = $github->findWebhook($owner, $repoName, $webhookUrl);
                if ($existingHook) {
                    $repo->webhook_uid = $existingHook['id'];
                    $webhookCreated = true;
                } else {
                    // Create webhook
                    $hook = $github->createWebhook($owner, $repoName, $webhookUrl, $webhookSecret);
                    $repo->webhook_uid = $hook['id'];
                    $webhookCreated = true;
                }

                // Ensure ai-dev label exists
                $github->ensureAiDevLabel($owner, $repoName);
            } catch (\Exception $e) {
                $webhookError = $e->getMessage();
                $this->logger->warning('Failed to create webhook', [
                    'repo' => $repoFullName,
                    'error' => $webhookError
                ]);
            }

            Bean::store($repo);

            
            $message = "Repository {$repoFullName} connected successfully!";
            if (!$webhookCreated) {
                $message .= " (Webhook setup failed - you may need to set it up manually)";
            }
            $this->flash('success', $message);
            $this->logger->info('Repository connected', [
                'member_id' => $this->member->id,
                'repo' => $repoFullName
            ]);

        } catch (Exception $e) {
                        $this->logger->error('Failed to connect repository', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to connect repository: ' . $e->getMessage());
        }

        Flight::redirect('/enterprise/repos');
    }

    /**
     * Disconnect a repository
     *
     * Uses RedBeanPHP associations:
     * - repoconnections owns boardrepomapping (repo->xownBoardrepomappingList for cascade delete)
     */
    public function disconnectrepo($params = []) {
        if (!$this->requireEnterprise()) return;

        $repoId = $this->opId() ?? 0;
        if (empty($repoId)) {
            Flight::redirect('/enterprise/repos');
            return;
        }

        try {
            
            // Load repo and use xownBoardrepomappingList for cascade delete
            $repo = Bean::load('repoconnections', (int)$repoId);
            if ($repo->id) {
                // Using xown prefix ensures cascade delete of related mappings
                $repo->xownBoardrepomappingList = [];
                Bean::store($repo);
                Bean::trash($repo);
            }

            
            $this->flash('success', 'Repository disconnected.');
            $this->logger->info('Repository disconnected', [
                'member_id' => $this->member->id,
                'repo_id' => $repoId
            ]);

        } catch (Exception $e) {
                        $this->logger->error('Failed to disconnect repository', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to disconnect repository.');
        }

        Flight::redirect('/enterprise/repos');
    }

    /**
     * Map a board to a repository
     *
     * Uses RedBeanPHP associations:
     * - jiraboards owns boardrepomapping (board->ownBoardrepomappingList)
     * - boardrepomapping belongs to repoconnections (mapping->repoconnections)
     *
     * Note: boardrepomapping is a link table with extra field (is_default),
     * so we use explicit handling but still leverage associations for FK management.
     */
    public function mapboard() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/enterprise/repos');
            return;
        }

        if (!$this->validateCSRF()) return;

        $boardId = (int)(Flight::request()->data->board_id ?? 0);
        $repoId = (int)(Flight::request()->data->repo_id ?? 0);
        $isDefault = Flight::request()->data->is_default ? 1 : 0;

        if (empty($boardId) || empty($repoId)) {
            $this->flash('error', 'Invalid board or repository.');
            Flight::redirect('/enterprise/repos');
            return;
        }

        try {
            
            // Load parent beans for associations
            $board = Bean::load('boards', $boardId);
            $repo = Bean::load('repoconnections', $repoId);

            // If setting as default, clear other defaults for this board via association
            if ($isDefault) {
                foreach ($board->ownBoardrepomappingList as $existingMapping) {
                    $existingMapping->is_default = 0;
                }
            }

            // Check if mapping already exists in board's mappings
            $mapping = null;
            foreach ($board->ownBoardrepomappingList as $existingMapping) {
                if ($existingMapping->repoconnections_id == $repoId) {
                    $mapping = $existingMapping;
                    break;
                }
            }

            if (!$mapping) {
                // Create new mapping via association
                $mapping = Bean::dispense('boardrepomapping');
                $mapping->created_at = date('Y-m-d H:i:s');
                $mapping->repoconnections = $repo;  // Sets repoconnections_id automatically
                $board->ownBoardrepomappingList[] = $mapping;  // Sets boards_id automatically
            }

            $mapping->is_default = $isDefault;
            Bean::store($board);

                        $this->flash('success', 'Board mapped to repository successfully.');

        } catch (Exception $e) {
                        $this->logger->error('Failed to map board', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to map board to repository.');
        }

        Flight::redirect('/enterprise/repos');
    }

    /**
     * Remove a board-to-repository mapping
     */
    public function unmapboard() {
        if (!$this->requireEnterprise()) return;

        $boardId = (int)$this->getParam('board_id');
        $repoId = (int)$this->getParam('repo_id');

        if (empty($boardId) || empty($repoId)) {
            $this->flash('error', 'Invalid board or repository.');
            Flight::redirect('/enterprise/repos');
            return;
        }

        try {
            
            // Find and delete the mapping
            $mapping = Bean::findOne('boardrepomapping',
                'boards_id = ? AND repoconnections_id = ?',
                [$boardId, $repoId]
            );

            if ($mapping) {
                Bean::trash($mapping);
                $this->flash('success', 'Repository mapping removed.');
            } else {
                $this->flash('warning', 'Mapping not found.');
            }

            
        } catch (Exception $e) {
                        $this->logger->error('Failed to unmap board', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to remove mapping.');
        }

        Flight::redirect('/enterprise/repos');
    }

    // ========================================
    // Jobs Management
    // ========================================

    /**
     * View a single AI Developer job detail - redirects to Jobs controller
     */
    public function job($params = []) {
        $issueKey = $this->opId() ?? '';
        Flight::redirect('/jobs/view/' . urlencode($issueKey));
    }

    /**
     * List AI Developer jobs - redirects to Jobs controller
     */
    public function jobs() {
        Flight::redirect('/jobs');
    }

    /**
     * Start a new AI Developer job
     */
    public function startjob($params = []) {
        // Redirect to runner implementation which uses Claude Code CLI
        return $this->startrunner($params);
    }

    /**
     * Start a new AI Developer job on a runner (Claude Code CLI)
     */
    public function startrunner($params = []) {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        $issueKey = Flight::request()->data->issue_key ?? '';
        $boardId = (int)(Flight::request()->data->board_id ?? 0);
        $repoId = (int)(Flight::request()->data->repo_id ?? 0);
        $cloudId = Flight::request()->data->cloud_uid ?? '';
        $useOrchestrator = !empty(Flight::request()->data->use_orchestrator);

        if (empty($issueKey) || empty($boardId) || empty($repoId) || empty($cloudId)) {
            $this->json(['success' => false, 'error' => 'Missing required parameters']);
            return;
        }

        try {
            
            // Get Anthropic API key from anthropickeys table
            $apiKey = AnthropicKeyService::getApiKey($this->member->id);

            if (!$apiKey) {
                                $this->json(['success' => false, 'error' => 'Anthropic API key not configured. Add one at /anthropic/keys']);
                return;
            }

            // Find an available runner
            $runner = RunnerRouter::findAvailableRunner($this->member->id, ['git', 'filesystem']);

            if (!$runner) {
                                $this->json(['success' => false, 'error' => 'No available runners. Please try again later.']);
                return;
            }

            // Get repository details
            $repoBean = Bean::load('repoconnections', (int)$repoId);

            if (!$repoBean->id) {
                                $this->json(['success' => false, 'error' => 'Repository not found']);
                return;
            }

            $repoToken = EncryptionService::decrypt($repoBean->access_token);

            // Store repo details for payload
            $repoResult = [
                'repo_owner' => $repoBean->repo_owner,
                'repo_name' => $repoBean->repo_name,
                'default_branch' => $repoBean->default_branch ?? 'main',
                'clone_url' => $repoBean->clone_url
            ];

            
            // Get issue details from Jira
            $jiraClient = new \app\services\JiraClient($this->member->id, $cloudId);
            $issue = $jiraClient->getIssue($issueKey);

            $summary = $issue['fields']['summary'] ?? '';
            $description = \app\services\JiraClient::extractTextFromAdf($issue['fields']['description'] ?? null);
            $issueType = $issue['fields']['issuetype']['name'] ?? 'Task';
            $priority = $issue['fields']['priority']['name'] ?? 'Medium';

            // Get comments
            $comments = $issue['fields']['comment']['comments'] ?? [];
            $commentText = '';
            foreach (array_slice($comments, -10) as $comment) {
                $commentText .= \app\services\JiraClient::extractTextFromAdf($comment['body']) . "\n\n";
            }

            // Get attachment info
            $attachments = $issue['fields']['attachment'] ?? [];
            $attachmentInfo = '';
            if (!empty($attachments)) {
                $attachmentInfo = "## Attachments\n";
                foreach ($attachments as $att) {
                    $attachmentInfo .= "- {$att['filename']} ({$att['mimeType']}, {$att['size']} bytes)\n";
                    $attachmentInfo .= "  Download: {$att['content']}\n";
                }
            }

            // Extract URLs from description and comments
            $urlsToCheck = $this->extractUrls($description . ' ' . $commentText);

            // Get Jira credentials
            $jiraCreds = RunnerRouter::getMemberMcpCredentials($this->member->id);
            $jiraHost = $jiraCreds['jira_host'] ?? '';
            $jiraEmail = $jiraCreds['jira_email'] ?? '';
            $jiraToken = $jiraCreds['jira_api_token'] ?? '';
            $jiraSiteUrl = $jiraCreds['jira_site_url'] ?? '';

            // Create or get job using AIDevJobManager
            $jobManager = new AIDevJobManager($this->member->id);
            $job = $jobManager->getOrCreate($issueKey, $boardId, $repoId, $cloudId);

            // Generate runner job ID (this will be stored when job starts running)
            $runnerJobId = md5(uniqid($issueKey . '_' . microtime(true), true));

            // Build payload for runner
            $payload = [
                'anthropic_api_key' => $apiKey,
                'job_uid' => $runnerJobId,
                'issue_key' => $issueKey,
                'issue_data' => [
                    'summary' => $summary,
                    'description' => $description,
                    'type' => $issueType,
                    'priority' => $priority,
                    'comments' => $commentText,
                    'attachment_info' => $attachmentInfo,
                    'urls_to_check' => $urlsToCheck
                ],
                'repo_config' => [
                    'repo_owner' => $repoResult['repo_owner'],
                    'repo_name' => $repoResult['repo_name'],
                    'default_branch' => $repoResult['default_branch'] ?? 'main',
                    'clone_url' => $repoResult['clone_url']
                ],
                'jira_host' => $jiraHost,
                'jira_email' => $jiraEmail,
                'jira_api_token' => $jiraToken,
                'jira_site_url' => $jiraSiteUrl,
                'github_token' => $repoToken,
                'callback_url' => Flight::get('baseurl') . '/webhook/aidev',
                'callback_api_key' => Flight::get('cron.api_key'),
                'action' => 'implement',
                'use_orchestrator' => $useOrchestrator
            ];

            // Call runner endpoint
            $runnerPort = $runner['port'];
            $runnerProtocol = ($runnerPort == 443 || !empty($runner['ssl'])) ? 'https' : 'http';
            $runnerUrl = "{$runnerProtocol}://{$runner['host']}:{$runnerPort}/analysis/runneraidev";

            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'verify' => false
            ]);

            $response = $client->post($runnerUrl, [
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 202) {
                throw new \Exception('Runner returned non-202 status: ' . $response->getStatusCode());
            }

            // Mark job as running with the runner job ID
            $jobManager->startRun($issueKey, $runnerJobId);

            $this->logger->info('AI Developer runner job started (Claude Code CLI)', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'runner_job_uid' => $runnerJobId,
                'runner_id' => $runner['id'],
                'runner_name' => $runner['name'] ?? $runner['host'],
                'use_orchestrator' => $useOrchestrator
            ]);

            $this->json([
                'success' => true,
                'issue_key' => $issueKey,
                'runner_job_uid' => $runnerJobId,
                'runner' => $runner['name'] ?? $runner['host'],
                'message' => $useOrchestrator ? 'Job started with agent orchestrator' : 'Job started on runner with Claude Code CLI',
                'use_orchestrator' => $useOrchestrator
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to start runner job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Extract URLs from text
     */
    private function extractUrls(string $text): array {
        $urls = [];
        // Match http/https URLs
        if (preg_match_all('#https?://[^\s<>"\')\]]+#i', $text, $matches)) {
            $urls = array_unique($matches[0]);
            // Filter out Jira/Atlassian internal URLs
            $urls = array_filter($urls, function($url) {
                return !preg_match('#atlassian\.net|atlassian\.com#i', $url);
            });
        }
        return array_values($urls);
    }

    /**
     * Callback endpoint for runner job completion
     */
    public function runnercallback($params = []) {
        // This endpoint receives callbacks from runners when jobs complete
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || empty($data['job_uid'])) {
            $this->json(['success' => false, 'error' => 'Invalid callback data']);
            return;
        }

        $jobId = $data['job_uid'];
        $status = $data['status'] ?? 'unknown';

        if ($status === 'completed') {
            RunnerRouter::updateJobResult($jobId, $data['result'] ?? []);
            $this->logger->info('Runner job completed', ['job_uid' => $jobId]);
        } elseif ($status === 'failed') {
            RunnerRouter::updateJobStatus($jobId, 'failed', $data['error'] ?? 'Unknown error');
            $this->logger->error('Runner job failed', ['job_uid' => $jobId, 'error' => $data['error'] ?? '']);
        }

        $this->json(['success' => true]);
    }

    /**
     * Get runner job status
     */
    public function runnerjobstatus($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $this->opId() ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        $status = RunnerRouter::getJobStatus($jobId);

        if (!$status) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        // Verify ownership
        if ($status['member_id'] != $this->member->id) {
            $this->json(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $this->json([
            'success' => true,
            'status' => $status
        ]);
    }

    /**
     * Get runner job output
     */
    public function runnerjoboutput($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $this->opId() ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        // Verify ownership first
        $job = RunnerRouter::getJobStatus($jobId);
        if (!$job || $job['member_id'] != $this->member->id) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $output = RunnerRouter::getJobOutput($jobId);

        $this->json([
            'success' => true,
            'output' => $output
        ]);
    }

    /**
     * List available runners for the current user
     */
    public function runners($params = []) {
        if (!$this->requireEnterprise()) return;

        // Get runners available to this member
        $memberRunners = RunnerService::getMemberRunners($this->member->id);
        if (empty($memberRunners)) {
            $memberRunners = RunnerService::getDefaultRunners();
        }

        $this->json([
            'success' => true,
            'runners' => RunnerService::enrichRunners($memberRunners)
        ]);
    }

    /**
     * Get job status (AJAX)
     * Accepts issue_key as the identifier
     */
    public function jobstatus($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $this->opId() ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        // Use AIDevStatusService for JSON-based job tracking
        // findAllJobsByIssueKey returns all jobs (including completed) sorted by updated_at DESC
        require_once __DIR__ . '/../services/AIDevStatusService.php';
        $jobs = \app\services\AIDevStatusService::findAllJobsByIssueKey($this->member->id, $issueKey);
        $job = $jobs[0] ?? null;  // Get the most recent job

        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $this->json([
            'success' => true,
            'status' => $job
        ]);
    }

    /**
     * Get job logs (AJAX)
     * Accepts issue_key as the identifier
     */
    public function joblogs($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $this->opId() ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        // Use AIDevStatusService for JSON-based job tracking
        // findAllJobsByIssueKey returns all jobs (including completed) sorted by updated_at DESC
        require_once __DIR__ . '/../services/AIDevStatusService.php';
        $jobs = \app\services\AIDevStatusService::findAllJobsByIssueKey($this->member->id, $issueKey);
        $job = $jobs[0] ?? null;  // Get the most recent job

        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        // Convert steps_completed to log format
        $logs = [];
        foreach ($job['steps_completed'] ?? [] as $step) {
            $logs[] = [
                'level' => 'info',
                'message' => $step['step'],
                'context' => ['progress' => $step['progress'] ?? 0],
                'created_at' => $step['timestamp'] ?? null
            ];
        }

        // Add error if present
        if (!empty($job['error'])) {
            $logs[] = [
                'level' => 'error',
                'message' => $job['error'],
                'context' => null,
                'created_at' => $job['updated_at'] ?? null
            ];
        }

        $this->json([
            'success' => true,
            'logs' => $logs,
            'job' => $job
        ]);
    }

    /**
     * Resume a job after clarification
     * Accepts issue_key as the identifier
     */
    public function resumejob($params = []) {
        if (!$this->requireEnterprise()) return;

        $issueKey = $this->opId() ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        if ($job->status !== AIDevJobManager::STATUS_WAITING_CLARIFICATION) {
            $this->json(['success' => false, 'error' => 'Job is not waiting for clarification']);
            return;
        }

        try {
            // Start background process
            $cronSecret = Flight::get('cron.api_key');
            $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

            // Get workspace slug for multi-tenancy support
            $workspaceSlug = $_SESSION['workspace_slug'] ?? null;
            $workspaceParam = $workspaceSlug && $workspaceSlug !== 'default'
                ? sprintf(' --workspace=%s', escapeshellarg($workspaceSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --job=%s --issue=%s --action=resume%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($job->id),
                escapeshellarg($issueKey),
                $workspaceParam
            );

            exec($cmd);

            $this->json([
                'success' => true,
                'message' => 'Job resumed'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to resume job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Retry a completed/failed job on its existing branch
     * Accepts issue_key as the identifier
     */
    public function retryjob($params = []) {
        if (!$this->requireEnterprise()) return;

        $issueKey = $this->opId() ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        // Must have a branch to retry on
        if (empty($job->branchName)) {
            $this->json(['success' => false, 'error' => 'No branch found for this job. Cannot retry.']);
            return;
        }

        // Can only retry completed, pr_created, or failed jobs
        if (!in_array($job->status, [
            AIDevJobManager::STATUS_COMPLETE,
            AIDevJobManager::STATUS_PR_CREATED,
            AIDevJobManager::STATUS_FAILED
        ])) {
            $this->json(['success' => false, 'error' => 'Can only retry completed, pr_created, or failed jobs']);
            return;
        }

        try {
            
            // Get API key from anthropickeys table
            $apiKey = AnthropicKeyService::getApiKey($this->member->id);

            if (!$apiKey) {
                                $this->json(['success' => false, 'error' => 'Anthropic API key not configured. Add one at /anthropic/keys']);
                return;
            }

            // Get cloud_uid from the job or look up from board
            $cloudId = $job->cloudId;
            if (empty($cloudId)) {
                $board = Bean::load('boards', (int)$job->boards_id);
                $cloudId = $board->cloud_uid ?? null;
            }

            
            if (empty($cloudId)) {
                $this->json(['success' => false, 'error' => 'Could not determine Atlassian Cloud ID for this job']);
                return;
            }

            // Generate a new runner job ID for the retry run
            $runnerJobId = md5(uniqid($issueKey . '_retry_' . microtime(true), true));

            // Start background process
            $cronSecret = Flight::get('cron.api_key');
            $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

            // Get workspace slug for multi-tenancy support
            $workspaceSlug = $_SESSION['workspace_slug'] ?? null;
            $workspaceParam = $workspaceSlug && $workspaceSlug !== 'default'
                ? sprintf(' --workspace=%s', escapeshellarg($workspaceSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --job=%s --issue=%s --action=retry --branch=%s --pr=%d%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($runnerJobId),
                escapeshellarg($issueKey),
                escapeshellarg($job->branchName),
                $job->prNumber ?? 0,
                $workspaceParam
            );

            exec($cmd);

            $this->logger->info('AI Developer retry job started', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'branch' => $job->branchName,
                'workspace' => $workspaceSlug ?? 'default'
            ]);

            $this->json([
                'success' => true,
                'issue_key' => $issueKey,
                'message' => 'Retry job started on branch: ' . $job->branchName
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retry job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mark a job as complete (Jira ticket is done)
     * Accepts issue_key as the identifier
     */
    public function completejob($params = []) {
        if (!$this->requireEnterprise()) return;

        $issueKey = $this->opId() ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        // Can only complete pr_created jobs
        if ($job->status !== AIDevJobManager::STATUS_PR_CREATED) {
            $this->json(['success' => false, 'error' => 'Can only mark pr_created jobs as complete']);
            return;
        }

        try {
            $jobManager->markComplete($issueKey);

            $this->logger->info('AI Developer job marked complete', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey
            ]);

            $this->json([
                'success' => true,
                'message' => 'Job marked as complete'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to complete job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ========================================
    // Scope Upgrade
    // ========================================

    /**
     * Upgrade Atlassian scopes to include write access
     */
    public function upgradescopes() {
        if (!$this->requireEnterprise()) return;

        $loginUrl = AtlassianAuth::getLoginUrlWithWriteScopes();
        Flight::redirect($loginUrl);
    }

    // ========================================
    // Agent Assignment
    // ========================================

    /**
     * Assign an agent to a repository
     */
    public function assignagent() {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;

        // Get JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        $repoId = (int) ($input['repo_id'] ?? 0);
        $agentId = $input['agent_id'] ? (int) $input['agent_id'] : null;

        if (!$repoId) {
            Flight::jsonError('Repository ID required', 400);
            return;
        }

        // repoconnections is in user SQLite database
        
        // Verify repo exists (no member_id filter - database is already per-workspace)
        $repo = Bean::findOne('repoconnections', 'id = ?', [$repoId]);
        if (!$repo) {
                        Flight::jsonError('Repository not found', 404);
            return;
        }

        // If agent_id provided, verify it exists (workspace-level - all members share agents)
        if ($agentId) {
            $agent = Bean::findOne('aiagents', 'id = ?', [$agentId]);
            if (!$agent) {
                                Flight::jsonError('Agent not found', 404);
                return;
            }
        }

        // Update repo using association (creates aiagents_id FK)
        if ($agentId) {
            $repo->aiagents = $agent;
        } else {
            $repo->aiagents_id = null;
        }
        $repo->updated_at = date('Y-m-d H:i:s');
        Bean::store($repo);

                Flight::jsonSuccess(['message' => 'Agent assigned successfully']);
    }
}
