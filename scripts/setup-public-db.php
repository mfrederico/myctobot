<?php
/**
 * Setup Public Database
 *
 * Creates the myctobot_public database, runs the schema, and initializes
 * default users and permissions.
 *
 * Usage:
 *   php scripts/setup-public-db.php [--mysql-user=root] [--mysql-pass=password]
 *
 * If credentials not provided, will prompt for them.
 */

define('BASE_PATH', dirname(__DIR__));
chdir(BASE_PATH);

// Parse command line arguments
$options = getopt('', ['mysql-user::', 'mysql-pass::', 'help']);

if (isset($options['help'])) {
    echo "Setup Public Database\n";
    echo "=====================\n\n";
    echo "Creates myctobot_public database and initializes it.\n\n";
    echo "Usage:\n";
    echo "  php scripts/setup-public-db.php [options]\n\n";
    echo "Options:\n";
    echo "  --mysql-user=USER   MySQL admin user (default: prompt)\n";
    echo "  --mysql-pass=PASS   MySQL admin password (default: prompt)\n";
    echo "  --help              Show this help\n\n";
    exit(0);
}

echo "===========================================\n";
echo "  MyCTOBot Public Database Setup\n";
echo "===========================================\n\n";

// Get MySQL admin credentials
$mysqlUser = $options['mysql-user'] ?? null;
$mysqlPass = $options['mysql-pass'] ?? null;

if (!$mysqlUser) {
    echo "MySQL admin username (needs CREATE DATABASE privilege): ";
    $mysqlUser = trim(fgets(STDIN));
}

if (!$mysqlPass) {
    echo "MySQL admin password: ";
    // Try to hide password input on Unix systems
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        system('stty -echo');
        $mysqlPass = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $mysqlPass = trim(fgets(STDIN));
    }
}

$dbName = 'myctobot_public';
$schemaFile = BASE_PATH . '/sql/mysql_schema.sql';

// Verify schema file exists
if (!file_exists($schemaFile)) {
    echo "ERROR: Schema file not found: {$schemaFile}\n";
    exit(1);
}

try {
    // Connect to MySQL
    echo "\n[1/4] Connecting to MySQL...\n";
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", $mysqlUser, $mysqlPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "      Connected successfully.\n";

    // Check if database already exists
    echo "\n[2/4] Creating database '{$dbName}'...\n";
    $stmt = $pdo->query("SHOW DATABASES LIKE '{$dbName}'");
    if ($stmt->fetch()) {
        echo "      Database already exists. Drop and recreate? (y/N): ";
        $answer = strtolower(trim(fgets(STDIN)));
        if ($answer === 'y' || $answer === 'yes') {
            $pdo->exec("DROP DATABASE `{$dbName}`");
            echo "      Dropped existing database.\n";
        } else {
            echo "      Keeping existing database.\n";
        }
    }

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "      Database created.\n";

    // Grant privileges to myctobot user
    echo "\n[3/4] Running schema...\n";

    // Read and execute schema
    $pdo->exec("USE `{$dbName}`");

    $schema = file_get_contents($schemaFile);

    // Remove the CREATE DATABASE and USE statements from schema (we already did that)
    $schema = preg_replace('/CREATE DATABASE.*?;/i', '', $schema);
    $schema = preg_replace('/USE\s+\w+;/i', '', $schema);

    // Split by semicolons and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($s) => !empty($s) && !preg_match('/^--/', trim($s))
    );

    $count = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $pdo->exec($statement);
                $count++;
            } catch (PDOException $e) {
                // Ignore "already exists" errors
                if (strpos($e->getMessage(), 'already exists') === false) {
                    echo "      Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    echo "      Executed {$count} statements.\n";

    // Grant privileges to the app user
    echo "\n[4/4] Granting privileges to 'myctobot' user...\n";
    try {
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO 'myctobot'@'localhost'");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO 'myctobot'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");
        echo "      Privileges granted.\n";
    } catch (PDOException $e) {
        echo "      Warning: Could not grant privileges: " . $e->getMessage() . "\n";
        echo "      You may need to grant manually.\n";
    }

    echo "\n-------------------------------------------\n";
    echo "Database setup complete!\n";
    echo "-------------------------------------------\n\n";

    // Now run init_users.php
    echo "Running init_users.php to create permissions...\n\n";

    // We need to make sure the config points to the new database
    // Check if config.ini points to myctobot_public
    $configFile = BASE_PATH . '/conf/config.ini';
    $config = parse_ini_file($configFile, true);

    if (($config['database']['name'] ?? '') !== $dbName) {
        echo "NOTE: conf/config.ini points to '{$config['database']['name']}', not '{$dbName}'.\n";
        echo "      Make sure to update [database] name = \"{$dbName}\" if this is your public site config.\n\n";
    }

    // Run init_users.php
    include BASE_PATH . '/database/init_users.php';

    echo "\n===========================================\n";
    echo "  Setup Complete!\n";
    echo "===========================================\n\n";
    echo "Next steps:\n";
    echo "1. Verify conf/config.ini has [database] name = \"{$dbName}\"\n";
    echo "2. Create a provisioner MySQL user with elevated privileges:\n";
    echo "   GRANT ALL PRIVILEGES ON *.* TO 'provisioner'@'localhost' WITH GRANT OPTION;\n";
    echo "3. Update [provisioner] section in conf/config.ini\n";
    echo "4. Set up wildcard DNS: *.myctobot.ai -> your server IP\n";
    echo "5. Test signup at https://myctobot.ai/signup\n\n";

} catch (PDOException $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
}
