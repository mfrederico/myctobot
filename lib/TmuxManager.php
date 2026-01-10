<?php
/**
 * TmuxManager - Centralized tmux session management
 *
 * Provides a clean abstraction layer for tmux operations.
 * Used by TmuxService for AI Developer sessions.
 *
 * Session Types:
 * - AI Developer: aidev-{domain_id}-{member_id}-{issue_key}
 * - Local runner: local-aidev-{domain_id}-{member_id}-{issue_key}
 */

namespace app;

use \Flight;

class TmuxManager {

    /**
     * Sanitize a string for use in tmux session names
     *
     * Removes/replaces characters that are invalid in tmux session names:
     * - Colons (:) - used for window:pane notation
     * - Periods (.) - can cause issues in some contexts
     * - Hash (#) - starts a comment in some contexts
     * - Slashes (/) - path separator, not valid in names
     *
     * @param string $component The string to sanitize
     * @return string Sanitized string safe for tmux session names
     */
    public static function sanitizeForSessionName(string $component): string {
        // Replace invalid characters with hyphens
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '-', $component);
        // Collapse multiple hyphens
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        return trim($sanitized, '-');
    }

    /**
     * Get domain identifier from config for session naming
     *
     * Extracts just the tenant/subdomain part for shorter session names:
     * - footest4.myctobot.ai → footest4
     * - gwt.myctobot.ai → gwt
     * - myctobot.ai → default
     *
     * @return string Domain ID (sanitized for use in session names)
     */
    public static function getDomainId(): string {
        $baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? 'localhost';
        $domainId = preg_replace('/^https?:\/\//', '', $baseUrl);

        // Extract subdomain/tenant from full domain
        // e.g., "footest4.myctobot.ai" → "footest4"
        // e.g., "myctobot.ai" → "default"
        $parts = explode('.', $domainId);
        if (count($parts) >= 3) {
            // Has subdomain (e.g., footest4.myctobot.ai)
            $domainId = $parts[0];
        } elseif (count($parts) == 2 && in_array($parts[1], ['ai', 'com', 'net', 'org', 'io'])) {
            // Main domain (e.g., myctobot.ai) - use 'default'
            $domainId = 'default';
        }
        // else: localhost or other simple names, use as-is

        return self::sanitizeForSessionName($domainId);
    }

    /**
     * Check if a tmux session exists
     *
     * @param string $sessionName The session name to check
     * @return bool True if session exists
     */
    public static function exists(string $sessionName): bool {
        $cmd = sprintf('tmux has-session -t %s 2>&1', escapeshellarg($sessionName));
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Create a new tmux session
     *
     * @param string $sessionName The session name
     * @param string $command The command to run in the session
     * @param string|null $workDir Working directory for the session
     * @return bool Success
     * @throws \Exception On failure
     */
    public static function create(string $sessionName, string $command, ?string $workDir = null): bool {
        if (self::exists($sessionName)) {
            throw new \Exception("Session already exists: {$sessionName}");
        }

        $cmd = 'tmux new-session -d -s ' . escapeshellarg($sessionName);

        if ($workDir && is_dir($workDir)) {
            $cmd .= ' -c ' . escapeshellarg($workDir);
        }

        $cmd .= ' ' . escapeshellarg($command) . ' 2>&1';

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Failed to create tmux session: " . implode("\n", $output));
        }

        return true;
    }

    /**
     * Kill a tmux session
     *
     * @param string $sessionName The session name to kill
     * @return bool Success (true even if session didn't exist)
     */
    public static function kill(string $sessionName): bool {
        if (!self::exists($sessionName)) {
            return true; // Already dead
        }

        $cmd = sprintf('tmux kill-session -t %s 2>&1', escapeshellarg($sessionName));
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Capture the content of a tmux pane
     *
     * @param string $sessionName The session name
     * @param int $lines Number of lines to capture (from bottom)
     * @return string The captured content
     */
    public static function capture(string $sessionName, int $lines = 100): string {
        if (!self::exists($sessionName)) {
            return '';
        }

        $cmd = sprintf(
            'tmux capture-pane -t %s -p -S -%d 2>/dev/null',
            escapeshellarg($sessionName),
            $lines
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Send keys to a tmux session
     *
     * @param string $sessionName The session name
     * @param string $keys The keys to send
     * @param bool $literal Send keys literally (no escaping)
     * @return bool Success
     */
    public static function sendKeys(string $sessionName, string $keys, bool $literal = false): bool {
        if (!self::exists($sessionName)) {
            return false;
        }

        $cmd = sprintf(
            'tmux send-keys -t %s %s%s 2>&1',
            escapeshellarg($sessionName),
            $literal ? '-l ' : '',
            escapeshellarg($keys)
        );
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Send text using tmux buffer (for long/complex text)
     *
     * @param string $sessionName The session name
     * @param string $text The text to send
     * @param string $bufferName Buffer name to use
     * @return bool Success
     */
    public static function sendTextViaBuffer(string $sessionName, string $text, string $bufferName = 'aidev-text'): bool {
        if (!self::exists($sessionName)) {
            return false;
        }

        // Write to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'tmux-');
        if ($tempFile === false) {
            return false;
        }

        file_put_contents($tempFile, $text);

        // Load into buffer
        $loadCmd = sprintf(
            'tmux load-buffer -b %s %s 2>&1',
            escapeshellarg($bufferName),
            escapeshellarg($tempFile)
        );
        exec($loadCmd, $output, $loadCode);

        unlink($tempFile);

        if ($loadCode !== 0) {
            return false;
        }

        // Paste buffer
        $pasteCmd = sprintf(
            'tmux paste-buffer -b %s -t %s 2>&1',
            escapeshellarg($bufferName),
            escapeshellarg($sessionName)
        );
        exec($pasteCmd, $output, $pasteCode);

        return $pasteCode === 0;
    }

    /**
     * Get the PID of the main process in a session's pane
     *
     * @param string $sessionName The session name
     * @return int|null The PID or null if not found
     */
    public static function getPanePid(string $sessionName): ?int {
        if (!self::exists($sessionName)) {
            return null;
        }

        $cmd = sprintf(
            'tmux list-panes -t %s -F "#{pane_pid}" 2>/dev/null | head -1',
            escapeshellarg($sessionName)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            return null;
        }

        return (int) trim($output[0]);
    }

    /**
     * Check if a process is running in the session
     *
     * @param string $sessionName The session name
     * @param string $processPattern Pattern to match (for pgrep -f)
     * @return bool True if process is running
     */
    public static function isProcessRunning(string $sessionName, string $processPattern): bool {
        $panePid = self::getPanePid($sessionName);
        if ($panePid === null) {
            return false;
        }

        $cmd = sprintf('pgrep -P %d -f %s 2>/dev/null', $panePid, escapeshellarg($processPattern));
        exec($cmd, $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    /**
     * List all tmux sessions matching a prefix
     *
     * @param string $prefix Session name prefix (e.g., 'aidev-')
     * @return array Array of session info [name, created, attached]
     */
    public static function listSessions(string $prefix = ''): array {
        $cmd = 'tmux list-sessions -F "#{session_name}|#{session_created}|#{session_attached}" 2>/dev/null';
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $sessions = [];
        foreach ($output as $line) {
            $parts = explode('|', $line);
            if (count($parts) >= 3) {
                $name = $parts[0];
                if (empty($prefix) || strpos($name, $prefix) === 0) {
                    $sessions[] = [
                        'name' => $name,
                        'created' => date('Y-m-d H:i:s', (int)$parts[1]),
                        'attached' => $parts[2] === '1'
                    ];
                }
            }
        }

        return $sessions;
    }

    /**
     * List all AI Developer sessions
     *
     * @return array Array of session info
     */
    public static function listAiDevSessions(): array {
        return self::listSessions('aidev-');
    }

    /**
     * List all local runner sessions
     *
     * @return array Array of session info
     */
    public static function listLocalRunnerSessions(): array {
        return self::listSessions('local-aidev-');
    }

    /**
     * Kill all sessions matching a prefix
     *
     * @param string $prefix Session name prefix
     * @return int Number of sessions killed
     */
    public static function killByPrefix(string $prefix): int {
        $sessions = self::listSessions($prefix);
        $killed = 0;

        foreach ($sessions as $session) {
            if (self::kill($session['name'])) {
                $killed++;
            }
        }

        return $killed;
    }

    /**
     * Get session info
     *
     * @param string $sessionName The session name
     * @return array|null Session info or null if not found
     */
    public static function getInfo(string $sessionName): ?array {
        if (!self::exists($sessionName)) {
            return null;
        }

        $cmd = sprintf(
            'tmux display-message -t %s -p "#{session_created}|#{session_attached}|#{pane_pid}" 2>/dev/null',
            escapeshellarg($sessionName)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            return null;
        }

        $parts = explode('|', $output[0]);

        return [
            'name' => $sessionName,
            'created' => isset($parts[0]) ? date('Y-m-d H:i:s', (int)$parts[0]) : null,
            'attached' => isset($parts[1]) && $parts[1] === '1',
            'pane_pid' => isset($parts[2]) ? (int)$parts[2] : null,
            'exists' => true
        ];
    }

    /**
     * Build an AI Developer session name
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key (Jira like PROJ-123, or GitHub like owner/repo#123)
     * @return string Session name (sanitized for tmux)
     */
    public static function buildSessionName(int $memberId, string $issueKey): string {
        $domainId = self::getDomainId();
        $safeIssueKey = self::sanitizeForSessionName($issueKey);
        return "aidev-{$domainId}-{$memberId}-{$safeIssueKey}";
    }

    /**
     * Build a local runner session name
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key (Jira like PROJ-123, or GitHub like owner/repo#123)
     * @return string Session name (sanitized for tmux)
     */
    public static function buildLocalRunnerSessionName(int $memberId, string $issueKey): string {
        $domainId = self::getDomainId();
        $safeIssueKey = self::sanitizeForSessionName($issueKey);
        return "local-aidev-{$domainId}-{$memberId}-{$safeIssueKey}";
    }

    /**
     * Parse a session name to extract IDs
     *
     * @param string $sessionName The session name
     * @return array|null Parsed info [type, domain_id, member_id, issue_key] or null
     */
    public static function parseSessionName(string $sessionName): ?array {
        // Local runner: local-aidev-{domain_id}-{member_id}-{issue_key}
        if (preg_match('/^local-aidev-([^-]+(?:-[^-]+)*)-(\d+)-([A-Z]+-\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'local',
                'domain_id' => $m[1],
                'member_id' => (int)$m[2],
                'issue_key' => $m[3]
            ];
        }

        // AI Developer: aidev-{domain_id}-{member_id}-{issue_key}
        if (preg_match('/^aidev-([^-]+(?:-[^-]+)*)-(\d+)-([A-Z]+-\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'aidev',
                'domain_id' => $m[1],
                'member_id' => (int)$m[2],
                'issue_key' => $m[3]
            ];
        }

        return null;
    }

    /**
     * Clean up orphaned sessions
     * (Sessions for jobs that no longer exist)
     *
     * @param callable $isValidCallback Callback that takes session name, returns true if valid
     * @return int Number of sessions cleaned up
     */
    public static function cleanupOrphaned(callable $isValidCallback): int {
        $sessions = array_merge(
            self::listAiDevSessions(),
            self::listLocalRunnerSessions()
        );
        $cleaned = 0;

        foreach ($sessions as $session) {
            if (!$isValidCallback($session['name'])) {
                if (self::kill($session['name'])) {
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }
}
