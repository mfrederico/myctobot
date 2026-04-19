#!/usr/bin/env php
<?php
/**
 * Reset Permission Cache
 * Clears the permission cache from CLI without needing to restart PHP-FPM
 *
 * Usage: php scripts/resetcache.php
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

// Load bootstrap
require_once __DIR__ . '/../bootstrap.php';

use \RedBeanPHP\R as R;
use \app\PermissionCache;

// Colors for output
define('GREEN', "\033[0;32m");
define('YELLOW', "\033[1;33m");
define('BLUE', "\033[0;34m");
define('NC', "\033[0m"); // No Color

echo BLUE . "\n=== TikNix Permission Cache Reset ===\n" . NC;

try {
    // Initialize application
    $app = new app\Bootstrap('conf/config.ini');

    // Get stats before clearing
    echo "\nCurrent cache status:\n";
    $stats = PermissionCache::getStats();
    echo "  Cached permissions: " . $stats['count'] . "\n";
    echo "  Memory usage: " . number_format($stats['memory'] / 1024, 2) . " KB\n";
    echo "  Cache loaded: " . ($stats['cache_loaded'] ? 'Yes' : 'No') . "\n";
    echo "  APCu available: " . ($stats['apcu_available'] ? 'Yes' : 'No') . "\n";
    echo "  Currently in APCu: " . ($stats['in_apcu'] ? 'Yes' : 'No') . "\n";
    echo "  Cache hits: " . $stats['hits'] . "\n";
    echo "  Cache misses: " . $stats['misses'] . "\n";
    echo "  Hit rate: " . number_format($stats['hit_rate'], 1) . "%\n";
    echo "  Cache version: " . $stats['cache_version'] . "\n";

    // Clear the cache
    echo "\n" . YELLOW . "Clearing cache..." . NC . "\n";
    PermissionCache::clear();

    // Get new stats after clearing
    $newStats = PermissionCache::getStats();
    echo GREEN . "✓ Cache cleared successfully" . NC . "\n";
    echo "  New cache version: " . $newStats['cache_version'] . "\n";

    // Reload to verify
    echo "\n" . YELLOW . "Reloading cache from database..." . NC . "\n";
    $permissions = PermissionCache::reload();
    echo GREEN . "✓ Cache reloaded with " . count($permissions) . " permissions" . NC . "\n";

    // Show updated stats
    $finalStats = PermissionCache::getStats();
    echo "\nFinal cache status:\n";
    echo "  Cached permissions: " . $finalStats['count'] . "\n";
    echo "  Memory usage: " . number_format($finalStats['memory'] / 1024, 2) . " KB\n";
    echo "  Currently in APCu: " . ($finalStats['in_apcu'] ? 'Yes' : 'No') . "\n";

    echo "\n" . BLUE . "=== Cache Reset Complete ===" . NC . "\n";
    echo "Next web request will automatically use the new cache version.\n";
    echo "No PHP-FPM restart required!\n\n";

} catch (\Exception $e) {
    echo "\n" . "\033[0;31m" . "❌ Error: " . $e->getMessage() . NC . "\n\n";
    exit(1);
}
