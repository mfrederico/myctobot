<?php
/**
 * PipelineToolService
 *
 * Business logic for all pipeline MCP tools, extracted from Mcppipelines controller traits.
 * Returns data arrays on success, throws PipelineToolException on errors.
 * Transport-agnostic: caller handles JSON-RPC wrapping.
 *
 * Caller must have already switched to the correct workspace database before calling methods.
 */

namespace app\services;

use app\Bean;

require_once __DIR__ . '/PipelineToolException.php';
require_once __DIR__ . '/PipelineSchemaService.php';
require_once __DIR__ . '/StepTypeRegistry.php';
require_once __DIR__ . '/SchedulerService.php';

class PipelineToolService
{
    private string $workspace;
    private ?int $memberId;
    private $logger;

    public function __construct(string $workspace, ?int $memberId, $logger)
    {
        $this->workspace = $workspace;
        $this->memberId = $memberId;
        $this->logger = $logger;
    }

    // ─── Pipeline CRUD ───────────────────────────────────────────────────

    /**
     * List all pipelines in the workspace
     */
    public function listPipelines(array $arguments): array
    {
        $includeInactive = $arguments['include_inactive'] ?? false;

        $where = $includeInactive ? ' 1=1 ' : ' is_active = 1 ';
        $pipelines = Bean::find('pipelines', $where . ' ORDER BY name ASC ');

        $result = [];
        foreach ($pipelines as $pipeline) {
            $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ?', [$pipeline->id]);
            $result[] = [
                'id' => (int) $pipeline->id,
                'name' => $pipeline->name,
                'slug' => $pipeline->slug,
                'description' => $pipeline->description,
                'trigger_type' => $pipeline->trigger_type,
                'is_active' => (bool) $pipeline->is_active,
                'expose_as_tool' => (bool) $pipeline->expose_as_tool,
                'step_count' => $stepCount,
                'run_count' => (int) ($pipeline->run_count ?? 0),
                'last_run_at' => $pipeline->last_run_at
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'pipelines' => $result,
                    'count' => count($result)
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Get all pipeline components (step types, integrations, variable syntax)
     */
    public function getPipelineComponents(array $arguments): array
    {
        $result = [
            'step_types' => PipelineSchemaService::getAllStepTypeSchemas(),
            'integrations' => $this->getUserIntegrations(),
            'variable_syntax' => PipelineSchemaService::getVariableSyntaxReference(),
            'step_options' => [
                'on_success' => [
                    'next_col' => 'Continue to next column in same row',
                    'goto:step_name' => 'Jump to a specific step by name',
                    'exit' => 'Stop pipeline execution'
                ],
                'on_failure' => [
                    'exit' => 'Stop pipeline and mark as failed',
                    'goto:step_name' => 'Jump to error handler step',
                    'retry' => 'Retry the step (uses retry_count)'
                ]
            ]
        ];

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Get pipeline details including steps
     */
    public function getPipeline(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $includeSteps = $arguments['include_steps'] ?? true;

        if (!$pipelineId && !$slug) {
            throw new PipelineToolException('Either pipeline_id or slug is required');
        }

        $pipeline = null;
        if ($pipelineId) {
            $pipeline = Bean::load('pipelines', (int)$pipelineId);
        } elseif ($slug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        }

        if (!$pipeline || !$pipeline->id) {
            throw new PipelineToolException('Pipeline not found');
        }

        $result = [
            'id' => (int) $pipeline->id,
            'name' => $pipeline->name,
            'slug' => $pipeline->slug,
            'description' => $pipeline->description,
            'columns' => json_decode($pipeline->columns_json ?: '[]', true),
            'trigger_type' => $pipeline->trigger_type,
            'trigger_config' => json_decode($pipeline->trigger_config_json ?: '{}', true),
            'default_context' => json_decode($pipeline->default_context_json ?: '{}', true),
            'input_schema' => json_decode($pipeline->input_schema_json ?: '{}', true),
            'expose_as_tool' => (bool) $pipeline->expose_as_tool,
            'is_active' => (bool) $pipeline->is_active,
            'run_count' => (int) ($pipeline->run_count ?? 0),
            'last_run_at' => $pipeline->last_run_at,
            'created_at' => $pipeline->created_at
        ];

        if ($includeSteps) {
            $steps = Bean::find('pipelinesteps', 'pipelines_id = ? ORDER BY sequence, col', [$pipeline->id]);
            $result['steps'] = [];
            foreach ($steps as $step) {
                $result['steps'][] = [
                    'id' => (int) $step->id,
                    'step_name' => $step->step_name,
                    'label' => $step->label,
                    'row' => (int) $step->row,
                    'col' => (int) $step->col,
                    'sequence' => (int) $step->sequence,
                    'step_type' => $step->step_type,
                    'config' => json_decode($step->config_json ?: '{}', true),
                    'on_success' => $step->on_success,
                    'on_failure' => $step->on_failure,
                    'timeout_seconds' => (int) $step->timeout_seconds,
                    'is_active' => (bool) $step->is_active
                ];
            }
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Get step configuration
     */
    public function getStep(array $arguments): array
    {
        $stepId = $arguments['step_id'] ?? null;
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $stepName = $arguments['step_name'] ?? null;

        if (!$pipelineId && $pipelineSlug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
            if ($pipeline && $pipeline->id) {
                $pipelineId = $pipeline->id;
            } else {
                throw new PipelineToolException("Pipeline not found: {$pipelineSlug}");
            }
        }

        $step = null;
        if ($stepId) {
            $step = Bean::load('pipelinesteps', (int)$stepId);
        } elseif ($pipelineId && $stepName) {
            $step = Bean::findOne('pipelinesteps', 'pipelines_id = ? AND step_name = ?', [(int)$pipelineId, $stepName]);
        } else {
            throw new PipelineToolException('Either step_id OR (pipeline_id/pipeline_slug + step_name) is required');
        }

        if (!$step || !$step->id) {
            throw new PipelineToolException('Step not found');
        }

        $result = [
            'id' => (int) $step->id,
            'pipeline_id' => (int) $step->pipelines_id,
            'step_name' => $step->step_name,
            'label' => $step->label,
            'row' => (int) $step->row,
            'col' => (int) $step->col,
            'sequence' => (int) $step->sequence,
            'step_type' => $step->step_type,
            'config' => json_decode($step->config_json ?: '{}', true),
            'input_source' => $step->input_source,
            'on_success' => $step->on_success,
            'on_failure' => $step->on_failure,
            'timeout_seconds' => (int) $step->timeout_seconds,
            'retry_count' => (int) $step->retry_count,
            'is_active' => (bool) $step->is_active
        ];

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Create or update a pipeline step
     */
    public function setStep(array $arguments): array
    {
        $stepId = $arguments['step_id'] ?? null;
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $stepName = $arguments['step_name'] ?? null;

        if (!$pipelineId && $pipelineSlug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
            if ($pipeline && $pipeline->id) {
                $pipelineId = $pipeline->id;
            } else {
                throw new PipelineToolException("Pipeline not found: {$pipelineSlug}");
            }
        }

        $step = null;
        $isUpdate = false;

        if ($stepId) {
            $step = Bean::load('pipelinesteps', (int)$stepId);
            if ($step && $step->id) {
                $isUpdate = true;
            }
        } elseif ($pipelineId && $stepName) {
            $step = Bean::findOne('pipelinesteps', 'pipelines_id = ? AND step_name = ?', [(int)$pipelineId, $stepName]);
            if ($step && $step->id) {
                $isUpdate = true;
            }
        }

        if (!$isUpdate) {
            if (!$pipelineId || !$stepName) {
                throw new PipelineToolException('For new steps, pipeline_id/pipeline_slug and step_name are required');
            }

            $pipeline = Bean::load('pipelines', (int)$pipelineId);
            if (!$pipeline || !$pipeline->id) {
                throw new PipelineToolException("Pipeline {$pipelineId} not found");
            }

            $step = Bean::dispense('pipelinesteps');
            $step->pipelines_id = $pipelineId;
            $step->step_name = $stepName;
            $step->created_at = date('Y-m-d H:i:s');
        }

        if (isset($arguments['label'])) {
            $step->label = $arguments['label'];
        } elseif (!$isUpdate) {
            $step->label = $stepName;
        }

        if (isset($arguments['step_type'])) {
            $step->step_type = $arguments['step_type'];
        } elseif (!$isUpdate) {
            throw new PipelineToolException('step_type is required for new steps');
        }

        if (isset($arguments['config'])) {
            $step->config_json = json_encode($arguments['config']);
        } elseif (!$isUpdate) {
            $step->config_json = '{}';
        }

        if (isset($arguments['row'])) {
            $step->row = (int) $arguments['row'];
        } elseif (!$isUpdate) {
            $step->row = 0;
        }

        if (isset($arguments['col'])) {
            $step->col = (int) $arguments['col'];
        } elseif (!$isUpdate) {
            $step->col = 0;
        }

        if (isset($arguments['sequence'])) {
            $step->sequence = (int) $arguments['sequence'];
        } elseif (!$isUpdate) {
            $step->sequence = ($step->row * 100) + $step->col;
        }

        if (isset($arguments['on_success'])) {
            $step->on_success = $arguments['on_success'];
        } elseif (!$isUpdate) {
            $step->on_success = 'next_col';
        }

        if (isset($arguments['on_failure'])) {
            $step->on_failure = $arguments['on_failure'];
        } elseif (!$isUpdate) {
            $step->on_failure = 'exit';
        }

        if (isset($arguments['timeout_seconds'])) {
            $step->timeout_seconds = (int) $arguments['timeout_seconds'];
        } elseif (!$isUpdate) {
            $step->timeout_seconds = 300;
        }

        if (isset($arguments['is_active'])) {
            $step->is_active = $arguments['is_active'] ? 1 : 0;
        } elseif (!$isUpdate) {
            $step->is_active = 1;
        }

        $step->updated_at = date('Y-m-d H:i:s');

        $stepId = Bean::store($step);

        $action = $isUpdate ? 'updated' : 'created';
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'action' => $action,
                    'step_id' => $stepId,
                    'step_name' => $step->step_name,
                    'pipeline_id' => (int) $step->pipelines_id,
                    'message' => "Step '{$step->step_name}' {$action} successfully"
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Create or update a pipeline (upsert)
     */
    public function setPipeline(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $name = trim($arguments['name'] ?? '');
        $slug = trim($arguments['slug'] ?? '');
        $description = trim($arguments['description'] ?? '');
        $columns = $arguments['columns'] ?? null;
        $triggerType = $arguments['trigger_type'] ?? null;
        $steps = $arguments['steps'] ?? null;
        $exposeAsTool = $arguments['expose_as_tool'] ?? null;
        $inputSchema = $arguments['input_schema'] ?? null;
        $dryRun = $arguments['dry_run'] ?? false;

        $errors = [];
        $warnings = [];
        $isUpdate = false;
        $existingPipeline = null;

        if ($pipelineId) {
            $existingPipeline = Bean::load('pipelines', (int)$pipelineId);
            if ($existingPipeline && $existingPipeline->id) {
                $isUpdate = true;
            } else {
                $errors[] = "Pipeline with ID {$pipelineId} not found";
            }
        } elseif (!empty($slug)) {
            $slug = $this->sanitizeSlug($slug);
            $existingPipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);
            if ($existingPipeline && $existingPipeline->id) {
                $isUpdate = true;
            }
        }

        if (!$isUpdate && empty($name)) {
            $errors[] = 'Pipeline name is required for new pipelines';
        }

        if (!$isUpdate && (empty($steps) || !is_array($steps))) {
            $errors[] = 'At least one step is required for new pipelines';
        }

        if (!$isUpdate && empty($slug) && !empty($name)) {
            $slug = $this->generateSlug($name);
            $slugCheck = Bean::findOne('pipelines', 'slug = ?', [$slug]);
            if ($slugCheck) {
                $errors[] = "A pipeline with slug '{$slug}' already exists. Provide a unique slug.";
            }
        } elseif (!empty($slug)) {
            $slug = $this->sanitizeSlug($slug);
        }

        $validTriggerTypes = ['manual', 'webhook', 'cron'];
        if ($triggerType !== null && !in_array($triggerType, $validTriggerTypes)) {
            $errors[] = "Invalid trigger_type. Must be one of: " . implode(', ', $validTriggerTypes);
        }

        if ($columns !== null && (!is_array($columns) || empty($columns))) {
            $errors[] = "columns must be a non-empty array of strings";
        }

        $stepNames = [];
        $stepTypesUsed = [];
        $integrations = $this->getUserIntegrations();

        if (is_array($steps) && !empty($steps)) {
            foreach ($steps as $index => $step) {
                $stepNum = $index + 1;

                if (empty($step['step_name'])) {
                    $errors[] = "Step #{$stepNum}: step_name is required";
                    continue;
                }

                $stepName = $step['step_name'];

                if (!preg_match('/^[a-z][a-z0-9_]*$/', $stepName)) {
                    $errors[] = "Step '{$stepName}': step_name must start with lowercase letter and contain only lowercase letters, numbers, and underscores";
                }

                if (in_array($stepName, $stepNames)) {
                    $errors[] = "Step '{$stepName}': duplicate step_name";
                }
                $stepNames[] = $stepName;

                $stepType = $step['step_type'] ?? '';
                $typeSchema = PipelineSchemaService::getStepTypeSchema($stepType);
                if (!$typeSchema) {
                    $errors[] = "Step '{$stepName}': unknown step_type '{$stepType}'";
                    continue;
                }
                $stepTypesUsed[] = $stepType;

                if (!isset($step['row']) || !is_numeric($step['row']) || $step['row'] < 0) {
                    $errors[] = "Step '{$stepName}': row must be a non-negative integer";
                }
                if (!isset($step['col']) || !is_numeric($step['col']) || $step['col'] < 0) {
                    $errors[] = "Step '{$stepName}': col must be a non-negative integer";
                }

                $config = $step['config'] ?? [];
                $configValidation = PipelineSchemaService::validateStepConfig($stepType, $config);
                if (!$configValidation['valid']) {
                    foreach ($configValidation['errors'] as $configError) {
                        $errors[] = "Step '{$stepName}': {$configError}";
                    }
                }

                if ($stepType === 'shopify_graphql' && !empty($config['connection_id'])) {
                    $connId = (int) $config['connection_id'];
                    $found = false;
                    foreach ($integrations['shopify_stores'] as $store) {
                        if ($store['id'] === $connId) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $warnings[] = "Step '{$stepName}': Shopify connection ID {$connId} not found in your integrations";
                    }
                }

                if ($stepType === 'direct_exec' && !empty($config['workstation_id'])) {
                    $wsId = (int) $config['workstation_id'];
                    $found = false;
                    foreach ($integrations['workstations'] as $ws) {
                        if ($ws['id'] === $wsId) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $warnings[] = "Step '{$stepName}': Workstation ID {$wsId} not found in your integrations";
                    }
                }

                if ($stepType === 'ai_agent' && !empty($config['agent_id'])) {
                    $agentId = (int) $config['agent_id'];
                    $found = false;
                    foreach ($integrations['ai_agents'] as $agent) {
                        if ($agent['id'] === $agentId) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $warnings[] = "Step '{$stepName}': AI agent ID {$agentId} not found in your integrations";
                    }
                }
            }
        }

        // Dry run mode
        if ($dryRun) {
            $valid = empty($errors);
            $result = [
                'valid' => $valid,
                'mode' => $isUpdate ? 'update' : 'create',
                'pipeline_preview' => [
                    'id' => $isUpdate ? (int) $existingPipeline->id : null,
                    'name' => $name ?: ($isUpdate ? $existingPipeline->name : ''),
                    'slug' => $slug ?: ($isUpdate ? $existingPipeline->slug : ''),
                    'step_count' => is_array($steps) ? count($steps) : ($isUpdate ? Bean::count('pipelinesteps', 'pipelines_id = ?', [$existingPipeline->id]) : 0),
                    'step_types_used' => array_unique($stepTypesUsed),
                    'columns' => $columns ?: ($isUpdate ? json_decode($existingPipeline->columns_json ?: '[]', true) : [])
                ],
                'errors' => $errors,
                'warnings' => $warnings
            ];

            if ($valid) {
                $result['message'] = $isUpdate
                    ? 'Pipeline update is valid. Call again with dry_run=false to apply.'
                    : 'Pipeline is valid. Call again with dry_run=false to create.';
            } else {
                $result['message'] = 'Pipeline validation failed. Fix the errors and try again.';
            }

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT)
                ]],
                'isError' => !$valid
            ];
        }

        if (!empty($errors)) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'success' => false,
                        'errors' => $errors,
                        'warnings' => $warnings,
                        'message' => 'Pipeline validation failed. Use dry_run=true to validate first.'
                    ], JSON_PRETTY_PRINT)
                ]],
                'isError' => true
            ];
        }

        // UPDATE existing pipeline
        if ($isUpdate) {
            $pipeline = $existingPipeline;

            if (!empty($name)) {
                $pipeline->name = $name;
            }
            if (!empty($slug) && $slug !== $pipeline->slug) {
                $slugConflict = Bean::findOne('pipelines', 'slug = ? AND id != ?', [$slug, $pipeline->id]);
                if ($slugConflict) {
                    throw new PipelineToolException("Slug '{$slug}' is already used by another pipeline");
                }
                $pipeline->slug = $slug;
            }
            if (!empty($description) || $description === '') {
                $pipeline->description = $description;
            }
            if ($columns !== null) {
                $pipeline->columns_json = json_encode($columns);
            }
            if ($triggerType !== null) {
                $pipeline->trigger_type = $triggerType;
            }
            if ($exposeAsTool !== null) {
                $pipeline->expose_as_tool = $exposeAsTool ? 1 : 0;
            }
            if ($inputSchema !== null) {
                $pipeline->input_schema_json = json_encode($inputSchema);
            }
            $pipeline->updated_at = date('Y-m-d H:i:s');

            Bean::store($pipeline);

            $createdSteps = [];
            if (is_array($steps) && !empty($steps)) {
                $existingSteps = Bean::find('pipelinesteps', 'pipelines_id = ?', [$pipeline->id]);
                foreach ($existingSteps as $oldStep) {
                    Bean::trash($oldStep);
                }

                foreach ($steps as $stepData) {
                    $step = Bean::dispense('pipelinesteps');
                    $step->pipelines = $pipeline;
                    $step->step_name = $stepData['step_name'];
                    $step->label = $stepData['label'] ?? $stepData['step_name'];
                    $step->row = (int) ($stepData['row'] ?? 0);
                    $step->col = (int) ($stepData['col'] ?? 0);
                    $step->step_type = $stepData['step_type'];
                    $step->config_json = json_encode($stepData['config'] ?? []);
                    $step->input_source = $stepData['input_source'] ?? 'context';
                    $step->input_config_json = json_encode($stepData['input_config'] ?? []);
                    $step->condition_json = '{}';
                    $step->on_success = $stepData['on_success'] ?? 'next_col';
                    $step->on_failure = $stepData['on_failure'] ?? 'exit';
                    $step->timeout_seconds = (int) ($stepData['timeout_seconds'] ?? 300);
                    $step->retry_count = (int) ($stepData['retry_count'] ?? 0);
                    $step->retry_delay_seconds = 10;
                    $step->is_active = 1;
                    $step->run_parallel = 0;
                    $step->sequence = ($step->row * 100) + $step->col;
                    $step->created_at = date('Y-m-d H:i:s');
                    $step->updated_at = date('Y-m-d H:i:s');

                    $sid = Bean::store($step);
                    $createdSteps[] = [
                        'id' => $sid,
                        'step_name' => $step->step_name,
                        'step_type' => $step->step_type
                    ];
                }
            }

            $result = [
                'success' => true,
                'action' => 'updated',
                'pipeline' => [
                    'id' => (int) $pipeline->id,
                    'name' => $pipeline->name,
                    'slug' => $pipeline->slug,
                    'step_count' => !empty($createdSteps) ? count($createdSteps) : Bean::count('pipelinesteps', 'pipelines_id = ?', [$pipeline->id]),
                ],
                'steps_replaced' => !empty($createdSteps),
                'steps' => $createdSteps ?: null,
                'warnings' => $warnings,
                'message' => "Pipeline '{$pipeline->name}' updated successfully."
            ];

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT)
                ]],
                'isError' => false
            ];
        }

        // CREATE new pipeline
        $pipeline = Bean::dispense('pipelines');
        $pipeline->member_id = $this->memberId;
        $pipeline->slug = $slug;
        $pipeline->name = $name;
        $pipeline->description = $description;
        $pipeline->columns_json = json_encode($columns ?: ['Start', 'Execute', 'Complete']);
        $pipeline->trigger_type = $triggerType ?: 'manual';
        $pipeline->trigger_config_json = '{}';
        $pipeline->default_context_json = '{}';
        $pipeline->is_active = 1;
        $pipeline->expose_as_tool = $exposeAsTool ? 1 : 0;
        $pipeline->input_schema_json = $exposeAsTool && !empty($inputSchema) ? json_encode($inputSchema) : '{}';
        $pipeline->run_count = 0;
        $pipeline->created_at = date('Y-m-d H:i:s');
        $pipeline->updated_at = date('Y-m-d H:i:s');

        $newPipelineId = Bean::store($pipeline);

        $createdSteps = [];
        foreach ($steps as $stepData) {
            $step = Bean::dispense('pipelinesteps');
            $step->pipelines = $pipeline;
            $step->step_name = $stepData['step_name'];
            $step->label = $stepData['label'] ?? $stepData['step_name'];
            $step->row = (int) ($stepData['row'] ?? 0);
            $step->col = (int) ($stepData['col'] ?? 0);
            $step->step_type = $stepData['step_type'];
            $step->config_json = json_encode($stepData['config'] ?? []);
            $step->input_source = $stepData['input_source'] ?? 'context';
            $step->input_config_json = json_encode($stepData['input_config'] ?? []);
            $step->condition_json = '{}';
            $step->on_success = $stepData['on_success'] ?? 'next_col';
            $step->on_failure = $stepData['on_failure'] ?? 'exit';
            $step->timeout_seconds = (int) ($stepData['timeout_seconds'] ?? 300);
            $step->retry_count = (int) ($stepData['retry_count'] ?? 0);
            $step->retry_delay_seconds = 10;
            $step->is_active = 1;
            $step->run_parallel = 0;
            $step->sequence = ($step->row * 100) + $step->col;
            $step->created_at = date('Y-m-d H:i:s');
            $step->updated_at = date('Y-m-d H:i:s');

            $sid = Bean::store($step);
            $createdSteps[] = [
                'id' => $sid,
                'step_name' => $step->step_name,
                'step_type' => $step->step_type
            ];
        }

        $result = [
            'success' => true,
            'pipeline' => [
                'id' => $newPipelineId,
                'name' => $name,
                'slug' => $slug,
                'step_count' => count($createdSteps),
            ],
            'steps' => $createdSteps,
            'warnings' => $warnings,
            'message' => "Pipeline '{$name}' created successfully with " . count($createdSteps) . " steps."
        ];

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Delete a pipeline and all its steps
     */
    public function deletePipeline(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $confirm = $arguments['confirm'] ?? false;

        if (!$confirm) {
            throw new PipelineToolException('Must set confirm=true to delete a pipeline');
        }

        if (!$pipelineId && !$slug) {
            throw new PipelineToolException('Either pipeline_id or slug is required');
        }

        $pipeline = null;
        if ($pipelineId) {
            $pipeline = Bean::load('pipelines', (int)$pipelineId);
        } elseif ($slug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        }

        if (!$pipeline || !$pipeline->id) {
            throw new PipelineToolException('Pipeline not found');
        }

        $pipelineName = $pipeline->name;
        $pipelineSlug = $pipeline->slug;
        $deletedPipelineId = (int) $pipeline->id;

        $steps = Bean::find('pipelinesteps', 'pipelines_id = ?', [$pipeline->id]);
        $stepCount = count($steps);
        foreach ($steps as $step) {
            Bean::trash($step);
        }

        Bean::trash($pipeline);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'deleted' => [
                        'pipeline_id' => $deletedPipelineId,
                        'name' => $pipelineName,
                        'slug' => $pipelineSlug,
                        'steps_deleted' => $stepCount
                    ],
                    'message' => "Pipeline '{$pipelineName}' and {$stepCount} steps deleted."
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Delete a step from a pipeline
     */
    public function deleteStep(array $arguments): array
    {
        $stepId = $arguments['step_id'] ?? null;
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $stepName = $arguments['step_name'] ?? null;

        if (!$pipelineId && $pipelineSlug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
            if ($pipeline && $pipeline->id) {
                $pipelineId = $pipeline->id;
            } else {
                throw new PipelineToolException("Pipeline not found: {$pipelineSlug}");
            }
        }

        $step = null;
        if ($stepId) {
            $step = Bean::load('pipelinesteps', (int)$stepId);
        } elseif ($pipelineId && $stepName) {
            $step = Bean::findOne('pipelinesteps', 'pipelines_id = ? AND step_name = ?', [(int)$pipelineId, $stepName]);
        } else {
            throw new PipelineToolException('Either step_id OR (pipeline_id/pipeline_slug + step_name) is required');
        }

        if (!$step || !$step->id) {
            throw new PipelineToolException('Step not found');
        }

        $deletedStepId = (int) $step->id;
        $deletedStepName = $step->step_name;
        $deletedFromPipeline = (int) $step->pipelines_id;

        Bean::trash($step);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'deleted' => [
                        'step_id' => $deletedStepId,
                        'step_name' => $deletedStepName,
                        'pipeline_id' => $deletedFromPipeline
                    ],
                    'message' => "Step '{$deletedStepName}' deleted."
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    // ─── Run Operations ──────────────────────────────────────────────────

    /**
     * Trigger a pipeline run
     */
    public function runPipeline(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $input = $arguments['input'] ?? [];
        $entryStep = $arguments['entry_step'] ?? null;

        if (!$pipelineId && !$slug) {
            throw new PipelineToolException('Either pipeline_id or slug is required');
        }

        $pipeline = null;
        if ($pipelineId) {
            $pipeline = Bean::load('pipelines', (int)$pipelineId);
        } elseif ($slug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        }

        if (!$pipeline || !$pipeline->id) {
            throw new PipelineToolException('Pipeline not found');
        }

        if (!$pipeline->is_active) {
            throw new PipelineToolException('Pipeline is inactive');
        }

        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            throw new PipelineToolException('Pipeline has no active steps', -32000);
        }

        if ($entryStep) {
            $entryStepBean = Bean::findOne('pipelinesteps',
                'pipelines_id = ? AND step_name = ? AND is_active = 1',
                [$pipeline->id, $entryStep]
            );
            if (!$entryStepBean) {
                throw new PipelineToolException("Entry step not found: {$entryStep}");
            }
        }

        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');

        $context = json_decode($pipeline->default_context_json ?: '{}', true);
        $context = array_merge($context, $input);
        $context['mcp_authenticated'] = 'true';

        $triggerData = $input;
        $triggerData['mcp_authenticated'] = 'true';
        if ($entryStep) {
            $triggerData['entry_step'] = $entryStep;
        }

        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member_id = $this->memberId;
        $run->trigger_source = 'mcp_run_pipeline';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = json_encode($context);
        $run->steps_total = $stepCount;
        $run->steps_completed = 0;
        $run->progress_percent = 0;
        $run->entry_step = $entryStep;
        $run->created_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');

        $runId = Bean::store($run);

        $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
        $pipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        PipelineDispatcher::dispatch(
            $runId,
            $this->workspace,
            false,
            ['member_id' => $this->memberId, 'entry_step' => $entryStep]
        );

        $this->logger->info("Pipeline {$pipeline->slug} triggered via run_pipeline", [
            'run_id' => $runId,
            'run_uid' => $runUid,
            'entry_step' => $entryStep
        ]);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'status' => 'running',
                    'run_id' => $runId,
                    'run_uid' => $runUid,
                    'pipeline' => [
                        'id' => (int) $pipeline->id,
                        'name' => $pipeline->name,
                        'slug' => $pipeline->slug
                    ],
                    'entry_step' => $entryStep,
                    'message' => "Pipeline '{$pipeline->name}' started. Use get_run_context with run_id={$runId} to check status.",
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Continue a paused pipeline with input
     */
    public function continuePipeline(array $arguments): array
    {
        $runId = $arguments['run_id'] ?? null;
        $inputToken = $arguments['input_token'] ?? null;
        $input = $arguments['input'] ?? [];

        if (!$runId) {
            throw new PipelineToolException('run_id is required');
        }

        if (!$inputToken) {
            throw new PipelineToolException('input_token is required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            throw new PipelineToolException("Run not found: {$runId}");
        }

        if ($run->status !== 'awaiting_input') {
            throw new PipelineToolException("Run is not awaiting input (status: {$run->status})");
        }

        require_once __DIR__ . '/PipelineExecutor.php';

        try {
            $executor = new PipelineExecutor($runId);
            $result = $executor->resumeFromAwaitInput($input, $inputToken, 'mcp');

            if (isset($result['success']) && $result['success'] === false) {
                throw new PipelineToolException($result['error'] ?? 'Resume failed', -32000);
            }

            $run = Bean::load('pipelineruns', $runId);

            if ($run->status === 'awaiting_input') {
                $awaitingStep = Bean::findOne('pipelinestepruns',
                    ' pipelineruns_id = ? AND awaiting_input = 1 ',
                    [$runId]
                );

                $schema = $awaitingStep ? json_decode($awaitingStep->awaiting_input_schema_json ?: '{}', true) : [];

                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'status' => 'awaiting_input',
                            'run_id' => $runId,
                            'input_token' => $awaitingStep->awaiting_input_token ?? null,
                            'prompt' => $awaitingStep->awaiting_input_prompt ?? 'Waiting for input',
                            'input_schema' => $schema,
                            'output' => $result['output'] ?? null
                        ], JSON_PRETTY_PRINT)
                    ]],
                    'isError' => false
                ];
            }

            $isError = !in_array($run->status, ['completed', 'success']);

            if ($isError) {
                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => "Pipeline failed: " . ($run->error_message ?: 'Unknown error')
                    ]],
                    'isError' => true
                ];
            }

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result['output'] ?? ['status' => 'completed'], JSON_PRETTY_PRINT)
                ]],
                'isError' => false
            ];

        } catch (PipelineToolException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PipelineToolException('Resume failed: ' . $e->getMessage(), -32000);
        }
    }

    /**
     * Schedule a pipeline to run at a future time
     */
    public function schedulePipeline(array $arguments): array
    {
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $delaySeconds = $arguments['delay_seconds'] ?? null;
        $scheduledTime = $arguments['scheduled_time'] ?? null;
        $inputData = $arguments['input_data'] ?? [];
        $entryStep = $arguments['entry_step'] ?? null;
        $description = $arguments['description'] ?? null;

        if (!$pipelineSlug) {
            throw new PipelineToolException('pipeline_slug is required');
        }

        $pipeline = Bean::findOne('pipelines', 'slug = ? AND is_active = 1', [$pipelineSlug]);
        if (!$pipeline) {
            throw new PipelineToolException("Pipeline not found or inactive: {$pipelineSlug}");
        }

        $scheduledFor = null;
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $scheduledFor = date('Y-m-d H:i:s', time() + (int)$delaySeconds);
        } elseif ($scheduledTime) {
            $timestamp = strtotime($scheduledTime);
            if ($timestamp === false || $timestamp <= time()) {
                throw new PipelineToolException("Invalid or past scheduled_time: {$scheduledTime}. Use ISO 8601 format like '2026-01-30T11:30:00-05:00' or relative like '+1 hour'.");
            }
            $scheduledFor = date('Y-m-d H:i:s', $timestamp);
        } else {
            throw new PipelineToolException('Either delay_seconds or scheduled_time is required');
        }

        if ($entryStep) {
            $stepExists = Bean::findOne('pipelinesteps',
                'pipelines_id = ? AND step_name = ? AND is_active = 1',
                [$pipeline->id, $entryStep]
            );
            if (!$stepExists) {
                throw new PipelineToolException("Entry step not found: {$entryStep}");
            }
        }

        $task = Bean::dispense('scheduledtasks');
        $task->task_type = 'execute_pipeline';
        $task->payload_json = json_encode([
            'pipeline_id' => $pipeline->id,
            'pipeline_slug' => $pipelineSlug,
            'input_data' => $inputData,
            'entry_step' => $entryStep,
            'trigger_source' => 'mcp_scheduled'
        ]);
        $task->description = $description ?: "Scheduled via MCP: {$pipeline->name}";
        $task->scheduled_at = $scheduledFor;
        $task->status = 'pending';
        $task->member_id = $this->memberId;
        $task->created_at = date('Y-m-d H:i:s');

        $taskId = Bean::store($task);

        $delayReadable = $this->formatDelay(strtotime($scheduledFor) - time());

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'status' => 'scheduled',
                    'task_id' => $taskId,
                    'pipeline' => $pipeline->name,
                    'pipeline_slug' => $pipelineSlug,
                    'scheduled_for' => $scheduledFor,
                    'scheduled_for_utc' => gmdate('Y-m-d H:i:s', strtotime($scheduledFor)) . ' UTC',
                    'delay' => $delayReadable,
                    'entry_step' => $entryStep,
                    'description' => $task->description
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Execute a pipeline as an MCP tool (myctobot_{slug})
     */
    public function executePipeline(string $slug, array $arguments): array
    {
        $pipeline = Bean::findOne('pipelines', 'slug = ? AND expose_as_tool = 1 AND is_active = 1', [$slug]);
        if (!$pipeline) {
            throw new PipelineToolException("Pipeline not found: {$slug}");
        }

        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            throw new PipelineToolException('Pipeline has no active steps', -32000);
        }

        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');

        $context = json_decode($pipeline->default_context_json ?: '{}', true);
        $context = array_merge($context, $arguments);

        $triggerData = $arguments;
        $triggerData['mcp_authenticated'] = 'true';
        $context['mcp_authenticated'] = 'true';

        $run->run_uid = $runUid;
        $run->pipelines = $pipeline;
        $run->member_id = $this->memberId;
        $run->trigger_source = 'mcp_tool';
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = json_encode($context);
        $run->steps_total = $stepCount;
        $run->steps_completed = 0;
        $run->progress_percent = 0;
        $run->created_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');

        $runId = Bean::store($run);

        $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
        $pipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        PipelineDispatcher::dispatch(
            $runId,
            $this->workspace,
            false,
            ['member_id' => $this->memberId]
        );

        $this->logger->info("Pipeline {$slug} started in background", [
            'run_id' => $runId,
            'run_uid' => $runUid,
        ]);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'status' => 'running',
                    'run_id' => $runId,
                    'run_uid' => $runUid,
                    'message' => "Pipeline '{$pipeline->name}' started in background. Use get_run_context with run_id={$runId} to check status and results.",
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * List pipeline runs with optional filters
     */
    public function listRuns(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $status = $arguments['status'] ?? null;
        $limit = (int) ($arguments['limit'] ?? 20);

        if (!$pipelineId && $pipelineSlug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
            if ($pipeline && $pipeline->id) {
                $pipelineId = $pipeline->id;
            }
        }

        $where = ' 1=1 ';
        $params = [];

        if ($pipelineId) {
            $where .= ' AND pipelines_id = ? ';
            $params[] = $pipelineId;
        }

        if ($status) {
            $where .= ' AND status = ? ';
            $params[] = $status;
        }

        $where .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit));

        $runs = Bean::find('pipelineruns', $where, $params);

        $result = [];
        foreach ($runs as $run) {
            $pipeline = Bean::load('pipelines', $run->pipelines_id);

            $result[] = [
                'id' => (int) $run->id,
                'run_uid' => $run->run_uid,
                'pipeline' => [
                    'id' => (int) $run->pipelines_id,
                    'name' => $pipeline ? $pipeline->name : null,
                    'slug' => $pipeline ? $pipeline->slug : null
                ],
                'status' => $run->status,
                'trigger_source' => $run->trigger_source,
                'progress_percent' => (int) $run->progress_percent,
                'steps_completed' => (int) $run->steps_completed,
                'steps_total' => (int) $run->steps_total,
                'created_at' => $run->created_at,
                'updated_at' => $run->updated_at,
                'completed_at' => $run->completed_at
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'runs' => $result,
                    'count' => count($result)
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Get detailed information about a pipeline run
     */
    public function getRun(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $includeStepRuns = $arguments['include_step_runs'] ?? true;
        $includeContext = $arguments['include_context'] ?? false;

        if (!$runId) {
            throw new PipelineToolException('run_id is required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Run {$runId} not found");
        }

        $pipeline = Bean::load('pipelines', $run->pipelines_id);

        $result = [
            'id' => (int) $run->id,
            'run_uid' => $run->run_uid,
            'pipeline' => [
                'id' => (int) $run->pipelines_id,
                'name' => $pipeline ? $pipeline->name : null,
                'slug' => $pipeline ? $pipeline->slug : null
            ],
            'status' => $run->status,
            'trigger_source' => $run->trigger_source,
            'trigger_data' => json_decode($run->trigger_data_json ?: '{}', true),
            'progress_percent' => (int) $run->progress_percent,
            'steps_completed' => (int) $run->steps_completed,
            'steps_total' => (int) $run->steps_total,
            'current_step' => $run->current_step,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at,
            'updated_at' => $run->updated_at,
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at
        ];

        if ($includeContext) {
            $result['context'] = json_decode($run->context_json ?: '{}', true);
        }

        if ($includeStepRuns) {
            $stepRuns = Bean::find('pipelinestepruns', 'pipelineruns_id = ? ORDER BY started_at', [$runId]);
            $result['step_runs'] = [];

            foreach ($stepRuns as $stepRun) {
                $stepRunData = [
                    'id' => (int) $stepRun->id,
                    'step_name' => $stepRun->step_name,
                    'step_type' => $stepRun->step_type,
                    'status' => $stepRun->status,
                    'started_at' => $stepRun->started_at,
                    'completed_at' => $stepRun->completed_at,
                    'duration_ms' => (int) $stepRun->duration_ms,
                    'error_message' => $stepRun->error_message
                ];

                $output = json_decode($stepRun->output_json ?: '{}', true);
                if (!empty($output)) {
                    $stepRunData['has_output'] = true;
                    $outputJson = json_encode($output);
                    if (strlen($outputJson) < 1000) {
                        $stepRunData['output'] = $output;
                    }
                }

                if ($stepRun->awaiting_input) {
                    $stepRunData['awaiting_input'] = true;
                    $stepRunData['awaiting_input_prompt'] = $stepRun->awaiting_input_prompt;
                }

                $result['step_runs'][] = $stepRunData;
            }
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Cancel a running pipeline
     */
    public function cancelRun(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $reason = $arguments['reason'] ?? 'Cancelled via MCP';

        if (!$runId) {
            throw new PipelineToolException('run_id is required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Run {$runId} not found");
        }

        $finishedStatuses = ['completed', 'failed', 'cancelled'];
        if (in_array($run->status, $finishedStatuses)) {
            throw new PipelineToolException("Run is already {$run->status}, cannot cancel");
        }

        $previousStatus = $run->status;

        $run->status = 'cancelled';
        $run->error_message = $reason;
        $run->updated_at = date('Y-m-d H:i:s');
        $run->completed_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $workspace = $this->workspace ?: 'dev';
        $sessionPattern = "aoe-{$workspace}-PIPE-{$runId}-";

        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $sessions);
        $killedSessions = [];
        foreach ($sessions as $session) {
            if (str_starts_with($session, $sessionPattern)) {
                exec("tmux kill-session -t " . escapeshellarg($session) . " 2>/dev/null");
                $killedSessions[] = $session;
            }
        }

        $runningStepRuns = Bean::find('pipelinestepruns',
            'pipelineruns_id = ? AND status = ?',
            [$runId, 'running']
        );
        foreach ($runningStepRuns as $stepRun) {
            $stepRun->status = 'cancelled';
            $stepRun->error_message = $reason;
            $stepRun->completed_at = date('Y-m-d H:i:s');
            Bean::store($stepRun);
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'run_id' => $runId,
                    'previous_status' => $previousStatus,
                    'new_status' => 'cancelled',
                    'reason' => $reason,
                    'sessions_killed' => count($killedSessions),
                    'message' => "Run {$runId} cancelled."
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Export a pipeline as JSON
     */
    public function exportPipeline(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $slug = $arguments['slug'] ?? null;

        if (!$pipelineId && !$slug) {
            throw new PipelineToolException('Either pipeline_id or slug is required');
        }

        $pipeline = null;
        if ($pipelineId) {
            $pipeline = Bean::load('pipelines', (int)$pipelineId);
        } elseif ($slug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        }

        if (!$pipeline || !$pipeline->id) {
            throw new PipelineToolException('Pipeline not found');
        }

        $export = [
            'version' => '1.0',
            'exported_at' => date('c'),
            'source_workspace' => $this->workspace,
            'pipeline' => [
                'name' => $pipeline->name,
                'slug' => $pipeline->slug,
                'description' => $pipeline->description,
                'columns_json' => json_decode($pipeline->columns_json ?: '[]', true),
                'trigger_type' => $pipeline->trigger_type,
                'trigger_config_json' => json_decode($pipeline->trigger_config_json ?: '{}', true),
                'default_context_json' => json_decode($pipeline->default_context_json ?: '{}', true),
                'input_schema_json' => json_decode($pipeline->input_schema_json ?: '{}', true),
                'expose_as_tool' => (bool) $pipeline->expose_as_tool,
                'is_active' => (bool) $pipeline->is_active
            ],
            'steps' => []
        ];

        $steps = Bean::find('pipelinesteps', 'pipelines_id = ? ORDER BY sequence, col', [$pipeline->id]);
        foreach ($steps as $step) {
            $export['steps'][] = [
                'step_name' => $step->step_name,
                'label' => $step->label,
                'row' => (int) $step->row,
                'col' => (int) $step->col,
                'step_type' => $step->step_type,
                'config_json' => json_decode($step->config_json ?: '{}', true),
                'input_source' => $step->input_source,
                'input_config_json' => json_decode($step->input_config_json ?: '{}', true),
                'condition_json' => json_decode($step->condition_json ?: '{}', true),
                'output_mappings_json' => json_decode($step->output_mappings_json ?: '{}', true),
                'on_success' => $step->on_success,
                'on_failure' => $step->on_failure,
                'timeout_seconds' => (int) $step->timeout_seconds,
                'retry_count' => (int) $step->retry_count,
                'retry_delay_seconds' => (int) $step->retry_delay_seconds,
                'is_active' => (bool) $step->is_active,
                'run_parallel' => (bool) $step->run_parallel
            ];
        }

        $export['dependencies'] = [
            'ai_agents' => [],
            'mcp_servers' => [],
            'repo_connections' => [],
            'shopify_connections' => [],
            'workstations' => []
        ];

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($export, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Import a pipeline from JSON export
     */
    public function importPipeline(array $arguments): array
    {
        $pipelineJson = $arguments['pipeline_json'] ?? null;
        $newName = $arguments['new_name'] ?? null;
        $newSlug = $arguments['new_slug'] ?? null;
        $dryRun = $arguments['dry_run'] ?? false;

        if (!$pipelineJson || !is_array($pipelineJson)) {
            throw new PipelineToolException('pipeline_json is required and must be an object');
        }

        $errors = [];
        $warnings = [];

        if (!isset($pipelineJson['pipeline'])) {
            $errors[] = 'Missing "pipeline" key in export JSON';
        }
        if (!isset($pipelineJson['steps']) || !is_array($pipelineJson['steps'])) {
            $errors[] = 'Missing or invalid "steps" array in export JSON';
        }

        if (!empty($errors)) {
            throw new PipelineToolException(implode('; ', $errors));
        }

        $pipelineData = $pipelineJson['pipeline'];
        $stepsData = $pipelineJson['steps'];

        $name = $newName ?: ($pipelineData['name'] ?? 'Imported Pipeline');
        $slug = $newSlug ?: ($pipelineData['slug'] ?? $this->generateSlug($name));
        $slug = $this->sanitizeSlug($slug);

        $existing = Bean::findOne('pipelines', 'slug = ?', [$slug]);
        if ($existing) {
            if ($newSlug) {
                $errors[] = "Slug '{$slug}' already exists";
            } else {
                $baseSlug = $slug;
                $counter = 1;
                while ($existing) {
                    $slug = $baseSlug . '-' . $counter;
                    $existing = Bean::findOne('pipelines', 'slug = ?', [$slug]);
                    $counter++;
                }
                $warnings[] = "Slug auto-modified to '{$slug}' to avoid conflict";
            }
        }

        $stepTypesUsed = [];
        foreach ($stepsData as $index => $step) {
            $stepNum = $index + 1;
            if (empty($step['step_name'])) {
                $errors[] = "Step #{$stepNum}: missing step_name";
            }
            if (empty($step['step_type'])) {
                $errors[] = "Step #{$stepNum}: missing step_type";
            } else {
                $typeSchema = PipelineSchemaService::getStepTypeSchema($step['step_type']);
                if (!$typeSchema) {
                    $errors[] = "Step #{$stepNum}: unknown step_type '{$step['step_type']}'";
                }
                $stepTypesUsed[] = $step['step_type'];
            }
        }

        if ($dryRun) {
            $valid = empty($errors);
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'valid' => $valid,
                        'preview' => [
                            'name' => $name,
                            'slug' => $slug,
                            'step_count' => count($stepsData),
                            'step_types' => array_unique($stepTypesUsed)
                        ],
                        'errors' => $errors,
                        'warnings' => $warnings,
                        'message' => $valid
                            ? 'Import is valid. Call again with dry_run=false to import.'
                            : 'Import validation failed.'
                    ], JSON_PRETTY_PRINT)
                ]],
                'isError' => !$valid
            ];
        }

        if (!empty($errors)) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'success' => false,
                        'errors' => $errors,
                        'warnings' => $warnings
                    ], JSON_PRETTY_PRINT)
                ]],
                'isError' => true
            ];
        }

        $pipeline = Bean::dispense('pipelines');
        $pipeline->member_id = $this->memberId;
        $pipeline->slug = $slug;
        $pipeline->name = $name;
        $pipeline->description = $pipelineData['description'] ?? '';
        $pipeline->columns_json = json_encode($pipelineData['columns_json'] ?? ['Start', 'Execute', 'Complete']);
        $pipeline->trigger_type = $pipelineData['trigger_type'] ?? 'manual';
        $pipeline->trigger_config_json = json_encode($pipelineData['trigger_config_json'] ?? []);
        $pipeline->default_context_json = json_encode($pipelineData['default_context_json'] ?? []);
        $pipeline->input_schema_json = json_encode($pipelineData['input_schema_json'] ?? []);
        $pipeline->expose_as_tool = ($pipelineData['expose_as_tool'] ?? false) ? 1 : 0;
        $pipeline->is_active = ($pipelineData['is_active'] ?? true) ? 1 : 0;
        $pipeline->run_count = 0;
        $pipeline->created_at = date('Y-m-d H:i:s');
        $pipeline->updated_at = date('Y-m-d H:i:s');

        $newPipelineId = Bean::store($pipeline);

        $createdSteps = [];
        foreach ($stepsData as $stepData) {
            $step = Bean::dispense('pipelinesteps');
            $step->pipelines = $pipeline;
            $step->step_name = $stepData['step_name'];
            $step->label = $stepData['label'] ?? $stepData['step_name'];
            $step->row = (int) ($stepData['row'] ?? 0);
            $step->col = (int) ($stepData['col'] ?? 0);
            $step->step_type = $stepData['step_type'];
            $step->config_json = json_encode($stepData['config_json'] ?? []);
            $step->input_source = $stepData['input_source'] ?? 'context';
            $step->input_config_json = json_encode($stepData['input_config_json'] ?? []);
            $step->condition_json = json_encode($stepData['condition_json'] ?? []);
            $step->output_mappings_json = json_encode($stepData['output_mappings_json'] ?? []);
            $step->on_success = $stepData['on_success'] ?? 'next_col';
            $step->on_failure = $stepData['on_failure'] ?? 'exit';
            $step->timeout_seconds = (int) ($stepData['timeout_seconds'] ?? 300);
            $step->retry_count = (int) ($stepData['retry_count'] ?? 0);
            $step->retry_delay_seconds = (int) ($stepData['retry_delay_seconds'] ?? 10);
            $step->is_active = ($stepData['is_active'] ?? true) ? 1 : 0;
            $step->run_parallel = ($stepData['run_parallel'] ?? false) ? 1 : 0;
            $step->sequence = ($step->row * 100) + $step->col;
            $step->created_at = date('Y-m-d H:i:s');
            $step->updated_at = date('Y-m-d H:i:s');

            $sid = Bean::store($step);
            $createdSteps[] = [
                'id' => $sid,
                'step_name' => $step->step_name,
                'step_type' => $step->step_type
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'pipeline' => [
                        'id' => $newPipelineId,
                        'name' => $name,
                        'slug' => $slug,
                        'step_count' => count($createdSteps),
                    ],
                    'steps' => $createdSteps,
                    'warnings' => $warnings,
                    'message' => "Pipeline '{$name}' imported successfully with " . count($createdSteps) . " steps."
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    // ─── Clone Operation ────────────────────────────────────────────────

    /**
     * Clone an existing pipeline with optional customizations
     */
    public function clonePipeline(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $newName = $arguments['new_name'] ?? null;
        $newSlug = $arguments['new_slug'] ?? null;
        $stepOverrides = $arguments['step_overrides'] ?? [];
        $pipelineOverrides = $arguments['pipeline_overrides'] ?? [];

        if (!$pipelineId && !$slug) {
            throw new PipelineToolException('Either pipeline_id or slug is required');
        }

        // 1. Export the source pipeline
        $exportResult = $this->exportPipeline([
            'pipeline_id' => $pipelineId,
            'slug' => $slug,
        ]);

        // Parse the export JSON from the result text
        $exportJson = json_decode($exportResult['content'][0]['text'], true);
        if (!$exportJson || !isset($exportJson['pipeline'])) {
            throw new PipelineToolException('Failed to export source pipeline');
        }

        // Default name if not provided
        $sourceName = $exportJson['pipeline']['name'] ?? 'Pipeline';
        if (!$newName) {
            $newName = $sourceName . ' (Clone)';
        }

        // 2. Apply pipeline-level overrides
        foreach ($pipelineOverrides as $key => $value) {
            $exportJson['pipeline'][$key] = $value;
        }

        // 3. Apply step-level overrides (deep merge config_json)
        if (!empty($stepOverrides) && !empty($exportJson['steps'])) {
            foreach ($exportJson['steps'] as &$step) {
                $stepName = $step['step_name'] ?? '';
                if (isset($stepOverrides[$stepName])) {
                    $overrides = $stepOverrides[$stepName];

                    // Deep merge config_json if provided
                    if (isset($overrides['config']) && isset($step['config_json'])) {
                        $step['config_json'] = array_replace_recursive(
                            $step['config_json'],
                            $overrides['config']
                        );
                        unset($overrides['config']);
                    }

                    // Apply any other step-level overrides (label, step_type, etc.)
                    foreach ($overrides as $k => $v) {
                        $step[$k] = $v;
                    }
                }
            }
            unset($step);
        }

        // 4. Import the modified export
        return $this->importPipeline([
            'pipeline_json' => $exportJson,
            'new_name' => $newName,
            'new_slug' => $newSlug,
        ]);
    }

    // ─── Schedule Operations ─────────────────────────────────────────────

    /**
     * Create a recurring schedule
     */
    public function createSchedule(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $name = $arguments['name'] ?? '';
        $scheduleType = $arguments['schedule_type'] ?? 'daily';
        $scheduleConfig = $arguments['schedule_config'] ?? [];
        $timezone = $arguments['timezone'] ?? 'UTC';
        $maxConcurrent = $arguments['max_concurrent'] ?? 1;
        $onOverlap = $arguments['on_overlap'] ?? 'skip';
        $inputData = $arguments['input_data'] ?? [];

        if (!$pipelineId && $pipelineSlug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
            if ($pipeline && $pipeline->id) {
                $pipelineId = $pipeline->id;
            }
        }

        if (!$pipelineId) {
            throw new PipelineToolException('pipeline_id or pipeline_slug is required');
        }

        $pipeline = Bean::load('pipelines', (int)$pipelineId);
        if (!$pipeline || !$pipeline->id) {
            throw new PipelineToolException('Pipeline not found');
        }

        if (empty($name)) {
            throw new PipelineToolException('name is required');
        }

        $errors = SchedulerService::validate([
            'pipeline_id' => $pipelineId,
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'on_overlap' => $onOverlap
        ]);

        if (!empty($errors)) {
            throw new PipelineToolException(implode('; ', $errors));
        }

        $scheduleId = SchedulerService::create([
            'pipeline_id' => $pipelineId,
            'name' => $name,
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'timezone' => $timezone,
            'max_concurrent' => $maxConcurrent,
            'on_overlap' => $onOverlap,
            'input_data' => $inputData
        ]);

        $schedule = Bean::load('scheduledrecurring', $scheduleId);

        $nextRuns = SchedulerService::previewNextRuns(
            $scheduleType,
            $scheduleConfig,
            $timezone,
            5
        );

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'schedule' => [
                        'id' => $scheduleId,
                        'name' => $name,
                        'pipeline_id' => (int) $pipelineId,
                        'pipeline_slug' => $pipeline->slug,
                        'schedule_type' => $scheduleType,
                        'timezone' => $timezone,
                        'next_run_at' => $schedule->next_run_at
                    ],
                    'next_runs_preview' => $nextRuns,
                    'message' => "Schedule '{$name}' created. Next run: {$schedule->next_run_at}"
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * List schedules
     */
    public function listSchedules(array $arguments): array
    {
        $pipelineId = $arguments['pipeline_id'] ?? null;
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $includeInactive = $arguments['include_inactive'] ?? false;

        if (!$pipelineId && $pipelineSlug) {
            $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
            if ($pipeline && $pipeline->id) {
                $pipelineId = $pipeline->id;
            }
        }

        $where = $includeInactive ? ' 1=1 ' : ' is_active = 1 ';
        $params = [];

        if ($pipelineId) {
            $where .= ' AND pipeline_id = ? ';
            $params[] = $pipelineId;
        }

        $schedules = Bean::find('scheduledrecurring', $where . ' ORDER BY next_run_at ASC ', $params);

        $result = [];
        foreach ($schedules as $schedule) {
            $pipeline = Bean::load('pipelines', $schedule->pipeline_id);
            $result[] = [
                'id' => (int) $schedule->id,
                'name' => $schedule->name,
                'pipeline' => [
                    'id' => (int) $schedule->pipeline_id,
                    'name' => $pipeline ? $pipeline->name : null,
                    'slug' => $pipeline ? $pipeline->slug : null
                ],
                'schedule_type' => $schedule->schedule_type,
                'timezone' => $schedule->timezone,
                'is_active' => (bool) $schedule->is_active,
                'next_run_at' => $schedule->next_run_at,
                'last_run_at' => $schedule->last_run_at,
                'run_count' => (int) $schedule->run_count,
                'current_running' => (int) $schedule->current_running
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'schedules' => $result,
                    'count' => count($result)
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Get schedule details
     */
    public function getSchedule(array $arguments): array
    {
        $scheduleId = (int) ($arguments['schedule_id'] ?? 0);
        $previewRuns = (int) ($arguments['preview_runs'] ?? 5);

        if (!$scheduleId) {
            throw new PipelineToolException('schedule_id is required');
        }

        $schedule = Bean::load('scheduledrecurring', $scheduleId);
        if (!$schedule || !$schedule->id) {
            throw new PipelineToolException('Schedule not found');
        }

        $pipeline = Bean::load('pipelines', $schedule->pipeline_id);
        $config = json_decode($schedule->schedule_config_json ?: '{}', true);

        $nextRuns = SchedulerService::previewNextRuns(
            $schedule->schedule_type,
            $config,
            $schedule->timezone,
            $previewRuns
        );

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'id' => (int) $schedule->id,
                    'name' => $schedule->name,
                    'description' => $schedule->description,
                    'pipeline' => [
                        'id' => (int) $schedule->pipeline_id,
                        'name' => $pipeline ? $pipeline->name : null,
                        'slug' => $pipeline ? $pipeline->slug : null
                    ],
                    'schedule_type' => $schedule->schedule_type,
                    'schedule_config' => $config,
                    'timezone' => $schedule->timezone,
                    'max_concurrent' => (int) $schedule->max_concurrent,
                    'on_overlap' => $schedule->on_overlap,
                    'queue_limit' => (int) $schedule->queue_limit,
                    'is_active' => (bool) $schedule->is_active,
                    'next_run_at' => $schedule->next_run_at,
                    'last_run_at' => $schedule->last_run_at,
                    'run_count' => (int) $schedule->run_count,
                    'current_running' => (int) $schedule->current_running,
                    'queued_count' => (int) $schedule->queued_count,
                    'input_data' => json_decode($schedule->input_data_json ?: '{}', true),
                    'next_runs_preview' => $nextRuns,
                    'created_at' => $schedule->created_at
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Update a schedule
     */
    public function updateSchedule(array $arguments): array
    {
        $scheduleId = (int) ($arguments['schedule_id'] ?? 0);

        if (!$scheduleId) {
            throw new PipelineToolException('schedule_id is required');
        }

        $schedule = Bean::load('scheduledrecurring', $scheduleId);
        if (!$schedule || !$schedule->id) {
            throw new PipelineToolException('Schedule not found');
        }

        if (isset($arguments['name'])) {
            $schedule->name = $arguments['name'];
        }
        if (isset($arguments['schedule_type'])) {
            $schedule->schedule_type = $arguments['schedule_type'];
        }
        if (isset($arguments['schedule_config'])) {
            $schedule->schedule_config_json = json_encode($arguments['schedule_config']);
        }
        if (isset($arguments['timezone'])) {
            $schedule->timezone = $arguments['timezone'];
        }
        if (isset($arguments['max_concurrent'])) {
            $schedule->max_concurrent = (int) $arguments['max_concurrent'];
        }
        if (isset($arguments['on_overlap'])) {
            $schedule->on_overlap = $arguments['on_overlap'];
        }
        if (isset($arguments['is_active'])) {
            $schedule->is_active = $arguments['is_active'] ? 1 : 0;
        }

        $config = json_decode($schedule->schedule_config_json ?: '{}', true);
        $schedule->next_run_at = SchedulerService::calculateNextRun(
            $schedule->schedule_type,
            $config,
            $schedule->timezone
        );
        $schedule->updated_at = date('Y-m-d H:i:s');

        Bean::store($schedule);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'schedule_id' => $scheduleId,
                    'next_run_at' => $schedule->next_run_at,
                    'message' => "Schedule updated. Next run: {$schedule->next_run_at}"
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Delete a schedule
     */
    public function deleteSchedule(array $arguments): array
    {
        $scheduleId = (int) ($arguments['schedule_id'] ?? 0);

        if (!$scheduleId) {
            throw new PipelineToolException('schedule_id is required');
        }

        $schedule = Bean::load('scheduledrecurring', $scheduleId);
        if (!$schedule || !$schedule->id) {
            throw new PipelineToolException('Schedule not found');
        }

        $name = $schedule->name;
        Bean::trash($schedule);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'deleted_id' => $scheduleId,
                    'message' => "Schedule '{$name}' deleted."
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    // ─── Inter-Agent Communication ───────────────────────────────────────

    /**
     * Send a message to another step's resident Claude session
     */
    public function sendToStep(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $stepName = $arguments['step_name'] ?? '';
        $message = $arguments['message'] ?? '';

        if (!$runId || !$stepName || !$message) {
            throw new PipelineToolException('run_id, step_name, and message are required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Pipeline run {$runId} not found");
        }

        $issueKey = sprintf('PIPE-%d-%s', $runId, $stepName);

        require_once __DIR__ . '/TmuxService.php';
        $tmuxService = new TmuxService(
            $run->member_id,
            $issueKey,
            null,
            $this->workspace
        );

        if (!$tmuxService->exists()) {
            throw new PipelineToolException("No active session found for step '{$stepName}' in run {$runId}");
        }

        $result = $tmuxService->sendMessage($message);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => $result,
                    'run_id' => $runId,
                    'step_name' => $stepName,
                    'session' => $tmuxService->getActiveSessionName(),
                    'message' => $result ? 'Message sent to session' : 'Failed to send message'
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => !$result
        ];
    }

    /**
     * Get pipeline run context (shared state)
     */
    public function getRunContext(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $key = $arguments['key'] ?? null;

        if (!$runId) {
            throw new PipelineToolException('run_id is required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Pipeline run {$runId} not found");
        }

        $context = json_decode($run->context_json ?: '{}', true);

        if ($key !== null) {
            $value = $context[$key] ?? null;
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'run_id' => $runId,
                        'key' => $key,
                        'value' => $value
                    ], JSON_PRETTY_PRINT)
                ]],
                'isError' => false
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'run_id' => $runId,
                    'context' => $context
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Update pipeline run context (shared state)
     */
    public function updateRunContext(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $key = $arguments['key'] ?? '';
        $value = $arguments['value'] ?? null;

        if (!$runId || !$key) {
            throw new PipelineToolException('run_id and key are required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Pipeline run {$runId} not found");
        }

        $context = json_decode($run->context_json ?: '{}', true);
        $context[$key] = $value;
        $run->context_json = json_encode($context);
        $run->updated_at = date('Y-m-d H:i:s');
        Bean::store($run);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'run_id' => $runId,
                    'key' => $key,
                    'message' => 'Context updated'
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Mark a step as complete
     */
    public function markStepComplete(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $stepName = $arguments['step_name'] ?? '';
        $output = $arguments['output'] ?? null;
        $summary = $arguments['summary'] ?? '';

        if (!$runId || !$stepName) {
            throw new PipelineToolException('run_id and step_name are required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Pipeline run {$runId} not found");
        }

        $issueKey = sprintf('PIPE-%d-%s', $runId, $stepName);
        $job = Bean::findOne('aidevjobs', 'issue_key = ? AND status = ?', [$issueKey, 'running']);

        if ($job) {
            $job->status = 'complete';
            $job->completed_at = date('Y-m-d H:i:s');
            $job->updated_at = date('Y-m-d H:i:s');
            if ($output) {
                $job->last_result_json = json_encode($output);
            }
            if ($summary) {
                $existingResult = json_decode($job->last_result_json ?: '{}', true);
                $existingResult['summary'] = $summary;
                $job->last_result_json = json_encode($existingResult);
            }
            Bean::store($job);
        }

        if ($output) {
            $context = json_decode($run->context_json ?: '{}', true);
            $context[$stepName] = [
                'output' => $output,
                'completed_at' => date('Y-m-d H:i:s')
            ];
            $run->context_json = json_encode($context);
            $run->updated_at = date('Y-m-d H:i:s');
            Bean::store($run);
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'run_id' => $runId,
                    'step_name' => $stepName,
                    'job_id' => $job ? $job->id : null,
                    'message' => 'Step marked as complete'
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * List active sessions for a pipeline run
     */
    public function listRunSessions(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);

        if (!$runId) {
            throw new PipelineToolException('run_id is required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Pipeline run {$runId} not found");
        }

        $workspace = $this->workspace ?: 'dev';
        $pattern = "aoe-{$workspace}-PIPE-{$runId}-";

        $sessions = [];
        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $output);
        foreach ($output as $session) {
            if (str_starts_with($session, $pattern)) {
                $remainder = substr($session, strlen($pattern));
                $lastHyphen = strrpos($remainder, '-');
                $stepNameSanitized = $lastHyphen !== false
                    ? substr($remainder, 0, $lastHyphen)
                    : $remainder;
                $stepName = str_replace('-', '_', $stepNameSanitized);

                $sessions[] = [
                    'session_name' => $session,
                    'step_name' => $stepName,
                    'pattern' => $pattern
                ];
            }
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'run_id' => $runId,
                    'active_sessions' => $sessions,
                    'count' => count($sessions)
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Post a message to the pipeline chat
     */
    public function postMessage(array $arguments): array
    {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $message = $arguments['message'] ?? '';

        if (!$runId || empty($message)) {
            throw new PipelineToolException('run_id and message are required');
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            throw new PipelineToolException("Pipeline run {$runId} not found");
        }

        $context = json_decode($run->context_json ?: '{}', true);
        if (!isset($context['_messages'])) {
            $context['_messages'] = [];
        }

        $context['_messages'][] = [
            'role' => 'agent',
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if (count($context['_messages']) > 50) {
            $context['_messages'] = array_slice($context['_messages'], -50);
        }

        $run->context_json = json_encode($context);
        $run->updated_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $awaitingStep = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND awaiting_input = 1 ',
            [$run->id]
        );
        if ($awaitingStep) {
            $awaitingStep->expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            Bean::store($awaitingStep);
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'run_id' => $runId,
                    'message_count' => count($context['_messages'])
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    // ─── Private Helpers ─────────────────────────────────────────────────

    private function getUserIntegrations(): array
    {
        $integrations = [
            'shopify_stores' => [],
            'workstations' => [],
            'mcp_servers' => [],
            'repositories' => [],
            'ai_agents' => [],
            'mailgun_configured' => false
        ];

        $shopifyConnections = Bean::find('connections', ' connector_type = ? ORDER BY external_name ASC ', ['shopify']);
        foreach ($shopifyConnections as $conn) {
            $integrations['shopify_stores'][] = [
                'id' => (int) $conn->id,
                'name' => $conn->external_name ?: $conn->external_eid,
                'domain' => $conn->external_eid,
                'connection_name' => $conn->connection_name
            ];
        }

        $runners = Bean::find('runners', ' is_active = 1 ORDER BY name ASC ');
        foreach ($runners as $runner) {
            $integrations['workstations'][] = [
                'id' => (int) $runner->id,
                'name' => $runner->name,
                'host' => $runner->host,
                'type' => $runner->runner_type ?? 'ssh'
            ];
        }

        $mcpServers = Bean::find('mcpservers', ' is_active = 1 ORDER BY name ASC ');
        foreach ($mcpServers as $server) {
            $integrations['mcp_servers'][] = [
                'id' => (int) $server->id,
                'name' => $server->name,
                'type' => $server->server_type
            ];
        }

        $repos = Bean::find('repoconnections', ' is_active = 1 ORDER BY repo_name ASC ');
        foreach ($repos as $repo) {
            $integrations['repositories'][] = [
                'id' => (int) $repo->id,
                'name' => $repo->repo_full_name ?? $repo->repo_name,
                'slug' => $repo->slug
            ];
        }

        $agents = Bean::find('aiagents', ' is_active = 1 ORDER BY name ASC ');
        foreach ($agents as $agent) {
            $integrations['ai_agents'][] = [
                'id' => (int) $agent->id,
                'name' => $agent->name,
                'description' => $agent->description
            ];
        }

        $mailgunConn = Bean::findOne('connections', 'connector_type = ? AND enabled = 1 ORDER BY id ASC', ['mailgun']);
        $integrations['mailgun_configured'] = !empty($mailgunConn?->access_token);

        return $integrations;
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'pipeline-' . time();
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'pipeline-' . time();
    }

    private function formatDelay(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        } elseif ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return "{$minutes} minute" . ($minutes > 1 ? 's' : '');
        } elseif ($seconds < 86400) {
            $hours = round($seconds / 3600, 1);
            return "{$hours} hour" . ($hours > 1 ? 's' : '');
        } else {
            $days = round($seconds / 86400, 1);
            return "{$days} day" . ($days > 1 ? 's' : '');
        }
    }
}
