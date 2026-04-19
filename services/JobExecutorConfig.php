<?php
/**
 * Job Executor Configuration Service
 *
 * Handles configuration hierarchy for job-executor service:
 * 1. Load base config from conf/jobexecutor.ini (public defaults)
 * 2. Override with workspace-specific values from conf/config.{workspace}.ini [jobexecutor] section
 * 3. Fall back to Flight::get() for legacy config support
 */

namespace app\services;

use \Flight as Flight;

class JobExecutorConfig {

    private static ?array $configCache = null;

    /**
     * Get job-executor configuration with hierarchy:
     * 1. Load base config from conf/jobexecutor.ini (public defaults)
     * 2. Override with workspace-specific values from conf/config.{workspace}.ini [jobexecutor] section
     * 3. Fall back to Flight::get() for legacy config support
     *
     * @param string|null $workspaceSlug Optional workspace slug (uses session if not provided)
     * @return array Job-executor configuration
     */
    public static function getConfig(?string $workspaceSlug = null): array {
        // Use provided workspace or get from session
        $workspace = $workspaceSlug ?? ($_SESSION['workspace_slug'] ?? null);

        // Cache key includes workspace for multi-workspace support
        $cacheKey = $workspace ?? 'default';

        if (self::$configCache !== null && isset(self::$configCache[$cacheKey])) {
            return self::$configCache[$cacheKey];
        }

        $config = [
            'url' => null,
            'timeout' => 30,
            'enabled' => true,
            'verify_ssl' => true,
        ];

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

        // 1. Load base config from conf/jobexecutor.ini (public defaults)
        $jobexecutorIni = $basePath . '/conf/jobexecutor.ini';
        if (file_exists($jobexecutorIni)) {
            $baseConfig = parse_ini_file($jobexecutorIni, true);
            if ($baseConfig && isset($baseConfig['jobexecutor'])) {
                foreach ($baseConfig['jobexecutor'] as $key => $value) {
                    // Note: For booleans, INI 'false' becomes '' - handled by Flight::isOn() later
                    if ($value !== null) {
                        $config[$key] = $value;
                    }
                }
            }
        }

        // 2. Override with workspace-specific config (workspace values take priority)
        if ($workspace && $workspace !== 'default') {
            $workspaceIni = $basePath . "/conf/config.{$workspace}.ini";
            if (file_exists($workspaceIni)) {
                $workspaceConfig = parse_ini_file($workspaceIni, true);
                if ($workspaceConfig && isset($workspaceConfig['jobexecutor'])) {
                    foreach ($workspaceConfig['jobexecutor'] as $key => $value) {
                        // Note: For booleans, INI 'false' becomes '' - handled by Flight::isOn() later
                        if ($value !== null) {
                            $config[$key] = $value;
                        }
                    }
                }
            }
        }

        // 3. Fall back to Flight::get() for legacy config support
        if (empty($config['url'])) {
            $config['url'] = Flight::get('job_executor_url') ?? 'http://localhost:8081';
        }

        // Normalize booleans using Flight::isOn() for consistent INI parsing
        $config['enabled'] = Flight::isOn($config['enabled']);
        $config['verify_ssl'] = Flight::isOn($config['verify_ssl']);

        // Normalize timeout to int
        $config['timeout'] = (int) $config['timeout'];

        // Cache the config
        if (self::$configCache === null) {
            self::$configCache = [];
        }
        self::$configCache[$cacheKey] = $config;

        return $config;
    }

    /**
     * Get the job-executor URL
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return string Job-executor URL
     */
    public static function getUrl(?string $workspaceSlug = null): string {
        return self::getConfig($workspaceSlug)['url'];
    }

    /**
     * Get the API timeout in seconds
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return int Timeout in seconds
     */
    public static function getTimeout(?string $workspaceSlug = null): int {
        return self::getConfig($workspaceSlug)['timeout'];
    }

    /**
     * Check if job-executor is enabled
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return bool Whether job-executor is enabled
     */
    public static function isEnabled(?string $workspaceSlug = null): bool {
        return self::getConfig($workspaceSlug)['enabled'];
    }

    /**
     * Check if SSL verification should be performed
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return bool Whether to verify SSL certificates
     */
    public static function shouldVerifySsl(?string $workspaceSlug = null): bool {
        return self::getConfig($workspaceSlug)['verify_ssl'];
    }

    /**
     * Clear the config cache (useful when switching workspaces)
     */
    public static function clearCache(): void {
        self::$configCache = null;
    }

    /**
     * Submit a test job to job-executor
     *
     * Creates an aidevjobs record and submits it to job-executor via PING.
     * Used by both Agent Test and Workstation Test endpoints.
     *
     * @param int $agentId Agent ID
     * @param int $workstationId Workstation/Runner ID
     * @param int $memberId Member ID
     * @param string|null $workspaceSlug Optional workspace slug
     * @return array Result with success, test_id, job_uid, agent, workstation info
     */
    public static function submitTestJob(int $agentId, int $workstationId, int $memberId, ?string $workspaceSlug = null): array {
        require_once __DIR__ . '/RunnerService.php';
        require_once __DIR__ . '/SiteConfig.php';

        $workspace = $workspaceSlug ?? ($_SESSION['workspace_slug'] ?? 'default');

        // Load agent
        $agent = \app\Bean::load('aiagents', $agentId);
        if (!$agent || !$agent->id || !$agent->is_active) {
            return ['success' => false, 'error' => 'Agent not found or inactive'];
        }

        // Load workstation
        $runner = RunnerService::getRunner($workstationId);
        if (!$runner) {
            return ['success' => false, 'error' => 'Workstation not found'];
        }

        $testId = 'at-' . bin2hex(random_bytes(8));
        $jobUid = 'test-' . $workspace . '-' . $testId;

        try {
            // Create test job in aidevjobs
            $job = \app\Bean::dispense('aidevjobs');
            $job->job_uid = $jobUid;
            $job->member_id = $memberId;
            $job->boards_id = null;
            $job->repoconnections_id = null;
            $job->runners_id = $workstationId;
            $job->aiagents_id = $agentId;
            $job->issue_key = 'TEST-AGENT-001';
            $job->status = 'pending';
            $job->phase = 'agent-test';
            $job->prompt = 'Hello! Please respond with a brief greeting to confirm you are working. Include the current directory path in your response. After responding, call the `job_complete` MCP tool with success=true and summary="Test completed successfully" to mark this test as complete.';
            $job->created_at = date('Y-m-d H:i:s');

            // Start with agent's existing MCP config and add myctobot jobs server
            $mcpConfig = json_decode($agent->mcp_config ?: '{"mcpServers":{}}', true);
            if (!isset($mcpConfig['mcpServers'])) {
                $mcpConfig['mcpServers'] = [];
            }
            // Add myctobot jobs server for test completion
            $mcpConfig['mcpServers']['myctobot'] = [
                'type' => 'http',
                'url' => SiteConfig::getWorkspaceUrl($workspace) . '/mcp/jobs',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($memberId . ':' . $jobUid)
                ]
            ];
            // Add pipelines server with X-Workspace header
            if (!isset($mcpConfig['mcpServers']['pipelines'])) {
                $member = \app\Bean::load('member', (int)$memberId);
                $apiToken = ($member && $member->id) ? ($member->api_token ?? '') : '';
                $mcpConfig['mcpServers']['pipelines'] = [
                    'type' => 'http',
                    'url' => SiteConfig::getWorkspaceUrl($workspace) . '/mcp/pipelines',
                    'headers' => [
                        'Authorization' => "Bearer {$apiToken}",
                        'X-Workspace' => $workspace,
                    ]
                ];
            }
            $job->mcp_config_json = json_encode($mcpConfig);

            $jobId = \app\Bean::store($job);

            \Flight::get('log')->info('Created agent test job', [
                'job_uid' => $jobUid,
                'job_id' => $jobId,
                'agent_id' => $agentId,
                'runner_id' => $workstationId,
                'workspace' => $workspace,
            ]);

            // Build workstation JSON for job-dispatcher
            $workstationJson = json_encode([
                'host' => $runner['host'],
                'user' => $runner['ssh_user'] ?? 'claudeuser',
                'port' => (int) ($runner['ssh_port'] ?? 22),
                'sshkey_id' => (int) ($runner['sshkey_id'] ?? 0),
            ]);

            // Spawn job-dispatcher.php in background
            $scriptPath = dirname(__DIR__) . '/scripts/job-dispatcher.php';
            $logPath = dirname(__DIR__) . '/log/agent-test-' . date('Y-m-d') . '.log';

            $cmd = sprintf(
                'nohup php %s --issue=%s --member=%d --workspace=%s --job-id=%d --workstation=%s --skip-jira >> %s 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($job->issue_key),
                $memberId,
                escapeshellarg($workspace),
                $jobId,
                escapeshellarg($workstationJson),
                escapeshellarg($logPath)
            );

            exec($cmd);

            \Flight::get('log')->info('Spawned job-dispatcher for agent test', [
                'job_uid' => $jobUid,
                'cmd' => $cmd,
            ]);

            // Parse provider config for display
            $providerConfig = json_decode($agent->provider_config ?: '{}', true);

            return [
                'success' => true,
                'test_id' => $testId,
                'job_uid' => $jobUid,
                'status' => 'started',
                'message' => 'Agent test started via job-dispatcher',
                'agent' => [
                    'name' => $agent->name,
                    'provider' => $agent->provider ?: 'claude_cli',
                    'use_ollama' => !empty($providerConfig['use_ollama']) || ($agent->provider === 'ollama'),
                    'ollama_model' => $providerConfig['ollama_model'] ?? $providerConfig['model'] ?? '',
                    'anthropic_model' => $providerConfig['model'] ?? 'sonnet',
                ],
                'workstation' => [
                    'name' => $runner['name'],
                    'host' => $runner['host'],
                    'ssh_user' => $runner['ssh_user'] ?? 'claudeuser',
                ],
            ];

        } catch (\Exception $e) {
            \Flight::get('log')->error('Agent test submission failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send /exit command to job-executor to stop a tmux session
     *
     * @param string $jobUid The job UID
     * @param string|null $workspaceSlug Optional workspace slug
     * @return array Result with success/error
     */
    public static function sendExit(string $jobUid, ?string $workspaceSlug = null): array {
        $workspace = $workspaceSlug ?? ($_SESSION['workspace_slug'] ?? 'default');
        $jobExecutorUrl = self::getUrl($workspace);
        $verifySsl = self::shouldVerifySsl($workspace);

        $exitUrl = rtrim($jobExecutorUrl, '/') . '/api/jobs/exit';

        $ch = curl_init($exitUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'workspace' => $workspace,
                'job_uid' => $jobUid,
            ]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => $curlError];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => "HTTP {$httpCode}"];
        }

        $result = json_decode($response, true);
        return ['success' => true, 'message' => $result['message'] ?? 'Exit sent'];
    }
}
