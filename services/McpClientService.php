<?php
/**
 * MCP Client Service
 *
 * Enables MyCTOBot to connect to MCP servers as a client,
 * consuming tools exposed by Fastmcphp or other MCP-compliant servers.
 *
 * Supports:
 *   - stdio transport (local subprocess)
 *   - HTTP/SSE transport (remote servers)
 *
 * Usage:
 *   $client = new McpClientService($logger);
 *   $client->connect('stdio', ['command' => 'php /path/to/mcp-server.php']);
 *   $tools = $client->listTools();
 *   $result = $client->callTool('create_issue', ['repo' => 'my/repo', 'title' => 'Bug']);
 *   $client->disconnect();
 */

namespace app\services;

class McpClientService {

    private $logger;
    private $transport = null;
    private $transportType = null;
    private $process = null;
    private $pipes = [];
    private $requestId = 0;
    private $serverInfo = null;
    private $capabilities = null;
    private $availableTools = [];

    // SSE transport state
    private $sseEndpoint = null;
    private $sseSessionId = null;
    private $sseCurlHandle = null;
    private $sseMultiHandle = null;
    private $sseBuffer = '';
    private $sseResponses = [];

    // Protocol constants
    private const JSONRPC_VERSION = '2.0';
    private const MCP_PROTOCOL_VERSION = '2024-11-05';

    public function __construct($logger = null) {
        $this->logger = $logger;
    }

    /**
     * Connect to an MCP server
     *
     * @param string $transportType 'stdio' or 'http'
     * @param array $config Transport-specific configuration
     * @return bool Success
     */
    public function connect(string $transportType, array $config): bool {
        $this->transportType = $transportType;

        switch ($transportType) {
            case 'stdio':
                return $this->connectStdio($config);
            case 'http':
            case 'sse':
                return $this->connectHttp($config);
            default:
                $this->log('error', "Unknown transport type: {$transportType}");
                return false;
        }
    }

    /**
     * Connect via stdio transport (subprocess)
     *
     * Config options:
     *   command      - Command to start the MCP server (e.g., "php /path/to/mcp-server.php")
     *   working_dir  - Working directory (optional)
     *   env          - Environment variables (optional)
     */
    private function connectStdio(array $config): bool {
        $command = $config['command'] ?? '';
        $workingDir = $config['working_dir'] ?? '/tmp';
        $env = $config['env'] ?? null;

        if (empty($command)) {
            $this->log('error', 'No command specified for stdio transport');
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin (we write to this)
            1 => ['pipe', 'w'],  // stdout (we read from this)
            2 => ['pipe', 'w'],  // stderr
        ];

        // Parse command into array for proc_open
        // Handle both double quotes and single quotes (escapeshellarg uses single quotes on Linux)
        $cmdParts = [];
        preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/', $command, $matches);
        foreach ($matches[0] as $part) {
            $cmdParts[] = trim($part, '"\'');
        }

        $this->log('info', "Starting MCP server: {$command}");

        $this->process = @proc_open($cmdParts, $descriptors, $this->pipes, $workingDir, $env);

        if (!is_resource($this->process)) {
            $this->log('error', "Failed to start MCP server process");
            return false;
        }

        // Set non-blocking on stdout
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        // Give server time to start
        usleep(500000); // 500ms

        // Check if process is still running
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $stderr = stream_get_contents($this->pipes[2]);
            $this->log('error', "MCP server exited immediately: {$stderr}");
            $this->cleanup();
            return false;
        }

        // Initialize the connection
        if (!$this->initialize()) {
            $this->cleanup();
            return false;
        }

        return true;
    }

    /**
     * Connect via HTTP/SSE transport
     *
     * Config options:
     *   url     - Base URL of the MCP server
     *   headers - Additional headers (optional)
     *   sse     - Force SSE mode (auto-detected if not specified)
     */
    private function connectHttp(array $config): bool {
        $url = $config['url'] ?? '';

        if (empty($url)) {
            $this->log('error', 'No URL specified for HTTP transport');
            return false;
        }

        $baseUrl = rtrim($url, '/');
        $headers = $config['headers'] ?? [];
        $forceSse = $config['sse'] ?? null;

        // Auto-detect SSE by trying /sse endpoint first
        if ($forceSse === true || $forceSse === null) {
            if ($this->connectSse($baseUrl, $headers)) {
                return true;
            }
            // If SSE was forced and failed, don't fall back
            if ($forceSse === true) {
                return false;
            }
        }

        // Fall back to simple HTTP POST
        $this->transport = [
            'type' => 'http',
            'url' => $baseUrl,
            'headers' => $headers
        ];

        // For HTTP transport, initialize happens on first request
        return $this->initialize();
    }

    /**
     * Connect via SSE transport (FastMCP style)
     *
     * SSE transport flow:
     * 1. GET /sse to establish SSE stream (keep connection open)
     * 2. Receive 'endpoint' event with POST URL
     * 3. POST JSON-RPC messages to that endpoint
     * 4. Receive responses via the same SSE stream
     */
    private function connectSse(string $baseUrl, array $headers): bool {
        // Check if URL already contains /sse path (with or without query params)
        $parsedUrl = parse_url($baseUrl);
        $path = $parsedUrl['path'] ?? '';
        if (str_ends_with($path, '/sse')) {
            // URL already has /sse endpoint, use as-is
            $sseUrl = $baseUrl;
        } else {
            $sseUrl = $baseUrl . '/sse';
        }
        $this->log('info', "Attempting SSE connection to: {$sseUrl}");

        // First, do a quick probe to see if /sse exists
        $ch = curl_init($sseUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 404 || $httpCode === 0) {
            $this->log('debug', "SSE endpoint not found (HTTP {$httpCode}), falling back to HTTP");
            return false;
        }

        // Establish persistent SSE connection using curl_multi for non-blocking reads
        $this->sseCurlHandle = curl_init($sseUrl);
        curl_setopt($this->sseCurlHandle, CURLOPT_HTTPHEADER, array_merge([
            'Accept: text/event-stream',
            'Cache-Control: no-cache'
        ], $headers));
        curl_setopt($this->sseCurlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->sseCurlHandle, CURLOPT_TIMEOUT, 0); // No timeout - keep open
        curl_setopt($this->sseCurlHandle, CURLOPT_WRITEFUNCTION, [$this, 'handleSseData']);

        $this->sseMultiHandle = curl_multi_init();
        curl_multi_add_handle($this->sseMultiHandle, $this->sseCurlHandle);

        // Start the connection and wait for endpoint event
        $endpoint = null;
        $startTime = time();
        $timeout = 10;

        while (time() - $startTime < $timeout) {
            // Process curl
            $status = curl_multi_exec($this->sseMultiHandle, $active);

            // Check for endpoint in received events
            foreach ($this->sseResponses as $key => $response) {
                if (isset($response['_sse_event']) && $response['_sse_event'] === 'endpoint') {
                    $rawEndpoint = $response['_sse_data'];
                    if (strpos($rawEndpoint, '/') === 0) {
                        $endpoint = rtrim($baseUrl, '/') . $rawEndpoint;
                    } else {
                        $endpoint = $rawEndpoint;
                    }
                    unset($this->sseResponses[$key]);
                    break 2;
                }
            }

            if ($status !== CURLM_OK) {
                break;
            }

            usleep(10000); // 10ms
        }

        if (!$endpoint) {
            $this->log('debug', "Failed to get SSE endpoint within timeout");
            $this->closeSseConnection();
            return false;
        }

        // Store SSE transport info
        $this->transport = [
            'type' => 'sse',
            'base_url' => $baseUrl,
            'sse_url' => $sseUrl,
            'endpoint' => $endpoint,
            'headers' => $headers
        ];
        $this->sseEndpoint = $endpoint;

        $this->log('info', "SSE transport established, endpoint: {$endpoint}");

        // Now initialize via the SSE endpoint
        return $this->initialize();
    }

    /**
     * Handle incoming SSE data (callback for curl)
     */
    private function handleSseData($ch, $data): int {
        $this->sseBuffer .= $data;
        $this->sseBuffer = str_replace("\r\n", "\n", $this->sseBuffer);

        while (($pos = strpos($this->sseBuffer, "\n\n")) !== false) {
            $eventBlock = substr($this->sseBuffer, 0, $pos);
            $this->sseBuffer = substr($this->sseBuffer, $pos + 2);

            $event = $this->parseSseEvent($eventBlock);

            if ($event['event'] === 'endpoint') {
                // Store endpoint event specially
                $this->sseResponses[] = [
                    '_sse_event' => 'endpoint',
                    '_sse_data' => trim($event['data'])
                ];
            } elseif ($event['event'] === 'message' && !empty($event['data'])) {
                // Parse JSON-RPC response
                $response = json_decode($event['data'], true);
                if ($response) {
                    $this->sseResponses[] = $response;
                    $this->log('debug', "SSE received response ID: " . ($response['id'] ?? 'notification'));
                }
            }
        }

        return strlen($data);
    }

    /**
     * Close SSE connection
     */
    private function closeSseConnection(): void {
        if ($this->sseMultiHandle && $this->sseCurlHandle) {
            curl_multi_remove_handle($this->sseMultiHandle, $this->sseCurlHandle);
        }
        if ($this->sseCurlHandle) {
            curl_close($this->sseCurlHandle);
            $this->sseCurlHandle = null;
        }
        if ($this->sseMultiHandle) {
            curl_multi_close($this->sseMultiHandle);
            $this->sseMultiHandle = null;
        }
        $this->sseBuffer = '';
        $this->sseResponses = [];
    }

    /**
     * Parse an SSE event block
     */
    private function parseSseEvent(string $block): array {
        $event = ['event' => 'message', 'data' => '', 'id' => null];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (strpos($line, 'event:') === 0) {
                $event['event'] = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $event['data'] .= trim(substr($line, 5));
            } elseif (strpos($line, 'id:') === 0) {
                $event['id'] = trim(substr($line, 3));
            }
        }

        return $event;
    }

    /**
     * Send MCP initialize request
     */
    private function initialize(): bool {
        $response = $this->sendRequest('initialize', [
            'protocolVersion' => self::MCP_PROTOCOL_VERSION,
            'capabilities' => [
                'roots' => ['listChanged' => true]
            ],
            'clientInfo' => [
                'name' => 'MyCTOBot',
                'version' => '1.0.0'
            ]
        ]);

        if (!$response || isset($response['error'])) {
            $error = $response['error']['message'] ?? 'Unknown error';
            $this->log('error', "MCP initialize failed: {$error}");
            return false;
        }

        $this->serverInfo = $response['result']['serverInfo'] ?? null;
        $this->capabilities = $response['result']['capabilities'] ?? null;

        $this->log('info', "Connected to MCP server", [
            'server' => $this->serverInfo,
            'capabilities' => array_keys($this->capabilities ?? [])
        ]);

        // Send initialized notification
        $this->sendNotification('notifications/initialized', []);

        // Fetch available tools
        $this->refreshTools();

        return true;
    }

    /**
     * Refresh the list of available tools
     */
    public function refreshTools(): void {
        // Check if 'tools' key exists in capabilities (not if it's truthy)
        // MCP servers return "tools":{} which becomes an empty array in PHP
        if (!isset($this->capabilities['tools']) && !array_key_exists('tools', $this->capabilities ?? [])) {
            $this->log('debug', 'Server does not expose tools capability');
            return;
        }

        $response = $this->sendRequest('tools/list', []);

        if ($response && !isset($response['error'])) {
            $this->availableTools = $response['result']['tools'] ?? [];
            $this->log('info', "Loaded " . count($this->availableTools) . " tools from MCP server");
        }
    }

    /**
     * Get list of available tools
     *
     * @return array Tool definitions
     */
    public function listTools(): array {
        return $this->availableTools;
    }

    /**
     * Call a tool on the MCP server
     *
     * @param string $toolName Name of the tool to call
     * @param array $arguments Tool arguments
     * @return array Result or error
     */
    public function callTool(string $toolName, array $arguments = []): array {
        $this->log('info', "Calling MCP tool: {$toolName}", ['arguments' => $arguments]);

        $response = $this->sendRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments
        ]);

        if (!$response) {
            return [
                'success' => false,
                'error' => 'No response from MCP server'
            ];
        }

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Tool call failed',
                'code' => $response['error']['code'] ?? null
            ];
        }

        $result = $response['result'] ?? [];
        $content = $result['content'] ?? [];

        // Extract text content
        $textParts = [];
        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'text') {
                $textParts[] = $item['text'];
            }
        }

        return [
            'success' => !($result['isError'] ?? false),
            'content' => $content,
            'text' => implode("\n", $textParts),
            'isError' => $result['isError'] ?? false
        ];
    }

    /**
     * Get a resource from the MCP server
     *
     * @param string $uri Resource URI
     * @return array|null Resource content
     */
    public function readResource(string $uri): ?array {
        $response = $this->sendRequest('resources/read', [
            'uri' => $uri
        ]);

        if (!$response || isset($response['error'])) {
            return null;
        }

        return $response['result']['contents'] ?? null;
    }

    /**
     * Send a prompt to the MCP server
     *
     * @param string $promptName Prompt name
     * @param array $arguments Prompt arguments
     * @return array|null Prompt result
     */
    public function getPrompt(string $promptName, array $arguments = []): ?array {
        $response = $this->sendRequest('prompts/get', [
            'name' => $promptName,
            'arguments' => $arguments
        ]);

        if (!$response || isset($response['error'])) {
            return null;
        }

        return $response['result'] ?? null;
    }

    /**
     * Send a JSON-RPC request
     */
    private function sendRequest(string $method, array $params): ?array {
        $id = ++$this->requestId;

        $message = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $id,
            'method' => $method,
            // MCP protocol requires params to be an object, not an array
            // Empty array [] becomes {} in JSON with this cast
            'params' => empty($params) ? (object)[] : $params
        ];

        if ($this->transportType === 'stdio') {
            return $this->sendStdioRequest($message);
        } else {
            return $this->sendHttpRequest($message);
        }
    }

    /**
     * Send a notification (no response expected)
     */
    private function sendNotification(string $method, array $params): void {
        $message = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => $method,
            // MCP protocol requires params to be an object, not an array
            'params' => empty($params) ? (object)[] : $params
        ];

        if ($this->transportType === 'stdio') {
            $this->writeStdio($message);
        } else {
            $this->sendHttpRequest($message, false);
        }
    }

    /**
     * Send request via stdio transport
     */
    private function sendStdioRequest(array $message): ?array {
        if (!$this->writeStdio($message)) {
            return null;
        }

        return $this->readStdioResponse($message['id'], 30); // 30 second timeout
    }

    /**
     * Write message to stdio
     */
    private function writeStdio(array $message): bool {
        if (!is_resource($this->process)) {
            return false;
        }

        $json = json_encode($message);
        $this->log('debug', "MCP >> {$json}");

        $written = fwrite($this->pipes[0], $json . "\n");
        fflush($this->pipes[0]);

        return $written !== false;
    }

    /**
     * Read response from stdio
     */
    private function readStdioResponse(int $expectedId, int $timeout): ?array {
        $startTime = time();
        $buffer = '';

        while (time() - $startTime < $timeout) {
            // Check for stderr
            $stderr = stream_get_contents($this->pipes[2]);
            if (!empty($stderr)) {
                $this->log('warning', "MCP stderr: " . trim($stderr));
            }

            // Read from stdout
            $chunk = fread($this->pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }

            // Try to parse complete JSON messages
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // Keep incomplete line in buffer

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $this->log('debug', "MCP << {$line}");

                $response = json_decode($line, true);
                if ($response === null) {
                    continue;
                }

                // Check if this is our response
                if (isset($response['id']) && $response['id'] === $expectedId) {
                    return $response;
                }

                // Handle notifications from server
                if (!isset($response['id']) && isset($response['method'])) {
                    $this->handleServerNotification($response);
                }
            }

            usleep(10000); // 10ms
        }

        $this->log('error', "Timeout waiting for MCP response (id: {$expectedId})");
        return null;
    }

    /**
     * Send request via HTTP transport
     */
    private function sendHttpRequest(array $message, bool $expectResponse = true): ?array {
        // Determine URL based on transport type
        $isSse = ($this->transport['type'] ?? '') === 'sse';

        if ($isSse) {
            // SSE transport: POST to the endpoint discovered during SSE handshake
            $url = $this->sseEndpoint;
        } else {
            // Simple HTTP transport: POST to the configured URL
            $url = $this->transport['url'];
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json'
        ], $this->transport['headers'] ?? []);

        $this->log('debug', "HTTP POST to: {$url}");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log('error', "HTTP error: {$error}");
            return null;
        }

        if (!$expectResponse) {
            return null;
        }

        // For simple HTTP, expect direct response
        if (!$isSse) {
            if ($httpCode < 200 || $httpCode >= 300) {
                $this->log('error', "HTTP {$httpCode}: {$response}");
                return null;
            }
            return json_decode($response, true);
        }

        // For SSE transport, 202 Accepted means response comes via SSE stream
        if ($httpCode === 202 || $response === 'Accepted') {
            $expectedId = $message['id'] ?? null;
            if ($expectedId !== null) {
                return $this->pollSseResponse($expectedId, 10);
            }
        }

        // If we got a JSON response directly, use it
        $decoded = json_decode($response, true);
        if ($decoded !== null) {
            return $decoded;
        }

        $this->log('debug', "Unexpected SSE response: {$response}");
        return null;
    }

    /**
     * Wait for SSE response with the expected ID from persistent connection
     */
    private function pollSseResponse(int $expectedId, int $timeout): ?array {
        $this->log('debug', "Waiting for SSE response ID: {$expectedId}");

        $startTime = time();

        while (time() - $startTime < $timeout) {
            // Process incoming SSE data
            if ($this->sseMultiHandle) {
                curl_multi_exec($this->sseMultiHandle, $active);
            }

            // Check for matching response
            foreach ($this->sseResponses as $key => $response) {
                if (isset($response['id']) && $response['id'] === $expectedId) {
                    unset($this->sseResponses[$key]);
                    $this->log('debug', "Got SSE response for ID: {$expectedId}");
                    return $response;
                }
            }

            usleep(10000); // 10ms
        }

        $this->log('error', "SSE timeout waiting for response ID: {$expectedId}");
        return null;
    }

    /**
     * Handle a notification from the server
     */
    private function handleServerNotification(array $notification): void {
        $method = $notification['method'] ?? '';

        switch ($method) {
            case 'notifications/tools/list_changed':
                $this->log('info', 'MCP server tools changed, refreshing...');
                $this->refreshTools();
                break;

            case 'notifications/resources/list_changed':
                $this->log('info', 'MCP server resources changed');
                break;

            default:
                $this->log('debug', "Unhandled MCP notification: {$method}");
        }
    }

    /**
     * Disconnect from the MCP server
     */
    public function disconnect(): void {
        $this->cleanup();
        $this->log('info', 'Disconnected from MCP server');
    }

    /**
     * Cleanup resources
     */
    private function cleanup(): void {
        if ($this->transportType === 'stdio') {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($this->process)) {
                proc_terminate($this->process);
                proc_close($this->process);
            }
        }

        // Close SSE connection if open
        $this->closeSseConnection();

        $this->process = null;
        $this->pipes = [];
        $this->transport = null;
        $this->serverInfo = null;
        $this->capabilities = null;
        $this->availableTools = [];
        $this->sseEndpoint = null;
        $this->sseSessionId = null;
    }

    /**
     * Get server info
     */
    public function getServerInfo(): ?array {
        return $this->serverInfo;
    }

    /**
     * Get server capabilities
     */
    public function getCapabilities(): ?array {
        return $this->capabilities;
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool {
        if ($this->transportType === 'stdio') {
            if (!is_resource($this->process)) {
                return false;
            }
            $status = proc_get_status($this->process);
            return $status['running'];
        }

        return $this->transport !== null;
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[McpClient] {$message}", $context);
        } else {
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            error_log("[McpClient] [{$level}] {$message}{$contextStr}");
        }
    }

    /**
     * Destructor - ensure cleanup
     */
    public function __destruct() {
        $this->cleanup();
    }
}
