<?php
/**
 * AI Agents Controller
 * Handles agent profile CRUD and configuration (MCP servers, hooks)
 * Supports multiple LLM providers: Claude, Ollama, OpenAI, etc.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use \app\services\TierFeatures;
use \app\services\EncryptionService;
use \app\services\LLMProviders\LLMProviderFactory;
use \app\services\LLMProviders\OllamaProvider;

require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderInterface.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';
require_once __DIR__ . '/../services/LLMProviders/OllamaProvider.php';
require_once __DIR__ . '/../services/LLMProviders/OpenAIProvider.php';
require_once __DIR__ . '/../services/AnthropicKeyService.php';
use \app\services\AnthropicKeyService;

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

        // Get all agents for this workspace (workspace-level - shared by all members)
        $agentBeans = Bean::findAll('aiagents', ' ORDER BY name ASC');

        $agents = [];
        foreach ($agentBeans as $bean) {
            $mcpServers = json_decode($bean->mcp_servers ?: '[]', true);
            $hooksConfig = json_decode($bean->hooks_config ?: '{}', true);
            $capabilities = json_decode($bean->capabilities ?: '[]', true);

            // Count repos using this agent
            $repoCount = Bean::count('repoconnections', 'aiagents_id = ?', [$bean->id]);

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

        // Check shard availability for Claude CLI
        $useLocalRunner = Flight::get('aidev.use_local_runner') ?? false;
        $shardCount = 0;
        if (!$useLocalRunner) {
            // Check for active shards in default database
            $shardCount = Bean::count('claudeshards', 'is_active = 1') ?? (int) 0;
        }
        $this->viewData['has_shards'] = $useLocalRunner || $shardCount > 0;
        $this->viewData['use_local_runner'] = $useLocalRunner;
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
        $this->viewData['anthropicKeys'] = AnthropicKeyService::getAllKeys($this->member->id);
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
        $provider = $this->getParam('provider', 'claude_cli');
        $isDefault = (bool) $this->getParam('is_default', false);

        if (empty($name)) {
            $this->flash('error', 'Agent name is required');
            Flight::redirect('/agents/create');
            return;
        }

        // Validate provider type
        if (!LLMProviderFactory::getProviderInfo($provider)) {
            $this->flash('error', 'Invalid provider type');
            Flight::redirect('/agents/create');
            return;
        }

        // Build provider config based on type
        $providerConfig = $this->buildRunnerConfig($provider);

        // Create agent (workspace-level - track who created it)
        $agent = Bean::dispense('aiagents');
        $agent->created_by_member_id = $memberId;
        $agent->created_by_name = $this->member->display_name ?? $this->member->email;
        $agent->name = $name;
        $agent->description = $description;
        $agent->provider = $provider;
        $agent->provider_config = json_encode($providerConfig);
        $agent->mcp_servers = '[]';
        $agent->hooks_config = '{}';
        $agent->is_active = 1;
        $agent->is_default = $isDefault ? 1 : 0;
        $agent->created_at = date('Y-m-d H:i:s');

        // Save Anthropic key assignment for claude_api provider
        $anthropicKeyId = $this->getParam('anthropickeys_id');
        if ($provider === 'claude_api' && $anthropicKeyId) {
            // Verify the key exists and is accessible to this member
            $key = Bean::load('anthropickeys', (int)$anthropicKeyId);
            if ($key && $key->id && ($key->created_by_member_id == $this->member->id || $key->shared)) {
                $agent->anthropickeys_id = (int)$anthropicKeyId;
            }
        }

        // If setting as default, unset other defaults (workspace-level)
        if ($isDefault) {
            Bean::exec('UPDATE aiagents SET is_default = 0 WHERE 1');
        }

        $id = Bean::store($agent);

        $this->flash('success', 'Agent profile created');
        Flight::redirect('/agents/edit/' . $id);
    }

    /**
     * Create agent from wizard (AJAX)
     *
     * Accepts wizard data and creates a fully configured agent.
     */
    public function createfromwizard($params = []) {
        if (!$this->requireEnterprise()) return;

        // Get JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Flight::jsonError('Invalid request data', 400);
            return;
        }

        // Validate CSRF from header
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $memberId = $this->member->id;

        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $provider = $input['provider'] ?? 'claude_cli';
        $providerConfig = $input['provider_config'] ?? [];
        $mcpServers = $input['mcp_servers'] ?? [];
        $capabilities = $input['capabilities'] ?? [];
        $isActive = $input['is_active'] ?? true;

        if (empty($name)) {
            Flight::jsonError('Agent name is required', 400);
            return;
        }

        // Validate provider type
        if (!LLMProviderFactory::getProviderInfo($provider)) {
            Flight::jsonError('Invalid provider type', 400);
            return;
        }

        // Create agent (workspace-level - track who created it)
        $agent = Bean::dispense('aiagents');
        $agent->created_by_member_id = $memberId;
        $agent->created_by_name = $this->member->display_name ?? $this->member->email;
        $agent->name = $name;
        $agent->description = $description;
        $agent->provider = $provider;
        $agent->provider_config = json_encode($providerConfig);
        $agent->mcp_servers = json_encode($mcpServers);
        $agent->hooks_config = '{}';
        $agent->capabilities = json_encode($capabilities);
        $agent->is_active = $isActive ? 1 : 0;
        $agent->is_default = 0;
        $agent->created_at = date('Y-m-d H:i:s');

        // Save Anthropic key assignment for claude_api provider
        $anthropicKeyId = $input['anthropickeys_id'] ?? null;
        if ($provider === 'claude_api' && $anthropicKeyId) {
            $key = Bean::load('anthropickeys', (int)$anthropicKeyId);
            if ($key && $key->id && ($key->created_by_member_id == $memberId || $key->shared)) {
                $agent->anthropickeys_id = (int)$anthropicKeyId;
            }
        }

        $id = Bean::store($agent);

        Flight::jsonSuccess(['agent_id' => $id], 'Agent created successfully');
    }

    /**
     * Show edit form
     */
    public function edit($params = []) {
        if (!$this->requireEnterprise()) return;

        // ID comes from URL: /agents/edit/{id}
        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);

        // Workspace-level - all members can edit agents
        $agent = Bean::findOne('aiagents', 'id = ?', [$id]);
        if (!$agent) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        $this->viewData['title'] = 'Edit Agent: ' . $agent->name;

        // Get linked MCP servers via many-to-many
        $linkedServers = [];
        foreach ($agent->sharedMcpserversList as $server) {
            $linkedServers[] = [
                'id' => $server->id,
                'name' => $server->name,
                'description' => $server->description,
                'server_type' => $server->server_type,
                'command' => $server->command,
                'args' => json_decode($server->args_json ?: '[]', true),
                'url' => $server->url,
                'headers' => json_decode($server->headers_json ?: '{}', true),
                'env' => json_decode($server->env_json ?: '{}', true),
                'is_shared' => (bool) $server->is_shared
            ];
        }

        // Get all available MCP servers (own + shared)
        $availableServers = [];
        $allServers = Bean::find('mcpservers',
            ' (member_id = ? OR is_shared = 1) ORDER BY name ASC',
            [$this->member->id]
        );
        foreach ($allServers as $server) {
            $availableServers[] = [
                'id' => $server->id,
                'name' => $server->name,
                'description' => $server->description,
                'server_type' => $server->server_type,
                'is_shared' => (bool) $server->is_shared
            ];
        }

        $this->viewData['agent'] = [
            'id' => $agent->id,
            'name' => $agent->name,
            'description' => $agent->description,
            'provider' => $agent->provider ?: 'claude_cli',
            'provider_config' => json_decode($agent->provider_config ?: '{}', true),
            'mcp_servers' => json_decode($agent->mcp_servers ?: '[]', true), // Legacy JSON (for migration)
            'linked_mcp_servers' => $linkedServers, // New many-to-many
            'hooks_config' => json_decode($agent->hooks_config ?: '{}', true),
            'capabilities' => json_decode($agent->capabilities ?: '[]', true),
            'expose_as_mcp' => (bool) $agent->expose_as_mcp,
            'mcp_tool_name' => $agent->mcp_tool_name,
            'mcp_tool_description' => $agent->mcp_tool_description,
            'is_active' => (bool) $agent->is_active,
            'is_default' => (bool) $agent->is_default,
            'anthropickeys_id' => $agent->anthropickeys_id
        ];
        $this->viewData['availableMcpServers'] = $availableServers;

        // Get available Anthropic keys for claude_api provider
        $this->viewData['anthropicKeys'] = AnthropicKeyService::getAllKeys($this->member->id);
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
        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);

        // Workspace-level - all members can update agents
        $agent = Bean::findOne('aiagents', 'id = ?', [$id]);
        if (!$agent) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        $memberId = $this->member->id;
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
        Bean::store($agent);

        $this->flash('success', 'Agent profile updated');
        Flight::redirect('/agents/edit/' . $id . '?tab=' . $tab);
    }

    /**
     * Update general settings
     */
    private function updateGeneral($agent, int $memberId): void {
        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));
        $provider = $this->getParam('provider', 'claude_cli');
        $isDefault = (bool) $this->getParam('is_default', false);
        $isActive = (bool) $this->getParam('is_active', true);

        if (!empty($name)) {
            $agent->name = $name;
        }
        $agent->description = $description;

        if (LLMProviderFactory::getProviderInfo($provider)) {
            $agent->provider = $provider;
            $agent->provider_config = json_encode($this->buildRunnerConfig($provider));
        }

        $agent->is_active = $isActive ? 1 : 0;

        if ($isDefault) {
            // Workspace-level - unset all other defaults
            Bean::exec('UPDATE aiagents SET is_default = 0 WHERE 1');
            $agent->is_default = 1;
        } else {
            $agent->is_default = 0;
        }
    }

    /**
     * Update MCP servers config
     * Supports both linked servers (many-to-many) and legacy JSON
     */
    private function updateMcp($agent): void {
        // Check if we have linked server IDs (new approach)
        $linkedServerIds = $this->getParam('linked_server_ids');

        if ($linkedServerIds !== null) {
            // New approach: Link servers via many-to-many
            $serverIds = is_string($linkedServerIds)
                ? json_decode($linkedServerIds, true)
                : $linkedServerIds;

            if (!is_array($serverIds)) {
                $serverIds = [];
            }

            // Clear existing links and add new ones
            $agent->sharedMcpserversList = [];
            Bean::store($agent);

            foreach ($serverIds as $serverId) {
                $server = Bean::load('mcpservers', (int) $serverId);
                if ($server->id) {
                    // Verify access (own or shared)
                    if ($server->member_id == $this->member->id || $server->is_shared) {
                        $agent->sharedMcpserversList[] = $server;
                    }
                }
            }

            // Clear legacy JSON since we're using linked servers
            $agent->mcp_servers = '[]';
        } else {
            // Legacy approach: Save as JSON
            $mcpJson = $this->getParam('mcp_servers', '[]');

            // Validate JSON
            $parsed = json_decode($mcpJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->flash('error', 'Invalid MCP servers JSON');
                return;
            }

            $agent->mcp_servers = json_encode($parsed);
        }
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
        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);

        // Workspace-level - all members can delete agents
        $agent = Bean::findOne('aiagents', 'id = ?', [$id]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Check if any repos are using this agent via association
        $repoCount = $agent->countOwn('repoconnections');
        if ($repoCount > 0) {
            Flight::jsonError('Cannot delete agent that is assigned to repositories', 400);
            return;
        }

        Bean::trash($agent);

        Flight::jsonSuccess(['message' => 'Agent deleted']);
    }

    /**
     * Test an agent on a specific workstation (async)
     * POST /agents/testagent/{agent_id}
     *
     * Spawns a background job to test the agent, returns test ID for polling.
     * Combines agent config (model, Ollama settings) with
     * workstation SSH connection for a hello-world test.
     */
    public function testagent($params = []) {
        if (!$this->requireEnterprise()) return;

        $agentId = (int) ($this->opId() ?? $this->getParam('agent_id') ?? 0);
        $workstationId = (int) ($this->getParam('workstation_id') ?? 0);

        if (!$agentId || !$workstationId) {
            Flight::jsonError('Agent ID and Workstation ID are required', 400);
            return;
        }

        // Verify agent and workstation exist
        require_once __DIR__ . '/../services/AgentTestService.php';
        $testService = \app\services\AgentTestService::fromIds($agentId, $workstationId);

        if (!$testService) {
            Flight::jsonError('Agent or workstation not found', 404);
            return;
        }

        // Generate unique test ID
        $testId = 'at-' . bin2hex(random_bytes(8));

        // Get tenant slug
        $tenantSlug = $_SESSION['tenant_slug'] ?? 'default';

        // Spawn background script
        $scriptPath = __DIR__ . '/../scripts/agent-test-runner.php';
        $cmd = sprintf(
            'php %s --tenant=%s --agent=%d --workstation=%d --test-id=%s > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($tenantSlug),
            $agentId,
            $workstationId,
            escapeshellarg($testId)
        );

        exec($cmd);

        Flight::jsonSuccess([
            'test_id' => $testId,
            'status' => 'started',
            'message' => 'Test started in background',
            'agent' => $testService->getAgentInfo(),
            'workstation' => $testService->getWorkstationInfo()
        ]);
    }

    /**
     * Get test status
     * GET /agents/teststatus/{test_id}
     *
     * Polls the status of a background agent test.
     */
    public function teststatus($params = []) {
        if (!$this->requireEnterprise()) return;

        $testId = $this->opId() ?? $this->getParam('test_id') ?? '';

        if (empty($testId)) {
            Flight::jsonError('Test ID is required', 400);
            return;
        }

        // Read status file
        $statusFile = "/tmp/agent-test-{$testId}.json";

        if (!file_exists($statusFile)) {
            Flight::jsonError('Test not found', 404);
            return;
        }

        $status = json_decode(file_get_contents($statusFile), true);

        if (!$status) {
            Flight::jsonError('Failed to read test status', 500);
            return;
        }

        Flight::jsonSuccess($status);
    }

    /**
     * Get available workstations for agent testing dropdown
     * GET /agents/workstations
     */
    public function workstations($params = []) {
        if (!$this->requireEnterprise()) return;

        require_once __DIR__ . '/../services/AgentTestService.php';
        $workstations = \app\services\AgentTestService::getActiveWorkstations();

        Flight::jsonSuccess(['workstations' => $workstations]);
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

        // Save Anthropic key assignment for claude_api provider
        $anthropicKeyId = $this->getParam('anthropickeys_id');
        if ($provider === 'claude_api' && $anthropicKeyId) {
            // Verify the key exists and is accessible to this member
            $key = Bean::load('anthropickeys', (int)$anthropicKeyId);
            if ($key && $key->id && ($key->created_by_member_id == $this->member->id || $key->shared)) {
                $agent->anthropickeys_id = (int)$anthropicKeyId;
            }
        } elseif ($provider !== 'claude_api') {
            // Clear the key assignment if switching away from claude_api
            $agent->anthropickeys_id = null;
        }
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
    public function testconnection($params = []) {
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
    public function getmodels($params = []) {
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
    public function getmodelinfo($params = []) {
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
    public function getcapabilities($params = []) {
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

        $agentId = (int) ($this->opId() ?? $this->getParam('agent_id') ?? 0);

        // Workspace-level - all members can view agent tools
        $agent = Bean::findOne('aiagents', 'id = ?', [$agentId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        $result = [];
        foreach ($agent->with(' ORDER BY tool_name ASC ')->ownAgenttoolsList as $tool) {
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
    public function savetool($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $agentId = (int) ($this->opId() ?? $this->getParam('agent_id') ?? 0);
        $toolId = (int) $this->getParam('tool_id', 0);

        // Workspace-level - all members can manage agent tools
        $agent = Bean::findOne('aiagents', 'id = ?', [$agentId]);
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
        $existingTool = Bean::findOne('agenttools', 'aiagents_id = ? AND tool_name = ? AND id != ?', [$agentId, $toolName, $toolId]);
        if ($existingTool) {
            Flight::jsonError('A tool with this name already exists for this agent', 400);
            return;
        }

        // Create or update tool
        if ($toolId > 0) {
            $tool = Bean::findOne('agenttools', 'id = ? AND aiagents_id = ?', [$toolId, $agentId]);
            if (!$tool) {
                Flight::jsonError('Tool not found', 404);
                return;
            }
        } else {
            $tool = Bean::dispense('agenttools');
            $tool->aiagents = $agent;  // Association creates aiagents_id FK
            $tool->created_at = date('Y-m-d H:i:s');
        }

        $tool->tool_name = $toolName;
        $tool->tool_description = $toolDescription;
        $tool->parameters_schema = json_encode($parsedParams);
        $tool->prompt_template = $promptTemplate;
        $tool->is_active = $isActive ? 1 : 0;
        $tool->updated_at = date('Y-m-d H:i:s');

        $id = Bean::store($tool);

        Flight::jsonSuccess([
            'id' => $id,
            'message' => $toolId > 0 ? 'Tool updated' : 'Tool created'
        ]);
    }

    /**
     * Delete a tool (AJAX)
     */
    public function deletetool($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $agentId = (int) ($this->opId() ?? $this->getParam('agent_id') ?? 0);
        $toolId = (int) $this->getParam('tool_id', 0);

        // Workspace-level - all members can delete agent tools
        $agent = Bean::findOne('aiagents', 'id = ?', [$agentId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Find and delete tool
        $tool = Bean::findOne('agenttools', 'id = ? AND aiagents_id = ?', [$toolId, $agentId]);
        if (!$tool) {
            Flight::jsonError('Tool not found', 404);
            return;
        }

        Bean::trash($tool);

        Flight::jsonSuccess(['message' => 'Tool deleted']);
    }

    /**
     * Test a tool execution (AJAX)
     */
    public function testtool($params = []) {
        if (!$this->requireEnterprise()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $agentId = (int) ($this->opId() ?? $this->getParam('agent_id') ?? 0);
        $toolId = (int) $this->getParam('tool_id', 0);
        $testParams = $this->getParam('test_params', '{}');

        // Workspace-level - all members can test agent tools
        $agent = Bean::findOne('aiagents', 'id = ?', [$agentId]);
        if (!$agent) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        // Find tool
        $tool = Bean::findOne('agenttools', 'id = ? AND aiagents_id = ?', [$toolId, $agentId]);
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

                $response = OllamaProvider::quickChat($ollamaHost, $ollamaModel, $prompt, $imagePath);
            } elseif ($provider === 'ollama') {
                // Direct Ollama
                $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
                $ollamaModel = $providerConfig['model'] ?? 'llama3';

                $response = OllamaProvider::quickChat($ollamaHost, $ollamaModel, $prompt, $imagePath);
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
}
