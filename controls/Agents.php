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
            // Count linked MCP servers and calculate cumulative token overhead
            $linkedMcpServers = $bean->sharedMcpserversList;
            $linkedMcpCount = count($linkedMcpServers);
            $mcpTokenOverhead = 0;
            foreach ($linkedMcpServers as $server) {
                $mcpTokenOverhead += (int) ($server->tools_token_estimate ?: 0);
            }

            $mcpCount = $linkedMcpCount;

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
                'mcp_count' => $mcpCount,
                'mcp_token_overhead' => $mcpTokenOverhead,
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

        // Check runner availability for Claude CLI
        $useLocalRunner = Flight::get('aidev.use_local_runner') ?? false;
        $runnerCount = 0;
        if (!$useLocalRunner) {
            // Check for active runners in default database
            $runnerCount = Bean::count('runners', 'is_active = 1') ?? (int) 0;
        }
        $this->viewData['has_runners'] = $useLocalRunner || $runnerCount > 0;
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
        $agent->member_id = $memberId;
        $agent->created_by_name = $this->member->display_name ?? $this->member->email;
        $agent->name = $name;
        $agent->description = $description;
        $agent->provider = $provider;
        $agent->provider_config = json_encode($providerConfig);
        $agent->hooks_config = '{}';
        $agent->is_active = 1;
        $agent->is_default = $isDefault ? 1 : 0;
        $agent->created_at = date('Y-m-d H:i:s');

        // Save Anthropic key assignment for claude_api provider
        $connectionId = $this->getParam('connections_id');
        if ($provider === 'claude_api' && $connectionId) {
            // Verify the connection exists and is an Anthropic key
            $key = Bean::load('connections', (int)$connectionId);
            if ($key && $key->id && $key->connector_type === 'anthropic') {
                $agent->connections_id = (int)$connectionId;
            }
        }

        // Save workstation assignment (optional - runs jobs on assigned workstation via SSH)
        $workstationId = $this->getParam('runners_id');
        if ($workstationId) {
            // Verify the workstation exists and is active
            $runner = Bean::findOne('runners', 'id = ? AND is_active = 1', [(int)$workstationId]);
            if ($runner) {
                $agent->runners_id = (int)$workstationId;
            }
        }

        // Save default working directory (optional - where agent runs on the workstation)
        $defaultWorkDir = trim($this->getParam('default_work_dir', ''));
        $agent->default_work_dir = $defaultWorkDir ?: null;

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
        $agent->member_id = $memberId;
        $agent->created_by_name = $this->member->display_name ?? $this->member->email;
        $agent->name = $name;
        $agent->description = $description;
        $agent->provider = $provider;
        $agent->provider_config = json_encode($providerConfig);
        $agent->hooks_config = '{}';
        $agent->capabilities = json_encode($capabilities);
        $agent->is_active = $isActive ? 1 : 0;
        $agent->is_default = 0;
        $agent->created_at = date('Y-m-d H:i:s');

        // Save Anthropic key assignment for claude_api provider
        $connectionId = $input['connections_id'] ?? null;
        if ($provider === 'claude_api' && $connectionId) {
            $key = Bean::load('connections', (int)$connectionId);
            if ($key && $key->id && $key->connector_type === 'anthropic') {
                $agent->connections_id = (int)$connectionId;
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
                'is_shared' => (bool) $server->is_shared,
                'tools_count' => $server->tools_json ? count(json_decode($server->tools_json, true) ?: []) : null,
                'tools_token_estimate' => $server->tools_token_estimate ? (int) $server->tools_token_estimate : null
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
                'command' => $server->command,
                'args' => json_decode($server->args_json ?: '[]', true),
                'url' => $server->url,
                'headers' => json_decode($server->headers_json ?: '{}', true),
                'env' => json_decode($server->env_json ?: '{}', true),
                'is_shared' => (bool) $server->is_shared,
                'tools_count' => $server->tools_json ? count(json_decode($server->tools_json, true) ?: []) : null,
                'tools_token_estimate' => $server->tools_token_estimate ? (int) $server->tools_token_estimate : null
            ];
        }

        $this->viewData['agent'] = [
            'id' => $agent->id,
            'name' => $agent->name,
            'description' => $agent->description,
            'provider' => $agent->provider ?: 'claude_cli',
            'provider_config' => json_decode($agent->provider_config ?: '{}', true),
            'linked_mcp_servers' => $linkedServers, // Many-to-many via aiagents_mcpservers
            'hooks_config' => json_decode($agent->hooks_config ?: '{}', true),
            'capabilities' => json_decode($agent->capabilities ?: '[]', true),
            'expose_as_mcp' => (bool) $agent->expose_as_mcp,
            'mcp_tool_name' => $agent->mcp_tool_name,
            'mcp_tool_description' => $agent->mcp_tool_description,
            'is_active' => (bool) $agent->is_active,
            'is_default' => (bool) $agent->is_default,
            'connections_id' => $agent->connections_id,
            'runners_id' => $agent->runners_id,
            'default_work_dir' => $agent->default_work_dir
        ];
        $this->viewData['availableMcpServers'] = $availableServers;

        // Get available Anthropic keys for claude_api provider
        $this->viewData['anthropicKeys'] = AnthropicKeyService::getAllKeys($this->member->id);
        $this->viewData['providers'] = LLMProviderFactory::getAllProvidersInfo();
        $this->viewData['capabilities'] = self::CAPABILITIES;
        $this->viewData['providerConfigs'] = $this->getAllProviderConfigs();
        // csrf already set by parent constructor
        $this->viewData['activeTab'] = $this->getParam('tab', 'general');

        // MCP config data for workspace-aware API
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $baseUrl = \app\services\SiteConfig::getWorkspaceUrl();

        // Get API key from workspace config for MCP presets
        $workspaceApiKey = '';
        $configFile = BASE_PATH . '/conf/config.' . $workspaceSlug . '.ini';
        if (file_exists($configFile)) {
            $workspaceConfig = parse_ini_file($configFile, true);
            $workspaceApiKey = $workspaceConfig['api']['api_key'] ?? '';
        }

        $this->viewData['workspaceSlug'] = $workspaceSlug;
        $this->viewData['workspaceApiKey'] = $workspaceApiKey;
        $this->viewData['apiBaseUrl'] = $baseUrl;
        $this->viewData['mcpApiUrl'] = "{$baseUrl}/api/mcp/{$workspaceSlug}";

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

        // DEBUG
        $this->logger->debug("POST data", ['post' => $_POST]);

        if (!empty($name)) {
            $agent->name = $name;
        }
        $agent->description = $description;

        if (LLMProviderFactory::getProviderInfo($provider)) {
            $agent->provider = $provider;
            $agent->provider_config = json_encode($this->buildRunnerConfig($provider));

            // Handle Anthropic API key assignment for claude_api provider
            $connectionId = $this->getParam('connections_id');
            if ($provider === 'claude_api' && $connectionId) {
                $key = Bean::load('connections', (int)$connectionId);
                if ($key->id && $key->connector_type === 'anthropic') {
                    $agent->connections_id = (int)$connectionId;
                }
            } elseif ($provider !== 'claude_api') {
                // Clear key assignment if switching away from claude_api
                $agent->connections_id = null;
            }
        }

        $agent->is_active = $isActive ? 1 : 0;

        if ($isDefault) {
            // Workspace-level - unset all other defaults
            Bean::exec('UPDATE aiagents SET is_default = 0 WHERE 1');
            $agent->is_default = 1;
        } else {
            $agent->is_default = 0;
        }

        // Update workstation assignment
        $workstationId = $this->getParam('runners_id');
        if ($workstationId === '' || $workstationId === '0') {
            // Clear assignment (use local runner)
            $agent->runners_id = null;
        } elseif ($workstationId) {
            // Verify the workstation exists and is active
            $runner = Bean::findOne('runners', 'id = ? AND is_active = 1', [(int)$workstationId]);
            if ($runner) {
                $agent->runners_id = (int)$workstationId;
            }
        }

        // Update default working directory
        $defaultWorkDir = trim($this->getParam('default_work_dir', ''));
        $agent->default_work_dir = $defaultWorkDir ?: null;
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
     * AJAX: Link/unlink MCP server to agent
     * POST /agents/linkmcp/{agentId}
     */
    public function linkmcp($params = []) {
        if (!$this->requireLogin()) return;

        $agentId = (int) ($this->opId() ?? $params[0] ?? 0);
        $agent = Bean::load('aiagents', $agentId);

        if (!$agent->id) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        $action = $this->getParam('action'); // 'add' or 'remove'
        $serverId = (int) $this->getParam('server_id');

        if (!$action || !$serverId) {
            Flight::jsonError('Missing action or server_id', 400);
            return;
        }

        $server = Bean::load('mcpservers', $serverId);
        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Verify access to server (own or shared)
        if ($server->member_id != $this->member->id && !$server->is_shared) {
            Flight::jsonError('Access denied to server', 403);
            return;
        }

        if ($action === 'add') {
            // Check if already linked
            $alreadyLinked = false;
            foreach ($agent->sharedMcpserversList as $linked) {
                if ($linked->id == $serverId) {
                    $alreadyLinked = true;
                    break;
                }
            }

            if (!$alreadyLinked) {
                $agent->sharedMcpserversList[] = $server;
                Bean::store($agent);
            }

            Flight::jsonSuccess(['linked' => true], 'Server linked');
        } elseif ($action === 'remove') {
            // Remove from the list
            $newList = [];
            foreach ($agent->sharedMcpserversList as $linked) {
                if ($linked->id != $serverId) {
                    $newList[] = $linked;
                }
            }
            $agent->sharedMcpserversList = $newList;
            Bean::store($agent);

            Flight::jsonSuccess(['linked' => false], 'Server unlinked');
        } else {
            Flight::jsonError('Invalid action', 400);
        }
    }

    /**
     * Delete agent
     */
    public function delete($params = []) {
        if (!$this->requireEnterprise()) return;
        if (!$this->validateCSRFHeader()) {
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
    /**
     * Run agent test via Agent Test pipeline
     * POST /agents/testagent/{agent_id}
     *
     * Triggers the 'agent-test' pipeline which:
     * 1. Spawns the agent with a test prompt
     * 2. Waits for job completion (harvest step)
     * 3. Terminates the session gracefully (reaper step)
     */
    public function testagent($params = []) {
        if (!$this->requireEnterprise()) return;

        require_once __DIR__ . '/../services/PipelineExecutor.php';

        $agentId = (int) ($this->opId() ?? $this->getParam('agent_id') ?? 0);
        $workstationId = (int) ($this->getParam('workstation_id') ?? 0);

        if (!$agentId || !$workstationId) {
            Flight::jsonError('Agent ID and Workstation ID are required', 400);
            return;
        }

        $memberId = $this->member->id ?? 1;

        // Find the agent-test pipeline
        $pipeline = Bean::findOne('pipelines', 'slug = ? AND is_active = 1', ['agent-test']);
        if (!$pipeline) {
            // Fallback to legacy method if pipeline doesn't exist
            require_once __DIR__ . '/../services/JobExecutorConfig.php';
            $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
            $result = \app\services\JobExecutorConfig::submitTestJob($agentId, $workstationId, $memberId, $workspaceSlug);
            if ($result['success']) {
                Flight::jsonSuccess($result);
            } else {
                Flight::jsonError($result['error'] ?? 'Test submission failed', 500);
            }
            return;
        }

        // Create pipeline run with context
        try {
            $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
            if ($stepCount === 0) {
                Flight::jsonError('Agent test pipeline has no active steps', 400);
                return;
            }

            $runUid = 'agent-test-' . bin2hex(random_bytes(8));

            // Build context: merge default context with test arguments
            $defaultContext = json_decode($pipeline->default_context_json ?: '{}', true);
            $triggerData = [
                'agent_id' => $agentId,
                'runner_id' => $workstationId,
            ];
            $context = array_merge($defaultContext, $triggerData);

            $run = Bean::dispense('pipelineruns');
            $run->run_uid = $runUid;
            $run->pipelines = $pipeline;
            $run->member_id = $memberId;
            $run->trigger_source = 'agent_test';
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

            // Dispatch via engine or background process
            $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
            \app\services\PipelineDispatcher::dispatch($runId, $workspaceSlug);

            $this->logger->info("Agent test pipeline started", [
                'run_id' => $runId,
                'run_uid' => $runUid,
                'agent_id' => $agentId,
                'workstation_id' => $workstationId,
            ]);

            Flight::jsonSuccess([
                'success' => true,
                'test_id' => 'pipeline-' . $runId,
                'run_id' => $runId,
                'run_uid' => $runUid,
                'status' => 'started',
                'message' => 'Agent test started via pipeline',
            ]);
        } catch (\Exception $e) {
            Flight::get('log')->error('Agent test pipeline failed', ['error' => $e->getMessage()]);
            Flight::jsonError('Test failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get test status
     * GET /agents/teststatus/{test_id}
     *
     * Polls the status of a background agent test.
     * Checks database first (job-executor jobs), falls back to file for legacy tests.
     */
    public function teststatus($params = []) {
        if (!$this->requireEnterprise()) return;

        require_once __DIR__ . '/../services/JobExecutorConfig.php';

        $testId = $this->opId() ?? $this->getParam('test_id') ?? '';
        $sendExit = $this->getParam('send_exit') === '1' || $this->getParam('send_exit') === 'true';

        if (empty($testId)) {
            Flight::jsonError('Test ID is required', 400);
            return;
        }

        // SECURITY (MED path traversal): test_id is interpolated into a /tmp file
        // path; restrict to a safe charset so '../' cannot escape the directory.
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $testId)) {
            Flight::jsonError('Invalid test ID', 400);
            return;
        }

        // Check if this is a pipeline-based test
        if (str_starts_with($testId, 'pipeline-')) {
            $runId = (int) substr($testId, strlen('pipeline-'));
            $run = Bean::load('pipelineruns', $runId);

            if (!$run || !$run->id) {
                Flight::jsonError('Pipeline run not found', 404);
                return;
            }

            $status = $run->status ?? 'pending';
            $context = json_decode($run->context_json ?: '{}', true);

            // Calculate duration
            $durationMs = null;
            if ($run->started_at && ($run->completed_at || $run->updated_at)) {
                $startTime = strtotime($run->started_at);
                $endTime = strtotime($run->completed_at ?? $run->updated_at);
                if ($startTime && $endTime) {
                    $durationMs = ($endTime - $startTime) * 1000;
                }
            }

            // Get step run details
            $stepRuns = Bean::find('pipelinestepruns', ' pipelineruns_id = ? ORDER BY id ASC ', [$runId]);
            $steps = [];
            foreach ($stepRuns as $sr) {
                $steps[$sr->step_name] = [
                    'status' => $sr->status,
                    'output' => json_decode($sr->output_json ?: '{}', true),
                    'error' => $sr->error_message,
                ];
            }

            // Check for spawn_agent step to get job info
            $jobUid = null;
            $workDir = null;
            if (isset($steps['spawn_agent']['output']['job_key'])) {
                $jobUid = $steps['spawn_agent']['output']['job_key'];
                // Also check for aidevjobs record
                $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$jobUid]);
                if ($job && $job->runners_id) {
                    $runner = Bean::load('runners', $job->runners_id);
                    if ($runner && $runner->id) {
                        $sshUser = $runner->ssh_user ?? 'claudeuser';
                        $workDir = "/home/{$sshUser}/jobs/{$jobUid}";
                    }
                }
            }

            // Map pipeline status to test status
            $testStatus = match ($status) {
                'completed' => 'completed',
                'failed' => 'failed',
                'running' => 'running',
                'awaiting_input' => 'running',
                default => 'pending',
            };

            $response = [
                'test_id' => $testId,
                'status' => $testStatus,
                'run_id' => $runId,
                'run_uid' => $run->run_uid,
                'job_uid' => $jobUid,
                'work_dir' => $workDir,
                'duration_ms' => $durationMs,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'steps_completed' => (int) $run->steps_completed,
                'steps_total' => (int) $run->steps_total,
                'steps' => $steps,
                'source' => 'pipeline',
            ];

            if ($status === 'completed') {
                $response['success'] = true;
                $response['message'] = 'Test completed successfully via pipeline';
            } elseif ($status === 'failed') {
                $response['success'] = false;
                $response['error'] = $run->error_message ?? 'Pipeline failed';
            }

            // Auto-send /exit when test completes via pipeline reaper step
            // The reaper step should handle this, but if needed can be triggered manually
            if ($sendExit && $jobUid && in_array($status, ['completed', 'failed'])) {
                $exitResult = \app\services\JobExecutorConfig::sendExit($jobUid);
                $response['exit_sent'] = $exitResult['success'] ?? false;
                $response['exit_message'] = $exitResult['message'] ?? $exitResult['error'] ?? null;
            }

            Flight::jsonSuccess($response);
            return;
        }

        // First check database for job-executor jobs (job_uid contains test_id)
        $job = Bean::findOne('aidevjobs', 'job_uid LIKE ?', ['%' . $testId]);

        if ($job) {
            $status = $job->status ?? 'pending';
            $phase = $job->phase ?? '';

            // Calculate duration if we have timing info
            $durationMs = null;
            if ($job->started_at && ($job->completed_at || $job->updated_at)) {
                $startTime = strtotime($job->started_at);
                $endTime = strtotime($job->completed_at ?? $job->updated_at);
                if ($startTime && $endTime) {
                    $durationMs = ($endTime - $startTime) * 1000;
                }
            }

            // Load workstation info if available
            $workstationInfo = null;
            if ($job->runners_id) {
                $runner = Bean::load('runners', $job->runners_id);
                if ($runner && $runner->id) {
                    $workstationInfo = [
                        'name' => $runner->name,
                        'host' => $runner->host,
                        'ssh_user' => $runner->ssh_user ?? 'claudeuser',
                    ];
                }
            }

            // Load agent info if available
            $agentInfo = null;
            if ($job->aiagents_id) {
                $agent = Bean::load('aiagents', $job->aiagents_id);
                if ($agent && $agent->id) {
                    $providerConfig = json_decode($agent->provider_config ?: '{}', true);
                    $agentInfo = [
                        'name' => $agent->name,
                        'provider' => $agent->provider ?: 'claude_cli',
                        'use_ollama' => !empty($providerConfig['use_ollama']) || ($agent->provider === 'ollama'),
                        'ollama_model' => $providerConfig['ollama_model'] ?? $providerConfig['model'] ?? '',
                    ];
                }
            }

            // Determine work directory path
            $sshUser = $workstationInfo['ssh_user'] ?? 'claudeuser';
            $workDir = "/home/{$sshUser}/jobs/" . $job->job_uid;

            $response = [
                'test_id' => $testId,
                'status' => $status,
                'phase' => $phase,
                'job_uid' => $job->job_uid,
                'duration_ms' => $durationMs,
                'work_dir' => $workDir,
                'started_at' => $job->started_at,
                'completed_at' => $job->completed_at,
                'agent' => $agentInfo,
                'workstation' => $workstationInfo,
            ];

            // Map status to result format
            if ($status === 'completed') {
                $response['success'] = true;
                $response['message'] = $job->summary ?? 'Test completed successfully';
                $response['output'] = $job->summary ?? '';
            } elseif ($status === 'failed') {
                $response['success'] = false;
                $response['error'] = $job->error_message ?? 'Test failed';
            } elseif (in_array($status, ['running', 'validated'])) {
                $response['status'] = 'running';
            }

            // Automatically send /exit for TEST jobs when completed/failed
            // (or when explicitly requested via send_exit parameter)
            $isTestJob = str_starts_with($job->job_uid ?? '', 'test-');
            $isFinished = in_array($status, ['completed', 'failed']);

            if ($job->job_uid && ($sendExit || ($isTestJob && $isFinished))) {
                $exitResult = \app\services\JobExecutorConfig::sendExit($job->job_uid);
                $response['exit_sent'] = $exitResult['success'] ?? false;
                $response['exit_message'] = $exitResult['message'] ?? $exitResult['error'] ?? null;
            }

            Flight::jsonSuccess($response);
            return;
        }

        // Fall back to status file (legacy method)
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
        $connectionId = $this->getParam('connections_id');
        if ($provider === 'claude_api' && $connectionId) {
            // Verify the connection exists and is an Anthropic key
            $key = Bean::load('connections', (int)$connectionId);
            if ($key && $key->id && $key->connector_type === 'anthropic') {
                $agent->connections_id = (int)$connectionId;
            }
        } elseif ($provider !== 'claude_api') {
            // Clear the key assignment if switching away from claude_api
            $agent->connections_id = null;
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
