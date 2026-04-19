<?php
/**
 * PipelineEngineClient - HTTP client to communicate with a persistent Pipeline Engine
 *
 * Discovers engine port from tenantapps table and sends requests to the
 * OpenSwoole-based pipeline engine service.
 */

namespace app\services;

use app\Bean;

class PipelineEngineClient
{
    private string $workspace;
    private ?int $port = null;
    private ?string $engineToken = null;

    /** @var int Connection timeout in seconds */
    private const CONNECT_TIMEOUT = 2;

    /** @var int Read timeout for sync execution in seconds */
    private const SYNC_TIMEOUT = 120;

    /** @var int Read timeout for async dispatch in seconds */
    private const ASYNC_TIMEOUT = 5;

    public function __construct(string $workspace)
    {
        $this->workspace = $workspace;
    }

    /**
     * Check if an engine is available for this workspace
     */
    public function isAvailable(): bool
    {
        $this->discoverEngine();

        if (!$this->port) {
            return false;
        }

        // Quick health check
        $result = $this->httpGet('/health', self::CONNECT_TIMEOUT);
        return $result !== null && ($result['status'] ?? '') === 'ok';
    }

    /**
     * Dispatch a pipeline run to the engine
     *
     * @param int $runId The pipeline run ID (must already exist in DB)
     * @param bool $sync If true, wait for completion
     * @return array Result with success, status, output
     */
    public function run(int $runId, bool $sync = false): array
    {
        $timeout = $sync ? self::SYNC_TIMEOUT : self::ASYNC_TIMEOUT;

        return $this->httpPost('/run', [
            'run_id' => $runId,
            'sync' => $sync,
        ], $timeout);
    }

    /**
     * Resume a paused pipeline (from await_input)
     *
     * @param int $runId The pipeline run ID
     * @param array $input The input data to resume with
     * @param string $inputToken The await input token
     * @param string $source Source identifier (api, form, mcp, etc.)
     * @return array Result with success, status
     */
    public function resume(int $runId, array $input, string $inputToken, string $source = 'api'): array
    {
        return $this->httpPost('/resume', [
            'run_id' => $runId,
            'input' => $input,
            'input_token' => $inputToken,
            'source' => $source,
        ], self::SYNC_TIMEOUT);
    }

    /**
     * Get engine status (active runs, health)
     */
    public function status(): ?array
    {
        return $this->httpGet('/status', self::CONNECT_TIMEOUT);
    }

    /**
     * Get the engine port (for diagnostics)
     */
    public function getPort(): ?int
    {
        $this->discoverEngine();
        return $this->port;
    }

    /**
     * Discover the engine service for this workspace from the database
     */
    private function discoverEngine(): void
    {
        if ($this->port !== null) {
            return;
        }

        $engine = Bean::findOne(
            'tenantapps',
            ' workspace = ? AND service_type = ? AND status = ? ',
            [$this->workspace, 'engine', 'running']
        );

        if ($engine && $engine->port) {
            $this->port = (int) $engine->port;
            $this->engineToken = $engine->api_key;
        }
    }

    /**
     * Send HTTP GET to the engine
     */
    private function httpGet(string $path, int $timeout): ?array
    {
        if (!$this->port) {
            return null;
        }

        $url = "http://127.0.0.1:{$this->port}{$path}";

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => $this->buildHeaders(),
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        return json_decode($response, true) ?: null;
    }

    /**
     * Send HTTP POST to the engine
     */
    private function httpPost(string $path, array $data, int $timeout): array
    {
        if (!$this->port) {
            return ['success' => false, 'error' => 'Engine not discovered'];
        }

        $url = "http://127.0.0.1:{$this->port}{$path}";
        $body = json_encode($data);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'header' => $this->buildHeaders() . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body),
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return ['success' => false, 'error' => 'Engine connection failed'];
        }

        $result = json_decode($response, true);
        if ($result === null) {
            return ['success' => false, 'error' => 'Invalid engine response'];
        }

        return $result;
    }

    /**
     * Build auth headers for engine requests
     */
    private function buildHeaders(): string
    {
        $headers = "Accept: application/json";

        if ($this->engineToken) {
            $headers .= "\r\nX-Engine-Token: {$this->engineToken}";
        }

        return $headers;
    }
}
