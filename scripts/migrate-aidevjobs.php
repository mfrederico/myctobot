<?php
/**
 * Migration script to create aidevjobs tables
 *
 * Usage: php scripts/migrate-aidevjobs.php [config_name]
 * Example: php scripts/migrate-aidevjobs.php gwt
 */
require_once __DIR__ . '/../vendor/autoload.php';

// Determine config file
$configName = $argv[1] ?? 'ini';
$configFile = __DIR__ . "/../conf/config.{$configName}.ini";
if (!file_exists($configFile)) {
    // Try without suffix
    $configFile = __DIR__ . '/../conf/config.ini';
}
if (!file_exists($configFile)) {
    die("Config file not found: $configFile\n");
}
echo "Using config: $configFile\n";

$config = parse_ini_file($configFile, true);
$db = $config['database'] ?? [];

if (empty($db['host'])) {
    die("Database config missing\n");
}

$dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']}";

try {
    \RedBeanPHP\R::setup($dsn, $db['user'], $db['pass']);

    // Check if tables exist
    $tables = \RedBeanPHP\R::getAll("SHOW TABLES LIKE 'aidev%'");
    echo "Existing aidev tables: " . count($tables) . "\n";

    // Create aidevjobs table
    \RedBeanPHP\R::exec("
        CREATE TABLE IF NOT EXISTS aidevjobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            issue_key VARCHAR(50) NOT NULL UNIQUE,
            board_id INT NOT NULL,
            repo_connection_id INT,
            cloud_id VARCHAR(100),
            status VARCHAR(30) DEFAULT 'pending',
            current_shard_job_id VARCHAR(64),
            branch_name VARCHAR(255),
            pr_url VARCHAR(500),
            pr_number INT,
            clarification_comment_id VARCHAR(50),
            clarification_questions TEXT,
            error_message TEXT,
            run_count INT DEFAULT 0,
            last_output LONGTEXT,
            last_result_json LONGTEXT,
            files_changed TEXT,
            commit_sha VARCHAR(64),
            started_at DATETIME,
            completed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_member (member_id),
            INDEX idx_board (board_id),
            INDEX idx_status (status),
            INDEX idx_issue (issue_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Created/verified aidevjobs table\n";

    // Create aidevjoblogs table
    \RedBeanPHP\R::exec("
        CREATE TABLE IF NOT EXISTS aidevjoblogs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            issue_key VARCHAR(50) NOT NULL,
            log_level VARCHAR(20) DEFAULT 'info',
            message TEXT NOT NULL,
            context_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_issue (issue_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Created/verified aidevjoblogs table\n";

    // Verify
    $tables = \RedBeanPHP\R::getAll("SHOW TABLES LIKE 'aidev%'");
    echo "Final aidev table count: " . count($tables) . "\n";
    foreach ($tables as $t) {
        echo "  - " . array_values($t)[0] . "\n";
    }

} catch (Exception $e) {
    die('Error: ' . $e->getMessage() . "\n");
}

echo "\nMigration complete!\n";
