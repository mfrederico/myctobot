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
use \Exception as Exception;

class Pipelines extends BaseControls\Control {

    /**
     * Available step types
     */
    private const STEP_TYPES = [
        'ai_agent' => [
            'label' => 'AI Agent',
            'description' => 'Run an AI agent (impl, verify, fix, or custom)',
            'icon' => 'bi-robot',
            'color' => 'primary'
        ],
        'script' => [
            'label' => 'Script',
            'description' => 'Pull and execute a script from a repo',
            'icon' => 'bi-file-code',
            'color' => 'info'
        ],
        'direct_exec' => [
            'label' => 'Shell Command',
            'description' => 'Execute a shell command with stdin/stdout',
            'icon' => 'bi-terminal',
            'color' => 'dark'
        ],
        'parser' => [
            'label' => 'Parser',
            'description' => 'Transform data (jq, php, custom)',
            'icon' => 'bi-braces',
            'color' => 'warning'
        ],
        'webhook_out' => [
            'label' => 'Webhook',
            'description' => 'POST to an external service',
            'icon' => 'bi-send',
            'color' => 'success'
        ],
        'wait' => [
            'label' => 'Wait',
            'description' => 'Wait for external event or approval',
            'icon' => 'bi-hourglass-split',
            'color' => 'secondary'
        ],
        'harvest' => [
            'label' => 'Harvest',
            'description' => 'Gather results from parallel rows',
            'icon' => 'bi-collection',
            'color' => 'success'
        ]
    ];

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
                'type_info' => self::STEP_TYPES[$step->step_type] ?? null,
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

        // Build webhook URL for this pipeline
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $baseUrl = Flight::get('app.baseurl') ?: 'https://myctobot.ai';
        $webhookUrl = "{$baseUrl}/pipein/{$workspaceSlug}/{$pipeline->slug}";

        // Build MCP tool URL for this pipeline
        $mcpToolsUrl = "{$baseUrl}/pipelines/mcp/tools/{$workspaceSlug}";

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
            'run_count' => $pipeline->run_count,
            'last_run_at' => $pipeline->last_run_at,
            'expose_as_tool' => (bool) $pipeline->expose_as_tool,
            'input_schema_json' => $pipeline->input_schema_json ?: '{}'
        ];
        $this->viewData['mcpToolsUrl'] = $mcpToolsUrl;
        $this->viewData['workspaceSlug'] = $workspaceSlug;
        $this->viewData['stepGrid'] = $stepGrid;
        $this->viewData['maxRow'] = $maxRow;
        $this->viewData['stepTypes'] = self::STEP_TYPES;
        $this->viewData['triggerTypes'] = self::TRIGGER_TYPES;
        $this->viewData['agents'] = $agentList;
        $this->viewData['repos'] = $repoList;
        $this->viewData['runners'] = $runnerList;
        $this->viewData['workstations'] = $workstationList;
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
        if (!isset(self::STEP_TYPES[$stepType])) {
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

        $step = Bean::findOne('pipelinesteps', 'id = ? AND pipelines_id = ?', [$stepId, $pipelineId]);
        if (!$step) {
            Flight::jsonError('Step not found', 404);
            return;
        }

        Bean::trash($step);

        Flight::jsonSuccess(['message' => 'Step deleted']);
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
                'run_parallel' => (bool) $step->run_parallel
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

        if (!in_array($run->status, ['pending', 'running'])) {
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
