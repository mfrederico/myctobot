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
            $repo = Bean::dispense('repoconnections');
            $repo->provider = 'github';
            $repo->full_name = $fullName;
            $repo->default_branch = $defaultBranch;
            $repo->enabled = 1;
            $repo->created_at = date('Y-m-d H:i:s');
            Bean::store($repo);

            $this->logger->info('GitHub repo connected', [
                'member_id' => $memberId,
                'repo' => $fullName
            ]);

            Flight::jsonSuccess(['id' => $repo->id], 'Repository connected');

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
