#!/usr/bin/env php
<?php
/**
 * Find _id columns that are not actual foreign keys
 *
 * This script identifies columns ending in _id that don't have corresponding
 * FK constraints, which may indicate they should be renamed to _uid or
 * converted to proper RedBeanPHP associations.
 *
 * Usage:
 *   php scripts/find-orphan-id-columns.php --script --tenant=tiknix
 *   php scripts/find-orphan-id-columns.php --script --tenant=tiknix --verbose
 *   php scripts/find-orphan-id-columns.php --script --db=default  # Check default DB
 */

$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);
chdir($baseDir);

$options = getopt('', ['script', 'tenant:', 'db:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Find _id columns that are not actual foreign keys\n\n";
    echo "Usage: php scripts/find-orphan-id-columns.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     REQUIRED for CLI execution\n";
    echo "  --tenant     Tenant slug (e.g., tiknix, gwt)\n";
    echo "  --db         Database to check: 'default' or 'tenant' (default: tenant)\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

if (!isset($options['script'])) {
    echo "Error: --script flag required for CLI execution\n";
    exit(1);
}

$verbose = isset($options['verbose']);
$tenantSlug = $options['tenant'] ?? null;
$dbType = $options['db'] ?? 'tenant';

if (!$tenantSlug && $dbType === 'tenant') {
    echo "Error: --tenant required when checking tenant database\n";
    exit(1);
}

require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap
if ($tenantSlug) {
    $configFile = $baseDir . "/conf/config.{$tenantSlug}.ini";
    if (!file_exists($configFile)) {
        echo "Error: Config not found: {$configFile}\n";
        exit(1);
    }
    require_once $baseDir . '/bootstrap.php';
    $bootstrap = new \app\Bootstrap($configFile);
} else {
    require_once $baseDir . '/bootstrap.php';
    $bootstrap = new \app\Bootstrap();
}

use \app\Bean;

echo "=== Finding Orphan _id Columns ===\n";
echo "Tenant: " . ($tenantSlug ?? 'default') . "\n";
echo "Database: {$dbType}\n\n";

// Get PDO connection from Bean wrapper
$pdo = Bean::getDatabaseAdapter()->getDatabase()->getPDO();

// Get current database name for information_schema queries
$dbNameResult = $pdo->query("SELECT DATABASE()")->fetch(\PDO::FETCH_NUM);
$currentDb = $dbNameResult[0];

// Get all tables from information_schema (safer than SHOW TABLES)
$tableStmt = $pdo->prepare("
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = ?
");
$tableStmt->execute([$currentDb]);
$tables = $tableStmt->fetchAll(\PDO::FETCH_COLUMN);

echo "Found " . count($tables) . " tables\n\n";

$orphanColumns = [];
$validFks = [];

// Get all columns ending in _id from information_schema
// Use escape for underscore to match literal _id (not uid, which ends in 'id')
$colStmt = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND COLUMN_NAME LIKE '%\\_id' ESCAPE '\\\\'
      AND COLUMN_NAME != 'id'
    ORDER BY TABLE_NAME, COLUMN_NAME
");
$colStmt->execute([$currentDb]);
$idColumns = $colStmt->fetchAll(\PDO::FETCH_ASSOC);

// Get all actual foreign keys from information_schema
$fkStmt = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = ?
      AND REFERENCED_TABLE_NAME IS NOT NULL
");
$fkStmt->execute([$currentDb]);
$fkRows = $fkStmt->fetchAll(\PDO::FETCH_ASSOC);

// Build lookup of actual FKs
$actualFks = [];
foreach ($fkRows as $fk) {
    $key = $fk['TABLE_NAME'] . '.' . $fk['COLUMN_NAME'];
    $actualFks[$key] = $fk['REFERENCED_TABLE_NAME'];
}

// Check each _id column
foreach ($idColumns as $col) {
    $table = $col['TABLE_NAME'];
    $colName = $col['COLUMN_NAME'];
    $key = "{$table}.{$colName}";

    if (isset($actualFks[$key])) {
        // This is a valid FK
        $validFks[] = [
            'table' => $table,
            'column' => $colName,
            'references' => $actualFks[$key]
        ];
        if ($verbose) {
            echo "[FK] {$table}.{$colName} -> {$actualFks[$key]}\n";
        }
    } else {
        // This is an orphan _id column (no FK constraint)
        $orphanColumns[] = [
            'table' => $table,
            'column' => $colName
        ];
        if ($verbose) {
            echo "[ORPHAN] {$table}.{$colName}\n";
        }
    }
}

echo "\n=== Results ===\n\n";

if (!empty($orphanColumns)) {
    echo "ORPHAN _id COLUMNS (no FK constraint):\n";
    echo str_repeat('-', 60) . "\n";

    $byTable = [];
    foreach ($orphanColumns as $orphan) {
        $byTable[$orphan['table']][] = $orphan['column'];
    }

    foreach ($byTable as $table => $columns) {
        echo "\n{$table}:\n";
        foreach ($columns as $col) {
            // Suggest what it might need to be
            $suggestion = '';
            if (preg_match('/^(.+)_id$/', $col, $m)) {
                $base = $m[1];
                // Check if there's a table with this name
                if (in_array($base, $tables)) {
                    $suggestion = " -> Should be FK to '{$base}' table";
                } elseif (preg_match('/^(story|epic|directive|project|agent)$/', $base)) {
                    $suggestion = " -> Should probably be '{$base}_uid' (string identifier)";
                } else {
                    $suggestion = " -> Review: may need FK or rename to _uid";
                }
            }
            echo "  - {$col}{$suggestion}\n";
        }
    }

    echo "\n";
    echo "Total orphan columns: " . count($orphanColumns) . "\n";
} else {
    echo "No orphan _id columns found!\n";
}

if ($verbose && !empty($validFks)) {
    echo "\n\nVALID FOREIGN KEYS:\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($validFks as $fk) {
        echo "  {$fk['table']}.{$fk['column']} -> {$fk['references']}\n";
    }
    echo "\nTotal valid FKs: " . count($validFks) . "\n";
}

echo "\n=== Recommendations ===\n";
echo "1. Columns that should reference existing tables need FK associations\n";
echo "2. Columns like 'story_id', 'epic_id' should be 'story_uid', 'epic_uid'\n";
echo "3. Use RedBeanPHP associations: \$child->parenttable = \$parentBean\n";
echo "\n";
