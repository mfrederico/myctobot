#!/usr/bin/env php
<?php
/**
 * MyCTOBot Agent MCP Server
 *
 * A dynamic MCP server that exposes agent-defined tools.
 * Routes through HTTP API to maintain tenant affinity.
 *
 * Usage:
 *   MYCTOBOT_API_URL=https://myctobot.ai MYCTOBOT_API_KEY=your-key php mcp-agent-server.php
 *
 * MCP Config:
 *   {
 *     "mcpServers": {
 *       "myctobot-agents": {
 *         "type": "stdio",
 *         "command": "php",
 *         "args": ["/path/to/mcp-agent-server.php"],
 *         "env": {
 *           "MYCTOBOT_API_URL": "https://myctobot.ai",
 *           "MYCTOBOT_API_KEY": "your-api-key"
 *         }
 *       }
 *     }
 *   }
 */

class McpAgentServer {
    private string $apiUrl;
    private string $apiKey;
    private array $toolsCache = [];
    private int $cacheExpiry = 0;
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct() {
        $this->apiUrl = rtrim(getenv('MYCTOBOT_API_URL') ?: 'https://myctobot.ai', '/');
        $this->apiKey = getenv('MYCTOBOT_API_KEY') ?: '';

        if (empty($this->apiKey)) {
            $this->logError('MYCTOBOT_API_KEY environment variable is required');
        }
    }

    /**
     * Main loop - read JSON-RPC from stdin, write to stdout
     */
    public function run(): void {
        stream_set_blocking(STDIN, false);

        while (true) {
            $line = fgets(STDIN);
            if ($line === false) {
                // Check for EOF
                if (feof(STDIN)) {
                    break;
                }
                usleep(10000); // 10ms
                continue;
            }

            $line = trim($line);
            if (empty($line)) continue;

            $request = json_decode($line, true);
            if (!$request) {
                $this->logError('Invalid JSON: ' . $line);
                continue;
            }

            $response = $this->handleRequest($request);
            if ($response !== null) {
                echo json_encode($response) . "\n";
                flush();
            }
        }
    }

    /**
     * Handle a JSON-RPC request
     */
    private function handleRequest(array $request): ?array {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;

        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($id, $request['params'] ?? []);

            case 'tools/list':
                return $this->handleToolsList($id);

            case 'tools/call':
                return $this->handleToolsCall($id, $request['params'] ?? []);

            case 'notifications/initialized':
            case 'notifications/cancelled':
                // Notifications don't need responses
                return null;

            default:
                return $this->errorResponse($id, -32601, "Method not found: {$method}");
        }
    }

    /**
     * Handle initialize request
     */
    private function handleInitialize($id, array $params): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => ['listChanged' => true]
                ],
                'serverInfo' => [
                    'name' => 'myctobot-agents',
                    'version' => '1.0.0'
                ]
            ]
        ];
    }

    /**
     * Handle tools/list - fetch from API
     */
    private function handleToolsList($id): array {
        // Check cache
        if ($this->cacheExpiry > time() && !empty($this->toolsCache)) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => ['tools' => $this->toolsCache]
            ];
        }

        // Fetch from API
        $result = $this->apiRequest('GET', '/api/mcp/tools');

        if (!$result['success']) {
            return $this->errorResponse($id, -32000, 'Failed to fetch tools: ' . ($result['message'] ?? 'Unknown error'));
        }

        $tools = [];
        foreach ($result['data']['tools'] ?? [] as $tool) {
            $tools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []]
            ];
        }

        // Update cache
        $this->toolsCache = $tools;
        $this->cacheExpiry = time() + self::CACHE_TTL;

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools]
        ];
    }

    /**
     * Handle tools/call - execute via API
     */
    private function handleToolsCall($id, array $params): array {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            return $this->errorResponse($id, -32602, 'Tool name is required');
        }

        // Call API
        $result = $this->apiRequest('POST', '/api/mcp/call', [
            'tool_name' => $toolName,
            'arguments' => $arguments
        ]);

        if (!$result['success']) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Error: ' . ($result['message'] ?? 'Tool execution failed')
                        ]
                    ],
                    'isError' => true
                ]
            ];
        }

        $responseText = $result['data']['result'] ?? json_encode($result['data']);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $responseText
                    ]
                ]
            ]
        ];
    }

    /**
     * Make API request to myctobot
     */
    private function apiRequest(string $method, string $endpoint, ?array $data = null): array {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 120
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => 'Request failed: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!$decoded) {
            return ['success' => false, 'message' => 'Invalid JSON response'];
        }

        return $decoded;
    }

    /**
     * Create JSON-RPC error response
     */
    private function errorResponse($id, int $code, string $message): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }

    /**
     * Log error to stderr
     */
    private function logError(string $message): void {
        fwrite(STDERR, "[MCP Agent Server] ERROR: {$message}\n");
    }
}

// Run the server
$server = new McpAgentServer();
$server->run();
