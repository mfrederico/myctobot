#!/usr/bin/env php
<?php
/**
 * Story Build Orchestrator
 *
 * Processes ctostories queue, spawning AI dev agents for each story.
 * Works with both Jira and GitHub issue sources.
 *
 * Usage:
 *   php scripts/story-build-orchestrator.php --tenant=footest4 [options]
 *
 * Options:
 *   --tenant=<name>       Required. Tenant slug (e.g., gwt, footest4)
 *   --member=<id>         Member ID to run as (default: first admin)
 *   --max-concurrent=<n>  Max parallel builds (default: 1)
 *   --once                Process one batch and exit (don't loop)
 *   --dry-run             Show what would be processed without running
 *   --verbose             Show detailed output
 *   --help                Show this help
 *
 * Examples:
 *   php scripts/story-build-orchestrator.php --tenant=footest4 --verbose
 *   php scripts/story-build-orchestrator.php --tenant=gwt --max-concurrent=2
 */

error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Parse command line arguments
$options = getopt('', [
    'tenant:',
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

if (empty($options['tenant'])) {
    echo "Error: --tenant is required\n";
    echo "Usage: php scripts/story-build-orchestrator.php --tenant=<tenant>\n";
    exit(1);
}

$tenant = $options['tenant'];
$memberIdParam = isset($options['member']) ? (int)$options['member'] : null;
$maxConcurrent = isset($options['max-concurrent']) ? (int)$options['max-concurrent'] : 1;
$runOnce = isset($options['once']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// Bootstrap
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/lib/FlightMap.php';
require_once $baseDir . '/lib/Bean.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

// Load tenant config
$configFile = "{$baseDir}/conf/config.{$tenant}.ini";
if (!file_exists($configFile)) {
    echo "Error: Tenant config not found: {$configFile}\n";
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
        $dbPath = $dbConfig['path'] ?? "database/{$tenant}.sqlite";
        R::setup("sqlite:{$dbPath}");
    } else {
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $name = $dbConfig['name'] ?? $tenant;
        $user = $dbConfig['user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? '';
        R::setup("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
    }
    R::freeze(true);
} catch (\Exception $e) {
    echo "Error: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Find member to use
if ($memberIdParam) {
    $member = R::load('member', $memberIdParam);
    if (!$member || !$member->id) {
        echo "Error: Member not found: {$memberIdParam}\n";
        exit(1);
    }
} else {
    $member = R::findOne('member', 'level <= ? ORDER BY level ASC, id ASC', [50]);
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
    private string $tenant;
    private int $memberId;
    private int $maxConcurrent;
    private bool $dryRun;
    private string $baseDir;

    /** @var array<int, array{session: string, story_id: int, issue_key: string, started: int}> */
    private array $activeSessions = [];

    public function __construct(string $tenant, int $memberId, int $maxConcurrent, bool $dryRun, string $baseDir) {
        $this->tenant = $tenant;
        $this->memberId = $memberId;
        $this->maxConcurrent = max(1, $maxConcurrent);
        $this->dryRun = $dryRun;
        $this->baseDir = $baseDir;
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
        return "story-{$this->tenant}-{$storyId}-{$safe}";
    }

    /**
     * Get backlog stories ready for processing
     */
    private function getBacklogStories(int $limit): array {
        return Bean::find(
            'ctostories',
            ' status = ? ORDER BY sequence ASC, id ASC LIMIT ? ',
            ['backlog', $limit]
        );
    }

    /**
     * Get count of stories still in queue
     */
    private function getQueueCount(): int {
        return Bean::count('ctostories', 'status = ?', ['backlog']);
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
            'php %s/scripts/local-aidev-full.php --issue=%s --tenant=%s --provider=%s --member=%d --print',
            escapeshellarg($this->baseDir),
            escapeshellarg($issueKey),
            escapeshellarg($this->tenant),
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
     * Get the work directory for a story session
     */
    private function getWorkDir(string $issueKey): string {
        // Sanitize issue key for directory path
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $issueKey);
        return "/tmp/local-aidev-{$this->tenant}-{$this->memberId}-{$safe}";
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

        // Update epic progress
        $this->updateEpicProgress($story->epic_id);
    }

    /**
     * Update epic completion stats
     */
    private function updateEpicProgress(int $epicId): void {
        if (!$epicId) return;

        $epic = Bean::load('ctoepics', $epicId);
        if (!$epic || !$epic->id) return;

        $totalStories = Bean::count('ctostories', 'epic_id = ?', [$epicId]);
        $completedStories = Bean::count('ctostories', 'epic_id = ? AND status = ?', [$epicId, 'done']);

        $epic->story_count = $totalStories;
        $epic->stories_completed = $completedStories;

        if ($completedStories >= $totalStories && $totalStories > 0) {
            $epic->status = 'completed';
            output("  Epic {$epicId} completed!", true);
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
            if (!$this->isSessionRunning($sessionInfo['session'])) {
                output("Session completed: {$sessionInfo['session']}");
                $this->handleCompletion($storyId, $sessionInfo['issue_key']);
                unset($this->activeSessions[$storyId]);
            } else {
                // Show elapsed time for long-running sessions
                $elapsed = time() - $sessionInfo['started'];
                if ($elapsed > 300 && $elapsed % 60 < 30) { // Every minute after 5 mins
                    output("  Session {$sessionInfo['session']} running for " . floor($elapsed / 60) . "m");
                }
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
                        'started' => time()
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
        output("  Tenant: {$this->tenant}", true);
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
                output("  {$info['session']} ({$info['issue_key']}) - {$elapsed}s", true);
            }
        }
    }
}

// Main execution
echo "===========================================\n";
echo "Story Build Orchestrator\n";
echo "===========================================\n\n";

$orchestrator = new StoryBuildOrchestrator(
    $tenant,
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
