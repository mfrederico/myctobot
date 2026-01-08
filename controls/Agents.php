<?php
/**
 * AI Agents Controller
 * Handles agent profile CRUD and configuration (MCP servers, hooks)
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\TierFeatures;
use \app\services\EncryptionService;

require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../lib/Bean.php';

use \app\Bean;

class Agents extends BaseControls\Control {

    /**
     * Valid runner types
     */
    private const RUNNER_TYPES = [
        'claude_cli' => 'Claude CLI (Local)',
        'anthropic_api' => 'Anthropic API',
        'ollama' => 'Ollama (Local LLM)'
    ];

    /**
     * Check Enterprise tier access
     */
    private function requireEnterprise(): bool {
        if (!$this->requireLogin()) return false;

        $tier = $this->member->getTier();
        if (!TierFeatures::hasFeature($tier, TierFeatures::FEATURE_AI_DEVELOPER)) {
            $this->flash('error', 'This feature requires an Enterprise subscription.');
            Flight::redirect('/settings/subscription');
            return false;
        }

        return true;
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

            // Count repos using this agent (repoconnections is in user SQLite database)
            $repoCount = Bean::count('repoconnections', 'agent_id = ?', [$bean->id]);

            $agents[] = [
                'id' => $bean->id,
                'name' => $bean->name,
                'description' => $bean->description,
                'runner_type' => $bean->runner_type,
                'runner_type_label' => self::RUNNER_TYPES[$bean->runner_type] ?? $bean->runner_type,
                'mcp_count' => count($mcpServers),
                'hooks_count' => $this->countHooks($hooksConfig),
                'repo_count' => $repoCount,
                'is_active' => (bool) $bean->is_active,
                'is_default' => (bool) $bean->is_default,
                'created_at' => $bean->created_at,
                'updated_at' => $bean->updated_at
            ];
        }

        $this->viewData['title'] = 'AI Agent Profiles';
        $this->viewData['agents'] = $agents;
        $this->viewData['runnerTypes'] = self::RUNNER_TYPES;
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
        $this->viewData['runnerTypes'] = self::RUNNER_TYPES;
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

        if (!isset(self::RUNNER_TYPES[$runnerType])) {
            $this->flash('error', 'Invalid runner type');
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
        $agent->runner_type = $runnerType;
        $agent->runner_config = json_encode($runnerConfig);
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
            'runner_type' => $agent->runner_type,
            'runner_config' => json_decode($agent->runner_config ?: '{}', true),
            'mcp_servers' => json_decode($agent->mcp_servers ?: '[]', true),
            'hooks_config' => json_decode($agent->hooks_config ?: '{}', true),
            'is_active' => (bool) $agent->is_active,
            'is_default' => (bool) $agent->is_default
        ];
        $this->viewData['runnerTypes'] = self::RUNNER_TYPES;
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
            case 'mcp':
                $this->updateMcp($agent);
                break;
            case 'hooks':
                $this->updateHooks($agent);
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

        if (isset(self::RUNNER_TYPES[$runnerType])) {
            $agent->runner_type = $runnerType;
            $agent->runner_config = json_encode($this->buildRunnerConfig($runnerType));
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
}
