<?php
/**
 * App - Tenant App Bootstrap
 *
 * Main entry point for tenant apps. Sets up database, router,
 * and runs the OpenSwoole HTTP server.
 */

namespace TenantApp;

class App {

    private array $config = [];
    private ?Router $router = null;
    private string $appDir;

    public function __construct(string $appDir) {
        $this->appDir = rtrim($appDir, '/');
    }

    /**
     * Load configuration
     */
    public function loadConfig(string $configFile = 'config.php'): self {
        $configPath = $this->appDir . '/' . $configFile;

        if (file_exists($configPath)) {
            $this->config = require $configPath;
        }

        return $this;
    }

    /**
     * Get config value
     */
    public function config(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set config value
     */
    public function setConfig(string $key, $value): self {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Initialize database
     */
    public function initDatabase(?string $dbPath = null): self {
        $dbPath = $dbPath ?? $this->config['database'] ?? $this->appDir . '/data.sqlite';

        // Create directory if needed
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        Bean::setup($dbPath);

        return $this;
    }

    /**
     * Initialize router
     */
    public function initRouter(): self {
        $controllerPath = $this->appDir . '/controls';
        $this->router = new Router($controllerPath, 'TenantApp\\Controls');

        // Set view paths for controllers
        Control::setPaths($this->appDir . '/views');

        return $this;
    }

    /**
     * Get router instance
     */
    public function getRouter(): Router {
        if (!$this->router) {
            $this->initRouter();
        }
        return $this->router;
    }

    /**
     * Handle an HTTP request (for OpenSwoole)
     */
    public function handle($request, $response): void {
        $method = $request->server['request_method'] ?? 'GET';
        $path = $request->server['request_uri'] ?? '/';

        // Remove query string from path
        $path = parse_url($path, PHP_URL_PATH) ?? '/';

        $context = [
            'request' => $request,
            'response' => $response,
            'app' => $this,
            'config' => $this->config,
        ];

        try {
            $this->getRouter()->dispatch($method, $path, $context);
        } catch (\Throwable $e) {
            $this->handleError($e, $response);
        }
    }

    /**
     * Handle errors
     */
    private function handleError(\Throwable $e, $response): void {
        error_log("App Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());

        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
        ]));
    }

    /**
     * Run the OpenSwoole HTTP server
     */
    public function run(string $host = '127.0.0.1', int $port = 9600): void {
        $server = new \OpenSwoole\HTTP\Server($host, $port);

        $server->set([
            'worker_num' => $this->config['workers'] ?? 2,
            'daemonize' => false,
            'log_level' => \OpenSwoole\Constant::LOG_WARNING,
        ]);

        $appName = $this->config['app_name'] ?? 'TenantApp';

        $server->on('start', function($server) use ($host, $port, $appName) {
            echo "[{$appName}] Server started at http://{$host}:{$port}\n";
        });

        $server->on('request', function($request, $response) {
            // Set default headers
            $response->header('Content-Type', 'application/json');
            $response->header('X-Powered-By', 'TenantApp');

            $this->handle($request, $response);
        });

        $server->start();
    }

    /**
     * Helper: Create standard health check route
     */
    public function addHealthCheck(string $path = '/health'): self {
        $this->getRouter()->route($path, function($params, $context) {
            $context['response']->end(json_encode([
                'status' => 'ok',
                'app' => $this->config['app_slug'] ?? 'unknown',
                'timestamp' => date('c'),
            ]));
        }, ['GET']);

        return $this;
    }
}
