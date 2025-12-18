<?php
/**
 * Git Operations Service
 * Handles local git operations for the AI Developer agent
 * Uses shell commands for clone, branch, commit, push operations
 */

namespace app\services;

use \Flight as Flight;

class GitOperations {
    private string $workDir;
    private string $repoDir = '';
    private bool $initialized = false;

    /**
     * Create a new GitOperations instance
     *
     * @param string|null $workDir Base working directory (defaults to system temp)
     */
    public function __construct(?string $workDir = null) {
        $this->workDir = $workDir ?? sys_get_temp_dir() . '/myctobot_git';

        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0755, true);
        }
    }

    /**
     * Clone a repository with authentication
     *
     * @param string $cloneUrl Repository URL
     * @param string $token Access token for authentication
     * @param string|null $targetDir Specific directory name (auto-generated if null)
     * @return string Path to cloned repository
     */
    public function cloneRepository(string $cloneUrl, string $token, ?string $targetDir = null): string {
        // Generate unique directory name if not provided
        if (!$targetDir) {
            $targetDir = 'repo_' . bin2hex(random_bytes(8));
        }

        $this->repoDir = $this->workDir . '/' . $targetDir;

        // Clean up if directory exists
        if (is_dir($this->repoDir)) {
            $this->removeDirectory($this->repoDir);
        }

        // Insert token into clone URL for authentication
        $authenticatedUrl = $this->injectTokenIntoUrl($cloneUrl, $token);

        // Clone the repository
        $result = $this->execute(
            sprintf('git clone --depth 1 %s %s 2>&1',
                escapeshellarg($authenticatedUrl),
                escapeshellarg($this->repoDir)
            ),
            $this->workDir
        );

        if ($result['code'] !== 0) {
            throw new \Exception('Git clone failed: ' . $result['output']);
        }

        $this->initialized = true;
        return $this->repoDir;
    }

    /**
     * Clone with full history (needed for branching)
     */
    public function cloneRepositoryFull(string $cloneUrl, string $token, ?string $targetDir = null): string {
        if (!$targetDir) {
            $targetDir = 'repo_' . bin2hex(random_bytes(8));
        }

        $this->repoDir = $this->workDir . '/' . $targetDir;

        if (is_dir($this->repoDir)) {
            $this->removeDirectory($this->repoDir);
        }

        $authenticatedUrl = $this->injectTokenIntoUrl($cloneUrl, $token);

        $result = $this->execute(
            sprintf('git clone %s %s 2>&1',
                escapeshellarg($authenticatedUrl),
                escapeshellarg($this->repoDir)
            ),
            $this->workDir
        );

        if ($result['code'] !== 0) {
            throw new \Exception('Git clone failed: ' . $result['output']);
        }

        $this->initialized = true;
        return $this->repoDir;
    }

    /**
     * Fetch latest changes from remote
     */
    public function fetch(): void {
        $this->ensureInitialized();

        $result = $this->execute('git fetch origin 2>&1');
        if ($result['code'] !== 0) {
            throw new \Exception('Git fetch failed: ' . $result['output']);
        }
    }

    /**
     * Create and checkout a new branch
     *
     * @param string $branchName Name of the new branch
     * @param string|null $baseBranch Branch to base from (default: current branch)
     */
    public function createBranch(string $branchName, ?string $baseBranch = null): void {
        $this->ensureInitialized();

        // If base branch specified, checkout it first
        if ($baseBranch) {
            $result = $this->execute(sprintf('git checkout %s 2>&1', escapeshellarg($baseBranch)));
            if ($result['code'] !== 0) {
                throw new \Exception('Git checkout base branch failed: ' . $result['output']);
            }

            // Pull latest
            $this->execute('git pull origin ' . escapeshellarg($baseBranch) . ' 2>&1');
        }

        // Create and checkout new branch
        $result = $this->execute(sprintf('git checkout -b %s 2>&1', escapeshellarg($branchName)));
        if ($result['code'] !== 0) {
            throw new \Exception('Git create branch failed: ' . $result['output']);
        }
    }

    /**
     * Checkout an existing branch
     */
    public function checkout(string $branchName): void {
        $this->ensureInitialized();

        $result = $this->execute(sprintf('git checkout %s 2>&1', escapeshellarg($branchName)));
        if ($result['code'] !== 0) {
            throw new \Exception('Git checkout failed: ' . $result['output']);
        }
    }

    /**
     * Get the current branch name
     */
    public function getCurrentBranch(): string {
        $this->ensureInitialized();

        $result = $this->execute('git branch --show-current 2>&1');
        if ($result['code'] !== 0) {
            throw new \Exception('Git get branch failed: ' . $result['output']);
        }

        return trim($result['output']);
    }

    /**
     * Stage files for commit
     *
     * @param array|string $files File paths to stage, or '.' for all
     */
    public function add($files): void {
        $this->ensureInitialized();

        if (is_array($files)) {
            $files = implode(' ', array_map('escapeshellarg', $files));
        } else {
            $files = escapeshellarg($files);
        }

        $result = $this->execute("git add {$files} 2>&1");
        if ($result['code'] !== 0) {
            throw new \Exception('Git add failed: ' . $result['output']);
        }
    }

    /**
     * Commit staged changes
     *
     * @param string $message Commit message
     * @param string|null $author Author string (e.g., "Name <email>")
     */
    public function commit(string $message, ?string $author = null): string {
        $this->ensureInitialized();

        // Configure git user if not set
        $this->execute('git config user.email "ai-dev@myctobot.ai" 2>&1');
        $this->execute('git config user.name "MyCTOBot AI Developer" 2>&1');

        $cmd = 'git commit -m ' . escapeshellarg($message);
        if ($author) {
            $cmd .= ' --author=' . escapeshellarg($author);
        }

        $result = $this->execute($cmd . ' 2>&1');

        // Check if there was nothing to commit
        if ($result['code'] !== 0 && strpos($result['output'], 'nothing to commit') !== false) {
            return ''; // No commit made
        }

        if ($result['code'] !== 0) {
            throw new \Exception('Git commit failed: ' . $result['output']);
        }

        // Get the commit SHA
        $shaResult = $this->execute('git rev-parse HEAD 2>&1');
        return trim($shaResult['output']);
    }

    /**
     * Push changes to remote
     *
     * @param string $remote Remote name (default: origin)
     * @param string|null $branch Branch to push (default: current branch)
     * @param bool $setUpstream Set upstream tracking
     */
    public function push(string $remote = 'origin', ?string $branch = null, bool $setUpstream = true): void {
        $this->ensureInitialized();

        if (!$branch) {
            $branch = $this->getCurrentBranch();
        }

        $cmd = sprintf('git push %s %s', escapeshellarg($remote), escapeshellarg($branch));
        if ($setUpstream) {
            $cmd .= ' -u';
        }

        $result = $this->execute($cmd . ' 2>&1');
        if ($result['code'] !== 0) {
            throw new \Exception('Git push failed: ' . $result['output']);
        }
    }

    /**
     * Get the status of the repository
     */
    public function status(): array {
        $this->ensureInitialized();

        $result = $this->execute('git status --porcelain 2>&1');
        if ($result['code'] !== 0) {
            throw new \Exception('Git status failed: ' . $result['output']);
        }

        $files = [];
        $lines = explode("\n", trim($result['output']));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $status = substr($line, 0, 2);
            $file = trim(substr($line, 3));
            $files[] = [
                'status' => $status,
                'file' => $file,
            ];
        }

        return $files;
    }

    /**
     * Get diff of changes
     *
     * @param bool $staged Show only staged changes
     */
    public function diff(bool $staged = false): string {
        $this->ensureInitialized();

        $cmd = 'git diff';
        if ($staged) {
            $cmd .= ' --staged';
        }

        $result = $this->execute($cmd . ' 2>&1');
        return $result['output'];
    }

    /**
     * Read a file from the repository
     */
    public function readFile(string $path): string {
        $this->ensureInitialized();
        $fullPath = $this->repoDir . '/' . ltrim($path, '/');

        if (!file_exists($fullPath)) {
            throw new \Exception("File not found: {$path}");
        }

        return file_get_contents($fullPath);
    }

    /**
     * Write content to a file in the repository
     */
    public function writeFile(string $path, string $content): void {
        $this->ensureInitialized();
        $fullPath = $this->repoDir . '/' . ltrim($path, '/');

        // Create directory if needed
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);
    }

    /**
     * Delete a file from the repository
     */
    public function deleteFile(string $path): void {
        $this->ensureInitialized();
        $fullPath = $this->repoDir . '/' . ltrim($path, '/');

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * List files in the repository
     *
     * @param string $path Subdirectory path (empty for root)
     * @param bool $recursive Include subdirectories
     */
    public function listFiles(string $path = '', bool $recursive = false): array {
        $this->ensureInitialized();
        $fullPath = $this->repoDir . ($path ? '/' . ltrim($path, '/') : '');

        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS))
            : new \DirectoryIterator($fullPath);

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($this->repoDir . '/', '', $file->getPathname());
                // Skip .git directory
                if (strpos($relativePath, '.git/') === 0) continue;
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    /**
     * Get the repository directory path
     */
    public function getRepoDir(): string {
        return $this->repoDir;
    }

    /**
     * Clean up - remove the cloned repository
     */
    public function cleanup(): void {
        if ($this->repoDir && is_dir($this->repoDir)) {
            $this->removeDirectory($this->repoDir);
            $this->repoDir = '';
            $this->initialized = false;
        }
    }

    /**
     * Check if repository is initialized
     */
    public function isInitialized(): bool {
        return $this->initialized && !empty($this->repoDir) && is_dir($this->repoDir);
    }

    // ========================================
    // Private Helper Methods
    // ========================================

    /**
     * Ensure the repository is initialized before operations
     */
    private function ensureInitialized(): void {
        if (!$this->initialized || !$this->repoDir) {
            throw new \Exception('Repository not initialized. Call cloneRepository first.');
        }
    }

    /**
     * Execute a shell command
     */
    private function execute(string $command, ?string $cwd = null): array {
        $cwd = $cwd ?? $this->repoDir;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, null);

        if (!is_resource($process)) {
            return ['code' => -1, 'output' => 'Failed to execute command'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'code' => $code,
            'output' => $stdout . $stderr,
        ];
    }

    /**
     * Inject authentication token into a git URL
     */
    private function injectTokenIntoUrl(string $url, string $token): string {
        // Handle HTTPS URLs
        if (preg_match('#^https://([^/]+)/(.+)$#', $url, $matches)) {
            return "https://{$token}@{$matches[1]}/{$matches[2]}";
        }

        // Handle git:// URLs - convert to HTTPS with token
        if (preg_match('#^git://([^/]+)/(.+)$#', $url, $matches)) {
            return "https://{$token}@{$matches[1]}/{$matches[2]}";
        }

        // Handle SSH URLs - convert to HTTPS with token
        if (preg_match('#^git@([^:]+):(.+)$#', $url, $matches)) {
            $repo = preg_replace('/\.git$/', '', $matches[2]) . '.git';
            return "https://{$token}@{$matches[1]}/{$repo}";
        }

        // If URL already has credentials, replace them
        if (preg_match('#^https://[^:]+:[^@]+@(.+)$#', $url, $matches)) {
            return "https://{$token}@{$matches[1]}";
        }

        // Return as-is if format not recognized
        return $url;
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Destructor - clean up on object destruction
     */
    public function __destruct() {
        // Optionally auto-cleanup (disabled by default to allow explicit control)
        // $this->cleanup();
    }
}
