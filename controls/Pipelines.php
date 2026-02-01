<?php
/**
 * Pipelines Controller
 * Handles pipeline CRUD, execution, and webhook endpoints
 *
 * Pipelines are spreadsheet-like automation workflows with:
 * - Fixed columns (phases like Start, Execute, Validate, Deploy)
 * - Named steps (cells) that can reference each other's output
 * - Multiple trigger types: manual, webhook, cron, http_poll
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\WorkspaceResolver;
use \app\services\ApiAuthService;
use \app\services\StepTypeRegistry;
use \Exception as Exception;

require_once __DIR__ . '/../services/StepTypeRegistry.php';

class Pipelines extends BaseControls\Control {

    /**
     * Available trigger types
     */
    private const TRIGGER_TYPES = [
        'manual' => [
            'label' => 'Manual',
            'description' => 'Triggered via form or API call',
            'icon' => 'bi-hand-index'
        ],
        'webhook' => [
            'label' => 'Webhook',
            'description' => 'Triggered by incoming webhook (Jira, GitHub, custom)',
            'icon' => 'bi-link-45deg'
        ],
        'cron' => [
            'label' => 'Scheduled',
            'description' => 'Run on a schedule (cron expression)',
            'icon' => 'bi-clock'
        ],
        'http_poll' => [
            'label' => 'HTTP Poll',
            'description' => 'Poll a URL at intervals',
            'icon' => 'bi-arrow-repeat'
        ]
    ];

    /**
     * List all pipelines
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $pipelines = Bean::findAll('pipelines', ' ORDER BY updated_at DESC ');

        $pipelineList = [];
        foreach ($pipelines as $p) {
            $columns = json_decode($p->columns_json ?: '[]', true);
            $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ?', [$p->id]);
            $recentRuns = Bean::find('pipelineruns',
                ' pipelines_id = ? ORDER BY created_at DESC LIMIT 5 ',
                [$p->id]
            );

            // Calculate success rate from recent runs
            $completedRuns = 0;
            $failedRuns = 0;
            foreach ($recentRuns as $run) {
                if ($run->status === 'completed') $completedRuns++;
                if ($run->status === 'failed') $failedRuns++;
            }
            $totalRecent = $completedRuns + $failedRuns;
            $successRate = $totalRecent > 0 ? round(($completedRuns / $totalRecent) * 100) : null;

            $pipelineList[] = [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'description' => $p->description,
                'columns' => $columns,
                'column_count' => count($columns),
                'step_count' => $stepCount,
                'trigger_type' => $p->trigger_type,
                'trigger_info' => self::TRIGGER_TYPES[$p->trigger_type] ?? null,
                'is_active' => (bool) $p->is_active,
                'is_system' => (bool) $p->is_system,
                'run_count' => $p->run_count ?? 0,
                'last_run_at' => $p->last_run_at,
                'success_rate' => $successRate,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at
            ];
        }

        $this->viewData['title'] = 'Pipelines';
        $this->viewData['pipelines'] = $pipelineList;
        $this->viewData['triggerTypes'] = self::TRIGGER_TYPES;

        $this->render('pipelines/index', $this->viewData);
    }

    /**
     * Show create form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Create Pipeline';
        $this->viewData['pipeline'] = null;
        $this->viewData['triggerTypes'] = self::TRIGGER_TYPES;
        $this->viewData['defaultColumns'] = ['Start', 'Execute', 'Validate', 'Complete'];

        $this->render('pipelines/edit', $this->viewData);
    }

    /**
     * Store new pipeline
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/pipelines');
            return;
        }

        $name = trim($this->getParam('name', ''));
        $slug = trim($this->getParam('slug', ''));
        $description = trim($this->getParam('description', ''));
        $triggerType = $this->getParam('trigger_type', 'manual');
        $columnsRaw = $this->getParam('columns', '');

        if (empty($name)) {
            $this->flash('error', 'Pipeline name is required');
            Flight::redirect('/pipelines/create');
            return;
        }

        // Generate slug if not provided
        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->sanitizeSlug($slug);
        }

        // Check slug uniqueness
        $existing = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        if ($existing) {
            $this->flash('error', 'A pipeline with this slug already exists');
            Flight::redirect('/pipelines/create');
            return;
        }

        // Parse columns
        $columns = $this->parseColumns($columnsRaw);
        if (empty($columns)) {
            $columns = ['Start', 'Execute', 'Validate', 'Complete'];
        }

        // Validate trigger type
        if (!isset(self::TRIGGER_TYPES[$triggerType])) {
            $triggerType = 'manual';
        }

        $pipeline = Bean::dispense('pipelines');
        $pipeline->member = $this->member;
        $pipeline->slug = $slug;
        $pipeline->name = $name;
        $pipeline->description = $description;
        $pipeline->columns_json = json_encode($columns);
        $pipeline->trigger_type = $triggerType;
        $pipeline->trigger_config_json = '{}';
        $pipeline->default_context_json = '{}';
        $pipeline->is_active = 1;
        $pipeline->run_count = 0;
        $pipeline->created_at = date('Y-m-d H:i:s');
        $pipeline->updated_at = date('Y-m-d H:i:s');

        $id = Bean::store($pipeline);

        $this->flash('success', 'Pipeline created');
        Flight::redirect('/pipelines/edit/' . $id);
    }

    /**
     * Show edit form (grid editor)
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$id]);
        if (!$pipeline) {
            $this->flash('error', 'Pipeline not found');
            Flight::redirect('/pipelines');
            return;
        }

        $columns = json_decode($pipeline->columns_json ?: '[]', true);
        $triggerConfig = json_decode($pipeline->trigger_config_json ?: '{}', true);
        $defaultContext = json_decode($pipeline->default_context_json ?: '{}', true);

        // Get steps organized by row/col
        $steps = Bean::find('pipelinesteps',
            ' pipelines_id = ? ORDER BY row ASC, col ASC ',
            [$pipeline->id]
        );

        $stepGrid = [];
        $maxRow = 0;
        foreach ($steps as $step) {
            $row = (int) $step->row;
            $col = (int) $step->col;
            $maxRow = max($maxRow, $row);

            if (!isset($stepGrid[$row])) {
                $stepGrid[$row] = [];
            }

            $stepGrid[$row][$col] = [
                'id' => $step->id,
                'step_name' => $step->step_name,
                'label' => $step->label,
                'step_type' => $step->step_type,
                'type_info' => StepTypeRegistry::get($step->step_type),
                'config' => json_decode($step->config_json ?: '{}', true),
                'input_source' => $step->input_source,
                'input_config' => json_decode($step->input_config_json ?: '{}', true),
                'condition' => json_decode($step->condition_json ?: '{}', true),
                'on_success' => $step->on_success,
                'on_failure' => $step->on_failure,
                'timeout_seconds' => $step->timeout_seconds,
                'retry_count' => $step->retry_count,
                'is_active' => (bool) $step->is_active
            ];
        }

        // Get available agents for ai_agent step type
        $agents = Bean::findAll('aiagents', ' is_active = 1 ORDER BY name ASC ');
        $agentList = [];
        foreach ($agents as $agent) {
            $agentList[] = [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description
            ];
        }

        // Get available repos for script step type
        $repos = Bean::findAll('repoconnections', ' is_active = 1 ORDER BY repo_name ASC ');
        $repoList = [];
        foreach ($repos as $repo) {
            $repoList[] = [
                'id' => $repo->id,
                'name' => $repo->repo_full_name ?? $repo->repo_name,
                'slug' => $repo->slug
            ];
        }

        // Get available runners (for AI agents)
        $runners = Bean::findAll('runners', ' is_active = 1 ORDER BY name ASC ');
        $runnerList = [];
        $workstationList = [];
        foreach ($runners as $runner) {
            $runnerList[] = [
                'id' => $runner->id,
                'name' => $runner->name,
                'host' => $runner->host
            ];
            // Also use as workstations for SSH execution
            if ($runner->ssh_user && $runner->host) {
                $workstationList[] = [
                    'id' => $runner->id,
                    'name' => $runner->name,
                    'ssh_user' => $runner->ssh_user,
                    'ssh_host' => $runner->host,
                    'ssh_port' => $runner->ssh_port ?: 22
                ];
            }
        }

        // Get available MCP servers for mcp_call step type
        // Use same logic as Mcpservers controller: own servers + shared servers, must be active
        $mcpServers = Bean::find('mcpservers',
            ' is_active = 1 AND (member_id = ? OR is_shared = 1) ORDER BY name ASC ',
            [$this->member->id]
        );
        $mcpServerList = [];
        foreach ($mcpServers as $server) {
            $mcpServerList[] = [
                'id' => $server->id,
                'name' => $server->name,
                'description' => $server->description,
                'server_type' => $server->server_type
            ];
        }

        // Get available Shopify connections for shopify_graphql step type
        $shopifyConnections = Bean::find('shopifyconnections',
            ' enabled = 1 AND (created_by_member_id = ? OR shared = 1) ORDER BY shop_name ASC ',
            [$this->member->id]
        );

        // Build webhook URL for this pipeline
        // If using subdomain routing, workspace is in subdomain, not path
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $subdomainWorkspace = $_SERVER['WORKSPACE'] ?? null;
        $baseUrl = Flight::get('app.baseurl') ?: 'https://myctobot.ai';

        if ($subdomainWorkspace) {
            // Subdomain routing: https://gwt.myctobot.ai/pipein/{slug}
            $webhookUrl = "https://{$subdomainWorkspace}.myctobot.ai/pipein/{$pipeline->slug}";
            $mcpToolsUrl = "https://{$subdomainWorkspace}.myctobot.ai/pipelines/mcp/tools";
        } else {
            // Path routing: https://myctobot.ai/pipein/{workspace}/{slug}
            $webhookUrl = "{$baseUrl}/pipein/{$workspaceSlug}/{$pipeline->slug}";
            $mcpToolsUrl = "{$baseUrl}/pipelines/mcp/tools/{$workspaceSlug}";
        }

        $this->viewData['title'] = 'Edit Pipeline: ' . $pipeline->name;
        $this->viewData['pipeline'] = [
            'id' => $pipeline->id,
            'slug' => $pipeline->slug,
            'name' => $pipeline->name,
            'description' => $pipeline->description,
            'columns' => $columns,
            'trigger_type' => $pipeline->trigger_type,
            'trigger_config' => $triggerConfig,
            'default_context' => $defaultContext,
            'is_active' => (bool) $pipeline->is_active,
            'is_system' => (bool) $pipeline->is_system,
            'run_count' => $pipeline->run_count,
            'last_run_at' => $pipeline->last_run_at,
            'expose_as_tool' => (bool) $pipeline->expose_as_tool,
            'input_schema_json' => $pipeline->input_schema_json ?: '{}'
        ];
        $this->viewData['mcpToolsUrl'] = $mcpToolsUrl;
        $this->viewData['workspaceSlug'] = $workspaceSlug;
        $this->viewData['stepGrid'] = $stepGrid;
        $this->viewData['maxRow'] = $maxRow;
        $this->viewData['stepTypes'] = StepTypeRegistry::getAll();
        $this->viewData['stepTypesGrouped'] = StepTypeRegistry::getAll(true);
        $this->viewData['triggerTypes'] = self::TRIGGER_TYPES;
        $this->viewData['agents'] = $agentList;
        $this->viewData['repos'] = $repoList;
        $this->viewData['runners'] = $runnerList;
        $this->viewData['workstations'] = $workstationList;
        $this->viewData['mcpServers'] = $mcpServerList;
        $this->viewData['shopifyConnections'] = $shopifyConnections;
        $this->viewData['webhookUrl'] = $webhookUrl;

        $this->render('pipelines/edit', $this->viewData);
    }

    /**
     * Update pipeline settings
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$id]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        $name = trim($this->getParam('name', ''));
        $slug = trim($this->getParam('slug', ''));
        $description = trim($this->getParam('description', ''));
        $triggerType = $this->getParam('trigger_type', 'manual');
        $triggerConfigJson = $this->getParam('trigger_config', '{}');
        $defaultContextJson = $this->getParam('default_context', '{}');
        $columnsRaw = $this->getParam('columns', '');
        $isActive = (bool) $this->getParam('is_active', true);

        if (!empty($name)) {
            $pipeline->name = $name;
        }

        if (!empty($slug)) {
            $slug = $this->sanitizeSlug($slug);
            // Check uniqueness (excluding self)
            $existing = Bean::findOne('pipelines', 'slug = ? AND id != ?', [$slug, $pipeline->id]);
            if ($existing) {
                Flight::jsonError('A pipeline with this slug already exists', 400);
                return;
            }
            $pipeline->slug = $slug;
        }

        $pipeline->description = $description;

        if (isset(self::TRIGGER_TYPES[$triggerType])) {
            $pipeline->trigger_type = $triggerType;
        }

        // Validate and store trigger config
        $triggerConfig = json_decode($triggerConfigJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $pipeline->trigger_config_json = json_encode($triggerConfig);
        }

        // Validate and store default context
        $defaultContext = json_decode($defaultContextJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $pipeline->default_context_json = json_encode($defaultContext);
        }

        // Update columns if provided
        if (!empty($columnsRaw)) {
            $columns = $this->parseColumns($columnsRaw);
            if (!empty($columns)) {
                $pipeline->columns_json = json_encode($columns);
            }
        }

        $pipeline->is_active = $isActive ? 1 : 0;

        // MCP Tool exposure settings
        $exposeAsTool = (bool) $this->getParam('expose_as_tool', false);
        $inputSchemaJson = $this->getParam('input_schema_json', '{}');

        $pipeline->expose_as_tool = $exposeAsTool ? 1 : 0;

        // Validate and store input schema
        $inputSchema = json_decode($inputSchemaJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $pipeline->input_schema_json = json_encode($inputSchema);
        }

        $pipeline->updated_at = date('Y-m-d H:i:s');

        Bean::store($pipeline);

        Flight::jsonSuccess(['message' => 'Pipeline updated']);
    }

    /**
     * Delete pipeline
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$id]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        // Prevent deletion of system pipelines
        if ($pipeline->is_system) {
            Flight::jsonError('System pipelines cannot be deleted', 403);
            return;
        }

        // Delete all steps
        $steps = Bean::find('pipelinesteps', 'pipelines_id = ?', [$pipeline->id]);
        foreach ($steps as $step) {
            Bean::trash($step);
        }

        // Delete all runs and step runs
        $runs = Bean::find('pipelineruns', 'pipelines_id = ?', [$pipeline->id]);
        foreach ($runs as $run) {
            $stepRuns = Bean::find('pipelinestepruns', 'pipelineruns_id = ?', [$run->id]);
            foreach ($stepRuns as $stepRun) {
                Bean::trash($stepRun);
            }
            Bean::trash($run);
        }

        Bean::trash($pipeline);

        Flight::jsonSuccess(['message' => 'Pipeline deleted']);
    }

    // =========================================================================
    // Step CRUD (AJAX)
    // =========================================================================

    /**
     * Save a step (create or update)
     */
    public function savestep($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        // Prevent modification of system pipelines unless explicitly unlocked
        $systemUnlocked = $this->getParam('system_unlocked') === '1' || $this->getParam('system_unlocked') === 'true';
        if ($pipeline->is_system && !$systemUnlocked) {
            Flight::jsonError('System pipelines cannot be modified. Unlock the pipeline first.', 403);
            return;
        }

        $stepName = trim($this->getParam('step_name', ''));
        $label = trim($this->getParam('label', ''));
        $row = (int) $this->getParam('row', 0);
        $col = (int) $this->getParam('col', 0);
        $stepType = $this->getParam('step_type', 'direct_exec');
        $configJson = $this->getParam('config', '{}');
        $inputSource = $this->getParam('input_source', 'context');
        $inputConfigJson = $this->getParam('input_config', '{}');
        $conditionJson = $this->getParam('condition', '{}');
        $onSuccess = $this->getParam('on_success', 'next_col');
        $onFailure = $this->getParam('on_failure', 'exit');
        $timeoutSeconds = (int) $this->getParam('timeout_seconds', 300);
        $retryCount = (int) $this->getParam('retry_count', 0);
        $isActive = (bool) $this->getParam('is_active', true);
        $runParallel = (bool) $this->getParam('run_parallel', false);

        // Validate step name
        if (empty($stepName)) {
            Flight::jsonError('Step name is required', 400);
            return;
        }

        // Validate step name format (alphanumeric + underscores)
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $stepName)) {
            Flight::jsonError('Step name must start with lowercase letter and contain only lowercase letters, numbers, and underscores', 400);
            return;
        }

        // Validate step type
        if (!StepTypeRegistry::get($stepType)) {
            Flight::jsonError('Invalid step type', 400);
            return;
        }

        // Check step name uniqueness within pipeline
        $existing = Bean::findOne('pipelinesteps',
            'pipelines_id = ? AND step_name = ? AND id != ?',
            [$pipelineId, $stepName, $stepId]
        );
        if ($existing) {
            Flight::jsonError('A step with this name already exists in this pipeline', 400);
            return;
        }

        // Parse JSON fields
        $config = json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid config JSON', 400);
            return;
        }

        $inputConfig = json_decode($inputConfigJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $inputConfig = [];
        }

        $condition = json_decode($conditionJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $condition = [];
        }

        // Get or create step
        if ($stepId > 0) {
            $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
            if (!$step) {
                Flight::jsonError('Step not found', 404);
                return;
            }
        } else {
            $step = Bean::dispense('pipelinesteps');
            $step->pipelines = $pipeline;
            $step->created_at = date('Y-m-d H:i:s');
        }

        $step->step_name = $stepName;
        $step->label = $label ?: $stepName;
        $step->row = $row;
        $step->col = $col;
        $step->step_type = $stepType;
        $step->config_json = json_encode($config);
        $step->input_source = $inputSource;
        $step->input_config_json = json_encode($inputConfig);
        $step->condition_json = json_encode($condition);
        $step->on_success = $onSuccess;
        $step->on_failure = $onFailure;
        $step->timeout_seconds = max(1, $timeoutSeconds);
        $step->retry_count = max(0, $retryCount);
        $step->retry_delay_seconds = 10;
        $step->is_active = $isActive ? 1 : 0;
        $step->run_parallel = $runParallel ? 1 : 0;
        $step->sequence = ($row * 100) + $col;
        $step->updated_at = date('Y-m-d H:i:s');

        $id = Bean::store($step);

        // Update pipeline timestamp
        $pipeline->updated_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        Flight::jsonSuccess([
            'id' => $id,
            'step_name' => $stepName,
            'message' => $stepId > 0 ? 'Step updated' : 'Step created'
        ]);
    }

    /**
     * Delete a step
     */
    public function deletestep($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);

        // Check if pipeline is a system pipeline
        $pipeline = Bean::load('pipelines', $pipelineId);
        $systemUnlocked = $this->getParam('system_unlocked') === '1' || $this->getParam('system_unlocked') === 'true';
        if ($pipeline->is_system && !$systemUnlocked) {
            Flight::jsonError('Cannot modify steps in system pipelines. Unlock first.', 403);
            return;
        }

        $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
        if (!$step) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        Bean::trash($step);

        Flight::jsonSuccess(['message' => 'Step deleted']);
    }

    /**
     * Update just the run_parallel flag for a step
     * Used by the row-level parallel toggle
     *
     * POST /pipelines/updatestepparallel/{pipeline_id}
     */
    public function updatestepparallel($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);
        $runParallel = $this->getParam('run_parallel', '0') === '1';

        $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
        if (!$step) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        $step->run_parallel = $runParallel ? 1 : 0;
        $step->updated_at = date('Y-m-d H:i:s');
        Bean::store($step);

        Flight::jsonSuccess(['message' => 'Parallel mode updated', 'run_parallel' => $runParallel]);
    }

    /**
     * Test a command/expression against sample input data
     * Used by the Input Inspector to preview jq, bash, or other stdio results
     *
     * POST /pipelines/testparser
     *
     * Supports:
     * - jq: JSON transformation
     * - bash: Shell command with stdin
     * - regex: Pattern matching
     * - php: (disabled for security)
     */
    public function testparser($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $parserType = $this->getParam('parser_type', 'jq');
        $expression = $this->getParam('expression', '');
        $command = $this->getParam('command', '');  // For bash/direct_exec
        $input = $this->getParam('input', '{}');
        $timeout = min((int) $this->getParam('timeout', 5), 30);  // Max 30 seconds

        // Use command if provided, otherwise use expression
        $toExecute = !empty($command) ? $command : $expression;

        if (empty($toExecute)) {
            Flight::jsonError('Expression or command is required', 400);
            return;
        }

        try {
            switch ($parserType) {
                case 'jq':
                    // Test jq expression
                    $tempFile = tempnam(sys_get_temp_dir(), 'jq_input_');
                    file_put_contents($tempFile, $input);

                    // Escape the expression for shell
                    $escapedExpr = escapeshellarg($toExecute);

                    // Run jq with timeout
                    $cmd = "timeout {$timeout}s jq {$escapedExpr} " . escapeshellarg($tempFile) . " 2>&1";
                    $output = shell_exec($cmd);
                    unlink($tempFile);

                    if ($output === null) {
                        Flight::jsonError('jq command failed to execute', 500);
                        return;
                    }

                    // Check for jq errors
                    if (strpos($output, 'jq: error') !== false || strpos($output, 'parse error') !== false) {
                        Flight::jsonError(trim($output), 400);
                        return;
                    }

                    Flight::jsonSuccess(['output' => trim($output)]);
                    break;

                case 'bash':
                case 'direct_exec':
                    // Test bash command with stdin
                    // SECURITY: Only allow testing on the local machine, not shards
                    // And limit what commands can be tested (no rm, no curl to external, etc)

                    $tempFile = tempnam(sys_get_temp_dir(), 'bash_input_');
                    file_put_contents($tempFile, $input);

                    // Build a safe test command - pipe input to the command
                    // We wrap in a subshell with timeout for safety
                    $escapedCmd = $toExecute;

                    // Simple safety checks - block obviously dangerous patterns
                    $blockedPatterns = ['rm -rf', 'dd if=', 'mkfs', '> /dev', 'curl', 'wget', 'nc ', 'netcat'];
                    foreach ($blockedPatterns as $pattern) {
                        if (stripos($escapedCmd, $pattern) !== false) {
                            Flight::jsonError("Command contains blocked pattern: {$pattern}", 400);
                            return;
                        }
                    }

                    // Run with timeout and capture output
                    $fullCmd = "timeout {$timeout}s /bin/bash -c " . escapeshellarg($escapedCmd) . " < " . escapeshellarg($tempFile) . " 2>&1";
                    $output = shell_exec($fullCmd);
                    $exitCode = 0;  // shell_exec doesn't give us exit code easily

                    unlink($tempFile);

                    Flight::jsonSuccess([
                        'output' => $output ?? '(no output)',
                        'exit_code' => $exitCode
                    ]);
                    break;

                case 'php':
                    // PHP expressions are risky - just show a preview message
                    Flight::jsonSuccess(['output' => '(PHP expressions cannot be safely previewed - run the step to test)']);
                    break;

                case 'regex':
                    // Test regex against input
                    $inputData = json_decode($input, true);
                    $subject = is_string($inputData) ? $inputData : json_encode($inputData);

                    // Validate regex syntax first
                    if (@preg_match($toExecute, '') === false) {
                        Flight::jsonError('Invalid regex pattern: ' . preg_last_error_msg(), 400);
                        return;
                    }

                    if (preg_match($toExecute, $subject, $matches)) {
                        Flight::jsonSuccess(['output' => json_encode($matches, JSON_PRETTY_PRINT)]);
                    } else {
                        Flight::jsonSuccess(['output' => '(No matches found)']);
                    }
                    break;

                default:
                    Flight::jsonError("Unknown parser type: {$parserType}", 400);
            }
        } catch (\Exception $e) {
            Flight::jsonError('Test failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Save output variable mappings for a step
     *
     * POST /pipelines/savestepmappings/{pipeline_id}
     * Body: step_id, mappings (JSON)
     *
     * These mappings make specific output paths available as named variables
     * in subsequent steps' Variable Browser.
     */
    public function savestepmappings($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);
        $mappingsJson = $this->getParam('mappings', '{}');

        $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
        if (!$step) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        // Validate mappings JSON
        $mappings = json_decode($mappingsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid mappings JSON', 400);
            return;
        }

        $step->output_mappings_json = $mappingsJson;
        $step->updated_at = date('Y-m-d H:i:s');
        Bean::store($step);

        Flight::jsonSuccess([
            'message' => 'Mappings saved',
            'mappings' => $mappings
        ]);
    }

    /**
     * Move a step to a new row/col position
     * If target cell is occupied, swap positions
     */
    public function movestep($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);
        $targetRow = (int) $this->getParam('target_row', 0);
        $targetCol = (int) $this->getParam('target_col', 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
        if (!$step) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        $sourceRow = $step->row;
        $sourceCol = $step->col;

        // Check if target cell is occupied
        $targetStep = Bean::findOne('pipelinesteps',
            'pipelines_id = ? AND row = ? AND col = ? AND id != ?',
            [$pipelineId, $targetRow, $targetCol, $stepId]
        );

        if ($targetStep) {
            // Swap positions
            $targetStep->row = $sourceRow;
            $targetStep->col = $sourceCol;
            $targetStep->sequence = ($sourceRow * 100) + $sourceCol;
            $targetStep->updated_at = date('Y-m-d H:i:s');
            Bean::store($targetStep);
        }

        // Move the dragged step
        $step->row = $targetRow;
        $step->col = $targetCol;
        $step->sequence = ($targetRow * 100) + $targetCol;
        $step->updated_at = date('Y-m-d H:i:s');
        Bean::store($step);

        // Update pipeline timestamp
        $pipeline->updated_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        Flight::jsonSuccess([
            'message' => $targetStep ? 'Steps swapped' : 'Step moved',
            'swapped' => (bool) $targetStep,
            'step_id' => $stepId,
            'new_row' => $targetRow,
            'new_col' => $targetCol
        ]);
    }

    /**
     * Toggle all steps in a row (enable/disable)
     */
    public function togglerow($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $row = (int) $this->getParam('row', 0);
        $enable = filter_var($this->getParam('enable', true), FILTER_VALIDATE_BOOLEAN);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        // Find all steps in this row
        $steps = Bean::find('pipelinesteps', 'pipelines_id = ? AND row = ?', [$pipelineId, $row]);

        if (empty($steps)) {
            Flight::jsonError('No steps found in this row', 404);
            return;
        }

        $count = 0;
        foreach ($steps as $step) {
            $step->is_active = $enable ? 1 : 0;
            $step->updated_at = date('Y-m-d H:i:s');
            Bean::store($step);
            $count++;
        }

        // Update pipeline timestamp
        $pipeline->updated_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        Flight::jsonSuccess([
            'message' => sprintf('%d steps %s', $count, $enable ? 'enabled' : 'disabled'),
            'row' => $row,
            'enabled' => $enable,
            'count' => $count
        ]);
    }

    /**
     * Test a shell command (AJAX)
     * Executes the command locally and returns output
     */
    public function testcommand($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $command = trim($input['command'] ?? '');
        $executor = trim($input['executor'] ?? '/bin/bash -c');
        $workingDir = trim($input['working_dir'] ?? '/tmp');

        if (empty($command)) {
            Flight::jsonError('No command specified', 400);
            return;
        }

        // Security: limit command length and timeout
        if (strlen($command) > 10000) {
            Flight::jsonError('Command too long', 400);
            return;
        }

        // Build the full command
        if (!empty($executor)) {
            $fullCommand = $executor . ' ' . escapeshellarg($command);
        } else {
            $fullCommand = $command;
        }

        // Change to working directory
        $originalDir = getcwd();
        if (!empty($workingDir) && is_dir($workingDir)) {
            chdir($workingDir);
        }

        try {
            // Execute with timeout (5 seconds for test)
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            $process = proc_open($fullCommand, $descriptors, $pipes);

            if (!is_resource($process)) {
                Flight::jsonError('Failed to start command', 500);
                return;
            }

            // Close stdin
            fclose($pipes[0]);

            // Set non-blocking and read with timeout
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $timeout = 5; // 5 seconds
            $startTime = time();

            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (time() - $startTime > $timeout) {
                    proc_terminate($process, 9);
                    Flight::json([
                        'success' => false,
                        'error' => 'Command timed out (5 second limit)',
                        'output' => $stdout . $stderr
                    ]);
                    return;
                }
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                usleep(10000); // 10ms
            }

            // Read any remaining output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            $output = trim($stdout);
            if (!empty($stderr)) {
                $output .= ($output ? "\n\n" : '') . "STDERR:\n" . trim($stderr);
            }

            Flight::json([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output ?: '(no output)',
                'error' => $exitCode !== 0 ? "Command exited with code {$exitCode}" : null
            ]);

        } finally {
            chdir($originalDir);
        }
    }

    /**
     * Get step details (AJAX)
     */
    public function getstep($params = []) {
        if (!$this->requireLogin()) return;

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);

        $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
        if (!$step) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        Flight::jsonSuccess([
            'step' => [
                'id' => $step->id,
                'step_name' => $step->step_name,
                'label' => $step->label,
                'row' => (int) $step->row,
                'col' => (int) $step->col,
                'step_type' => $step->step_type,
                'config' => json_decode($step->config_json ?: '{}', true),
                'input_source' => $step->input_source,
                'input_config' => json_decode($step->input_config_json ?: '{}', true),
                'condition' => json_decode($step->condition_json ?: '{}', true),
                'on_success' => $step->on_success,
                'on_failure' => $step->on_failure,
                'timeout_seconds' => (int) $step->timeout_seconds,
                'retry_count' => (int) $step->retry_count,
                'is_active' => (bool) $step->is_active,
                'run_parallel' => (bool) $step->run_parallel,
                'output_mappings' => json_decode($step->output_mappings_json ?: '{}', true)
            ]
        ]);
    }

    // =========================================================================
    // Run Management
    // =========================================================================

    /**
     * List runs for a pipeline
     */
    public function runs($params = []) {
        if (!$this->requireLogin()) return;

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            $this->flash('error', 'Pipeline not found');
            Flight::redirect('/pipelines');
            return;
        }

        $runs = Bean::find('pipelineruns',
            ' pipelines_id = ? ORDER BY created_at DESC LIMIT 50 ',
            [$pipelineId]
        );

        $runList = [];
        foreach ($runs as $run) {
            $runList[] = [
                'id' => $run->id,
                'run_uid' => $run->run_uid,
                'trigger_source' => $run->trigger_source,
                'status' => $run->status,
                'current_step_name' => $run->current_step_name,
                'progress_percent' => $run->progress_percent,
                'steps_completed' => $run->steps_completed,
                'steps_total' => $run->steps_total,
                'error_message' => $run->error_message,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'created_at' => $run->created_at
            ];
        }

        $this->viewData['title'] = 'Runs: ' . $pipeline->name;
        $this->viewData['pipeline'] = [
            'id' => $pipeline->id,
            'slug' => $pipeline->slug,
            'name' => $pipeline->name
        ];
        $this->viewData['runs'] = $runList;

        $this->render('pipelines/runs', $this->viewData);
    }

    /**
     * View a specific run
     */
    public function viewrun($params = []) {
        if (!$this->requireLogin()) return;

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            $this->flash('error', 'Run not found');
            Flight::redirect('/pipelines');
            return;
        }

        $pipeline = Bean::load('pipelines', $run->pipelines_id);
        $columns = json_decode($pipeline->columns_json ?: '[]', true);

        // Get step runs organized by row/col
        $stepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? ORDER BY row ASC, col ASC ',
            [$run->id]
        );

        $stepRunGrid = [];
        foreach ($stepRuns as $sr) {
            $row = (int) $sr->row;
            $col = (int) $sr->col;

            if (!isset($stepRunGrid[$row])) {
                $stepRunGrid[$row] = [];
            }

            $stepRunGrid[$row][$col] = [
                'id' => $sr->id,
                'step_name' => $sr->step_name,
                'status' => $sr->status,
                'attempt_number' => $sr->attempt_number,
                'fault_count' => $sr->fault_count,
                'input' => json_decode($sr->input_json ?: '{}', true),
                'output' => json_decode($sr->output_json ?: '{}', true),
                'stdout' => $sr->stdout,
                'stderr' => $sr->stderr,
                'exit_code' => $sr->exit_code,
                'error_message' => $sr->error_message,
                'duration_ms' => $sr->duration_ms,
                'started_at' => $sr->started_at,
                'completed_at' => $sr->completed_at
            ];
        }

        $this->viewData['title'] = 'Run: ' . $run->run_uid;
        $this->viewData['pipeline'] = [
            'id' => $pipeline->id,
            'slug' => $pipeline->slug,
            'name' => $pipeline->name,
            'columns' => $columns
        ];
        $this->viewData['run'] = [
            'id' => $run->id,
            'run_uid' => $run->run_uid,
            'trigger_source' => $run->trigger_source,
            'trigger_data' => json_decode($run->trigger_data_json ?: '{}', true),
            'status' => $run->status,
            'current_step_name' => $run->current_step_name,
            'context' => json_decode($run->context_json ?: '{}', true),
            'progress_percent' => $run->progress_percent,
            'steps_completed' => $run->steps_completed,
            'steps_total' => $run->steps_total,
            'error_message' => $run->error_message,
            'handoff_run_id' => $run->handoff_run_id,
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at,
            'created_at' => $run->created_at
        ];
        $this->viewData['stepRunGrid'] = $stepRunGrid;

        // If awaiting input, get the awaiting step info
        $awaitingStep = null;
        if ($run->status === 'awaiting_input') {
            $awaitingStepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input = 1 ',
                [$run->id]
            );

            if ($awaitingStepRun) {
                $token = $awaitingStepRun->awaiting_input_token;
                $baseUrl = rtrim($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'], '/');

                $awaitingStep = [
                    'step_name' => $awaitingStepRun->step_name,
                    'prompt' => $awaitingStepRun->awaiting_input_prompt,
                    'schema' => json_decode($awaitingStepRun->awaiting_input_schema_json ?: '{}', true),
                    'timeout_at' => $awaitingStepRun->awaiting_input_timeout_at,
                    'allowed_sources' => json_decode($awaitingStepRun->awaiting_input_sources_json ?: '[]', true),
                    'form_url' => "{$baseUrl}/pipelines/form/{$run->id}?token={$token}",
                    'webhook_url' => "{$baseUrl}/pipelines/input/{$run->id}?token={$token}",
                    'input_token' => $token
                ];
            }
        }
        $this->viewData['awaitingStep'] = $awaitingStep;

        $this->render('pipelines/viewrun', $this->viewData);
    }

    /**
     * Trigger a manual run
     */
    public function trigger($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $contextJson = $this->getParam('context', '{}');

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        if (!$pipeline->is_active) {
            Flight::jsonError('Pipeline is not active', 400);
            return;
        }

        // Parse context
        $triggerData = json_decode($contextJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $triggerData = [];
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member = $this->member;
        $run->trigger_source = 'manual';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = $pipeline->default_context_json ?: '{}';
        $run->steps_total = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        $run->steps_completed = 0;
        $run->progress_percent = 0;
        $run->created_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');

        $runId = Bean::store($run);

        // Update pipeline stats
        $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
        $pipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        // Spawn background execution
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $scriptPath = __DIR__ . '/../scripts/runpipe.php';
        $cmd = sprintf(
            'php %s --workspace=%s --run-id=%d > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($workspaceSlug),
            $runId
        );
        exec($cmd);

        Flight::jsonSuccess([
            'run_id' => $runId,
            'run_uid' => $runUid,
            'message' => 'Pipeline run started'
        ]);
    }

    /**
     * Run pipeline synchronously and wait for completion
     *
     * POST /pipelines/runsync/{slug}
     * Body: {"context": {...}}
     *
     * Returns full results when pipeline completes.
     * Use for tool calls, MCP, or when you need to wait for results.
     */
    public function runsync($params = []) {
        // Allow both authenticated users and API calls with token
        $authenticated = $this->isLoggedIn();
        $memberId = $this->member->id ?? null;

        // If not logged in via session, try API key authentication
        if (!$authenticated) {
            $authResult = ApiAuthService::authenticate('pipelines', 'runsync');
            if (!$authResult['success']) {
                Flight::jsonError($authResult['error'], $authResult['code']);
                return;
            }
            $memberId = $authResult['member']->id;
        }

        $slug = $this->opId() ?? $this->getParam('slug');
        $contextJson = $this->getParam('context', '{}');

        // Find pipeline by slug or ID
        if (is_numeric($slug)) {
            $pipeline = Bean::load('pipelines', (int) $slug);
        } else {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        }

        if (!$pipeline || !$pipeline->id) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        if (!$pipeline->is_active) {
            Flight::jsonError('Pipeline is not active', 400);
            return;
        }

        // Parse context
        $triggerData = is_array($contextJson) ? $contextJson : json_decode($contextJson, true);
        if (!is_array($triggerData)) {
            $triggerData = [];
        }

        // Count steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            Flight::jsonError('Pipeline has no active steps', 400);
            return;
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member_id = $memberId;
        $run->trigger_source = 'api_sync';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = $pipeline->default_context_json ?: '{}';
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
        $success = $executor->execute();

        // Reload run to get final state
        $run = Bean::load('pipelineruns', $runId);
        $context = json_decode($run->context_json ?: '{}', true);

        // Get step results
        $stepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? ORDER BY id ASC ',
            [$runId]
        );

        $steps = [];
        foreach ($stepRuns as $sr) {
            $steps[$sr->step_name] = [
                'status' => $sr->status,
                'output' => json_decode($sr->output_json ?: '{}', true),
                'stdout' => $sr->stdout,
                'stderr' => $sr->stderr,
                'exit_code' => $sr->exit_code,
                'duration_ms' => $sr->duration_ms,
                'error' => $sr->error_message
            ];
        }

        Flight::jsonSuccess([
            'run_id' => $runId,
            'run_uid' => $runUid,
            'status' => $run->status,
            'success' => $run->status === 'completed',
            'steps_completed' => (int) $run->steps_completed,
            'steps_total' => (int) $run->steps_total,
            'duration_seconds' => $run->started_at && $run->completed_at
                ? strtotime($run->completed_at) - strtotime($run->started_at)
                : null,
            'context' => $context,
            'steps' => $steps,
            'error' => $run->error_message
        ]);
    }

    /**
     * Run pipeline synchronously via API with workspace in URL path
     * POST /pipelines/runsyncapi/workspace/{slug}
     *
     * URL pattern matches Pipein webhook style for consistent API design.
     * Validates API token from header or query param.
     *
     * @example POST /pipelines/runsyncapi/gwt/my-deploy-pipeline
     *          X-API-Token: your-api-token
     *          {"context_key": "value"}
     */
    public function runsyncapi($params = []) {
        // Parse URL to extract workspace and slug: /pipelines/runsyncapi/workspace/{slug}
        $url = Flight::request()->url;
        $path = preg_replace('#^/pipelines/runsyncapi/?#', '', $url);
        if (strpos($path, '?') !== false) {
            $path = substr($path, 0, strpos($path, '?'));
        }
        $parts = array_filter(explode('/', $path));
        $parts = array_values($parts);

        if (count($parts) < 2) {
            Flight::jsonError('Invalid URL format. Expected: /pipelines/runsyncapi/{workspace}/{slug}', 400);
            return;
        }

        $workspace = $parts[0];
        $slug = $parts[1];

        // Switch to workspace database first (required for API key lookup)
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            Flight::jsonError("Invalid workspace: {$workspace}", 400);
            return;
        }

        // Validate API token against workspace's apikeys table
        $authResult = ApiAuthService::authenticate('pipelines', 'runsyncapi');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }
        $apiMember = $authResult['member'];

        $this->logger->info("Pipeline API sync run for workspace: {$workspace}, pipeline: {$slug}", [
            'member_id' => $apiMember->id,
            'member_username' => $apiMember->username
        ]);

        // Find pipeline by slug
        $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);

        if (!$pipeline || !$pipeline->id) {
            Flight::jsonError("Pipeline not found: {$slug}", 404);
            return;
        }

        if (!$pipeline->is_active) {
            Flight::jsonError('Pipeline is not active', 400);
            return;
        }

        // Get context from request body
        $rawBody = file_get_contents('php://input');
        $triggerData = [];
        if (!empty($rawBody)) {
            $triggerData = json_decode($rawBody, true);
            if (!is_array($triggerData)) {
                $triggerData = [];
            }
        }

        // Count steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            Flight::jsonError('Pipeline has no active steps', 400);
            return;
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member_id = $apiMember->id;
        $run->trigger_source = 'api_sync';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = $pipeline->default_context_json ?: '{}';
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

        $this->logger->info("Created pipeline run: {$runUid} (ID: {$runId})");

        // Execute synchronously
        require_once __DIR__ . '/../services/PipelineExecutor.php';
        $executor = new \app\services\PipelineExecutor($runId);
        $success = $executor->execute();

        // Reload run to get final state
        $run = Bean::load('pipelineruns', $runId);
        $context = json_decode($run->context_json ?: '{}', true);

        // Get step results
        $stepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? ORDER BY id ASC ',
            [$runId]
        );

        $steps = [];
        foreach ($stepRuns as $sr) {
            $steps[$sr->step_name] = [
                'status' => $sr->status,
                'output' => json_decode($sr->output_json ?: '{}', true),
                'stdout' => $sr->stdout,
                'stderr' => $sr->stderr,
                'exit_code' => $sr->exit_code,
                'duration_ms' => $sr->duration_ms,
                'error' => $sr->error_message
            ];
        }

        Flight::response()->status(200);
        Flight::response()->header('Content-Type', 'application/json');
        echo json_encode([
            'success' => $run->status === 'completed',
            'run_id' => $runId,
            'run_uid' => $runUid,
            'status' => $run->status,
            'steps_completed' => (int) $run->steps_completed,
            'steps_total' => (int) $run->steps_total,
            'duration_seconds' => $run->started_at && $run->completed_at
                ? strtotime($run->completed_at) - strtotime($run->started_at)
                : null,
            'context' => $context,
            'steps' => $steps,
            'error' => $run->error_message
        ]);
    }

    /**
     * List pipelines exposed as MCP tools
     *
     * GET /pipelines/mcp/tools/workspace
     * Header: X-API-TOKEN: your-api-key
     *
     * Returns MCP-compatible tool definitions for all pipelines with expose_as_tool=1
     */
    public function mcptools($workspace = null) {
        if (empty($workspace)) {
            Flight::jsonError('Workspace required. Format: /pipelines/mcp/tools/workspace', 400);
            return;
        }

        // Switch to workspace database first
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            Flight::jsonError("Invalid workspace: {$workspace}", 400);
            return;
        }

        // Authenticate using API key
        $authResult = ApiAuthService::authenticate('pipelines', 'mcptools');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        // Find all pipelines exposed as tools
        $pipelines = Bean::find('pipelines', ' expose_as_tool = 1 AND is_active = 1 ');

        $tools = [];
        foreach ($pipelines as $pipeline) {
            $inputSchema = json_decode($pipeline->input_schema_json ?: '{}', true);
            if (empty($inputSchema) || !isset($inputSchema['type'])) {
                // Default to object with no required properties
                $inputSchema = [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => []
                ];
            }

            $tools[] = [
                'name' => 'myctobot_' . $pipeline->slug,
                'description' => $pipeline->description ?: "Execute the {$pipeline->name} pipeline",
                'inputSchema' => $inputSchema
            ];
        }

        Flight::response()->status(200);
        Flight::response()->header('Content-Type', 'application/json');
        echo json_encode([
            'tools' => $tools
        ]);
    }

    /**
     * Execute a pipeline as an MCP tool call
     *
     * POST /pipelines/mcp/call/workspace/{slug}
     * Header: X-API-TOKEN: your-api-key
     * Body: {"input": {...}}  (matches the pipeline's input schema)
     *
     * Returns tool result in MCP format
     */
    public function mcpcall($workspace = null, $slug = null) {
        if (empty($workspace) || empty($slug)) {
            Flight::jsonError('Invalid URL format. Expected: /pipelines/mcp/call/workspace/{slug}', 400);
            return;
        }

        // Switch to workspace database first
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            Flight::jsonError("Invalid workspace: {$workspace}", 400);
            return;
        }

        // Authenticate using API key
        $authResult = ApiAuthService::authenticate('pipelines', 'mcpcall');
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return;
        }

        $apiMember = $authResult['member'];

        // Find pipeline by slug
        $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);

        if (!$pipeline || !$pipeline->id) {
            Flight::jsonError("Tool not found: myctobot_{$slug}", 404);
            return;
        }

        if (!$pipeline->is_active) {
            Flight::jsonError('Tool is not active', 400);
            return;
        }

        if (!$pipeline->expose_as_tool) {
            Flight::jsonError("Pipeline '{$slug}' is not exposed as a tool", 403);
            return;
        }

        // Get input from request body
        $rawBody = file_get_contents('php://input');
        $requestData = [];

        if (!empty($rawBody)) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $requestData = $decoded;
            }
        }

        // The input for the pipeline comes from the 'arguments' field (MCP standard)
        $triggerData = $requestData['arguments'] ?? $requestData['input'] ?? $requestData;

        // Count steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            Flight::jsonError('Tool has no active steps', 400);
            return;
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member_id = $apiMember->id; // From API key authentication
        $run->trigger_source = 'mcp_tool';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = $pipeline->default_context_json ?: '{}';
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
                'text' => "Tool execution failed: " . ($run->error_message ?: 'Unknown error')
            ];
        } else {
            // Return the context/output as the tool result
            $resultText = json_encode($lastOutput ?? $context, JSON_PRETTY_PRINT);
            $content[] = [
                'type' => 'text',
                'text' => $resultText
            ];
        }

        Flight::response()->status(200);
        Flight::response()->header('Content-Type', 'application/json');
        echo json_encode([
            'content' => $content,
            'isError' => $isError,
            '_meta' => [
                'run_id' => $runId,
                'run_uid' => $runUid,
                'status' => $run->status,
                'duration_seconds' => $run->started_at && $run->completed_at
                    ? strtotime($run->completed_at) - strtotime($run->started_at)
                    : null
            ]
        ]);
    }

    /**
     * Cancel a running pipeline
     */
    public function cancelrun($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        if (!in_array($run->status, ['pending', 'running', 'paused'])) {
            Flight::jsonError('Run cannot be cancelled (status: ' . $run->status . ')', 400);
            return;
        }

        $run->status = 'cancelled';
        $run->completed_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');
        Bean::store($run);

        Flight::jsonSuccess(['message' => 'Run cancelled']);
    }

    /**
     * Resume a paused interactive run
     *
     * POST /pipelines/resumerun/{run_id}
     * Body: mappings (optional JSON of field mappings)
     *
     * This continues execution of an interactive pipeline that paused after a step.
     */
    public function resumerun($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        if ($run->status !== 'paused') {
            Flight::jsonError('Run is not paused (status: ' . $run->status . ')', 400);
            return;
        }

        // Parse optional mappings
        $mappingsJson = $this->getParam('mappings', '[]');
        $mappings = json_decode($mappingsJson, true);
        if (!is_array($mappings)) {
            $mappings = [];
        }

        try {
            $executor = new \app\services\PipelineExecutor($runId);
            $success = $executor->resume($mappings, false, false);

            // Reload run to get current status
            $run = Bean::load('pipelineruns', $runId);

            Flight::jsonSuccess([
                'message' => $run->status === 'paused' ? 'Pipeline paused at next step' : 'Pipeline resumed',
                'status' => $run->status,
                'run_id' => $run->id
            ]);

        } catch (\Exception $e) {
            Flight::jsonError('Failed to resume: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get run status (AJAX polling)
     */
    public function runstatus($params = []) {
        if (!$this->requireLogin()) return;

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Get latest step run statuses
        $stepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? ORDER BY row ASC, col ASC ',
            [$run->id]
        );

        $stepStatuses = [];
        foreach ($stepRuns as $sr) {
            $stepStatuses[$sr->step_name] = [
                'status' => $sr->status,
                'row' => (int) $sr->row,
                'col' => (int) $sr->col,
                'duration_ms' => $sr->duration_ms,
                'error_message' => $sr->error_message
            ];
        }

        Flight::jsonSuccess([
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'current_step_name' => $run->current_step_name,
                'progress_percent' => $run->progress_percent,
                'steps_completed' => $run->steps_completed,
                'steps_total' => $run->steps_total,
                'error_message' => $run->error_message,
                'completed_at' => $run->completed_at
            ],
            'steps' => $stepStatuses
        ]);
    }

    // =========================================================================
    // Interactive/Debug Mode (Pause and Map)
    // =========================================================================

    /**
     * Trigger an interactive/debug run that pauses after each step
     *
     * POST /pipelines/triggerinteractive/{pipeline_id}
     *
     * In interactive mode, the pipeline pauses after each step completes,
     * allowing the user to view the output and map fields to variables
     * before continuing to the next step.
     */
    public function triggerinteractive($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $contextJson = $this->getParam('context', '{}');

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        if (!$pipeline->is_active) {
            Flight::jsonError('Pipeline is not active', 400);
            return;
        }

        // Parse context
        $triggerData = json_decode($contextJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $triggerData = [];
        }

        // Count steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            Flight::jsonError('Pipeline has no active steps', 400);
            return;
        }

        // Create run with interactive mode enabled
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member = $this->member;
        $run->trigger_source = 'interactive';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = $pipeline->default_context_json ?: '{}';
        $run->steps_total = $stepCount;
        $run->steps_completed = 0;
        $run->progress_percent = 0;
        $run->interactive_mode = 1;  // Enable interactive mode
        $run->created_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');

        $runId = Bean::store($run);

        // Update pipeline stats
        $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
        $pipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        // Spawn background execution
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $scriptPath = __DIR__ . '/../scripts/runpipe.php';
        $cmd = sprintf(
            'php %s --workspace=%s --run-id=%d > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($workspaceSlug),
            $runId
        );
        exec($cmd);

        Flight::jsonSuccess([
            'run_id' => $runId,
            'run_uid' => $runUid,
            'interactive_mode' => true,
            'message' => 'Interactive pipeline run started'
        ]);
    }

    /**
     * Submit user mappings for a paused interactive run
     *
     * POST /pipelines/submitmappings/{run_id}
     *
     * Body:
     *   mappings: [{"source": "data.field", "target": "variable_name"}, ...]
     *   passthrough: bool (if true, merge entire output into context)
     *   save_mappings: bool (if true, save mappings to step for future runs)
     *
     * After submitting, the pipeline resumes execution from the next step.
     */
    public function submitmappings($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Verify ownership (user who started the run or admin)
        $isOwner = ($run->member_id == $this->member->id);
        $isAdmin = Flight::hasLevel(LEVELS['ADMIN'] ?? 50);

        if (!$isOwner && !$isAdmin) {
            Flight::jsonError('Not authorized to resume this run', 403);
            return;
        }

        // Verify run is paused
        if ($run->status !== 'paused') {
            Flight::jsonError('Run is not paused (status: ' . $run->status . ')', 400);
            return;
        }

        // Get mappings from request
        $mappingsJson = $this->getParam('mappings', '[]');
        $mappings = json_decode($mappingsJson, true);
        if (!is_array($mappings)) {
            $mappings = [];
        }

        $passthrough = (bool) $this->getParam('passthrough', false);
        $saveMappings = (bool) $this->getParam('save_mappings', false);

        // Resume the pipeline
        require_once __DIR__ . '/../services/PipelineExecutor.php';
        $executor = new \app\services\PipelineExecutor($runId);

        try {
            $success = $executor->resume($mappings, $passthrough, $saveMappings);

            // Reload run to get current state
            $run = Bean::load('pipelineruns', $runId);

            Flight::jsonSuccess([
                'resumed' => true,
                'status' => $run->status,
                'message' => $run->status === 'paused'
                    ? 'Mappings applied, waiting for next step'
                    : 'Pipeline resumed'
            ]);

        } catch (\Exception $e) {
            Flight::jsonError('Resume failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get extended status for an interactive run
     *
     * GET /pipelines/interactivestatus/{run_id}
     *
     * Returns additional info for paused runs:
     *   - The step that's awaiting input
     *   - The step's full output (for mapping UI)
     *   - Any saved mappings for the step
     *   - Full step_runs array with output data for grid display
     */
    public function interactivestatus($params = []) {
        if (!$this->requireLogin()) return;

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Get latest step run statuses
        $stepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? ORDER BY row ASC, col ASC ',
            [$run->id]
        );

        $stepStatuses = [];
        $stepRunsArray = [];
        $awaitingStep = null;
        $awaitingStepOutput = null;
        $savedMappings = null;

        foreach ($stepRuns as $sr) {
            $stepStatuses[$sr->step_name] = [
                'status' => $sr->status,
                'row' => (int) $sr->row,
                'col' => (int) $sr->col,
                'duration_ms' => $sr->duration_ms,
                'error_message' => $sr->error_message,
                'awaiting_input' => (bool) $sr->awaiting_input
            ];

            // Build detailed step_runs array for grid display
            $stepRunsArray[] = [
                'id' => $sr->id,
                'step_id' => $sr->pipelinesteps_id,
                'step_name' => $sr->step_name,
                'row' => (int) $sr->row,
                'col' => (int) $sr->col,
                'status' => $sr->status,
                'output' => json_decode($sr->output_json ?: '{}', true),
                'stdout' => $sr->stdout,
                'stderr' => $sr->stderr,
                'exit_code' => $sr->exit_code,
                'duration_ms' => $sr->duration_ms,
                'error_message' => $sr->error_message,
                'awaiting_input' => (bool) $sr->awaiting_input
            ];

            // If this step is awaiting input, get its full output
            if ($sr->awaiting_input) {
                $awaitingStep = [
                    'id' => $sr->id,
                    'step_name' => $sr->step_name,
                    'row' => (int) $sr->row,
                    'col' => (int) $sr->col,
                    'pipelinesteps_id' => $sr->pipelinesteps_id
                ];
                $awaitingStepOutput = json_decode($sr->output_json ?: '{}', true);

                // Check for saved mappings on the step definition
                $stepDef = Bean::load('pipelinesteps', $sr->pipelinesteps_id);
                if ($stepDef && $stepDef->output_mappings_json) {
                    $savedMappings = json_decode($stepDef->output_mappings_json, true);
                }
            }
        }

        $result = [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'current_step_name' => $run->current_step_name,
                'progress_percent' => $run->progress_percent,
                'steps_completed' => $run->steps_completed,
                'steps_total' => $run->steps_total,
                'error_message' => $run->error_message,
                'completed_at' => $run->completed_at,
                'interactive_mode' => (bool) $run->interactive_mode
            ],
            'steps' => $stepStatuses,
            'step_runs' => $stepRunsArray
        ];

        // Add pause/mapping info if run is paused
        if ($run->status === 'paused' && $awaitingStep) {
            $result['paused_at_step'] = $awaitingStep;
            $result['step_output'] = $awaitingStepOutput;
            if ($savedMappings) {
                $result['saved_mappings'] = $savedMappings;
            }
        }

        Flight::jsonSuccess($result);
    }

    /**
     * Execute exactly one step in debug mode
     *
     * POST /pipelines/stepnext/{run_id}
     *
     * This is the API endpoint for the debugger's "Next Step" button.
     * Executes exactly one step, then pauses and returns:
     * - The executed step's result
     * - Information about the next step (id, name, row, col, reason)
     * - Updated run status and progress
     */
    public function stepnext($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Verify run is in a steppable state
        if (!in_array($run->status, ['paused', 'pending', 'running'])) {
            Flight::jsonError("Run cannot be stepped (status: {$run->status})", 400);
            return;
        }

        try {
            $executor = new \app\services\PipelineExecutor($runId);
            $result = $executor->stepNext();

            if ($result['success']) {
                // Build extended step_runs array for UI update
                $stepRuns = Bean::find('pipelinestepruns',
                    ' pipelineruns_id = ? ORDER BY row ASC, col ASC ',
                    [$run->id]
                );

                $stepRunsArray = [];
                foreach ($stepRuns as $sr) {
                    $stepRunsArray[] = [
                        'id' => $sr->id,
                        'step_id' => $sr->pipelinesteps_id,
                        'step_name' => $sr->step_name,
                        'row' => (int) $sr->row,
                        'col' => (int) $sr->col,
                        'status' => $sr->status,
                        'output' => json_decode($sr->output_json ?: '{}', true),
                        'stdout' => $sr->stdout,
                        'stderr' => $sr->stderr,
                        'exit_code' => $sr->exit_code,
                        'duration_ms' => $sr->duration_ms,
                        'error_message' => $sr->error_message,
                        'awaiting_input' => (bool) $sr->awaiting_input
                    ];
                }

                $result['step_runs'] = $stepRunsArray;

                Flight::jsonSuccess($result);
            } else {
                Flight::jsonError($result['error'] ?? 'Step execution failed', 500);
            }

        } catch (\Exception $e) {
            Flight::jsonError('Step execution failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Re-run pipeline from a specific step
     * POST /pipelines/rerunfrom/{run_id}
     * Body: step_id - the step to restart from
     *
     * This resets the specified step and all following steps to 'pending',
     * then sets the run to 'paused' so user can step through again.
     */
    public function rerunfrom($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);
        $stepId = (int) $this->getParam('step_id', 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Get the target step to find its position
        $targetStep = Bean::findOne('pipelinesteps', 'id = ?', [$stepId]);
        if (!$targetStep) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        $targetRow = (int) $targetStep->row;
        $targetCol = (int) $targetStep->col;

        // Get all step runs for this pipeline run
        $stepRuns = Bean::find('pipelinestepruns', ' pipelineruns_id = ? ', [$runId]);

        // Reset steps at or after the target position
        $resetCount = 0;
        foreach ($stepRuns as $sr) {
            $srRow = (int) $sr->row;
            $srCol = (int) $sr->col;

            // Reset if same row and same/later col, or later row
            if (($srRow === $targetRow && $srCol >= $targetCol) || $srRow > $targetRow) {
                $sr->status = 'pending';
                $sr->output_json = null;
                $sr->stdout = null;
                $sr->stderr = null;
                $sr->exit_code = null;
                $sr->error_message = null;
                $sr->started_at = null;
                $sr->completed_at = null;
                $sr->duration_ms = null;
                Bean::store($sr);
                $resetCount++;
            }
        }

        // Set run status to paused
        $run->status = 'paused';
        $run->current_step = $targetStep->step_name;

        // Update step counts
        $completedCount = Bean::count('pipelinestepruns',
            ' pipelineruns_id = ? AND status IN (?, ?) ',
            [$runId, 'completed', 'success']
        );
        $run->steps_completed = $completedCount;

        Bean::store($run);

        // Calculate what the next step will be
        $nextStep = [
            'id' => $targetStep->id,
            'step_name' => $targetStep->step_name,
            'label' => $targetStep->label,
            'row' => $targetRow,
            'col' => $targetCol,
            'step_type' => $targetStep->step_type
        ];

        Flight::jsonSuccess([
            'message' => "Reset {$resetCount} steps. Ready to re-run from '{$targetStep->step_name}'",
            'reset_count' => $resetCount,
            'run_status' => 'paused',
            'next_step' => $nextStep
        ]);
    }

    /**
     * Get available variables for a step at a given position
     * Returns variables from previous steps and pipeline context
     */
    public function getstepvariables($params = []) {
        if (!$this->requireLogin()) return;

        $pipelineId = (int) ($this->opId() ?? $this->getParam('pipeline_id') ?? 0);
        $row = (int) $this->getParam('row', 0);
        $col = (int) $this->getParam('col', 0);
        $stepId = (int) $this->getParam('step_id', 0);

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        $variables = [
            'context' => [],
            'previous_steps' => [],
            'builtins' => []
        ];

        // 1. Built-in context variables always available
        $variables['builtins'] = [
            ['name' => 'run_id', 'type' => 'integer', 'description' => 'Current run ID'],
            ['name' => 'run_uid', 'type' => 'string', 'description' => 'Unique run identifier'],
            ['name' => 'run_directory', 'type' => 'string', 'description' => 'File storage directory for this run'],
            ['name' => 'pipeline_name', 'type' => 'string', 'description' => 'Pipeline name'],
            ['name' => 'pipeline_slug', 'type' => 'string', 'description' => 'Pipeline slug'],
            ['name' => 'started_at', 'type' => 'datetime', 'description' => 'Run start time'],
            ['name' => 'trigger_source', 'type' => 'string', 'description' => 'What triggered the run'],
        ];

        // 2. Pipeline input schema variables (if exposed as MCP tool or has defaults)
        if ($pipeline->input_schema_json) {
            $schema = json_decode($pipeline->input_schema_json, true);
            if ($schema && isset($schema['properties'])) {
                foreach ($schema['properties'] as $propName => $propDef) {
                    $variables['context'][] = [
                        'name' => $propName,
                        'type' => $propDef['type'] ?? 'string',
                        'description' => $propDef['description'] ?? 'Input parameter',
                        'required' => in_array($propName, $schema['required'] ?? [])
                    ];
                }
            }
        }

        // 3. Get all steps that execute before this position
        // Steps are ordered by row first, then col. Previous steps are those with:
        // - Lower row number, OR
        // - Same row but lower column number
        $allSteps = Bean::find('pipelinesteps',
            ' pipelines_id = ? ORDER BY row ASC, col ASC ',
            [$pipelineId]
        );

        foreach ($allSteps as $step) {
            // Skip the step we're currently editing
            if ($step->id == $stepId) continue;

            // Check if this step comes before our position
            $stepRow = (int) $step->row;
            $stepCol = (int) $step->col;

            $isBefore = ($stepRow < $row) || ($stepRow === $row && $stepCol < $col);
            if (!$isBefore) continue;

            $stepVars = [
                'step_name' => $step->step_name,
                'label' => $step->label ?: $step->step_name,
                'row' => $stepRow,
                'col' => $stepCol,
                'variables' => []
            ];

            // Add standard output reference
            $stepVars['variables'][] = [
                'path' => $step->step_name . '.output',
                'type' => 'object',
                'description' => 'Full output object from this step'
            ];
            $stepVars['variables'][] = [
                'path' => $step->step_name . '.stdout',
                'type' => 'string',
                'description' => 'Raw stdout from this step'
            ];
            $stepVars['variables'][] = [
                'path' => $step->step_name . '.status',
                'type' => 'string',
                'description' => 'Step status (completed, failed)'
            ];

            // If step has saved output_mappings_json, show those mapped/exported variables
            // These are displayed with a special "mapped" type and highlighted in the Variable Browser
            if ($step->output_mappings_json) {
                $mappings = json_decode($step->output_mappings_json, true);
                if ($mappings && is_array($mappings)) {
                    foreach ($mappings as $alias => $mapping) {
                        // Support both old format (target/source) and new format (path/fullPath)
                        if (isset($mapping['fullPath'])) {
                            // New format from Variable Exporter
                            $stepVars['variables'][] = [
                                'path' => $mapping['fullPath'],
                                'type' => 'mapped',
                                'description' => 'Exported: ' . $alias,
                                'alias' => $alias
                            ];
                        } elseif (isset($mapping['target'])) {
                            // Old format
                            $stepVars['variables'][] = [
                                'path' => $mapping['target'],
                                'type' => 'mapped',
                                'description' => 'Mapped from ' . $step->step_name . '.' . ($mapping['source'] ?? 'output'),
                                'source' => $mapping['source'] ?? null
                            ];
                        }
                    }
                }
            }

            $variables['previous_steps'][] = $stepVars;
        }

        Flight::jsonSuccess([
            'variables' => $variables,
            'position' => ['row' => $row, 'col' => $col]
        ]);
    }

    // =========================================================================
    // Await Input Gateway (Multi-turn MCP / Webhook / Form Input)
    // =========================================================================

    /**
     * Submit input to a pipeline awaiting external input
     *
     * POST /pipelines/input/{run_id}?token={input_token}
     * Body: JSON input data matching the awaiting_input_schema
     *
     * Used by:
     * - External webhooks
     * - Form submissions (AJAX)
     * - Direct API calls
     *
     * For MCP, use the continue_pipeline tool in Mcppipelines.php instead.
     */
    public function input($params = []) {
        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);
        $token = $this->getParam('token');

        // Read JSON body
        $rawBody = file_get_contents('php://input');
        $inputData = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try form data
            $inputData = $_POST;
            unset($inputData['token']);
        }

        if (empty($inputData)) {
            Flight::jsonError('Input data required', 400);
            return;
        }

        if (empty($token)) {
            Flight::jsonError('Input token required', 400);
            return;
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Find the step that's awaiting input
        $awaitingStep = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND awaiting_input = 1 ',
            [$runId]
        );

        if (!$awaitingStep) {
            Flight::jsonError('Pipeline is not awaiting input', 400);
            return;
        }

        // Validate token
        if ($awaitingStep->awaiting_input_token !== $token) {
            Flight::jsonError('Invalid input token', 403);
            return;
        }

        // Check timeout
        if ($awaitingStep->awaiting_input_timeout_at && strtotime($awaitingStep->awaiting_input_timeout_at) < time()) {
            Flight::jsonError('Input window has expired', 410);
            return;
        }

        // Check allowed sources
        $allowedSources = json_decode($awaitingStep->awaiting_input_sources_json ?: '[]', true);
        if (!empty($allowedSources) && !in_array('webhook', $allowedSources) && !in_array('form', $allowedSources)) {
            Flight::jsonError('Input from this source is not allowed', 403);
            return;
        }

        // Resume the pipeline with the input
        require_once __DIR__ . '/../services/PipelineExecutor.php';

        try {
            $executor = new \app\services\PipelineExecutor($runId);
            $result = $executor->resumeFromAwaitInput($inputData, $token, 'webhook');

            Flight::jsonSuccess([
                'resumed' => true,
                'run_id' => $runId,
                'status' => $result['status'] ?? 'running',
                'output' => $result['output'] ?? null,
                'message' => 'Pipeline resumed with input'
            ]);

        } catch (\Exception $e) {
            Flight::jsonError('Resume failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Render a form for pipeline input submission
     *
     * GET /pipelines/form/{run_id}?token={input_token}
     *
     * Renders a web form based on the awaiting_input_schema_json.
     * Allows non-technical users to provide input via browser.
     */
    public function form($params = []) {
        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);
        $token = $this->getParam('token');

        // Support run_uid lookup (for friendlier URLs)
        if (!$runId && $this->opId()) {
            $run = Bean::findOne('pipelineruns', 'run_uid = ?', [$this->opId()]);
            $runId = $run ? $run->id : 0;
        } else {
            $run = Bean::load('pipelineruns', $runId);
        }

        if (!$run || !$run->id) {
            $this->render('pipelines/form_error', ['error' => 'Run not found']);
            return;
        }

        // Find the step that's awaiting input
        $awaitingStep = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND awaiting_input = 1 ',
            [$run->id]
        );

        if (!$awaitingStep) {
            $this->render('pipelines/form_error', ['error' => 'Pipeline is not awaiting input']);
            return;
        }

        // Authentication: token OR logged-in session
        $authenticated = false;

        // Method 1: Token-based auth (for email links, external access)
        if (!empty($token) && $awaitingStep->awaiting_input_token === $token) {
            $authenticated = true;
        }

        // Method 2: Session-based auth (logged-in workspace member)
        if (!$authenticated && $this->member && $this->member->id) {
            // User is logged in - allow access if they're a workspace member
            // Optionally could check if they own the run: $run->member_id === $this->member->id
            $authenticated = true;
        }

        if (!$authenticated) {
            $this->render('pipelines/form_error', ['error' => 'Input token required or login to continue']);
            return;
        }

        // Check timeout
        if ($awaitingStep->awaiting_input_timeout_at && strtotime($awaitingStep->awaiting_input_timeout_at) < time()) {
            $this->render('pipelines/form_error', ['error' => 'This input window has expired']);
            return;
        }

        // Check allowed sources
        $allowedSources = json_decode($awaitingStep->awaiting_input_sources_json ?: '[]', true);
        if (!empty($allowedSources) && !in_array('form', $allowedSources)) {
            $this->render('pipelines/form_error', ['error' => 'Form input is not allowed for this step']);
            return;
        }

        // Get schema and context
        $schema = json_decode($awaitingStep->awaiting_input_schema_json ?: '{}', true);
        $prompt = $awaitingStep->awaiting_input_prompt ?: 'Please provide the requested information';
        $context = json_decode($run->context_json ?: '{}', true);

        // Get pipeline info for display
        $pipeline = $run->pipelines;

        $this->render('pipelines/form_input', [
            'run_id' => $run->id,
            'run_uid' => $run->run_uid,
            'token' => $token ?? '',
            'schema' => $schema,
            'prompt' => $prompt,
            'context' => $context,
            'pipeline_name' => $pipeline->name ?? 'Pipeline',
            'step_name' => $awaitingStep->step_name,
            'timeout_at' => $awaitingStep->awaiting_input_timeout_at
        ]);
    }

    /**
     * Handle form submission for pipeline input
     *
     * POST /pipelines/formsubmit/{run_id}
     *
     * Processes form submission, resumes pipeline, and shows result page.
     */
    public function formsubmit($params = []) {
        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);
        $token = $this->getParam('token');

        // Support run_uid lookup
        if (!$runId && $this->opId()) {
            $run = Bean::findOne('pipelineruns', 'run_uid = ?', [$this->opId()]);
            $runId = $run ? $run->id : 0;
        } else {
            $run = Bean::load('pipelineruns', $runId);
        }

        // Get form data
        $inputData = $_POST;
        unset($inputData['token']); // Remove token from input data
        unset($inputData['csrf_token']); // Remove CSRF token from input data

        if (empty($inputData)) {
            $this->render('pipelines/form_error', ['error' => 'No input data provided']);
            return;
        }

        if (!$run || !$run->id) {
            $this->render('pipelines/form_error', ['error' => 'Run not found']);
            return;
        }

        // Find the step that's awaiting input
        $awaitingStep = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND awaiting_input = 1 ',
            [$run->id]
        );

        if (!$awaitingStep) {
            $this->render('pipelines/form_error', ['error' => 'Pipeline is not awaiting input']);
            return;
        }

        // Authentication: token OR logged-in session
        $authenticated = false;

        // Method 1: Token-based auth (for email links, external access)
        if (!empty($token) && $awaitingStep->awaiting_input_token === $token) {
            $authenticated = true;
        }

        // Method 2: Session-based auth (logged-in workspace member)
        if (!$authenticated && $this->member && $this->member->id) {
            $authenticated = true;
        }

        if (!$authenticated) {
            $this->render('pipelines/form_error', ['error' => 'Input token required or login to continue']);
            return;
        }

        // Use the actual token from the awaiting step (for session-based auth, token might be empty)
        $actualToken = $awaitingStep->awaiting_input_token;

        // Add user's message to the chat before resuming
        $userMessage = $inputData['message'] ?? $inputData['instructions'] ?? $inputData['response'] ?? null;
        if ($userMessage) {
            $context = json_decode($run->context_json ?: '{}', true);
            if (!isset($context['_messages'])) {
                $context['_messages'] = [];
            }
            $context['_messages'][] = [
                'role' => 'user',
                'message' => $userMessage,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $run->context_json = json_encode($context);
            Bean::store($run);
        }

        // Check if this is an AJAX request
        $isAjax = !empty($inputData['ajax']) || $this->getParam('ajax');
        unset($inputData['ajax']);

        // Resume the pipeline with the input
        require_once __DIR__ . '/../services/PipelineExecutor.php';

        try {
            $this->logger->info('formsubmit resuming pipeline', [
                'run_id' => $run->id,
                'inputData' => $inputData,
                'token_length' => strlen($actualToken)
            ]);

            $executor = new \app\services\PipelineExecutor($run->id, $this->logger);
            $executor->setTimingEnabled(true);  // Enable performance timing
            $result = $executor->resumeFromAwaitInput($inputData, $actualToken, 'form');

            // Log timing report for performance analysis
            $timingReport = $executor->getTimingReport();
            if (!empty($timingReport)) {
                $this->logger->info('formsubmit timing', [
                    'run_id' => $run->id,
                    'timings' => $timingReport
                ]);
            }

            $this->logger->info('formsubmit resume result', [
                'run_id' => $run->id,
                'success' => $result['success'] ?? false,
                'status' => $result['status'] ?? 'unknown',
                'error' => $result['error'] ?? null
            ]);

            if ($isAjax) {
                // Return JSON for AJAX requests - check actual result
                if (!empty($result['success'])) {
                    Flight::json([
                        'success' => true,
                        'run_id' => $runId,
                        'run_uid' => $run->run_uid,
                        'status' => $result['status'] ?? 'running',
                        'message' => 'Input received'
                    ]);
                } else {
                    Flight::json([
                        'success' => false,
                        'error' => $result['error'] ?? 'Failed to process input'
                    ], 400);
                }
                return;
            }

            // Show success page for regular form submissions
            $pipeline = $run->pipelines;

            $this->render('pipelines/form_success', [
                'run_id' => $runId,
                'run_uid' => $run->run_uid,
                'pipeline_name' => $pipeline->name ?? 'Pipeline',
                'status' => $result['status'] ?? 'running',
                'output' => $result['output'] ?? null,
                'message' => 'Your input has been received'
            ]);

        } catch (\Exception $e) {
            if ($isAjax) {
                Flight::json([
                    'success' => false,
                    'error' => 'Failed to process input: ' . $e->getMessage()
                ], 500);
                return;
            }
            $this->render('pipelines/form_error', ['error' => 'Failed to process input: ' . $e->getMessage()]);
        }
    }

    /**
     * Get chat messages for a pipeline run (AJAX polling)
     *
     * GET /pipelines/messages/{run_id}
     *
     * Returns the _messages array from the run context for chat display.
     */
    public function messages($params = []) {
        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);
        $token = $this->getParam('token');

        // Support run_uid lookup
        if (!$runId && $this->opId()) {
            $run = Bean::findOne('pipelineruns', 'run_uid = ?', [$this->opId()]);
            $runId = $run ? $run->id : 0;
        } else {
            $run = Bean::load('pipelineruns', $runId);
        }

        if (!$run || !$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Authentication: token OR logged-in session
        $authenticated = false;
        if (!empty($token)) {
            $awaitingStep = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input_token = ? ',
                [$run->id, $token]
            );
            if ($awaitingStep) {
                $authenticated = true;
            }
        }
        if (!$authenticated && $this->member && $this->member->id) {
            $authenticated = true;
        }

        if (!$authenticated) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        // Get messages from context
        $context = json_decode($run->context_json ?: '{}', true);
        $messages = $context['_messages'] ?? [];

        // Also get agent job status if available
        $agentStatus = null;
        $jobId = $context['spawn_agent']['output']['job_id'] ?? null;
        if ($jobId) {
            $job = Bean::load('aidevjobs', $jobId);
            if ($job && $job->id) {
                $agentStatus = [
                    'status' => $job->status,
                    'message' => $job->current_step,
                    'progress' => $job->progress ?? 0,
                    'updated_at' => $job->updated_at
                ];
            }
        }

        Flight::jsonSuccess([
            'run_id' => $run->id,
            'run_uid' => $run->run_uid,
            'status' => $run->status,
            'messages' => $messages,
            'agent_status' => $agentStatus,
            'updated_at' => $run->updated_at
        ]);
    }

    /**
     * Get awaiting input status for a run (AJAX)
     *
     * GET /pipelines/awaitingstatus/{run_id}
     *
     * Returns info about what input is being awaited.
     */
    public function awaitingstatus($params = []) {
        if (!$this->requireLogin()) return;

        $runId = (int) ($this->opId() ?? $this->getParam('run_id') ?? 0);

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        if ($run->status !== 'awaiting_input') {
            Flight::jsonSuccess([
                'awaiting_input' => false,
                'status' => $run->status
            ]);
            return;
        }

        // Find the step that's awaiting input
        $awaitingStep = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND awaiting_input = 1 ',
            [$runId]
        );

        if (!$awaitingStep) {
            Flight::jsonSuccess([
                'awaiting_input' => false,
                'status' => $run->status,
                'note' => 'Run marked as awaiting_input but no step found'
            ]);
            return;
        }

        $schema = json_decode($awaitingStep->awaiting_input_schema_json ?: '{}', true);
        $allowedSources = json_decode($awaitingStep->awaiting_input_sources_json ?: '[]', true);
        $token = $awaitingStep->awaiting_input_token;

        // Build URLs
        $baseUrl = rtrim($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'], '/');
        $formUrl = "{$baseUrl}/pipelines/form/{$runId}?token={$token}";
        $webhookUrl = "{$baseUrl}/pipelines/input/{$runId}?token={$token}";

        Flight::jsonSuccess([
            'awaiting_input' => true,
            'step_name' => $awaitingStep->step_name,
            'prompt' => $awaitingStep->awaiting_input_prompt,
            'schema' => $schema,
            'allowed_sources' => $allowedSources,
            'timeout_at' => $awaitingStep->awaiting_input_timeout_at,
            'form_url' => $formUrl,
            'webhook_url' => $webhookUrl,
            'input_token' => $token
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Generate slug from name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'pipeline-' . time();
    }

    /**
     * Extract context variables from first-row steps for MCP input schema generation
     *
     * Scans all active steps in row 0 for {context.xxx} variable references
     * and generates a JSON Schema with those variables as required properties.
     */
    public function extractvariables($params = []): void {
        if (!$this->requireLogin()) return;

        $pipelineId = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$pipelineId) {
            Flight::jsonError('Pipeline ID required', 400);
            return;
        }

        $pipeline = Bean::findOne('pipelines', 'id = ?', [$pipelineId]);
        if (!$pipeline) {
            Flight::jsonError('Pipeline not found', 404);
            return;
        }

        // Get all active steps in row 0
        $steps = Bean::find('pipelinesteps', 'pipelines_id = ? AND row = 0 AND is_active = 1', [$pipelineId]);

        $variables = [];
        foreach ($steps as $step) {
            // Extract {context.xxx} patterns from config_json
            $configJson = $step->config_json ?: '';
            preg_match_all('/\{context\.([^}]+)\}/', $configJson, $matches);

            foreach ($matches[1] as $varPath) {
                // Get the root variable name (e.g., "issue_key" from "issue_key.summary")
                $rootVar = explode('.', $varPath)[0];
                $variables[$rootVar] = true;
            }
        }

        $varNames = array_keys($variables);

        // Generate JSON Schema with placeholder descriptions
        $properties = new \stdClass();
        foreach ($varNames as $name) {
            // Convert snake_case to readable description
            $readableName = str_replace('_', ' ', $name);
            $properties->$name = [
                'type' => 'string',
                'description' => "The {$readableName} value (update this description for MCP)"
            ];
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $varNames
        ];

        Flight::jsonSuccess([
            'schema' => $schema,
            'variables' => $varNames
        ]);
    }

    /**
     * Sanitize slug
     */
    private function sanitizeSlug(string $slug): string {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'pipeline-' . time();
    }

    /**
     * Parse columns from comma-separated or JSON input
     */
    private function parseColumns($input): array {
        if (is_array($input)) {
            return array_filter(array_map('trim', $input));
        }

        // Try JSON first
        $decoded = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_filter(array_map('trim', $decoded));
        }

        // Fall back to comma-separated
        $columns = explode(',', $input);
        return array_filter(array_map('trim', $columns));
    }
}
