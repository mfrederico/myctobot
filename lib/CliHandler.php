<?php
/**
 * CLI Handler for TikNix Framework
 * Handles command line execution of controllers and methods
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;

class CliHandler {
    
    private $options = [];
    private $argv;
    private $argc;
    
    public function __construct($argv = null, $argc = null) {
        $this->argv = $argv ?? $_SERVER['argv'] ?? [];
        $this->argc = $argc ?? $_SERVER['argc'] ?? 0;
    }
    
    /**
     * Check if running in CLI mode
     */
    public static function isCli() {
        return php_sapi_name() === 'cli' || 
               (defined('STDIN') && !empty($_SERVER['argv']));
    }
    
    /**
     * Process CLI arguments and set up environment
     */
    public function process() {
        // Show help if requested
        if ($this->hasArg('--help') || $this->hasArg('-h')) {
            $this->showHelp();
            exit(0);
        }
        
        // Parse command line options
        $this->parseOptions();
        
        // Validate required options for command execution
        // Skip validation if --script mode (standalone scripts handle their own routing)
        if ($this->argc > 1 && !$this->isConfigOnly() && !$this->isScriptMode()) {
            if (!isset($this->options['control'])) {
                $this->error("Controller (--control) is required for CLI execution");
            }
            
            // Set up the CLI environment
            $this->setupEnvironment();
            
            // Set CLI mode flag
            $_SERVER['CLI_MODE'] = true;
            Flight::set('cli_mode', true);
            
            // Log CLI execution
            if (Flight::has('log')) {
                Flight::get('log')->info('CLI Execution', [
                    'control' => $this->options['control'],
                    'method' => $this->options['method'] ?? 'index',
                    'member' => $this->options['member'] ?? 'public'
                ]);
            }
        }
    }
    
    /**
     * Parse command line options
     */
    private function parseOptions() {
        // Define long options
        $longopts = [
            "member:",     // Member ID to run as
            "control:",    // Controller name
            "method:",     // Method name
            "params:",     // URL-encoded parameters
            "json:",       // JSON parameters
            "cron",        // Cron mode (suppress output)
            "verbose",     // Verbose output
            "script",      // Standalone script mode (skip controller routing)
        ];
        
        // Try getopt first
        $options = getopt("", $longopts);
        
        // Manual parsing as fallback
        if (empty($options) && $this->argc > 1) {
            foreach ($this->argv as $arg) {
                if (strpos($arg, '--member=') === 0) {
                    $options['member'] = substr($arg, 9);
                } elseif (strpos($arg, '--control=') === 0) {
                    $options['control'] = substr($arg, 10);
                } elseif (strpos($arg, '--method=') === 0) {
                    $options['method'] = substr($arg, 9);
                } elseif (strpos($arg, '--params=') === 0) {
                    $options['params'] = substr($arg, 9);
                } elseif (strpos($arg, '--json=') === 0) {
                    $options['json'] = substr($arg, 7);
                } elseif ($arg === '--cron') {
                    $options['cron'] = true;
                } elseif ($arg === '--verbose') {
                    $options['verbose'] = true;
                } elseif ($arg === '--script') {
                    $options['script'] = true;
                }
            }
        }
        
        $this->options = $options;
    }
    
    /**
     * Set up the request environment for CLI
     */
    private function setupEnvironment() {
        $control = $this->options['control'];
        $method = $this->options['method'] ?? 'index';
        
        // Set up server variables
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = "/{$control}/{$method}";
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'cli.localhost';
        
        // Parse parameters
        if (!empty($this->options['params'])) {
            $_SERVER['QUERY_STRING'] = $this->options['params'];
            parse_str($this->options['params'], $_GET);
            $_REQUEST = array_merge($_REQUEST, $_GET);
        }
        
        // Parse JSON parameters
        if (!empty($this->options['json'])) {
            $jsonData = json_decode($this->options['json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $_REQUEST = array_merge($_REQUEST, $jsonData);
                // Simulate POST for JSON data
                $_SERVER['REQUEST_METHOD'] = 'POST';
                Flight::request()->data->setData($jsonData);
            } else {
                $this->error("Invalid JSON parameters: " . json_last_error_msg());
            }
        }
        
        // Store CLI options for later use
        Flight::set('cli_options', $this->options);
    }
    
    /**
     * Set up member context for CLI execution
     */
    public function setupMember() {
        if (!empty($this->options['member'])) {
            try {
                $member = R::load('member', $this->options['member']);
                if ($member && $member->id) {
                    // Set as current member
                    $_SESSION['member'] = $member->export();
                    $_SESSION['member']['id'] = $member->id;
                    $_SESSION['member']['level'] = $member->level;
                    
                    // Also set in Flight for easy access
                    Flight::set('member', $member);
                    Flight::set('memberlevel', $member->level);
                    
                    if (!$this->isCronMode()) {
                        echo "Running as member: {$member->username} (ID: {$member->id}, Level: {$member->level})\n";
                    }
                } else {
                    $this->error("Member ID {$this->options['member']} not found");
                }
            } catch (Exception $e) {
                $this->error("Error loading member: " . $e->getMessage());
            }
        } else {
            // Use public-user-entity by default
            $publicUser = R::findOne('member', 'username = ?', ['public-user-entity']);
            if ($publicUser) {
                $_SESSION['member'] = $publicUser->export();
                $_SESSION['member']['id'] = $publicUser->id;
                $_SESSION['member']['level'] = $publicUser->level;
                
                // Also set in Flight for easy access
                Flight::set('member', $publicUser);
                Flight::set('memberlevel', $publicUser->level);
                
                if ($this->isVerbose()) {
                    echo "Running as public-user-entity (Level: {$publicUser->level})\n";
                }
            }
        }
    }
    
    /**
     * Check if running in cron mode (suppress output)
     */
    public function isCronMode() {
        return isset($this->options['cron']);
    }

    /**
     * Check if verbose mode
     */
    public function isVerbose() {
        return isset($this->options['verbose']);
    }

    /**
     * Check if running in script mode (standalone scripts, skip controller routing)
     */
    public function isScriptMode() {
        return isset($this->options['script']);
    }
    
    /**
     * Check if only config file was provided
     */
    private function isConfigOnly() {
        return $this->argc === 2 && !strpos($this->argv[1], '--');
    }
    
    /**
     * Check if argument exists
     */
    private function hasArg($arg) {
        return in_array($arg, $this->argv);
    }
    
    /**
     * Show help message
     */
    private function showHelp() {
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
    }
    
    /**
     * Show error and exit
     */
    private function error($message) {
        if (!$this->isCronMode()) {
            echo "Error: {$message}\n";
            echo "Use --help for usage information\n";
        }
        exit(1);
    }
    
    /**
     * Get CLI options
     */
    public function getOptions() {
        return $this->options;
    }
    
    /**
     * Get specific option
     */
    public function getOption($key, $default = null) {
        return $this->options[$key] ?? $default;
    }
}