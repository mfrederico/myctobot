#!/usr/bin/env php
<?php
/**
 * MyCTOBot Plugin Scanner CLI
 *
 * Background script to scan configured repository sources for plugins.
 * Can scan all repositories or a single repository.
 *
 * USAGE:
 * ------
 * # Scan all enabled repositories:
 * php scripts/scan-plugins.php --script --secret=KEY --verbose
 *
 * # Scan a single repository:
 * php scripts/scan-plugins.php --script --secret=KEY --repo-id=5 --verbose
 *
 * # With workspace support:
 * php scripts/scan-plugins.php --script --secret=KEY --workspace=gwt --verbose
 *
 * OPTIONS:
 * --------
 *   --script        REQUIRED for CLI execution
 *   --secret        REQUIRED - Authentication key (cron.api_key in config.ini)
 *   --repo-id       Optional - Scan only this repository connection ID
 *   --workspace        Optional - Workspace slug for multi-workspace setups
 *   --verbose       Show detailed output
 *   --help          Show this help message
 *
 * EXIT CODES:
 * -----------
 *   0 - Success (scan completed)
 *   1 - Error (invalid arguments, authentication failure, scan failure)
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', [
    'repo-id:',
    'workspace:',
    'verbose',
    'help',
    'script',
    'secret:'
]);

if (isset($options['help'])) {
    echo "MyCTOBot Plugin Scanner\n\n";
    echo "Usage:\n";
    echo "  php scripts/scan-plugins.php --script --secret=KEY\n\n";
    echo "Options:\n";
    echo "  --script      REQUIRED for CLI execution\n";
    echo "  --secret      REQUIRED - Authentication key (from config.ini)\n";
    echo "  --repo-id     Scan only this repository connection ID (optional)\n";
    echo "  --workspace      Workspace slug for multi-workspace setups (optional)\n";
    echo "  --verbose     Show detailed output\n";
    echo "  --help        Show this help message\n\n";
    echo "Examples:\n";
    echo "  # Scan all repositories\n";
    echo "  php scripts/scan-plugins.php --script --secret=mykey --verbose\n\n";
    echo "  # Scan single repository\n";
    echo "  php scripts/scan-plugins.php --script --secret=mykey --repo-id=5\n\n";
    echo "  # With workspace\n";
    echo "  php scripts/scan-plugins.php --script --secret=mykey --workspace=gwt\n";
    exit(0);
}

$verbose = isset($options['verbose']);

if ($verbose) {
    echo "MyCTOBot Plugin Scanner\n";
    echo "=======================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \app\services\PluginScannerService;

// Load the service
require_once $baseDir . '/services/PluginScannerService.php';

try {
    // Determine config file based on workspace parameter
    $workspace = $options['workspace'] ?? null;
    if ($workspace) {
        $configFile = $baseDir . "/conf/config.{$workspace}.ini";
        if (!file_exists($configFile)) {
            echo "Error: Workspace config not found: {$configFile}\n";
            exit(1);
        }
    } else {
        $configFile = $baseDir . '/conf/config.ini';
    }

    $bootstrap = new \app\Bootstrap($configFile);

    if ($verbose) {
        echo "Application initialized" . ($workspace ? " (workspace: {$workspace})" : "") . "\n\n";
    }

    // Validate CLI secret key for authentication
    $providedSecret = $options['secret'] ?? null;
    $expectedSecret = Flight::get('cron.api_key');

    if (empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
        echo "Error: Invalid or missing --secret parameter\n";
        echo "The secret key must match cron.api_key in config.ini\n";
        exit(1);
    }

    // Get optional repo ID
    $repoId = isset($options['repo-id']) ? (int)$options['repo-id'] : null;

    // Create scanner service
    $scanner = new PluginScannerService($verbose);

    // Run the appropriate scan
    if ($repoId) {
        if ($verbose) {
            echo "Scanning single repository (ID: {$repoId})...\n\n";
        }
        $result = $scanner->scanRepository($repoId);
    } else {
        if ($verbose) {
            echo "Scanning all enabled repositories...\n\n";
        }
        $result = $scanner->scanAllSources();
    }

    // Output results
    if ($verbose) {
        echo "\n=======================\n";
        echo "Scan Complete!\n";
        echo "=======================\n";
        echo "Scan ID:           {$result['scan_uid']}\n";
        echo "Repos scanned:     {$result['repos_scanned']}\n";
        echo "Plugins found:     {$result['plugins_found']}\n";
        echo "Errors:            {$result['errors_encountered']}\n";

        if (!empty($result['plugins'])) {
            echo "\nDiscovered Plugins:\n";
            foreach ($result['plugins'] as $plugin) {
                echo "  - {$plugin['name']} v{$plugin['version']} ({$plugin['repo_owner']}/{$plugin['repo_name']})\n";
            }
        }

        if (!empty($result['errors'])) {
            echo "\nErrors:\n";
            foreach ($result['errors'] as $error) {
                echo "  - {$error['repo']}: {$error['error']}\n";
            }
        }
    }

    // Exit with appropriate code
    if ($result['errors_encountered'] > 0 && $result['plugins_found'] === 0) {
        // All repos failed
        exit(1);
    }

    exit(0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

    // Try to log if possible
    try {
        $logger = Flight::get('log');
        if ($logger) {
            $logger->error($message, ['trace' => $e->getTraceAsString()]);
        }
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    exit(1);
}
