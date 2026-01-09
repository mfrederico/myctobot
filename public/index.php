<?php
/**
 * TikNix Framework - Entry Point
 * This is the main entry point for all web requests and CLI commands
 */

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Change to base directory
chdir(BASE_PATH);

// Check if we're running from CLI
if (php_sapi_name() === 'cli' && $argc > 1) {
    // Check if asking for help
    if (in_array('--help', $argv) || in_array('-h', $argv)) {
        echo "TikNix CLI Interface\n";
        echo "====================\n\n";
        echo "Usage: php index.php [config] [options]\n\n";
        echo "Options:\n";
        echo "  --help, -h         Show this help message\n";
        echo "  --control=NAME     Controller name (required)\n";
        echo "  --method=NAME      Method name (default: index)\n";
        echo "  --member=ID        Member ID to run as (default: public-user-entity)\n";
        echo "  --params=STRING    URL-encoded parameters (e.g., 'param1=value1&param2=value2')\n";
        echo "  --json=JSON        JSON parameters (e.g., '{\"key\":\"value\"}')\n";
        echo "  --cron             Cron mode (suppress output)\n";
        echo "  --verbose          Verbose output\n\n";
        echo "Examples:\n";
        echo "  # Run a simple controller method\n";
        echo "  php index.php --control=test --method=hello\n\n";
        echo "  # Run with parameters\n";
        echo "  php index.php --control=report --method=generate --params='type=daily&format=pdf'\n\n";
        echo "  # Run as specific member with JSON data\n";
        echo "  php index.php --member=1 --control=api --method=process --json='{\"action\":\"sync\"}'\n\n";
        echo "  # Run in cron mode (silent)\n";
        echo "  php index.php --control=cleanup --method=daily --cron\n\n";
        echo "  # Create a cron job\n";
        echo "  0 2 * * * /usr/bin/php /path/to/index.php --control=cleanup --method=daily --cron\n";
        exit(0);
    }
    
    // Determine config file from first arg if it's not an option
    $configFile = 'conf/config.ini';
    if (!empty($argv[1]) && strpos($argv[1], '--') !== 0 && file_exists($argv[1])) {
        $configFile = $argv[1];
    }
} else {
    // Web mode - determine config file normally
    $configFile = 'conf/config.ini';
}

// Load bootstrap
require_once BASE_PATH . '/bootstrap.php';

// Check config file exists
if (!file_exists($configFile)) {
    if (php_sapi_name() === 'cli') {
        echo "Error: Configuration file not found: {$configFile}\n";
        echo "Please create conf/config.ini from conf/config.example.ini\n";
        exit(1);
    } else {
        // Show setup message for web
        die('
            <h1>Welcome to TikNix Framework!</h1>
            <p>Please copy <code>conf/config.example.ini</code> to <code>conf/config.ini</code> and update with your settings.</p>
            <p>Then run: <code>php database/init_users.php</code></p>
        ');
    }
}

// Initialize application with CLI arguments if available
$app = new app\Bootstrap($configFile);

// Check maintenance mode (skip for CLI)
if (php_sapi_name() !== 'cli') {
    $maintenanceEnabled = \Flight::get('maintenance.enabled');
    if ($maintenanceEnabled && strtolower($maintenanceEnabled) !== 'false' && $maintenanceEnabled !== '0') {
        // Check if IP is allowed
        $allowedIps = \Flight::get('maintenance.allowed_ips') ?? '';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $allowedList = array_map('trim', explode(',', $allowedIps));

        if (!in_array($clientIp, $allowedList)) {
            $message = \Flight::get('maintenance.message') ?? 'We are currently performing maintenance. Please check back soon.';
            http_response_code(503);
            header('Retry-After: 3600');
            echo '<!DOCTYPE html><html><head><title>Maintenance</title>
                <style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5;}
                .box{text-align:center;padding:40px;background:white;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:500px;}
                h1{color:#333;margin-bottom:20px;}p{color:#666;}</style></head>
                <body><div class="box"><h1>Under Maintenance</h1><p>' . htmlspecialchars($message) . '</p></div></body></html>';
            exit;
        }
    }
}

// Load routes - for both web and CLI modes (CLI sets REQUEST_URI in CliHandler)
if (isset($_SERVER['REQUEST_URI'])) {
    $routePath = BASE_PATH . '/routes';
    
    // Check if we have a specific route file for the first segment
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $segments = explode('/', trim($requestUri, '/'));
    $firstSegment = (!empty($segments[0])) ? $segments[0] : 'index';
    
    // Load specific route file if it exists, otherwise use default
    $specificRoute = $routePath . '/' . $firstSegment . '.php';
    if (file_exists($specificRoute)) {
        require_once $specificRoute;
    } else {
        // Load default routes
        require_once $routePath . '/default.php';
    }
}

// Run the application
$app->run();
