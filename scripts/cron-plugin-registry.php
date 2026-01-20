#!/usr/bin/env php
<?php
/**
 * MyCTOBot Plugin Registry Cache Refresh
 *
 * Run this script via cron to keep plugin registry cache up to date.
 * The script checks TTL and only refreshes when needed (unless --force is used).
 *
 * CRONTAB:
 * --------
 * # Run every hour (or adjust based on your refresh_interval config)
 * 0 * * * * cd /path/to/myctobot/scripts && php cron-plugin-registry.php --script >> ../log/cron.log 2>&1
 *
 * # Or run every 15 minutes and let TTL control actual refreshes
 * 0,15,30,45 * * * * cd /path/to/myctobot/scripts && php cron-plugin-registry.php --script >> ../log/cron.log 2>&1
 *
 * OPTIONS:
 * --------
 *   --script     REQUIRED for CLI execution
 *   --workspace     Process specific workspace (e.g., gwt, greenworks). If omitted, processes all workspaces.
 *   --verbose    Show detailed output
 *   --force      Force refresh regardless of TTL
 *   --help       Show this help message
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', ['verbose', 'force', 'script', 'workspace:', 'help']);

if (isset($options['help'])) {
    echo "MyCTOBot Plugin Registry Cache Refresh\n\n";
    echo "Usage: php cron-plugin-registry.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     REQUIRED for standalone script mode\n";
    echo "  --workspace     Process specific workspace (omit to process all workspaces)\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --force      Force refresh regardless of TTL\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$force = isset($options['force']);
$specificWorkspace = $options['workspace'] ?? null;

if ($verbose) {
    echo "MyCTOBot Plugin Registry Refresh\n";
    echo "================================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n";
    if ($specificWorkspace) echo "Workspace: {$specificWorkspace}\n";
    if ($force) echo "Mode: FORCE (ignoring TTL)\n";
    echo "\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \app\PluginRegistryCache;

// Load the cache class
require_once $baseDir . '/lib/PluginRegistryCache.php';

try {
    // Discover workspaces to process
    $workspacesToProcess = [];
    if ($specificWorkspace) {
        // Single workspace specified
        $workspaceConfig = $baseDir . "/conf/config.{$specificWorkspace}.ini";
        if (!file_exists($workspaceConfig)) {
            echo "Error: Workspace config not found: {$workspaceConfig}\n";
            exit(1);
        }
        $workspacesToProcess[$specificWorkspace] = $workspaceConfig;
    } else {
        // Discover all workspace configs
        $configFiles = glob($baseDir . '/conf/config.*.ini');
        foreach ($configFiles as $configFile) {
            $basename = basename($configFile);
            // Extract workspace name from config.{workspace}.ini
            if (preg_match('/^config\.([a-z0-9]+)\.ini$/', $basename, $matches)) {
                $workspaceSlug = $matches[1];
                // Skip 'example' config
                if ($workspaceSlug !== 'example') {
                    $workspacesToProcess[$workspaceSlug] = $configFile;
                }
            }
        }
    }

    if (empty($workspacesToProcess)) {
        if ($verbose) {
            echo "No workspace configs found. Nothing to process.\n";
        }
        exit(0);
    }

    if ($verbose) {
        echo "Workspaces to process: " . implode(', ', array_keys($workspacesToProcess)) . "\n\n";
    }

    $totalProcessed = 0;
    $totalErrors = 0;
    $totalSkipped = 0;

    // Process each workspace
    foreach ($workspacesToProcess as $workspaceSlug => $configFile) {
        if ($verbose) {
            echo "=== Processing workspace: {$workspaceSlug} ===\n";
        }

        try {
            // Initialize fresh bootstrap for this workspace
            $bootstrap = new \app\Bootstrap($configFile);

            // Set workspace in session for cache key differentiation
            $_SESSION['workspace_slug'] = $workspaceSlug;

            // Set timezone from config
            $configTimezone = Flight::get('app.timezone') ?? 'America/New_York';
            date_default_timezone_set($configTimezone);

            if ($verbose) {
                echo "Workspace initialized, timezone: {$configTimezone}\n";

                // Show current cache status
                $stats = PluginRegistryCache::getStats();
                echo "Current cache status:\n";
                echo "  - Plugins cached: {$stats['count']}\n";
                echo "  - Last refresh: {$stats['last_refresh_formatted']}\n";
                echo "  - TTL: {$stats['ttl']} seconds\n";
                if ($stats['ttl_remaining'] !== null) {
                    echo "  - TTL remaining: {$stats['ttl_remaining']} seconds\n";
                }
                echo "\n";
            }

            // Perform the refresh
            $result = PluginRegistryCache::refresh($force);

            if (isset($result['skipped']) && $result['skipped']) {
                $totalSkipped++;
                if ($verbose) {
                    echo "Skipped refresh: {$result['reason']}\n";
                }
            } else {
                $totalProcessed++;
                if ($verbose) {
                    echo "Refresh completed:\n";
                    echo "  - Plugins found: {$result['count']}\n";
                    echo "  - Duration: " . ($result['duration_ms'] ?? 'N/A') . " ms\n";
                    echo "  - Errors: " . count($result['errors']) . "\n";

                    if (!empty($result['errors'])) {
                        foreach ($result['errors'] as $error) {
                            echo "    - [{$error['source']}] {$error['error']}\n";
                        }
                    }
                }
            }

            // Count errors
            $totalErrors += count($result['errors'] ?? []);

            // Log the result
            Flight::get('log')->info('Plugin registry cache refreshed', [
                'workspace' => $workspaceSlug,
                'plugins_count' => $result['count'],
                'errors_count' => count($result['errors'] ?? []),
                'forced' => $force,
                'skipped' => $result['skipped'] ?? false
            ]);

        } catch (\Exception $e) {
            $totalErrors++;
            if ($verbose) {
                echo "Error processing workspace {$workspaceSlug}: {$e->getMessage()}\n\n";
            }
        }

        if ($verbose) {
            echo "\n";
        }
    }

    if ($verbose) {
        echo "================================\n";
        echo "All workspaces complete!\n";
        echo "Total processed: {$totalProcessed}\n";
        echo "Total skipped (TTL): {$totalSkipped}\n";
        echo "Total errors: {$totalErrors}\n";
    }

    exit($totalErrors > 0 ? 1 : 0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

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
