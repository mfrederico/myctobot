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
use \app\services\ShardService;
use \app\services\ShardRouter;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/GitHubClient.php';
require_once __DIR__ . '/../services/AIDevAgent.php';
require_once __DIR__ . '/../services/AIDevJobManager.php';
require_once __DIR__ . '/../services/ShardService.php';
require_once __DIR__ . '/../services/ShardRouter.php';
require_once __DIR__ . '/../lib/Bean.php';

use \app\Bean;

class Enterprise extends BaseControls\Control {

    /**
     * Check access - all features now available to logged-in users
     */
    private function requireEnterprise(): bool {
        // All features now available to all tiers
        return $this->requireLogin();
    }

    /**
     * Connect to user database (legacy no-op - all data now in single MySQL DB)
     */
    private function connectUserDb(): void {
        // No-op: All data is now in single MySQL database per tenant
    }

    /**
     * Disconnect from user database (legacy no-op)
     */
    private function disconnectUserDb(): void {
        // No-op: No database switching needed
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
        // Check API key
        $apiKeySetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);
        $apiKeySet = $apiKeySetting && !empty($apiKeySetting->setting_value);

        // Check GitHub connections
        $githubCount = R::count('repoconnections', 'provider = ? AND enabled = ? AND member_id = ?', ['github', 1, $memberId]);
        $githubConnected = $githubCount > 0;

        // Check credit balance errors (within last 24 hours)
        $creditSetting = R::findOne('enterprisesettings',
            'setting_key = ? AND member_id = ? AND updated_at > ?',
            ['credit_balance_error', $memberId, date('Y-m-d H:i:s', strtotime('-24 hours'))]
        );
        $creditBalanceError = $creditSetting ? $creditSetting->setting_value : null;

        // Get boards
        $boards = [];
        $boardBeans = R::findAll('jiraboards', 'member_id = ? ORDER BY board_name ASC', [$memberId]);
        foreach ($boardBeans as $board) {
            $boards[] = [
                'id' => $board->id,
                'board_name' => $board->board_name,
                'project_key' => $board->project_key,
                'cloud_id' => $board->cloud_id
            ];
        }

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
        // Redirect to unified settings page
        Flight::redirect('/settings/connections');
    }

    /**
     * Re-register Jira webhook with correct tenant URL
     * POST /enterprise/reregisterwebhook
     */
    public function reregisterwebhook() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        $cloudId = $this->getParam('cloud_id');
        if (empty($cloudId)) {
            // Try to get from member's first Atlassian token
            $token = R::findOne('atlassiantoken', 'member_id = ?', [$this->member->id]);
            if ($token) {
                $cloudId = $token->cloud_id;
            }
        }

        if (empty($cloudId)) {
            $this->json(['success' => false, 'error' => 'No Atlassian connection found']);
            return;
        }

        try {
            $result = AtlassianAuth::reregisterAIDevWebhook($this->member->id, $cloudId);

            if ($result) {
                $tenantSlug = $_SESSION['tenant_slug'] ?? 'default';
                $this->json([
                    'success' => true,
                    'message' => "Webhook re-registered with tenant: {$tenantSlug}"
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

        $this->connectUserDb();
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

            $this->disconnectUserDb();
        } catch (\Exception $e) {
            $this->disconnectUserDb();
            $this->flash('error', 'Failed to save API key: ' . $e->getMessage());
        }

        Flight::redirect('/enterprise/settings');
    }

    /**
     * Delete an API key
     */
    public function deletekey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $params['operation']->name ?? null;
        if (!$keyId) {
            $this->flash('error', 'No key specified.');
            Flight::redirect('/enterprise/settings');
            return;
        }

        $this->connectUserDb();
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
            $this->disconnectUserDb();
        } catch (\Exception $e) {
            $this->disconnectUserDb();
            $this->flash('error', 'Failed to delete key: ' . $e->getMessage());
        }

        Flight::redirect('/enterprise/settings');
    }

    /**
     * Test an API key
     */
    public function testkey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $params['operation']->name ?? null;
        if (!$keyId) {
            Flight::json(['success' => false, 'error' => 'No key specified']);
            return;
        }

        $this->connectUserDb();
        try {
            $key = Bean::load('anthropickeys', $keyId);
            if (!$key || !$key->id) {
                $this->disconnectUserDb();
                Flight::json(['success' => false, 'error' => 'Key not found']);
                return;
            }

            $apiKey = EncryptionService::decrypt($key->api_key);
            $model = $key->model ?? 'claude-sonnet-4-20250514';

            $this->disconnectUserDb();

            // Test the key
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
                    'model' => $model,
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hi']]
                ]
            ]);

            Flight::json(['success' => true, 'message' => 'API key is valid!']);

        } catch (\Exception $e) {
            $this->disconnectUserDb();
            $this->logger->warning('Anthropic API key test failed', ['error' => $e->getMessage()]);
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mask an Anthropic API key for display
     */
    private function maskAnthropicKey(string $key): string {
        if (empty($key)) return '(empty)';

        if (preg_match('/^(sk-ant-api\d+-)(.+)$/', $key, $matches)) {
            $prefix = $matches[1];
            $secret = $matches[2];
            $secretLen = strlen($secret);
            if ($secretLen > 7) {
                return $prefix . substr($secret, 0, 3) . '...' . substr($secret, -4);
            }
            return $prefix . '***';
        }

        return substr($key, 0, 10) . '...' . substr($key, -4);
    }

    // ========================================
    // GitHub OAuth
    // ========================================

    /**
     * Start GitHub OAuth flow
     */
    public function github() {
        // GitHub connection is free for all tiers
        if (!$this->requireLogin()) return;

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

        // Get boards for mapping using static method
        $this->connectUserDb();
        $boards = [];
        $boardBeans = Bean::findAll('jiraboards', ' ORDER BY board_name ASC ');
        foreach ($boardBeans as $bean) {
            $boards[] = [
                'id' => $bean->id,
                'board_name' => $bean->board_name,
                'project_key' => $bean->project_key,
                'cloud_id' => $bean->cloud_id
            ];
        }
        try {
            // Get board-repo mappings
            // Note: mapboard() uses RedBeanPHP associations which create jiraboards_id and repoconnections_id
            // Support both old schema (board_id, repo_connection_id) and new association columns
            $mappings = [];
            $mappingBeans = Bean::findAll('boardrepomapping');
            foreach ($mappingBeans as $bean) {
                // Use association column names (jiraboards_id, repoconnections_id) with fallback to old schema
                $boardId = $bean->jiraboards_id ?? $bean->board_id;
                $repoId = $bean->repoconnections_id ?? $bean->repo_connection_id;

                if (!$boardId || !$repoId) continue; // Skip invalid mappings

                // Dedupe: skip if this exact mapping already exists
                $isDupe = false;
                if (isset($mappings[$boardId])) {
                    foreach ($mappings[$boardId] as $existing) {
                        if ($existing['repo_connection_id'] == $repoId) {
                            $isDupe = true;
                            break;
                        }
                    }
                }
                if ($isDupe) continue;

                $mappings[$boardId][] = [
                    'id' => $bean->id,
                    'board_id' => $boardId,
                    'repo_connection_id' => $repoId,
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

            // Get agents for dropdown
            $agents = [];
            $agentBeans = R::findAll('aiagents', 'member_id = ? AND is_active = 1 ORDER BY name ASC', [$this->member->id]);
            foreach ($agentBeans as $agentBean) {
                $agents[] = [
                    'id' => $agentBean->id,
                    'name' => $agentBean->name,
                    'provider' => $agentBean->provider ?: 'claude_cli',
                    'provider_label' => $this->getProviderLabel($agentBean->provider),
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
                    $repo->webhook_id = $existingHook['id'];
                    $webhookCreated = true;
                } else {
                    // Create webhook
                    $hook = $github->createWebhook($owner, $repoName, $webhookUrl, $webhookSecret);
                    $repo->webhook_id = $hook['id'];
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

            $this->disconnectUserDb();

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
            $this->connectUserDb();

            // Find and delete the mapping
            $mapping = Bean::findOne('boardrepomapping',
                '(jiraboards_id = ? OR board_id = ?) AND (repoconnections_id = ? OR repo_connection_id = ?)',
                [$boardId, $boardId, $repoId, $repoId]
            );

            if ($mapping) {
                Bean::trash($mapping);
                $this->flash('success', 'Repository mapping removed.');
            } else {
                $this->flash('warning', 'Mapping not found.');
            }

            $this->disconnectUserDb();

        } catch (Exception $e) {
            $this->disconnectUserDb();
            $this->logger->error('Failed to unmap board', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to remove mapping.');
        }

        Flight::redirect('/enterprise/repos');
    }

    /**
     * Helper: Get repository connections
     */
    private function getRepoConnections(): array {
        $repos = [];

        // repoconnections is in user SQLite database, use Bean::
        $this->connectUserDb();
        $repoBeans = Bean::findAll('repoconnections', ' ORDER BY created_at DESC ');

        foreach ($repoBeans as $bean) {
            // Get agent name if assigned (aiagents is in MySQL)
            $agentName = null;
            if ($bean->agent_id) {
                $agent = R::load('aiagents', $bean->agent_id);
                if ($agent->id) {
                    $agentName = $agent->name;
                }
            }

            $repos[] = [
                'id' => $bean->id,
                'provider' => $bean->provider,
                'repo_owner' => $bean->repo_owner,
                'repo_name' => $bean->repo_name,
                'default_branch' => $bean->default_branch,
                'clone_url' => $bean->clone_url,
                'access_token' => $bean->access_token,
                'enabled' => $bean->enabled,
                'issues_enabled' => $bean->issues_enabled ?? 0,
                'webhook_id' => $bean->webhook_id,
                'agent_id' => $bean->agent_id,
                'agent_name' => $agentName,
                'created_at' => $bean->created_at,
                'updated_at' => $bean->updated_at
            ];
        }

        $this->disconnectUserDb();
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

        // Use AIDevStatusService for JSON-based job tracking
        require_once __DIR__ . '/../services/AIDevStatusService.php';
        $jobs = \app\services\AIDevStatusService::getAllJobs($this->member->id, 50);
        $activeJobs = \app\services\AIDevStatusService::getActiveJobs($this->member->id);

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

        $issueKey = $params['operation']->name ?? '';
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

            // Get tenant slug for multi-tenancy support
            $tenantSlug = $_SESSION['tenant_slug'] ?? null;
            $tenantParam = $tenantSlug && $tenantSlug !== 'default'
                ? sprintf(' --tenant=%s', escapeshellarg($tenantSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --job=%s --issue=%s --action=resume%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($job->id),
                escapeshellarg($issueKey),
                $tenantParam
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

            // Get tenant slug for multi-tenancy support
            $tenantSlug = $_SESSION['tenant_slug'] ?? null;
            $tenantParam = $tenantSlug && $tenantSlug !== 'default'
                ? sprintf(' --tenant=%s', escapeshellarg($tenantSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --job=%s --issue=%s --action=retry --branch=%s --pr=%d%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($shardJobId),
                escapeshellarg($issueKey),
                escapeshellarg($job->branchName),
                $job->prNumber ?? 0,
                $tenantParam
            );

            exec($cmd);

            $this->logger->info('AI Developer retry job started', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'branch' => $job->branchName,
                'tenant' => $tenantSlug ?? 'default'
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
        $this->connectUserDb();

        // Verify repo exists (no member_id filter - database is already per-tenant)
        $repo = Bean::findOne('repoconnections', 'id = ?', [$repoId]);
        if (!$repo) {
            $this->disconnectUserDb();
            Flight::jsonError('Repository not found', 404);
            return;
        }

        // If agent_id provided, verify it belongs to this member (aiagents is in MySQL)
        if ($agentId) {
            $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$agentId, $memberId]);
            if (!$agent) {
                $this->disconnectUserDb();
                Flight::jsonError('Agent not found', 404);
                return;
            }
        }

        // Update repo
        $repo->agent_id = $agentId;
        $repo->updated_at = date('Y-m-d H:i:s');
        Bean::store($repo);

        $this->disconnectUserDb();
        Flight::jsonSuccess(['message' => 'Agent assigned successfully']);
    }

    /**
     * Helper: Get runner type label
     */
    private function getProviderLabel(?string $provider): string {
        if ($provider === null) {
            return 'Claude CLI';
        }
        $labels = [
            'claude_cli' => 'Claude CLI',
            'anthropic_api' => 'Anthropic API',
            'ollama' => 'Ollama'
        ];
        return $labels[$provider] ?? $provider;
    }
}
