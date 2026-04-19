#!/usr/bin/env php
<?php
/**
 * Story Build Orchestrator
 *
 * Processes ctostories queue, spawning AI dev agents for each story.
 * Works with both Jira and GitHub issue sources.
 *
 * Usage:
 *   php scripts/story-build-orchestrator.php --workspace=footest4 [options]
 *
 * Options:
 *   --workspace=<name>       Required. Workspace slug (e.g., gwt, footest4)
 *   --member=<id>         Member ID to run as (default: first admin)
 *   --max-concurrent=<n>  Max parallel builds (default: 1)
 *   --once                Process one batch and exit (don't loop)
 *   --dry-run             Show what would be processed without running
 *   --verbose             Show detailed output
 *   --help                Show this help
 *
 * Examples:
 *   php scripts/story-build-orchestrator.php --workspace=footest4 --verbose
 *   php scripts/story-build-orchestrator.php --workspace=gwt --max-concurrent=2
 */

error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Parse command line arguments
$options = getopt('', [
    'workspace:',
    'member:',
    'max-concurrent:',
    'once',
    'dry-run',
    'verbose',
    'help'
]);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    preg_match('/\/\*\*[\s\S]*?\*\//', file_get_contents(__FILE__), $matches);
    echo str_replace(['/**', '*/', ' * ', ' *'], '', $matches[0]) . "\n";
    exit(0);
}

if (empty($options['workspace'])) {
    echo "Error: --workspace is required\n";
    echo "Usage: php scripts/story-build-orchestrator.php --workspace=<workspace>\n";
    exit(1);
}

$workspace = $options['workspace'];
$memberIdParam = isset($options['member']) ? (int)$options['member'] : null;
$maxConcurrent = isset($options['max-concurrent']) ? (int)$options['max-concurrent'] : 1;
$runOnce = isset($options['once']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// Bootstrap
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/lib/FlightMap.php';

use \Flight as Flight;
use \app\Bean;

// Load workspace config
$configFile = "{$baseDir}/conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    echo "Error: Workspace config not found: {$configFile}\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
if (!$config) {
    echo "Error: Failed to parse config file\n";
    exit(1);
}

// Initialize Flight config
foreach ($config as $section => $values) {
    if (is_array($values)) {
        foreach ($values as $key => $value) {
            Flight::set("{$section}.{$key}", $value);
        }
    }
}

// Initialize database
try {
    $dbConfig = $config['database'];
    $type = $dbConfig['type'] ?? 'mysql';

    if ($type === 'sqlite') {
        $dbPath = $dbConfig['path'] ?? "database/{$workspace}.sqlite";
        Bean::setup("sqlite:{$dbPath}");
    } else {
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $name = $dbConfig['name'] ?? $workspace;
        $user = $dbConfig['user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? '';
        Bean::setup("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
    }
    Bean::freeze(true);
} catch (\Exception $e) {
    echo "Error: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Find member to use
if ($memberIdParam) {
    $member = Bean::load('member', $memberIdParam);
    if (!$member || !$member->id) {
        echo "Error: Member not found: {$memberIdParam}\n";
        exit(1);
    }
} else {
    $member = Bean::findOne('member', 'level <= ? ORDER BY level ASC, id ASC', [50]);
    if (!$member) {
        echo "Error: No admin member found\n";
        exit(1);
    }
}
$memberId = $member->id;

// Output helper
function output($message, $force = false) {
    global $verbose;
    if ($verbose || $force) {
        echo "[" . date('H:i:s') . "] " . $message . "\n";
    }
}

/**
 * Story Build Orchestrator Class
 */
class StoryBuildOrchestrator {
    private string $workspace;
    private int $memberId;
    private int $maxConcurrent;
    private bool $dryRun;
    private string $baseDir;

    /** @var array<int, array{session: string, story_id: int, issue_key: string, started: int, last_activity: int, last_status: string}> */
    private array $activeSessions = [];

    /** @var int Seconds before considering a session stuck */
    private int $stuckThreshold = 600; // 10 minutes

    public function __construct(string $workspace, int $memberId, int $maxConcurrent, bool $dryRun, string $baseDir) {
        $this->workspace = $workspace;
        $this->memberId = $memberId;
        $this->maxConcurrent = max(1, $maxConcurrent);
        $this->dryRun = $dryRun;
        $this->baseDir = $baseDir;

        // Discover any existing running sessions for this workspace
        $this->discoverExistingSessions();
    }

    /**
     * Discover existing tmux sessions that match our pattern
     */
    private function discoverExistingSessions(): void {
        $pattern = "story-{$this->workspace}-";
        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $sessions);

        foreach ($sessions as $sessionName) {
            if (strpos($sessionName, $pattern) === 0) {
                // Parse story ID from session name: story-{workspace}-{storyId}-{issueKey}
                if (preg_match('/^story-[^-]+-(\d+)-(.+)$/', $sessionName, $matches)) {
                    $storyId = (int)$matches[1];

                    // Load story from database
                    $story = Bean::load('ctostories', $storyId);
                    if ($story && $story->id && $story->status === 'in_progress') {
                        $this->activeSessions[$storyId] = [
                            'session' => $sessionName,
                            'story_id' => $storyId,
                            'issue_key' => $story->jira_issue_key,
                            'started' => time() - 60, // Assume started recently
                            'last_activity' => time(),
                            'last_status' => 'discovered'
                        ];
                        output("  Discovered existing session: {$sessionName}", true);
                    }
                }
            }
        }

        if (count($this->activeSessions) > 0) {
            output("  Found " . count($this->activeSessions) . " existing session(s)", true);
        }
    }

    /**
     * Detect provider from issue key format
     */
    private function getProvider(string $issueKey): string {
        // GitHub format: owner/repo#123
        if (preg_match('/^[^\/]+\/[^#]+#\d+$/', $issueKey)) {
            return 'github';
        }
        // Jira format: PROJECT-123
        return 'jira';
    }

    /**
     * Build a safe session name from issue key
     */
    private function buildSessionName(int $storyId, string $issueKey): string {
        // Sanitize issue key for tmux session name
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $issueKey);
        return "story-{$this->workspace}-{$storyId}-{$safe}";
    }

    /**
     * Get approved stories ready for processing
     * Includes stories with no job or failed jobs (for retry)
     * Excludes stories with running/pending jobs
     */
    private function getBacklogStories(int $limit): array {
        // Get approved stories that either:
        // 1. Have no job record at all
        // 2. Have a failed/cancelled job (retry eligible)
        // Exclude stories with running/pending jobs
        $sql = "SELECT s.* FROM ctostories s
                LEFT JOIN aidevjobs j ON j.issue_key = s.jira_issue_key
                WHERE s.status = 'approved'
                AND (j.id IS NULL OR j.status IN ('failed', 'cancelled'))
                GROUP BY s.id
                ORDER BY s.sequence ASC, s.id ASC
                LIMIT ?";

        return Bean::convertToBeans('ctostories',
            Bean::getAll($sql, [$limit])
        );
    }

    /**
     * Get count of stories still in queue (approved without running job)
     */
    private function getQueueCount(): int {
        $sql = "SELECT COUNT(DISTINCT s.id) as cnt FROM ctostories s
                LEFT JOIN aidevjobs j ON j.issue_key = s.jira_issue_key
                WHERE s.status = 'approved'
                AND (j.id IS NULL OR j.status IN ('failed', 'cancelled'))";
        $result = Bean::getRow($sql);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Spawn a tmux session to process a story
     */
    private function spawnBuildSession($story): ?string {
        $issueKey = $story->jira_issue_key;
        if (empty($issueKey)) {
            output("  Skipping story {$story->id}: no issue key", true);
            return null;
        }

        $provider = $this->getProvider($issueKey);
        $sessionName = $this->buildSessionName($story->id, $issueKey);

        // Kill any existing session with same name
        exec("tmux kill-session -t " . escapeshellarg($sessionName) . " 2>/dev/null");

        // Build the command
        $cmd = sprintf(
            'php %s/scripts/job-dispatcher.php --issue=%s --workspace=%s --provider=%s --member=%d --print',
            escapeshellarg($this->baseDir),
            escapeshellarg($issueKey),
            escapeshellarg($this->workspace),
            $provider,
            $this->memberId
        );

        if ($this->dryRun) {
            output("  [DRY RUN] Would spawn: {$sessionName}", true);
            output("  [DRY RUN] Command: {$cmd}", true);
            return null;
        }

        // Create tmux session
        $tmuxCmd = sprintf(
            'tmux new-session -d -s %s -x 200 -y 50 %s',
            escapeshellarg($sessionName),
            escapeshellarg($cmd)
        );

        exec($tmuxCmd, $output, $exitCode);

        if ($exitCode !== 0) {
            output("  Failed to create tmux session: {$sessionName}", true);
            return null;
        }

        // Update story status
        $story->status = 'in_progress';
        Bean::store($story);

        output("  Spawned session: {$sessionName} ({$provider})", true);

        return $sessionName;
    }

    /**
     * Check if a tmux session is still running
     */
    private function isSessionRunning(string $sessionName): bool {
        exec("tmux has-session -t " . escapeshellarg($sessionName) . " 2>/dev/null", $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get the Claude process PID for a session
     */
    private function getClaudePid(string $sessionName): ?int {
        // Get the tmux pane PID
        exec("tmux list-panes -t " . escapeshellarg($sessionName) . " -F '#{pane_pid}' 2>/dev/null", $output);
        if (empty($output)) {
            return null;
        }
        $panePid = (int)$output[0];

        // Find the claude process under this pane
        exec("pstree -p {$panePid} 2>/dev/null | grep -o 'claude([0-9]*)' | grep -o '[0-9]*'", $claudePids);
        if (!empty($claudePids)) {
            return (int)$claudePids[0];
        }

        return null;
    }

    /**
     * Get CPU time for a process (utime + stime in jiffies)
     */
    private function getProcessCpuTime(int $pid): ?int {
        $statFile = "/proc/{$pid}/stat";
        if (!file_exists($statFile)) {
            return null;
        }

        $stat = @file_get_contents($statFile);
        if (!$stat) {
            return null;
        }

        $parts = explode(' ', $stat);
        if (count($parts) < 15) {
            return null;
        }

        // utime is field 14, stime is field 15 (0-indexed: 13, 14)
        return (int)$parts[13] + (int)$parts[14];
    }

    /**
     * Check if a session's Claude process is actively running
     */
    private function isProcessActive(int $storyId): bool {
        if (!isset($this->activeSessions[$storyId])) {
            return false;
        }

        $sessionInfo = &$this->activeSessions[$storyId];
        $sessionName = $sessionInfo['session'];

        // Get Claude PID if not cached
        if (!isset($sessionInfo['claude_pid'])) {
            $sessionInfo['claude_pid'] = $this->getClaudePid($sessionName);
        }

        $pid = $sessionInfo['claude_pid'];
        if (!$pid) {
            return false;
        }

        // Get current CPU time
        $cpuTime = $this->getProcessCpuTime($pid);
        if ($cpuTime === null) {
            return false;
        }

        // Compare with last CPU time
        $lastCpuTime = $sessionInfo['last_cpu_time'] ?? 0;
        $sessionInfo['last_cpu_time'] = $cpuTime;

        // If CPU time increased, process is active
        if ($cpuTime > $lastCpuTime) {
            $sessionInfo['last_activity'] = time();
            return true;
        }

        return false;
    }

    /**
     * Capture recent output from a tmux session
     */
    private function captureSessionOutput(string $sessionName, int $lines = 50): string {
        $output = [];
        exec("tmux capture-pane -t " . escapeshellarg($sessionName) . " -p -S -{$lines} 2>/dev/null", $output);
        return implode("\n", $output);
    }

    /**
     * Parse session output for progress indicators
     */
    private function parseSessionProgress(string $output): array {
        $status = 'unknown';
        $phase = '';
        $details = '';

        // Check for various progress indicators
        if (preg_match('/Implementation complete/i', $output)) {
            $status = 'completing';
            $phase = 'implementation_done';
        } elseif (preg_match('/PR Created|pull request/i', $output)) {
            $status = 'pr_created';
            $phase = 'pr_created';
        } elseif (preg_match('/Verifying implementation/i', $output)) {
            $status = 'verifying';
            $phase = 'verification';
        } elseif (preg_match('/Spawning.*agent|php-architect-engineer|impl-agent/i', $output)) {
            $status = 'implementing';
            $phase = 'implementation';
        } elseif (preg_match('/Cloning repository/i', $output)) {
            $status = 'starting';
            $phase = 'setup';
        } elseif (preg_match('/Fetching issue details/i', $output)) {
            $status = 'starting';
            $phase = 'fetch_issue';
        } elseif (preg_match('/Error:|Fatal:|Exception:/i', $output)) {
            $status = 'error';
            // Try to extract error message
            if (preg_match('/(?:Error|Fatal|Exception):\s*(.+?)(?:\n|$)/i', $output, $matches)) {
                $details = trim($matches[1]);
            }
        }

        // Check for time indicators in output (e.g., "3m 45s")
        if (preg_match('/(\d+)m\s*(\d+)s/', $output, $matches)) {
            $details = "{$matches[1]}m {$matches[2]}s elapsed";
        }

        return [
            'status' => $status,
            'phase' => $phase,
            'details' => $details
        ];
    }

    /**
     * Monitor a session and update its tracking info
     */
    private function monitorSession(int $storyId): void {
        if (!isset($this->activeSessions[$storyId])) {
            return;
        }

        $sessionInfo = &$this->activeSessions[$storyId];
        $sessionName = $sessionInfo['session'];

        // Check if session is still running
        if (!$this->isSessionRunning($sessionName)) {
            return; // Will be handled by checkSessions
        }

        // Capture and parse output
        $output = $this->captureSessionOutput($sessionName);
        $progress = $this->parseSessionProgress($output);

        // Update tracking info if we got meaningful progress from output
        if ($progress['status'] !== 'unknown') {
            $sessionInfo['last_activity'] = time();
            $sessionInfo['last_status'] = $progress['status'];

            // Log progress changes
            static $lastReported = [];
            $reportKey = "{$storyId}:{$progress['phase']}";
            if (!isset($lastReported[$reportKey])) {
                $lastReported[$reportKey] = true;
                $elapsed = time() - $sessionInfo['started'];
                $elapsedStr = sprintf('%dm %ds', floor($elapsed / 60), $elapsed % 60);
                output("  [{$sessionInfo['issue_key']}] {$progress['status']} ({$elapsedStr})" .
                    ($progress['details'] ? " - {$progress['details']}" : ''));
            }
        } else {
            // No output progress detected - check process CPU activity
            // This handles --print mode which buffers all output
            $processActive = $this->isProcessActive($storyId);
            if ($processActive) {
                // Process is actively consuming CPU - it's working
                static $lastCpuReport = [];
                $elapsed = time() - $sessionInfo['started'];
                $cpuReportInterval = 60; // Report CPU activity every 60 seconds

                if (!isset($lastCpuReport[$storyId]) || (time() - $lastCpuReport[$storyId]) >= $cpuReportInterval) {
                    $lastCpuReport[$storyId] = time();
                    $elapsedStr = sprintf('%dm %ds', floor($elapsed / 60), $elapsed % 60);
                    $cpuTime = $sessionInfo['last_cpu_time'] ?? 0;
                    output("  [{$sessionInfo['issue_key']}] processing ({$elapsedStr}) - CPU: {$cpuTime} jiffies");
                }
            }
        }

        // Check for stuck session - use longer threshold for print mode
        $timeSinceActivity = time() - $sessionInfo['last_activity'];
        $effectiveThreshold = $this->stuckThreshold;

        // If we're actively consuming CPU, extend the threshold
        if (isset($sessionInfo['last_cpu_time']) && $sessionInfo['last_cpu_time'] > 0) {
            $effectiveThreshold = max($this->stuckThreshold, 900); // 15 min for print mode
        }

        if ($timeSinceActivity > $effectiveThreshold) {
            output("  WARNING: Session {$sessionName} may be stuck ({$timeSinceActivity}s since activity)", true);
        }
    }

    /**
     * Check session log file for completion
     */
    private function checkSessionLogForCompletion(string $issueKey): bool {
        $workDir = $this->getWorkDir($issueKey);
        $resultFile = "{$workDir}/result.json";

        // If result.json exists, session is complete
        if (file_exists($resultFile)) {
            return true;
        }

        // Check session.log for completion markers
        $logFile = "{$workDir}/session.log";
        if (file_exists($logFile)) {
            $log = file_get_contents($logFile);
            if (preg_match('/Implementation complete|"success"\s*:\s*(true|false)/i', $log)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the work directory for a story session
     */
    private function getWorkDir(string $issueKey): string {
        // Sanitize issue key for directory path
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $issueKey);
        return "/tmp/local-aidev-{$this->workspace}-{$this->memberId}-{$safe}";
    }

    /**
     * Check result of completed session
     */
    private function checkResult(int $storyId, string $issueKey): array {
        $workDir = $this->getWorkDir($issueKey);
        $resultFile = "{$workDir}/result.json";

        if (file_exists($resultFile)) {
            $result = json_decode(file_get_contents($resultFile), true);
            if ($result) {
                return $result;
            }
        }

        // Try to extract from session log
        $logFile = "{$workDir}/session.log";
        if (file_exists($logFile)) {
            $log = file_get_contents($logFile);
            // Look for JSON result pattern
            if (preg_match('/\{[^{}]*"success"\s*:\s*(true|false)[^{}]*\}/s', $log, $matches)) {
                $result = json_decode($matches[0], true);
                if ($result) {
                    return $result;
                }
            }
        }

        return ['success' => false, 'error' => 'No result found'];
    }

    /**
     * Handle completion of a build session
     */
    private function handleCompletion(int $storyId, string $issueKey): void {
        $story = Bean::load('ctostories', $storyId);
        if (!$story || !$story->id) {
            output("  Warning: Story {$storyId} not found", true);
            return;
        }

        $result = $this->checkResult($storyId, $issueKey);

        // Update the aidevjobs record with result data
        $this->updateJobWithResult($issueKey, $result);

        if (!empty($result['success'])) {
            $story->status = 'done';
            $story->verified_at = date('Y-m-d H:i:s');

            if (!empty($result['pr_url'])) {
                // Store PR URL in verification_result
                $story->verification_result = json_encode([
                    'pr_url' => $result['pr_url'],
                    'branch' => $result['branch_name'] ?? null,
                    'files_changed' => $result['files_changed'] ?? [],
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
            }

            output("  Story {$storyId} completed successfully", true);
            if (!empty($result['pr_url'])) {
                output("    PR: {$result['pr_url']}", true);
            }
        } else {
            $story->status = 'blocked';
            $story->blocker_reason = $result['error'] ?? 'Build failed - check logs';
            output("  Story {$storyId} failed: " . ($result['error'] ?? 'Unknown error'), true);
        }

        Bean::store($story);

        // Update epic progress via association
        $epic = $story->ctoepics;
        if ($epic && $epic->id) {
            $this->updateEpicProgress($epic);
        }
    }

    /**
     * Update aidevjobs record with result.json data
     */
    private function updateJobWithResult(string $issueKey, array $result): void {
        // Find the most recent job for this issue
        $job = Bean::findOne('aidevjobs',
            'issue_key = ? ORDER BY updated_at DESC',
            [$issueKey]
        );

        if (!$job) {
            output("  Warning: No aidevjobs record found for {$issueKey}", true);
            return;
        }

        // Update job with result data
        if (!empty($result['success'])) {
            $job->status = 'pr_created';
            $job->branch_name = $result['branch_name'] ?? null;
            $job->pr_url = $result['pr_url'] ?? null;
            $job->pr_number = $result['pr_number'] ?? null;
            $job->pr_created_at = date('Y-m-d H:i:s');
            $job->files_changed = !empty($result['files_changed'])
                ? json_encode($result['files_changed'])
                : null;
            $job->commit_sha = $result['commit_sha'] ?? null;
            // current_step is VARCHAR(100) - use simple status, store summary in logs
            $job->current_step = 'Complete';
            $job->progress = 100;
            $job->completed_at = date('Y-m-d H:i:s');
        } else {
            $job->status = 'failed';
            $job->error_message = $result['error'] ?? 'Build failed - check logs';
            $job->completed_at = date('Y-m-d H:i:s');
        }

        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        output("  Updated aidevjobs record: {$job->job_uid} -> {$job->status}", true);
    }

    /**
     * Update epic completion stats via associations
     */
    private function updateEpicProgress(object $epic): void {
        if (!$epic || !$epic->id) return;

        // Count stories via association
        $stories = $epic->ownCtostoriesList;
        $totalStories = count($stories);
        $completedStories = 0;

        foreach ($stories as $story) {
            if ($story->status === 'done') {
                $completedStories++;
            }
        }

        $epic->story_count = $totalStories;
        $epic->stories_completed = $completedStories;

        if ($completedStories >= $totalStories && $totalStories > 0) {
            $epic->status = 'completed';
            output("  Epic {$epic->epic_uid} completed!", true);
        } elseif ($completedStories > 0) {
            $epic->status = 'in_progress';
        }

        Bean::store($epic);
    }

    /**
     * Check active sessions and handle completions
     */
    private function checkSessions(): void {
        foreach ($this->activeSessions as $storyId => $sessionInfo) {
            $sessionRunning = $this->isSessionRunning($sessionInfo['session']);
            $logComplete = $this->checkSessionLogForCompletion($sessionInfo['issue_key']);

            if (!$sessionRunning || $logComplete) {
                // Session ended or completed
                $elapsed = time() - $sessionInfo['started'];
                $elapsedStr = sprintf('%dm %ds', floor($elapsed / 60), $elapsed % 60);
                output("Session completed: {$sessionInfo['session']} ({$elapsedStr})", true);
                $this->handleCompletion($storyId, $sessionInfo['issue_key']);
                unset($this->activeSessions[$storyId]);

                // Kill tmux session if it's still hanging around
                if ($sessionRunning) {
                    exec("tmux kill-session -t " . escapeshellarg($sessionInfo['session']) . " 2>/dev/null");
                }
            } else {
                // Session still running - monitor it
                $this->monitorSession($storyId);
            }
        }
    }

    /**
     * Get active session count
     */
    public function getActiveCount(): int {
        return count($this->activeSessions);
    }

    /**
     * Run a single iteration of the orchestrator
     * Returns true if there's more work to do
     */
    public function runOnce(): bool {
        // Check for completed sessions
        $this->checkSessions();

        // Calculate how many new sessions we can spawn
        $availableSlots = $this->maxConcurrent - count($this->activeSessions);

        if ($availableSlots > 0) {
            $stories = $this->getBacklogStories($availableSlots);

            foreach ($stories as $story) {
                $sessionName = $this->spawnBuildSession($story);

                if ($sessionName) {
                    $this->activeSessions[$story->id] = [
                        'session' => $sessionName,
                        'story_id' => $story->id,
                        'issue_key' => $story->jira_issue_key,
                        'started' => time(),
                        'last_activity' => time(),
                        'last_status' => 'spawned'
                    ];
                }
            }
        }

        // Return true if there's still work (active sessions or queued stories)
        return count($this->activeSessions) > 0 || $this->getQueueCount() > 0;
    }

    /**
     * Run the orchestrator loop until queue is empty
     */
    public function run(): void {
        output("Starting Story Build Orchestrator", true);
        output("  workspace: {$this->workspace}", true);
        output("  Member: {$this->memberId}", true);
        output("  Max Concurrent: {$this->maxConcurrent}", true);
        output("  Dry Run: " . ($this->dryRun ? 'YES' : 'NO'), true);

        $queueCount = $this->getQueueCount();
        output("  Stories in queue: {$queueCount}", true);

        if ($queueCount === 0) {
            output("No stories to process", true);
            return;
        }

        output("", true);

        while ($this->runOnce()) {
            // Show status
            $activeCount = count($this->activeSessions);
            $remainingCount = $this->getQueueCount();

            if ($activeCount > 0) {
                output("Active: {$activeCount}, Queued: {$remainingCount}");
            }

            // Wait before checking again
            sleep(30);
        }

        output("", true);
        output("Orchestrator complete - all stories processed", true);
    }

    /**
     * Show current status
     */
    public function showStatus(): void {
        $backlog = Bean::count('ctostories', 'status = ?', ['backlog']);
        $inProgress = Bean::count('ctostories', 'status = ?', ['in_progress']);
        $done = Bean::count('ctostories', 'status = ?', ['done']);
        $blocked = Bean::count('ctostories', 'status = ?', ['blocked']);

        output("Story Status:", true);
        output("  Backlog:     {$backlog}", true);
        output("  In Progress: {$inProgress}", true);
        output("  Done:        {$done}", true);
        output("  Blocked:     {$blocked}", true);

        if (count($this->activeSessions) > 0) {
            output("", true);
            output("Active Sessions:", true);
            foreach ($this->activeSessions as $storyId => $info) {
                $elapsed = time() - $info['started'];
                $elapsedStr = sprintf('%dm %ds', floor($elapsed / 60), $elapsed % 60);
                $status = $info['last_status'] ?? 'unknown';

                // Get current progress from tmux
                if ($this->isSessionRunning($info['session'])) {
                    $output = $this->captureSessionOutput($info['session'], 20);
                    $progress = $this->parseSessionProgress($output);
                    if ($progress['status'] !== 'unknown') {
                        $status = $progress['status'];
                    }
                }

                output("  {$info['issue_key']}: {$status} ({$elapsedStr})", true);
                output("    Session: {$info['session']}", true);
            }
        }
    }
}

// Main execution
echo "===========================================\n";
echo "Story Build Orchestrator\n";
echo "===========================================\n\n";

$orchestrator = new StoryBuildOrchestrator(
    $workspace,
    $memberId,
    $maxConcurrent,
    $dryRun,
    $baseDir
);

if ($dryRun) {
    output("=== DRY RUN MODE ===", true);
    $orchestrator->showStatus();
    output("", true);
    $orchestrator->runOnce();
    output("=== DRY RUN COMPLETE ===", true);
} elseif ($runOnce) {
    $orchestrator->runOnce();
    $orchestrator->showStatus();
} else {
    $orchestrator->run();
}

exit(0);
