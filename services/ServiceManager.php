<?php
/**
 * ServiceManager - Unified lifecycle management for apps and engines
 *
 * Handles start/stop/restart/status/logs for both tenant apps and
 * pipeline engines. Dispatches to the appropriate builder based on
 * service_type stored in tenantapps table.
 */

namespace app\services;

use app\Bean;
use app\TmuxManager;

class ServiceManager
{
    private ServicePortManager $portManager;
    private TenantAppBuilder $appBuilder;
    private PipelineEngineBuilder $engineBuilder;
    private $logger;

    private const SESSION_PREFIX = 'tenantapp';
    private const ENGINE_SESSION_PREFIX = 'pipeeng';
    private const HEALTH_CHECK_TIMEOUT = 10;

    public function __construct($logger = null)
    {
        $this->portManager = new ServicePortManager();
        $this->appBuilder = new TenantAppBuilder();
        $this->engineBuilder = new PipelineEngineBuilder();
        $this->logger = $logger;
    }

    /**
     * Start a service (app or engine)
     */
    public function start(int $serviceId): array
    {
        $service = Bean::load('tenantapps', $serviceId);
        if (!$service || !$service->id) {
            throw new \RuntimeException("Service not found: {$serviceId}");
        }

        $serviceType = $service->service_type ?? 'app';

        // Check if already running
        if ($service->status === 'running') {
            $sessionName = $this->buildSessionName($service);
            if (TmuxManager::exists($sessionName)) {
                return [
                    'success' => true,
                    'status' => 'already_running',
                    'port' => $service->port,
                    'url' => $this->getUrl($service),
                ];
            }
        }

        // Allocate port if not assigned
        if (!$service->port) {
            $service->port = $this->portManager->allocate();
        }

        // Update status
        $service->status = 'starting';
        $service->updated_at = date('Y-m-d H:i:s');
        Bean::store($service);

        try {
            // Build server code based on service type
            $serverDir = $this->buildService($service, $serviceType);

            // Create tmux session
            $sessionName = $this->buildSessionName($service);
            $command = sprintf('php %s/server.php', $serverDir);

            // Kill existing session if it exists
            if (TmuxManager::exists($sessionName)) {
                TmuxManager::kill($sessionName);
                usleep(500000);
            }

            // Start new session
            TmuxManager::create($sessionName, $command, $serverDir);

            // Wait for health check
            $healthy = $this->waitForHealth($service, self::HEALTH_CHECK_TIMEOUT);

            if ($healthy) {
                $service->status = 'running';
                $service->tmux_session = $sessionName;
                $service->pid = TmuxManager::getPanePid($sessionName);
                $service->last_started_at = date('Y-m-d H:i:s');
                $service->error_message = null;
                Bean::store($service);

                $this->log('info', "Started {$serviceType}: {$service->name}", [
                    'service_id' => $service->id,
                    'port' => $service->port,
                    'session' => $sessionName,
                ]);

                return [
                    'success' => true,
                    'status' => 'running',
                    'port' => $service->port,
                    'url' => $this->getUrl($service),
                    'session' => $sessionName,
                ];
            } else {
                $output = TmuxManager::capture($sessionName, 50);
                TmuxManager::kill($sessionName);

                $service->status = 'error';
                $service->error_message = "Health check failed. Server output:\n" . $output;
                Bean::store($service);

                throw new \RuntimeException('Health check failed after ' . self::HEALTH_CHECK_TIMEOUT . ' seconds');
            }

        } catch (\Throwable $e) {
            $service->status = 'error';
            $service->error_message = $e->getMessage();
            $service->updated_at = date('Y-m-d H:i:s');
            Bean::store($service);

            $this->log('error', "Failed to start {$serviceType}: {$service->name}", [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Stop a running service
     */
    public function stop(int $serviceId): array
    {
        $service = Bean::load('tenantapps', $serviceId);
        if (!$service || !$service->id) {
            throw new \RuntimeException("Service not found: {$serviceId}");
        }

        $sessionName = $this->buildSessionName($service);

        // Send Ctrl+C for graceful shutdown
        if (TmuxManager::exists($sessionName)) {
            TmuxManager::sendKeys($sessionName, 'C-c');
            usleep(2000000); // Wait 2 seconds

            if (TmuxManager::exists($sessionName)) {
                TmuxManager::kill($sessionName);
            }
        }

        $service->status = 'stopped';
        $service->tmux_session = null;
        $service->pid = null;
        $service->last_stopped_at = date('Y-m-d H:i:s');
        $service->updated_at = date('Y-m-d H:i:s');
        Bean::store($service);

        $this->log('info', "Stopped service: {$service->name}", ['service_id' => $service->id]);

        return ['success' => true, 'status' => 'stopped'];
    }

    /**
     * Restart a service
     */
    public function restart(int $serviceId): array
    {
        $this->stop($serviceId);
        usleep(1000000);
        return $this->start($serviceId);
    }

    /**
     * Get service status with zombie detection
     */
    public function status(int $serviceId): array
    {
        $service = Bean::load('tenantapps', $serviceId);
        if (!$service || !$service->id) {
            throw new \RuntimeException("Service not found: {$serviceId}");
        }

        $sessionName = $this->buildSessionName($service);
        $sessionExists = TmuxManager::exists($sessionName);

        // Detect zombie state
        if ($service->status === 'running' && !$sessionExists) {
            $service->status = 'crashed';
            $service->error_message = 'Session terminated unexpectedly';
            $service->updated_at = date('Y-m-d H:i:s');
            Bean::store($service);
        }

        $healthy = false;
        if ($service->status === 'running' && $service->port) {
            $healthy = $this->checkHealth($service);
            $service->last_health_check = date('Y-m-d H:i:s');
            Bean::store($service);
        }

        return [
            'service_id' => $service->id,
            'name' => $service->name,
            'slug' => $service->slug,
            'service_type' => $service->service_type ?? 'app',
            'status' => $service->status,
            'port' => $service->port,
            'url' => $service->status === 'running' ? $this->getUrl($service) : null,
            'session_exists' => $sessionExists,
            'healthy' => $healthy,
            'pid' => $service->pid,
            'last_started_at' => $service->last_started_at,
            'last_stopped_at' => $service->last_stopped_at,
            'last_health_check' => $service->last_health_check,
            'error_message' => $service->error_message,
            'auto_restart' => (bool) $service->auto_restart,
        ];
    }

    /**
     * Get service logs
     */
    public function getLogs(int $serviceId, int $lines = 100): array
    {
        $service = Bean::load('tenantapps', $serviceId);
        if (!$service || !$service->id) {
            throw new \RuntimeException("Service not found: {$serviceId}");
        }

        $logs = [];
        $sessionName = $this->buildSessionName($service);

        if (TmuxManager::exists($sessionName)) {
            $tmuxOutput = TmuxManager::capture($sessionName, $lines);
            if ($tmuxOutput) {
                $logs['tmux'] = explode("\n", $tmuxOutput);
            }
        }

        $logSlug = ($service->service_type ?? 'app') === 'engine' ? 'pipeline-engine' : $service->slug;
        $logger = new TenantAppLogger($service->workspace, $logSlug);
        $fileLogs = $logger->getRecentLogs($lines);
        if (!empty($fileLogs)) {
            $logs['file'] = $fileLogs;
        }

        return [
            'service_id' => $service->id,
            'name' => $service->name,
            'status' => $service->status,
            'logs' => $logs,
        ];
    }

    /**
     * List all services for a workspace, optionally filtered by type
     */
    public function listServices(string $workspace, ?string $serviceType = null): array
    {
        $sql = ' workspace = ? ';
        $params = [$workspace];

        if ($serviceType) {
            $sql .= ' AND service_type = ? ';
            $params[] = $serviceType;
        }

        $sql .= ' ORDER BY name ASC ';

        $services = Bean::find('tenantapps', $sql, $params);
        $result = [];

        foreach ($services as $service) {
            $result[] = [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
                'service_type' => $service->service_type ?? 'app',
                'status' => $service->status,
                'port' => $service->port,
                'url' => $service->status === 'running' ? $this->getUrl($service) : null,
                'last_started_at' => $service->last_started_at,
                'created_at' => $service->created_at,
            ];
        }

        return $result;
    }

    /**
     * Create an engine service record for a workspace (if one doesn't exist)
     */
    public function createEngine(string $workspace, ?int $memberId = null): \RedBeanPHP\OODBBean
    {
        // Check for existing engine
        $existing = Bean::findOne('tenantapps',
            ' workspace = ? AND service_type = ? ',
            [$workspace, 'engine']
        );

        if ($existing) {
            return $existing;
        }

        $engine = Bean::dispense('tenantapps');
        $engine->workspace = $workspace;
        $engine->slug = 'pipeline-engine';
        $engine->name = 'Pipeline Engine';
        $engine->description = 'Persistent pipeline execution service';
        $engine->app_type = 'engine';
        $engine->service_type = 'engine';
        $engine->auth_type = 'token';
        $engine->api_key = 'eng_' . bin2hex(random_bytes(16));
        $engine->config_json = json_encode(['workers' => 4]);
        $engine->routes_json = '[]';
        $engine->health_check_path = '/health';
        $engine->status = 'stopped';
        $engine->member_id = $memberId;
        $engine->created_at = date('Y-m-d H:i:s');
        $engine->updated_at = date('Y-m-d H:i:s');

        Bean::store($engine);

        $this->log('info', "Created pipeline engine service", [
            'workspace' => $workspace,
            'service_id' => $engine->id,
        ]);

        return $engine;
    }

    /**
     * Build service directory based on type
     */
    private function buildService($service, string $serviceType): string
    {
        if ($serviceType === 'engine') {
            return $this->engineBuilder->build($service->workspace, $service->id);
        }

        return $this->appBuilder->build($service->id);
    }

    /**
     * Build tmux session name for a service
     */
    private function buildSessionName($service): string
    {
        $serviceType = $service->service_type ?? 'app';
        $prefix = $serviceType === 'engine' ? self::ENGINE_SESSION_PREFIX : self::SESSION_PREFIX;

        $safeWorkspace = TmuxManager::sanitizeForSessionName($service->workspace);
        $safeSlug = TmuxManager::sanitizeForSessionName($service->slug);

        return "{$prefix}-{$safeWorkspace}-{$safeSlug}";
    }

    /**
     * Get service URL
     */
    private function getUrl($service): ?string
    {
        if (!$service->port) {
            return null;
        }
        return "http://127.0.0.1:{$service->port}";
    }

    /**
     * Wait for health check to pass
     */
    private function waitForHealth($service, int $timeout): bool
    {
        $start = time();
        while ((time() - $start) < $timeout) {
            if ($this->checkHealth($service)) {
                return true;
            }
            usleep(500000);
        }
        return false;
    }

    /**
     * Check health endpoint
     */
    private function checkHealth($service): bool
    {
        if (!$service->port) {
            return false;
        }

        $url = "http://127.0.0.1:{$service->port}" . ($service->health_check_path ?: '/health');

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

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message, $context);
        }
    }
}
