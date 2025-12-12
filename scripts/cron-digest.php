#!/usr/bin/env php
<?php
/**
 * MyCTOBot Daily Digest Cron Job
 *
 * Run this script via cron every 15 minutes to process scheduled digests:
 * 0,15,30,45 * * * * php /path/to/myctobot/scripts/cron-digest.php
 *
 * Options:
 *   --verbose    Show detailed output
 *   --dry-run    Check what would be sent without actually sending
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', ['verbose', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "MyCTOBot Daily Digest Processor\n\n";
    echo "Usage: php cron-digest.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Check what would be sent without sending\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

if ($verbose) {
    echo "MyCTOBot Digest Processor\n";
    echo "=========================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;

// Initialize the application via Bootstrap
try {
    $bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');

    if ($verbose) {
        echo "Application initialized\n\n";
    }

    // Check if this is a dry run
    if ($dryRun) {
        echo "DRY RUN MODE - No emails will be sent\n\n";

        // Just list what would be processed
        $members = R::findAll('member', 'status = ?', ['active']);
        $userDbPath = Flight::get('ceobot.user_db_path') ?? 'database/';

        foreach ($members as $member) {
            if (empty($member->ceobot_db)) continue;

            $dbPath = $userDbPath . $member->ceobot_db . '.sqlite';
            if (!file_exists($dbPath)) continue;

            $userDb = new \SQLite3($dbPath);
            $result = $userDb->query("SELECT * FROM jira_boards WHERE enabled = 1 AND digest_enabled = 1");

            while ($board = $result->fetchArray(SQLITE3_ASSOC)) {
                echo "Would process: {$member->email} - {$board['board_name']} ({$board['project_key']})\n";
                echo "  Digest Time: {$board['digest_time']} ({$board['timezone']})\n";
                echo "  Last Digest: " . ($board['last_digest_at'] ?? 'Never') . "\n\n";
            }

            $userDb->close();
        }

        exit(0);
    }

    // Process digests
    require_once $baseDir . '/services/DigestService.php';

    $digestService = new \app\services\DigestService($verbose);
    $result = $digestService->processAllDigests();

    if ($verbose) {
        echo "\n=========================\n";
        echo "Processing complete!\n";
        echo "Digests sent: {$result['processed']}\n";
        echo "Errors: {$result['errors']}\n";
    }

    exit($result['errors'] > 0 ? 1 : 0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

    // Try to log if possible
    try {
        $logger = Flight::log();
        if ($logger) {
            $logger->error($message, ['trace' => $e->getTraceAsString()]);
        }
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    exit(1);
}
