#!/usr/bin/env php
<?php
/**
 * Directive Processor Cron Script
 *
 * Processes CEO directives through the autonomous CTO system.
 * Run every 5 minutes via cron:
 *   * /5 * * * * php /path/to/scripts/cron-directive-processor.php --tenant=gwt
 *
 * Usage:
 *   php cron-directive-processor.php --tenant=gwt [--verbose] [--dry-run]
 */

// Parse command line arguments
$options = getopt('', ['tenant:', 'verbose', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Directive Processor - Autonomous CTO System

Usage: php cron-directive-processor.php --tenant=<tenant> [options]

Options:
  --tenant=<name>   Required. Tenant slug (e.g., gwt)
  --verbose         Show detailed output
  --dry-run         Check what would be processed without actually processing
  --help            Show this help message

Example:
  php cron-directive-processor.php --tenant=gwt --verbose

HELP;
    exit(0);
}

if (empty($options['tenant'])) {
    echo "Error: --tenant is required\n";
    echo "Usage: php cron-directive-processor.php --tenant=<tenant>\n";
    exit(1);
}

$tenant = $options['tenant'];
$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// Change to project root
chdir(dirname(__DIR__));

// Bootstrap
require_once 'vendor/autoload.php';
require_once 'lib/FlightMap.php';

use \Flight as Flight;
use \app\Bean;
use \app\services\DirectiveOrchestrator;
use \app\services\CompletionDetector;
use \app\services\UserDatabaseService;
use \app\services\EncryptionService;
use \app\services\AIDevJobService;

// Load tenant config
$configFile = "conf/config.{$tenant}.ini";
if (!file_exists($configFile)) {
    echo "Error: Tenant config not found: {$configFile}\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
if (!$config) {
    echo "Error: Failed to parse config file\n";
    exit(1);
}

// Lockfile to prevent concurrent runs (race condition protection)
$lockFile = "/tmp/cron-directive-processor-{$tenant}.lock";
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle) {
    echo "Error: Could not create lock file: {$lockFile}\n";
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another instance is already running - this is not an error, just skip
    if ($verbose) {
        echo "Another directive processor is already running for tenant: {$tenant}\n";
    }
    fclose($lockHandle);
    exit(0);
}

// Write PID to lock file for debugging
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

// Register shutdown handler to release lock on exit (normal or error)
register_shutdown_function(function() use ($lockHandle, $lockFile) {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    @unlink($lockFile);
});

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
        Bean::setup("sqlite:{$dbPath}");
    } else {
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $name = $dbConfig['name'] ?? $tenant;
        $user = $dbConfig['user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? '';
        Bean::setup("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
    }
    Bean::freeze(true);
} catch (\Exception $e) {
    echo "Error: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Initialize logger
$logger = new \Monolog\Logger('directive_processor');
$handler = new \Monolog\Handler\StreamHandler(
    $verbose ? 'php://stdout' : "log/directive_processor-" . date('Y-m-d') . ".log",
    $verbose ? \Monolog\Level::Debug : \Monolog\Level::Info
);
$logger->pushHandler($handler);
Flight::set('log', $logger);

// Output helper
function output($message, $forceVerbose = false) {
    global $verbose;
    if ($verbose || $forceVerbose) {
        echo "[" . date('H:i:s') . "] " . $message . "\n";
    }
}

output("Starting Directive Processor for tenant: {$tenant}", true);
output("Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE'), true);

// Get processing config
$processingConfig = [
    'max_concurrent_projects' => $config['directives']['max_concurrent_projects'] ?? 3,
    'auto_start_stories' => ($config['directives']['auto_start_stories'] ?? 'true') === 'true',
    'batch_size' => $config['directives']['batch_size'] ?? 5,
];

output("Config: " . json_encode($processingConfig));

// Find a member to use for processing (first admin or first member)
$member = Bean::findOne('member', 'level <= ? ORDER BY level ASC, id ASC', [50]);
if (!$member) {
    echo "Error: No member found to process directives\n";
    exit(1);
}

$memberId = $member->id;
output("Processing as member ID: {$memberId} ({$member->email})");

// Get Jira context from first configured board
$cloudId = null;
$projectKey = null;

try {
    $boards = Bean::findAll('jiraboards', ' is_active = 1 ORDER BY id ASC LIMIT 1 ');
    if (!empty($boards)) {
        $board = reset($boards);
        $cloudId = $board->cloud_uid;
        $projectKey = $board->project_key;
        output("Jira context: {$projectKey} (cloud: {$cloudId})");
    } else {
        output("Warning: No enabled Jira boards found - will check GitHub");
    }
} catch (\Exception $e) {
    output("Warning: Could not get Jira context: " . $e->getMessage());
}

// Get GitHub context if Jira isn't configured
// NOTE: GitHub OAuth token is stored encrypted in `enterprisesettings` table with key 'github_token'
//       (see controls/Github.php callback() method for where it's stored after OAuth)
//       The repo connection info is in `repoconnections` but the token is shared across all repos
$githubConfig = null;

if (!$cloudId || !$projectKey) {
    try {
        // Get GitHub token from enterprisesettings (where OAuth stores it)
        $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
        $githubToken = null;

        if ($tokenSetting && !empty($tokenSetting->setting_value)) {
            $githubToken = EncryptionService::decrypt($tokenSetting->setting_value);
        }

        if ($githubToken) {
            // Find an enabled GitHub repo
            $repos = Bean::findAll('repoconnections', ' enabled = 1 AND provider = ? ORDER BY id ASC LIMIT 1 ', ['github']);
            if (!empty($repos)) {
                $repo = reset($repos);
                $githubConfig = [
                    'access_token' => $githubToken,
                    'owner' => $repo->repo_owner,
                    'repo' => $repo->repo_name
                ];
                output("GitHub context: {$repo->repo_owner}/{$repo->repo_name}");
            }
        }

        if (!$githubConfig) {
            output("Warning: No GitHub token or repos configured - stories will be created without issue tracking");
        }
    } catch (\Exception $e) {
        output("Warning: Could not get GitHub context: " . $e->getMessage());
    }
}

if ($dryRun) {
    // Dry run - just report what would be processed
    output("=== DRY RUN - Checking pending work ===", true);

    $pendingDirectives = Bean::count('ceodirectives', 'status = ?', ['received']);
    output("Pending directives: {$pendingDirectives}", true);

    $parsingDirectives = Bean::count('ceodirectives', 'status IN (?, ?)', ['parsing', 'planning']);
    output("In-progress directives: {$parsingDirectives}", true);

    $executingDirectives = Bean::count('ceodirectives', 'status = ?', ['executing']);
    output("Executing directives: {$executingDirectives}", true);

    $inProgressProjects = Bean::count('ctoprojects', 'status = ?', ['in_progress']);
    output("In-progress projects: {$inProgressProjects}", true);

    $pendingStories = Bean::count('ctostories', 'status = ?', ['backlog']);
    output("Pending stories (backlog): {$pendingStories}", true);

    output("=== DRY RUN COMPLETE ===", true);
    exit(0);
}

// Process queue
try {
    output("=== Processing Directive Queue ===");

    // Initialize orchestrator
    $orchestrator = new DirectiveOrchestrator($memberId, $processingConfig);
    if ($cloudId && $projectKey) {
        $orchestrator->setJiraContext($cloudId, $projectKey);
    }
    if ($githubConfig) {
        $orchestrator->setGitHubContext(
            $githubConfig['access_token'],
            $githubConfig['owner'],
            $githubConfig['repo']
        );
    }

    // Process queue
    $orchestratorResults = $orchestrator->processQueue();

    output("Directives processed: {$orchestratorResults['directives_processed']}");
    output("Projects checked: {$orchestratorResults['projects_checked']}");
    output("Stories queued: {$orchestratorResults['stories_queued']}");

    if (!empty($orchestratorResults['errors'])) {
        foreach ($orchestratorResults['errors'] as $error) {
            output("ERROR: {$error}", true);
        }
    }

    // Check completions
    output("=== Checking Completions ===");

    $detector = new CompletionDetector($memberId);
    $detectorResults = $detector->checkAll();

    output("Stories updated: {$detectorResults['stories_updated']}");
    output("Epics updated: {$detectorResults['epics_updated']}");
    output("Projects completed: {$detectorResults['projects_completed']}");
    output("Stories unblocked: {$detectorResults['stories_unblocked']}");

    // Check AOE sessions for stale status
    output("=== Syncing AOE Session Status ===");

    $aoePath = dirname(__DIR__) . '/../aoe-php/vendor/autoload.php';
    if (file_exists($aoePath)) {
        require_once $aoePath;

        try {
            \Aoe\Tenant\TenantContext::set($tenant);
            $aoeStorage = new \Aoe\Session\Storage($tenant);
            $aoeTmux = new \Aoe\Tmux\TmuxService($tenant);
            $statusDetector = new \Aoe\Tmux\StatusDetector();

            $sessions = $aoeStorage->loadAll();
            $aoeUpdated = 0;
            $aoeStale = 0;

            foreach ($sessions as $session) {
                // Only check sessions that claim to be active
                if (!$session->status->isActive()) {
                    continue;
                }

                $tmuxName = $session->getTmuxName();
                $tmuxExists = $aoeTmux->sessionExistsByName($tmuxName);

                if ($tmuxExists) {
                    // Tmux running - try to detect real status
                    $content = $aoeTmux->capturePaneByName($tmuxName, 20);
                    $newStatus = $statusDetector->detect($content);

                    if ($newStatus !== $session->status) {
                        $session->status = $newStatus;
                        $aoeStorage->save($session);
                        $aoeUpdated++;
                        output("  Updated {$session->title}: {$newStatus->value}");
                    }
                } else {
                    // Tmux gone but status says active - mark as stopped
                    $session->status = \Aoe\Session\Status::Stopped;
                    $aoeStorage->save($session);
                    $aoeStale++;
                    output("  Marked stale: {$session->title}");

                    // Cleanup job labels for this session's issue
                    $issueKey = $session->reference ?? $session->title ?? null;
                    if ($issueKey && preg_match('/^[A-Z]+-\d+$/', $issueKey)) {
                        try {
                            require_once __DIR__ . '/../services/AIDevJobService.php';
                            $jobService = new \app\services\AIDevJobService();
                            $cleanupResult = $jobService->cleanupJobLabels(null, $issueKey);
                            if (!empty($cleanupResult['labels_removed'])) {
                                output("    Cleaned up labels: " . implode(', ', $cleanupResult['labels_removed']));
                            }
                        } catch (\Exception $e) {
                            output("    Warning: Label cleanup failed: " . $e->getMessage());
                        }
                    }
                }
            }

            output("AOE sessions synced: {$aoeUpdated} updated, {$aoeStale} marked stopped");

            // Clear any queue check trigger files
            $queueCheckFile = "/tmp/aoe-queue-check-{$tenant}";
            if (file_exists($queueCheckFile)) {
                unlink($queueCheckFile);
                output("Queue check trigger cleared");
            }
        } catch (\Exception $e) {
            output("Warning: AOE sync failed: " . $e->getMessage());
        }
    } else {
        output("AOE-PHP not found, skipping session sync");
    }

    // Process job queue - start queued jobs if capacity available
    output("=== Processing Job Queue ===");

    try {
        $jobService = new AIDevJobService();
        $queueResults = $jobService->processJobQueue($tenant);

        output("Jobs started from queue: {$queueResults['jobs_started']}");
        output("Jobs remaining in queue: {$queueResults['jobs_remaining']}");

        if (!empty($queueResults['errors'])) {
            foreach ($queueResults['errors'] as $error) {
                output("  Queue error: {$error}");
            }
        }
    } catch (\Exception $e) {
        output("Warning: Queue processing failed: " . $e->getMessage());
    }

    output("=== Processing Complete ===", true);

} catch (\Exception $e) {
    $logger->error('Fatal error in directive processor', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
