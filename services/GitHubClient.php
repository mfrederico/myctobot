<?php
/**
 * GitHub Client Service
 * Handles GitHub OAuth 2.0 and API operations for Enterprise tier
 */

namespace app\services;

use \Flight as Flight;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GitHubClient {
    private Client $client;
    private string $accessToken;
    private const API_BASE = 'https://api.github.com';
    private const AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    /**
     * Create a GitHubClient with an access token
     */
    public function __construct(string $accessToken) {
        $this->accessToken = $accessToken;

        $this->client = new Client([
            'base_uri' => self::API_BASE,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
    }

    // ========================================
    // OAuth Methods (Static)
    // ========================================

    /**
     * Get GitHub OAuth login URL
     *
     * @param string $state Random state for CSRF protection
     * @return string The authorization URL
     */
    public static function getLoginUrl(string $state): string {
        $config = self::getConfig();
        $clientId = $config['client_id'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? Flight::get('github.redirect_uri') ?? '';

        if (empty($clientId)) {
            throw new \Exception('GitHub client_id not configured');
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'repo read:user',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback and exchange code for access token
     *
     * @param string $code Authorization code from GitHub
     * @param string $state State parameter for validation
     * @return array Token response containing access_token and user info
     */
    public static function handleCallback(string $code, string $state): array {
        $config = self::getConfig();
        $clientId = $config['client_id'] ?? '';
        $clientSecret = $config['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            throw new \Exception('GitHub OAuth not properly configured');
        }

        $client = new Client();

        // Exchange code for token
        $response = $client->post(self::TOKEN_URL, [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
            ],
        ]);

        $tokenData = json_decode($response->getBody()->getContents(), true);

        if (isset($tokenData['error'])) {
            throw new \Exception('GitHub OAuth error: ' . ($tokenData['error_description'] ?? $tokenData['error']));
        }

        if (empty($tokenData['access_token'])) {
            throw new \Exception('No access token received from GitHub');
        }

        // Get user info with the new token
        $ghClient = new self($tokenData['access_token']);
        $user = $ghClient->getCurrentUser();

        return [
            'access_token' => $tokenData['access_token'],
            'token_type' => $tokenData['token_type'] ?? 'bearer',
            'scope' => $tokenData['scope'] ?? '',
            'user' => $user,
        ];
    }

    /**
     * Check if GitHub OAuth is configured
     */
    public static function isConfigured(): bool {
        $config = self::getConfig();
        return !empty($config['client_id']) && !empty($config['client_secret']);
    }

    /**
     * Get GitHub config from Flight or fallback to conf/github.ini
     */
    private static function getConfig(): array {
        // Try Flight config first (tenant config)
        $clientId = Flight::get('github.client_id') ?? '';
        $clientSecret = Flight::get('github.client_secret') ?? '';

        if (!empty($clientId) && !empty($clientSecret)) {
            return [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => Flight::get('github.redirect_uri') ?? '',
            ];
        }

        // Fall back to conf/github.ini
        $iniPath = dirname(__DIR__) . '/conf/github.ini';
        if (file_exists($iniPath)) {
            $config = parse_ini_file($iniPath);
            if ($config && !empty($config['client_id']) && !empty($config['client_secret'])) {
                return $config;
            }
        }

        // Fall back to main config.ini [github] section
        $mainConfigPath = dirname(__DIR__) . '/conf/config.ini';
        if (file_exists($mainConfigPath)) {
            $mainConfig = parse_ini_file($mainConfigPath, true);
            if (!empty($mainConfig['github']['client_id']) && !empty($mainConfig['github']['client_secret'])) {
                return $mainConfig['github'];
            }
        }

        return [];
    }

    // ========================================
    // User Methods
    // ========================================

    /**
     * Get the authenticated user's information
     */
    public function getCurrentUser(): array {
        $response = $this->client->get('/user');
        return json_decode($response->getBody()->getContents(), true);
    }

    // ========================================
    // Repository Methods
    // ========================================

    /**
     * List repositories accessible to the authenticated user
     *
     * @param string $type Type of repos: all, owner, public, private, member
     * @param string $sort Sort by: created, updated, pushed, full_name
     * @param int $perPage Results per page (max 100)
     * @return array List of repositories
     */
    public function listRepositories(string $type = 'all', string $sort = 'updated', int $perPage = 30): array {
        $response = $this->client->get('/user/repos', [
            'query' => [
                'type' => $type,
                'sort' => $sort,
                'per_page' => min($perPage, 100),
            ],
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get a specific repository
     */
    public function getRepository(string $owner, string $repo): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get repository default branch
     */
    public function getDefaultBranch(string $owner, string $repo): string {
        $repoData = $this->getRepository($owner, $repo);
        return $repoData['default_branch'] ?? 'main';
    }

    // ========================================
    // Branch Methods
    // ========================================

    /**
     * Get a branch reference
     */
    public function getBranch(string $owner, string $repo, string $branch): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}/branches/{$branch}");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Create a new branch from a base branch
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $newBranch Name for the new branch
     * @param string $baseBranch Base branch to create from (default: repo's default branch)
     * @return array Created reference data
     */
    public function createBranch(string $owner, string $repo, string $newBranch, ?string $baseBranch = null): array {
        if (!$baseBranch) {
            $baseBranch = $this->getDefaultBranch($owner, $repo);
        }

        // Get the SHA of the base branch
        $baseRef = $this->getBranch($owner, $repo, $baseBranch);
        $baseSha = $baseRef['commit']['sha'];

        // Create the new branch
        $response = $this->client->post("/repos/{$owner}/{$repo}/git/refs", [
            'json' => [
                'ref' => "refs/heads/{$newBranch}",
                'sha' => $baseSha,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    // ========================================
    // File Operations
    // ========================================

    /**
     * Get file contents from a repository
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $path File path
     * @param string|null $ref Branch, tag, or commit (optional)
     * @return array File contents and metadata
     */
    public function getContents(string $owner, string $repo, string $path, ?string $ref = null): array {
        $query = [];
        if ($ref) {
            $query['ref'] = $ref;
        }

        $response = $this->client->get("/repos/{$owner}/{$repo}/contents/{$path}", [
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get decoded file content
     */
    public function getFileContent(string $owner, string $repo, string $path, ?string $ref = null): string {
        $contents = $this->getContents($owner, $repo, $path, $ref);

        if (!isset($contents['content'])) {
            throw new \Exception('File content not available');
        }

        return base64_decode($contents['content']);
    }

    /**
     * Create or update a file in a repository
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $path File path
     * @param string $content File content (will be base64 encoded)
     * @param string $message Commit message
     * @param string $branch Branch to commit to
     * @param string|null $sha SHA of file being replaced (required for updates)
     * @return array Commit data
     */
    public function createOrUpdateFile(
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message,
        string $branch,
        ?string $sha = null
    ): array {
        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => $branch,
        ];

        if ($sha) {
            $payload['sha'] = $sha;
        }

        $response = $this->client->put("/repos/{$owner}/{$repo}/contents/{$path}", [
            'json' => $payload,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Delete a file from a repository
     */
    public function deleteFile(
        string $owner,
        string $repo,
        string $path,
        string $message,
        string $sha,
        string $branch
    ): array {
        $response = $this->client->delete("/repos/{$owner}/{$repo}/contents/{$path}", [
            'json' => [
                'message' => $message,
                'sha' => $sha,
                'branch' => $branch,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    // ========================================
    // Pull Request Methods
    // ========================================

    /**
     * Create a pull request
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $title PR title
     * @param string $body PR description (markdown)
     * @param string $head Branch with changes
     * @param string $base Branch to merge into
     * @param bool $draft Create as draft PR
     * @return array Created PR data
     */
    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $body,
        string $head,
        string $base,
        bool $draft = false
    ): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/pulls", [
            'json' => [
                'title' => $title,
                'body' => $body,
                'head' => $head,
                'base' => $base,
                'draft' => $draft,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get a pull request
     */
    public function getPullRequest(string $owner, string $repo, int $prNumber): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}/pulls/{$prNumber}");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * List pull requests for a repository
     */
    public function listPullRequests(
        string $owner,
        string $repo,
        string $state = 'open',
        int $perPage = 30
    ): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}/pulls", [
            'query' => [
                'state' => $state,
                'per_page' => min($perPage, 100),
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Add a comment to a pull request
     */
    public function addPRComment(string $owner, string $repo, int $prNumber, string $body): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/issues/{$prNumber}/comments", [
            'json' => [
                'body' => $body,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    // ========================================
    // Tree Operations (for multi-file commits)
    // ========================================

    /**
     * Get a tree (directory structure)
     */
    public function getTree(string $owner, string $repo, string $treeSha, bool $recursive = false): array {
        $query = [];
        if ($recursive) {
            $query['recursive'] = '1';
        }

        $response = $this->client->get("/repos/{$owner}/{$repo}/git/trees/{$treeSha}", [
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Create a new tree
     */
    public function createTree(string $owner, string $repo, array $tree, ?string $baseTree = null): array {
        $payload = ['tree' => $tree];
        if ($baseTree) {
            $payload['base_tree'] = $baseTree;
        }

        $response = $this->client->post("/repos/{$owner}/{$repo}/git/trees", [
            'json' => $payload,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Create a commit
     */
    public function createCommit(
        string $owner,
        string $repo,
        string $message,
        string $treeSha,
        array $parents
    ): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/git/commits", [
            'json' => [
                'message' => $message,
                'tree' => $treeSha,
                'parents' => $parents,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Update a reference (branch head)
     */
    public function updateReference(string $owner, string $repo, string $ref, string $sha, bool $force = false): array {
        $response = $this->client->patch("/repos/{$owner}/{$repo}/git/refs/{$ref}", [
            'json' => [
                'sha' => $sha,
                'force' => $force,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Commit multiple files at once using the Git Data API
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $branch Branch to commit to
     * @param array $files Array of ['path' => string, 'content' => string]
     * @param string $message Commit message
     * @return array Commit data
     */
    public function commitMultipleFiles(
        string $owner,
        string $repo,
        string $branch,
        array $files,
        string $message
    ): array {
        // Get the current commit on the branch
        $branchData = $this->getBranch($owner, $repo, $branch);
        $currentCommitSha = $branchData['commit']['sha'];
        $currentTreeSha = $branchData['commit']['commit']['tree']['sha'];

        // Build tree entries for new/modified files
        $treeEntries = [];
        foreach ($files as $file) {
            $treeEntries[] = [
                'path' => $file['path'],
                'mode' => '100644',
                'type' => 'blob',
                'content' => $file['content'],
            ];
        }

        // Create a new tree
        $newTree = $this->createTree($owner, $repo, $treeEntries, $currentTreeSha);

        // Create a new commit
        $newCommit = $this->createCommit($owner, $repo, $message, $newTree['sha'], [$currentCommitSha]);

        // Update branch reference to point to new commit
        $this->updateReference($owner, $repo, "heads/{$branch}", $newCommit['sha']);

        return $newCommit;
    }

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Generate a URL for cloning with token authentication
     */
    public function getAuthenticatedCloneUrl(string $owner, string $repo): string {
        return "https://{$this->accessToken}@github.com/{$owner}/{$repo}.git";
    }

    /**
     * Check if repo exists and user has access
     */
    public function hasRepoAccess(string $owner, string $repo): bool {
        try {
            $this->getRepository($owner, $repo);
            return true;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Get rate limit status
     */
    public function getRateLimit(): array {
        $response = $this->client->get('/rate_limit');
        return json_decode($response->getBody()->getContents(), true);
    }

    // ========================================
    // Webhook Methods
    // ========================================

    /**
     * Create a webhook for a repository
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $webhookUrl URL to receive webhook events
     * @param string $secret Secret for webhook signature verification
     * @param array $events Events to subscribe to (default: issues, issue_comment)
     * @return array Created webhook data
     */
    public function createWebhook(
        string $owner,
        string $repo,
        string $webhookUrl,
        string $secret,
        array $events = ['issues', 'issue_comment']
    ): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/hooks", [
            'json' => [
                'name' => 'web',
                'active' => true,
                'events' => $events,
                'config' => [
                    'url' => $webhookUrl,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ],
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * List webhooks for a repository
     */
    public function listWebhooks(string $owner, string $repo): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}/hooks");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Delete a webhook
     */
    public function deleteWebhook(string $owner, string $repo, int $hookId): void {
        $this->client->delete("/repos/{$owner}/{$repo}/hooks/{$hookId}");
    }

    /**
     * Check if our webhook already exists on a repo
     */
    public function findWebhook(string $owner, string $repo, string $webhookUrl): ?array {
        try {
            $hooks = $this->listWebhooks($owner, $repo);
            foreach ($hooks as $hook) {
                if (($hook['config']['url'] ?? '') === $webhookUrl) {
                    return $hook;
                }
            }
        } catch (GuzzleException $e) {
            // No access or no hooks
        }
        return null;
    }

    // ========================================
    // Issue Methods
    // ========================================

    /**
     * Get an issue
     */
    public function getIssue(string $owner, string $repo, int $issueNumber): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}/issues/{$issueNumber}");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * List issues for a repository
     */
    public function listIssues(
        string $owner,
        string $repo,
        string $state = 'open',
        ?string $labels = null,
        int $perPage = 30
    ): array {
        $query = [
            'state' => $state,
            'per_page' => min($perPage, 100),
        ];
        if ($labels) {
            $query['labels'] = $labels;
        }

        $response = $this->client->get("/repos/{$owner}/{$repo}/issues", [
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Add a comment to an issue
     */
    public function addIssueComment(string $owner, string $repo, int $issueNumber, string $body): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/issues/{$issueNumber}/comments", [
            'json' => [
                'body' => $body,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Update an issue (change state, labels, assignees, etc.)
     */
    public function updateIssue(string $owner, string $repo, int $issueNumber, array $data): array {
        $response = $this->client->patch("/repos/{$owner}/{$repo}/issues/{$issueNumber}", [
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Close an issue
     */
    public function closeIssue(string $owner, string $repo, int $issueNumber): array {
        return $this->updateIssue($owner, $repo, $issueNumber, ['state' => 'closed']);
    }

    /**
     * Reopen an issue
     */
    public function reopenIssue(string $owner, string $repo, int $issueNumber): array {
        return $this->updateIssue($owner, $repo, $issueNumber, ['state' => 'open']);
    }

    /**
     * Add labels to an issue
     */
    public function addLabels(string $owner, string $repo, int $issueNumber, array $labels): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/issues/{$issueNumber}/labels", [
            'json' => ['labels' => $labels],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Remove a label from an issue
     */
    public function removeLabel(string $owner, string $repo, int $issueNumber, string $label): void {
        $this->client->delete("/repos/{$owner}/{$repo}/issues/{$issueNumber}/labels/" . urlencode($label));
    }

    /**
     * Get labels for a repository (to verify ai-dev label exists)
     */
    public function listLabels(string $owner, string $repo): array {
        $response = $this->client->get("/repos/{$owner}/{$repo}/labels");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Create a label in a repository
     */
    public function createLabel(string $owner, string $repo, string $name, string $color = 'c5def5', string $description = ''): array {
        $response = $this->client->post("/repos/{$owner}/{$repo}/labels", [
            'json' => [
                'name' => $name,
                'color' => $color,
                'description' => $description,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Ensure ai-dev label exists in repository
     */
    public function ensureAiDevLabel(string $owner, string $repo): array {
        try {
            $labels = $this->listLabels($owner, $repo);
            foreach ($labels as $label) {
                if ($label['name'] === 'ai-dev') {
                    return $label;
                }
            }
            // Create if not exists
            return $this->createLabel($owner, $repo, 'ai-dev', '7057ff', 'AI Developer will work on this issue');
        } catch (GuzzleException $e) {
            throw new \Exception('Failed to ensure ai-dev label: ' . $e->getMessage());
        }
    }

    /**
     * Get all repos the user has access to (paginated)
     */
    public function getRepos(int $limit = 100): array {
        $repos = [];
        $page = 1;
        $perPage = min($limit, 100);

        while (count($repos) < $limit) {
            $response = $this->client->get('/user/repos', [
                'query' => [
                    'sort' => 'updated',
                    'per_page' => $perPage,
                    'page' => $page,
                ],
            ]);

            $pageRepos = json_decode($response->getBody()->getContents(), true);
            if (empty($pageRepos)) {
                break;
            }

            $repos = array_merge($repos, $pageRepos);
            $page++;

            if (count($pageRepos) < $perPage) {
                break;
            }
        }

        return array_slice($repos, 0, $limit);
    }
}
