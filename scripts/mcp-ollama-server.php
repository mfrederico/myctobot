#!/usr/bin/env php
<?php
/**
 * MCP Server for Ollama LLM Backend
 *
 * Exposes local Ollama models as MCP tools for Claude Code orchestration.
 * This enables Claude to delegate tasks to local models (cheaper, faster, private).
 *
 * Tools provided:
 * - ollama_chat: Send a chat conversation to Ollama
 * - ollama_complete: Send a completion prompt to Ollama
 * - ollama_list_models: List available Ollama models
 *
 * Usage in .mcp.json:
 * {
 *   "mcpServers": {
 *     "ollama": {
 *       "type": "stdio",
 *       "command": "php",
 *       "args": ["/path/to/mcp-ollama-server.php"],
 *       "env": {
 *         "OLLAMA_HOST": "http://localhost:11434",
 *         "OLLAMA_MODEL": "llama3.2"
 *       }
 *     }
 *   }
 * }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/mcp-ollama-server.log');

// Configuration from environment
$ollamaHost = getenv('OLLAMA_HOST') ?: 'http://localhost:11434';
$defaultModel = getenv('OLLAMA_MODEL') ?: 'llama3.2';

// Custom tools config - JSON array of tool definitions
// Each tool: {"name": "...", "description": "...", "model": "...", "system": "..."}
$customToolsJson = getenv('OLLAMA_TOOLS') ?: '';

/**
 * MCP Ollama Server
 */
class MCPOllamaServer {
    private string $host;
    private string $defaultModel;
    private array $customTools = [];
    private array $sessions = []; // In-memory session storage
    private string $sessionDir;

    public function __construct(string $host, string $defaultModel, string $customToolsJson = '') {
        $this->host = rtrim($host, '/');
        $this->defaultModel = $defaultModel;
        $this->sessionDir = getenv('OLLAMA_SESSION_DIR') ?: '/tmp/ollama-sessions';

        // Ensure session directory exists
        if (!is_dir($this->sessionDir)) {
            @mkdir($this->sessionDir, 0755, true);
        }

        // Parse custom tools from environment
        if ($customToolsJson) {
            $tools = json_decode($customToolsJson, true);
            if (is_array($tools)) {
                foreach ($tools as $tool) {
                    if (!empty($tool['name'])) {
                        $this->customTools[$tool['name']] = $tool;
                    }
                }
            }
        }
    }

    /**
     * Get or create a session
     */
    private function getSession(string $sessionId): array {
        // Try memory first
        if (isset($this->sessions[$sessionId])) {
            return $this->sessions[$sessionId];
        }

        // Try file storage
        $file = $this->sessionDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $this->sessions[$sessionId] = $data;
                return $data;
            }
        }

        // Create new session
        return [
            'id' => $sessionId,
            'messages' => [],
            'model' => $this->defaultModel,
            'created_at' => time()
        ];
    }

    /**
     * Save session to file
     */
    private function saveSession(string $sessionId, array $session): void {
        $this->sessions[$sessionId] = $session;
        $file = $this->sessionDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';
        file_put_contents($file, json_encode($session, JSON_PRETTY_PRINT));
    }

    public function run(): void {
        stream_set_blocking(STDIN, true);

        while (true) {
            $line = fgets(STDIN);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            try {
                $request = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->sendError(null, -32700, "Parse error");
                    continue;
                }

                $response = $this->handleRequest($request);
                $this->sendResponse($response);

            } catch (Exception $e) {
                error_log("MCP Ollama Server error: " . $e->getMessage());
                $this->sendError($request['id'] ?? null, -32603, $e->getMessage());
            }
        }
    }

    private function handleRequest(array $request): array {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($id, $params);

            case 'tools/list':
                return $this->handleToolsList($id);

            case 'tools/call':
                return $this->handleToolCall($id, $params);

            case 'notifications/initialized':
            case 'notifications/cancelled':
                return []; // Notifications don't need responses

            default:
                return $this->errorResponse($id, -32601, "Method not found: {$method}");
        }
    }

    private function handleInitialize(mixed $id, array $params): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => [
                    'name' => 'ollama-mcp-server',
                    'version' => '1.0.0'
                ],
                'capabilities' => [
                    'tools' => new \stdClass()
                ]
            ]
        ];
    }

    private function handleToolsList(mixed $id): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'ollama_chat',
                        'description' => "Send a chat conversation to a local Ollama model. Use this to delegate tasks to a local LLM. Default model: {$this->defaultModel}",
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'messages' => [
                                    'type' => 'array',
                                    'description' => 'Array of message objects with role (system/user/assistant) and content',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'role' => ['type' => 'string', 'enum' => ['system', 'user', 'assistant']],
                                            'content' => ['type' => 'string']
                                        ],
                                        'required' => ['role', 'content']
                                    ]
                                ],
                                'model' => [
                                    'type' => 'string',
                                    'description' => "Ollama model to use (default: {$this->defaultModel})"
                                ],
                                'temperature' => [
                                    'type' => 'number',
                                    'description' => 'Sampling temperature 0.0-2.0 (default: 0.7)'
                                ]
                            ],
                            'required' => ['messages']
                        ]
                    ],
                    [
                        'name' => 'ollama_session_chat',
                        'description' => "Chat with Ollama while maintaining conversation history. Use session_id to continue a conversation. Creates a new session if session_id doesn't exist.",
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'session_id' => [
                                    'type' => 'string',
                                    'description' => 'Session identifier for conversation continuity (e.g., "task-123" or "code-review")'
                                ],
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'The message to send (previous context is automatically included)'
                                ],
                                'system' => [
                                    'type' => 'string',
                                    'description' => 'System prompt (only used when starting a new session)'
                                ],
                                'model' => [
                                    'type' => 'string',
                                    'description' => "Ollama model to use (default: {$this->defaultModel})"
                                ]
                            ],
                            'required' => ['session_id', 'message']
                        ]
                    ],
                    [
                        'name' => 'ollama_session_info',
                        'description' => 'Get information about an Ollama session including message count and model',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'session_id' => [
                                    'type' => 'string',
                                    'description' => 'Session identifier'
                                ]
                            ],
                            'required' => ['session_id']
                        ]
                    ],
                    [
                        'name' => 'ollama_complete',
                        'description' => "Send a completion prompt to a local Ollama model. Good for code generation, text completion. Default model: {$this->defaultModel}",
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => [
                                    'type' => 'string',
                                    'description' => 'The prompt to complete'
                                ],
                                'system' => [
                                    'type' => 'string',
                                    'description' => 'Optional system prompt'
                                ],
                                'model' => [
                                    'type' => 'string',
                                    'description' => "Ollama model to use (default: {$this->defaultModel})"
                                ],
                                'temperature' => [
                                    'type' => 'number',
                                    'description' => 'Sampling temperature 0.0-2.0 (default: 0.7)'
                                ]
                            ],
                            'required' => ['prompt']
                        ]
                    ],
                    [
                        'name' => 'ollama_vision',
                        'description' => "Analyze an image using a vision-capable Ollama model (e.g., llama3.2-vision, llava). Describe what you see, extract text, identify objects, etc.",
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'image_path' => [
                                    'type' => 'string',
                                    'description' => 'Path to an image file (PNG, JPG, etc.) to analyze'
                                ],
                                'image_base64' => [
                                    'type' => 'string',
                                    'description' => 'Base64-encoded image data (alternative to image_path)'
                                ],
                                'prompt' => [
                                    'type' => 'string',
                                    'description' => 'What to analyze or ask about the image (default: "Describe this image in detail")'
                                ],
                                'model' => [
                                    'type' => 'string',
                                    'description' => "Vision model to use (default: {$this->defaultModel}). Requires a vision-capable model like llama3.2-vision or llava."
                                ]
                            ],
                            'required' => []
                        ]
                    ],
                    [
                        'name' => 'ollama_list_models',
                        'description' => 'List all available Ollama models on the server',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => []
                        ]
                    ]
                ]
            ]
        ];
    }

    private function handleToolCall(mixed $id, array $params): array {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        switch ($toolName) {
            case 'ollama_chat':
                return $this->toolOllamaChat($id, $args);

            case 'ollama_session_chat':
                return $this->toolOllamaSessionChat($id, $args);

            case 'ollama_session_info':
                return $this->toolOllamaSessionInfo($id, $args);

            case 'ollama_complete':
                return $this->toolOllamaComplete($id, $args);

            case 'ollama_vision':
                return $this->toolOllamaVision($id, $args);

            case 'ollama_list_models':
                return $this->toolOllamaListModels($id);

            default:
                return $this->toolErrorResponse($id, "Unknown tool: {$toolName}");
        }
    }

    /**
     * ollama_chat - Send chat messages to Ollama
     */
    private function toolOllamaChat(mixed $id, array $args): array {
        $messages = $args['messages'] ?? [];
        $model = $args['model'] ?? $this->defaultModel;
        $temperature = $args['temperature'] ?? 0.7;

        if (empty($messages)) {
            return $this->toolErrorResponse($id, "messages array is required");
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $temperature
            ]
        ];

        $result = $this->ollamaPost('/api/chat', $payload);

        if (!$result['success']) {
            return $this->toolErrorResponse($id, $result['error'] ?? 'Ollama request failed');
        }

        $data = json_decode($result['body'], true);
        $response = $data['message']['content'] ?? '';
        $usage = sprintf(
            "Tokens: %d prompt + %d completion",
            $data['prompt_eval_count'] ?? 0,
            $data['eval_count'] ?? 0
        );

        return $this->toolSuccessResponse($id, $response . "\n\n---\n" . $usage);
    }

    /**
     * ollama_session_chat - Chat with session continuity
     */
    private function toolOllamaSessionChat(mixed $id, array $args): array {
        $sessionId = $args['session_id'] ?? '';
        $message = $args['message'] ?? '';
        $system = $args['system'] ?? null;
        $model = $args['model'] ?? $this->defaultModel;

        if (empty($sessionId) || empty($message)) {
            return $this->toolErrorResponse($id, "session_id and message are required");
        }

        // Load or create session
        $session = $this->getSession($sessionId);
        $isNewSession = empty($session['messages']);

        // Set model for new session
        if ($isNewSession) {
            $session['model'] = $model;
        }

        // Add system prompt for new sessions
        if ($isNewSession && $system) {
            $session['messages'][] = ['role' => 'system', 'content' => $system];
        }

        // Add user message
        $session['messages'][] = ['role' => 'user', 'content' => $message];

        // Call Ollama with full conversation history
        $payload = [
            'model' => $session['model'],
            'messages' => $session['messages'],
            'stream' => false
        ];

        $result = $this->ollamaPost('/api/chat', $payload);

        if (!$result['success']) {
            return $this->toolErrorResponse($id, $result['error'] ?? 'Ollama request failed');
        }

        $data = json_decode($result['body'], true);
        $assistantMessage = $data['message']['content'] ?? '';

        // Add assistant response to session
        $session['messages'][] = ['role' => 'assistant', 'content' => $assistantMessage];
        $session['last_activity'] = time();

        // Save session
        $this->saveSession($sessionId, $session);

        $messageCount = count($session['messages']);
        $status = $isNewSession ? "New session created" : "Continued session";
        $usage = sprintf(
            "%s | Messages: %d | Tokens: %d prompt + %d completion",
            $status,
            $messageCount,
            $data['prompt_eval_count'] ?? 0,
            $data['eval_count'] ?? 0
        );

        return $this->toolSuccessResponse($id, $assistantMessage . "\n\n---\n" . $usage);
    }

    /**
     * ollama_session_info - Get session information
     */
    private function toolOllamaSessionInfo(mixed $id, array $args): array {
        $sessionId = $args['session_id'] ?? '';

        if (empty($sessionId)) {
            return $this->toolErrorResponse($id, "session_id is required");
        }

        $session = $this->getSession($sessionId);

        if (empty($session['messages'])) {
            return $this->toolSuccessResponse($id, "Session '{$sessionId}' does not exist or has no messages.");
        }

        $messageCount = count($session['messages']);
        $userMessages = count(array_filter($session['messages'], fn($m) => $m['role'] === 'user'));
        $assistantMessages = count(array_filter($session['messages'], fn($m) => $m['role'] === 'assistant'));
        $hasSystem = !empty(array_filter($session['messages'], fn($m) => $m['role'] === 'system'));

        $info = "Session: {$sessionId}\n";
        $info .= "Model: {$session['model']}\n";
        $info .= "Messages: {$messageCount} total ({$userMessages} user, {$assistantMessages} assistant" . ($hasSystem ? ", 1 system" : "") . ")\n";
        $info .= "Created: " . date('Y-m-d H:i:s', $session['created_at'] ?? time()) . "\n";
        if (!empty($session['last_activity'])) {
            $info .= "Last activity: " . date('Y-m-d H:i:s', $session['last_activity']);
        }

        return $this->toolSuccessResponse($id, $info);
    }

    /**
     * ollama_complete - Send completion prompt to Ollama
     */
    private function toolOllamaComplete(mixed $id, array $args): array {
        $prompt = $args['prompt'] ?? '';
        $system = $args['system'] ?? null;
        $model = $args['model'] ?? $this->defaultModel;
        $temperature = $args['temperature'] ?? 0.7;

        if (empty($prompt)) {
            return $this->toolErrorResponse($id, "prompt is required");
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $temperature
            ]
        ];

        if ($system) {
            $payload['system'] = $system;
        }

        $result = $this->ollamaPost('/api/generate', $payload);

        if (!$result['success']) {
            return $this->toolErrorResponse($id, $result['error'] ?? 'Ollama request failed');
        }

        $data = json_decode($result['body'], true);
        $response = $data['response'] ?? '';
        $usage = sprintf(
            "Tokens: %d prompt + %d completion (%.1fs)",
            $data['prompt_eval_count'] ?? 0,
            $data['eval_count'] ?? 0,
            ($data['total_duration'] ?? 0) / 1e9
        );

        return $this->toolSuccessResponse($id, $response . "\n\n---\n" . $usage);
    }

    /**
     * ollama_vision - Analyze an image with a vision model
     */
    private function toolOllamaVision(mixed $id, array $args): array {
        $imagePath = $args['image_path'] ?? '';
        $imageBase64 = $args['image_base64'] ?? '';
        $prompt = $args['prompt'] ?? 'Describe this image in detail';
        $model = $args['model'] ?? $this->defaultModel;

        // Get image as base64
        if ($imagePath && !$imageBase64) {
            if (!file_exists($imagePath)) {
                return $this->toolErrorResponse($id, "Image file not found: {$imagePath}");
            }
            $imageData = file_get_contents($imagePath);
            if ($imageData === false) {
                return $this->toolErrorResponse($id, "Failed to read image: {$imagePath}");
            }
            $imageBase64 = base64_encode($imageData);
        }

        if (empty($imageBase64)) {
            return $this->toolErrorResponse($id, "Either image_path or image_base64 is required");
        }

        // Ollama vision API uses the 'images' field in messages
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                    'images' => [$imageBase64]
                ]
            ],
            'stream' => false
        ];

        $result = $this->ollamaPost('/api/chat', $payload);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Ollama request failed';
            // Check for common vision model errors
            if (strpos($error, 'does not support') !== false || strpos($result['body'] ?? '', 'does not support') !== false) {
                $error .= "\n\nHint: Make sure you're using a vision-capable model (e.g., llama3.2-vision, llava, bakllava)";
            }
            return $this->toolErrorResponse($id, $error);
        }

        $data = json_decode($result['body'], true);

        // Check for model-level errors
        if (isset($data['error'])) {
            return $this->toolErrorResponse($id, $data['error'] . "\n\nHint: Use a vision-capable model like llama3.2-vision or llava");
        }

        $response = $data['message']['content'] ?? '';
        $usage = sprintf(
            "Model: %s | Tokens: %d prompt + %d completion (%.1fs)",
            $model,
            $data['prompt_eval_count'] ?? 0,
            $data['eval_count'] ?? 0,
            ($data['total_duration'] ?? 0) / 1e9
        );

        return $this->toolSuccessResponse($id, $response . "\n\n---\n" . $usage);
    }

    /**
     * ollama_list_models - List available models
     */
    private function toolOllamaListModels(mixed $id): array {
        $result = $this->ollamaGet('/api/tags');

        if (!$result['success']) {
            return $this->toolErrorResponse($id, $result['error'] ?? 'Failed to list models');
        }

        $data = json_decode($result['body'], true);
        $models = $data['models'] ?? [];

        $output = "Available Ollama models:\n\n";
        foreach ($models as $m) {
            $size = $this->formatBytes($m['size'] ?? 0);
            $output .= "- {$m['name']} ({$size})\n";
        }

        if (empty($models)) {
            $output = "No models installed. Run: ollama pull llama3.2";
        }

        return $this->toolSuccessResponse($id, $output);
    }

    // ========================================
    // HTTP Helpers
    // ========================================

    private function ollamaGet(string $path): array {
        $url = $this->host . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'body' => $body,
            'http_code' => $httpCode
        ];
    }

    private function ollamaPost(string $path, array $payload): array {
        $url = $this->host . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300, // 5 minutes for inference
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'body' => $body,
            'http_code' => $httpCode
        ];
    }

    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    // ========================================
    // Response Helpers
    // ========================================

    private function toolSuccessResponse(mixed $id, string $text): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => $text]
                ]
            ]
        ];
    }

    private function toolErrorResponse(mixed $id, string $error): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => "Error: {$error}"]
                ],
                'isError' => true
            ]
        ];
    }

    private function errorResponse(mixed $id, int $code, string $message): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }

    private function sendResponse(array $response): void {
        if (empty($response)) {
            return;
        }
        echo json_encode($response) . "\n";
        flush();
    }

    private function sendError(mixed $id, int $code, string $message): void {
        $this->sendResponse($this->errorResponse($id, $code, $message));
    }
}

// Run the server
$server = new MCPOllamaServer($ollamaHost, $defaultModel);
$server->run();
