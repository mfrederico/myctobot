<?php
/**
 * Control - Base Controller Class
 *
 * FlightPHP-style base controller for tenant apps.
 * Provides render(), getParam(), json helpers, etc.
 */

namespace TenantApp;

abstract class Control {

    protected $request;
    protected $response;
    protected array $context = [];
    protected ?string $opId = null;
    protected array $viewData = [];

    // Paths (set by App)
    protected static string $viewPath = '';
    protected static string $layoutPath = '';

    /**
     * Set view paths
     */
    public static function setPaths(string $viewPath, string $layoutPath = ''): void {
        self::$viewPath = rtrim($viewPath, '/');
        self::$layoutPath = $layoutPath ? rtrim($layoutPath, '/') : '';
    }

    /**
     * Set request context (called by Router)
     */
    public function setContext(array $context): void {
        $this->context = $context;
        $this->request = $context['request'] ?? null;
        $this->response = $context['response'] ?? null;
    }

    /**
     * Set operation ID (from URL path)
     */
    public function setOpId(?string $id): void {
        $this->opId = $id;
    }

    /**
     * Get operation ID
     */
    protected function opId(): ?string {
        return $this->opId;
    }

    /**
     * Get request parameter (from GET, POST, or JSON body)
     */
    protected function getParam(string $key, $default = null) {
        // Check GET params
        if (isset($this->request->get[$key])) {
            return $this->request->get[$key];
        }

        // Check POST params
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }

        // Check JSON body
        $body = $this->getJsonBody();
        if (isset($body[$key])) {
            return $body[$key];
        }

        return $default;
    }

    /**
     * Get all request parameters
     */
    protected function getParams(): array {
        $params = [];

        // Merge GET
        if (isset($this->request->get)) {
            $params = array_merge($params, (array) $this->request->get);
        }

        // Merge POST
        if (isset($this->request->post)) {
            $params = array_merge($params, (array) $this->request->post);
        }

        // Merge JSON body
        $body = $this->getJsonBody();
        if ($body) {
            $params = array_merge($params, $body);
        }

        return $params;
    }

    /**
     * Get JSON body (cached)
     */
    private ?array $jsonBody = null;

    protected function getJsonBody(): ?array {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $rawBody = method_exists($this->request, 'getContent')
            ? $this->request->getContent()
            : ($this->request->rawContent() ?? '');

        if (empty($rawBody)) {
            return $this->jsonBody = [];
        }

        $decoded = json_decode($rawBody, true);
        return $this->jsonBody = (is_array($decoded) ? $decoded : []);
    }

    /**
     * Check if request is POST
     */
    protected function isPost(): bool {
        return strtoupper($this->request->server['request_method'] ?? 'GET') === 'POST';
    }

    /**
     * Check if request is AJAX
     */
    protected function isAjax(): bool {
        return ($this->request->header['x-requested-with'] ?? '') === 'XMLHttpRequest'
            || str_contains($this->request->header['accept'] ?? '', 'application/json');
    }

    /**
     * Render a view
     */
    protected function render(string $view, array $data = []): void {
        $viewFile = self::$viewPath . '/' . $view . '.php';

        if (!file_exists($viewFile)) {
            $this->response->status(500);
            $this->response->end("View not found: {$view}");
            return;
        }

        // Merge view data
        $data = array_merge($this->viewData, $data);

        // Extract variables for view
        extract($data);

        // Capture output
        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        $this->response->header('Content-Type', 'text/html; charset=utf-8');
        $this->response->end($content);
    }

    /**
     * Send JSON success response
     */
    protected function jsonSuccess($data = null, string $message = ''): void {
        $response = ['success' => true];

        if ($message) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->response->header('Content-Type', 'application/json');
        $this->response->end(json_encode($response));
    }

    /**
     * Send JSON error response
     */
    protected function jsonError(string $message, int $code = 400): void {
        $this->response->status($code);
        $this->response->header('Content-Type', 'application/json');
        $this->response->end(json_encode([
            'success' => false,
            'error' => $message,
        ]));
    }

    /**
     * Redirect to another URL
     */
    protected function redirect(string $url, int $code = 302): void {
        $this->response->status($code);
        $this->response->header('Location', $url);
        $this->response->end();
    }

    /**
     * Get API key from request
     */
    protected function getApiKey(): ?string {
        // Check X-API-Key header
        $key = $this->request->header['x-api-key'] ?? null;
        if ($key) return $key;

        // Check Authorization: Bearer header
        $auth = $this->request->header['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return $m[1];
        }

        // Check query param
        return $this->request->get['api_key'] ?? null;
    }

    /**
     * Require API key authentication
     */
    protected function requireApiKey(string $expectedKey): bool {
        $providedKey = $this->getApiKey();

        if (empty($expectedKey)) {
            return true; // No key required
        }

        if ($providedKey !== $expectedKey) {
            $this->jsonError('Unauthorized', 401);
            return false;
        }

        return true;
    }

    /**
     * Get client IP address
     */
    protected function getClientIp(): string {
        return $this->request->server['remote_addr']
            ?? $this->request->header['x-forwarded-for']
            ?? '0.0.0.0';
    }

    /**
     * Set flash message (stored in response header for client-side handling)
     */
    protected function flash(string $type, string $message): void {
        // For SPA apps, we can include flash in response or use cookies
        // Simple approach: add to viewData for next render
        $this->viewData['flash'] = ['type' => $type, 'message' => $message];
    }
}
