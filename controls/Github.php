<?php
/**
 * GitHub Controller
 * Handles GitHub OAuth and repository connections
 * Available for all tiers (free feature)
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\GitHubClient;
use \app\services\EncryptionService;

class Github extends BaseControls\Control {

    /**
     * Start GitHub OAuth flow
     */
    public function connect() {
        if (!$this->requireLogin()) return;

        if (!GitHubClient::isConfigured()) {
            $this->flash('error', 'GitHub integration is not configured. Please contact support.');
            Flight::redirect('/settings/connections');
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
    public function callback() {
        if (!$this->requireLogin()) return;

        $code = Flight::request()->query->code ?? '';
        $state = Flight::request()->query->state ?? '';
        $error = Flight::request()->query->error ?? '';

        if ($error) {
            $this->logger->warning('GitHub OAuth error', ['error' => $error]);
            $this->flash('error', 'GitHub authentication was cancelled or failed.');
            Flight::redirect('/settings/connections');
            return;
        }

        // Validate state
        if (empty($state) || $state !== ($_SESSION['github_oauth_state'] ?? '')) {
            $this->logger->warning('GitHub OAuth state mismatch');
            $this->flash('error', 'Invalid OAuth state. Please try again.');
            Flight::redirect('/settings/connections');
            return;
        }
        unset($_SESSION['github_oauth_state']);

        try {
            // Exchange code for token
            $result = GitHubClient::handleCallback($code, $state);

            if (!$result || empty($result['access_token'])) {
                throw new \Exception('Failed to authenticate with GitHub');
            }

            // Get user info from callback result
            $user = $result['user'] ?? [];
            $accessToken = $result['access_token'];
            $memberId = $this->member->id;

            // Store encrypted token in user database
            $this->saveSetting('github_token', EncryptionService::encrypt($accessToken));
            $this->saveSetting('github_user', json_encode($user));

            $this->logger->info('GitHub connected', [
                'member_id' => $memberId,
                'github_user' => $user['login'] ?? 'unknown'
            ]);

            $this->flash('success', 'GitHub connected successfully!');
            Flight::redirect('/settings/connections');

        } catch (\Exception $e) {
            $this->logger->error('GitHub OAuth failed', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to connect GitHub: ' . $e->getMessage());
            Flight::redirect('/settings/connections');
        }
    }

    /**
     * Show repository selection page
     * Redirects to repolist() which has full functionality
     */
    public function repos() {
        // Use repolist() which has complete functionality
        return $this->repolist();
    }

    /**
     * Connect a repository
     */
    public function addrepo() {
        if (!$this->requireLogin()) return;

        $fullName = Flight::request()->data->full_name ?? '';
        $defaultBranch = Flight::request()->data->default_branch ?? 'main';

        if (empty($fullName)) {
            Flight::jsonError('Repository name is required', 400);
            return;
        }

        $memberId = $this->member->id;

        // Parse owner/repo from full_name for duplicate check
        $parts = explode('/', $fullName, 2);
        if (count($parts) !== 2) {
            Flight::jsonError('Invalid repository name format (expected owner/repo)', 400);
            return;
        }

        // Check if already connected (user database via Bean::)
        $existing = Bean::findOne('repoconnections', 'repo_owner = ? AND repo_name = ?', [$parts[0], $parts[1]]);
        if ($existing) {
            Flight::jsonError('Repository is already connected', 400);
            return;
        }

        try {
            // Use parsed owner/repo from earlier
            [$owner, $repoName] = $parts;

            // Generate webhook secret for this repo
            $webhookSecret = bin2hex(random_bytes(20));

            $repo = Bean::dispense('repoconnections');
            $repo->member_id = $this->member->id;  // Store owner for webhook callbacks
            $repo->provider = 'github';
            $repo->repo_owner = $owner;
            $repo->repo_name = $repoName;
            $repo->default_branch = $defaultBranch;
            $repo->webhook_secret = $webhookSecret;
            $repo->enabled = 1;
            $repo->issues_enabled = 0; // Default: GitHub for code only, not issue tracking
            $repo->created_at = date('Y-m-d H:i:s');
            Bean::store($repo);

            // Try to create webhook on GitHub
            $webhookCreated = false;
            $webhookError = null;
            try {
                $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
                if ($tokenSetting && !empty($tokenSetting->setting_value)) {
                    $token = EncryptionService::decrypt($tokenSetting->setting_value);
                    $github = new GitHubClient($token);

                    // $owner and $repoName already set from earlier parsing
                    // Always use main domain for webhooks (subdomains redirect, breaking webhook delivery)
                    $webhookUrl = 'https://myctobot.ai/webhook/github';
                    $workspace = $_SESSION['tenant_slug'] ?? null;
                    if ($workspace && $workspace !== 'default') {
                        $webhookUrl .= '?workspace=' . urlencode($workspace);
                    }

                    // Check if webhook already exists
                    $existingHook = $github->findWebhook($owner, $repoName, $webhookUrl);
                    if ($existingHook) {
                        $webhookCreated = true;
                        $repo->webhook_id = $existingHook['id'];
                        $this->logger->info('Found existing webhook', ['hook_id' => $existingHook['id'], 'url' => $webhookUrl]);
                    } else {
                        // Create webhook
                        $hook = $github->createWebhook($owner, $repoName, $webhookUrl, $webhookSecret);
                        $repo->webhook_id = $hook['id'] ?? null;
                        $webhookCreated = !empty($repo->webhook_id);
                        $this->logger->info('Created webhook', ['hook_id' => $repo->webhook_id, 'url' => $webhookUrl, 'response' => $hook]);
                    }

                    // Ensure ai-dev label exists
                    $github->ensureAiDevLabel($owner, $repoName);

                    // Save the webhook_id to the repo
                    $storedId = Bean::store($repo);
                    $this->logger->info('Stored repo with webhook_id', ['repo_id' => $storedId, 'webhook_id' => $repo->webhook_id]);
                }
            } catch (\Exception $e) {
                $webhookError = $e->getMessage();
                $this->logger->warning('Failed to create webhook', [
                    'repo' => $fullName,
                    'error' => $webhookError
                ]);
            }

            $this->logger->info('GitHub repo connected', [
                'member_id' => $memberId,
                'repo' => $fullName,
                'webhook_created' => $webhookCreated
            ]);

            $message = 'Repository connected';
            if (!$webhookCreated) {
                $message .= ' (webhook setup failed - you may need admin access)';
            }

            Flight::jsonSuccess([
                'id' => $repo->id,
                'webhook_created' => $webhookCreated,
                'webhook_error' => $webhookError
            ], $message);

        } catch (\Exception $e) {
            $this->logger->error('Failed to connect repo', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to connect repository', 500);
        }
    }

    /**
     * Disconnect a repository
     */
    public function removerepo() {
        if (!$this->requireLogin()) return;

        $repoId = Flight::request()->data->id ?? 0;

        if (empty($repoId)) {
            Flight::jsonError('Repository ID is required', 400);
            return;
        }

        // User database via Bean::
        $repo = Bean::findOne('repoconnections', 'id = ?', [$repoId]);

        if (!$repo) {
            Flight::jsonError('Repository not found', 404);
            return;
        }

        Bean::trash($repo);

        $this->logger->info('GitHub repo disconnected', [
            'member_id' => $this->member->id,
            'repo' => $repo->full_name
        ]);

        Flight::jsonSuccess(null, 'Repository disconnected');
    }

    /**
     * Disconnect GitHub account
     */
    public function disconnect() {
        if (!$this->requireLogin()) return;

        // Remove token and user info from user database
        $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
        if ($tokenSetting) Bean::trash($tokenSetting);

        $userSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_user']);
        if ($userSetting) Bean::trash($userSetting);

        // Disable repos (keep them for re-connection)
        $repos = Bean::find('repoconnections', 'provider = ?', ['github']);
        foreach ($repos as $repo) {
            $repo->enabled = 0;
            Bean::store($repo);
        }

        $this->logger->info('GitHub disconnected', ['member_id' => $this->member->id]);
        $this->flash('success', 'GitHub account disconnected.');
        Flight::redirect('/settings/connections');
    }

    /**
     * Toggle issue tracking for a repository (AJAX)
     *
     * When enabled: GitHub Issues will trigger AI Developer jobs
     * When disabled: GitHub used only for code hosting, use Jira for issues
     */
    public function toggleissues() {
        if (!$this->requireLogin()) return;

        $repoId = Flight::request()->data->id ?? 0;
        $enabled = Flight::request()->data->enabled ?? false;

        if (empty($repoId)) {
            Flight::jsonError('Repository ID is required', 400);
            return;
        }

        $repo = Bean::findOne('repoconnections', 'id = ? AND provider = ?', [$repoId, 'github']);
        if (!$repo) {
            Flight::jsonError('Repository not found', 404);
            return;
        }

        $repo->issues_enabled = $enabled ? 1 : 0;
        Bean::store($repo);

        $this->logger->info('GitHub issue tracking toggled', [
            'member_id' => $this->member->id,
            'repo' => $repo->full_name,
            'issues_enabled' => $enabled
        ]);

        $status = $enabled ? 'GitHub Issues enabled' : 'GitHub Issues disabled (use Jira instead)';
        Flight::jsonSuccess(['issues_enabled' => $repo->issues_enabled], $status);
    }

    /**
     * Get repository settings (AJAX)
     */
    public function reposettings() {
        if (!$this->requireLogin()) return;

        $repoId = Flight::request()->query->id ?? 0;

        if (empty($repoId)) {
            Flight::jsonError('Repository ID is required', 400);
            return;
        }

        $repo = Bean::findOne('repoconnections', 'id = ? AND provider = ?', [$repoId, 'github']);
        if (!$repo) {
            Flight::jsonError('Repository not found', 404);
            return;
        }

        Flight::jsonSuccess([
            'id' => $repo->id,
            'full_name' => $repo->full_name,
            'default_branch' => $repo->default_branch,
            'enabled' => (bool)$repo->enabled,
            'issues_enabled' => (bool)$repo->issues_enabled,
            'webhook_id' => $repo->webhook_id,
        ]);
    }

    /**
     * Helper to save enterprise setting (uses user database via Bean::)
     */
    private function saveSetting(string $key, string $value): void {
        $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', [$key]);
        if (!$setting) {
            $setting = Bean::dispense('enterprisesettings');
            $setting->setting_key = $key;
        }
        $setting->setting_value = $value;
        $setting->updated_at = date('Y-m-d H:i:s');
        Bean::store($setting);
    }

    /**
     * List repository connections (main repos page)
     */
    public function repolist() {
        if (!$this->requireLogin()) return;

        $repos = $this->getRepoConnections();

        // Get boards for mapping
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
            $mappings = [];
            $mappingBeans = Bean::findAll('boardrepomapping');
            foreach ($mappingBeans as $bean) {
                $boardId = $bean->jiraboards_id ?? $bean->board_id;
                $repoId = $bean->repoconnections_id ?? $bean->repo_connection_id;

                if (!$boardId || !$repoId) continue;

                // Dedupe
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

            // Get available repos from GitHub if connected
            $availableRepos = [];
            if (!empty($githubToken)) {
                try {
                    $token = EncryptionService::decrypt($githubToken);
                    $github = new GitHubClient($token);
                    $availableRepos = $github->listRepositories('all', 'updated', 50);
                } catch (\Exception $e) {
                    $this->logger->warning('Could not fetch GitHub repos', ['error' => $e->getMessage()]);
                }
            }

            // Get agents for dropdown
            $agents = [];
            $agentBeans = \RedBeanPHP\R::findAll('aiagents', 'member_id = ? AND is_active = 1 ORDER BY name ASC', [$this->member->id]);
            foreach ($agentBeans as $agentBean) {
                $agents[] = [
                    'id' => $agentBean->id,
                    'name' => $agentBean->name,
                    'runner_type' => $agentBean->runner_type,
                    'runner_type_label' => $this->getRunnerTypeLabel($agentBean->runner_type),
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
     * Disconnect a repository
     */
    public function disconnectrepo($params = []) {
        if (!$this->requireLogin()) return;

        $repoId = $params['operation']->name ?? 0;
        if (empty($repoId)) {
            Flight::redirect('/github/repolist');
            return;
        }

        try {
            // Load repo
            $repo = Bean::load('repoconnections', (int)$repoId);
            if (!$repo->id) {
                $this->flash('error', 'Repository not found.');
                Flight::redirect('/github/repolist');
                return;
            }

            // Delete webhook from GitHub if we have one
            $webhookDeleted = false;
            if (!empty($repo->webhook_id) && !empty($repo->repo_owner) && !empty($repo->repo_name)) {
                try {
                    $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
                    if ($tokenSetting && !empty($tokenSetting->setting_value)) {
                        $token = EncryptionService::decrypt($tokenSetting->setting_value);
                        $github = new GitHubClient($token);
                        $webhookDeleted = $github->deleteWebhook($repo->repo_owner, $repo->repo_name, $repo->webhook_id);

                        if ($webhookDeleted) {
                            $this->logger->info('Deleted GitHub webhook', [
                                'repo' => $repo->repo_owner . '/' . $repo->repo_name,
                                'webhook_id' => $repo->webhook_id
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - webhook might already be gone
                    $this->logger->warning('Could not delete GitHub webhook', [
                        'repo' => $repo->repo_owner . '/' . $repo->repo_name,
                        'webhook_id' => $repo->webhook_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Delete board mappings and repo from database
            $repo->xownBoardrepomappingList = [];
            Bean::store($repo);
            Bean::trash($repo);

            $message = 'Repository disconnected.';
            if ($webhookDeleted) {
                $message .= ' Webhook removed from GitHub.';
            }
            $this->flash('success', $message);
            $this->logger->info('Repository disconnected', [
                'member_id' => $this->member->id,
                'repo_id' => $repoId,
                'webhook_deleted' => $webhookDeleted
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to disconnect repository', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to disconnect repository.');
        }

        Flight::redirect('/github/repolist');
    }

    /**
     * Assign an agent to a repository
     */
    public function assignagent() {
        if (!$this->requireLogin()) return;

        $memberId = $this->member->id;

        $input = json_decode(file_get_contents('php://input'), true);
        $repoId = (int) ($input['repo_id'] ?? 0);
        $agentId = $input['agent_id'] ? (int) $input['agent_id'] : null;

        if (!$repoId) {
            Flight::jsonError('Repository ID required', 400);
            return;
        }

        // Verify repo exists
        $repo = Bean::findOne('repoconnections', 'id = ?', [$repoId]);
        if (!$repo) {
            Flight::jsonError('Repository not found', 404);
            return;
        }

        // If agent_id provided, verify it belongs to this member
        if ($agentId) {
            $agent = \RedBeanPHP\R::findOne('aiagents', 'id = ? AND member_id = ?', [$agentId, $memberId]);
            if (!$agent) {
                Flight::jsonError('Agent not found', 404);
                return;
            }
        }

        // Update repo
        $repo->agent_id = $agentId;
        $repo->updated_at = date('Y-m-d H:i:s');
        Bean::store($repo);

        Flight::jsonSuccess(['message' => 'Agent assigned successfully']);
    }

    /**
     * Map a board to a repository
     */
    public function mapboard() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/github/repolist');
            return;
        }

        if (!$this->validateCSRF()) return;

        $boardId = (int)(Flight::request()->data->board_id ?? 0);
        $repoId = (int)(Flight::request()->data->repo_id ?? 0);
        $isDefault = Flight::request()->data->is_default ? 1 : 0;

        if (empty($boardId) || empty($repoId)) {
            $this->flash('error', 'Invalid board or repository.');
            Flight::redirect('/github/repolist');
            return;
        }

        try {
            // Load parent beans for associations
            $board = Bean::load('jiraboards', $boardId);
            $repo = Bean::load('repoconnections', $repoId);

            // If setting as default, clear other defaults for this board
            if ($isDefault) {
                foreach ($board->ownBoardrepomappingList as $existingMapping) {
                    $existingMapping->is_default = 0;
                }
            }

            // Check if mapping already exists
            $mapping = null;
            foreach ($board->ownBoardrepomappingList as $existingMapping) {
                if ($existingMapping->repoconnections_id == $repoId) {
                    $mapping = $existingMapping;
                    break;
                }
            }

            if (!$mapping) {
                $mapping = Bean::dispense('boardrepomapping');
                $mapping->created_at = date('Y-m-d H:i:s');
                $mapping->repoconnections = $repo;
                $board->ownBoardrepomappingList[] = $mapping;
            }

            $mapping->is_default = $isDefault;
            Bean::store($board);

            $this->flash('success', 'Board mapped to repository successfully.');

        } catch (\Exception $e) {
            $this->logger->error('Failed to map board', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to map board to repository.');
        }

        Flight::redirect('/github/repolist');
    }

    /**
     * Remove a board-to-repository mapping
     */
    public function unmapboard() {
        if (!$this->requireLogin()) return;

        $boardId = (int)$this->getParam('board_id');
        $repoId = (int)$this->getParam('repo_id');

        if (empty($boardId) || empty($repoId)) {
            $this->flash('error', 'Invalid board or repository.');
            Flight::redirect('/github/repolist');
            return;
        }

        try {
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

        } catch (\Exception $e) {
            $this->logger->error('Failed to unmap board', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to remove mapping.');
        }

        Flight::redirect('/github/repolist');
    }

    /**
     * Helper: Get repository connections
     */
    private function getRepoConnections(): array {
        $repos = [];

        $repoBeans = Bean::findAll('repoconnections', ' ORDER BY created_at DESC ');

        foreach ($repoBeans as $bean) {
            $agentName = null;
            if ($bean->agent_id) {
                $agent = \RedBeanPHP\R::load('aiagents', $bean->agent_id);
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

        return $repos;
    }

    /**
     * Helper: Get runner type label
     */
    private function getRunnerTypeLabel(string $runnerType): string {
        $labels = [
            'claude_cli' => 'Claude CLI',
            'anthropic_api' => 'Anthropic API',
            'ollama' => 'Ollama'
        ];
        return $labels[$runnerType] ?? $runnerType;
    }
}
