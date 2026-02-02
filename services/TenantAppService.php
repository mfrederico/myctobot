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
        $app->config_json = json_encode($data['config'] ?? []);
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
     * Start an app
     *
     * @param int $appId The app ID
     * @return array Result with status, port, url
     */
    public function start(int $appId): array
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        // Check if already running
        if ($app->status === 'running') {
            $sessionName = $this->buildSessionName($app->workspace, $app->slug);
            if (TmuxManager::exists($sessionName)) {
                return [
                    'success' => true,
                    'status' => 'already_running',
                    'port' => $app->port,
                    'url' => $this->getUrl($app),
                ];
            }
            // Session doesn't exist but status is running - needs restart
        }

        // Allocate port if not assigned
        if (!$app->port) {
            $app->port = $this->portManager->allocate();
        }

        // Update status
        $app->status = 'starting';
        $app->updated_at = date('Y-m-d H:i:s');
        Bean::store($app);

        try {
            // Build app server code
            $appDir = $this->builder->build($appId);

            // Create tmux session
            $sessionName = $this->buildSessionName($app->workspace, $app->slug);
            $command = sprintf('php %s/server.php', $appDir);

            // Kill existing session if it exists
            if (TmuxManager::exists($sessionName)) {
                TmuxManager::kill($sessionName);
                usleep(500000); // Wait 500ms for cleanup
            }

            // Start new session
            TmuxManager::create($sessionName, $command, $appDir);

            // Wait for health check
            $healthy = $this->waitForHealth($app, self::HEALTH_CHECK_TIMEOUT);

            if ($healthy) {
                $app->status = 'running';
                $app->tmux_session = $sessionName;
                $app->pid = TmuxManager::getPanePid($sessionName);
                $app->last_started_at = date('Y-m-d H:i:s');
                $app->error_message = null;
                Bean::store($app);

                $this->log('info', "Started app: {$app->name}", [
                    'app_id' => $app->id,
                    'port' => $app->port,
                    'session' => $sessionName,
                ]);

                return [
                    'success' => true,
                    'status' => 'running',
                    'port' => $app->port,
                    'url' => $this->getUrl($app),
                    'session' => $sessionName,
                ];
            } else {
                // Health check failed - capture error
                $output = TmuxManager::capture($sessionName, 50);
                TmuxManager::kill($sessionName);

                $app->status = 'error';
                $app->error_message = "Health check failed. Server output:\n" . $output;
                Bean::store($app);

                throw new \RuntimeException('Health check failed after ' . self::HEALTH_CHECK_TIMEOUT . ' seconds');
            }

        } catch (\Throwable $e) {
            $app->status = 'error';
            $app->error_message = $e->getMessage();
            $app->updated_at = date('Y-m-d H:i:s');
            Bean::store($app);

            $this->log('error', "Failed to start app: {$app->name}", [
                'app_id' => $app->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Stop a running app
     *
     * @param int $appId The app ID
     * @return array Result with status
     */
    public function stop(int $appId): array
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        $sessionName = $this->buildSessionName($app->workspace, $app->slug);

        // Send Ctrl+C for graceful shutdown
        if (TmuxManager::exists($sessionName)) {
            TmuxManager::sendKeys($sessionName, 'C-c');
            usleep(2000000); // Wait 2 seconds for graceful shutdown

            // Force kill if still running
            if (TmuxManager::exists($sessionName)) {
                TmuxManager::kill($sessionName);
            }
        }

        // Update status
        $app->status = 'stopped';
        $app->tmux_session = null;
        $app->pid = null;
        $app->last_stopped_at = date('Y-m-d H:i:s');
        $app->updated_at = date('Y-m-d H:i:s');
        Bean::store($app);

        $this->log('info', "Stopped app: {$app->name}", ['app_id' => $app->id]);

        return [
            'success' => true,
            'status' => 'stopped',
        ];
    }

    /**
     * Restart an app
     *
     * @param int $appId The app ID
     * @return array Result with status
     */
    public function restart(int $appId): array
    {
        $this->stop($appId);
        usleep(1000000); // Wait 1 second between stop and start
        return $this->start($appId);
    }

    /**
     * Get app status
     *
     * @param int $appId The app ID
     * @return array Status information
     */
    public function status(int $appId): array
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        $sessionName = $this->buildSessionName($app->workspace, $app->slug);
        $sessionExists = TmuxManager::exists($sessionName);

        // Detect zombie state (status says running but session dead)
        if ($app->status === 'running' && !$sessionExists) {
            $app->status = 'crashed';
            $app->error_message = 'Session terminated unexpectedly';
            $app->updated_at = date('Y-m-d H:i:s');
            Bean::store($app);
        }

        // Check health endpoint if running
        $healthy = false;
        if ($app->status === 'running' && $app->port) {
            $healthy = $this->checkHealth($app);
            $app->last_health_check = date('Y-m-d H:i:s');
            Bean::store($app);
        }

        return [
            'app_id' => $app->id,
            'name' => $app->name,
            'slug' => $app->slug,
            'status' => $app->status,
            'port' => $app->port,
            'url' => $app->status === 'running' ? $this->getUrl($app) : null,
            'session_exists' => $sessionExists,
            'healthy' => $healthy,
            'pid' => $app->pid,
            'last_started_at' => $app->last_started_at,
            'last_stopped_at' => $app->last_stopped_at,
            'last_health_check' => $app->last_health_check,
            'error_message' => $app->error_message,
            'auto_restart' => (bool)$app->auto_restart,
        ];
    }

    /**
     * Get app logs
     *
     * @param int $appId The app ID
     * @param int $lines Number of lines to return
     * @return array Log lines and metadata
     */
    public function getLogs(int $appId, int $lines = 100): array
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        $logs = [];

        // Get tmux output if session exists
        $sessionName = $this->buildSessionName($app->workspace, $app->slug);
        if (TmuxManager::exists($sessionName)) {
            $tmuxOutput = TmuxManager::capture($sessionName, $lines);
            if ($tmuxOutput) {
                $logs['tmux'] = explode("\n", $tmuxOutput);
            }
        }

        // Get file logs
        $logger = new TenantAppLogger($app->workspace, $app->slug);
        $fileLogs = $logger->getRecentLogs($lines);
        if (!empty($fileLogs)) {
            $logs['file'] = $fileLogs;
        }

        return [
            'app_id' => $app->id,
            'name' => $app->name,
            'status' => $app->status,
            'logs' => $logs,
        ];
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

        // Handle JSON fields
        if (isset($data['config'])) {
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
        $apps = Bean::find('tenantapps', ' workspace = ? ORDER BY name ASC ', [$workspace]);
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
                'last_started_at' => $app->last_started_at,
                'created_at' => $app->created_at,
            ];
        }

        return $result;
    }

    /**
     * Build session name for an app
     */
    private function buildSessionName(string $workspace, string $slug): string
    {
        $safeWorkspace = TmuxManager::sanitizeForSessionName($workspace);
        $safeSlug = TmuxManager::sanitizeForSessionName($slug);
        return self::SESSION_PREFIX . "-{$safeWorkspace}-{$safeSlug}";
    }

    /**
     * Wait for health check to pass
     */
    private function waitForHealth($app, int $timeout): bool
    {
        $start = time();
        while ((time() - $start) < $timeout) {
            if ($this->checkHealth($app)) {
                return true;
            }
            usleep(500000); // Check every 500ms
        }
        return false;
    }

    /**
     * Check health endpoint
     */
    private function checkHealth($app): bool
    {
        if (!$app->port) {
            return false;
        }

        $url = "http://127.0.0.1:{$app->port}" . ($app->health_check_path ?: '/health');

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['status']) && $data['status'] === 'ok';
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
