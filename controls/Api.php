<?php
/**
 * API Controller for MCP Tool Calls
 * Provides HTTP endpoints for the MCP agent server to call
 * Handles workspace routing based on API key
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R; // Keep for hasDatabase/addDatabase/selectDatabase
use \app\Bean;
use \Exception as Exception;
use \app\services\UserDatabaseService;
use \app\services\LLMProviders\LLMProviderFactory;
use \app\services\LLMProviders\OllamaProvider;
use \app\services\ApiAuthService;
use \app\WorkspaceResolver;

require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderInterface.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/../services/LLMProviders/OllamaProvider.php';

class Api extends BaseControls\Control {

    private ?string $workspaceSlug = null;
    private ?int $workspaceMemberId = null;

    /**
     * Authenticate via API key using ApiAuthService
     * Requires workspace to be determined first (via URL, header, or query param)
     *
     * @param string|null $workspace Workspace slug (from URL, X-Workspace header, or ?workspace= param)
     * @param string $controller Controller name for scope check
     * @param string $method Method name for scope check
     * @return array{success: bool, member: ?object, error: ?string, code: ?int}
     */
    private function authenticateWithApiAuth(?string $workspace, string $controller, string $method): array {
        // Determine workspace from multiple sources
        if (empty($workspace)) {
            $workspace = $_SERVER['HTTP_X_WORKSPACE']
                ?? $this->getParam('workspace')
                ?? $this->getParam('workspace');
        }

        if (empty($workspace)) {
            return [
                'success' => false,
                'member' => null,
                'error' => 'Workspace required (via URL, X-Workspace header, or ?workspace= param)',
                'code' => 400
            ];
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            return [
                'success' => false,
                'member' => null,
                'error' => "Invalid workspace: {$workspace}",
                'code' => 400
            ];
        }

        $this->workspaceSlug = $workspace;

        // Authenticate via ApiAuthService
        $authResult = ApiAuthService::authenticate($controller, $method);
        if (!$authResult['success']) {
            return $authResult;
        }

        $this->workspaceMemberId = $authResult['member']->id;

        return $authResult;
    }

    /**
     * List all exposed MCP tools
     * GET /api/mcp/tools
     */
    public function mcptools($params = []) {
        // Authenticate with ApiAuthService (requires workspace from header or query)
        $authResult = $this->authenticateWithApiAuth(null, 'api', 'mcptools');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        $this->mcpToolsInternal();
    }

    /**
     * Internal MCP tools list (assumes already authenticated)
     */
    private function mcpToolsInternal() {
        // Get all agents with expose_as_mcp = 1 (use workspace member ID)
        $agents = Bean::find('aiagents', 'member_id = ? AND expose_as_mcp = 1 AND is_active = 1', [$this->workspaceMemberId]);

        $tools = [];
        foreach ($agents as $agent) {
            // Get tools for this agent
            $agentTools = Bean::find('agenttools', 'aiagents_id = ? AND is_active = 1', [$agent->id]);

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
    public function mcpcall($params = []) {
        // Authenticate with ApiAuthService (requires workspace from header or query)
        $authResult = $this->authenticateWithApiAuth(null, 'api', 'mcpcall');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        $this->mcpCallInternal();
    }

    /**
     * Internal MCP call execution (assumes already authenticated)
     */
    private function mcpCallInternal() {
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
        $tool = Bean::findOne('agenttools', 'tool_name = ? AND is_active = 1', [$toolName]);
        if (!$tool) {
            Flight::jsonError("Tool not found: {$toolName}", 404);
            return;
        }

        // Verify agent ownership and get agent config (use workspace member ID)
        $agent = Bean::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$tool->aiagents_id, $this->workspaceMemberId]);
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
            return OllamaProvider::quickChat($ollamaHost, $ollamaModel, $prompt, $imagePath);
        } elseif ($provider === 'ollama') {
            // Direct Ollama
            $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['model'] ?? 'llama3';
            return OllamaProvider::quickChat($ollamaHost, $ollamaModel, $prompt, $imagePath);
        } else {
            // Other providers - return prompt preview for now
            return "Prompt would be sent to {$provider}:\n\n{$prompt}";
        }
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
     * List active workstations/runners
     * GET /api/workstations/@workspace
     */
    public function workstations($workspace = null) {
        // Authenticate with ApiAuthService (handles workspace switching)
        $authResult = $this->authenticateWithApiAuth($workspace, 'api', 'workstations');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        // Query active runners
        $runners = Bean::find('runners', 'is_active = 1 ORDER BY name ASC');

        $workstations = [];
        foreach ($runners as $runner) {
            $workstations[] = [
                'id' => (int) $runner->id,
                'name' => $runner->name,
                'host' => $runner->host,
                'ssh_user' => $runner->ssh_user ?? 'claudeuser',
                'ssh_port' => (int) ($runner->ssh_port ?? 22),
                'execution_mode' => $runner->execution_mode ?? 'ssh_tmux',
                'sshkey_id' => $runner->sshkey_id ? (int) $runner->sshkey_id : null,
            ];
        }

        Flight::jsonSuccess(['workstations' => $workstations]);
    }

    /**
     * MCP JSON-RPC endpoint
     * POST /api/mcp/workspace
     *
     * Handles MCP protocol requests from Claude Code's HTTP MCP client
     * Methods: initialize, tools/list, tools/call
     */
    public function mcpjsonrpc($workspace = null) {
        $urlWorkspace = $workspace;

        // Debug logging for MCP requests
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'none';
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? 'none';
        Flight::get('log')->debug("MCP request: workspace={$urlWorkspace}, auth_header=" . substr($authHeader, 0, 30) . "..., x-api-token=" . substr($apiToken, 0, 20) . "...");

        if (empty($urlWorkspace)) {
            $this->jsonRpcError(-32600, 'Workspace required in URL', null);
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

        Flight::get('log')->debug("MCP JSON-RPC: method={$method}, workspace={$urlWorkspace}");

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
                $this->mcpToolsList($urlWorkspace, $id);
                break;

            case 'tools/call':
                $this->mcpToolsCall($urlWorkspace, $rpcParams, $id);
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
    private function mcpToolsList(string $urlWorkspace, $id) {
        // Authenticate with ApiAuthService
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'none';
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? 'none';
        $queryKey = $_GET['key'] ?? 'none';
        $queryString = $_SERVER['QUERY_STRING'] ?? 'none';
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'none';
        Flight::get('log')->debug("MCP tools/list auth check: auth_header=" . substr($authHeader, 0, 40) . ", x-api-token=" . substr($apiToken, 0, 25) . ", query_key=" . substr($queryKey, 0, 20) . ", query_string=" . $queryString . ", uri=" . $requestUri);

        $authResult = $this->authenticateWithApiAuth($urlWorkspace, 'api', 'mcptools');
        Flight::get('log')->debug("MCP tools/list auth result: success=" . ($authResult['success'] ? 'true' : 'false') . ", error=" . ($authResult['error'] ?? 'none'));

        if (!$authResult['success']) {
            $this->jsonRpcError(-32000, $authResult['error'], $id);
            return;
        }

        $tools = [];

        // Get all agents with expose_as_mcp = 1
        $agents = Bean::find('aiagents', 'member_id = ? AND expose_as_mcp = 1 AND is_active = 1', [$this->workspaceMemberId]);

        foreach ($agents as $agent) {
            $agentTools = Bean::find('agenttools', 'aiagents_id = ? AND is_active = 1', [$agent->id]);

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
                    'inputSchema' => $inputSchema,
                    '_type' => 'agent_tool'
                ];
            }
        }

        // Get all pipelines with expose_as_tool = 1
        $pipelines = Bean::find('pipelines', 'expose_as_tool = 1 AND is_active = 1');

        foreach ($pipelines as $pipeline) {
            $inputSchema = json_decode($pipeline->input_schema_json ?: '{}', true);
            if (empty($inputSchema) || !isset($inputSchema['type'])) {
                // Default to object with no required properties
                $inputSchema = [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => []
                ];
            }

            $tools[] = [
                'name' => 'myctobot_' . $pipeline->slug,
                'description' => $pipeline->description ?: "Execute the {$pipeline->name} pipeline",
                'inputSchema' => $inputSchema,
                '_type' => 'pipeline',
                '_pipeline_id' => (int) $pipeline->id,
                '_pipeline_slug' => $pipeline->slug
            ];
        }

        $this->jsonRpcResult(['tools' => $tools], $id);
    }

    /**
     * Handle MCP tools/call request
     */
    private function mcpToolsCall(string $urlWorkspace, array $params, $id) {
        // Authenticate with ApiAuthService
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'none';
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? 'none';
        $queryKey = $_GET['key'] ?? 'none';
        Flight::get('log')->debug("MCP tools/call auth check: auth_header=" . substr($authHeader, 0, 40) . ", x-api-token=" . substr($apiToken, 0, 25) . ", query_key=" . substr($queryKey, 0, 20));

        $authResult = $this->authenticateWithApiAuth($urlWorkspace, 'api', 'mcpcall');
        Flight::get('log')->debug("MCP tools/call auth result: success=" . ($authResult['success'] ? 'true' : 'false') . ", error=" . ($authResult['error'] ?? 'none'));

        if (!$authResult['success']) {
            $this->jsonRpcError(-32000, $authResult['error'], $id);
            return;
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            $this->jsonRpcError(-32602, 'Tool name is required', $id);
            return;
        }

        // Check if this is a pipeline tool (prefixed with myctobot_)
        if (str_starts_with($toolName, 'myctobot_')) {
            $this->executePipelineTool($toolName, $arguments, $id);
            return;
        }

        // Find the agent tool
        $tool = Bean::findOne('agenttools', 'tool_name = ? AND is_active = 1', [$toolName]);
        if (!$tool) {
            $this->jsonRpcError(-32602, "Tool not found: {$toolName}", $id);
            return;
        }

        // Verify agent ownership
        $agent = Bean::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$tool->aiagents_id, $this->workspaceMemberId]);
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
     * Execute a pipeline as an MCP tool
     */
    private function executePipelineTool(string $toolName, array $arguments, $id) {
        // Extract pipeline slug from tool name (myctobot_slug -> slug)
        $slug = substr($toolName, strlen('myctobot_'));

        $pipeline = Bean::findOne('pipelines', 'slug = ? AND expose_as_tool = 1 AND is_active = 1', [$slug]);
        if (!$pipeline) {
            $this->jsonRpcError(-32602, "Pipeline not found: {$slug}", $id);
            return;
        }

        // Count steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            $this->jsonRpcError(-32000, 'Pipeline has no active steps', $id);
            return;
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');
        // Build context: merge default context with MCP arguments and auth info
        $context = json_decode($pipeline->default_context_json ?: '{}', true);
        $context = array_merge($context, $arguments);

        // Mark as authenticated MCP request in trigger data
        $triggerData = $arguments;
        $triggerData['mcp_authenticated'] = 'true';
        $context['mcp_authenticated'] = 'true';

        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member_id = $this->workspaceMemberId;
        $run->trigger_source = 'mcp_tool';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = json_encode($context);
        $run->steps_total = $stepCount;
        $run->steps_completed = 0;
        $run->progress_percent = 0;
        $run->created_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');

        $runId = Bean::store($run);

        // Update pipeline stats
        $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
        $pipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        // Execute synchronously
        require_once __DIR__ . '/../services/PipelineExecutor.php';
        $executor = new \app\services\PipelineExecutor($runId);
        $executor->execute();

        // Reload run to get final state
        $run = Bean::load('pipelineruns', $runId);
        $context = json_decode($run->context_json ?: '{}', true);

        // Get step results for the output
        $stepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? ORDER BY id ASC ',
            [$runId]
        );

        $lastOutput = null;
        foreach ($stepRuns as $sr) {
            if ($sr->status === 'success' && !empty($sr->output_json)) {
                $lastOutput = json_decode($sr->output_json, true);
            }
        }

        // MCP tool result format
        $isError = $run->status !== 'completed';
        $content = [];

        if ($isError) {
            $content[] = [
                'type' => 'text',
                'text' => "Pipeline execution failed: " . ($run->error_message ?: 'Unknown error')
            ];
        } else {
            // Return the context/output as the tool result
            $resultText = json_encode($lastOutput ?? $context, JSON_PRETTY_PRINT);
            $content[] = [
                'type' => 'text',
                'text' => $resultText
            ];
        }

        $this->jsonRpcResult([
            'content' => $content,
            'isError' => $isError
        ], $id);
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
     * List MCP tools with workspace validation from URL
     * GET /api/mcp/workspace/tools
     */
    public function mcptoolswithworkspace($workspace = null) {
        // Authenticate with ApiAuthService
        $authResult = $this->authenticateWithApiAuth($workspace, 'api', 'mcptools');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        // Delegate to internal tools list (already authenticated)
        $this->mcpToolsInternal();
    }

    /**
     * Execute MCP tool with workspace validation from URL
     * POST /api/mcp/workspace/call
     */
    public function mcpcallwithworkspace($workspace = null) {
        // Authenticate with ApiAuthService
        $authResult = $this->authenticateWithApiAuth($workspace, 'api', 'mcpcall');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        // Delegate to standard mcpCall (already authenticated)
        $this->mcpCallInternal();
    }

    /**
     * Get MCP configuration for an agent
     * GET /api/mcp/workspace/config/{agentId}
     *
     * Returns a ready-to-use .mcp.json configuration for the specified agent
     */
    public function mcpconfig($workspace = null, $agentId = 0) {
        $urlWorkspace = $workspace;
        $agentId = (int) $agentId;

        if ($agentId <= 0) {
            Flight::jsonError('Agent ID required in URL', 400);
            return;
        }

        // Authenticate with ApiAuthService
        $authResult = $this->authenticateWithApiAuth($urlWorkspace, 'api', 'mcpconfig');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        // Get the agent
        $agent = Bean::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$agentId, $this->workspaceMemberId]);

        if (!$agent) {
            Flight::jsonError('Agent not found or not exposed as MCP', 404);
            return;
        }

        // Get tools for this agent
        $tools = Bean::find('agenttools', 'aiagents_id = ? AND is_active = 1', [$agentId]);

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
                    'url' => "{$baseUrl}/api/mcp/{$urlWorkspace}",
                    'headers' => [
                        'X-API-Key' => '${MYCTOBOT_API_KEY}',
                        'X-Workspace' => $urlWorkspace
                    ]
                ]
            ],
            '_meta' => [
                'workspace' => $urlWorkspace,
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

    /**
     * Get PM Assistant context data
     * GET /api/pm/context/workspace
     * GET /api/pm/context/workspace?project_uid=xxx
     */
    public function pmcontext($workspace = null) {
        // Authenticate with ApiAuthService
        $authResult = $this->authenticateWithApiAuth($workspace, 'api', 'pmcontext');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        $projectId = $this->getParam('project_uid');

        try {
            $context = $this->buildPmContext($projectId);
            Flight::jsonSuccess($context);
        } catch (Exception $e) {
            Flight::get('log')->error('PM Context error: ' . $e->getMessage());
            Flight::jsonError('Failed to build PM context: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Build PM context from database using RedBeanPHP
     */
    private function buildPmContext(?string $projectId = null): array {
        $projects = $this->getPmProjects($projectId);
        $epics = $this->getPmEpicsWithStats($projectId);
        $stories = $this->getPmPendingStories($projectId);
        $completed = $this->getPmCompletedStories($projectId);
        $blocked = $this->getPmBlockedItems($projectId);
        $archived = $this->getPmArchivedStories($projectId);
        $summary = $this->buildPmSummary($projects, $epics, $stories, $completed, $blocked, $archived);

        return compact('projects', 'epics', 'stories', 'completed', 'blocked', 'archived', 'summary');
    }

    /**
     * Get active projects using Bean::find()
     */
    private function getPmProjects(?string $projectId): array {
        $condition = 'status IN (?, ?) ORDER BY created_at DESC LIMIT 10';
        $params = ['planning', 'in_progress'];

        if ($projectId) {
            $condition = 'status IN (?, ?) AND project_uid = ? ORDER BY created_at DESC LIMIT 10';
            $params[] = $projectId;
        }

        $beans = Bean::find('ctoprojects', $condition, $params);

        return array_values(array_map(fn($p) => [
            'project_uid' => $p->project_uid,
            'name' => $p->name,
            'description' => $p->description,
            'status' => $p->status,
            'created_at' => $p->created_at
        ], $beans));
    }

    /**
     * Get epics with stats - uses raw SQL for GROUP BY aggregations
     */
    private function getPmEpicsWithStats(?string $projectId): array {
        // GROUP BY with aggregations requires raw SQL
        $sql = "SELECT e.id, e.epic_id, e.title, e.description, e.priority, e.sequence,
                       e.project_uid, p.name as project_name,
                       COUNT(s.id) as total_stories,
                       SUM(CASE WHEN s.status = 'done' THEN 1 ELSE 0 END) as completed_stories,
                       SUM(CASE WHEN s.status = 'pending_review' THEN 1 ELSE 0 END) as pending_stories,
                       SUM(CASE WHEN s.status = 'approved' THEN 1 ELSE 0 END) as approved_stories,
                       SUM(CASE WHEN s.status = 'blocked' THEN 1 ELSE 0 END) as blocked_stories,
                       SUM(COALESCE(s.story_points, 0)) as total_points,
                       SUM(CASE WHEN s.status = 'done' THEN COALESCE(s.story_points, 0) ELSE 0 END) as completed_points
                FROM ctoepics e
                JOIN ctoprojects p ON e.project_uid = p.id
                LEFT JOIN ctostories s ON s.epic_id = e.id
                WHERE p.status IN ('planning', 'in_progress')";

        $params = [];
        if ($projectId) {
            $sql .= " AND p.project_uid = ?";
            $params[] = $projectId;
        }

        $sql .= " GROUP BY e.id ORDER BY e.priority ASC, e.sequence ASC LIMIT 50";

        return Bean::getAll($sql, $params);
    }

    /**
     * Get pending stories using Bean::find() with Bean::load() for relations
     */
    private function getPmPendingStories(?string $projectId): array {
        $beans = Bean::find('ctostories',
            'status IN (?, ?, ?, ?, ?) ORDER BY sequence ASC LIMIT 100',
            ['pending_review', 'approved', 'backlog', 'ready', 'in_progress']
        );

        $result = [];
        foreach ($beans as $s) {
            $epic = $s->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id || !in_array($project->status, ['planning', 'in_progress'])) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $s->id,
                'story_id' => $s->story_uid,
                'title' => $s->title,
                'description' => $s->description,
                'status' => $s->status,
                'story_points' => $s->story_points,
                'jira_issue_key' => $s->jira_issue_key,
                'epic_title' => $epic->title,
                'epic_id' => $epic->epic_uid,
                'project_name' => $project->name
            ];
        }

        // Sort by status priority
        $order = ['in_progress' => 1, 'ready' => 2, 'approved' => 3, 'pending_review' => 4, 'backlog' => 5];
        usort($result, fn($a, $b) => ($order[$a['status']] ?? 99) - ($order[$b['status']] ?? 99));

        return $result;
    }

    /**
     * Get completed stories - those with jobs that have PRs (what Review Board shows)
     * Uses Bean::find() to get jobs, then loads related story/epic/project
     */
    private function getPmCompletedStories(?string $projectId): array {
        // Get jobs with PRs - this is what Review Board shows as "Complete"
        $jobs = Bean::find('aidevjobs',
            'status IN (?, ?) ORDER BY completed_at ASC LIMIT 50',
            ['pr_created', 'complete']
        );

        $result = [];
        foreach ($jobs as $job) {
            // Find the story by jira_issue_key (job.issue_key matches story.jira_issue_key)
            $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$job->issue_key]);
            if (!$story) continue;

            $epic = $story->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id || !in_array($project->status, ['planning', 'in_progress'])) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $story->id,
                'story_id' => $story->story_uid,
                'title' => $story->title,
                'status' => $story->status,
                'story_points' => $story->story_points,
                'jira_issue_key' => $story->jira_issue_key,
                'updated_at' => $story->updated_at,
                'epic_title' => $epic->title,
                'epic_id' => $epic->epic_uid,
                'epic_sequence' => $epic->sequence,
                'project_name' => $project->name,
                'job_status' => $job->status,
                'branch_name' => $job->branch_name,
                'pr_url' => $job->pr_url,
                'completed_at' => $job->completed_at
            ];
        }

        return $result;
    }

    /**
     * Get blocked items using Bean::find() with Bean::load() for relations
     */
    private function getPmBlockedItems(?string $projectId): array {
        $beans = Bean::find('ctostories', 'status = ? LIMIT 20', ['blocked']);

        $result = [];
        foreach ($beans as $s) {
            $epic = $s->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id || !in_array($project->status, ['planning', 'in_progress'])) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $s->id,
                'story_id' => $s->story_uid,
                'title' => $s->title,
                'description' => $s->description,
                'jira_issue_key' => $s->jira_issue_key,
                'epic_title' => $epic->title,
                'project_name' => $project->name
            ];
        }

        return $result;
    }

    /**
     * Get archived/merged stories using Bean::find()
     * These are stories that have been included in a QA release branch
     */
    private function getPmArchivedStories(?string $projectId): array {
        // Get jobs with merged status
        $jobs = Bean::find(
            'aidevjobs',
            'status = ? ORDER BY merged_at DESC LIMIT 100',
            ['merged']
        );

        $result = [];
        foreach ($jobs as $job) {
            // Find the story by jira_issue_key
            $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$job->issue_key]);
            if (!$story) continue;

            $epic = $story->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $story->id,
                'story_id' => $story->story_uid,
                'title' => $story->title,
                'story_points' => $story->story_points,
                'jira_issue_key' => $story->jira_issue_key,
                'epic_title' => $epic->title,
                'project_name' => $project->name,
                'qa_branch' => $job->qa_branch,
                'merged_at' => $job->merged_at
            ];
        }

        return $result;
    }

    /**
     * Build summary text
     */
    private function buildPmSummary(array $projects, array $epics, array $stories, array $completed, array $blocked, array $archived = []): string {
        $parts = [];
        $parts[] = count($projects) . ' active project(s)';
        $parts[] = count($epics) . ' epic(s)';
        $parts[] = count($stories) . ' pending story(ies)';
        if (count($completed) > 0) {
            $parts[] = count($completed) . ' completed (ready to merge)';
        }
        if (count($blocked) > 0) {
            $parts[] = count($blocked) . ' blocked';
        }
        if (count($archived) > 0) {
            $parts[] = count($archived) . ' archived/merged';
        }
        return implode(', ', $parts);
    }
}
