<?php
/**
 * Router - Lightweight FlightPHP-style Router
 *
 * Auto-routes URLs to controllers using the DefaultRoute pattern:
 * /controller/method/id -> Controller::method($id)
 */

namespace TenantApp;

class Router {

    private string $controllerPath;
    private string $controllerNamespace;
    private array $routes = [];
    private $notFoundHandler = null;

    public function __construct(string $controllerPath, string $namespace = 'TenantApp\\Controls') {
        $this->controllerPath = rtrim($controllerPath, '/');
        $this->controllerNamespace = $namespace;
    }

    /**
     * Register an explicit route (optional - for special cases)
     */
    public function route(string $pattern, callable $handler, array $methods = ['GET']): self {
        $this->routes[] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'methods' => array_map('strtoupper', $methods),
        ];
        return $this;
    }

    /**
     * Set 404 handler
     */
    public function notFound(callable $handler): self {
        $this->notFoundHandler = $handler;
        return $this;
    }

    /**
     * Dispatch a request
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path
     * @param array $context Additional context (request, response for OpenSwoole)
     * @return mixed Handler result
     */
    public function dispatch(string $method, string $path, array $context = []) {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        // Check explicit routes first
        foreach ($this->routes as $route) {
            if (in_array($method, $route['methods']) && $this->matchPattern($route['pattern'], $path, $params)) {
                return call_user_func($route['handler'], $params, $context);
            }
        }

        // Auto-route to controller
        return $this->autoRoute($method, $path, $context);
    }

    /**
     * Auto-route using DefaultRoute pattern
     */
    private function autoRoute(string $method, string $path, array $context) {
        $parts = explode('/', trim($path, '/'));
        $parts = array_filter($parts, fn($p) => $p !== '');

        // Default to Index controller
        $controllerName = !empty($parts[0]) ? ucfirst(strtolower($parts[0])) : 'Index';
        $methodName = !empty($parts[1]) ? strtolower($parts[1]) : 'index';
        $operationId = $parts[2] ?? null;

        // Load controller
        $controllerFile = $this->controllerPath . '/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            return $this->handleNotFound($path, $context);
        }

        require_once $controllerFile;

        $controllerClass = $this->controllerNamespace . '\\' . $controllerName;

        if (!class_exists($controllerClass)) {
            return $this->handleNotFound($path, $context);
        }

        $controller = new $controllerClass();

        // Inject context (request, response, etc.)
        if (method_exists($controller, 'setContext')) {
            $controller->setContext($context);
        }

        // Set operation ID if available
        if ($operationId !== null && method_exists($controller, 'setOpId')) {
            $controller->setOpId($operationId);
        }

        // Check if method exists (must be lowercase for routing)
        if (!method_exists($controller, $methodName)) {
            return $this->handleNotFound($path, $context);
        }

        // Security: Don't allow calling methods that start with underscore
        if (str_starts_with($methodName, '_')) {
            return $this->handleNotFound($path, $context);
        }

        // Call the method
        return $controller->$methodName();
    }

    /**
     * Match a route pattern
     */
    private function matchPattern(string $pattern, string $path, ?array &$params = null): bool {
        $params = [];

        // Convert pattern to regex
        $regex = preg_replace_callback('/\{(\w+)\}/', function($m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Handle 404
     */
    private function handleNotFound(string $path, array $context) {
        if ($this->notFoundHandler) {
            return call_user_func($this->notFoundHandler, $path, $context);
        }

        // Default 404 response
        if (isset($context['response'])) {
            $context['response']->status(404);
            $context['response']->end(json_encode(['error' => 'Not Found', 'path' => $path]));
        }

        return null;
    }
}
