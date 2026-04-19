<?php

namespace app\services;

use \Flight as Flight;
use \app\Bean;

/**
 * StudioPipelineGenerator
 *
 * Generates real pipelines from Studio templates + wizard variables.
 * Takes a template's pipeline_template_json and substitutes variables
 * from the wizard, then creates the actual pipeline and steps.
 */
class StudioPipelineGenerator
{
    private int $memberId;
    private bool $verbose;

    public function __construct(int $memberId, bool $verbose = false)
    {
        $this->memberId = $memberId;
        $this->verbose = $verbose;
    }

    /**
     * Generate a pipeline from a template and variables
     *
     * @param object $template The studiotemplates bean
     * @param array $variables The wizard-collected variables
     * @param string|null $name Optional custom pipeline name
     * @return array ['success' => bool, 'pipeline_id' => int, 'error' => string|null]
     */
    public function generate($template, array $variables, ?string $name = null): array
    {
        try {
            // Parse template JSON
            $pipelineTemplate = json_decode($template->pipeline_template_json ?: '{}', true);
            $wizardConfig = json_decode($template->wizard_config_json ?: '{}', true);

            if (empty($pipelineTemplate['steps'])) {
                return [
                    'success' => false,
                    'error' => 'Template has no pipeline steps defined'
                ];
            }

            // Generate pipeline name
            $pipelineName = $name ?? $this->generatePipelineName($template, $variables);
            $slug = $this->generateSlug($pipelineName);

            // Ensure slug is unique
            $slug = $this->ensureUniqueSlug($slug);

            // Create the pipeline
            $pipeline = $this->createPipeline($pipelineName, $slug, $template, $variables);

            if ($this->verbose) {
                echo "Created pipeline: {$pipeline->name} (ID: {$pipeline->id})\n";
            }

            // Generate columns from template steps
            $columns = $this->generateColumns($pipelineTemplate['steps']);
            $pipeline->columns_json = json_encode($columns);
            Bean::store($pipeline);

            // Create steps with variable substitution
            $stepCount = $this->createSteps($pipeline, $pipelineTemplate['steps'], $variables);

            if ($this->verbose) {
                echo "Created {$stepCount} steps\n";
            }

            return [
                'success' => true,
                'pipeline_id' => $pipeline->id,
                'pipeline' => $pipeline,
                'step_count' => $stepCount
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create the pipeline bean
     */
    private function createPipeline(string $name, string $slug, $template, array $variables): object
    {
        $member = Bean::load('member', $this->memberId);

        $pipeline = Bean::dispense('pipelines');
        $pipeline->member = $member;
        $pipeline->slug = $slug;
        $pipeline->name = $name;
        $pipeline->description = "Generated from template: {$template->name}";
        $pipeline->columns_json = '[]'; // Will be updated after analyzing steps
        $pipeline->trigger_type = 'manual'; // Studio orchestrations are manual by default
        $pipeline->trigger_config_json = '{}';
        $pipeline->default_context_json = json_encode([
            'template_id' => $template->id,
            'template_slug' => $template->slug,
            'variables' => $variables
        ]);
        $pipeline->is_active = 1;
        $pipeline->run_count = 0;
        $pipeline->created_at = date('Y-m-d H:i:s');
        $pipeline->updated_at = date('Y-m-d H:i:s');

        Bean::store($pipeline);

        return $pipeline;
    }

    /**
     * Generate column names from step rows
     */
    private function generateColumns(array $steps): array
    {
        $maxRow = 0;
        foreach ($steps as $step) {
            $row = $step['row'] ?? 0;
            if ($row > $maxRow) {
                $maxRow = $row;
            }
        }

        // Create columns for each row
        $columns = [];
        for ($i = 0; $i <= $maxRow; $i++) {
            $columns[] = "Stage " . ($i + 1);
        }

        return $columns;
    }

    /**
     * Create pipeline steps from template
     */
    private function createSteps($pipeline, array $templateSteps, array $variables): int
    {
        $count = 0;

        foreach ($templateSteps as $index => $stepTemplate) {
            // Check conditional - skip step if condition not met
            if (isset($stepTemplate['conditional'])) {
                $condition = $this->substituteVariables($stepTemplate['conditional'], $variables);
                if (!$this->evaluateCondition($condition)) {
                    if ($this->verbose) {
                        echo "  Skipping step '{$stepTemplate['name']}' - condition not met\n";
                    }
                    continue;
                }
            }

            $step = $this->createStep($pipeline, $stepTemplate, $variables, $index);
            $count++;

            if ($this->verbose) {
                echo "  Created step: {$step->step_name} (row {$step->row}, col {$step->col})\n";
            }
        }

        return $count;
    }

    /**
     * Create a single pipeline step
     */
    private function createStep($pipeline, array $template, array $variables, int $index): object
    {
        $step = Bean::dispense('pipelinesteps');

        // Basic step info
        $step->pipelines_id = $pipeline->id;
        $step->step_name = $this->generateStepName($template['name'] ?? "step_{$index}");
        $step->label = $template['name'] ?? "Step " . ($index + 1);
        $step->row = $template['row'] ?? 0;
        $step->col = $template['col'] ?? $this->getNextColForRow($pipeline->id, $template['row'] ?? 0);
        $step->step_type = $template['type'] ?? 'direct_exec';

        // Substitute variables in config
        $config = $template['config'] ?? [];
        $config = $this->substituteVariablesInArray($config, $variables);
        $step->config_json = json_encode($config);

        // Input configuration
        $step->input_source = $template['input_source'] ?? 'context';
        $inputConfig = $template['input_config'] ?? [];
        $inputConfig = $this->substituteVariablesInArray($inputConfig, $variables);
        $step->input_config_json = json_encode($inputConfig);

        // Condition (already evaluated for skip, but store for reference)
        $step->condition_json = '{}';

        // Flow control
        $step->on_success = $template['on_success'] ?? 'next_col';
        $step->on_failure = $template['on_failure'] ?? 'exit';
        $step->timeout_seconds = $template['timeout_seconds'] ?? 300;
        $step->retry_count = $template['retry_count'] ?? 0;
        $step->is_active = 1;
        $step->run_parallel = $template['run_parallel'] ?? false;

        $step->created_at = date('Y-m-d H:i:s');
        $step->updated_at = date('Y-m-d H:i:s');

        Bean::store($step);

        return $step;
    }

    /**
     * Get next available column for a row
     */
    private function getNextColForRow(int $pipelineId, int $row): int
    {
        $existing = Bean::find('pipelinesteps',
            'pipelines_id = ? AND row = ?',
            [$pipelineId, $row]
        );

        return count($existing);
    }

    /**
     * Substitute {{variable}} placeholders with values
     */
    private function substituteVariables(string $text, array $variables): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            if (isset($variables[$key])) {
                $value = $variables[$key];
                return is_array($value) ? json_encode($value) : (string) $value;
            }
            return $matches[0]; // Keep original if not found
        }, $text);
    }

    /**
     * Substitute variables in an array recursively
     */
    private function substituteVariablesInArray(array $data, array $variables): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->substituteVariables($value, $variables);
            } elseif (is_array($value)) {
                $result[$key] = $this->substituteVariablesInArray($value, $variables);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Evaluate a simple condition string
     *
     * Supports:
     * - {{var}} == "value"
     * - {{var}} != "value"
     * - {{var}} contains "value"
     * - {{var}} (truthy check)
     */
    private function evaluateCondition(string $condition): bool
    {
        $condition = trim($condition);

        if (empty($condition)) {
            return true;
        }

        // Check for == comparison
        if (preg_match('/^(.+?)\s*==\s*"(.+?)"$/', $condition, $matches)) {
            return trim($matches[1]) === $matches[2];
        }

        // Check for != comparison
        if (preg_match('/^(.+?)\s*!=\s*"(.+?)"$/', $condition, $matches)) {
            return trim($matches[1]) !== $matches[2];
        }

        // Check for contains
        if (preg_match('/^(.+?)\s+contains\s+"(.+?)"$/', $condition, $matches)) {
            return strpos($matches[1], $matches[2]) !== false;
        }

        // Truthy check
        return !empty($condition) && $condition !== 'false' && $condition !== '0';
    }

    /**
     * Generate pipeline name from template and variables
     */
    private function generatePipelineName($template, array $variables): string
    {
        $name = $template->name;

        // Add date for uniqueness
        $date = date('M j');

        // Try to add a meaningful identifier from variables
        if (isset($variables['shopify_store'])) {
            return "{$name} - {$date}";
        }

        return "{$name} - {$date}";
    }

    /**
     * Generate URL-safe slug
     */
    private function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Ensure slug is unique by appending number if needed
     */
    private function ensureUniqueSlug(string $slug): string
    {
        $originalSlug = $slug;
        $counter = 1;

        while (Bean::findOne('pipelines', 'slug = ?', [$slug])) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Generate step name from label
     */
    private function generateStepName(string $label): string
    {
        $name = strtolower($label);
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        $name = trim($name, '_');

        // Ensure it starts with a letter
        if (!preg_match('/^[a-z]/', $name)) {
            $name = 'step_' . $name;
        }

        return $name;
    }

    /**
     * Update a project's generated pipeline
     *
     * @param object $project The studioprojects bean
     * @return array Result with success status
     */
    public function updateProjectPipeline($project): array
    {
        $template = Bean::load('studiotemplates', $project->studiotemplates_id);
        if (!$template->id) {
            return ['success' => false, 'error' => 'Template not found'];
        }

        $variables = json_decode($project->variables_json ?: '{}', true);

        // If project already has a pipeline, update it
        if ($project->pipelines_id) {
            // Delete existing steps
            $existingSteps = Bean::find('pipelinesteps', 'pipelines_id = ?', [$project->pipelines_id]);
            foreach ($existingSteps as $step) {
                Bean::trash($step);
            }

            // Regenerate steps
            $pipeline = Bean::load('pipelines', $project->pipelines_id);
            $pipelineTemplate = json_decode($template->pipeline_template_json ?: '{}', true);
            $stepCount = $this->createSteps($pipeline, $pipelineTemplate['steps'] ?? [], $variables);

            return [
                'success' => true,
                'pipeline_id' => $pipeline->id,
                'step_count' => $stepCount,
                'action' => 'updated'
            ];
        }

        // Generate new pipeline
        $result = $this->generate($template, $variables, $project->name);

        if ($result['success']) {
            $project->pipelines_id = $result['pipeline_id'];
            Bean::store($project);
        }

        return $result;
    }
}
