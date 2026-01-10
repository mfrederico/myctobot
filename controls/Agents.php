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

            $agents[] = [
                'id' => $bean->id,
                'name' => $bean->name,
                'description' => $bean->description,
                'provider' => $provider,
                'provider_label' => $providerInfo['name'] ?? $provider,
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
                return [];
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

        $providerInstance = LLMProviderFactory::create($provider, $config, $this->member->id);
        if (!$providerInstance) {
            // Return defaults for claude_cli
            if ($provider === 'claude_cli') {
                Flight::jsonSuccess(['models' => [
                    ['name' => 'haiku', 'details' => ['family' => 'claude', 'parameter_size' => 'Small']],
                    ['name' => 'sonnet', 'details' => ['family' => 'claude', 'parameter_size' => 'Medium']],
                    ['name' => 'opus', 'details' => ['family' => 'claude', 'parameter_size' => 'Large']]
                ]]);
                return;
            }
            Flight::jsonError('Unknown provider', 400);
            return;
        }

        // For Ollama, get detailed models if requested
        if ($provider === 'ollama' && $detailed && method_exists($providerInstance, 'getModelsWithDetails')) {
            $models = $providerInstance->getModelsWithDetails();
        } else {
            $models = $providerInstance->getAvailableModels();
            // Normalize to array of objects for consistency
            if (!empty($models) && is_string($models[0])) {
                $models = array_map(fn($m) => ['name' => $m], $models);
            }
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

        $providerInstance = LLMProviderFactory::create($provider, $config, $this->member->id);
        if (!$providerInstance) {
            Flight::jsonError('Unknown provider', 400);
            return;
        }

        // Only Ollama supports model info currently
        if ($provider === 'ollama' && method_exists($providerInstance, 'getModelInfo')) {
            $info = $providerInstance->getModelInfo($modelName);
            Flight::jsonSuccess($info);
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
}
