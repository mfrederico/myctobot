<?php
/**
 * Migration: Rename tables to lowercase (no underscores)
 *
 * RedBeanPHP R::dispense() requires all lowercase bean types with no underscores.
 * This migration renames all snake_case tables to lowercase format.
 *
 * Run with: php database/migrations/001_rename_tables_lowercase.php
 */

$baseDir = dirname(dirname(__DIR__));
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';

// Load config from INI file
$configFile = $baseDir . '/conf/config.ini';
if (!file_exists($configFile)) {
    die("Configuration file not found: {$configFile}\n");
}
$config = parse_ini_file($configFile, true);

// Table rename mapping: old_name => new_name (user database tables)
$tableRenames = [
    'ai_dev_jobs' => 'aidevjobs',
    'ai_dev_job_logs' => 'aidevjoblogs',
    'enterprise_settings' => 'enterprisesettings',
    'repo_connections' => 'repoconnections',
    'board_repo_mapping' => 'boardrepomapping',
    'jira_boards' => 'jiraboards',
    'digest_history' => 'digesthistory',
    'ticket_analysis_cache' => 'ticketanalysiscache',
    'analysis_results' => 'analysisresults',
    'user_settings' => 'usersettings',
];

/**
 * Get all tables in a SQLite database
 */
function getTables($dbPath) {
    $pdo = new PDO("sqlite:$dbPath");
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    $tables = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $tables[] = $row['name'];
    }
    return $tables;
}

/**
 * Rename a table in SQLite
 */
function renameTable($pdo, $oldName, $newName) {
    $pdo->exec("ALTER TABLE `$oldName` RENAME TO `$newName`");
}

/**
 * Merge data from old table to new table (for cases where both exist)
 */
function mergeAndDropOld($pdo, $oldName, $newName) {
    // Get columns from old table
    $result = $pdo->query("PRAGMA table_info(`$oldName`)");
    $columns = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['name'];
    }

    if (empty($columns)) {
        return;
    }

    $columnList = implode(', ', array_map(fn($c) => "`$c`", $columns));

    // Insert data from old table that doesn't exist in new (based on id or unique key)
    // For enterprise_settings, use setting_key as unique
    if ($oldName === 'enterprise_settings') {
        $pdo->exec("
            INSERT OR IGNORE INTO `$newName` ($columnList)
            SELECT $columnList FROM `$oldName`
        ");
    } else {
        // For other tables, just insert with IGNORE to skip duplicates
        $pdo->exec("
            INSERT OR IGNORE INTO `$newName` ($columnList)
            SELECT $columnList FROM `$oldName`
        ");
    }

    // Drop old table
    $pdo->exec("DROP TABLE IF EXISTS `$oldName`");
}

/**
 * Migrate a single database file
 */
function migrateDatabase($dbPath, $tableRenames, $verbose = true) {
    if (!file_exists($dbPath)) {
        if ($verbose) echo "  [SKIP] File not found: $dbPath\n";
        return false;
    }

    try {
        $pdo = new PDO("sqlite:$dbPath");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $existingTables = getTables($dbPath);
        $changes = 0;

        foreach ($tableRenames as $oldName => $newName) {
            $hasOld = in_array($oldName, $existingTables);
            $hasNew = in_array($newName, $existingTables);

            if ($hasOld && $hasNew) {
                // Both exist - merge old into new, then drop old
                if ($verbose) echo "  [MERGE] $oldName -> $newName\n";
                mergeAndDropOld($pdo, $oldName, $newName);
                $changes++;
            } elseif ($hasOld && !$hasNew) {
                // Only old exists - rename it
                if ($verbose) echo "  [RENAME] $oldName -> $newName\n";
                renameTable($pdo, $oldName, $newName);
                $changes++;
            } elseif (!$hasOld && $hasNew) {
                // Already migrated
                if ($verbose) echo "  [OK] $newName already exists\n";
            }
            // else: neither exists, nothing to do
        }

        return $changes;

    } catch (Exception $e) {
        if ($verbose) echo "  [ERROR] " . $e->getMessage() . "\n";
        return false;
    }
}

// Main execution
echo "=== Table Rename Migration ===\n\n";

// Get database directory
$dbDir = $config['ceobot']['user_db_path'] ?? 'database/';
if (!str_ends_with($dbDir, '/')) {
    $dbDir .= '/';
}

echo "Database directory: $dbDir\n\n";

// Find all SQLite files
$sqliteFiles = glob($dbDir . '*.sqlite');

if (empty($sqliteFiles)) {
    echo "No SQLite database files found in $dbDir\n";
    exit(0);
}

echo "Found " . count($sqliteFiles) . " database file(s)\n\n";

$totalChanges = 0;
$successCount = 0;
$errorCount = 0;

foreach ($sqliteFiles as $dbFile) {
    echo "Processing: " . basename($dbFile) . "\n";

    $result = migrateDatabase($dbFile, $tableRenames);

    if ($result === false) {
        $errorCount++;
    } else {
        $successCount++;
        $totalChanges += $result;
    }

    echo "\n";
}

echo "=== Migration Complete ===\n";
echo "Databases processed: $successCount\n";
echo "Errors: $errorCount\n";
echo "Total table changes: $totalChanges\n";
