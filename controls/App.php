<?php
/**
 * App Controller - Tenant App Proxy
 *
 * Proxies requests to tenant apps running on their designated ports.
 * URL: /app/{slug}/{path...}
 *
 * Example:
 *   /app/lead-manager/dashboard → proxy to 127.0.0.1:9601/dashboard
 *   /app/contact-classifier/admin → proxy to 127.0.0.1:9605/admin
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class App extends BaseControls\Control {

    /**
     * Main proxy handler
     * Routes: /app/{slug}, /app/{slug}/{path...}
     */
    public function index($params = []) {
        // Get the full request URI and extract slug + path
        $uri = $_SERVER['REQUEST_URI'] ?? '/app';
        $uri = parse_url($uri, PHP_URL_PATH);

        // Remove /app/ prefix to get slug/path
        $remainder = preg_replace('#^/app/?#', '', $uri);
        $parts = explode('/', $remainder, 2);

        $slug = $parts[0] ?? null;
        $path = '/' . ($parts[1] ?? '');

        if (empty($slug)) {
            $this->listApps();
            return;
        }

        $this->proxyToApp($slug, $path);
    }

    /**
     * List available apps (when accessing /app with no slug)
     */
    private function listApps(): void {
        $apps = Bean::find('tenantapps', ' status = ? ORDER BY name ', ['running']);

        $appList = [];
        foreach ($apps as $app) {
            $appList[] = [
                'slug' => $app->slug,
                'name' => $app->name,
                'url' => "/app/{$app->slug}",
                'port' => (int) $app->port,
                'status' => $app->status,
            ];
        }

        // If JSON requested, return list
        if ($this->wantsJson()) {
            Flight::jsonSuccess(['apps' => $appList]);
            return;
        }

        // HTML list
        $html = $this->renderAppList($appList);
        echo $html;
    }

    /**
     * Proxy request to tenant app
     */
    private function proxyToApp(string $slug, string $path): void {
        // Find the app
        $app = Bean::findOne('tenantapps', ' slug = ? ', [$slug]);

        if (!$app || !$app->id) {
            $this->sendError(404, 'App not found', $slug);
            return;
        }

        if ($app->status !== 'running') {
            $this->sendError(503, 'App not running', $slug, [
                'status' => $app->status,
                'hint' => 'Start the app from /apps',
            ]);
            return;
        }

        if (!$app->port) {
            $this->sendError(503, 'App has no port assigned', $slug);
            return;
        }

        // Build target URL
        $targetUrl = "http://127.0.0.1:{$app->port}{$path}";

        // Include query string
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        if ($queryString) {
            $targetUrl .= '?' . $queryString;
        }

        // Proxy the request
        $this->doProxy($targetUrl, $app);
    }

    /**
     * Execute the proxy request via cURL
     */
    private function doProxy(string $targetUrl, $app): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $ch = curl_init($targetUrl);

        // Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        // Forward request body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $body = file_get_contents('php://input');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Build headers to forward
        $forwardHeaders = $this->buildForwardHeaders($app);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);

        // Execute request
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($error) {
            $this->sendError(502, 'Proxy error: ' . $error, $app->slug);
            return;
        }

        // Split response headers and body
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        // Forward response status
        http_response_code($httpCode);

        // Forward relevant response headers
        $this->forwardResponseHeaders($responseHeaders);

        // Send body
        echo $responseBody;
    }

    /**
     * Build headers to forward to the tenant app
     */
    private function buildForwardHeaders($app): array {
        $headers = [];

        // Forward content type
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
        }

        // Forward authorization if present
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
        }

        // Forward API key if present
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            $headers[] = 'X-API-Key: ' . $_SERVER['HTTP_X_API_KEY'];
        }

        // Forward accept header
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
        }

        // Inject context headers (so app knows who's calling)
        if ($this->member) {
            $headers[] = 'X-Member-Id: ' . $this->member->id;
            $headers[] = 'X-Member-Email: ' . ($this->member->email ?? '');
            $headers[] = 'X-Member-Level: ' . ($this->member->level ?? 100);
        }

        // Workspace context
        $workspace = $_SESSION['workspace_slug'] ?? $_SERVER['WORKSPACE'] ?? 'default';
        $headers[] = 'X-Workspace: ' . $workspace;

        // Forwarded headers
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $headers[] = 'X-Forwarded-For: ' . $clientIp;
        $headers[] = 'X-Forwarded-Proto: ' . ($_SERVER['HTTPS'] ?? 'off' === 'on' ? 'https' : 'http');
        $headers[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Mark as proxied with base path for URL generation
        $headers[] = 'X-Proxied-By: myctobot';
        $headers[] = 'X-App-Slug: ' . $app->slug;
        $headers[] = 'X-Forwarded-Prefix: /app/' . $app->slug;

        return $headers;
    }

    /**
     * Forward response headers from tenant app
     */
    private function forwardResponseHeaders(string $headerBlock): void {
        $lines = explode("\r\n", $headerBlock);

        // Headers to forward
        $forwardable = [
            'content-type',
            'content-disposition',
            'cache-control',
            'expires',
            'last-modified',
            'etag',
            'x-',  // Forward all X- headers
        ];

        foreach ($lines as $line) {
            if (empty($line) || !str_contains($line, ':')) {
                continue;
            }

            $parts = explode(':', $line, 2);
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1] ?? '');

            foreach ($forwardable as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    header("{$parts[0]}: {$value}");
                    break;
                }
            }
        }
    }

    /**
     * Check if client wants JSON response
     */
    private function wantsJson(): bool {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Send error response
     */
    private function sendError(int $code, string $message, string $slug, array $extra = []): void {
        http_response_code($code);

        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            echo json_encode(array_merge([
                'error' => $message,
                'app' => $slug,
            ], $extra));
            return;
        }

        // HTML error
        echo "<!DOCTYPE html><html><head><title>Error</title>";
        echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>";
        echo "</head><body class='bg-light'>";
        echo "<div class='container mt-5'><div class='alert alert-danger'>";
        echo "<h4>{$code} - {$message}</h4>";
        echo "<p>App: <code>{$slug}</code></p>";
        if (!empty($extra['hint'])) {
            echo "<p class='mb-0'><small>{$extra['hint']}</small></p>";
        }
        echo "</div><a href='/app' class='btn btn-primary'>← Back to Apps</a></div>";
        echo "</body></html>";
    }

    /**
     * Render app list HTML
     */
    private function renderAppList(array $apps): string {
        $appCards = '';
        foreach ($apps as $app) {
            $name = h($app['name']);
            $slug = h($app['slug']);
            $url = h($app['url']);
            $port = (int) $app['port'];

            $appCards .= <<<HTML
                <div class="col-md-4">
                    <a href="{$url}" class="card app-card text-decoration-none h-100">
                        <div class="card-body">
                            <h5 class="card-title text-primary">
                                <i class="bi bi-app-indicator me-2"></i>{$name}
                            </h5>
                            <p class="card-text text-muted mb-2"><code>{$slug}</code></p>
                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i>Running on port {$port}
                            </span>
                        </div>
                    </a>
                </div>
HTML;
        }

        $emptyMessage = empty($apps)
            ? '<div class="alert alert-info">No apps are currently running.</div>'
            : '';

        $appGrid = empty($apps) ? '' : "<div class=\"row g-4\">{$appCards}</div>";

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Apps</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .app-card { transition: transform .2s; }
        .app-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <h2 class="mb-4">
            <i class="bi bi-grid-3x3-gap me-2"></i>Available Apps
        </h2>

        {$emptyMessage}
        {$appGrid}

        <div class="mt-4">
            <a href="/apps" class="btn btn-outline-secondary">
                <i class="bi bi-gear me-1"></i>Manage Apps
            </a>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
