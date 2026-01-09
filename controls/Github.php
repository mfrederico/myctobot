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
     */
    public function repos() {
        if (!$this->requireLogin()) return;

        $memberId = $this->member->id;

        // Check if GitHub is connected (user database via Bean::)
        $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
        if (!$tokenSetting || empty($tokenSetting->setting_value)) {
            $this->flash('error', 'Please connect your GitHub account first.');
            Flight::redirect('/github/connect');
            return;
        }

        try {
            $token = EncryptionService::decrypt($tokenSetting->setting_value);
            $github = new GitHubClient($token);

            // Get user's repositories
            $repos = $github->getRepos(100);

            // Get currently connected repos (user database via Bean::)
            $connectedRepos = Bean::find('repoconnections', 'provider = ?', ['github']);
            $connectedMap = [];
            foreach ($connectedRepos as $repo) {
                $connectedMap[$repo->full_name] = $repo->export();
            }

            $this->render('github/repos', [
                'title' => 'GitHub Repositories',
                'repos' => $repos,
                'connectedRepos' => $connectedMap
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch GitHub repos', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to fetch repositories. Please reconnect GitHub.');
            Flight::redirect('/settings/connections');
        }
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

        // Check if already connected (user database via Bean::)
        $existing = Bean::findOne('repoconnections', 'full_name = ?', [$fullName]);
        if ($existing) {
            Flight::jsonError('Repository is already connected', 400);
            return;
        }

        try {
            // Generate webhook secret for this repo
            $webhookSecret = bin2hex(random_bytes(20));

            $repo = Bean::dispense('repoconnections');
            $repo->provider = 'github';
            $repo->full_name = $fullName;
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

                    [$owner, $repoName] = explode('/', $fullName);
                    $baseUrl = rtrim(Flight::get('app.baseurl') ?? 'https://myctobot.ai', '/');
                    $webhookUrl = $baseUrl . '/webhook/github';

                    // Check if webhook already exists
                    $existingHook = $github->findWebhook($owner, $repoName, $webhookUrl);
                    if ($existingHook) {
                        $webhookCreated = true;
                        $repo->webhook_id = $existingHook['id'];
                    } else {
                        // Create webhook
                        $hook = $github->createWebhook($owner, $repoName, $webhookUrl, $webhookSecret);
                        $repo->webhook_id = $hook['id'];
                        $webhookCreated = true;
                    }

                    // Ensure ai-dev label exists
                    $github->ensureAiDevLabel($owner, $repoName);

                    Bean::store($repo);
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
}
