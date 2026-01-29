<?php
/**
 * Orchestration Controller
 * AI-powered business orchestration platform
 *
 * Orchestration enables users to create complex, multi-system automations using
 * natural language and wizard-driven templates. It builds on the pipeline
 * infrastructure with temporal scheduling and AI-assisted generation.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;

class Orchestration extends BaseControls\Control {

    /**
     * Template categories
     */
    private const CATEGORIES = [
        'ecommerce' => [
            'label' => 'E-Commerce',
            'description' => 'Shopify, inventory, pricing automations',
            'icon' => 'bi-shop',
            'color' => 'success'
        ],
        'marketing' => [
            'label' => 'Marketing',
            'description' => 'Social media, email campaigns, content',
            'icon' => 'bi-megaphone',
            'color' => 'info'
        ],
        'development' => [
            'label' => 'Development',
            'description' => 'CI/CD, deployments, code review',
            'icon' => 'bi-code-slash',
            'color' => 'primary'
        ],
        'reporting' => [
            'label' => 'Reporting',
            'description' => 'Analytics, dashboards, digests',
            'icon' => 'bi-bar-chart',
            'color' => 'warning'
        ],
        'custom' => [
            'label' => 'Custom',
            'description' => 'Build your own orchestration',
            'icon' => 'bi-gear',
            'color' => 'secondary'
        ]
    ];

    /**
     * Project statuses
     */
    private const STATUSES = [
        'draft' => ['label' => 'Draft', 'color' => 'secondary', 'icon' => 'bi-pencil'],
        'active' => ['label' => 'Active', 'color' => 'success', 'icon' => 'bi-play-circle'],
        'paused' => ['label' => 'Paused', 'color' => 'warning', 'icon' => 'bi-pause-circle'],
        'completed' => ['label' => 'Completed', 'color' => 'info', 'icon' => 'bi-check-circle'],
        'failed' => ['label' => 'Failed', 'color' => 'danger', 'icon' => 'bi-x-circle']
    ];

    /**
     * Studio dashboard - list projects and featured templates
     */
    public function index() {
        if (!$this->requireLogin()) return;

        $member = Flight::getMember();

        // Get user's projects
        $projects = Bean::find('studioprojects',
            ' member_id = ? ORDER BY updated_at DESC ',
            [$member->id]
        );

        $projectList = [];
        foreach ($projects as $p) {
            $template = $p->studiotemplates_id
                ? Bean::load('studiotemplates', $p->studiotemplates_id)
                : null;

            $projectList[] = [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status,
                'status_info' => self::STATUSES[$p->status] ?? null,
                'template_name' => $template ? $template->name : 'Custom',
                'template_icon' => $template ? $template->icon : 'bi-gear',
                'run_count' => $p->run_count ?? 0,
                'last_run_at' => $p->last_run_at,
                'next_run_at' => $p->next_run_at,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at
            ];
        }

        // Get featured templates
        $templates = Bean::find('studiotemplates',
            ' is_active = 1 ORDER BY name ASC LIMIT 6 '
        );

        $templateList = [];
        foreach ($templates as $t) {
            $templateList[] = $this->formatTemplate($t);
        }

        $this->viewData['title'] = 'Orchestration';
        $this->viewData['projects'] = $projectList;
        $this->viewData['templates'] = $templateList;
        $this->viewData['categories'] = self::CATEGORIES;
        $this->viewData['statuses'] = self::STATUSES;

        $this->render('orchestration/index', $this->viewData);
    }

    /**
     * Template gallery
     */
    public function templates() {
        if (!$this->requireLogin()) return;

        $category = $this->getParam('category');

        $sql = ' is_active = 1 ';
        $params = [];

        if ($category && isset(self::CATEGORIES[$category])) {
            $sql .= ' AND category = ? ';
            $params[] = $category;
        }

        $sql .= ' ORDER BY name ASC ';

        $templates = Bean::find('studiotemplates', $sql, $params);

        $templateList = [];
        foreach ($templates as $t) {
            $templateList[] = $this->formatTemplate($t);
        }

        $this->viewData['title'] = 'Orchestration Templates';
        $this->viewData['templates'] = $templateList;
        $this->viewData['categories'] = self::CATEGORIES;
        $this->viewData['selectedCategory'] = $category;

        $this->render('orchestration/templates', $this->viewData);
    }

    /**
     * View single template details
     */
    public function template($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $template = Bean::load('studiotemplates', $id);
        if (!$template->id) {
            Flight::redirect('/orchestration/templates');
            return;
        }

        $this->viewData['title'] = $template->name;
        $this->viewData['template'] = $this->formatTemplate($template, true);
        $this->viewData['categories'] = self::CATEGORIES;

        $this->render('orchestration/template_detail', $this->viewData);
    }

    /**
     * Start wizard for a template
     */
    public function wizard($params = []) {
        if (!$this->requireLogin()) return;

        $templateId = (int) ($this->opId() ?? $this->getParam('template_id') ?? 0);

        $template = null;
        $wizardConfig = [];

        if ($templateId) {
            $template = Bean::load('studiotemplates', $templateId);
            if ($template->id) {
                $wizardConfig = json_decode($template->wizard_config_json ?: '{}', true);
            }
        }

        // If no template, use custom wizard
        if (!$wizardConfig) {
            $wizardConfig = $this->getCustomWizardConfig();
        }

        $this->viewData['title'] = $template ? "Create: {$template->name}" : 'Create Custom Orchestration';
        $this->viewData['template'] = $template ? $this->formatTemplate($template) : null;
        $this->viewData['wizardConfig'] = $wizardConfig;
        $this->viewData['templateId'] = $templateId;

        $this->render('orchestration/wizard', $this->viewData);
    }

    /**
     * AJAX: Save wizard step and get next
     */
    public function wizardstep() {
        if (!$this->requireLogin()) return;

        $templateId = $this->getParam('template_id');
        $stepId = $this->getParam('step_id');
        $stepData = $this->getParam('data');
        $projectId = $this->getParam('project_id');

        try {
            // Load or create project
            if ($projectId) {
                $project = Bean::load('studioprojects', $projectId);
            } else {
                $project = Bean::dispense('studioprojects');
                $project->member_id = Flight::getMember()->id;
                $project->studiotemplates_id = $templateId ?: null;
                $project->status = 'draft';
                $project->variables_json = '{}';
                $project->created_at = date('Y-m-d H:i:s');
            }

            // Merge step data into variables
            $variables = json_decode($project->variables_json ?: '{}', true);
            if (is_array($stepData)) {
                $variables = array_merge($variables, $stepData);
            }
            $project->variables_json = json_encode($variables);
            $project->updated_at = date('Y-m-d H:i:s');

            Bean::store($project);

            Flight::jsonSuccess([
                'project_id' => $project->id,
                'variables' => $variables
            ]);

        } catch (Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * AJAX: AI assist for wizard field
     */
    public function aiassist() {
        if (!$this->requireLogin()) return;

        $fieldType = $this->getParam('field_type');
        $context = $this->getParam('context');
        $userInput = $this->getParam('input');

        // TODO: Implement AI assistance using Anthropic API
        // For now, return placeholder

        Flight::jsonSuccess([
            'suggestion' => "AI suggestion for: {$userInput}",
            'options' => []
        ]);
    }

    /**
     * AJAX: Finalize wizard and generate pipeline
     */
    public function finalize() {
        if (!$this->requireLogin()) return;

        $member = Flight::getMember();
        $templateId = $this->getParam('template_id');
        $projectId = $this->getParam('project_id');
        $wizardData = $this->getParam('data');

        try {
            // Load or create project
            if ($projectId) {
                $project = Bean::load('studioprojects', $projectId);
                if (!$project->id || $project->member_id != $member->id) {
                    Flight::jsonError('Project not found', 404);
                    return;
                }
            } else {
                $project = Bean::dispense('studioprojects');
                $project->member_id = $member->id;
                $project->studiotemplates_id = $templateId ?: null;
                $project->status = 'draft';
                $project->created_at = date('Y-m-d H:i:s');
            }

            // Set project name from wizard data
            $projectName = $wizardData['name'] ?? null;
            if (!$projectName && $templateId) {
                $template = Bean::load('studiotemplates', $templateId);
                $projectName = $template->name . ' - ' . date('M j, Y');
            }
            $project->name = $projectName ?: 'Untitled Orchestration';
            $project->slug = $this->generateProjectSlug($project->name);

            // Store all wizard variables
            $project->variables_json = json_encode($wizardData);
            $project->updated_at = date('Y-m-d H:i:s');
            Bean::store($project);

            // Generate the pipeline
            $genResult = $this->generateProjectPipeline($project);

            if (!$genResult['success']) {
                Flight::jsonError($genResult['error'] ?? 'Failed to generate pipeline', 500);
                return;
            }

            Flight::jsonSuccess([
                'project_id' => $project->id,
                'pipeline_id' => $genResult['pipeline_id'],
                'step_count' => $genResult['step_count'] ?? 0
            ], 'Orchestration created successfully');

        } catch (Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Generate URL-safe slug for project
     */
    private function generateProjectSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;
        while (Bean::findOne('studioprojects', 'slug = ?', [$slug])) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * View project details and control center
     */
    public function project($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $member = Flight::getMember();
        $project = Bean::load('studioprojects', $id);

        if (!$project->id || $project->member_id != $member->id) {
            Flight::redirect('/orchestration');
            return;
        }

        $template = $project->studiotemplates_id
            ? Bean::load('studiotemplates', $project->studiotemplates_id)
            : null;

        // Get associated pipeline if exists
        $pipeline = $project->pipelines_id
            ? Bean::load('pipelines', $project->pipelines_id)
            : null;

        // Get recent runs
        $runs = [];
        if ($pipeline) {
            $recentRuns = Bean::find('pipelineruns',
                ' pipelines_id = ? ORDER BY created_at DESC LIMIT 10 ',
                [$pipeline->id]
            );
            foreach ($recentRuns as $run) {
                $runs[] = [
                    'id' => $run->id,
                    'run_uid' => $run->run_uid,
                    'status' => $run->status,
                    'trigger_source' => $run->trigger_source,
                    'started_at' => $run->started_at,
                    'completed_at' => $run->completed_at
                ];
            }
        }

        // Get scheduled tasks
        $scheduledTasks = Bean::find('scheduledtasks',
            ' studioprojects_id = ? ORDER BY scheduled_at ASC ',
            [$project->id]
        );

        $tasks = [];
        foreach ($scheduledTasks as $task) {
            $tasks[] = [
                'id' => $task->id,
                'task_type' => $task->task_type,
                'status' => $task->status,
                'scheduled_at' => $task->scheduled_at,
                'completed_at' => $task->completed_at
            ];
        }

        $this->viewData['title'] = $project->name;
        $this->viewData['project'] = [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status,
            'status_info' => self::STATUSES[$project->status] ?? null,
            'variables' => json_decode($project->variables_json ?: '{}', true),
            'schedule_config' => json_decode($project->schedule_config_json ?: '{}', true),
            'run_count' => $project->run_count ?? 0,
            'last_run_at' => $project->last_run_at,
            'next_run_at' => $project->next_run_at,
            'created_at' => $project->created_at
        ];
        $this->viewData['template'] = $template ? $this->formatTemplate($template) : null;
        $this->viewData['pipeline'] = $pipeline;
        $this->viewData['runs'] = $runs;
        $this->viewData['scheduledTasks'] = $tasks;
        $this->viewData['statuses'] = self::STATUSES;

        $this->render('orchestration/project', $this->viewData);
    }

    /**
     * Manually trigger a project
     */
    public function execute($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $member = Flight::getMember();
        $project = Bean::load('studioprojects', $id);

        if (!$project->id || $project->member_id != $member->id) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        // Generate pipeline if not exists
        if (!$project->pipelines_id) {
            $genResult = $this->generateProjectPipeline($project);
            if (!$genResult['success']) {
                Flight::jsonError('Failed to generate pipeline: ' . ($genResult['error'] ?? 'Unknown error'), 400);
                return;
            }
            // Reload project to get the pipeline_id
            $project = Bean::load('studioprojects', $id);
        }

        try {
            // Create pipeline run
            $pipeline = Bean::load('pipelines', $project->pipelines_id);
            $context = json_decode($project->variables_json ?: '{}', true);
            $context['_studio_project_id'] = $project->id;

            $run = Bean::dispense('pipelineruns');
            $run->run_uid = 'run-' . bin2hex(random_bytes(8));
            $run->pipelines_id = $pipeline->id;
            $run->member_id = $member->id;
            $run->trigger_source = 'studio';
            $run->trigger_data_json = json_encode(['project_id' => $project->id]);
            $run->status = 'pending';
            $run->context_json = json_encode($context);
            $run->created_at = date('Y-m-d H:i:s');
            Bean::store($run);

            // Spawn background execution
            $projectDir = dirname(__DIR__);
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $cmd = sprintf(
                'php %s/scripts/runpipe.php --workspace=%s --run-id=%d > /dev/null 2>&1 &',
                escapeshellarg($projectDir),
                escapeshellarg($workspace),
                $run->id
            );
            exec($cmd);

            // Update project stats
            $project->run_count = ($project->run_count ?? 0) + 1;
            $project->last_run_at = date('Y-m-d H:i:s');
            $project->updated_at = date('Y-m-d H:i:s');
            Bean::store($project);

            Flight::jsonSuccess([
                'run_id' => $run->id,
                'run_uid' => $run->run_uid
            ], 'Pipeline triggered');

        } catch (Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Pause a project
     */
    public function pause($params = []) {
        if (!$this->requireLogin()) return;
        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $this->updateProjectStatus($id, 'paused');
    }

    /**
     * Resume a project
     */
    public function resume($params = []) {
        if (!$this->requireLogin()) return;
        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $this->updateProjectStatus($id, 'active');
    }

    /**
     * Delete a project
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $member = Flight::getMember();
        $project = Bean::load('studioprojects', $id);

        if (!$project->id || $project->member_id != $member->id) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        try {
            // Cancel any pending scheduled tasks
            $pendingTasks = Bean::find('scheduledtasks',
                ' studioprojects_id = ? AND status = ? ',
                [$project->id, 'pending']
            );
            foreach ($pendingTasks as $task) {
                $task->status = 'cancelled';
                $task->updated_at = date('Y-m-d H:i:s');
                Bean::store($task);
            }

            // Delete project
            Bean::trash($project);

            Flight::jsonSuccess([], 'Project deleted');

        } catch (Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get project run history
     */
    public function history($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $member = Flight::getMember();
        $project = Bean::load('studioprojects', $id);

        if (!$project->id || $project->member_id != $member->id) {
            Flight::redirect('/orchestration');
            return;
        }

        $runs = [];
        if ($project->pipelines_id) {
            $allRuns = Bean::find('pipelineruns',
                ' pipelines_id = ? ORDER BY created_at DESC ',
                [$project->pipelines_id]
            );
            foreach ($allRuns as $run) {
                $runs[] = [
                    'id' => $run->id,
                    'run_uid' => $run->run_uid,
                    'status' => $run->status,
                    'trigger_source' => $run->trigger_source,
                    'steps_completed' => $run->steps_completed,
                    'steps_total' => $run->steps_total,
                    'error_message' => $run->error_message,
                    'started_at' => $run->started_at,
                    'completed_at' => $run->completed_at,
                    'created_at' => $run->created_at
                ];
            }
        }

        $this->viewData['title'] = "History: {$project->name}";
        $this->viewData['project'] = [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug
        ];
        $this->viewData['runs'] = $runs;

        $this->render('orchestration/history', $this->viewData);
    }

    // ========================================
    // Private helpers
    // ========================================

    /**
     * Format template for view
     */
    private function formatTemplate($template, $includeConfig = false): array {
        $data = [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'category' => $template->category,
            'category_info' => self::CATEGORIES[$template->category] ?? null,
            'description' => $template->description,
            'icon' => $template->icon ?: 'bi-lightning',
            'color' => $template->color ?: 'primary',
            'required_connections' => json_decode($template->required_connections_json ?: '[]', true),
            'estimated_duration_minutes' => $template->estimated_duration_minutes,
            'is_active' => (bool) $template->is_active
        ];

        if ($includeConfig) {
            $data['wizard_config'] = json_decode($template->wizard_config_json ?: '{}', true);
            $data['variables_schema'] = json_decode($template->variables_schema_json ?: '{}', true);
        }

        return $data;
    }

    /**
     * Get default wizard config for custom orchestrations
     */
    private function getCustomWizardConfig(): array {
        return [
            'steps' => [
                [
                    'id' => 'name',
                    'title' => 'Name Your Orchestration',
                    'type' => 'form',
                    'fields' => [
                        ['name' => 'name', 'type' => 'text', 'label' => 'Project Name', 'required' => true],
                        ['name' => 'description', 'type' => 'textarea', 'label' => 'Description']
                    ]
                ],
                [
                    'id' => 'intent',
                    'title' => 'What do you want to automate?',
                    'type' => 'ai_assisted_text',
                    'variable' => 'intent',
                    'placeholder' => 'Describe what you want to happen...'
                ],
                [
                    'id' => 'connections',
                    'title' => 'Select Integrations',
                    'type' => 'connection_picker',
                    'multiple' => true,
                    'variable' => 'connections'
                ],
                [
                    'id' => 'review',
                    'title' => 'Review & Create',
                    'type' => 'summary',
                    'show_preview' => true
                ]
            ]
        ];
    }

    /**
     * Update project status
     */
    private function updateProjectStatus($id, $status): void {
        $member = Flight::getMember();
        $project = Bean::load('studioprojects', $id);

        if (!$project->id || $project->member_id != $member->id) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        $project->status = $status;
        $project->updated_at = date('Y-m-d H:i:s');
        Bean::store($project);

        Flight::jsonSuccess(['status' => $status], 'Project updated');
    }

    /**
     * Generate pipeline from project's template and variables
     */
    private function generateProjectPipeline($project): array {
        // Load the template
        if (!$project->studiotemplates_id) {
            return [
                'success' => false,
                'error' => 'Project has no template - custom orchestrations not yet supported'
            ];
        }

        $template = Bean::load('studiotemplates', $project->studiotemplates_id);
        if (!$template->id) {
            return [
                'success' => false,
                'error' => 'Template not found'
            ];
        }

        $variables = json_decode($project->variables_json ?: '{}', true);

        // Load the generator service
        require_once dirname(__DIR__) . '/services/StudioPipelineGenerator.php';

        $generator = new \app\services\StudioPipelineGenerator($project->member_id);
        $result = $generator->generate($template, $variables, $project->name);

        if ($result['success']) {
            // Update project with generated pipeline
            $project->pipelines_id = $result['pipeline_id'];
            $project->status = 'active';
            $project->updated_at = date('Y-m-d H:i:s');
            Bean::store($project);

            Flight::get('log')->info('Generated pipeline for studio project', [
                'project_id' => $project->id,
                'pipeline_id' => $result['pipeline_id'],
                'step_count' => $result['step_count'] ?? 0
            ]);
        }

        return $result;
    }
}
