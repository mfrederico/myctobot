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
use \app\services\LLMProviders\LLMProviderFactory;
use \app\services\ConnectionsService;

require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/../services/ConnectionsService.php';

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

            // Check for custom return URL (e.g., from Enterprise controller)
            $returnUrl = $_SESSION['github_oauth_return'] ?? '/settings/connections';
            unset($_SESSION['github_oauth_return']);
            Flight::redirect($returnUrl);

        } catch (\Exception $e) {
            $this->logger->error('GitHub OAuth failed', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to connect GitHub: ' . $e->getMessage());

            // Check for custom return URL for errors too
            $returnUrl = $_SESSION['github_oauth_return'] ?? '/settings/connections';
            unset($_SESSION['github_oauth_return']);
            Flight::redirect($returnUrl);
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

            // Create repo (workspace-level - track who created it)
            $repo = Bean::dispense('repoconnections');
            $repo->member = $this->member; // Required for webhook to trigger AI dev jobs
            $repo->created_by_member_id = $this->member->id;
            $repo->created_by_name = $this->member->display_name ?? $this->member->email;
            $repo->provider = 'github';
            $repo->repo_owner = $owner;
            $repo->repo_name = $repoName;
            $repo->default_branch = $defaultBranch;
            $repo->webhook_secret = $webhookSecret;
            $repo->enabled = 1;
            $repo->issues_enabled = 0; // Default: GitHub for code only, not issue tracking
            $repo->slug = $this->generateUniqueSlug($repoName);
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
                        $repo->webhook_uid = $existingHook['id'];
                        $this->logger->info('Found existing webhook', ['hook_id' => $existingHook['id'], 'url' => $webhookUrl]);
                    } else {
                        // Create webhook
                        $hook = $github->createWebhook($owner, $repoName, $webhookUrl, $webhookSecret);
                        $repo->webhook_uid = $hook['id'] ?? null;
                        $webhookCreated = !empty($repo->webhook_uid);
                        $this->logger->info('Created webhook', ['hook_id' => $repo->webhook_uid, 'url' => $webhookUrl, 'response' => $hook]);
                    }

                    // Ensure ai-dev label exists
                    $github->ensureAiDevLabel($owner, $repoName);

                    // Save the webhook_uid to the repo
                    $storedId = Bean::store($repo);
                    $this->logger->info('Stored repo with webhook_uid', ['repo_id' => $storedId, 'webhook_uid' => $repo->webhook_uid]);
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
            'webhook_uid' => $repo->webhook_uid,
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
        $setting->is_shared = 1;  // GitHub settings are workspace-level (always set)
        $setting->updated_at = date('Y-m-d H:i:s');
        Bean::store($setting);
    }

    /**
     * Generate a URL-safe slug from a string
     * Converts to lowercase, replaces non-alphanumeric with hyphens, collapses multiple hyphens
     */
    private function generateSlug(string $input): string {
        // Convert to lowercase
        $slug = strtolower($input);
        // Replace non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');
        // Collapse multiple hyphens
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug;
    }

    /**
     * Generate a unique slug for a repository (appends number if needed)
     */
    private function generateUniqueSlug(string $repoName): string {
        $baseSlug = $this->generateSlug($repoName);
        $slug = $baseSlug;
        $counter = 1;

        // Check for uniqueness and append number if needed
        while (Bean::findOne('repoconnections', 'slug = ?', [$slug])) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Update repository slug (AJAX)
     */
    public function updateslug() {
        if (!$this->requireLogin()) return;

        $input = json_decode(file_get_contents('php://input'), true);
        $repoId = (int)($input['repo_id'] ?? 0);
        $newSlug = trim($input['slug'] ?? '');

        if (empty($repoId)) {
            Flight::jsonError('Repository ID is required', 400);
            return;
        }

        if (empty($newSlug)) {
            Flight::jsonError('Slug cannot be empty', 400);
            return;
        }

        // Validate slug format (lowercase, alphanumeric, hyphens only)
        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $newSlug)) {
            Flight::jsonError('Invalid slug format. Use lowercase letters, numbers, and hyphens only. Cannot start or end with hyphen.', 400);
            return;
        }

        // Limit slug length
        if (strlen($newSlug) > 50) {
            Flight::jsonError('Slug must be 50 characters or less', 400);
            return;
        }

        $repo = Bean::findOne('repoconnections', 'id = ?', [$repoId]);
        if (!$repo) {
            Flight::jsonError('Repository not found', 404);
            return;
        }

        // Check uniqueness (exclude current repo)
        $existing = Bean::findOne('repoconnections', 'slug = ? AND id != ?', [$newSlug, $repoId]);
        if ($existing) {
            Flight::jsonError('Slug is already in use by another repository', 400);
            return;
        }

        $oldSlug = $repo->slug;
        $repo->slug = $newSlug;
        $repo->updated_at = date('Y-m-d H:i:s');
        Bean::store($repo);

        $this->logger->info('Repository slug updated', [
            'member_id' => $this->member->id,
            'repo_id' => $repoId,
            'old_slug' => $oldSlug,
            'new_slug' => $newSlug
        ]);

        Flight::jsonSuccess([
            'slug' => $newSlug,
            'tag' => 'repo-' . $newSlug
        ], 'Slug updated successfully');
    }

    /**
     * List repository connections (main repos page)
     */
    public function repolist() {
        if (!$this->requireLogin()) return;

        $repos = ConnectionsService::getRepositoryConnections();

        // Get boards for mapping
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

                if (!$boardId || !$repoId) continue;

                // Dedupe
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
                } catch (\Exception $e) {
                    $this->logger->warning('Could not fetch GitHub repos', ['error' => $e->getMessage()]);
                }
            }

            // Get agents for dropdown (workspace-level - shared by all members)
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
     * Disconnect a repository
     */
    public function disconnectrepo($params = []) {
        if (!$this->requireLogin()) return;

        $repoId = $this->opId() ?? 0;
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
            if (!empty($repo->webhook_uid) && !empty($repo->repo_owner) && !empty($repo->repo_name)) {
                try {
                    $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
                    if ($tokenSetting && !empty($tokenSetting->setting_value)) {
                        $token = EncryptionService::decrypt($tokenSetting->setting_value);
                        $github = new GitHubClient($token);
                        $webhookDeleted = $github->deleteWebhook($repo->repo_owner, $repo->repo_name, $repo->webhook_uid);

                        if ($webhookDeleted) {
                            $this->logger->info('Deleted GitHub webhook', [
                                'repo' => $repo->repo_owner . '/' . $repo->repo_name,
                                'webhook_uid' => $repo->webhook_uid
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - webhook might already be gone
                    $this->logger->warning('Could not delete GitHub webhook', [
                        'repo' => $repo->repo_owner . '/' . $repo->repo_name,
                        'webhook_uid' => $repo->webhook_uid,
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

        // If agent_id provided, verify it exists (workspace-level)
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
            $board = Bean::load('boards', $boardId);
            $repo = Bean::load('repoconnections', $repoId);

            if (!$board->id || !$repo->id) {
                $this->flash('error', 'Board or repository not found.');
                Flight::redirect('/github/repolist');
                return;
            }

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
                'boards_id = ? AND repoconnections_id = ?',
                [$boardId, $repoId]
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

    // ========================================
    // Static Label Management Methods
    // ========================================

    /**
     * Get GitHub token for a repo (from repo connection or enterprise settings)
     * Public static for use by other controllers (e.g., Webhook re-clone)
     */
    public static function getTokenStatic(string $owner, string $repo, int $memberId): ?string {
        \app\services\UserDatabaseService::connect($memberId);

        $repoConnection = Bean::findOne('repoconnections', 'repo_owner = ? AND repo_name = ?', [$owner, $repo]);
        $githubToken = $repoConnection->access_token ?? null;

        // Fallback to enterprise settings if repo doesn't have token
        if (empty($githubToken)) {
            $githubSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            if ($githubSetting && !empty($githubSetting->setting_value)) {
                $encryption = new EncryptionService();
                $githubToken = $encryption->decrypt($githubSetting->setting_value);
            }
        }

        return $githubToken ?: null;
    }

    /**
     * Remove a label from a GitHub issue
     */
    public static function removeLabel(string $owner, string $repo, int $issueNumber, int $memberId, string $label): void {
        $logger = Flight::get('log');

        $githubToken = self::getTokenStatic($owner, $repo, $memberId);
        if (empty($githubToken)) {
            $logger->warning('No GitHub token available to remove label', [
                'owner' => $owner,
                'repo' => $repo,
                'issue' => $issueNumber
            ]);
            return;
        }

        try {
            $githubClient = new GitHubClient($githubToken);
            $githubClient->removeLabel($owner, $repo, $issueNumber, $label);
            $logger->info("Removed {$label} label from GitHub issue", [
                'owner' => $owner,
                'repo' => $repo,
                'issue' => $issueNumber
            ]);
        } catch (\Exception $e) {
            // 404 means label wasn't on the issue - that's OK
            if (strpos($e->getMessage(), '404') === false) {
                $logger->warning("Failed to remove {$label} label from GitHub issue", [
                    'owner' => $owner,
                    'repo' => $repo,
                    'issue' => $issueNumber,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Remove ai-dev and myctobot-working labels from GitHub issue (called on /exit)
     */
    public static function removeLabelsOnExit(string $owner, string $repo, int $issueNumber, int $memberId): void {
        self::removeLabel($owner, $repo, $issueNumber, $memberId, 'ai-dev');
        self::removeLabel($owner, $repo, $issueNumber, $memberId, 'myctobot-working');
    }

    /**
     * Format a clone URL with GitHub authentication
     * GitHub format: https://{token}@github.com/owner/repo.git
     *
     * Converts SSH URLs to HTTPS automatically (with warning)
     */
    public static function formatAuthenticatedUrl(string $cloneUrl, string $token): string {
        $logger = Flight::get('log');

        // Detect SSH URL and convert to HTTPS
        // SSH format: git@github.com:owner/repo.git
        if (preg_match('#^git@github\.com:(.+)$#', $cloneUrl, $matches)) {
            $logger->warning('Github: Converting SSH URL to HTTPS for authentication', [
                'original_url' => $cloneUrl,
                'note' => 'Consider updating repo connection to use HTTPS URL'
            ]);
            $cloneUrl = 'https://github.com/' . $matches[1];
        }

        if (empty($token)) {
            return $cloneUrl;
        }

        return preg_replace(
            '#^(https?://)(.*)$#',
            '$1' . $token . '@$2',
            $cloneUrl
        );
    }
}
