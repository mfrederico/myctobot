<?php
/**
 * Bootstrap file - Initializes the application
 * This sets up all core components: autoloading, database, logging, sessions
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Monolog\Logger;

use \app\Bean;
use \Monolog\Handler\StreamHandler;
use \Monolog\Handler\RotatingFileHandler;
use \Monolog\Formatter\LineFormatter;

class Bootstrap {
    
    private $config;
    private $configFile;
    private $logger;
    private $cliHandler;
    
    public function __construct($configFile = 'conf/config.ini') {
        // Initialize autoloader first - this must come before any framework usage
        $this->initAutoloader();

        // Resolve workspace from subdomain and load workspace-specific config
        $this->configFile = $this->resolveConfigFile($configFile);

        // Now load configuration (after autoloader so Flight class is available)
        $this->loadConfig($this->configFile);
        
        // Check for CLI mode and handle it
        $this->initCLI();
        
        // Initialize remaining components in order
        $this->initLogging();
        $this->initDatabase();
        $this->initSession();
        $this->initWorkspaceFromSession();  // Check for session-based tenancy after session starts
        $this->initFlight();
        $this->initCORS();
        
        // Set up CLI member context if in CLI mode
        if ($this->cliHandler && \app\CliHandler::isCli()) {
            $this->cliHandler->setupMember();
        }
    }
    
    /**
     * Load configuration from INI file
     */
    private function loadConfig($configFile) {
        if (!file_exists($configFile)) {
            die("Configuration file not found: {$configFile}");
        }
        
        $this->config = parse_ini_file($configFile, true);

        // Set configuration in Flight
        foreach ($this->config as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    Flight::set("{$section}.{$key}", $value);
                }
            } else {
                Flight::set($section, $values);
            }
        }

        // Set PHP timezone from config
        $timezone = $this->config['app']['timezone'] ?? $this->config['timezone'] ?? 'UTC';
        date_default_timezone_set($timezone);
    }
    
    /**
     * Initialize Composer autoloader
     */
    private function initAutoloader() {
        $vendorPath = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($vendorPath)) {
            die("Vendor autoload not found. Please run: composer install");
        }
        require_once $vendorPath;
    }
    
    /**
     * Initialize CLI handler if running from command line
     */
    private function initCLI() {
        // Load CLI handler class
        require_once __DIR__ . '/lib/CliHandler.php';
        
        if (\app\CliHandler::isCli()) {
            $this->cliHandler = new \app\CliHandler();
            $this->cliHandler->process();
        }
    }
    
    /**
     * Initialize Monolog logging
     */
    private function initLogging() {
		$config = $this->config;

        // Determine workspace slug: $_SERVER['WORKSPACE'] (set by front controller) or config file name
        $workspaceSlug = $_SERVER['WORKSPACE'] ?? null;
        if (!$workspaceSlug && preg_match('/config\.([a-z0-9_]+)\.ini$/', $this->configFile, $m)) {
            $workspaceSlug = $m[1];
        }

        // Default config (conf/config.ini) uses "default" as workspace
        if (!$workspaceSlug && basename($this->configFile) === 'config.ini') {
            $workspaceSlug = 'default';
        }

        if (!$workspaceSlug) {
            throw new \RuntimeException('Workspace not determined from config file: ' . $this->configFile);
        }

        $config['logLevel'] = $this->config['logging']['level'] ?? 'DEBUG';

        // Use workspace slug in log filename (e.g., log/gwt.log -> gwt-2026-01-28.log)
        $defaultLogFile = "log/{$workspaceSlug}.log";
        $config['logFile'] = $this->config['logging']['file'] ?? $defaultLogFile;

        // If config specifies 'app.log', replace 'app' with workspace slug
        if (strpos($config['logFile'], 'app.log') !== false) {
            $config['logFile'] = str_replace('app.log', "{$workspaceSlug}.log", $config['logFile']);
        }

        // Create log directory if it doesn't exist
        $logDir = dirname($config['logFile']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

		if (empty($config['log.name'])) $config['log.name'] = $workspaceSlug;

        // Store workspace globally so it's accessible throughout the app (CLI and web)
        Flight::set('workspace', $workspaceSlug);

		// Set up logging for legacy
		Flight::register('log', 'Monolog\Logger', array($config['log.name']), function($log) use ($config) {
			// Create logger
			$log = new Logger($config['log.name']);

			// Create formatter with workspace ID prefix for better readability
			$formatter = new LineFormatter(
				"[%datetime%] [%extra.workspace%] %channel%.%level_name%: %message% %context%\n",
				"Y-m-d H:i:s",
				true,
				true
			);

			// Add rotating file handler (new file each day, keep 30 days)
			$handler = new RotatingFileHandler($config['logFile'], 30, constant("Monolog\Logger::{$config['logLevel']}"));
			$handler->setFormatter($formatter);
			$log->pushHandler($handler);

			// Add processor to include workspace ID in every log entry
			$log->pushProcessor(function ($record) {
				$workspace = Flight::get('workspace') ?? $_SESSION['workspace_slug'] ?? null;
				if (!$workspace) {
					throw new \RuntimeException('Workspace not configured - cannot find conf/config.{workspace}.ini');
				}
				$record['extra']['workspace'] = $workspace;
				return $record;
			});

			// Sets up the cached flight logger
			Flight::set('log',$log);
		});

		$this->logger = Flight::log();
    }
    
    /**
     * Initialize RedBeanPHP database connection
     */
    private function initDatabase() {
        $dbConfig = $this->config['database'];
        
        if (empty($dbConfig)) {
            $this->logger->error('Database configuration missing');
            die('Database configuration missing');
        }
        
        try {
            // Construct DSN based on database type
            $type = $dbConfig['type'] ?? 'mysql';

            // Check if default database already exists (prevents error on Bootstrap re-init)
            if (Bean::hasDatabase('default')) {
                // Already initialized, just select it
                Bean::selectDatabase('default');
            } elseif ($type === 'sqlite') {
                // SQLite configuration
                $dbPath = $dbConfig['path'] ?? 'database/tiknix.db';
                // Create database directory if it doesn't exist
                $dbDir = dirname($dbPath);
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                $dsn = "sqlite:{$dbPath}";
                // Setup RedBean for SQLite (no user/pass needed)
                R::setup($dsn);
            } else {
                // MySQL/PostgreSQL configuration
                $host = $dbConfig['host'] ?? 'localhost';
                $port = $dbConfig['port'] ?? 3306;
                $name = $dbConfig['name'] ?? 'app';
                $user = $dbConfig['user'] ?? 'root';
                $pass = $dbConfig['pass'] ?? '';

                $dsn = "{$type}:host={$host};port={$port};dbname={$name}";

                // Setup RedBean
                R::setup($dsn, $user, $pass);
            }
            
            // Keep fluid mode on - RedBean auto-manages schema
            // No significant performance hit and simplifies migrations
            R::freeze(false);

            // Load RedBean FUSE models
            // FUSE models automatically switch to correct database in open()/update() hooks
            require_once __DIR__ . '/models/Model_Member.php';
            require_once __DIR__ . '/models/Model_Subscription.php';
            require_once __DIR__ . '/models/Model_Jiraboards.php';
            
            // Enable query logging in debug mode
            if ($this->config['app']['debug'] ?? false) {
                R::debug(true, 1);
            }

            // Initialize Query Cache with CachedDatabaseAdapter
            if ($this->config['cache']['query_cache'] ?? false) {
                // Use the new transparent CachedDatabaseAdapter
                if (file_exists(__DIR__ . '/lib/CachedDatabaseAdapter.php')) {
                    require_once __DIR__ . '/lib/CachedDatabaseAdapter.php';
                    $cachedAdapter = new \app\CachedDatabaseAdapter(R::getDatabaseAdapter()->getDatabase());
                    $toolbox = new \RedBeanPHP\ToolBox(R::getRedBean(), $cachedAdapter, R::getWriter());
                    R::configureFacadeWithToolbox($toolbox);
                    // Store adapter in Flight so it survives R::selectDatabase() calls
                    Flight::set('cachedDatabaseAdapter', $cachedAdapter);
                    $this->logger->info('CachedDatabaseAdapter initialized - all queries cached automatically');
                }
                // Note: Bean class also provides caching via Bean::findCached(), Bean::countCached()
                // which uses APCu directly without requiring CachedDatabaseAdapter
            }

            $this->logger->info('Database connected');
            
        } catch (\Exception $e) {
            $this->logger->error('Database connection failed: ' . $e->getMessage());
            die('Database connection failed: '.$e->getMessage());
        }
    }
    
    /**
     * Initialize PHP session with security settings
     * Skips session initialization in CLI mode to avoid warnings
     */
    private function initSession() {
        // Skip session initialization in CLI mode
        if (php_sapi_name() === 'cli') {
            $this->logger->debug('Skipping session initialization in CLI mode');
            return;
        }

        // Session configuration for security
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');

        // Use HTTPS for cookies in production
        if ($this->config['app']['environment'] === 'production') {
            ini_set('session.cookie_secure', 1);
        }

        // Set session name
        session_name($this->config['app']['session_name'] ?? 'APP_SESSION');

        // Set session lifetime (default 24 hours)
        $lifetime = $this->config['app']['session_lifetime'] ?? 86400;
        ini_set('session.gc_maxlifetime', $lifetime);
        session_set_cookie_params($lifetime);

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            $this->logger->debug('Session started', ['id' => session_id()]);
        }
    }

    /**
     * Initialize workspace from session (session-based multi-workspace)
     *
     * If user logged in with a workspace code, their session stores the workspace slug.
     * This method switches to that workspace's database for subsequent requests.
     */
    private function initWorkspaceFromSession() {
        // Skip in CLI mode (no session)
        if (php_sapi_name() === 'cli') {
            return;
        }

        // Check for session workspace
        $workspaceSlug = $_SESSION['workspace_slug'] ?? null;
        if (empty($workspaceSlug) || $workspaceSlug === 'default') {
            return;
        }

        // Load workspace config file
        $configFile = "conf/config.{$workspaceSlug}.ini";
        if (!file_exists($configFile)) {
            $this->logger->warning("Workspace config not found: {$configFile}, clearing session workspace");
            unset($_SESSION['workspace_slug']);
            return;
        }

        $workspaceConfig = parse_ini_file($configFile, true);
        if (!$workspaceConfig || empty($workspaceConfig['database'])) {
            $this->logger->warning("Invalid workspace config: {$configFile}");
            unset($_SESSION['workspace_slug']);
            return;
        }

        // Override current config with workspace config (keep any non-overlapping settings)
        foreach ($workspaceConfig as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    Flight::set("{$section}.{$key}", $value);
                }
            }
        }

        // Add and switch to workspace database
        try {
            $dbConfig = $workspaceConfig['database'];
            $type = $dbConfig['type'] ?? 'mysql';

            // Add (if not already registered) and select workspace database
            if ($type === 'sqlite') {
                $dbPath = $dbConfig['path'] ?? "database/{$workspaceSlug}.sqlite";
                $dsn = "sqlite:{$dbPath}";
                Bean::useDatabase($workspaceSlug, $dsn);
            } else {
                $host = $dbConfig['host'] ?? 'localhost';
                $port = $dbConfig['port'] ?? 3306;
                $name = $dbConfig['name'] ?? $workspaceSlug;
                $user = $dbConfig['user'] ?? 'root';
                $pass = $dbConfig['pass'] ?? '';

                // Check if database exists before connecting
                if (!$this->databaseExists($host, $port, $user, $pass, $name)) {
                    $this->logger->error("Workspace database '{$name}' does not exist");
                    // Clear session and redirect to home
                    unset($_SESSION['workspace_slug']);
                    header('Location: /');
                    exit;
                }

                $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
                Bean::useDatabase($workspaceSlug, $dsn, $user, $pass);
            }
            Flight::set('workspace.slug', $workspaceSlug);
            Flight::set('workspace.active', true);
            $this->logger->info("Switched to workspace database: {$workspaceSlug}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to switch to workspace database: " . $e->getMessage());
            unset($_SESSION['workspace_slug']);
        }
    }

    /**
     * Check if a MySQL database exists
     */
    private function databaseExists(string $host, int $port, string $user, string $pass, string $dbName): bool {
        try {
            $pdo = new \PDO("mysql:host={$host};port={$port}", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]);
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$dbName]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            $this->logger->error("Database existence check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize Flight framework settings
     */
    private function initFlight() {
        // Set Flight configuration
        Flight::set('flight.views.path', __DIR__ . '/views');
        Flight::set('flight.log_errors', true);
        Flight::set('baseurl', $this->config['app']['baseurl'] ?? '/');
        Flight::set('debug', $this->config['app']['debug'] ?? false);
        Flight::set('build', $this->config['app']['build_mode'] ?? false);

        // AI Dev settings - use JobExecutorConfig for consistency
        Flight::set('job_executor_url', \app\services\JobExecutorConfig::getUrl());

        // Load FlightMap extensions
        require_once __DIR__ . '/lib/FlightMap.php';
        
        // Load utility functions if exists
        if (file_exists(__DIR__ . '/lib/functions.php')) {
            require_once __DIR__ . '/lib/functions.php';
        }
        
        $this->logger->info('Flight framework initialized');
    }
    
    /**
     * Initialize CORS headers for API access
     */
    private function initCORS() {
        // Skip CORS in CLI mode
        if (php_sapi_name() === 'cli') {
            return;
        }

        // Only set CORS headers if configured
        if ($this->config['cors']['enabled'] ?? false) {
            $origin = $this->config['cors']['origin'] ?? '*';
            $methods = $this->config['cors']['methods'] ?? 'GET, POST, PUT, DELETE, OPTIONS';
            $headers = $this->config['cors']['headers'] ?? 'Content-Type, Authorization';

            header("Access-Control-Allow-Origin: {$origin}");
            header("Access-Control-Allow-Methods: {$methods}");
            header("Access-Control-Allow-Headers: {$headers}");

            // Handle preflight requests
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                http_response_code(200);
                exit();
            }
        }
    }
    
    /**
     * Get logger instance
     */
    public function getLogger() {
        return $this->logger;
    }
    
    /**
     * Resolve config file based on subdomain
     *
     * greenworks.myctobot.ai → conf/config.greenworks.ini
     * myctobot.ai → conf/config.ini (default)
     * localhost → conf/config.ini (default)
     *
     * @param string $defaultConfigFile Fallback config path
     * @return string Config file to use
     */
    private function resolveConfigFile(string $defaultConfigFile): string {
        // Skip in CLI mode
        if (php_sapi_name() === 'cli') {
            return $defaultConfigFile;
        }

        // Get host without port
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = explode(':', $host)[0];
        $host = strtolower($host);

        // Skip localhost and IP addresses - use default config
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $defaultConfigFile;
        }

        // Determine workspace from subdomain or query parameter
        // Both methods are interchangeable and work the same way
        $workspace = null;

        // Check for subdomain (first part if 3+ parts)
        // gwt.myctobot.ai → gwt
        // myctobot.ai → null (no subdomain)
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $workspace = $parts[0];
        }

        // Query parameter ?workspace= overrides subdomain if both present
        if (isset($_GET['workspace']) && !empty($_GET['workspace'])) {
            $workspace = $_GET['workspace'];
        }

        // If we have a workspace, set it in $_REQUEST and load workspace config
        if (!empty($workspace)) {
            // Normalize workspace and make it available globally
            $_REQUEST['workspace'] = $workspace;
            $_GET['workspace'] = $workspace;

            // Check if workspace config exists
            $workspaceConfigFile = "conf/config.{$workspace}.ini";
            if (file_exists($workspaceConfigFile)) {
                // Set session workspace if not in CLI mode
                if (php_sapi_name() !== 'cli') {
                    $_SESSION['workspace_slug'] = $workspace;
                }
                return $workspaceConfigFile;
            }
        }

        // Fallback to default
        return $defaultConfigFile;
    }

    /**
     * Get configuration
     */
    public function getConfig($key = null) {
        if ($key === null) {
            return $this->config;
        }
        
        // Support dot notation
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /**
     * Run the application
     */
    public function run() {
        $this->logger->info('Starting Flight application');
        
        // Start Flight framework
        Flight::start();
    }
}
