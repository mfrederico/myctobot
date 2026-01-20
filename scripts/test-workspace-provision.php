#!/usr/bin/env php
<?php
/**
 * Test script for workspace provisioning
 *
 * Usage: php scripts/test-workspace-provision.php [subdomain]
 *        php scripts/test-workspace-provision.php --cleanup [subdomain]
 *
 * Tests the WorkspaceProvisioner and WorkspaceSchemaBuilder without modifying production.
 */

$baseDir = dirname(__DIR__);
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';

use app\services\WorkspaceProvisioner;
use \Flight as Flight;

// Parse arguments
$subdomain = $argv[1] ?? 'testprovision';
$cleanup = false;

if ($subdomain === '--cleanup') {
    $cleanup = true;
    $subdomain = $argv[2] ?? 'testprovision';
}

echo "=== Workspace Provisioning Test ===\n";
echo "Subdomain: {$subdomain}\n\n";

// Initialize bootstrap with gwt config (has valid DB credentials)
$configFile = $baseDir . '/conf/config.gwt.ini';
if (!file_exists($configFile)) {
    echo "Error: config.gwt.ini not found. This script requires valid DB credentials.\n";
    exit(1);
}
$bootstrap = new \app\Bootstrap($configFile);

// Get admin database credentials from config
// Note: Bootstrap stores config as Flight::set('section.key', value)
$dbHost = Flight::get('database.host') ?? 'localhost';
$dbUser = Flight::get('database.user') ?? 'myctobot';
$dbPass = Flight::get('database.pass') ?? '';

// For provisioning, we need root/admin level access
// Check if root credentials are available
$adminUser = getenv('MYSQL_ADMIN_USER') ?: $dbUser;
$adminPass = getenv('MYSQL_ADMIN_PASS') ?: $dbPass;

echo "Using admin user: {$adminUser}\n";
echo "Database host: {$dbHost}\n\n";

try {
    $provisioner = new WorkspaceProvisioner($dbHost, $adminUser, $adminPass);

    if ($cleanup) {
        // Cleanup mode - remove test workspace
        echo "Cleaning up test workspace...\n";

        $dbName = "myctobot_{$subdomain}";
        $dbUserName = "mctb_{$subdomain}";

        // Use reflection to access private cleanup method
        $reflection = new ReflectionClass($provisioner);
        $cleanupMethod = $reflection->getMethod('cleanup');
        $cleanupMethod->setAccessible(true);
        $cleanupMethod->invoke($provisioner, $dbName, $dbUserName);

        echo "Cleanup complete.\n";
        exit(0);
    }

    // Step 1: Validate subdomain
    echo "Step 1: Validating subdomain...\n";
    $validation = $provisioner->validateSubdomain($subdomain);
    if (!$validation['valid']) {
        echo "  ERROR: {$validation['error']}\n";
        exit(1);
    }
    echo "  OK: Subdomain is available\n\n";

    // Step 2: Provision workspace
    echo "Step 2: Provisioning workspace...\n";
    $result = $provisioner->provision(
        $subdomain,
        'Test Business Name',
        'test@example.com',
        'TestPassword123!'
    );

    if (!$result['success']) {
        echo "  ERROR: {$result['error']}\n";
        exit(1);
    }

    echo "  OK: Workspace provisioned successfully!\n";
    echo "\n=== Results ===\n";
    echo "Subdomain: {$result['subdomain']}\n";
    echo "Database: {$result['database']}\n";
    echo "DB User: {$result['db_user']}\n";
    echo "Config File: {$result['config_file']}\n";
    echo "URL: {$result['url']}\n";

    // Step 3: Verify database tables were created
    // Note: We need to read the generated config to get the actual DB password
    $configPath = $result['config_file'];
    $workspaceConfig = parse_ini_file($configPath, true);
    $workspaceDbPass = $workspaceConfig['database']['pass'] ?? '';

    echo "\nStep 3: Verifying database schema...\n";
    $dsn = "mysql:host={$dbHost};dbname={$result['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $result['db_user'], $workspaceDbPass);
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    $expectedTables = [
        'member', 'authcontrol', 'atlassiantoken', 'jiraboards',
        'repoconnections', 'aidevjobs', 'aidevjoblogs', 'analysisresults',
        'digesthistory', 'enterprisesettings', 'githubtokens',
        'boardrepomapping', 'anthropickeys', 'aiagents', 'directives',
        'projects', 'discoveredplugins', 'pluginscans', 'installedplugins'
    ];

    echo "  Tables created: " . count($tables) . "\n";

    $missing = array_diff($expectedTables, $tables);
    if (!empty($missing)) {
        echo "  WARNING: Missing tables: " . implode(', ', $missing) . "\n";
    } else {
        echo "  OK: All expected tables exist\n";
    }

    // Step 4: Verify _uid columns exist (not _id)
    echo "\nStep 4: Verifying _uid column naming...\n";
    $uidColumns = [
        'aidevjobs' => ['job_uid', 'cloud_uid', 'current_shard_job_uid', 'clarification_comment_uid'],
        'atlassiantoken' => ['cloud_uid'],
        'jiraboards' => ['cloud_uid'],
        'repoconnections' => ['webhook_uid'],
        'directives' => ['directive_uid', 'source_uid'],
        'projects' => ['project_uid'],
    ];

    $columnsOk = true;
    foreach ($uidColumns as $tableName => $columns) {
        // Use INFORMATION_SCHEMA with parameterized query for safety
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ");
        $stmt->execute([$result['database'], $tableName]);
        $tableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($columns as $col) {
            if (!in_array($col, $tableColumns)) {
                echo "  ERROR: Missing column {$tableName}.{$col}\n";
                $columnsOk = false;
            }
        }
    }

    if ($columnsOk) {
        echo "  OK: All _uid columns exist\n";
    }

    // Step 5: Verify admin user was created
    echo "\nStep 5: Verifying admin user...\n";
    $stmt = $pdo->prepare("SELECT id, email, level FROM member WHERE email = ?");
    $stmt->execute(['test@example.com']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($admin && $admin['level'] == 1) {
        echo "  OK: Admin user created (id={$admin['id']}, level=ROOT)\n";
    } else {
        echo "  ERROR: Admin user not found or wrong level\n";
    }

    echo "\n=== Test Complete ===\n";
    echo "To cleanup: php scripts/test-workspace-provision.php --cleanup {$subdomain}\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
