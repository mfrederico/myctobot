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
require_once 'lib/Bean.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\DirectiveOrchestrator;
use \app\services\CompletionDetector;
use \app\services\UserDatabaseService;
use \app\services\EncryptionService;

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
$member = R::findOne('member', 'level <= ? ORDER BY level ASC, id ASC', [50]);
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
    $boards = Bean::findAll('jiraboards', ' enabled = 1 ORDER BY id ASC LIMIT 1 ');
    if (!empty($boards)) {
        $board = reset($boards);
        $cloudId = $board->cloud_id;
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
