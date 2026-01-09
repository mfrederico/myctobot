<?php
/**
 * Bootstrap file - Initializes the application
 * This sets up all core components: autoloading, database, logging, sessions
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use \Monolog\Handler\RotatingFileHandler;
use \Monolog\Formatter\LineFormatter;

class Bootstrap {
    
    private $config;
    private $logger;
    private $cliHandler;
    
    public function __construct($configFile = 'conf/config.ini') {
        // Initialize autoloader first - this must come before any framework usage
        $this->initAutoloader();

        // Resolve tenant from subdomain and load tenant-specific config
        $configFile = $this->resolveConfigFile($configFile);

        // Now load configuration (after autoloader so Flight class is available)
        $this->loadConfig($configFile);
        
        // Check for CLI mode and handle it
        $this->initCLI();
        
        // Initialize remaining components in order
        $this->initLogging();
        $this->initDatabase();
        $this->initSession();
        $this->initTenantFromSession();  // Check for session-based tenancy after session starts
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

        $config['logLevel'] = $this->config['logging']['level'] ?? 'DEBUG';
        $config['logFile']  = $this->config['logging']['file'] ?? 'log/app.log';

        // Create log directory if it doesn't exist
        $logDir = dirname($config['logFile']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

		if (empty($config['log.name'])) $config['log.name'] = 'app';

		// Set up logging for legacy 
		Flight::register('log', 'Monolog\Logger', array($config['log.name']), function($log) use ($config) {
			// Create logger
			$log = new Logger($config['log.name']);
			
			// Create formatter for better readability
			$formatter = new LineFormatter(
				"[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
				"Y-m-d H:i:s",
				true,
				true
			);
        
			// Add rotating file handler (new file each day, keep 30 days)
			$handler = new RotatingFileHandler($config['logFile'], 30, constant("Monolog\Logger::{$config['logLevel']}"));
			$handler->setFormatter($formatter);
			$log->pushHandler($handler);

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
            
            if ($type === 'sqlite') {
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
                // Fallback to old implementation if new one doesn't exist
                elseif (file_exists(__DIR__ . '/lib/RedBeanQueryCache.php')) {
                    require_once __DIR__ . '/lib/RedBeanQueryCache.php';
                    \app\RedBeanQueryCache::init();
                    $this->logger->info('Query cache initialized (legacy)');
                }
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
     * Initialize tenant from session (session-based multi-tenancy)
     *
     * If user logged in with a workspace code, their session stores the tenant slug.
     * This method switches to that tenant's database for subsequent requests.
     */
    private function initTenantFromSession() {
        // Skip in CLI mode (no session)
        if (php_sapi_name() === 'cli') {
            return;
        }

        // Check for session tenant
        $tenantSlug = $_SESSION['tenant_slug'] ?? null;
        if (empty($tenantSlug) || $tenantSlug === 'default') {
            return;
        }

        // Load tenant config file
        $configFile = "conf/config.{$tenantSlug}.ini";
        if (!file_exists($configFile)) {
            $this->logger->warning("Tenant config not found: {$configFile}, clearing session tenant");
            unset($_SESSION['tenant_slug']);
            return;
        }

        $tenantConfig = parse_ini_file($configFile, true);
        if (!$tenantConfig || empty($tenantConfig['database'])) {
            $this->logger->warning("Invalid tenant config: {$configFile}");
            unset($_SESSION['tenant_slug']);
            return;
        }

        // Override current config with tenant config (keep any non-overlapping settings)
        foreach ($tenantConfig as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    Flight::set("{$section}.{$key}", $value);
                }
            }
        }

        // Add and switch to tenant database
        try {
            $dbConfig = $tenantConfig['database'];
            $type = $dbConfig['type'] ?? 'mysql';

            if ($type === 'sqlite') {
                $dbPath = $dbConfig['path'] ?? "database/{$tenantSlug}.sqlite";
                $dsn = "sqlite:{$dbPath}";
                R::addDatabase($tenantSlug, $dsn);
            } else {
                $host = $dbConfig['host'] ?? 'localhost';
                $port = $dbConfig['port'] ?? 3306;
                $name = $dbConfig['name'] ?? $tenantSlug;
                $user = $dbConfig['user'] ?? 'root';
                $pass = $dbConfig['pass'] ?? '';
                $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
                R::addDatabase($tenantSlug, $dsn, $user, $pass);
            }

            R::selectDatabase($tenantSlug);
            Flight::set('tenant.slug', $tenantSlug);
            Flight::set('tenant.active', true);
            $this->logger->info("Switched to tenant database: {$tenantSlug}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to switch to tenant database: " . $e->getMessage());
            unset($_SESSION['tenant_slug']);
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

        // Extract subdomain (first part if 3+ parts)
        // clicksimple-inc.myctobot.ai → clicksimple-inc
        // myctobot.ai → null (no subdomain)
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];

            // Redirect subdomain to main domain with workspace in path
            // clicksimple-inc.myctobot.ai/login → myctobot.ai/login/clicksimple-inc
            // clicksimple-inc.myctobot.ai/settings/connections → myctobot.ai/settings/connections?workspace=clicksimple-inc
            $mainDomain = implode('.', array_slice($parts, 1)); // myctobot.ai
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

            // For /login, append workspace to path: /login/workspace
            // For other paths, add workspace as query param
            if (preg_match('#^/login/?$#', $requestUri)) {
                $redirectUrl = "{$scheme}://{$mainDomain}/login/{$subdomain}";
            } else {
                // Preserve existing query string and add workspace
                $separator = (strpos($requestUri, '?') !== false) ? '&' : '?';
                $redirectUrl = "{$scheme}://{$mainDomain}{$requestUri}{$separator}workspace={$subdomain}";
            }

            header("Location: {$redirectUrl}", true, 302);
            exit;
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
