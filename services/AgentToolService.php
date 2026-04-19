<?php
/**
 * Agent Tool Service
 *
 * Handles database-defined agent tools (from aiagents/agenttools tables).
 * Used by the Api controller for REST-style MCP endpoints.
 */

namespace app\services;

use \app\Bean;
use \Exception;

require_once __DIR__ . '/LLMProviders/LLMProviderInterface.php';
require_once __DIR__ . '/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/LLMProviders/OllamaProvider.php';

class AgentToolService {

    private int $memberId;
    private string $workspace;

    public function __construct(int $memberId, string $workspace) {
        $this->memberId = $memberId;
        $this->workspace = $workspace;
    }

    /**
     * List all MCP-exposed tools for the member
     *
     * @return array MCP-compatible tool definitions
     */
    public function listTools(): array {
        $agents = Bean::find('aiagents', 'member_id = ? AND expose_as_mcp = 1 AND is_active = 1', [$this->memberId]);

        $tools = [];
        foreach ($agents as $agent) {
            $agentTools = Bean::find('agenttools', 'aiagents_id = ? AND is_active = 1', [$agent->id]);

            foreach ($agentTools as $tool) {
                $parametersSchema = json_decode($tool->parameters_schema ?: '[]', true);

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

                // Ensure empty properties is an object {}, not array []
                if (empty($inputSchema['properties'])) {
                    $inputSchema['properties'] = (object) [];
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

        return $tools;
    }

    /**
     * Call a tool by name
     *
     * @param string $toolName Tool name
     * @param array $arguments Tool arguments
     * @return string Tool execution result
     * @throws Exception If tool not found or execution fails
     */
    public function callTool(string $toolName, array $arguments = []): string {
        $tool = Bean::findOne('agenttools', 'tool_name = ? AND is_active = 1', [$toolName]);
        if (!$tool) {
            throw new Exception("Tool not found: {$toolName}");
        }

        $agent = Bean::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$tool->aiagents_id, $this->memberId]);
        if (!$agent) {
            throw new Exception("Tool's agent not accessible");
        }

        return $this->executeTool($tool, $agent, $arguments);
    }

    /**
     * Get MCP configuration for an agent
     *
     * @param int $agentId Agent ID
     * @return array|null MCP config or null if not found
     */
    public function getAgentConfig(int $agentId): ?array {
        $agent = Bean::findOne('aiagents', 'id = ? AND member_id = ? AND expose_as_mcp = 1 AND is_active = 1',
            [$agentId, $this->memberId]);

        if (!$agent) {
            return null;
        }

        $tools = Bean::find('agenttools', 'aiagents_id = ? AND is_active = 1', [$agentId]);

        $toolDescriptions = [];
        foreach ($tools as $tool) {
            $toolDescriptions[] = $tool->tool_name;
        }

        $serverName = $agent->mcp_tool_name ?: strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $agent->name));

        return [
            'mcpServers' => [
                $serverName => [
                    'type' => 'http',
                    'url' => "https://{$this->workspace}.myctobot.ai/mcp",
                    'headers' => [
                        'Authorization' => 'Bearer ${MYCTOBOT_API_KEY}'
                    ]
                ]
            ],
            '_meta' => [
                'workspace' => $this->workspace,
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
    }

    /**
     * Execute a tool with the agent's LLM
     */
    private function executeTool($tool, $agent, array $arguments): string {
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
            $ollamaHost = $providerConfig['ollama_host'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['ollama_model'] ?? 'llama3';
            return LLMProviders\OllamaProvider::quickChat($ollamaHost, $ollamaModel, $prompt, $imagePath);
        } elseif ($provider === 'ollama') {
            $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['model'] ?? 'llama3';
            return LLMProviders\OllamaProvider::quickChat($ollamaHost, $ollamaModel, $prompt, $imagePath);
        } else {
            // Other providers - return prompt preview for now
            return "Prompt would be sent to {$provider}:\n\n{$prompt}";
        }
    }
}
