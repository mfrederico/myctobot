<?php
/**
 * TmuxService - AI Developer tmux session management
 *
 * High-level service for:
 * - Spawning Claude Code sessions for AI Developer jobs
 * - Monitoring progress and status
 * - Health checking and error detection
 * - Session lifecycle management
 *
 * Uses AOE-PHP for session management with TmuxManager as fallback.
 * Session naming: aoe-{tenant}-{issue}-{shortId}
 */

namespace app\services;

use \app\TmuxManager;
use Aoe\Session\Instance as AoeInstance;
use Aoe\Session\Storage as AoeStorage;
use Aoe\Session\Status as AoeStatus;
use Aoe\Tmux\TmuxService as AoeTmux;

require_once __DIR__ . '/../lib/TmuxManager.php';

class TmuxService {

    private int $memberId;
    private string $issueKey;
    private string $sessionName;
    private string $localSessionName;
    private string $workDir;
    private ?string $repoPath = null;
    private ?string $activeSessionName = null;
    private ?AoeInstance $aoeSession = null;
    private ?string $tenantSlug = null;

    /**
     * Get the base path for aoe-php storage
     * Uses a consistent path that works for both CLI and web contexts
     *
     * @return string
     */
    private static function getAoeStoragePath(): string {
        // Check config first
        if (class_exists('\Flight') && \Flight::has('aoe.storage_path')) {
            $configPath = \Flight::get('aoe.storage_path');
            if ($configPath && is_dir($configPath)) {
                return $configPath;
            }
        }

        // Default to /tmp/.aoe-php (accessible by both CLI and web)
        $defaultPath = '/tmp/.aoe-php';
        if (is_dir($defaultPath)) {
            return $defaultPath;
        }

        // Fallback to project-relative path
        return dirname(__DIR__) . '/data/aoe';
    }

    /**
     * Create a TmuxService for a specific member and issue
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key (Jira like PROJ-123, or GitHub like owner/repo#123)
     * @param string|null $repoPath Optional repository path (for local runner)
     * @param string|null $tenant Tenant slug for AOE session naming
     */
    public function __construct(int $memberId, string $issueKey, ?string $repoPath = null, ?string $tenant = null) {
        $this->memberId = $memberId;
        $this->issueKey = $issueKey;
        $this->repoPath = $repoPath;
        $this->tenantSlug = $tenant ?: AoeTmux::getDomainId();

        // Try to find existing AOE session for this issue first
        $storage = new AoeStorage($this->tenantSlug, self::getAoeStoragePath());
        $existingSession = $this->findSessionByReference($storage, $issueKey);

        if ($existingSession) {
            // Use existing session
            $this->aoeSession = $existingSession;
        } else {
            // Create new AOE session for tracking and naming
            $this->aoeSession = AoeInstance::create(
                tenantId: $this->tenantSlug,
                title: $issueKey,
                projectPath: '/tmp',  // Will be updated below
                tool: 'claude',
                groupPath: $this->tenantSlug,  // Group = tenant for easy filtering
                reference: $issueKey  // Ticket ID visible in tmux session name
            );
        }

        // AOE-style session name: aoe-{tenant}-{issue}-{shortId}
        $this->sessionName = $this->aoeSession->getTmuxName();
        $this->localSessionName = $this->aoeSession->getTmuxName();  // Same for local runner
        $this->workDir = "/tmp/{$this->sessionName}";

        // Update AOE session with actual work dir
        $this->aoeSession->projectPath = $this->workDir;
    }

    /**
     * Find existing AOE session by issue reference
     * Uses loadAllSynced() to query tmux first (source of truth)
     */
    private function findSessionByReference(AoeStorage $storage, string $reference): ?AoeInstance {
        // Use synced method - queries tmux first, then matches with storage
        $sessions = $storage->loadAllSynced();
        foreach ($sessions as $session) {
            if ($session->reference === $reference) {
                return $session;
            }
        }
        return null;
    }

    /**
     * Find tmux session by pattern matching using aoe-php's TmuxService
     *
     * Job-executor creates sessions with pattern: aoe-{tenant}-{issue}-{shortUid}
     * Uses aoe-php's listSessions() which returns all aoe-{tenant}-* sessions.
     *
     * @param string $issueKey Issue key to search for
     * @return string|null Session name if found
     */
    private function findTmuxSessionByPattern(string $issueKey): ?string {
        $sanitizedIssue = AoeTmux::sanitize($issueKey);

        // Use aoe-php's TmuxService to list tenant sessions
        $aoeTmux = new AoeTmux($this->tenantSlug);
        $sessions = $aoeTmux->listSessions();

        // Sessions are keyed by sessionId, value has 'name' (full tmux name)
        foreach ($sessions as $sessionId => $info) {
            // Check if session ID starts with the issue key
            if (str_starts_with($sessionId, $sanitizedIssue . '-')) {
                return $info['name'];
            }
        }

        return null;
    }

    /**
     * Get the session name (aidev session, not local runner)
     */
    public function getSessionName(): string {
        return $this->sessionName;
    }

    /**
     * Get the local runner session name
     */
    public function getLocalSessionName(): string {
        return $this->localSessionName;
    }

    /**
     * Get the active session name (whichever one exists)
     */
    public function getActiveSessionName(): ?string {
        if ($this->activeSessionName) {
            return $this->activeSessionName;
        }

        // Search for session by pattern: aoe-{tenant}-{issue}-*
        // This finds both aoe-php sessions and job-executor sessions
        $found = $this->findTmuxSessionByPattern($this->issueKey);
        if ($found) {
            $this->activeSessionName = $found;
            return $this->activeSessionName;
        }

        return null;
    }

    /**
     * Get the AOE session instance (for tracking)
     */
    public function getAoeSession(): ?AoeInstance {
        return $this->aoeSession;
    }

    /**
     * Get the work directory path
     */
    public function getWorkDir(): string {
        return $this->workDir;
    }

    /**
     * Check if ANY tmux session exists (aidev or local-aidev)
     */
    public function exists(): bool {
        return $this->getActiveSessionName() !== null;
    }

    /**
     * Check if Claude is still running in the session
     */
    public function isClaudeRunning(): bool {
        $activeSession = $this->getActiveSessionName();
        if (!$activeSession) {
            return false;
        }
        return TmuxManager::isProcessRunning($activeSession, 'claude');
    }

    /**
     * Send /exit command to Claude to trigger graceful shutdown
     *
     * @return bool Success
     */
    public function sendExit(): bool {
        $activeSession = $this->getActiveSessionName();
        if (!$activeSession) {
            return false;
        }

        // Send /exit command to Claude (goes through SSH for remote sessions)
        TmuxManager::sendKeys($activeSession, '/exit');
        TmuxManager::sendKeys($activeSession, 'Enter');

        return true;
    }

    /**
     * Spawn a new tmux session running Claude Code
     *
     * @param string $prompt Initial prompt for Claude
     * @param bool $skipPermissions Use --dangerously-skip-permissions flag
     * @return bool Success
     */
    public function spawn(string $prompt = '', bool $skipPermissions = true): bool {
        if ($this->exists()) {
            return false; // Already exists
        }

        // Create work directory
        if (!is_dir($this->workDir)) {
            if (!mkdir($this->workDir, 0755, true)) {
                throw new \Exception("Failed to create work directory: {$this->workDir}");
            }
        }

        // Build invocation script
        $script = $this->buildInvocationScript($prompt, $skipPermissions);
        $scriptFile = $this->workDir . '/run-claude.sh';
        file_put_contents($scriptFile, $script);
        chmod($scriptFile, 0755);

        // Use TmuxManager to create the session
        try {
            $workingDir = $this->repoPath ?? dirname(__DIR__);
            TmuxManager::create($this->sessionName, $scriptFile, $workingDir);
        } catch (\Exception $e) {
            return false;
        }

        // Wait for Claude to initialize
        usleep(500000); // 500ms

        return $this->exists();
    }

    /**
     * Spawn using an existing PHP script (legacy support)
     *
     * @param string $scriptPath Path to PHP script
     * @param bool $orchestrator Use orchestrator mode
     * @param string|null $jobId Job ID for tracking
     * @param int|null $repoId Repository connection ID
     * @param string|null $tenant Tenant slug for multi-tenancy
     * @param string|null $provider Issue provider (jira, github)
     * @param array|null $workstation Workstation config: ['host'=>..., 'user'=>..., 'port'=>...]
     * @return bool Success
     */
    public function spawnWithScript(string $scriptPath, bool $orchestrator = true, ?string $jobId = null, ?int $repoId = null, ?string $tenant = null, ?string $provider = null, ?array $workstation = null): bool {
        if ($this->exists()) {
            return false;
        }

        @mkdir($this->workDir, 0755, true);

        // Save AOE session for tracking - reset status to Starting
        // This ensures the directive processor can detect if the session crashes
        if ($this->aoeSession) {
            $this->aoeSession->status = AoeStatus::Starting;
            $storage = new AoeStorage($this->tenantSlug, self::getAoeStoragePath());
            $storage->save($this->aoeSession);
        }

        $orchestratorFlag = $orchestrator ? '--orchestrator' : '';
        $jobIdFlag = $jobId ? sprintf('--job-id=%s', escapeshellarg($jobId)) : '';
        $repoIdFlag = $repoId ? sprintf('--repo=%d', $repoId) : '';
        $tenantFlag = $tenant ?: $this->tenantSlug;
        $tenantFlagStr = $tenantFlag ? sprintf('--tenant=%s', escapeshellarg($tenantFlag)) : '';
        $providerFlag = $provider ? sprintf('--provider=%s', escapeshellarg($provider)) : '';

        // Pass AOE session ID so job-dispatcher.php uses the same session
        $aoeSessionFlag = $this->aoeSession ? sprintf('--aoe-session=%s', escapeshellarg($this->aoeSession->id)) : '';

        // Pass workstation config if provided (runs Claude on remote workstation via SSH)
        $workstationFlag = '';
        if ($workstation && !empty($workstation['host']) && !empty($workstation['user'])) {
            $workstationFlag = sprintf('--workstation=%s', escapeshellarg(json_encode($workstation)));
        }

        // Log file for capturing errors from job-dispatcher.php
        $logFile = $this->workDir . '/spawn.log';

        $command = sprintf(
            'php %s --issue=%s --member=%d %s %s %s %s %s %s %s 2>&1 | tee %s',
            escapeshellarg($scriptPath),
            escapeshellarg($this->issueKey),
            $this->memberId,
            $orchestratorFlag,
            $jobIdFlag,
            $repoIdFlag,
            $tenantFlagStr,
            $providerFlag,
            $aoeSessionFlag,
            $workstationFlag,
            escapeshellarg($logFile)
        );

        try {
            TmuxManager::create($this->sessionName, $command);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build the invocation script that sets up environment and runs Claude
     */
    private function buildInvocationScript(string $prompt, bool $skipPermissions): string {
        $projectRoot = dirname(__DIR__);
        $timestamp = date('Y-m-d H:i:s');
        $workspaceDir = $this->repoPath ?? $projectRoot;

        $claudeCmd = 'claude --debug';
        if ($skipPermissions) {
            $claudeCmd .= ' --dangerously-skip-permissions';
        }
        if (!empty($prompt)) {
            // Write prompt to file to avoid shell escaping issues
            $promptFile = $this->workDir . '/initial-prompt.txt';
            file_put_contents($promptFile, $prompt);
            $claudeCmd .= ' -p "$(cat ' . escapeshellarg($promptFile) . ')"';
        }

        return <<<BASH
#!/bin/bash
#
# MyCTOBot AI Developer Session
#

# Ensure PATH includes common binary locations (Claude CLI, npm global, etc.)
export PATH="\$HOME/.local/bin:\$HOME/.npm-global/bin:/usr/local/bin:\$PATH"

# Export environment variables for hooks and child processes
export MYCTOBOT_MEMBER_ID={$this->memberId}
export MYCTOBOT_ISSUE_KEY="{$this->issueKey}"
export MYCTOBOT_SESSION_NAME="{$this->sessionName}"
export MYCTOBOT_PROJECT_ROOT="{$projectRoot}"
export MYCTOBOT_WORKSPACE="{$workspaceDir}"

# Allow larger Claude outputs
export CLAUDE_CODE_MAX_OUTPUT_TOKENS=250000

echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║                 MYCTOBOT AI DEVELOPER SESSION                    ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""
echo "  Session:     {$this->sessionName}"
echo "  Issue Key:   {$this->issueKey}"
echo "  Member ID:   {$this->memberId}"
echo "  Started:     {$timestamp}"
echo ""
echo "  Project:     {$projectRoot}"
echo "  Workspace:   {$workspaceDir}"
echo "  Work Dir:    {$this->workDir}"
echo ""
echo "────────────────────────────────────────────────────────────────────"
echo "  Invoking: {$claudeCmd}"
echo "────────────────────────────────────────────────────────────────────"
echo ""

# Function to auto-accept bypass permissions dialog
auto_accept_permissions() {
    local session="{$this->sessionName}"
    local max_attempts=10
    local attempt=0

    while [ \$attempt -lt \$max_attempts ]; do
        sleep 0.5
        local content=\$(tmux capture-pane -t "\$session" -p 2>/dev/null)
        if echo "\$content" | grep -q "Bypass Permissions mode"; then
            sleep 0.3
            tmux send-keys -t "\$session" Down 2>/dev/null
            sleep 0.1
            tmux send-keys -t "\$session" Enter 2>/dev/null
            echo "  [Auto-accepted bypass permissions dialog]"
            return 0
        fi
        if echo "\$content" | grep -q "Claude Code"; then
            return 0
        fi
        attempt=\$((attempt + 1))
    done
}

# Start the auto-accept watcher in background
auto_accept_permissions &
WATCHER_PID=\$!

# Change to workspace directory and run Claude
cd "{$workspaceDir}"
{$claudeCmd}
EXIT_CODE=\$?

# Kill the watcher if still running
kill \$WATCHER_PID 2>/dev/null

echo ""
echo "────────────────────────────────────────────────────────────────────"
echo "  Claude exited with code: \$EXIT_CODE"
echo "────────────────────────────────────────────────────────────────────"

echo ""
echo "Session complete. Press Enter to close."
read
BASH;
    }

    /**
     * Kill the tmux session (whichever is active, or both)
     */
    public function kill(): bool {
        $killed = false;
        // Kill both session types if they exist
        if (TmuxManager::exists($this->sessionName)) {
            $killed = TmuxManager::kill($this->sessionName) || $killed;
        }
        if (TmuxManager::exists($this->localSessionName)) {
            $killed = TmuxManager::kill($this->localSessionName) || $killed;
        }
        $this->activeSessionName = null;
        return $killed;
    }

    /**
     * Send a message to the running Claude session
     *
     * @param string $message The message to send
     * @return bool Success
     */
    public function sendMessage(string $message): bool {
        $activeSession = $this->getActiveSessionName();
        if (!$activeSession) {
            return false;
        }

        // For long messages, use buffer
        if (strlen($message) > 500) {
            if (!TmuxManager::sendTextViaBuffer($activeSession, $message)) {
                // Fallback to direct send
                return $this->sendDirect($message);
            }
        } else {
            if (!TmuxManager::sendKeys($activeSession, $message, true)) {
                return false;
            }
        }

        // Send Enter to submit
        usleep(100000); // 100ms
        TmuxManager::sendKeys($activeSession, 'Enter');

        // Second Enter for confirmation
        usleep(200000); // 200ms
        TmuxManager::sendKeys($activeSession, 'Enter');

        return true;
    }

    /**
     * Send message directly (for short messages)
     */
    private function sendDirect(string $message): bool {
        $activeSession = $this->getActiveSessionName();
        if (!$activeSession) {
            return false;
        }

        // Escape special characters
        $escaped = str_replace(
            ["'", '"', '\\', '$', '`'],
            ["\\'", '\\"', '\\\\', '\\$', '\\`'],
            $message
        );

        if (!TmuxManager::sendKeys($activeSession, $escaped)) {
            return false;
        }

        return TmuxManager::sendKeys($activeSession, 'Enter');
    }

    /**
     * Capture a snapshot of the current tmux pane content
     *
     * @param int $lines Number of lines to capture
     * @return string The captured content
     */
    public function captureSnapshot(int $lines = 100): string {
        $activeSession = $this->getActiveSessionName();
        if (!$activeSession) {
            return '';
        }
        return TmuxManager::capture($activeSession, $lines);
    }

    /**
     * Get session status summary
     *
     * @return array Status info
     */
    public function getStatus(): array {
        $exists = $this->exists();
        $claudeRunning = $exists ? $this->isClaudeRunning() : false;

        return [
            'member_id' => $this->memberId,
            'issue_key' => $this->issueKey,
            'session_name' => $this->sessionName,
            'work_dir' => $this->workDir,
            'exists' => $exists,
            'claude_running' => $claudeRunning,
            'log_file' => $this->workDir . '/output.log',
            'log_exists' => file_exists($this->workDir . '/output.log'),
        ];
    }

    /**
     * Get progress information from the session
     *
     * @return array Progress data
     */
    public function getProgress(): array {
        $snapshot = $this->captureSnapshot(50);

        if (empty($snapshot)) {
            return [
                'status' => 'unknown',
                'last_activity' => null,
                'current_task' => null,
                'files_changed' => [],
                'last_lines' => []
            ];
        }

        $lines = array_filter(array_map('trim', explode("\n", $snapshot)));
        $lastLines = array_slice($lines, -10);

        // Detect current activity
        $currentTask = $this->detectCurrentTask($lines);
        $filesChanged = $this->detectFilesChanged($lines);
        $status = $this->detectStatus($lines);

        return [
            'status' => $status,
            'last_activity' => date('Y-m-d H:i:s'),
            'current_task' => $currentTask,
            'files_changed' => $filesChanged,
            'last_lines' => $lastLines
        ];
    }

    /**
     * Detect current task from output lines
     */
    private function detectCurrentTask(array $lines): ?string {
        $patterns = [
            '/gh pr create/i' => 'Creating pull request',
            '/git push /i' => 'Pushing changes',
            '/git commit /i' => 'Committing changes',
            '/Read\s+tool|Reading\s+\S+\.(php|js|ts|json)/i' => 'Reading files',
            '/Write\s+tool|Writing\s+to\s+\S+/i' => 'Writing files',
            '/Edit\s+tool|Editing\s+\S+\.(php|js|ts|json)/i' => 'Editing files',
            '/Grep\s+tool|Glob\s+tool/i' => 'Searching codebase',
            '/Bash\s+tool|Running\s+command/i' => 'Running command',
            '/npm test|pytest|phpunit/i' => 'Running tests',
            '/TodoWrite/i' => 'Planning tasks',
            '/Task\s+tool|Agent/i' => 'Running sub-agent',
        ];

        $recentLines = array_slice($lines, -20);
        foreach (array_reverse($recentLines) as $line) {
            foreach ($patterns as $pattern => $task) {
                if (preg_match($pattern, $line)) {
                    return $task;
                }
            }
        }

        // Check for thinking indicator
        $allText = implode("\n", $recentLines);
        if (preg_match('/esc to interrupt/i', $allText)) {
            return 'Thinking...';
        }

        if ($this->isClaudeRunning()) {
            return 'Working...';
        }

        return null;
    }

    /**
     * Detect files that have been changed
     */
    private function detectFilesChanged(array $lines): array {
        $files = [];
        $pattern = '/(editing|wrote|modified|created|Writing to|Editing)\s+[\'"]?([^\s\'"]+\.(php|js|ts|json|md|css|html|vue|py))[\'"]?/i';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                $file = $matches[2];
                if (!in_array($file, $files)) {
                    $files[] = $file;
                }
            }
        }

        return array_slice($files, -10);
    }

    /**
     * Detect overall status from output
     */
    public function detectStatus(array $lines = []): string {
        if (empty($lines)) {
            $activeSession = $this->getActiveSessionName();
            $content = $activeSession ? TmuxManager::capture($activeSession, 50) : '';
            $lines = explode("\n", $content);
        }

        $recentLines = array_slice($lines, -15);

        $lastLines = implode("\n", $recentLines);

        // Check for session complete
        if (preg_match('/Session complete|Claude exited with code: 0/i', $lastLines)) {
            return 'completed';
        }

        // Check for errors
        if (preg_match('/Claude exited with code: [1-9]|Fatal error:|PHP Parse error:|API Error:/i', $lastLines)) {
            return 'error';
        }

        // Simple detection: "(ctrl+c to interrupt" = working, anything else = idle
        // Format: (ctrl+c to interrupt · 2m 37s · ↑ 1.0k tokens)
        if (preg_match('/\(ctrl\+c to interrupt/i', $lastLines)) {
            return 'working';
        }

        // If Claude process is running but not showing "ctrl+c to interrupt", it's waiting/idle
        if ($this->isClaudeRunning()) {
            return 'waiting';
        }

        // If session exists but Claude not running, it completed
        if ($this->exists()) {
            return 'completed';
        }

        return 'unknown';
    }

    /**
     * Check if the session is hung (error + waiting at prompt)
     *
     * @return bool True if session appears hung
     */
    public function isHung(): bool {
        if (!$this->exists()) {
            return false;
        }

        $progress = $this->getProgress();

        if ($progress['status'] === 'error') {
            return true;
        }

        // Check if waiting at prompt after an API error
        if ($progress['status'] === 'waiting') {
            $snapshot = $this->captureSnapshot(100);
            if (preg_match('/API Error:/i', $snapshot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract error message from session output if present
     *
     * @return string|null Error message or null
     */
    public function getErrorMessage(): ?string {
        if (!$this->exists()) {
            return null;
        }

        $snapshot = $this->captureSnapshot(100);

        // Look for API Error
        if (preg_match('/API Error:\s*(.+?)(?:\.\s*To configure|$)/i', $snapshot, $matches)) {
            return 'API Error: ' . trim($matches[1]);
        }

        // Look for Claude exit code
        if (preg_match('/Claude exited with code:\s*(\d+)/i', $snapshot, $matches)) {
            return 'Claude exited with code: ' . $matches[1];
        }

        // Look for PHP errors
        if (preg_match('/(Fatal error:|PHP Parse error:)\s*(.+)/i', $snapshot, $matches)) {
            return $matches[1] . ' ' . trim($matches[2]);
        }

        return null;
    }

    /**
     * Check and return health status
     *
     * @return array Status info with is_hung, error_message, status
     */
    public function checkHealth(): array {
        $result = [
            'is_hung' => false,
            'error_message' => null,
            'status' => 'running'
        ];

        if (!$this->exists()) {
            $result['status'] = 'session_not_found';
            return $result;
        }

        $progress = $this->getProgress();
        $result['status'] = $progress['status'];

        if ($this->isHung()) {
            $result['is_hung'] = true;
            $result['error_message'] = $this->getErrorMessage();
        }

        return $result;
    }

    /**
     * Get the last N lines of the output log file
     *
     * @param int $lines Number of lines
     * @return string Log content
     */
    public function getLogTail(int $lines = 50): string {
        $logFile = $this->workDir . '/output.log';

        if (!file_exists($logFile)) {
            return '';
        }

        exec(sprintf('tail -n %d %s 2>/dev/null', $lines, escapeshellarg($logFile)), $output);
        return implode("\n", $output);
    }

    // =========================================
    // Static helper methods
    // =========================================

    /**
     * List all active aidev tmux sessions
     *
     * @return array List of session info
     */
    public static function listAllSessions(): array {
        $sessions = [];

        foreach (TmuxManager::listAiDevSessions() as $session) {
            $parsed = TmuxManager::parseSessionName($session['name']);
            if ($parsed) {
                $service = new self($parsed['member_id'], $parsed['issue_key']);
                $status = $service->getStatus();
                $status['created'] = $session['created'];
                $status['attached'] = $session['attached'];
                $sessions[] = $status;
            }
        }

        return $sessions;
    }

    /**
     * Find session by issue key (any member)
     *
     * @param string $issueKey
     * @return TmuxService|null
     */
    public static function findByIssue(string $issueKey): ?TmuxService {
        foreach (TmuxManager::listAiDevSessions() as $session) {
            $parsed = TmuxManager::parseSessionName($session['name']);
            if ($parsed && $parsed['issue_key'] === $issueKey) {
                return new self($parsed['member_id'], $issueKey);
            }
        }

        return null;
    }

    /**
     * Clean up orphaned work directories
     *
     * @param int $maxAgeSeconds Max age in seconds (default 24 hours)
     */
    public static function cleanupWorkDirs(int $maxAgeSeconds = 86400): void {
        $pattern = '/tmp/aidev-*';
        $dirs = glob($pattern, GLOB_ONLYDIR);

        if (!$dirs) return;

        $cutoff = time() - $maxAgeSeconds;

        foreach ($dirs as $dir) {
            $mtime = filemtime($dir);
            if ($mtime && $mtime < $cutoff) {
                // Check if there's an active session for this dir
                $sessionName = basename($dir);
                $sessionName = 'aidev-' . substr($sessionName, strlen('aidev-'));
                if (!TmuxManager::exists($sessionName)) {
                    self::removeDirectory($dir);
                }
            }
        }
    }

    /**
     * Recursively remove a directory
     */
    private static function removeDirectory(string $dir): void {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
