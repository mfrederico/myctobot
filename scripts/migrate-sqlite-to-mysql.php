<?php
/**
 * Migration Script: SQLite → MySQL
 *
 * Merges per-user SQLite databases into the main MySQL database.
 *
 * Usage:
 *   php scripts/migrate-sqlite-to-mysql.php [--dry-run] [--member=ID]
 *
 * Options:
 *   --dry-run    Show what would be migrated without making changes
 *   --member=ID  Only migrate a specific member's data
 */

require_once __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R as R;

// Parse command line options
$options = getopt('', ['dry-run', 'member:']);
$dryRun = isset($options['dry-run']);
$specificMember = $options['member'] ?? null;

echo "=== SQLite to MySQL Migration ===\n";
echo $dryRun ? "[DRY RUN MODE]\n" : "";
echo "\n";

// Load config
$config = parse_ini_file(__DIR__ . '/../conf/config.ini', true);
if (!$config) {
    die("ERROR: Cannot load config.ini\n");
}

// Connect to MySQL
$dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['name']}";
try {
    R::setup($dsn, $config['database']['user'], $config['database']['pass']);
    R::freeze(false); // Allow schema modifications
    echo "Connected to MySQL: {$config['database']['name']}\n";
} catch (Exception $e) {
    die("ERROR: Cannot connect to MySQL: " . $e->getMessage() . "\n");
}

// First, ensure schema is up to date
echo "\n--- Checking Schema ---\n";
$schemaFile = __DIR__ . '/../sql/tenant_schema.sql';
if (!file_exists($schemaFile)) {
    die("ERROR: tenant_schema.sql not found\n");
}

// Check if key tables exist, if not run schema
$tables = R::inspect();
$requiredTables = ['jiraboards', 'aidevjobs', 'enterprisesettings', 'repoconnections'];
$missingTables = array_diff($requiredTables, $tables);

if (!empty($missingTables)) {
    echo "Missing tables: " . implode(', ', $missingTables) . "\n";
    if (!$dryRun) {
        echo "Running tenant_schema.sql...\n";
        $sql = file_get_contents($schemaFile);
        // Split by semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt) && !preg_match('/^(--|SET|\/\*)/i', $stmt)) {
                try {
                    R::exec($stmt);
                } catch (Exception $e) {
                    // Ignore "table already exists" errors
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "  Warning: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        echo "Schema updated.\n";
    }
} else {
    echo "All required tables exist.\n";
}

// Get members with SQLite databases
echo "\n--- Finding Members with SQLite DBs ---\n";
$whereClause = "ceobot_db IS NOT NULL AND ceobot_db != '' AND status != 'system'";
if ($specificMember) {
    $whereClause .= " AND id = " . intval($specificMember);
}
$members = R::getAll("SELECT id, email, ceobot_db FROM member WHERE {$whereClause}");

if (empty($members)) {
    echo "No members with SQLite databases found.\n";
    exit(0);
}

echo "Found " . count($members) . " member(s) to migrate.\n";

// Tables to migrate and their structure
$tablesToMigrate = [
    'jiraboards' => [
        'needs_member_id' => true,  // Add member_id column
        'unique_key' => ['board_id', 'cloud_id'],
    ],
    'aidevjobs' => [
        'needs_member_id' => true,
        'unique_key' => ['issue_key'],
    ],
    'aidevjoblogs' => [
        'needs_member_id' => false,  // References aidevjobs via issue_key
        'unique_key' => null,
    ],
    'analysisresults' => [
        'needs_member_id' => false,  // FK to jiraboards
        'unique_key' => null,
    ],
    'repoconnections' => [
        'needs_member_id' => true,
        'unique_key' => ['repo_owner', 'repo_name'],
    ],
    'boardrepomapping' => [
        'needs_member_id' => false,  // FK to jiraboards
        'unique_key' => null,
    ],
    'enterprisesettings' => [
        'needs_member_id' => true,  // Settings per member
        'unique_key' => ['member_id', 'setting_key'],
    ],
    'anthropickeys' => [
        'needs_member_id' => true,
        'unique_key' => null,
    ],
    'digesthistory' => [
        'needs_member_id' => false,  // FK to jiraboards
        'unique_key' => null,
    ],
    'ticketanalysiscache' => [
        'needs_member_id' => false,  // FK to jiraboards
        'unique_key' => null,
    ],
];

// Add member_id column to tables that need it
echo "\n--- Ensuring member_id columns ---\n";
if (!$dryRun) {
    foreach ($tablesToMigrate as $table => $config) {
        if ($config['needs_member_id']) {
            try {
                // Check if column exists
                $columns = R::getAll("SHOW COLUMNS FROM `{$table}` LIKE 'member_id'");
                if (empty($columns)) {
                    R::exec("ALTER TABLE `{$table}` ADD COLUMN `member_id` INT NULL AFTER `id`");
                    R::exec("CREATE INDEX `idx_{$table}_member` ON `{$table}`(`member_id`)");
                    echo "  Added member_id to {$table}\n";
                }
            } catch (Exception $e) {
                echo "  Warning ({$table}): " . $e->getMessage() . "\n";
            }
        }
    }
}

// Migrate each member's data
foreach ($members as $member) {
    $memberId = $member['id'];
    $email = $member['email'];
    $sqliteDb = $member['ceobot_db'];
    $sqlitePath = __DIR__ . "/../database/{$sqliteDb}.sqlite";

    echo "\n--- Migrating Member {$memberId}: {$email} ---\n";

    if (!file_exists($sqlitePath)) {
        echo "  WARNING: SQLite file not found: {$sqlitePath}\n";
        continue;
    }

    // Connect to SQLite
    try {
        $sqlite = new PDO("sqlite:{$sqlitePath}");
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        echo "  ERROR: Cannot open SQLite: " . $e->getMessage() . "\n";
        continue;
    }

    // Track ID mappings for foreign keys
    $idMappings = [];

    // Migrate each table
    foreach ($tablesToMigrate as $table => $tableConfig) {
        echo "  Migrating {$table}... ";

        // Check if table exists in SQLite
        $tableCheck = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetch();
        if (!$tableCheck) {
            echo "not in SQLite, skipping.\n";
            continue;
        }

        // Get all rows from SQLite
        $rows = $sqlite->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);

        if ($count === 0) {
            echo "empty.\n";
            continue;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $oldId = $row['id'] ?? null;
            unset($row['id']); // Let MySQL auto-increment

            // Add member_id if needed
            if ($tableConfig['needs_member_id']) {
                $row['member_id'] = $memberId;
            }

            // Remap foreign keys
            if ($table === 'analysisresults' || $table === 'digesthistory' || $table === 'ticketanalysiscache' || $table === 'boardrepomapping') {
                if (isset($row['board_id']) && isset($idMappings['jiraboards'][$row['board_id']])) {
                    $row['board_id'] = $idMappings['jiraboards'][$row['board_id']];
                }
            }
            if ($table === 'boardrepomapping' && isset($row['repo_connection_id'])) {
                if (isset($idMappings['repoconnections'][$row['repo_connection_id']])) {
                    $row['repo_connection_id'] = $idMappings['repoconnections'][$row['repo_connection_id']];
                }
            }

            // Check for duplicates if unique key defined
            if ($tableConfig['unique_key'] && !$dryRun) {
                $conditions = [];
                $params = [];
                foreach ($tableConfig['unique_key'] as $col) {
                    if (isset($row[$col])) {
                        $conditions[] = "`{$col}` = ?";
                        $params[] = $row[$col];
                    }
                }
                if (!empty($conditions)) {
                    $existing = R::getRow(
                        "SELECT id FROM `{$table}` WHERE " . implode(' AND ', $conditions),
                        $params
                    );
                    if ($existing) {
                        $skipped++;
                        // Map old ID to existing ID
                        if ($oldId) {
                            $idMappings[$table][$oldId] = $existing['id'];
                        }
                        continue;
                    }
                }
            }

            if (!$dryRun) {
                try {
                    $bean = R::dispense($table);
                    foreach ($row as $col => $val) {
                        $bean->$col = $val;
                    }
                    $newId = R::store($bean);
                    $migrated++;

                    // Track ID mapping
                    if ($oldId) {
                        $idMappings[$table][$oldId] = $newId;
                    }
                } catch (Exception $e) {
                    echo "\n    ERROR inserting into {$table}: " . $e->getMessage() . "\n    ";
                }
            } else {
                $migrated++;
            }
        }

        echo "{$migrated} migrated, {$skipped} skipped (duplicates).\n";
    }
}

echo "\n=== Migration Complete ===\n";
if ($dryRun) {
    echo "This was a dry run. No changes were made.\n";
    echo "Run without --dry-run to perform the actual migration.\n";
}
