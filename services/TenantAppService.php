<?php
/**
 * TenantAppService - Core service for tenant app management
 *
 * Handles the lifecycle of tenant apps: create, start, stop, status, logs.
 * Uses TmuxManager for process lifecycle management.
 */

namespace app\services;

use app\Bean;
use app\TmuxManager;

class TenantAppService
{
    private TenantAppPortManager $portManager;
    private TenantAppBuilder $builder;
    private ServiceManager $serviceManager;
    private $logger;

    /**
     * Session name prefix for tenant apps
     */
    private const SESSION_PREFIX = 'tenantapp';

    /**
     * Health check timeout in seconds
     */
    private const HEALTH_CHECK_TIMEOUT = 10;

    /**
     * Create the service
     */
    public function __construct($logger = null)
    {
        $this->portManager = new TenantAppPortManager();
        $this->builder = new TenantAppBuilder();
        $this->serviceManager = new ServiceManager($logger);
        $this->logger = $logger;
    }

    /**
     * Create a new tenant app
     *
     * @param string $workspace The workspace slug
     * @param array $data App data (name, slug, description, pipeline_id, etc.)
     * @return \RedBeanPHP\OODBBean The created app bean
     */
    public function create(string $workspace, array $data): \RedBeanPHP\OODBBean
    {
        // Generate slug if not provided or empty
        $slug = !empty($data['slug']) ? $data['slug'] : $this->generateSlug($data['name'] ?? 'app');

        // Check for existing app with same slug in workspace
        $existing = Bean::findOne('tenantapps', ' workspace = ? AND slug = ? ', [$workspace, $slug]);
        if ($existing) {
            throw new \RuntimeException("App with slug '{$slug}' already exists in workspace '{$workspace}'");
        }

        $app = Bean::dispense('tenantapps');
        $app->workspace = $workspace;
        $app->slug = $slug;
        $app->name = $data['name'] ?? 'Unnamed App';
        $app->description = $data['description'] ?? '';
        $app->app_type = $data['app_type'] ?? 'pipeline';
        $app->pipelines_id = $data['pipeline_id'] ?? null;
        $app->auth_type = $data['auth_type'] ?? 'api_key';
        $app->api_key = $data['api_key'] ?? $this->generateApiKey();
        // Handle config_json - accept either pre-encoded string or array
        if (isset($data['config_json']) && is_string($data['config_json'])) {
            $app->config_json = $data['config_json'];
        } else {
            $app->config_json = json_encode($data['config'] ?? []);
        }
        $app->routes_json = json_encode($data['routes'] ?? []);
        $app->expose_mcp = !empty($data['expose_mcp']);
        $app->mcp_tools_json = json_encode($data['mcp_tools'] ?? []);
        $app->auto_restart = !empty($data['auto_restart']);
        $app->health_check_path = $data['health_check_path'] ?? '/health';
        $app->status = 'stopped';
        $app->member_id = $data['member_id'] ?? null;
        $app->created_at = date('Y-m-d H:i:s');
        $app->updated_at = date('Y-m-d H:i:s');

        Bean::store($app);

        $this->log('info', "Created app: {$app->name}", ['app_id' => $app->id, 'workspace' => $workspace]);

        return $app;
    }

    /**
     * Start an app — delegates to ServiceManager
     */
    public function start(int $appId): array
    {
        return $this->serviceManager->start($appId);
    }

    /**
     * Stop a running app — delegates to ServiceManager
     */
    public function stop(int $appId): array
    {
        return $this->serviceManager->stop($appId);
    }

    /**
     * Restart an app — delegates to ServiceManager
     */
    public function restart(int $appId): array
    {
        return $this->serviceManager->restart($appId);
    }

    /**
     * Get app status — delegates to ServiceManager
     */
    public function status(int $appId): array
    {
        return $this->serviceManager->status($appId);
    }

    /**
     * Get app logs — delegates to ServiceManager
     */
    public function getLogs(int $appId, int $lines = 100): array
    {
        return $this->serviceManager->getLogs($appId, $lines);
    }

    /**
     * Get app URL
     *
     * @param \RedBeanPHP\OODBBean|int $app App bean or ID
     * @return string|null The app URL or null if not running
     */
    public function getUrl($app): ?string
    {
        if (is_int($app)) {
            $app = Bean::load('tenantapps', $app);
        }

        if (!$app || !$app->port) {
            return null;
        }

        return "http://127.0.0.1:{$app->port}";
    }

    /**
     * Update an app
     *
     * @param int $appId The app ID
     * @param array $data Updated data
     * @return \RedBeanPHP\OODBBean The updated app
     */
    public function update(int $appId, array $data): \RedBeanPHP\OODBBean
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        // Update allowed fields
        $allowedFields = [
            'name', 'description', 'app_type', 'pipelines_id',
            'auth_type', 'api_key', 'expose_mcp', 'auto_restart',
            'health_check_path',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $dbField = $field === 'pipelines_id' ? 'pipelines_id' : $field;
                $app->$dbField = $data[$field];
            }
        }

        // Handle JSON fields - accept either pre-encoded string or array
        if (isset($data['config_json']) && is_string($data['config_json'])) {
            $app->config_json = $data['config_json'];
        } elseif (isset($data['config'])) {
            $app->config_json = json_encode($data['config']);
        }
        if (isset($data['routes'])) {
            $app->routes_json = json_encode($data['routes']);
        }
        if (isset($data['mcp_tools'])) {
            $app->mcp_tools_json = json_encode($data['mcp_tools']);
        }

        $app->updated_at = date('Y-m-d H:i:s');
        Bean::store($app);

        // Rebuild if running (will be picked up on next restart)
        if ($app->status === 'running') {
            $this->builder->build($appId);
        }

        return $app;
    }

    /**
     * Delete an app
     *
     * @param int $appId The app ID
     * @return bool Success
     */
    public function delete(int $appId): bool
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        // Stop if running
        if ($app->status === 'running') {
            $this->stop($appId);
        }

        // Clean up app directory
        $this->builder->cleanup($app->workspace, $app->slug);

        // Delete from database
        Bean::trash($app);

        $this->log('info', "Deleted app: {$app->name}", ['app_id' => $appId]);

        return true;
    }

    /**
     * List all apps for a workspace
     *
     * @param string $workspace The workspace slug
     * @return array Array of app data
     */
    public function listApps(string $workspace): array
    {
        $apps = Bean::find('tenantapps', ' workspace = ? AND (service_type IS NULL OR service_type = ?) ORDER BY name ASC ', [$workspace, 'app']);
        $result = [];

        foreach ($apps as $app) {
            $result[] = [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'status' => $app->status,
                'port' => $app->port,
                'url' => $app->status === 'running' ? $this->getUrl($app) : null,
                'app_type' => $app->app_type,
                'pipeline_id' => $app->pipelines_id,
                'auth_type' => $app->auth_type,
                'expose_mcp' => (bool)$app->expose_mcp,
                'auto_restart' => (bool)$app->auto_restart,
                'deployment_target' => $app->deployment_target ?? 'local',
                'last_started_at' => $app->last_started_at,
                'created_at' => $app->created_at,
            ];
        }

        return $result;
    }

    /**
     * Generate a URL-safe slug
     */
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'app-' . time();
    }

    /**
     * Generate a random API key
     */
    private function generateApiKey(): string
    {
        return 'app_' . bin2hex(random_bytes(16));
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message, $context);
        }
    }
}
