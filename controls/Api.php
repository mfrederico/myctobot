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

    /**
     * Authenticate via API key
     * Returns member ID if valid, null otherwise
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

        // Look up member by API token
        $member = R::findOne('member', 'api_token = ? AND status = ?', [$apiKey, 'active']);
        if (!$member) {
            return null;
        }

        return (int) $member->id;
    }

    /**
     * Switch to member's tenant database
     */
    private function switchToMemberDatabase(int $memberId): bool {
        try {
            UserDatabaseService::forMember($memberId);
            return true;
        } catch (Exception $e) {
            Flight::log('error', 'Failed to switch to member database: ' . $e->getMessage());
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

        // Get all agents with expose_as_mcp = 1
        $agents = R::find('aiagents', 'member_id = ? AND expose_as_mcp = 1 AND is_active = 1', [$memberId]);

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

        // Verify agent ownership and get agent config
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$tool->agent_id, $memberId]);
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
}
