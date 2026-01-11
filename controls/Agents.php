<?php
/**
 * AI Agents Controller
 * Handles agent profile CRUD and configuration (MCP servers, hooks)
 * Supports multiple LLM providers: Claude, Ollama, OpenAI, etc.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\TierFeatures;
use \app\services\EncryptionService;
use \app\services\LLMProviders\LLMProviderFactory;

require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderInterface.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/../services/LLMProviders/OllamaProvider.php';
require_once __DIR__ . '/../services/LLMProviders/OpenAIProvider.php';
require_once __DIR__ . '/../lib/Bean.php';

use \app\Bean;

class Agents extends BaseControls\Control {

    /**
     * Available capabilities for agents
     */
    private const CAPABILITIES = [
        'code_implementation' => 'Code Implementation',
        'code_review' => 'Code Review',
        'browser_testing' => 'Browser Testing',
        'requirements_analysis' => 'Requirements Analysis',
        'documentation' => 'Documentation',
        'security_audit' => 'Security Audit',
        'refactoring' => 'Refactoring',
        'debugging' => 'Debugging'
    ];

    /**
     * Check access - all features now available to logged-in users
     */
    private function requireEnterprise(): bool {
        return $this->requireLogin();
    }

    /**
     * List all agents
     */
    public function index($params = []) {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;

        // Get all agents for this member
        $agentBeans = R::findAll('aiagents', 'member_id = ? ORDER BY name ASC', [$memberId]);

        $agents = [];
        foreach ($agentBeans as $bean) {
            $mcpServers = json_decode($bean->mcp_servers ?: '[]', true);
            $hooksConfig = json_decode($bean->hooks_config ?: '{}', true);
            $capabilities = json_decode($bean->capabilities ?: '[]', true);

            // Count repos using this agent (repoconnections is in user SQLite database)
            $repoCount = Bean::count('repoconnections', 'agent_id = ?', [$bean->id]);

            // Get provider info
            $provider = $bean->provider ?: 'claude_cli';
            $providerInfo = LLMProviderFactory::getProviderInfo($provider);

            $providerConfig = json_decode($bean->provider_config ?: '{}', true);

            // Get LLM capabilities (tool calling, vision, etc.)
            $llmCapabilities = LLMProviderFactory::getCapabilities($provider, $providerConfig);

            $agents[] = [
                'id' => $bean->id,
                'name' => $bean->name,
                'description' => $bean->description,
                'provider' => $provider,
                'provider_label' => $providerInfo['name'] ?? $provider,
                'provider_config' => $providerConfig,
                'llm_capabilities' => $llmCapabilities,
                'runner_type' => $bean->runner_type, // Legacy field
                'mcp_count' => count($mcpServers),
                'hooks_count' => $this->countHooks($hooksConfig),
                'capabilities_count' => count($capabilities),
                'repo_count' => $repoCount,
                'is_active' => (bool) $bean->is_active,
                'is_default' => (bool) $bean->is_default,
                'expose_as_mcp' => (bool) $bean->expose_as_mcp,
                'mcp_tool_name' => $bean->mcp_tool_name,
                'created_at' => $bean->created_at,
                'updated_at' => $bean->updated_at
            ];
        }

        $this->viewData['title'] = 'AI Agent Profiles';
        $this->viewData['agents'] = $agents;
        $this->viewData['providers'] = LLMProviderFactory::getAllProvidersInfo();
        // csrf already set by parent constructor

        $this->render('agents/index', $this->viewData);
    }

    /**
     * Show create form
     */
    public function create($params = []) {
        if (!$this->requireEnterprise()) return;

        $this->viewData['title'] = 'Create Agent Profile';
        $this->viewData['agent'] = null;
        $this->viewData['providers'] = LLMProviderFactory::getAllProvidersInfo();
        $this->viewData['capabilities'] = self::CAPABILITIES;
        $this->viewData['providerConfigs'] = $this->getAllProviderConfigs();
        // csrf already set by parent constructor
        $this->viewData['activeTab'] = $this->getParam('tab', 'general');

        $this->render('agents/edit', $this->viewData);
    }

    /**
     * Store new agent
     */
    public function store($params = []) {
        if (!$this->requireEnterprise()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/agents');
            return;
        }

        $memberId = $this->member->id;

        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));
        $runnerType = $this->getParam('runner_type', 'claude_cli');
        $isDefault = (bool) $this->getParam('is_default', false);

        if (empty($name)) {
            $this->flash('error', 'Agent name is required');
            Flight::redirect('/agents/create');
            return;
        }

        // Validate provider type
        if (!LLMProviderFactory::getProviderInfo($runnerType)) {
            $this->flash('error', 'Invalid provider type');
            Flight::redirect('/agents/create');
            return;
        }

        // Build runner config based on type
        $runnerConfig = $this->buildRunnerConfig($runnerType);

        // Create agent
        $agent = R::dispense('aiagents');
        $agent->member_id = $memberId;
        $agent->name = $name;
        $agent->description = $description;
        $agent->provider = $runnerType;
        $agent->provider_config = json_encode($runnerConfig);
        $agent->runner_type = $runnerType; // Legacy field
        $agent->mcp_servers = '[]';
        $agent->hooks_config = '{}';
        $agent->is_active = 1;
        $agent->is_default = $isDefault ? 1 : 0;
        $agent->created_at = date('Y-m-d H:i:s');

        // If setting as default, unset other defaults
        if ($isDefault) {
            R::exec('UPDATE aiagents SET is_default = 0 WHERE member_id = ?', [$memberId]);
        }

        $id = R::store($agent);

        $this->flash('success', 'Agent profile created');
        Flight::redirect('/agents/edit/' . $id);
    }

    /**
     * Show edit form
     */
    public function edit($params = []) {
        if (!$this->requireEnterprise()) return;

        // ID comes from URL: /agents/edit/{id}
        $id = (int) ($params['operation']->name ?? $this->getParam('id') ?? 0);
        $memberId = $this->member->id;

        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$id, $memberId]);
        if (!$agent) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        $this->viewData['title'] = 'Edit Agent: ' . $agent->name;
        $this->viewData['agent'] = [
            'id' => $agent->id,
            'name' => $agent->name,
            'description' => $agent->description,
            'provider' => $agent->provider ?: 'claude_cli',
            'provider_config' => json_decode($agent->provider_config ?: '{}', true),
            'runner_type' => $agent->runner_type, // Legacy
            'runner_config' => json_decode($agent->runner_config ?: '{}', true),
            'mcp_servers' => json_decode($agent->mcp_servers ?: '[]', true),
            'hooks_config' => json_decode($agent->hooks_config ?: '{}', true),
            'capabilities' => json_decode($agent->capabilities ?: '[]', true),
            'expose_as_mcp' => (bool) $agent->expose_as_mcp,
            'mcp_tool_name' => $agent->mcp_tool_name,
            'mcp_tool_description' => $agent->mcp_tool_description,
            'is_active' => (bool) $agent->is_active,
            'is_default' => (bool) $agent->is_default
        ];
        $this->viewData['providers'] = LLMProviderFactory::getAllProvidersInfo();
        $this->viewData['capabilities'] = self::CAPABILITIES;
        $this->viewData['providerConfigs'] = $this->getAllProviderConfigs();
        // csrf already set by parent constructor
        $this->viewData['activeTab'] = $this->getParam('tab', 'general');

        // MCP config data for tenant-aware API
        $tenantSlug = $_SESSION['tenant_slug'] ?? 'default';
        $baseUrl = Flight::get('app.baseurl') ?: 'https://myctobot.ai';
        // Ensure HTTPS in production
        if (strpos($baseUrl, 'localhost') === false && strpos($baseUrl, '127.0.0.1') === false) {
            $baseUrl = preg_replace('/^http:/', 'https:', $baseUrl);
        }
        $this->viewData['tenantSlug'] = $tenantSlug;
        $this->viewData['apiBaseUrl'] = $baseUrl;
        $this->viewData['mcpApiUrl'] = "{$baseUrl}/api/mcp/{$tenantSlug}";

        $this->render('agents/edit', $this->viewData);
    }

    /**
     * Update agent
     */
    public function update($params = []) {
        if (!$this->requireEnterprise()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/agents');
            return;
        }

        // ID comes from URL: /agents/update/{id}
        $id = (int) ($params['operation']->name ?? $this->getParam('id') ?? 0);
        $memberId = $this->member->id;

        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$id, $memberId]);
        if (!$agent) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        $tab = $this->getParam('tab', 'general');

        switch ($tab) {
            case 'general':
                $this->updateGeneral($agent, $memberId);
                break;
            case 'provider':
                $this->updateProvider($agent);
                break;
            case 'mcp':
                $this->updateMcp($agent);
                break;
            case 'hooks':
                $this->updateHooks($agent);
                break;
            case 'capabilities':
                $this->updateCapabilities($agent);
                break;
        }

        $agent->updated_at = date('Y-m-d H:i:s');
        R::store($agent);

        $this->flash('success', 'Agent profile updated');
        Flight::redirect('/agents/edit/' . $id . '?tab=' . $tab);
    }

    /**
     * Update general settings
     */
    private function updateGeneral($agent, int $memberId): void {
        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));
        $runnerType = $this->getParam('runner_type', 'claude_cli');
        $isDefault = (bool) $this->getParam('is_default', false);
        $isActive = (bool) $this->getParam('is_active', true);

        if (!empty($name)) {
            $agent->name = $name;
        }
        $agent->description = $description;

        if (LLMProviderFactory::getProviderInfo($runnerType)) {
            $agent->provider = $runnerType;
            $agent->provider_config = json_encode($this->buildRunnerConfig($runnerType));
            $agent->runner_type = $runnerType; // Legacy field
        }

        $agent->is_active = $isActive ? 1 : 0;

        if ($isDefault) {
            R::exec('UPDATE aiagents SET is_default = 0 WHERE member_id = ?', [$memberId]);
            $agent->is_default = 1;
        } else {
            $agent->is_default = 0;
        }
    }

    /**
     * Update MCP servers config
     */
    private function updateMcp($agent): void {
        $mcpJson = $this->getParam('mcp_servers', '[]');

        // Validate JSON
        $parsed = json_decode($mcpJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->flash('error', 'Invalid MCP servers JSON');
            return;
        }

        $agent->mcp_servers = json_encode($parsed);
    }

    /**
     * Update hooks config
     */
    private function updateHooks($agent): void {
        $hooksJson = $this->getParam('hooks_config', '{}');

        // Validate JSON
        $parsed = json_decode($hooksJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->flash('error', 'Invalid hooks JSON');
            return;
        }

        $agent->hooks_config = json_encode($parsed);
    }

    /**
     * Delete agent
     */
    public function delete($params = []) {
        if (!$this->requireEnterprise()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        // ID comes from URL: /agents/delete/{id}
        $id = (int) ($params['operation']->name ?? $this->getParam('id') ?? 0);
        $memberId = $this->member->id;

        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$id, $memberId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Check if any repos are using this agent (repoconnections is in user SQLite database)
        $repoCount = Bean::count('repoconnections', 'agent_id = ?', [$id]);
        if ($repoCount > 0) {
            Flight::jsonError('Cannot delete agent that is assigned to repositories', 400);
            return;
        }

        R::trash($agent);

        Flight::jsonSuccess(['message' => 'Agent deleted']);
    }

    /**
     * Build runner config based on type
     */
    private function buildRunnerConfig(string $runnerType): array {
        switch ($runnerType) {
            case 'anthropic_api':
                $apiKey = $this->getParam('api_key', '');
                $model = $this->getParam('model', 'claude-sonnet-4-20250514');
                return [
                    'api_key' => $apiKey ? EncryptionService::encrypt($apiKey) : '',
                    'model' => $model
                ];

            case 'ollama':
                return [
                    'model' => $this->getParam('model', 'llama3'),
                    'base_url' => $this->getParam('base_url', 'http://localhost:11434')
                ];

            case 'claude_cli':
            default:
                // Check if using Ollama as backend
                $useOllama = (bool) $this->getParam('use_ollama', false);
                if ($useOllama) {
                    return [
                        'use_ollama' => true,
                        'ollama_host' => $this->getParam('ollama_host', 'http://localhost:11434'),
                        'ollama_model' => $this->getParam('ollama_model', '')
                    ];
                }
                // Standard Claude CLI with Anthropic backend
                return [
                    'model' => $this->getParam('model', 'sonnet')
                ];
        }
    }

    /**
     * Count total hooks across all events
     */
    private function countHooks(array $hooksConfig): int {
        $count = 0;
        foreach (['PreToolUse', 'PostToolUse', 'Stop'] as $event) {
            if (isset($hooksConfig[$event]) && is_array($hooksConfig[$event])) {
                $count += count($hooksConfig[$event]);
            }
        }
        return $count;
    }

    /**
     * Update provider settings
     */
    private function updateProvider($agent): void {
        $provider = $this->getParam('provider', 'claude_cli');
        $providerConfigJson = $this->getParam('provider_config', '{}');

        // Validate provider type
        if (!LLMProviderFactory::getProviderInfo($provider)) {
            $this->flash('error', 'Invalid provider type');
            return;
        }

        // Parse and validate provider config
        $providerConfig = json_decode($providerConfigJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->flash('error', 'Invalid provider configuration JSON');
            return;
        }

        // MCP exposure settings
        $exposeAsMcp = (bool) $this->getParam('expose_as_mcp', false);
        $mcpToolName = trim($this->getParam('mcp_tool_name', ''));
        $mcpToolDescription = trim($this->getParam('mcp_tool_description', ''));

        // Validate MCP tool name if exposing
        if ($exposeAsMcp && empty($mcpToolName)) {
            $mcpToolName = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $agent->name));
        }

        $agent->provider = $provider;
        $agent->provider_config = json_encode($providerConfig);
        $agent->expose_as_mcp = $exposeAsMcp ? 1 : 0;
        $agent->mcp_tool_name = $mcpToolName;
        $agent->mcp_tool_description = $mcpToolDescription;

        // Also update legacy runner_type for backwards compatibility
        $agent->runner_type = $provider;
    }

    /**
     * Update capabilities
     */
    private function updateCapabilities($agent): void {
        $capabilities = $this->getParam('capabilities', []);

        // Validate capabilities
        if (!is_array($capabilities)) {
            $capabilities = [];
        }

        // Filter to valid capabilities
        $validCapabilities = array_intersect($capabilities, array_keys(self::CAPABILITIES));

        $agent->capabilities = json_encode(array_values($validCapabilities));
    }

    /**
     * Test provider connection (AJAX endpoint)
     */
    public function testConnection($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $provider = $this->getParam('provider', '');
        $configJson = $this->getParam('config', '{}');

        $config = json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid configuration JSON', 400);
            return;
        }

        $result = LLMProviderFactory::testConnection($provider, $config, $this->member->id);

        Flight::jsonSuccess($result);
    }

    /**
     * Get available models for a provider (AJAX endpoint)
     */
    public function getModels($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $provider = $this->getParam('provider', '');
        $configJson = $this->getParam('config', '{}');
        $detailed = (bool) $this->getParam('detailed', false);

        $config = json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid configuration JSON', 400);
            return;
        }

        // Special handling for Ollama (used by Claude CLI + Ollama backend)
        if ($provider === 'ollama') {
            $providerInstance = LLMProviderFactory::getOllamaProvider($config);
            if ($detailed) {
                $models = $providerInstance->getModelsWithDetails();
            } else {
                $models = $providerInstance->getAvailableModels();
                $models = array_map(fn($m) => ['name' => $m], $models);
            }
            Flight::jsonSuccess(['models' => $models]);
            return;
        }

        // Return defaults for claude_cli (Anthropic models)
        if ($provider === 'claude_cli') {
            Flight::jsonSuccess(['models' => [
                ['name' => 'haiku', 'details' => ['family' => 'claude', 'parameter_size' => 'Small']],
                ['name' => 'sonnet', 'details' => ['family' => 'claude', 'parameter_size' => 'Medium']],
                ['name' => 'opus', 'details' => ['family' => 'claude', 'parameter_size' => 'Large']]
            ]]);
            return;
        }

        $providerInstance = LLMProviderFactory::create($provider, $config, $this->member->id);
        if (!$providerInstance) {
            Flight::jsonError('Unknown provider', 400);
            return;
        }

        $models = $providerInstance->getAvailableModels();
        // Normalize to array of objects for consistency
        if (!empty($models) && is_string($models[0])) {
            $models = array_map(fn($m) => ['name' => $m], $models);
        }

        Flight::jsonSuccess(['models' => $models]);
    }

    /**
     * Get detailed info about a specific model (AJAX endpoint)
     */
    public function getModelInfo($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $provider = $this->getParam('provider', '');
        $modelName = $this->getParam('model', '');
        $configJson = $this->getParam('config', '{}');

        if (empty($modelName)) {
            Flight::jsonError('Model name is required', 400);
            return;
        }

        $config = json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid configuration JSON', 400);
            return;
        }

        // Special handling for Ollama
        if ($provider === 'ollama') {
            $providerInstance = LLMProviderFactory::getOllamaProvider($config);
            $info = $providerInstance->getModelInfo($modelName);
            Flight::jsonSuccess($info);
            return;
        }

        $providerInstance = LLMProviderFactory::create($provider, $config, $this->member->id);
        if (!$providerInstance) {
            Flight::jsonError('Unknown provider', 400);
            return;
        }

        // Return basic info for other providers
        Flight::jsonSuccess([
            'success' => true,
            'model' => $modelName,
            'details' => [],
            'message' => 'Model info not available for this provider'
        ]);
    }

    /**
     * Get provider capabilities (AJAX endpoint)
     * For Ollama backend, queries the model info to derive capabilities
     */
    public function getCapabilities($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $provider = $this->getParam('provider', 'claude_cli');
        $providerConfigJson = $this->getParam('provider_config', '{}');

        $providerConfig = json_decode($providerConfigJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid provider config JSON', 400);
            return;
        }

        $capabilities = LLMProviderFactory::getCapabilities($provider, $providerConfig);

        Flight::jsonSuccess([
            'capabilities' => $capabilities,
            'labels' => [
                'can_orchestrate' => 'Agent Orchestration',
                'tool_calling' => 'Tool Calling',
                'vision' => 'Vision/Images',
                'streaming' => 'Streaming',
                'file_operations' => 'File Operations',
                'web_search' => 'Web Search'
            ]
        ]);
    }

    /**
     * Get all provider configs for JavaScript
     */
    private function getAllProviderConfigs(): array {
        $configs = [];
        foreach (LLMProviderFactory::getProviderTypes() as $type) {
            $configs[$type] = [
                'schema' => LLMProviderFactory::getConfigSchema($type),
                'defaults' => LLMProviderFactory::getDefaultConfig($type)
            ];
        }
        return $configs;
    }

    // =========================================================================
    // MCP Tools CRUD
    // =========================================================================

    /**
     * List tools for an agent (AJAX)
     */
    public function tools($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $agentId = (int) ($params['operation']->name ?? $this->getParam('agent_id') ?? 0);
        $memberId = $this->member->id;

        // Verify agent ownership
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$agentId, $memberId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        $tools = R::find('agenttools', 'agent_id = ? ORDER BY tool_name ASC', [$agentId]);

        $result = [];
        foreach ($tools as $tool) {
            $result[] = [
                'id' => (int) $tool->id,
                'tool_name' => $tool->tool_name,
                'tool_description' => $tool->tool_description,
                'parameters_schema' => json_decode($tool->parameters_schema ?: '[]', true),
                'prompt_template' => $tool->prompt_template,
                'is_active' => (bool) $tool->is_active,
                'created_at' => $tool->created_at,
                'updated_at' => $tool->updated_at
            ];
        }

        Flight::jsonSuccess(['tools' => $result]);
    }

    /**
     * Save a tool (create or update) (AJAX)
     */
    public function saveTool($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $agentId = (int) ($params['operation']->name ?? $this->getParam('agent_id') ?? 0);
        $toolId = (int) $this->getParam('tool_id', 0);
        $memberId = $this->member->id;

        // Verify agent ownership
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$agentId, $memberId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Get tool data
        $toolName = trim($this->getParam('tool_name', ''));
        $toolDescription = trim($this->getParam('tool_description', ''));
        $parametersSchema = $this->getParam('parameters_schema', '[]');
        $promptTemplate = trim($this->getParam('prompt_template', ''));
        $isActive = (bool) $this->getParam('is_active', true);

        // Validate tool name
        if (empty($toolName)) {
            Flight::jsonError('Tool name is required', 400);
            return;
        }

        // Validate tool name format (alphanumeric + underscores)
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $toolName)) {
            Flight::jsonError('Tool name must start with lowercase letter and contain only lowercase letters, numbers, and underscores', 400);
            return;
        }

        // Validate JSON parameters
        $parsedParams = json_decode($parametersSchema, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid parameters schema JSON', 400);
            return;
        }

        // Validate each parameter
        foreach ($parsedParams as $param) {
            if (empty($param['name'])) {
                Flight::jsonError('Each parameter must have a name', 400);
                return;
            }
            if (!in_array($param['type'] ?? 'string', ['string', 'number', 'boolean'])) {
                Flight::jsonError('Invalid parameter type: ' . ($param['type'] ?? 'unknown'), 400);
                return;
            }
        }

        // Check for duplicate tool name (excluding current tool if updating)
        $existingTool = R::findOne('agenttools', 'agent_id = ? AND tool_name = ? AND id != ?', [$agentId, $toolName, $toolId]);
        if ($existingTool) {
            Flight::jsonError('A tool with this name already exists for this agent', 400);
            return;
        }

        // Create or update tool
        if ($toolId > 0) {
            $tool = R::findOne('agenttools', 'id = ? AND agent_id = ?', [$toolId, $agentId]);
            if (!$tool) {
                Flight::jsonError('Tool not found', 404);
                return;
            }
        } else {
            $tool = R::dispense('agenttools');
            $tool->agent_id = $agentId;
            $tool->created_at = date('Y-m-d H:i:s');
        }

        $tool->tool_name = $toolName;
        $tool->tool_description = $toolDescription;
        $tool->parameters_schema = json_encode($parsedParams);
        $tool->prompt_template = $promptTemplate;
        $tool->is_active = $isActive ? 1 : 0;
        $tool->updated_at = date('Y-m-d H:i:s');

        $id = R::store($tool);

        Flight::jsonSuccess([
            'id' => $id,
            'message' => $toolId > 0 ? 'Tool updated' : 'Tool created'
        ]);
    }

    /**
     * Delete a tool (AJAX)
     */
    public function deleteTool($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $agentId = (int) ($params['operation']->name ?? $this->getParam('agent_id') ?? 0);
        $toolId = (int) $this->getParam('tool_id', 0);
        $memberId = $this->member->id;

        // Verify agent ownership
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$agentId, $memberId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Find and delete tool
        $tool = R::findOne('agenttools', 'id = ? AND agent_id = ?', [$toolId, $agentId]);
        if (!$tool) {
            Flight::jsonError('Tool not found', 404);
            return;
        }

        R::trash($tool);

        Flight::jsonSuccess(['message' => 'Tool deleted']);
    }

    /**
     * Test a tool execution (AJAX)
     */
    public function testTool($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $agentId = (int) ($params['operation']->name ?? $this->getParam('agent_id') ?? 0);
        $toolId = (int) $this->getParam('tool_id', 0);
        $testParams = $this->getParam('test_params', '{}');
        $memberId = $this->member->id;

        // Verify agent ownership
        $agent = R::findOne('aiagents', 'id = ? AND member_id = ?', [$agentId, $memberId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Find tool
        $tool = R::findOne('agenttools', 'id = ? AND agent_id = ?', [$toolId, $agentId]);
        if (!$tool) {
            Flight::jsonError('Tool not found', 404);
            return;
        }

        // Parse test parameters
        $params = json_decode($testParams, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid test parameters JSON', 400);
            return;
        }

        // Build prompt from template
        $prompt = $tool->prompt_template;
        $parametersSchema = json_decode($tool->parameters_schema ?: '[]', true);

        // Replace placeholders with values
        foreach ($parametersSchema as $paramDef) {
            $paramName = $paramDef['name'];
            $value = $params[$paramName] ?? $paramDef['default'] ?? '';
            $prompt = str_replace('{' . $paramName . '}', $value, $prompt);
        }

        // Get agent provider config
        $provider = $agent->provider ?: 'claude_cli';
        $providerConfig = json_decode($agent->provider_config ?: '{}', true);

        // Check if this is an image tool (has image_path parameter)
        $hasImageParam = false;
        $imagePath = null;
        foreach ($parametersSchema as $paramDef) {
            if (in_array($paramDef['name'], ['image_path', 'image', 'file_path'])) {
                $hasImageParam = true;
                $imagePath = $params[$paramDef['name']] ?? null;
                break;
            }
        }

        try {
            // Execute based on provider
            if ($provider === 'claude_cli' && !empty($providerConfig['use_ollama'])) {
                // Ollama backend
                $ollamaHost = $providerConfig['ollama_host'] ?? 'http://localhost:11434';
                $ollamaModel = $providerConfig['ollama_model'] ?? 'llama3';

                $response = $this->callOllama($ollamaHost, $ollamaModel, $prompt, $imagePath);
            } elseif ($provider === 'ollama') {
                // Direct Ollama
                $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
                $ollamaModel = $providerConfig['model'] ?? 'llama3';

                $response = $this->callOllama($ollamaHost, $ollamaModel, $prompt, $imagePath);
            } else {
                // For other providers, return a placeholder response
                $response = [
                    'success' => true,
                    'message' => 'Test mode: Prompt would be sent to ' . $provider,
                    'prompt_preview' => substr($prompt, 0, 500) . (strlen($prompt) > 500 ? '...' : '')
                ];
                Flight::jsonSuccess($response);
                return;
            }

            Flight::jsonSuccess([
                'success' => true,
                'response' => $response,
                'prompt_used' => $prompt
            ]);
        } catch (Exception $e) {
            Flight::jsonError('Tool execution failed: ' . $e->getMessage(), 500);
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
            throw new Exception('Ollama returned HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (isset($data['message']['content'])) {
            return $data['message']['content'];
        }

        return $response;
    }
}
