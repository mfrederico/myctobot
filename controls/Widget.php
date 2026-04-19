<?php
/**
 * Widget Controller
 *
 * Serves the embeddable JavaScript widget for customer support chat.
 * This is a public controller (no auth required).
 *
 * Routes:
 *   GET /widget.js          -> Serve widget JavaScript
 *   GET /widget/{token}     -> Serve configured widget for specific store
 *   GET /widget/status      -> Check widget/connection status (AJAX)
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use \app\services\WorkspaceResolver;

class Widget extends BaseControls\Control {

    protected $logger;
    private ?string $workspace = null;

    /**
     * Constructor - stateless, no session required
     */
    public function __construct() {
        // Don't call parent - no session for public widget serving
        $this->logger = Flight::get('log');
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;

        // CORS headers for widget loading
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Default - serve widget JavaScript
     *
     * GET /widget/{token}  -> Serve widget with embedded token (for Shopify Script Tags)
     * GET /widget          -> Serve widget without token (use data-attribute)
     */
    public function index($params = []) {
        header('Content-Type: application/javascript');
        header('Cache-Control: public, max-age=60');

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'myctobot.ai';
        $baseUrl = $protocol . '://' . $host;

        // Get token from URL path: /widget/wgt_xxx
        $token = '';
        if (!empty($params) && is_array($params)) {
            $token = reset($params);
        }
        if (empty($token)) {
            $token = $this->getParam('token', '');
        }

        echo $this->getWidgetScript($baseUrl, $token);
    }

    /**
     * Serve widget JavaScript
     *
     * GET /widget/js/{token}  -> Token in URL path
     * GET /widget/js?token=x  -> Token in query string
     */
    public function js($params = []) {
        header('Content-Type: application/javascript');
        header('Cache-Control: public, max-age=60');

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'myctobot.ai';
        $baseUrl = $protocol . '://' . $host;

        // Get token from URL path using opId() helper
        // URL: /widget/js/{token} -> opId() returns the token
        $token = $this->opId() ?? $this->getParam('token', '');

        echo $this->getWidgetScript($baseUrl, (string) $token);
    }

    /**
     * Serve widget CSS
     *
     * GET /widget/css
     */
    public function css($params = []) {
        header('Content-Type: text/css');
        header('Cache-Control: public, max-age=3600');

        echo $this->getWidgetStyles();
    }

    /**
     * Check widget status (used by widget to verify token)
     *
     * GET /widget/status?token=xxx
     */
    public function status($params = []) {
        header('Content-Type: application/json');

        $token = $this->getParam('token');
        if (empty($token)) {
            echo json_encode(['valid' => false, 'error' => 'Token required']);
            return;
        }

        // Switch to workspace if needed
        if ($this->workspace) {
            WorkspaceResolver::switchDatabase($this->workspace);
        }

        $connection = Bean::findOne('connections',
            'connector_type = ? AND enabled = 1 AND metadata_json LIKE ?',
            ['shopify', '%' . $token . '%']
        );

        if (!$connection) {
            echo json_encode(['valid' => false, 'error' => 'Invalid or unconfigured widget']);
            return;
        }

        // Extract pipelines_id from metadata_json
        $metadata = json_decode($connection->metadata_json ?? '{}', true);
        $pipelinesId = $metadata['pipelines_id'] ?? null;

        if (empty($pipelinesId)) {
            echo json_encode(['valid' => false, 'error' => 'Invalid or unconfigured widget']);
            return;
        }

        echo json_encode([
            'valid' => true,
            'shop_name' => $connection->external_name ?: $connection->connection_name ?: 'Store',
            'shop_domain' => $connection->external_eid
        ]);
    }

    /**
     * Get the widget JavaScript code
     *
     * Loads from views/widget/widget.js.txt and does search/replace for variables
     *
     * @param string $baseUrl Base URL for API calls
     * @param string $token Optional token to embed (for Shopify Script Tags)
     */
    private function getWidgetScript(string $baseUrl, string $token = ''): string {
        // Load script template from file
        $templatePath = __DIR__ . '/../views/widget/widget.js.txt';
        $script = file_get_contents($templatePath);

        if ($script === false) {
            return '// Error: widget template not found';
        }

        // Replace placeholders with actual values
        $script = str_replace('%%TOKEN%%', addslashes($token), $script);
        $script = str_replace('%%BASEURL%%', $baseUrl, $script);

        return $script;
    }

    /**
     * Get the widget CSS (for external reference)
     */
    private function getWidgetStyles(): string {
        return <<<CSS
/* MyCTOBot Widget Styles - for external reference only */
/* Styles are injected inline by the widget script */
CSS;
    }

    /**
     * Catch-all for token-based URLs: /widget/{token}
     *
     * When DefaultRoute sees /widget/wgt_xxx, it tries to call wgt_xxx() method.
     * This __call catches that and serves the widget with the token embedded.
     */
    public function __call($method, $args) {
        // If method looks like a token (wgt_*), serve the widget
        if (strpos($method, 'wgt_') === 0 || strpos($method, 'tok_') === 0) {
            $this->index([$method]);
            return;
        }

        // Otherwise, 404
        http_response_code(404);
        echo '// Widget endpoint not found';
    }

    /**
     * Override getParam to work without parent
     */
    protected function getParam($key, $default = null) {
        return Flight::request()->query->$key
            ?? Flight::request()->data->$key
            ?? $default;
    }
}
