<?php
/**
 * API Controller for MCP Tool Calls
 * Provides HTTP endpoints for the MCP agent server to call
 * Handles tenant routing based on API key
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\UserDatabaseService;
use \app\services\LLMProviders\LLMProviderFactory;

require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderInterface.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/../services/LLMProviders/OllamaProvider.php';

class Api extends BaseControls\Control {

    private ?string $tenantSlug = null;
    private ?int $tenantMemberId = null;

    /**
     * Authenticate via API key
     * Returns member ID from main DB if valid, null otherwise
     * Also stores tenant slug for later database switching
     */
    private function authenticateApiKey(): ?int {
        // Check X-API-Key header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($apiKey)) {
            // Check Authorization header (Bearer token)
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
                $apiKey = $matches[1];
            }
        }

        if (empty($apiKey)) {
            return null;
        }

        // Look up member by API token in main database
        $member = R::findOne('member', 'api_token = ? AND status = ?', [$apiKey, 'active']);
        if (!$member) {
            return null;
        }

        // Store tenant slug from ceobot_db field
        $this->tenantSlug = $member->ceobot_db ?: null;

        return (int) $member->id;
    }

    /**
     * Switch to member's tenant database
     * Returns the member ID in the tenant database
     */
    private function switchToMemberDatabase(int $mainMemberId): bool {
        if (empty($this->tenantSlug)) {
            // No tenant, use main database
            $this->tenantMemberId = $mainMemberId;
            return true;
        }

        try {
            // Load tenant config
            $configFile = BASE_PATH . "/conf/config.{$this->tenantSlug}.ini";
            if (!file_exists($configFile)) {
                Flight::get('log')->error("Tenant config not found: {$configFile}");
                return false;
            }

            $tenantConfig = parse_ini_file($configFile, true);
            if (!$tenantConfig || empty($tenantConfig['database'])) {
                Flight::get('log')->error("Invalid tenant config: {$configFile}");
                return false;
            }

            // Add and switch to tenant database
            $dbConfig = $tenantConfig['database'];
            $type = $dbConfig['type'] ?? 'mysql';
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? $this->tenantSlug;
            $user = $dbConfig['user'] ?? 'root';
            $pass = $dbConfig['pass'] ?? '';
            $dsn = "{$type}:host={$host};port={$port};dbname={$name}";

            // Check if already added
            if (!R::hasDatabase($this->tenantSlug)) {
                R::addDatabase($this->tenantSlug, $dsn, $user, $pass);
            }
            R::selectDatabase($this->tenantSlug);

            // Get member from tenant by email (main member has same email)
            $mainMember = R::selectDatabase('default');
            $mainMember = R::load('member', $mainMemberId);
            R::selectDatabase($this->tenantSlug);

            $tenantMember = R::findOne('member', 'email = ?', [$mainMember->email]);
            if ($tenantMember) {
                $this->tenantMemberId = (int) $tenantMember->id;
            } else {
                $this->tenantMemberId = $mainMemberId;
            }

            Flight::get('log')->debug("Switched to tenant: {$this->tenantSlug}, member ID: {$this->tenantMemberId}");
            return true;
        } catch (Exception $e) {
            Flight::get('log')->error('Failed to switch to tenant database: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List all exposed MCP tools
     * GET /api/mcp/tools
     */
    public function mcpTools($params = []) {
        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        if (!$this->switchToMemberDatabase($memberId)) {
            Flight::jsonError('Failed to access tenant database', 500);
            return;
        }

        // Get all agents with expose_as_mcp = 1 (use tenant member ID)
        $agents = R::find('aiagents', 'member_id = ? AND expose_as_mcp = 1 AND is_active = 1', [$this->tenantMemberId]);

        $tools = [];
        foreach ($agents as $agent) {
            // Get tools for this agent
            $agentTools = R::find('agenttools', 'agent_id = ? AND is_active = 1', [$agent->id]);

            foreach ($agentTools as $tool) {
                $parametersSchema = json_decode($tool->parameters_schema ?: '[]', true);

                // Build MCP-compatible input schema
                $inputSchema = [
                    'type' => 'object',
                    'properties' => [],
                    'required' => []
                ];

                foreach ($parametersSchema as $param) {
                    $propSchema = [
                        'type' => $param['type'] ?? 'string',
                        'description' => $param['description'] ?? ''
                    ];
                    if (isset($param['default'])) {
                        $propSchema['default'] = $param['default'];
                    }

                    $inputSchema['properties'][$param['name']] = $propSchema;

                    if ($param['required'] ?? false) {
                        $inputSchema['required'][] = $param['name'];
                    }
                }

                $tools[] = [
                    'name' => $tool->tool_name,
                    'description' => $tool->tool_description ?: "Tool from agent: {$agent->name}",
                    'inputSchema' => $inputSchema,
                    '_agent_id' => (int) $agent->id,
                    '_tool_id' => (int) $tool->id
                ];
            }
        }

        Flight::jsonSuccess(['tools' => $tools]);
    }

    /**
     * Execute an MCP tool
     * POST /api/mcp/call
     */
    public function mcpCall($params = []) {
        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        if (!$this->switchToMemberDatabase($memberId)) {
            Flight::jsonError('Failed to access tenant database', 500);
            return;
        }

        // Get request body
        $rawBody = file_get_contents('php://input');
        $request = json_decode($rawBody, true);

        if (!$request) {
            // Try form data
            $toolName = $this->getParam('tool_name', '');
            $arguments = json_decode($this->getParam('arguments', '{}'), true);
        } else {
            $toolName = $request['tool_name'] ?? '';
            $arguments = $request['arguments'] ?? [];
        }

        if (empty($toolName)) {
            Flight::jsonError('tool_name is required', 400);
            return;
        }

        // Find the tool
        $tool = R::findOne('agenttools', 'tool_name = ? AND is_active = 1', [$toolName]);
        if (!$tool) {
            Flight::jsonError("Tool not found: {$toolName}", 404);
            return;
        }

        // Verify agent ownership and get agent config (use tenant member ID)
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$tool->agent_id, $this->tenantMemberId]);
        if (!$agent) {
            Flight::jsonError("Tool's agent not accessible", 403);
            return;
        }

        try {
            $result = $this->executeTool($tool, $agent, $arguments);
            Flight::jsonSuccess(['result' => $result]);
        } catch (Exception $e) {
            Flight::jsonError('Tool execution failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Execute a tool with the agent's LLM
     */
    private function executeTool($tool, $agent, array $arguments): string {
        // Build prompt from template
        $prompt = $tool->prompt_template ?: '';
        $parametersSchema = json_decode($tool->parameters_schema ?: '[]', true);

        // Replace placeholders with argument values
        foreach ($parametersSchema as $paramDef) {
            $paramName = $paramDef['name'];
            $value = $arguments[$paramName] ?? $paramDef['default'] ?? '';
            $prompt = str_replace('{' . $paramName . '}', $value, $prompt);
        }

        if (empty($prompt)) {
            throw new Exception('Empty prompt after template substitution');
        }

        // Get provider config
        $provider = $agent->provider ?: 'claude_cli';
        $providerConfig = json_decode($agent->provider_config ?: '{}', true);

        // Check for image parameter
        $imagePath = null;
        foreach ($parametersSchema as $paramDef) {
            if (in_array($paramDef['name'], ['image_path', 'image', 'file_path'])) {
                $imagePath = $arguments[$paramDef['name']] ?? null;
                break;
            }
        }

        // Execute based on provider
        if ($provider === 'claude_cli' && !empty($providerConfig['use_ollama'])) {
            // Ollama backend via Claude CLI
            $ollamaHost = $providerConfig['ollama_host'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['ollama_model'] ?? 'llama3';
            return $this->callOllama($ollamaHost, $ollamaModel, $prompt, $imagePath);
        } elseif ($provider === 'ollama') {
            // Direct Ollama
            $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['model'] ?? 'llama3';
            return $this->callOllama($ollamaHost, $ollamaModel, $prompt, $imagePath);
        } else {
            // Other providers - return prompt preview for now
            return "Prompt would be sent to {$provider}:\n\n{$prompt}";
        }
    }

    /**
     * Call Ollama API
     */
    private function callOllama(string $host, string $model, string $prompt, ?string $imagePath = null): string {
        $url = rtrim($host, '/') . '/api/chat';

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        // Add image if provided
        if ($imagePath && file_exists($imagePath)) {
            $imageData = file_get_contents($imagePath);
            if ($imageData !== false) {
                $messages[0]['images'] = [base64_encode($imageData)];
            }
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('Ollama request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception('Ollama returned HTTP ' . $httpCode . ': ' . $response);
        }

        $data = json_decode($response, true);
        if (isset($data['message']['content'])) {
            return $data['message']['content'];
        }

        return $response;
    }

    /**
     * Health check endpoint
     * GET /api/health
     */
    public function health($params = []) {
        Flight::jsonSuccess([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ]);
    }

    /**
     * MCP JSON-RPC endpoint
     * POST /api/mcp/{tenant}
     *
     * Handles MCP protocol requests from Claude Code's HTTP MCP client
     * Methods: initialize, tools/list, tools/call
     */
    public function mcpJsonRpc($tenant = null) {
        $urlTenant = $tenant;
        if (empty($urlTenant)) {
            $this->jsonRpcError(-32600, 'Tenant required in URL', null);
            return;
        }

        // Read JSON-RPC request
        $rawBody = file_get_contents('php://input');
        $request = json_decode($rawBody, true);

        if (!$request || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            $this->jsonRpcError(-32600, 'Invalid JSON-RPC request', null);
            return;
        }

        $method = $request['method'] ?? '';
        $rpcParams = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        Flight::get('log')->debug("MCP JSON-RPC: method={$method}, tenant={$urlTenant}");

        // Handle methods
        switch ($method) {
            case 'initialize':
                $this->mcpInitialize($rpcParams, $id);
                break;

            case 'notifications/initialized':
                // Client confirmation - just acknowledge
                $this->jsonRpcResult(['acknowledged' => true], $id);
                break;

            case 'tools/list':
                $this->mcpToolsList($urlTenant, $id);
                break;

            case 'tools/call':
                $this->mcpToolsCall($urlTenant, $rpcParams, $id);
                break;

            default:
                $this->jsonRpcError(-32601, "Method not found: {$method}", $id);
        }
    }

    /**
     * Handle MCP initialize request
     */
    private function mcpInitialize(array $params, $id) {
        $this->jsonRpcResult([
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false]
            ],
            'serverInfo' => [
                'name' => 'myctobot-mcp',
                'version' => '1.0.0'
            ]
        ], $id);
    }

    /**
     * Handle MCP tools/list request
     */
    private function mcpToolsList(string $urlTenant, $id) {
        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            $this->jsonRpcError(-32000, 'Unauthorized - valid API key required', $id);
            return;
        }

        // Validate URL tenant matches API key tenant
        if ($this->tenantSlug !== $urlTenant) {
            $this->jsonRpcError(-32000, "Tenant mismatch: API key belongs to '{$this->tenantSlug}', URL specifies '{$urlTenant}'", $id);
            return;
        }

        if (!$this->switchToMemberDatabase($memberId)) {
            $this->jsonRpcError(-32000, 'Failed to access tenant database', $id);
            return;
        }

        // Get all agents with expose_as_mcp = 1
        $agents = R::find('aiagents', 'member_id = ? AND expose_as_mcp = 1 AND is_active = 1', [$this->tenantMemberId]);

        $tools = [];
        foreach ($agents as $agent) {
            $agentTools = R::find('agenttools', 'agent_id = ? AND is_active = 1', [$agent->id]);

            foreach ($agentTools as $tool) {
                $parametersSchema = json_decode($tool->parameters_schema ?: '[]', true);

                // Build MCP-compatible input schema
                $inputSchema = [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => []
                ];

                foreach ($parametersSchema as $param) {
                    $propSchema = [
                        'type' => $param['type'] ?? 'string',
                        'description' => $param['description'] ?? ''
                    ];

                    $inputSchema['properties']->{$param['name']} = $propSchema;

                    if ($param['required'] ?? false) {
                        $inputSchema['required'][] = $param['name'];
                    }
                }

                $tools[] = [
                    'name' => $tool->tool_name,
                    'description' => $tool->tool_description ?: "Tool from agent: {$agent->name}",
                    'inputSchema' => $inputSchema
                ];
            }
        }

        $this->jsonRpcResult(['tools' => $tools], $id);
    }

    /**
     * Handle MCP tools/call request
     */
    private function mcpToolsCall(string $urlTenant, array $params, $id) {
        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            $this->jsonRpcError(-32000, 'Unauthorized - valid API key required', $id);
            return;
        }

        if ($this->tenantSlug !== $urlTenant) {
            $this->jsonRpcError(-32000, "Tenant mismatch", $id);
            return;
        }

        if (!$this->switchToMemberDatabase($memberId)) {
            $this->jsonRpcError(-32000, 'Failed to access tenant database', $id);
            return;
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            $this->jsonRpcError(-32602, 'Tool name is required', $id);
            return;
        }

        // Find the tool
        $tool = R::findOne('agenttools', 'tool_name = ? AND is_active = 1', [$toolName]);
        if (!$tool) {
            $this->jsonRpcError(-32602, "Tool not found: {$toolName}", $id);
            return;
        }

        // Verify agent ownership
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$tool->agent_id, $this->tenantMemberId]);
        if (!$agent) {
            $this->jsonRpcError(-32000, "Tool's agent not accessible", $id);
            return;
        }

        try {
            $result = $this->executeTool($tool, $agent, $arguments);
            $this->jsonRpcResult([
                'content' => [
                    ['type' => 'text', 'text' => $result]
                ]
            ], $id);
        } catch (Exception $e) {
            $this->jsonRpcError(-32000, 'Tool execution failed: ' . $e->getMessage(), $id);
        }
    }

    /**
     * Send JSON-RPC success response
     */
    private function jsonRpcResult($result, $id) {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ]);
        exit;
    }

    /**
     * Send JSON-RPC error response
     */
    private function jsonRpcError(int $code, string $message, $id) {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'id' => $id
        ]);
        exit;
    }

    /**
     * List MCP tools with tenant validation from URL
     * GET /api/mcp/{tenant}/tools
     */
    public function mcpToolsWithTenant($tenant = null) {
        $urlTenant = $tenant;
        if (empty($urlTenant)) {
            Flight::jsonError('Tenant required in URL', 400);
            return;
        }

        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        // Validate URL tenant matches API key tenant
        if ($this->tenantSlug !== $urlTenant) {
            Flight::jsonError("Tenant mismatch: API key belongs to '{$this->tenantSlug}', URL specifies '{$urlTenant}'", 403);
            return;
        }

        // Delegate to standard mcpTools
        $this->mcpTools([]);
    }

    /**
     * Execute MCP tool with tenant validation from URL
     * POST /api/mcp/{tenant}/call
     */
    public function mcpCallWithTenant($tenant = null) {
        $urlTenant = $tenant;
        if (empty($urlTenant)) {
            Flight::jsonError('Tenant required in URL', 400);
            return;
        }

        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        // Validate URL tenant matches API key tenant
        if ($this->tenantSlug !== $urlTenant) {
            Flight::jsonError("Tenant mismatch: API key belongs to '{$this->tenantSlug}', URL specifies '{$urlTenant}'", 403);
            return;
        }

        // Delegate to standard mcpCall
        $this->mcpCall([]);
    }

    /**
     * Get MCP configuration for an agent
     * GET /api/mcp/{tenant}/config/{agentId}
     *
     * Returns a ready-to-use .mcp.json configuration for the specified agent
     */
    public function mcpConfig($tenant = null, $agentId = 0) {
        $urlTenant = $tenant;
        $agentId = (int) $agentId;

        if (empty($urlTenant)) {
            Flight::jsonError('Tenant required in URL', 400);
            return;
        }

        if ($agentId <= 0) {
            Flight::jsonError('Agent ID required in URL', 400);
            return;
        }

        $memberId = $this->authenticateApiKey();
        if (!$memberId) {
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        // Validate URL tenant matches API key tenant
        if ($this->tenantSlug !== $urlTenant) {
            Flight::jsonError("Tenant mismatch: API key belongs to '{$this->tenantSlug}', URL specifies '{$urlTenant}'", 403);
            return;
        }

        if (!$this->switchToMemberDatabase($memberId)) {
            Flight::jsonError('Failed to access tenant database', 500);
            return;
        }

        // Get the agent
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$agentId, $this->tenantMemberId]);

        if (!$agent) {
            Flight::jsonError('Agent not found or not exposed as MCP', 404);
            return;
        }

        // Get tools for this agent
        $tools = R::find('agenttools', 'agent_id = ? AND is_active = 1', [$agentId]);

        // Build tool descriptions for the config
        $toolDescriptions = [];
        foreach ($tools as $tool) {
            $toolDescriptions[] = $tool->tool_name;
        }

        // Get base URL from config
        $baseUrl = Flight::get('app.baseurl') ?: 'https://myctobot.ai';
        // Ensure HTTPS in production
        if (strpos($baseUrl, 'localhost') === false && strpos($baseUrl, '127.0.0.1') === false) {
            $baseUrl = preg_replace('/^http:/', 'https:', $baseUrl);
        }

        // Build the MCP server configuration
        $serverName = $agent->mcp_tool_name ?: strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $agent->name));

        $mcpConfig = [
            'mcpServers' => [
                $serverName => [
                    'type' => 'http',
                    'url' => "{$baseUrl}/api/mcp/{$urlTenant}",
                    'headers' => [
                        'X-API-Key' => '${MYCTOBOT_API_KEY}',
                        'X-Tenant' => $urlTenant
                    ]
                ]
            ],
            '_meta' => [
                'tenant' => $urlTenant,
                'agent_id' => $agentId,
                'agent_name' => $agent->name,
                'tools' => $toolDescriptions,
                'generated_at' => date('c'),
                'instructions' => [
                    'Save this as .mcp.json in your project root',
                    'Set MYCTOBOT_API_KEY environment variable with your API key',
                    'Or replace ${MYCTOBOT_API_KEY} with your actual API key'
                ]
            ]
        ];

        // Return as JSON with proper formatting
        header('Content-Type: application/json');
        echo json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
