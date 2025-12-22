<?php
/**
 * TmuxService - Manages local Claude Code tmux sessions
 *
 * Provides a clean interface for:
 * - Spawning/killing tmux sessions
 * - Sending messages to running sessions
 * - Capturing snapshots of session output for admin UI
 * - Checking session and process status
 */

namespace app\services;

class TmuxService {

    private int $memberId;
    private string $issueKey;
    private string $sessionName;
    private string $workDir;

    /**
     * Create a TmuxService for a specific member and issue
     */
    public function __construct(int $memberId, string $issueKey) {
        $this->memberId = $memberId;
        $this->issueKey = $issueKey;
        $this->sessionName = "aidev-{$memberId}-{$issueKey}";
        $this->workDir = "/tmp/local-aidev-{$memberId}-{$issueKey}";
    }

    /**
     * Get the session name
     */
    public function getSessionName(): string {
        return $this->sessionName;
    }

    /**
     * Get the work directory path
     */
    public function getWorkDir(): string {
        return $this->workDir;
    }

    /**
     * Check if the tmux session exists
     */
    public function exists(): bool {
        exec("tmux has-session -t " . escapeshellarg($this->sessionName) . " 2>/dev/null", $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Check if Claude is still running in the session
     */
    public function isClaudeRunning(): bool {
        if (!$this->exists()) {
            return false;
        }

        // Get the pane PID
        exec("tmux list-panes -t " . escapeshellarg($this->sessionName) . " -F '#{pane_pid}' 2>/dev/null", $output);
        if (empty($output)) {
            return false;
        }

        $panePid = trim($output[0]);

        // Check if claude process is running under this pane
        exec("pgrep -P {$panePid} -f 'claude' 2>/dev/null", $procs);
        return !empty($procs);
    }

    /**
     * Spawn a new tmux session running the local AI developer
     *
     * @param string $scriptPath Path to local-aidev-full.php
     * @param bool $orchestrator Use orchestrator mode
     * @return bool Success
     */
    public function spawn(string $scriptPath, bool $orchestrator = true): bool {
        if ($this->exists()) {
            return false; // Already exists
        }

        // Ensure work directory exists
        @mkdir($this->workDir, 0755, true);

        $orchestratorFlag = $orchestrator ? '--orchestrator' : '';

        // Run script directly in tmux - no pipes that would break TTY
        $cmd = sprintf(
            'tmux new-session -d -s %s "php %s --issue=%s --member=%d %s"',
            escapeshellarg($this->sessionName),
            escapeshellarg($scriptPath),
            escapeshellarg($this->issueKey),
            $this->memberId,
            $orchestratorFlag
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Kill the tmux session
     */
    public function kill(): bool {
        if (!$this->exists()) {
            return false;
        }

        exec("tmux kill-session -t " . escapeshellarg($this->sessionName) . " 2>/dev/null", $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Send a message to the running Claude session
     *
     * @param string $message The message to send
     * @return bool Success
     */
    public function sendMessage(string $message): bool {
        if (!$this->exists()) {
            return false;
        }

        // Send the message text
        exec("tmux send-keys -t " . escapeshellarg($this->sessionName) . " " . escapeshellarg($message) . " 2>/dev/null", $output, $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        // Small delay then send Enter
        usleep(100000); // 100ms
        exec("tmux send-keys -t " . escapeshellarg($this->sessionName) . " Enter 2>/dev/null");

        // Another Enter to confirm
        usleep(200000); // 200ms
        exec("tmux send-keys -t " . escapeshellarg($this->sessionName) . " Enter 2>/dev/null");

        return true;
    }

    /**
     * Capture a snapshot of the current tmux pane content
     *
     * @param int $lines Number of lines to capture (default 100)
     * @return string The captured content
     */
    public function captureSnapshot(int $lines = 100): string {
        if (!$this->exists()) {
            return '';
        }

        // Capture the visible pane content plus scrollback
        $cmd = sprintf(
            'tmux capture-pane -t %s -p -S -%d 2>/dev/null',
            escapeshellarg($this->sessionName),
            $lines
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return '';
        }

        return implode("\n", $output);
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

        $cmd = sprintf('tail -n %d %s 2>/dev/null', $lines, escapeshellarg($logFile));
        exec($cmd, $output, $exitCode);

        return implode("\n", $output);
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
     * Get a parsed progress summary from the session
     * Extracts key information like current task, files changed, etc.
     *
     * @return array Progress info
     */
    public function getProgress(): array {
        $snapshot = $this->captureSnapshot(200);

        if (empty($snapshot)) {
            return [
                'status' => 'unknown',
                'last_activity' => null,
                'current_task' => null,
                'files_changed' => [],
            ];
        }

        $progress = [
            'status' => 'running',
            'last_activity' => date('Y-m-d H:i:s'),
            'current_task' => null,
            'files_changed' => [],
            'last_lines' => [],
        ];

        // Get last 10 non-empty lines for display
        $lines = array_filter(explode("\n", $snapshot), fn($l) => trim($l) !== '');
        $progress['last_lines'] = array_slice($lines, -10);

        // Try to detect what Claude is doing
        $lowerSnapshot = strtolower($snapshot);

        if (strpos($lowerSnapshot, 'creating pull request') !== false || strpos($lowerSnapshot, 'gh pr create') !== false) {
            $progress['current_task'] = 'Creating pull request';
        } elseif (strpos($lowerSnapshot, 'git push') !== false) {
            $progress['current_task'] = 'Pushing changes';
        } elseif (strpos($lowerSnapshot, 'git commit') !== false) {
            $progress['current_task'] = 'Committing changes';
        } elseif (strpos($lowerSnapshot, 'editing') !== false || strpos($lowerSnapshot, 'write tool') !== false) {
            $progress['current_task'] = 'Editing files';
        } elseif (strpos($lowerSnapshot, 'reading') !== false || strpos($lowerSnapshot, 'read tool') !== false) {
            $progress['current_task'] = 'Reading files';
        } elseif (strpos($lowerSnapshot, 'searching') !== false || strpos($lowerSnapshot, 'grep') !== false) {
            $progress['current_task'] = 'Searching codebase';
        } elseif (strpos($lowerSnapshot, 'cloning') !== false || strpos($lowerSnapshot, 'git clone') !== false) {
            $progress['current_task'] = 'Cloning repository';
        } elseif (strpos($lowerSnapshot, 'waiting') !== false || strpos($lowerSnapshot, 'clarification') !== false) {
            $progress['current_task'] = 'Waiting for clarification';
            $progress['status'] = 'waiting';
        }

        // Try to find files that were changed
        preg_match_all('/(?:editing|wrote|modified|created)\s+[`\'"]?([^\s`\'"]+\.\w+)[`\'"]?/i', $snapshot, $matches);
        if (!empty($matches[1])) {
            $progress['files_changed'] = array_unique($matches[1]);
        }

        return $progress;
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
        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $output, $exitCode);

        if ($exitCode !== 0) {
            return [];
        }

        $sessions = [];
        foreach ($output as $sessionName) {
            // Only include aidev sessions
            if (preg_match('/^aidev-(\d+)-(.+)$/', $sessionName, $matches)) {
                $memberId = (int)$matches[1];
                $issueKey = $matches[2];

                $service = new self($memberId, $issueKey);
                $sessions[] = $service->getStatus();
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
        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $output);

        foreach ($output as $sessionName) {
            if (preg_match('/^aidev-(\d+)-' . preg_quote($issueKey, '/') . '$/', $sessionName, $matches)) {
                return new self((int)$matches[1], $issueKey);
            }
        }

        return null;
    }
}
