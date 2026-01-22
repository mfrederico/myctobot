#!/usr/bin/env php
<?php
/**
 * Sync Schema - Run seed files on existing workspace using RedBeanPHP fluid mode
 *
 * RedBeanPHP in fluid mode will:
 * - Create tables that don't exist
 * - Add columns that are missing
 * - Skip data that already exists (by checking unique fields)
 *
 * Usage:
 *   php scripts/sync-schema.php --workspace=gwt
 *   php scripts/sync-schema.php --workspace=gwt --seeds=40,41,42,43,44  # Run specific seeds
 *   php scripts/sync-schema.php --workspace=gwt --dry-run              # Show what would happen
 *   php scripts/sync-schema.php --list                                  # List available seeds
 */

$projectDir = dirname(__DIR__);
chdir($projectDir);

require_once $projectDir . '/vendor/autoload.php';

use RedBeanPHP\R as R;

// Parse arguments
$options = getopt('', ['workspace:', 'seeds:', 'dry-run', 'list', 'verbose', 'help']);

if (isset($options['help']) || empty($options)) {
    echo "Schema Sync Tool - Apply seeds to existing workspaces\n";
    echo "=====================================================\n\n";
    echo "Usage:\n";
    echo "  php scripts/sync-schema.php --workspace=<slug>           Sync all seeds\n";
    echo "  php scripts/sync-schema.php --workspace=<slug> --seeds=40,41  Specific seeds only\n";
    echo "  php scripts/sync-schema.php --workspace=<slug> --dry-run     Preview changes\n";
    echo "  php scripts/sync-schema.php --list                       List available seeds\n";
    echo "  php scripts/sync-schema.php --verbose                    Show detailed output\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// List available seeds
if (isset($options['list'])) {
    $seedsDir = $projectDir . '/services/Schema/Seeds';
    $seedFiles = glob($seedsDir . '/*.php');
    sort($seedFiles);

    echo "Available Seed Files:\n";
    echo "=====================\n";
    foreach ($seedFiles as $file) {
        $basename = basename($file);
        preg_match('/^(\d+)_(.+)\.php$/', $basename, $matches);
        $num = $matches[1] ?? '??';
        $name = $matches[2] ?? $basename;
        echo "  {$num}: {$name}\n";
    }
    echo "\nTotal: " . count($seedFiles) . " seed files\n";
    exit(0);
}

// Require workspace
if (!isset($options['workspace'])) {
    echo "Error: --workspace is required\n";
    exit(1);
}

$workspace = $options['workspace'];
$configFile = $projectDir . "/conf/config.{$workspace}.ini";

if (!file_exists($configFile)) {
    echo "Error: Config file not found: {$configFile}\n";
    exit(1);
}

// Parse config
$config = parse_ini_file($configFile, true);
if (!isset($config['database'])) {
    echo "Error: No database section in config\n";
    exit(1);
}

$db = $config['database'];
$dbName = $db['name'];
$dbUser = $db['user'];
$dbPass = $db['pass'] ?? '';
$dbHost = $db['host'] ?? 'localhost';

echo "Schema Sync for Workspace: {$workspace}\n";
echo "Database: {$dbName}\n";
if ($dryRun) echo "Mode: DRY RUN\n";
echo str_repeat('=', 50) . "\n\n";

// Connect to database
try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    R::setup($dsn, $dbUser, $dbPass);
    R::freeze(false); // Fluid mode - allows schema changes

    if ($verbose) {
        echo "Connected to database in fluid mode\n\n";
    }
} catch (Exception $e) {
    echo "Error connecting to database: " . $e->getMessage() . "\n";
    exit(1);
}

// Get existing tables
$existingTables = R::inspect();
if ($verbose) {
    echo "Existing tables: " . count($existingTables) . "\n";
}

// Determine which seeds to run
$seedsDir = $projectDir . '/services/Schema/Seeds';
$allSeedFiles = glob($seedsDir . '/*.php');
sort($allSeedFiles);

$seedsToRun = [];
if (isset($options['seeds'])) {
    // Filter to specific seed numbers
    $requestedSeeds = array_map('trim', explode(',', $options['seeds']));
    foreach ($allSeedFiles as $file) {
        $basename = basename($file);
        preg_match('/^(\d+)_/', $basename, $matches);
        if ($matches && in_array($matches[1], $requestedSeeds)) {
            $seedsToRun[] = $file;
        }
    }
} else {
    $seedsToRun = $allSeedFiles;
}

echo "Seeds to process: " . count($seedsToRun) . "\n\n";

// Track changes
$tablesCreated = [];
$columnsAdded = [];
$dataInserted = [];
$dataSkipped = [];

// Get list of tables helper function
function getTableList() {
    // R::inspect() without args returns array of table names
    $tables = R::inspect();
    if (!is_array($tables)) {
        return [];
    }
    // If it's an associative array of table => columns, get keys
    // If it's a simple list of table names, return as-is
    $firstValue = reset($tables);
    if (is_array($firstValue)) {
        return array_keys($tables);
    }
    return $tables;
}

// Get columns for a table
function getTableColumns($table) {
    try {
        $columns = R::inspect($table);
        if (is_array($columns)) {
            return array_keys($columns);
        }
        return [];
    } catch (Exception $e) {
        return [];
    }
}

// Process each seed file
foreach ($seedsToRun as $seedFile) {
    $basename = basename($seedFile);
    echo "Processing: {$basename}\n";

    // Get tables before
    $tablesBefore = getTableList();
    $columnsBefore = [];
    foreach ($tablesBefore as $table) {
        $columnsBefore[$table] = getTableColumns($table);
    }

    if (!$dryRun) {
        // Include the seed file - it will use R:: to create/modify beans
        try {
            include $seedFile;
        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
            continue;
        }
    }

    // Get tables after
    $tablesAfter = getTableList();

    // Check for new tables
    $newTables = array_diff($tablesAfter, $tablesBefore);
    if (!empty($newTables)) {
        foreach ($newTables as $table) {
            echo "  + Created table: {$table}\n";
            $tablesCreated[] = $table;
        }
    }

    // Check for new columns in existing tables
    foreach ($tablesAfter as $table) {
        $colsAfter = getTableColumns($table);
        $colsBefore = $columnsBefore[$table] ?? [];
        $newCols = array_diff($colsAfter, $colsBefore);
        if (!empty($newCols)) {
            foreach ($newCols as $col) {
                echo "  + Added column: {$table}.{$col}\n";
                $columnsAdded[] = "{$table}.{$col}";
            }
        }
    }

    if ($verbose && empty($newTables) && empty(array_filter($columnsAdded))) {
        echo "  (no schema changes)\n";
    }
}

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Summary:\n";
echo "  Tables created: " . count($tablesCreated) . "\n";
if (!empty($tablesCreated) && $verbose) {
    foreach ($tablesCreated as $t) echo "    - {$t}\n";
}
echo "  Columns added: " . count($columnsAdded) . "\n";
if (!empty($columnsAdded) && $verbose) {
    foreach ($columnsAdded as $c) echo "    - {$c}\n";
}

if ($dryRun) {
    echo "\n(Dry run - no changes were made)\n";
}

// Close connection
R::close();

echo "\nDone!\n";
