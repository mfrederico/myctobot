<?php
/**
 * TenantProvisioner - Creates new tenant databases and config files
 *
 * Handles full tenant isolation:
 * - Separate database per tenant
 * - Separate database user per tenant
 * - Separate config file per tenant
 */

namespace app\services;

use \Flight;
use \PDO;

class TenantProvisioner {

    private PDO $adminDb;
    private string $schemaPath;
    private string $configDir;
    private array $reservedSubdomains = [
        'www', 'api', 'admin', 'app', 'mail', 'smtp', 'pop', 'imap',
        'ftp', 'ssh', 'vpn', 'cdn', 'static', 'assets', 'media',
        'blog', 'help', 'support', 'docs', 'status', 'billing',
        'account', 'accounts', 'login', 'signup', 'register',
        'dashboard', 'portal', 'console', 'manage', 'dev', 'test',
        'stage', 'staging', 'prod', 'production', 'demo', 'sandbox',
        'gwt', 'internal', 'private', 'public', 'default'
    ];

    /**
     * Create provisioner with admin database connection
     *
     * @param string $adminHost MySQL host
     * @param string $adminUser MySQL user with CREATE DATABASE/USER privileges
     * @param string $adminPass MySQL password
     */
    public function __construct(string $adminHost, string $adminUser, string $adminPass) {
        $dsn = "mysql:host={$adminHost};charset=utf8mb4";
        $this->adminDb = new PDO($dsn, $adminUser, $adminPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $this->schemaPath = dirname(__DIR__) . '/sql/mysql_schema.sql';
        $this->configDir = dirname(__DIR__) . '/conf';
    }

    /**
     * Validate a subdomain
     *
     * @param string $subdomain Proposed subdomain
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateSubdomain(string $subdomain): array {
        $subdomain = strtolower(trim($subdomain));

        // Length check
        if (strlen($subdomain) < 3) {
            return ['valid' => false, 'error' => 'Subdomain must be at least 3 characters'];
        }
        if (strlen($subdomain) > 32) {
            return ['valid' => false, 'error' => 'Subdomain must be 32 characters or less'];
        }

        // Character check (alphanumeric and hyphens, no leading/trailing hyphens)
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $subdomain)) {
            return ['valid' => false, 'error' => 'Subdomain can only contain letters, numbers, and hyphens'];
        }

        // Reserved check
        if (in_array($subdomain, $this->reservedSubdomains)) {
            return ['valid' => false, 'error' => 'This subdomain is reserved'];
        }

        // Check if config already exists
        $configPath = "{$this->configDir}/config.{$subdomain}.ini";
        if (file_exists($configPath)) {
            return ['valid' => false, 'error' => 'This subdomain is already taken'];
        }

        // Check if database already exists
        $stmt = $this->adminDb->query("SHOW DATABASES LIKE 'myctobot_{$subdomain}'");
        if ($stmt->fetch()) {
            return ['valid' => false, 'error' => 'This subdomain is already taken'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Provision a new tenant
     *
     * @param string $subdomain Tenant subdomain (e.g., 'acme')
     * @param string $businessName Business/company name
     * @param string $adminEmail Admin user email
     * @param string $adminPassword Admin user password (plain text, will be hashed)
     * @return array Result with keys: success, error, subdomain, database, config_file
     */
    public function provision(
        string $subdomain,
        string $businessName,
        string $adminEmail,
        string $adminPassword
    ): array {
        $subdomain = strtolower(trim($subdomain));

        // Validate subdomain
        $validation = $this->validateSubdomain($subdomain);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        // Generate credentials
        $dbName = "myctobot_{$subdomain}";
        $dbUser = "mctb_{$subdomain}";
        $dbPass = $this->generatePassword(24);

        try {
            // Step 1: Create database
            $this->adminDb->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Step 2: Create user
            $this->adminDb->exec("CREATE USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'");

            // Step 3: Grant privileges (only on their database)
            $this->adminDb->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'");
            $this->adminDb->exec("FLUSH PRIVILEGES");

            // Step 4: Run schema
            $this->runSchema($dbName, $dbUser, $dbPass);

            // Step 5: Create config file
            $configPath = $this->createConfigFile($subdomain, $dbName, $dbUser, $dbPass, $businessName);

            // Step 6: Create admin user in tenant database
            $this->createAdminUser($dbName, $dbUser, $dbPass, $adminEmail, $adminPassword, $businessName);

            return [
                'success' => true,
                'subdomain' => $subdomain,
                'database' => $dbName,
                'db_user' => $dbUser,
                'config_file' => $configPath,
                'url' => "https://{$subdomain}.myctobot.ai"
            ];

        } catch (\Exception $e) {
            // Cleanup on failure
            $this->cleanup($dbName, $dbUser);

            return [
                'success' => false,
                'error' => 'Provisioning failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Run the schema on the new database
     */
    private function runSchema(string $dbName, string $dbUser, string $dbPass): void {
        if (!file_exists($this->schemaPath)) {
            throw new \Exception("Schema file not found: {$this->schemaPath}");
        }

        // Connect as the new user to their database
        $dsn = "mysql:host=localhost;dbname={$dbName};charset=utf8mb4";
        $db = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Read schema
        $schema = file_get_contents($this->schemaPath);

        // Remove CREATE DATABASE and USE statements (we already created the DB)
        $schema = preg_replace('/CREATE\s+DATABASE[^;]+;/i', '', $schema);
        $schema = preg_replace('/USE\s+[^;]+;/i', '', $schema);

        // Remove single-line comments
        $schema = preg_replace('/--.*$/m', '', $schema);

        // Split by semicolons
        $statements = explode(';', $schema);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            // Skip empty statements
            if (empty($statement)) {
                continue;
            }
            // Must start with a SQL keyword
            if (!preg_match('/^(CREATE|INSERT|ALTER|DROP|UPDATE|DELETE|SET)/i', $statement)) {
                continue;
            }
            try {
                $db->exec($statement);
            } catch (\PDOException $e) {
                // Ignore "already exists" errors
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Create the tenant config file
     */
    private function createConfigFile(
        string $subdomain,
        string $dbName,
        string $dbUser,
        string $dbPass,
        string $businessName
    ): string {
        $configPath = "{$this->configDir}/config.{$subdomain}.ini";

        // Generate a unique encryption key for this tenant
        $encryptionKey = bin2hex(random_bytes(32));

        $config = <<<INI
; MyCTOBot - Tenant Configuration
; Tenant: {$businessName}
; Subdomain: {$subdomain}.myctobot.ai
; Generated: {$this->now()}

[app]
name = "{$businessName}"
environment = "production"
debug = false
build_mode = false
session_name = "MCTB_{$subdomain}"
session_lifetime = 2592000
baseurl = "https://{$subdomain}.myctobot.ai"
timezone = "America/New_York"

[database]
type = "mysql"
host = "localhost"
port = 3306
name = "{$dbName}"
user = "{$dbUser}"
pass = "{$dbPass}"
charset = "utf8mb4"

[logging]
level = "INFO"
file = "log/{$subdomain}.log"
max_files = 30

[mail]
enabled = false
driver = "mailgun"
from_email = "noreply@myctobot.ai"
from_name = "{$businessName}"

[cors]
enabled = true
origin = "*"
methods = "GET, POST, PUT, DELETE, OPTIONS"
headers = "Content-Type, Authorization, X-Requested-With"
credentials = false

[security]
csrf_enabled = false
csrf_token_name = "csrf_token"
password_min_length = 8
max_login_attempts = 5
lockout_duration = 900

[cache]
enabled = true
driver = "file"
ttl = 3600
path = "cache/{$subdomain}/"
query_cache = true
query_cache_ttl = 60

[api]
enabled = true
rate_limit = true
rate_limit_requests = 100
rate_limit_window = 3600
require_auth = true

[encryption]
master_key = "{$encryptionKey}"

[features]
registration_enabled = false
email_verification = false
two_factor_auth = false
remember_me = true
social_login = false
api_access = true

[maintenance]
enabled = false
message = "We are currently performing maintenance. Please check back soon."
allowed_ips = "127.0.0.1"

[atlassian]
; Tenant will configure their own Atlassian OAuth
client_id = ""
client_secret = ""
redirect_uri = "https://{$subdomain}.myctobot.ai/atlassian/callback"
scopes = "read:jira-work read:jira-user read:board-scope:jira-software read:sprint:jira-software read:issue:jira-software read:project:jira write:jira-work manage:jira-webhook offline_access"

[github]
; Tenant will configure their own GitHub OAuth
client_id = ""
client_secret = ""
redirect_uri = "https://{$subdomain}.myctobot.ai/enterprise/githubcallback"

[stripe]
; Handled at platform level
enabled = false

[aidev]
use_local_runner = false
INI;

        file_put_contents($configPath, $config);
        chmod($configPath, 0640);

        return $configPath;
    }

    /**
     * Create the first admin user in the tenant database
     */
    private function createAdminUser(
        string $dbName,
        string $dbUser,
        string $dbPass,
        string $email,
        string $password,
        string $businessName
    ): void {
        $dsn = "mysql:host=localhost;dbname={$dbName};charset=utf8mb4";
        $db = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $now = $this->now();

        $stmt = $db->prepare("
            INSERT INTO member (email, password, level, status, created_at, updated_at)
            VALUES (?, ?, ?, 'active', ?, ?)
        ");

        $stmt->execute([
            $email,
            $hashedPassword,
            1, // ROOT level
            $now,
            $now
        ]);

        // Also create default auth permissions
        $memberId = $db->lastInsertId();

        // Insert default permissions for the tenant (IGNORE duplicates if schema already has them)
        $db->exec("
            INSERT IGNORE INTO authcontrol (control, method, level) VALUES
            ('index', 'index', 101),
            ('auth', 'login', 101),
            ('auth', 'logout', 100),
            ('auth', 'register', 101),
            ('dashboard', 'index', 100),
            ('admin', 'index', 50),
            ('settings', 'index', 100),
            ('boards', 'index', 100),
            ('enterprise', 'index', 100)
        ");
    }

    /**
     * Cleanup on failure
     */
    private function cleanup(string $dbName, string $dbUser): void {
        try {
            $this->adminDb->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        } catch (\Exception $e) {
            // Ignore
        }

        try {
            $this->adminDb->exec("DROP USER IF EXISTS '{$dbUser}'@'localhost'");
        } catch (\Exception $e) {
            // Ignore
        }

        $configPath = "{$this->configDir}/config." . str_replace('myctobot_', '', $dbName) . ".ini";
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    }

    /**
     * Generate a secure random password
     */
    private function generatePassword(int $length = 24): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Get current timestamp
     */
    private function now(): string {
        return date('Y-m-d H:i:s');
    }

    /**
     * List all provisioned tenants
     */
    public function listTenants(): array {
        $tenants = [];
        $configs = glob("{$this->configDir}/config.*.ini");

        foreach ($configs as $configPath) {
            $filename = basename($configPath);
            if (preg_match('/^config\.([a-z0-9-]+)\.ini$/', $filename, $matches)) {
                $slug = $matches[1];
                if ($slug !== 'example' && $slug !== 'sqlite') {
                    $tenants[] = [
                        'slug' => $slug,
                        'config' => $configPath,
                        'url' => "https://{$slug}.myctobot.ai"
                    ];
                }
            }
        }

        return $tenants;
    }
}
