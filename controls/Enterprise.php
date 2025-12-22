<?php
/**
 * Enterprise Controller
 * Handles Enterprise tier features: AI Developer, GitHub integration, etc.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;
use \app\services\TierFeatures;
use \app\services\EncryptionService;
use \app\services\GitHubClient;
use \app\services\AIDevAgent;
use \app\services\AIDevJobManager;
use \app\services\UserDatabase;
use \app\services\UserDatabaseService;
use \app\services\ShardService;
use \app\services\ShardRouter;

require_once __DIR__ . '/../lib/Bean.php';
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/GitHubClient.php';
require_once __DIR__ . '/../services/AIDevAgent.php';
require_once __DIR__ . '/../services/AIDevJobManager.php';
require_once __DIR__ . '/../services/UserDatabase.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/ShardService.php';
require_once __DIR__ . '/../services/ShardRouter.php';

class Enterprise extends BaseControls\Control {

    /**
     * Check Enterprise tier access
     */
    private function requireEnterprise(): bool {
        if (!$this->requireLogin()) return false;

        // Use the model's getTier() method which fetches from subscription table
        $tier = $this->member->getTier();
        if (!TierFeatures::hasFeature($tier, TierFeatures::FEATURE_AI_DEVELOPER)) {
            $this->flash('error', 'This feature requires an Enterprise subscription.');
            Flight::redirect('/settings/subscription');
            return false;
        }

        return true;
    }

    /**
     * Connect to user's SQLite database via RedBean
     * Call this at start of method, then use Bean:: methods directly
     */
    private function connectUserDb(): void {
        UserDatabase::connect($this->member->id);
    }

    /**
     * Disconnect from user database (return to default)
     */
    private function disconnectUserDb(): void {
        UserDatabase::disconnect();
    }

    // ========================================
    // Dashboard & Settings
    // ========================================

    /**
     * Enterprise dashboard
     */
    public function index() {
        if (!$this->requireEnterprise()) return;

        // Use RedBean for user database queries via UserDatabase service
        $memberId = $this->member->id;

        // Check setup status using RedBean
        $apiKeySet = false;
        $githubConnected = false;
        $creditBalanceError = null;
        $boards = [];

        UserDatabase::with($memberId, function() use (&$apiKeySet, &$githubConnected, &$creditBalanceError, &$boards) {
            // Check API key
            $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);
            $apiKeySet = $apiKeySetting && !empty($apiKeySetting->setting_value);

            // Check GitHub connections
            $githubCount = Bean::count('repoconnections', 'provider = ? AND enabled = ?', ['github', 1]);
            $githubConnected = $githubCount > 0;

            // Check credit balance errors (within last 24 hours)
            $creditSetting = Bean::findOne('enterprisesettings',
                'setting_key = ? AND updated_at > ?',
                ['credit_balance_error', date('Y-m-d H:i:s', strtotime('-24 hours'))]
            );
            if ($creditSetting) {
                $creditBalanceError = $creditSetting->setting_value;
            }

            // Get boards
            $boardBeans = Bean::findAll('jiraboards', 'ORDER BY board_name ASC');
            foreach ($boardBeans as $board) {
                $boards[] = [
                    'id' => $board->id,
                    'board_name' => $board->board_name,
                    'project_key' => $board->project_key,
                    'cloud_id' => $board->cloud_id
                ];
            }
        });

        // Get Jira write scope status
        $sites = AtlassianAuth::getConnectedSites($memberId);
        $hasWriteScopes = false;
        foreach ($sites as $site) {
            if (AtlassianAuth::hasWriteScopes($memberId, $site->cloud_id)) {
                $hasWriteScopes = true;
                break;
            }
        }

        // Get repo connections
        $repos = $this->getRepoConnections();

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
        if (!$this->requireEnterprise()) return;

        $this->connectUserDb();
        try {
            // Handle form submission
            if (Flight::request()->method === 'POST') {
                if (!$this->validateCSRF()) {
                    $this->disconnectUserDb();
                    return;
                }

                $apiKey = Flight::request()->data->anthropic_api_key ?? '';

                if (!empty($apiKey)) {
                    // Validate API key format
                    if (!preg_match('/^sk-ant-/', $apiKey)) {
                        $this->flash('error', 'Invalid API key format. Should start with sk-ant-');
                        $this->disconnectUserDb();
                        Flight::redirect('/enterprise/settings');
                        return;
                    }

                    // Encrypt and store
                    $encrypted = EncryptionService::encrypt($apiKey);

                    // Find or create setting bean
                    $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);
                    if (!$setting) {
                        $setting = Bean::dispense('enterprisesettings');
                        $setting->setting_key = 'anthropic_api_key';
                    }
                    $setting->setting_value = $encrypted;
                    $setting->is_encrypted = 1;
                    $setting->updated_at = date('Y-m-d H:i:s');
                    Bean::store($setting);

                    $this->flash('success', 'API key saved successfully.');
                    $this->logger->info('Enterprise API key updated', ['member_id' => $this->member->id]);
                }

                $this->disconnectUserDb();
                Flight::redirect('/enterprise/settings');
                return;
            }

            // Check if API key is set
            $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);
            $apiKeySet = $apiKeySetting && !empty($apiKeySetting->setting_value);

            $this->disconnectUserDb();
            $this->render('enterprise/settings', [
                'title' => 'Enterprise Settings',
                'apiKeySet' => $apiKeySet,
                'githubConfigured' => GitHubClient::isConfigured()
            ]);
        } catch (\Exception $e) {
            $this->disconnectUserDb();
            throw $e;
        }
    }

    /**
     * Test API key (AJAX)
     */
    public function testkey() {
        if (!$this->requireEnterprise()) return;

        $this->connectUserDb();
        try {
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);

            if (!$setting || empty($setting->setting_value)) {
                $this->disconnectUserDb();
                $this->json(['success' => false, 'error' => 'No API key configured']);
                return;
            }

            $apiKey = EncryptionService::decrypt($setting->setting_value);
            $this->disconnectUserDb();

            // Test the key with a simple request
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.anthropic.com',
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->post('/v1/messages', [
                'json' => [
                    'model' => 'claude-sonnet-4-20250514',
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hello']]
                ]
            ]);

            $this->json(['success' => true, 'message' => 'API key is valid']);

        } catch (Exception $e) {
            $this->disconnectUserDb();
            $this->logger->warning('API key test failed', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => 'API key validation failed: ' . $e->getMessage()]);
        }
    }

    // ========================================
    // GitHub OAuth
    // ========================================

    /**
     * Start GitHub OAuth flow
     */
    public function github() {
        if (!$this->requireEnterprise()) return;

        if (!GitHubClient::isConfigured()) {
            $this->flash('error', 'GitHub integration is not configured.');
            Flight::redirect('/enterprise');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['github_oauth_state'] = $state;

        $loginUrl = GitHubClient::getLoginUrl($state);
        Flight::redirect($loginUrl);
    }

    /**
     * Handle GitHub OAuth callback
     */
    public function githubcallback() {
        if (!$this->requireLogin()) return;

        $code = Flight::request()->query->code ?? '';
        $state = Flight::request()->query->state ?? '';
        $error = Flight::request()->query->error ?? '';

        if ($error) {
            $this->logger->warning('GitHub OAuth error', ['error' => $error]);
            $this->flash('error', 'GitHub authentication was cancelled or failed.');
            Flight::redirect('/enterprise');
            return;
        }

        // Verify state
        if (empty($state) || $state !== ($_SESSION['github_oauth_state'] ?? '')) {
            $this->flash('error', 'Invalid OAuth state. Please try again.');
            Flight::redirect('/enterprise');
            return;
        }
        unset($_SESSION['github_oauth_state']);

        try {
            $result = GitHubClient::handleCallback($code, $state);

            // Encrypt the access token
            $encryptedToken = EncryptionService::encrypt($result['access_token']);

            // Get user's repositories
            $github = new GitHubClient($result['access_token']);
            $repos = $github->listRepositories('owner', 'updated', 10);

            // Store the connection using RedBean
            $this->connectUserDb();

            // Store GitHub user info for reference
            $userSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_user']);
            if (!$userSetting) {
                $userSetting = Bean::dispense('enterprisesettings');
                $userSetting->setting_key = 'github_user';
            }
            $userSetting->setting_value = json_encode($result['user']);
            $userSetting->is_encrypted = 0;
            $userSetting->updated_at = date('Y-m-d H:i:s');
            Bean::store($userSetting);

            // Store GitHub token
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            if (!$tokenSetting) {
                $tokenSetting = Bean::dispense('enterprisesettings');
                $tokenSetting->setting_key = 'github_token';
            }
            $tokenSetting->setting_value = $encryptedToken;
            $tokenSetting->is_encrypted = 1;
            $tokenSetting->updated_at = date('Y-m-d H:i:s');
            Bean::store($tokenSetting);

            $this->disconnectUserDb();

            $this->flash('success', 'GitHub connected successfully! You can now add repositories.');
            $this->logger->info('GitHub connected', [
                'member_id' => $this->member->id,
                'github_user' => $result['user']['login'] ?? ''
            ]);

            Flight::redirect('/enterprise/repos');

        } catch (Exception $e) {
            $this->disconnectUserDb();
            $this->logger->error('GitHub OAuth callback failed', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to connect GitHub: ' . $e->getMessage());
            Flight::redirect('/enterprise');
        }
    }

    // ========================================
    // Repository Management
    // ========================================

    /**
     * List repository connections
     */
    public function repos() {
        if (!$this->requireEnterprise()) return;

        $repos = $this->getRepoConnections();

        // Get boards for mapping
        $userDb = new UserDatabaseService($this->member->id);
        $boards = $userDb->getBoards();

        $this->connectUserDb();
        try {
            // Get board-repo mappings
            $mappings = [];
            $mappingBeans = Bean::findAll('boardrepomapping');
            foreach ($mappingBeans as $bean) {
                $mappings[$bean->board_id][] = [
                    'id' => $bean->id,
                    'board_id' => $bean->board_id,
                    'repo_connection_id' => $bean->repo_connection_id,
                    'is_default' => $bean->is_default,
                    'created_at' => $bean->created_at
                ];
            }

            // Check if GitHub is connected
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            $githubToken = $tokenSetting ? $tokenSetting->setting_value : null;

            $this->disconnectUserDb();

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

            $this->render('enterprise/repos', [
                'title' => 'Repository Connections',
                'repos' => $repos,
                'boards' => $boards,
                'mappings' => $mappings,
                'availableRepos' => $availableRepos,
                'githubConnected' => !empty($githubToken)
            ]);
        } catch (\Exception $e) {
            $this->disconnectUserDb();
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
            $this->connectUserDb();

            // Get the token
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);

            if (!$tokenSetting || empty($tokenSetting->setting_value)) {
                $this->disconnectUserDb();
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

            // Find or create repo connection
            $repo = Bean::findOne('repoconnections', 'provider = ? AND repo_owner = ? AND repo_name = ?',
                [$provider, $owner, $repoName]);
            if (!$repo) {
                $repo = Bean::dispense('repoconnections');
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
            Bean::store($repo);

            $this->disconnectUserDb();

            $this->flash('success', "Repository {$repoFullName} connected successfully!");
            $this->logger->info('Repository connected', [
                'member_id' => $this->member->id,
                'repo' => $repoFullName
            ]);

        } catch (Exception $e) {
            $this->disconnectUserDb();
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

        $repoId = $params['operation']->name ?? 0;
        if (empty($repoId)) {
            Flight::redirect('/enterprise/repos');
            return;
        }

        try {
            $this->connectUserDb();

            // Load repo and use xownBoardrepomappingList for cascade delete
            $repo = Bean::load('repoconnections', (int)$repoId);
            if ($repo->id) {
                // Using xown prefix ensures cascade delete of related mappings
                $repo->xownBoardrepomappingList = [];
                Bean::store($repo);
                Bean::trash($repo);
            }

            $this->disconnectUserDb();

            $this->flash('success', 'Repository disconnected.');
            $this->logger->info('Repository disconnected', [
                'member_id' => $this->member->id,
                'repo_id' => $repoId
            ]);

        } catch (Exception $e) {
            $this->disconnectUserDb();
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
            $this->connectUserDb();

            // Load parent beans for associations
            $board = Bean::load('jiraboards', $boardId);
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
                $board->ownBoardrepomappingList[] = $mapping;  // Sets jiraboards_id automatically
            }

            $mapping->is_default = $isDefault;
            Bean::store($board);

            $this->disconnectUserDb();
            $this->flash('success', 'Board mapped to repository successfully.');

        } catch (Exception $e) {
            $this->disconnectUserDb();
            $this->logger->error('Failed to map board', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to map board to repository.');
        }

        Flight::redirect('/enterprise/repos');
    }

    /**
     * Helper: Get repository connections
     */
    private function getRepoConnections(): array {
        $memberId = $this->member->id;
        $repos = [];

        UserDatabase::with($memberId, function() use (&$repos) {
            $repoBeans = Bean::findAll('repoconnections', 'ORDER BY created_at DESC');

            foreach ($repoBeans as $bean) {
                $repos[] = [
                    'id' => $bean->id,
                    'provider' => $bean->provider,
                    'repo_owner' => $bean->repo_owner,
                    'repo_name' => $bean->repo_name,
                    'default_branch' => $bean->default_branch,
                    'clone_url' => $bean->clone_url,
                    'access_token' => $bean->access_token,
                    'enabled' => $bean->enabled,
                    'created_at' => $bean->created_at,
                    'updated_at' => $bean->updated_at
                ];
            }
        });

        return $repos;
    }

    // ========================================
    // Jobs Management
    // ========================================

    /**
     * View a single AI Developer job detail
     */
    public function job($params = []) {
        if (!$this->requireEnterprise()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->flash('error', 'Issue key required');
            Flight::redirect('/enterprise');
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);

        if (!$job) {
            $this->flash('error', 'Job not found');
            Flight::redirect('/enterprise');
            return;
        }

        // Format job data for the view
        $jobData = $jobManager->formatJob($job);

        // Get job logs
        $logs = $jobManager->getLogs($issueKey);

        $this->render('enterprise/job', [
            'title' => 'Job: ' . $issueKey,
            'job' => $jobData,
            'logs' => $logs
        ]);
    }

    /**
     * List AI Developer jobs
     */
    public function jobs() {
        if (!$this->requireEnterprise()) return;

        $jobManager = new AIDevJobManager($this->member->id);
        $jobs = $jobManager->getAll(50);
        $activeJobs = $jobManager->getActive();

        $this->render('enterprise/jobs', [
            'title' => 'AI Developer Jobs',
            'jobs' => $jobs,
            'activeJobs' => $activeJobs
        ]);
    }

    /**
     * Start a new AI Developer job
     */
    public function startjob($params = []) {
        // Redirect to sharded implementation which uses Claude Code CLI
        return $this->startsharded($params);
    }

    /**
     * Start a new AI Developer job on a shard (Claude Code CLI)
     */
    public function startsharded($params = []) {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        $issueKey = Flight::request()->data->issue_key ?? '';
        $boardId = (int)(Flight::request()->data->board_id ?? 0);
        $repoId = (int)(Flight::request()->data->repo_id ?? 0);
        $cloudId = Flight::request()->data->cloud_id ?? '';
        $useOrchestrator = !empty(Flight::request()->data->use_orchestrator);

        if (empty($issueKey) || empty($boardId) || empty($repoId) || empty($cloudId)) {
            $this->json(['success' => false, 'error' => 'Missing required parameters']);
            return;
        }

        try {
            $this->connectUserDb();

            // Get Anthropic API key
            $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);

            if (!$apiKeySetting || empty($apiKeySetting->setting_value)) {
                $this->disconnectUserDb();
                $this->json(['success' => false, 'error' => 'Anthropic API key not configured']);
                return;
            }

            $apiKey = EncryptionService::decrypt($apiKeySetting->setting_value);

            // Find an available shard
            $shard = ShardRouter::findAvailableShard($this->member->id, ['git', 'filesystem']);

            if (!$shard) {
                $this->disconnectUserDb();
                $this->json(['success' => false, 'error' => 'No available shards. Please try again later.']);
                return;
            }

            // Get repository details
            $repoBean = Bean::load('repoconnections', (int)$repoId);

            if (!$repoBean->id) {
                $this->disconnectUserDb();
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

            $this->disconnectUserDb();

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
            $jiraCreds = ShardRouter::getMemberMcpCredentials($this->member->id);
            $jiraHost = $jiraCreds['jira_host'] ?? '';
            $jiraEmail = $jiraCreds['jira_email'] ?? '';
            $jiraToken = $jiraCreds['jira_api_token'] ?? '';
            $jiraSiteUrl = $jiraCreds['jira_site_url'] ?? '';

            // Create or get job using AIDevJobManager
            $jobManager = new AIDevJobManager($this->member->id);
            $job = $jobManager->getOrCreate($issueKey, $boardId, $repoId, $cloudId);

            // Generate shard job ID (this will be stored when job starts running)
            $shardJobId = md5(uniqid($issueKey . '_' . microtime(true), true));

            // Build payload for shard
            $payload = [
                'anthropic_api_key' => $apiKey,
                'job_id' => $shardJobId,
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

            // Call shard endpoint
            $shardPort = $shard['port'];
            $shardProtocol = ($shardPort == 443 || !empty($shard['ssl'])) ? 'https' : 'http';
            $shardUrl = "{$shardProtocol}://{$shard['host']}:{$shardPort}/analysis/shardaidev";

            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'verify' => false
            ]);

            $response = $client->post($shardUrl, [
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 202) {
                throw new \Exception('Shard returned non-202 status: ' . $response->getStatusCode());
            }

            // Mark job as running with the shard job ID
            $jobManager->startRun($issueKey, $shardJobId);

            $this->logger->info('AI Developer shard job started (Claude Code CLI)', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'shard_job_id' => $shardJobId,
                'shard_id' => $shard['id'],
                'shard_name' => $shard['name'] ?? $shard['host'],
                'use_orchestrator' => $useOrchestrator
            ]);

            $this->json([
                'success' => true,
                'issue_key' => $issueKey,
                'shard_job_id' => $shardJobId,
                'shard' => $shard['name'] ?? $shard['host'],
                'message' => $useOrchestrator ? 'Job started with agent orchestrator' : 'Job started on shard with Claude Code CLI',
                'use_orchestrator' => $useOrchestrator
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to start shard job', ['error' => $e->getMessage()]);
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
     * Callback endpoint for shard job completion
     */
    public function shardcallback($params = []) {
        // This endpoint receives callbacks from shards when jobs complete
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || empty($data['job_id'])) {
            $this->json(['success' => false, 'error' => 'Invalid callback data']);
            return;
        }

        $jobId = $data['job_id'];
        $status = $data['status'] ?? 'unknown';

        if ($status === 'completed') {
            ShardRouter::updateJobResult($jobId, $data['result'] ?? []);
            $this->logger->info('Shard job completed', ['job_id' => $jobId]);
        } elseif ($status === 'failed') {
            ShardRouter::updateJobStatus($jobId, 'failed', $data['error'] ?? 'Unknown error');
            $this->logger->error('Shard job failed', ['job_id' => $jobId, 'error' => $data['error'] ?? '']);
        }

        $this->json(['success' => true]);
    }

    /**
     * Get shard job status
     */
    public function shardjobstatus($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $params['operation']->name ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        $status = ShardRouter::getJobStatus($jobId);

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
     * Get shard job output
     */
    public function shardjoboutput($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $params['operation']->name ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        // Verify ownership first
        $job = ShardRouter::getJobStatus($jobId);
        if (!$job || $job['member_id'] != $this->member->id) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $output = ShardRouter::getJobOutput($jobId);

        $this->json([
            'success' => true,
            'output' => $output
        ]);
    }

    /**
     * List available shards for the current user
     */
    public function shards($params = []) {
        if (!$this->requireEnterprise()) return;

        // Get shards available to this member
        $memberShards = ShardService::getMemberShards($this->member->id);

        if (empty($memberShards)) {
            $memberShards = ShardService::getDefaultShards();
        }

        // Add health status
        foreach ($memberShards as &$shard) {
            $shard['stats'] = ShardService::getShardStats($shard['id']);
            $shard['capabilities'] = json_decode($shard['capabilities'] ?? '[]', true);
        }

        $this->json([
            'success' => true,
            'shards' => $memberShards
        ]);
    }

    /**
     * Get job status (AJAX)
     * Accepts issue_key as the identifier
     */
    public function jobstatus($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
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

        $this->json([
            'success' => true,
            'status' => $jobManager->formatJob($job)
        ]);
    }

    /**
     * Get job logs (AJAX)
     * Accepts issue_key as the identifier
     */
    public function joblogs($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);

        // Verify job exists
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $logs = $jobManager->getLogs($issueKey);

        $this->json([
            'success' => true,
            'logs' => $logs
        ]);
    }

    /**
     * Resume a job after clarification
     * Accepts issue_key as the identifier
     */
    public function resumejob($params = []) {
        if (!$this->requireEnterprise()) return;

        $issueKey = $params['operation']->name ?? '';
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

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --issue=%s --action=resume > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($issueKey)
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

        $issueKey = $params['operation']->name ?? '';
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
            $this->connectUserDb();

            // Get API key
            $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);

            if (!$apiKeySetting || empty($apiKeySetting->setting_value)) {
                $this->disconnectUserDb();
                $this->json(['success' => false, 'error' => 'Anthropic API key not configured']);
                return;
            }

            // Get cloud_id from the job or look up from board
            $cloudId = $job->cloudId;
            if (empty($cloudId)) {
                $board = Bean::load('jiraboards', (int)$job->board_id);
                $cloudId = $board->cloud_id ?? null;
            }

            $this->disconnectUserDb();

            if (empty($cloudId)) {
                $this->json(['success' => false, 'error' => 'Could not determine Atlassian Cloud ID for this job']);
                return;
            }

            // Generate a new shard job ID for the retry run
            $shardJobId = md5(uniqid($issueKey . '_retry_' . microtime(true), true));

            // Start background process
            $cronSecret = Flight::get('cron.api_key');
            $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --issue=%s --action=retry --branch=%s --pr=%d > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($issueKey),
                escapeshellarg($job->branchName),
                $job->prNumber ?? 0
            );

            exec($cmd);

            $this->logger->info('AI Developer retry job started', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'branch' => $job->branchName
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

        $issueKey = $params['operation']->name ?? '';
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
}
