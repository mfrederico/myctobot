<?php
/**
 * PipelineExecutor Service
 *
 * Executes pipeline runs by processing steps in sequence.
 * Handles step types: ai_agent, direct_exec, script, webhook_out, email_out, parser, wait, harvest, mcp_call
 *
 * Usage:
 *   $executor = new PipelineExecutor($runId, $logger);
 *   $executor->execute();
 */

namespace app\services;

use \app\Bean;
use \RedBeanPHP\R as R;
use \app\services\AnthropicKeyService;

/**
 * Exception thrown when a pipeline pauses for user input in interactive mode
 */
class PipelinePausedException extends \Exception {
    private $stepRunId;
    private $stepOutput;

    public function __construct(int $stepRunId, array $stepOutput = [], string $message = 'Pipeline paused for user input') {
        parent::__construct($message);
        $this->stepRunId = $stepRunId;
        $this->stepOutput = $stepOutput;
    }

    public function getStepRunId(): int {
        return $this->stepRunId;
    }

    public function getStepOutput(): array {
        return $this->stepOutput;
    }
}

class PipelineExecutor {

    private int $runId;
    private $run;
    private $pipeline;
    private $steps = [];
    private array $context = [];
    private array $stepOutputs = [];
    private $logger;

    // Column name mappings
    private array $columnNames = [];      // index => name (e.g., 0 => 'start', 1 => 'execute')
    private array $columnIndices = [];    // name => index (e.g., 'start' => 0, 'execute' => 1)

    // Step lookup for goto
    private array $stepsByPosition = [];  // "row.col" => step
    private array $stepsByName = [];      // "step_name" => step

    // Interactive mode - pause after each step for user mapping
    private bool $interactiveMode = false;
    private ?int $resumeFromStepIndex = null;  // When resuming, start from this step index

    // File storage for run outputs (images, CSVs, binary files, etc.)
    private ?string $runDirectory = null;
    private const RUN_FILES_BASE = '/tmp/pipeline-runs';

    public function __construct(int $runId, $logger = null) {
        $this->runId = $runId;
        $this->logger = $logger;
    }

    /**
     * Get the directory for this run's file outputs
     * Creates the directory if it doesn't exist
     *
     * @return string Absolute path to run directory
     */
    public function getRunDirectory(): string {
        if ($this->runDirectory === null) {
            // Load run to get the UID
            if (!$this->run) {
                $this->run = Bean::load('pipelineruns', $this->runId);
            }

            $runUid = $this->run->run_uid ?? "run-{$this->runId}";
            $this->runDirectory = self::RUN_FILES_BASE . '/' . $runUid;

            // Create directory if it doesn't exist
            if (!is_dir($this->runDirectory)) {
                mkdir($this->runDirectory, 0755, true);
            }
        }

        return $this->runDirectory;
    }

    /**
     * Write content to a file in the run directory
     *
     * @param string $filename Filename (will be placed in run directory)
     * @param string $content File content (can be binary)
     * @return string Full path to the written file
     */
    public function writeRunFile(string $filename, string $content): string {
        $dir = $this->getRunDirectory();
        $filePath = $dir . '/' . $filename;

        // Ensure subdirectories exist if filename contains path
        $fileDir = dirname($filePath);
        if (!is_dir($fileDir)) {
            mkdir($fileDir, 0755, true);
        }

        file_put_contents($filePath, $content);
        return $filePath;
    }

    /**
     * Get list of files in the run directory
     *
     * @return array List of file paths
     */
    public function getRunFiles(): array {
        $dir = $this->getRunDirectory();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Clean up run directory (call after run completes if cleanup is desired)
     *
     * @param bool $force Force cleanup even if files exist
     * @return bool Success
     */
    public function cleanupRunDirectory(bool $force = false): bool {
        $dir = $this->getRunDirectory();
        if (!is_dir($dir)) {
            return true;
        }

        // Remove all files recursively
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        return rmdir($dir);
    }

    /**
     * Check if this run is in interactive mode
     */
    public function isInteractiveMode(): bool {
        return $this->interactiveMode;
    }

    /**
     * Resume a paused interactive run after user submits mappings
     *
     * @param array $mappings User's field mappings: [['source' => 'data.field', 'target' => 'var_name'], ...]
     * @param bool $passthrough If true, merge entire output into context
     * @param bool $saveMappings If true, save mappings to step definition for future runs
     * @return bool Success
     */
    public function resume(array $mappings = [], bool $passthrough = false, bool $saveMappings = false): bool {
        try {
            // Load run and pipeline
            $this->run = Bean::load('pipelineruns', $this->runId);
            if (!$this->run->id) {
                throw new \Exception("Run not found: {$this->runId}");
            }

            // Verify run is in paused state
            if ($this->run->status !== 'paused') {
                throw new \Exception("Run is not paused (status: {$this->run->status})");
            }

            $this->pipeline = Bean::load('pipelines', $this->run->pipelines_id);
            if (!$this->pipeline->id) {
                throw new \Exception("Pipeline not found for run: {$this->runId}");
            }

            // Find the step run that's awaiting input
            $awaitingStepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input = 1 ',
                [$this->run->id]
            );

            if (!$awaitingStepRun) {
                throw new \Exception("No step awaiting input found");
            }

            // Load context from run
            $this->context = json_decode($this->run->context_json ?: '{}', true);

            // Apply user mappings to context
            $stepOutput = json_decode($awaitingStepRun->output_json ?: '{}', true);
            $this->applyUserMappings($stepOutput, $mappings, $passthrough);

            // Store user mappings on the step run record
            $awaitingStepRun->user_mappings_json = json_encode($mappings);
            $awaitingStepRun->passthrough = $passthrough ? 1 : 0;
            $awaitingStepRun->awaiting_input = 0;
            $awaitingStepRun->updated_at = date('Y-m-d H:i:s');
            Bean::store($awaitingStepRun);

            // If save mappings requested, save to step definition for auto-apply in future runs
            if ($saveMappings && count($mappings) > 0) {
                $stepDef = Bean::load('pipelinesteps', $awaitingStepRun->pipelinesteps_id);
                if ($stepDef->id) {
                    $stepDef->output_mappings_json = json_encode($mappings);
                    $stepDef->auto_passthrough = $passthrough ? 1 : 0;
                    $stepDef->updated_at = date('Y-m-d H:i:s');
                    Bean::store($stepDef);
                }
            }

            // Update context on run
            $this->run->context_json = json_encode($this->context);
            $this->run->status = 'running';
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            // Find which step to resume from (next step after the paused one)
            $this->loadStepLookups();
            $stepsArray = array_values($this->steps);

            // Find index of paused step
            $pausedStepIndex = null;
            foreach ($stepsArray as $idx => $step) {
                if ($step->id == $awaitingStepRun->pipelinesteps_id) {
                    $pausedStepIndex = $idx;
                    break;
                }
            }

            if ($pausedStepIndex === null) {
                throw new \Exception("Could not find paused step in step list");
            }

            // Store the paused step's output for reference by other steps
            $pausedStep = $stepsArray[$pausedStepIndex];
            $this->stepOutputs[$pausedStep->step_name] = $stepOutput;

            // Load outputs from all completed steps before the paused one
            $this->loadCompletedStepOutputs();

            // Resume from next step
            $this->resumeFromStepIndex = $pausedStepIndex + 1;
            $this->interactiveMode = (bool) $this->run->interactive_mode;

            $this->log('info', "Resuming pipeline from step index {$this->resumeFromStepIndex}");

            // Continue execution
            $this->executeSteps();

            return true;

        } catch (PipelinePausedException $e) {
            // Another step needs user input - this is expected in interactive mode
            $this->log('info', "Pipeline paused again at step run " . $e->getStepRunId());
            return true;

        } catch (\Exception $e) {
            $this->log('error', "Resume failed: " . $e->getMessage());
            $this->completeRun('failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Apply user mappings to context
     *
     * @param array $stepOutput The step's output data
     * @param array $mappings Array of ['source' => 'path.to.field', 'target' => 'variable_name']
     * @param bool $passthrough If true, merge entire output into context
     */
    private function applyUserMappings(array $stepOutput, array $mappings, bool $passthrough): void {
        // Passthrough: merge entire output into context
        if ($passthrough) {
            $this->context = array_merge($this->context, $stepOutput);
            $this->log('info', "Applied passthrough - merged " . count($stepOutput) . " keys into context");
        }

        // Apply individual mappings
        foreach ($mappings as $mapping) {
            $source = $mapping['source'] ?? '';
            $target = $mapping['target'] ?? '';

            if (empty($source) || empty($target)) {
                continue;
            }

            // Get value from step output using dot notation
            $value = $this->getNestedValue($stepOutput, $source);

            if ($value !== null) {
                // Set in context (supports dot notation for nested targets)
                $this->setNestedValue($this->context, $target, $value);
                $this->log('info', "Mapped {$source} => {$target}");
            }
        }
    }

    /**
     * Set a nested value in an array using dot notation
     */
    private function setNestedValue(array &$data, string $path, $value): void {
        $keys = explode('.', $path);
        $current = &$data;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
    }

    /**
     * Check if pipeline should pause after a step (interactive mode)
     */
    private function shouldPauseAfterStep($step): bool {
        if (!$this->interactiveMode) {
            return false;
        }

        // Check if step has saved mappings that should auto-apply
        $stepDef = Bean::load('pipelinesteps', $step->id);
        if ($stepDef->output_mappings_json) {
            $savedMappings = json_decode($stepDef->output_mappings_json, true);
            if (!empty($savedMappings)) {
                // Has saved mappings - auto-apply them instead of pausing
                return false;
            }
        }

        // Pause for user input
        return true;
    }

    /**
     * Pause the pipeline for user input
     *
     * @throws PipelinePausedException
     */
    private function pauseForInput($step, $stepRun, array $stepOutput): void {
        $this->log('info', "Pausing for user input at step: {$step->step_name}");

        // Mark step run as awaiting input
        $stepRun->awaiting_input = 1;
        $stepRun->updated_at = date('Y-m-d H:i:s');
        Bean::store($stepRun);

        // Mark run as paused
        $this->run->status = 'paused';
        $this->run->context_json = json_encode($this->context);
        $this->run->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->run);

        throw new PipelinePausedException($stepRun->id, $stepOutput);
    }

    /**
     * Auto-apply saved mappings from step definition
     */
    private function autoApplySavedMappings($step, array $stepOutput): void {
        $stepDef = Bean::load('pipelinesteps', $step->id);
        if (!$stepDef->output_mappings_json) {
            return;
        }

        $savedMappings = json_decode($stepDef->output_mappings_json, true);
        if (empty($savedMappings)) {
            return;
        }

        $passthrough = (bool) $stepDef->auto_passthrough;

        $this->log('info', "Auto-applying saved mappings for step: {$step->step_name}");
        $this->applyUserMappings($stepOutput, $savedMappings, $passthrough);
    }

    /**
     * Load step lookups (for resume)
     */
    private function loadStepLookups(): void {
        // Load column names from pipeline
        $columns = json_decode($this->pipeline->columns_json ?: '[]', true);
        foreach ($columns as $index => $name) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
            $this->columnNames[$index] = $slug;
            $this->columnIndices[$slug] = $index;
        }

        // Load steps ordered by sequence
        $this->steps = Bean::find('pipelinesteps',
            ' pipelines_id = ? AND is_active = 1 ORDER BY sequence ASC ',
            [$this->pipeline->id]
        );

        // Build step lookup maps
        foreach ($this->steps as $step) {
            $row = (int) $step->row;
            $col = (int) $step->col;
            $this->stepsByPosition["{$row}.{$col}"] = $step;
            $this->stepsByName[$step->step_name] = $step;
        }
    }

    /**
     * Load outputs from completed step runs (for resume)
     */
    private function loadCompletedStepOutputs(): void {
        $completedStepRuns = Bean::find('pipelinestepruns',
            ' pipelineruns_id = ? AND status = ? ORDER BY id ASC ',
            [$this->run->id, 'success']
        );

        foreach ($completedStepRuns as $sr) {
            $output = json_decode($sr->output_json ?: '{}', true);
            $this->stepOutputs[$sr->step_name] = $output;
        }
    }

    /**
     * Execute the pipeline run
     */
    public function execute(): bool {
        try {
            // Load run and pipeline
            $this->run = Bean::load('pipelineruns', $this->runId);
            if (!$this->run->id) {
                throw new \Exception("Run not found: {$this->runId}");
            }

            $this->pipeline = Bean::load('pipelines', $this->run->pipelines_id);
            if (!$this->pipeline->id) {
                throw new \Exception("Pipeline not found for run: {$this->runId}");
            }

            // Check if already completed or cancelled
            if (in_array($this->run->status, ['completed', 'failed', 'cancelled'])) {
                $this->log('info', "Run already in terminal state: {$this->run->status}");
                return false;
            }

            // Check for interactive mode
            $this->interactiveMode = (bool) ($this->run->interactive_mode ?? false);
            if ($this->interactiveMode) {
                $this->log('info', "Running in interactive mode");
            }

            // Load step lookups (column names, steps, etc.)
            $this->loadStepLookups();

            if (empty($this->steps)) {
                $this->completeRun('completed', 'No active steps to execute');
                return true;
            }

            // Initialize context from pipeline defaults and trigger data
            $defaultContext = json_decode($this->pipeline->default_context_json ?: '{}', true);
            $triggerData = json_decode($this->run->trigger_data_json ?: '{}', true);
            $this->context = array_merge($defaultContext, $triggerData);

            // Add built-in context variables
            $this->context['run_id'] = $this->run->id;
            $this->context['run_uid'] = $this->run->run_uid;
            $this->context['run_directory'] = $this->getRunDirectory();
            $this->context['pipeline_name'] = $this->pipeline->name;
            $this->context['pipeline_slug'] = $this->pipeline->slug;
            $this->context['started_at'] = date('Y-m-d H:i:s');
            $this->context['trigger_source'] = $this->run->trigger_source;

            // Update run status
            $this->run->status = 'running';
            $this->run->started_at = date('Y-m-d H:i:s');
            $this->run->steps_total = count($this->steps);
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            // Create step run records
            $this->initializeStepRuns();

            // Execute steps
            $this->executeSteps();

            return true;

        } catch (PipelinePausedException $e) {
            // Pipeline paused for user input - this is expected in interactive mode
            $this->log('info', "Pipeline paused at step run " . $e->getStepRunId());
            return true;

        } catch (\Exception $e) {
            $this->log('error', "Pipeline execution failed: " . $e->getMessage());
            $this->completeRun('failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize step run records for all steps
     */
    private function initializeStepRuns(): void {
        foreach ($this->steps as $step) {
            $stepRun = Bean::dispense('pipelinestepruns');
            $stepRun->pipelineruns = $this->run;
            $stepRun->pipelinesteps = $step;
            $stepRun->step_name = $step->step_name;
            $stepRun->row = $step->row;
            $stepRun->col = $step->col;
            $stepRun->status = 'pending';
            $stepRun->attempt_number = 1;
            $stepRun->fault_count = 0;
            $stepRun->input_json = '{}';
            $stepRun->output_json = '{}';
            $stepRun->created_at = date('Y-m-d H:i:s');
            $stepRun->updated_at = date('Y-m-d H:i:s');
            Bean::store($stepRun);
        }
    }

    /**
     * Execute steps with parallel row support and goto flow control
     *
     * Supported flow actions for on_success/on_failure:
     *   - next_col: Move to next column in same row (default for success)
     *   - next_row: Move to first column of next row (triggers parallel if target is parallel)
     *   - exit: Complete/fail the pipeline (or just the current parallel row if in parallel mode)
     *   - skip: Skip this failure and continue (only for on_failure)
     *   - ignore: Do nothing on this outcome, continue to next step
     *   - goto:ROW.COLUMN: Jump to specific row and column (e.g., goto:2.execute)
     *   - goto:STEP_NAME: Jump to specific step by name (e.g., goto:validate_output)
     *   - handoff:pipeline.step: Hand off to another pipeline and exit
     */
    private function executeSteps(): void {
        // When resuming, start from the resume index
        $startIndex = $this->resumeFromStepIndex ?? 0;

        // Calculate completed count from already-completed steps
        $completedCount = Bean::count('pipelinestepruns',
            ' pipelineruns_id = ? AND status IN (?, ?, ?) ',
            [$this->run->id, 'success', 'skipped', 'failure']
        );

        $maxIterations = count($this->steps) * 3;  // Safety limit
        $iterations = 0;

        $stepsArray = array_values($this->steps);
        $currentIndex = $startIndex;

        $this->log('info', "Starting execution from step index {$currentIndex}, already completed: {$completedCount}");

        while ($currentIndex < count($stepsArray) && $iterations < $maxIterations) {
            $iterations++;
            $step = $stepsArray[$currentIndex];

            // Check if run was cancelled
            $this->run = Bean::load('pipelineruns', $this->runId);
            if ($this->run->status === 'cancelled') {
                $this->log('info', 'Run was cancelled, stopping execution');
                return;
            }

            // Check if this step's row is parallel - if so, run all parallel rows
            if ($step->run_parallel) {
                $this->log('info', "Entering parallel execution at row {$step->row}");
                $completedCount = $this->executeParallelRows($stepsArray, $currentIndex, $completedCount);

                // Find the highest parallel row number that was executed
                $highestParallelRow = (int) $step->row;
                foreach ($stepsArray as $checkStep) {
                    if ($checkStep->run_parallel && (int) $checkStep->row >= (int) $step->row) {
                        $highestParallelRow = max($highestParallelRow, (int) $checkStep->row);
                    }
                }

                // Find next non-parallel step AFTER the highest parallel row
                $foundNonParallel = false;
                for ($i = 0; $i < count($stepsArray); $i++) {
                    $checkStep = $stepsArray[$i];
                    $checkRow = (int) $checkStep->row;

                    // Must be after all parallel rows AND must not be parallel itself
                    if ($checkRow > $highestParallelRow && !$checkStep->run_parallel) {
                        $currentIndex = $i;
                        $foundNonParallel = true;
                        $this->log('info', "Continuing from non-parallel row {$checkRow} (after parallel rows up to {$highestParallelRow})");
                        break;
                    }
                }

                if (!$foundNonParallel) {
                    // No more non-parallel rows, we're done
                    $this->completeRun('completed');
                    return;
                }
                continue;
            }

            // Normal (non-parallel) step execution
            $result = $this->executeSingleStep($step, $stepsArray, $currentIndex, $completedCount);

            if ($result['action'] === 'exit') {
                $this->completeRun($result['status'], $result['message'] ?? null);
                return;
            } elseif ($result['action'] === 'continue') {
                $currentIndex = $result['nextIndex'];
                $completedCount = $result['completedCount'];
            }
        }

        if ($iterations >= $maxIterations) {
            $this->completeRun('failed', 'Maximum iterations exceeded (possible infinite loop)');
            return;
        }

        $this->completeRun('completed');
    }

    /**
     * Execute all consecutive parallel rows starting from the given index
     *
     * A row is considered parallel if ANY step in that row has run_parallel=1.
     * All steps in a parallel row are executed together.
     *
     * @return int Updated completed count
     */
    private function executeParallelRows(array $stepsArray, int $startIndex, int $completedCount): int {
        $startRow = (int) $stepsArray[$startIndex]->row;

        // First pass: identify which rows are parallel (any step with run_parallel=1)
        $parallelRowNumbers = [];
        $stepsByRow = [];

        // Group all steps by row number
        foreach ($stepsArray as $step) {
            $row = (int) $step->row;
            if (!isset($stepsByRow[$row])) {
                $stepsByRow[$row] = [];
            }
            $stepsByRow[$row][] = $step;

            // Mark row as parallel if any step has the flag
            if ($step->run_parallel && $row >= $startRow) {
                $parallelRowNumbers[$row] = true;
            }
        }

        // Collect consecutive parallel rows starting from startRow
        $parallelRows = [];
        $currentRow = $startRow;

        while (isset($parallelRowNumbers[$currentRow])) {
            if (isset($stepsByRow[$currentRow])) {
                $parallelRows[$currentRow] = $stepsByRow[$currentRow];
            }
            $currentRow++;
        }

        $this->log('info', "Found " . count($parallelRows) . " parallel rows to execute");

        // Execute each parallel row sequentially (true parallelism would need pcntl_fork)
        foreach ($parallelRows as $rowNum => $rowSteps) {
            $this->log('info', "Executing parallel row {$rowNum} with " . count($rowSteps) . " steps");
            $completedCount = $this->executeRowSteps($rowSteps, $completedCount);
        }

        return $completedCount;
    }

    /**
     * Execute steps within a single row until exit or end of row
     *
     * @return int Updated completed count
     */
    private function executeRowSteps(array $rowSteps, int $completedCount): int {
        // Sort by column
        usort($rowSteps, function($a, $b) {
            return (int) $a->col - (int) $b->col;
        });

        $rowStepsArray = array_values($rowSteps);
        $colIndex = 0;
        $maxIterations = count($rowStepsArray) * 2;
        $iterations = 0;

        while ($colIndex < count($rowStepsArray) && $iterations < $maxIterations) {
            $iterations++;
            $step = $rowStepsArray[$colIndex];

            // Check if run was cancelled
            $this->run = Bean::load('pipelineruns', $this->runId);
            if ($this->run->status === 'cancelled') {
                return $completedCount;
            }

            $stepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND pipelinesteps_id = ? ',
                [$this->run->id, $step->id]
            );

            if (!$stepRun) {
                $this->log('error', "Step run not found for step: {$step->step_name}");
                $colIndex++;
                continue;
            }

            // Check condition
            if (!$this->evaluateCondition($step)) {
                $this->log('info', "Step skipped due to condition: {$step->step_name}");
                $stepRun->status = 'skipped';
                $stepRun->completed_at = date('Y-m-d H:i:s');
                $stepRun->updated_at = date('Y-m-d H:i:s');
                Bean::store($stepRun);
                $completedCount++;
                $colIndex++;
                continue;
            }

            // Update current step
            $this->run->current_step_name = $step->step_name;
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            // Execute step
            $success = $this->executeStepWithRetries($step, $stepRun);

            if ($success) {
                $completedCount++;
                $this->updateProgress($completedCount);

                $nextAction = $step->on_success ?: 'next_col';

                if ($nextAction === 'exit') {
                    // Exit just this row, not the entire pipeline
                    $this->log('info', "Row {$step->row} completed via exit");
                    return $completedCount;
                } elseif ($nextAction === 'next_col' || $nextAction === 'ignore') {
                    $colIndex++;
                } elseif (strpos($nextAction, 'goto:') === 0) {
                    // Find target step within this row
                    $target = substr($nextAction, 5);
                    $found = false;
                    foreach ($rowStepsArray as $idx => $s) {
                        if ($s->step_name === $target) {
                            $colIndex = $idx;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $colIndex++;  // Target not in this row, continue
                    }
                } else {
                    // next_row or handoff within parallel row - treat as exit for this row
                    return $completedCount;
                }
            } else {
                // Handle failure
                $failAction = $step->on_failure ?: 'exit';

                if ($failAction === 'exit') {
                    // Exit just this row on failure
                    $this->log('info', "Row {$step->row} failed at step {$step->step_name}");
                    return $completedCount;
                } elseif ($failAction === 'skip' || $failAction === 'ignore') {
                    $completedCount++;
                    $this->updateProgress($completedCount);
                    $colIndex++;
                } else {
                    // goto on failure within row
                    if (strpos($failAction, 'goto:') === 0) {
                        $target = substr($failAction, 5);
                        $found = false;
                        foreach ($rowStepsArray as $idx => $s) {
                            if ($s->step_name === $target) {
                                $colIndex = $idx;
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            return $completedCount;  // Target not found, exit row
                        }
                    } else {
                        return $completedCount;  // Unknown action, exit row
                    }
                }
            }
        }

        return $completedCount;
    }

    /**
     * Execute a single non-parallel step
     *
     * @return array ['action' => 'exit'|'continue', 'status' => ..., 'nextIndex' => ..., 'completedCount' => ...]
     */
    private function executeSingleStep($step, array $stepsArray, int $currentIndex, int $completedCount): array {
        $stepRun = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND pipelinesteps_id = ? ',
            [$this->run->id, $step->id]
        );

        if (!$stepRun) {
            $this->log('error', "Step run not found for step: {$step->step_name}");
            return ['action' => 'continue', 'nextIndex' => $currentIndex + 1, 'completedCount' => $completedCount];
        }

        // Check condition
        if (!$this->evaluateCondition($step)) {
            $this->log('info', "Step skipped due to condition: {$step->step_name}");
            $stepRun->status = 'skipped';
            $stepRun->completed_at = date('Y-m-d H:i:s');
            $stepRun->updated_at = date('Y-m-d H:i:s');
            Bean::store($stepRun);
            return ['action' => 'continue', 'nextIndex' => $currentIndex + 1, 'completedCount' => $completedCount + 1];
        }

        // Update current step
        $this->run->current_step_name = $step->step_name;
        $this->run->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->run);

        // Execute step
        $success = $this->executeStepWithRetries($step, $stepRun);

        if ($success) {
            $completedCount++;
            $this->updateProgress($completedCount);

            $nextAction = $step->on_success ?: 'next_col';
            $nextIndex = $this->resolveNextStep($step, $nextAction, $stepsArray, $currentIndex);

            if ($nextIndex === -1) {
                return ['action' => 'exit', 'status' => 'completed'];
            }
            return ['action' => 'continue', 'nextIndex' => $nextIndex, 'completedCount' => $completedCount];
        } else {
            $failAction = $step->on_failure ?: 'exit';

            if ($failAction === 'exit') {
                return ['action' => 'exit', 'status' => 'failed', 'message' => "Step failed: {$step->step_name}"];
            } elseif ($failAction === 'skip' || $failAction === 'ignore') {
                return ['action' => 'continue', 'nextIndex' => $currentIndex + 1, 'completedCount' => $completedCount + 1];
            } else {
                $nextIndex = $this->resolveNextStep($step, $failAction, $stepsArray, $currentIndex);
                if ($nextIndex === -1) {
                    return ['action' => 'exit', 'status' => 'failed', 'message' => "Step failed: {$step->step_name}"];
                }
                return ['action' => 'continue', 'nextIndex' => $nextIndex, 'completedCount' => $completedCount];
            }
        }
    }

    /**
     * Resolve next step based on flow action
     *
     * @return int Next step index, -1 for exit, or -2 for handoff (handled separately)
     */
    private function resolveNextStep($currentStep, string $action, array $stepsArray, int $currentIndex): int {
        $currentRow = (int) $currentStep->row;
        $currentCol = (int) $currentStep->col;

        switch ($action) {
            case 'exit':
                return -1;

            case 'ignore':
                // No action - just continue to next step
                return $currentIndex + 1;

            case 'next_col':
                // Find next step in sequence (default behavior)
                return $currentIndex + 1;

            case 'next_row':
                // Find first step in next row
                $nextRow = $currentRow + 1;
                foreach ($stepsArray as $idx => $step) {
                    if ((int) $step->row === $nextRow && (int) $step->col === 0) {
                        return $idx;
                    }
                }
                // No step in next row, find any step in next row
                foreach ($stepsArray as $idx => $step) {
                    if ((int) $step->row === $nextRow) {
                        return $idx;
                    }
                }
                // No next row, continue to next in sequence
                return $currentIndex + 1;

            default:
                // Check for goto: prefix
                if (strpos($action, 'goto:') === 0) {
                    $target = substr($action, 5);
                    return $this->resolveGotoTarget($target, $stepsArray);
                }

                // Check for handoff: prefix
                if (strpos($action, 'handoff:') === 0) {
                    $target = substr($action, 8);
                    $this->executeHandoff($target);
                    return -1;  // Exit this pipeline after handoff
                }

                // Unknown action, continue to next
                return $currentIndex + 1;
        }
    }

    /**
     * Resolve goto target to step index
     *
     * Formats:
     *   - "step_name": Find step by name
     *   - "2.execute": Find step at row 2, column "execute"
     *   - "2.1": Find step at row 2, column index 1
     */
    private function resolveGotoTarget(string $target, array $stepsArray): int {
        // Check if it's a direct step name
        if (isset($this->stepsByName[$target])) {
            $step = $this->stepsByName[$target];
            foreach ($stepsArray as $idx => $s) {
                if ($s->id === $step->id) {
                    return $idx;
                }
            }
        }

        // Check for row.column format
        if (strpos($target, '.') !== false) {
            list($rowPart, $colPart) = explode('.', $target, 2);

            $row = (int) $rowPart;

            // Column can be numeric or name
            if (is_numeric($colPart)) {
                $col = (int) $colPart;
            } else {
                // Look up column name
                $colSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $colPart));
                $col = $this->columnIndices[$colSlug] ?? -1;
            }

            if ($col >= 0) {
                // Find step at this position
                $posKey = "{$row}.{$col}";
                if (isset($this->stepsByPosition[$posKey])) {
                    $step = $this->stepsByPosition[$posKey];
                    foreach ($stepsArray as $idx => $s) {
                        if ($s->id === $step->id) {
                            return $idx;
                        }
                    }
                }
            }
        }

        $this->log('warning', "Could not resolve goto target: {$target}");
        return -1;  // Exit if target not found
    }

    /**
     * Execute a step with retry logic
     *
     * @throws PipelinePausedException When in interactive mode and step needs user mapping
     */
    private function executeStepWithRetries($step, $stepRun): bool {
        $maxRetries = $step->retry_count ?? 0;
        $retryDelay = $step->retry_delay_seconds ?? 10;
        $attempt = 1;

        while (true) {
            $stepRun->attempt_number = $attempt;
            $stepRun->status = 'running';
            $stepRun->started_at = date('Y-m-d H:i:s');
            $stepRun->updated_at = date('Y-m-d H:i:s');
            Bean::store($stepRun);

            $this->log('info', "Executing step: {$step->step_name} (attempt {$attempt})");

            // Prepare input
            $input = $this->prepareStepInput($step);
            $stepRun->input_json = json_encode($input);
            Bean::store($stepRun);

            // Execute based on step type
            $startTime = microtime(true);
            $result = $this->executeStep($step, $input);
            $endTime = microtime(true);

            $stepRun->duration_ms = (int) (($endTime - $startTime) * 1000);

            if ($result['success']) {
                // Ensure stepOutput is always an array (some step types may return strings)
                $stepOutput = $result['output'] ?? [];
                if (!is_array($stepOutput)) {
                    $stepOutput = ['raw' => $stepOutput];
                }

                // Always include stdout/stderr in output for variable substitution
                // This allows {step.stdout} and {step.stderr} to work
                if (isset($result['stdout'])) {
                    $stepOutput['stdout'] = $result['stdout'];
                }
                if (isset($result['stderr'])) {
                    $stepOutput['stderr'] = $result['stderr'];
                }
                if (isset($result['exit_code'])) {
                    $stepOutput['exit_code'] = $result['exit_code'];
                }

                $stepRun->status = 'success';
                $stepRun->output_json = json_encode($stepOutput);
                $stepRun->stdout = $result['stdout'] ?? null;
                $stepRun->stderr = $result['stderr'] ?? null;
                $stepRun->exit_code = $result['exit_code'] ?? null;
                $stepRun->completed_at = date('Y-m-d H:i:s');
                $stepRun->updated_at = date('Y-m-d H:i:s');
                Bean::store($stepRun);

                // Store output for reference by other steps
                $this->stepOutputs[$step->step_name] = $stepOutput;

                // Merge output into context if configured
                if (!empty($stepOutput)) {
                    $this->context[$step->step_name] = $stepOutput;
                }

                // Interactive mode: check if we should pause for user mapping
                if ($this->shouldPauseAfterStep($step)) {
                    // Pause for user input - throws PipelinePausedException
                    $this->pauseForInput($step, $stepRun, $stepOutput);
                } else {
                    // Auto-apply saved mappings (works in both normal and interactive mode)
                    $this->autoApplySavedMappings($step, $stepOutput);
                }

                return true;
            } else {
                $stepRun->fault_count = ($stepRun->fault_count ?? 0) + 1;
                $stepRun->error_message = $result['error'] ?? 'Unknown error';
                $stepRun->stderr = $result['stderr'] ?? null;
                $stepRun->exit_code = $result['exit_code'] ?? null;
                $stepRun->updated_at = date('Y-m-d H:i:s');

                $this->log('warning', "Step failed: {$step->step_name} - {$stepRun->error_message}");

                if ($attempt <= $maxRetries) {
                    $attempt++;
                    $stepRun->status = 'fault';
                    Bean::store($stepRun);
                    $this->log('info', "Retrying step {$step->step_name} in {$retryDelay}s...");
                    sleep($retryDelay);
                } else {
                    $stepRun->status = 'failure';
                    $stepRun->completed_at = date('Y-m-d H:i:s');
                    Bean::store($stepRun);
                    return false;
                }
            }
        }
    }

    /**
     * Execute a single step based on its type
     */
    private function executeStep($step, array $input): array {
        $config = json_decode($step->config_json ?: '{}', true);
        $timeout = $step->timeout_seconds ?? 300;

        switch ($step->step_type) {
            case 'direct_exec':
                return $this->executeDirectExec($config, $input, $timeout);

            case 'script':
                return $this->executeScript($config, $input, $timeout);

            case 'ai_agent':
                return $this->executeAIAgent($config, $input, $timeout);

            case 'webhook_out':
                return $this->executeWebhookOut($config, $input, $timeout);

            case 'email_out':
                return $this->executeEmailOut($config, $input);

            case 'parser':
                return $this->executeParser($config, $input);

            case 'wait':
                return $this->executeWait($config, $input);

            case 'harvest':
                return $this->executeHarvest($config, $input);

            case 'mcp_call':
                return $this->executeMcpCall($config, $input, $timeout);

            case 'schedule_task':
                return $this->executeScheduleTask($config, $input);

            case 'shopify_graphql':
                return $this->executeShopifyGraphql($config, $input, $timeout);

            case 'mailgun':
                return $this->executeMailgun($config, $input);

            case 'file_write':
                return $this->executeFileWrite($config, $input);

            default:
                return [
                    'success' => false,
                    'error' => "Unknown step type: {$step->step_type}"
                ];
        }
    }

    /**
     * Execute a direct command
     *
     * Config options:
     *   command      - The command/code to execute
     *   executor     - How to run it (default: /bin/bash -c). Examples:
     *                  - "/bin/bash -c" (shell command)
     *                  - "/usr/bin/python -c" (python code)
     *                  - "/usr/bin/php -r" (php code)
     *                  - "node -e" (node code)
     *                  - "ssh user@host" (remote execution)
     *                  - "" (empty = run command directly as executable)
     *   workstation_id - If set, builds SSH executor from workstation entity
     *   working_dir  - Working directory (default: /tmp)
     */
    private function executeDirectExec(array $config, array $input, int $timeout): array {
        $command = $config['command'] ?? '';
        // Use !empty() to handle both null and empty strings
        $workingDir = !empty($config['working_dir']) ? $config['working_dir'] : '/tmp';
        $executor = !empty($config['executor']) ? $config['executor'] : '/bin/bash -c';
        $workstationId = $config['workstation_id'] ?? null;

        if (empty($command)) {
            return ['success' => false, 'error' => 'No command specified'];
        }

        // If workstation specified, build SSH executor from runners table
        if (!empty($workstationId)) {
            $workstation = Bean::load('runners', $workstationId);
            if ($workstation->id && $workstation->ssh_user && $workstation->host) {
                $sshPort = $workstation->ssh_port ?: 22;
                if ($sshPort != 22) {
                    $executor = "ssh -p {$sshPort} {$workstation->ssh_user}@{$workstation->host}";
                } else {
                    $executor = "ssh {$workstation->ssh_user}@{$workstation->host}";
                }
            }
        }

        // Substitute variables in command
        $command = $this->substituteVariables($command);

        // Pre-flight checks for better error messages
        $executorBinary = null;
        $executorParts = [];
        $commandParts = [];

        if (!empty($executor)) {
            $executorParts = preg_split('/\s+/', trim($executor));
            $executorBinary = $executorParts[0];
        } else {
            $commandParts = preg_split('/\s+/', trim($command));
            $executorBinary = $commandParts[0];
        }

        // Check if executor/command binary exists (only for local execution, not SSH)
        $isRemoteExecution = str_starts_with($executorBinary, 'ssh');
        if (!$isRemoteExecution) {
            if (!file_exists($executorBinary)) {
                // Try to find it in PATH using 'which'
                $whichResult = trim(shell_exec("which " . escapeshellarg($executorBinary) . " 2>/dev/null") ?: '');
                if (empty($whichResult)) {
                    return [
                        'success' => false,
                        'error' => "Executor binary not found: '{$executorBinary}'. " .
                                   "The file does not exist and is not in PATH. " .
                                   "Full executor config: '{$executor}'. " .
                                   "Command: '{$command}'. " .
                                   "Working dir: '{$workingDir}'."
                    ];
                }
            }
        }

        // Check if working directory exists
        if (!is_dir($workingDir)) {
            return [
                'success' => false,
                'error' => "Working directory does not exist: '{$workingDir}'. " .
                           "Executor: '{$executor}'. Command: '{$command}'."
            ];
        }

        // Build the execution
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Clear any previous errors to get accurate error info
        error_clear_last();

        // Execute: use executor prefix if configured, otherwise run command directly
        if (!empty($executor)) {
            // Parse executor into array parts (e.g., "/bin/bash -c" → ['/bin/bash', '-c'])
            $executorParts[] = $command;
            $process = @proc_open($executorParts, $descriptors, $pipes, $workingDir, null);
        } else {
            // Direct execution - split command into parts
            $process = @proc_open($commandParts, $descriptors, $pipes, $workingDir, null);
        }

        if (!is_resource($process)) {
            $lastError = error_get_last();
            $errorMsg = $lastError['message'] ?? 'Unknown error';

            // Build detailed diagnostic info
            $diagnostics = [
                'php_error' => $errorMsg,
                'executor' => $executor,
                'executor_binary' => $executorBinary,
                'executor_exists' => file_exists($executorBinary) ? 'yes' : 'no',
                'executor_executable' => is_executable($executorBinary) ? 'yes' : 'no',
                'working_dir' => $workingDir,
                'working_dir_exists' => is_dir($workingDir) ? 'yes' : 'no',
                'command' => $command,
                'full_exec_array' => !empty($executor) ? $executorParts : $commandParts
            ];

            $this->log('error', "proc_open failed", $diagnostics);

            return [
                'success' => false,
                'error' => "Failed to start process: {$errorMsg}. " .
                           "Executor: '{$executor}' (exists: " . ($diagnostics['executor_exists']) . ", " .
                           "executable: " . ($diagnostics['executor_executable']) . "). " .
                           "Working dir: '{$workingDir}' (exists: " . ($diagnostics['working_dir_exists']) . "). " .
                           "Command: '{$command}'.",
                'diagnostics' => $diagnostics
            ];
        }

        // Write stdin if provided
        if (!empty($input['stdin'])) {
            fwrite($pipes[0], $input['stdin']);
        }
        fclose($pipes[0]);

        // Set non-blocking on stdout/stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            // timeout=0 means no timeout (wait forever)
            if ($timeout > 0 && (time() - $startTime) > $timeout) {
                proc_terminate($process);
                return [
                    'success' => false,
                    'error' => "Command timed out after {$timeout}s",
                    'stdout' => $stdout,
                    'stderr' => $stderr
                ];
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            usleep(100000); // 100ms
        }

        // Get remaining output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Try to parse stdout as JSON
        $output = [];
        $trimmedStdout = trim($stdout);
        if (!empty($trimmedStdout) && $trimmedStdout[0] === '{') {
            $decoded = json_decode($trimmedStdout, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $output = $decoded;
            }
        }
        $output['stdout'] = $stdout;
        $output['exit_code'] = $exitCode;

        return [
            'success' => $exitCode === 0,
            'output' => $output,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode
        ];
    }

    /**
     * Execute a script from a repository
     */
    private function executeScript(array $config, array $input, int $timeout): array {
        $repoId = $config['repo_id'] ?? null;
        $scriptPath = $config['script_path'] ?? '';
        $args = $config['args'] ?? '';

        if (empty($repoId) || empty($scriptPath)) {
            return ['success' => false, 'error' => 'Repository and script path required'];
        }

        // Load repo
        $repo = Bean::load('repoconnections', $repoId);
        if (!$repo->id) {
            return ['success' => false, 'error' => 'Repository not found'];
        }

        // For now, execute script directly if it exists locally
        // TODO: Clone/pull repo first if needed
        $fullPath = $scriptPath;
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => "Script not found: {$scriptPath}"];
        }

        $command = "bash " . escapeshellarg($fullPath);
        if (!empty($args)) {
            $command .= ' ' . $this->substituteVariables($args);
        }

        return $this->executeDirectExec(['command' => $command, 'working_dir' => dirname($fullPath)], $input, $timeout);
    }

    /**
     * Execute an AI agent step
     *
     * Config options:
     *   agent_id       - Required: ID from aiagents table
     *   prompt         - The prompt to send (supports variable substitution)
     *   system_prompt  - Optional system prompt override
     *   model          - Optional model override (default: claude-sonnet-4-20250514)
     *   max_tokens     - Optional max tokens (default: 4096)
     *   include_input  - Whether to include step input in the prompt (default: true)
     */
    private function executeAIAgent(array $config, array $input, int $timeout): array {
        $agentId = $config['agent_id'] ?? null;
        $prompt = $config['prompt'] ?? '';
        $systemPrompt = $config['system_prompt'] ?? 'You are a helpful assistant processing pipeline data. Analyze the input and respond with structured JSON when appropriate.';
        $model = $config['model'] ?? 'claude-sonnet-4-20250514';
        $maxTokens = (int)($config['max_tokens'] ?? 4096);
        $includeInput = $config['include_input'] ?? true;

        if (empty($agentId)) {
            return ['success' => false, 'error' => 'Agent ID required'];
        }

        // Load agent
        $agent = Bean::load('aiagents', $agentId);
        if (!$agent->id) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        // Get API key
        $memberId = $this->run->member_id ?? 1;
        $apiKey = AnthropicKeyService::getApiKeyForAgent($agent, $memberId);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'No Anthropic API key available'];
        }

        // Substitute variables in prompts
        $prompt = $this->substituteVariables($prompt);
        $systemPrompt = $this->substituteVariables($systemPrompt);

        // Build user message with input data if requested
        $userMessage = $prompt;
        if ($includeInput && !empty($input)) {
            $inputJson = json_encode($input, JSON_PRETTY_PRINT);
            $userMessage = "{$prompt}\n\n## Input Data\n```json\n{$inputJson}\n```";
        }

        $this->log('info', "Executing AI agent: {$agent->name}", [
            'model' => $model,
            'prompt_length' => strlen($userMessage)
        ]);

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.anthropic.com',
                'timeout' => $timeout,
            ]);

            $response = $client->post('/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage]
                    ]
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $content = $body['content'][0]['text'] ?? '';

            // Try to parse response as JSON
            $parsed = null;
            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $content, $matches)) {
                $parsed = json_decode($matches[1], true);
            } elseif (str_starts_with(trim($content), '{') || str_starts_with(trim($content), '[')) {
                $parsed = json_decode($content, true);
            }

            $this->log('info', "AI agent completed", [
                'response_length' => strlen($content),
                'has_json' => $parsed !== null
            ]);

            return [
                'success' => true,
                'output' => [
                    'agent_id' => $agentId,
                    'agent_name' => $agent->name,
                    'model' => $model,
                    'response' => $content,
                    'parsed' => $parsed,
                    'usage' => $body['usage'] ?? null
                ]
            ];

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $errorMessage = $errorBody['error']['message'] ?? $errorMessage;
            }
            $this->log('error', "AI agent failed: {$errorMessage}");
            return ['success' => false, 'error' => "AI agent error: {$errorMessage}"];

        } catch (\Exception $e) {
            $this->log('error', "AI agent exception: {$e->getMessage()}");
            return ['success' => false, 'error' => "AI agent exception: {$e->getMessage()}"];
        }
    }

    /**
     * Execute an outbound webhook
     */
    private function executeWebhookOut(array $config, array $input, int $timeout): array {
        $url = $config['url'] ?? '';
        $method = $config['method'] ?? 'POST';
        $headers = $config['headers'] ?? [];
        $bodyTemplate = $config['body'] ?? '';

        if (empty($url)) {
            return ['success' => false, 'error' => 'URL required'];
        }

        // Substitute variables
        $url = $this->substituteVariables($url);
        $body = $this->substituteVariables($bodyTemplate);

        // Build headers
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $this->substituteVariables($value);
        }

        // Make request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => "CURL error: {$error}"
            ];
        }

        $success = $httpCode >= 200 && $httpCode < 300;

        // Try to parse response as JSON
        $output = ['http_code' => $httpCode, 'body' => $response];
        if (!empty($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $output['data'] = $decoded;
            }
        }

        return [
            'success' => $success,
            'output' => $output,
            'error' => $success ? null : "HTTP {$httpCode}"
        ];
    }

    /**
     * Execute an email step via Mailgun
     *
     * Config options:
     *   to           - Recipient email (required, supports variable substitution)
     *   cc           - CC recipients (optional, comma-separated)
     *   subject      - Email subject (required, supports variable substitution)
     *   body         - Email body in markdown (supports variable substitution)
     *   template     - Named template to use instead of body (optional)
     *   from_email   - Override sender email (optional)
     *   from_name    - Override sender name (optional)
     */
    private function executeEmailOut(array $config, array $input): array {
        require_once __DIR__ . '/MailgunService.php';

        $to = $config['to'] ?? '';
        $cc = $config['cc'] ?? null;
        $subject = $config['subject'] ?? '';
        $body = $config['body'] ?? '';
        $template = $config['template'] ?? null;
        $fromEmail = $config['from_email'] ?? null;
        $fromName = $config['from_name'] ?? null;

        if (empty($to)) {
            return ['success' => false, 'error' => 'Recipient (to) is required'];
        }

        if (empty($subject)) {
            return ['success' => false, 'error' => 'Subject is required'];
        }

        // Variable substitution
        $to = $this->substituteVariables($to);
        $cc = $cc ? $this->substituteVariables($cc) : null;
        $subject = $this->substituteVariables($subject);
        $body = $this->substituteVariables($body);

        // If using a template, load it
        if ($template && empty($body)) {
            $body = $this->loadEmailTemplate($template, $input);
        }

        // Build body from input if still empty
        if (empty($body)) {
            // Default body: pretty-print the input data
            $body = "# Pipeline Notification\n\n";
            $body .= "**Pipeline:** " . ($this->pipeline->name ?? 'Unknown') . "\n";
            $body .= "**Run ID:** " . $this->runId . "\n\n";
            $body .= "## Data\n\n```json\n" . json_encode($input, JSON_PRETTY_PRINT) . "\n```";
        }

        try {
            $mailgun = new MailgunService();

            if (!$mailgun->isEnabled()) {
                return [
                    'success' => false,
                    'error' => 'Mailgun is not configured. Please configure it in Settings > Connections.'
                ];
            }

            $result = $mailgun->sendMarkdownEmail($subject, $body, $to, $cc);

            if ($result) {
                $this->log('info', "Email sent successfully to {$to}", ['subject' => $subject]);
                return [
                    'success' => true,
                    'output' => [
                        'sent' => true,
                        'to' => $to,
                        'cc' => $cc,
                        'subject' => $subject
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to send email via Mailgun'
                ];
            }

        } catch (\Exception $e) {
            $this->log('error', "Email send failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => "Email exception: " . $e->getMessage()
            ];
        }
    }

    /**
     * Load an email template by name
     */
    private function loadEmailTemplate(string $templateName, array $data): string {
        // Templates can be stored in enterprisesettings or as files
        $setting = \app\Bean::findOne('enterprisesettings', 'setting_key = ?', ["email_template_{$templateName}"]);

        if ($setting && !empty($setting->setting_value)) {
            return $this->substituteVariables($setting->setting_value);
        }

        // Try loading from file
        $templatePath = dirname(__DIR__) . "/templates/email/{$templateName}.md";
        if (file_exists($templatePath)) {
            $template = file_get_contents($templatePath);
            return $this->substituteVariables($template);
        }

        // Return a default template
        return "# Notification\n\nThis is an automated notification from MyCTOBot.\n\n---\n*Pipeline: " . ($this->pipeline->name ?? 'Unknown') . "*";
    }

    /**
     * Execute a parser (jq, php, regex)
     */
    private function executeParser(array $config, array $input): array {
        $parserType = $config['parser_type'] ?? 'jq';
        $expression = $config['expression'] ?? '';

        if (empty($expression)) {
            return ['success' => false, 'error' => 'Expression required'];
        }

        // If input has 'stdin' key, use that as the raw data to parse (it's already a string)
        // Otherwise, encode the full input array as JSON
        if (isset($input['stdin'])) {
            // stdin contains the raw output from the previous step - use it directly
            $inputData = trim($input['stdin']);
        } else {
            $inputData = json_encode($input);
        }

        switch ($parserType) {
            case 'jq':
                // Use jq command with proper stderr capture
                $descriptors = [
                    0 => ['pipe', 'r'],  // stdin
                    1 => ['pipe', 'w'],  // stdout
                    2 => ['pipe', 'w'],  // stderr
                ];

                $process = proc_open(['jq', $expression], $descriptors, $pipes, '/tmp', null);

                if (!is_resource($process)) {
                    return ['success' => false, 'error' => 'Failed to execute jq'];
                }

                // Write input to stdin
                fwrite($pipes[0], $inputData);
                fclose($pipes[0]);

                // Read output
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);

                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);

                $exitCode = proc_close($process);

                // Log stderr if present
                if (!empty(trim($stderr))) {
                    $this->log('warning', "jq stderr: " . trim($stderr));
                }

                // Check for errors
                if ($exitCode !== 0) {
                    return [
                        'success' => false,
                        'error' => trim($stderr) ?: "jq exited with code {$exitCode}",
                        'stdout' => trim($stdout),
                        'stderr' => trim($stderr),
                        'exit_code' => $exitCode
                    ];
                }

                $decoded = json_decode(trim($stdout), true);
                return [
                    'success' => true,
                    'output' => $decoded ?? ['raw' => trim($stdout)],
                    'stdout' => trim($stdout),
                    'stderr' => trim($stderr)
                ];

            case 'regex':
                // PHP regex
                if (preg_match($expression, $inputData, $matches)) {
                    return [
                        'success' => true,
                        'output' => ['matches' => $matches]
                    ];
                }
                return ['success' => false, 'error' => 'No regex match'];

            default:
                return ['success' => false, 'error' => "Unknown parser type: {$parserType}"];
        }
    }

    /**
     * Execute a wait step
     */
    private function executeWait(array $config, array $input): array {
        $waitType = $config['wait_type'] ?? 'delay';
        $duration = $config['duration'] ?? 60;

        switch ($waitType) {
            case 'delay':
                $this->log('info', "Waiting for {$duration} seconds...");
                sleep($duration);
                return ['success' => true, 'output' => ['waited_seconds' => $duration]];

            case 'approval':
                // TODO: Implement manual approval flow
                return ['success' => true, 'output' => ['approval' => 'auto-approved']];

            case 'webhook':
                // TODO: Implement webhook wait
                return ['success' => true, 'output' => ['webhook' => 'not-implemented']];

            default:
                return ['success' => false, 'error' => "Unknown wait type: {$waitType}"];
        }
    }

    /**
     * Execute a schedule_task step - creates a non-blocking scheduled task
     *
     * Unlike the 'wait' step which blocks with sleep(), this creates a database
     * record that will be processed by cron-scheduled-tasks.php, allowing the
     * pipeline to complete immediately.
     *
     * Config options:
     *   task_type        - Type of task: 'revert_action', 'execute_pipeline', 'webhook_call', 'api_call'
     *   delay_seconds    - Delay in seconds (static)
     *   delay_expression - Expression like "{context.duration_hours} * 3600" (evaluated)
     *   payload          - Task-specific data to store
     *   revert_data      - Data needed to revert actions (optional)
     */
    private function executeScheduleTask(array $config, array $input): array {
        $taskType = $config['task_type'] ?? 'revert_action';
        $delaySeconds = $config['delay_seconds'] ?? 0;
        $delayExpression = $config['delay_expression'] ?? null;

        // Evaluate delay expression if provided
        if ($delayExpression && $delaySeconds === 0) {
            $delaySeconds = $this->evaluateDelayExpression($delayExpression);
        }

        if ($delaySeconds <= 0) {
            return ['success' => false, 'error' => 'Invalid delay: must be positive'];
        }

        $scheduledAt = date('Y-m-d H:i:s', time() + $delaySeconds);

        try {
            // Create scheduled task record
            $task = Bean::dispense('scheduledtasks');
            $task->studioprojects_id = $this->context['_studio_project_id'] ?? null;
            $task->pipelineruns_id = $this->runId;
            $task->task_type = $taskType;
            $task->scheduled_at = $scheduledAt;
            $task->status = 'pending';
            $task->payload_json = json_encode($config['payload'] ?? []);

            // Store revert data - either from config or from input
            $revertData = $config['revert_data'] ?? $input['_revert_state'] ?? [];
            $task->revert_data_json = json_encode($revertData);

            $task->retry_count = 0;
            $task->max_retries = $config['max_retries'] ?? 3;
            $task->created_at = date('Y-m-d H:i:s');
            $task->updated_at = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->log('info', "Scheduled task created: type={$taskType}, scheduled_at={$scheduledAt}, task_id={$task->id}");

            return [
                'success' => true,
                'output' => [
                    'task_id' => $task->id,
                    'task_type' => $taskType,
                    'scheduled_at' => $scheduledAt,
                    'delay_seconds' => $delaySeconds
                ]
            ];

        } catch (\Exception $e) {
            $this->log('error', "Failed to create scheduled task: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Evaluate a delay expression like "{context.duration_hours} * 3600"
     */
    private function evaluateDelayExpression(string $expression): int {
        // Substitute context variables
        $substituted = $this->substituteVariables($expression);

        // Simple evaluation - only allow numbers and basic math operators
        $cleaned = preg_replace('/[^0-9+\-*\/\.\s]/', '', $substituted);

        if (empty($cleaned)) {
            return 0;
        }

        // Use eval safely - expression is sanitized to only numbers and operators
        try {
            $result = eval("return (int)($cleaned);");
            return max(0, (int) $result);
        } catch (\Throwable $e) {
            $this->log('warning', "Failed to evaluate delay expression: {$expression}");
            return 0;
        }
    }

    /**
     * Prepare input for a step based on input_source configuration
     */
    private function prepareStepInput($step): array {
        $inputSource = $step->input_source ?? 'context';
        $inputConfig = json_decode($step->input_config_json ?: '{}', true);

        switch ($inputSource) {
            case 'context':
                return $this->context;

            case 'stdin':
                // Get stdout from previous step
                $prevOutput = end($this->stepOutputs) ?: [];
                return ['stdin' => $prevOutput['stdout'] ?? json_encode($prevOutput)];

            case 'getfrom':
                $stepName = $inputConfig['step'] ?? '';
                if (empty($stepName)) {
                    return $this->context;
                }
                return $this->stepOutputs[$stepName] ?? [];

            default:
                return $this->context;
        }
    }

    /**
     * Evaluate step condition
     */
    private function evaluateCondition($step): bool {
        $conditionJson = $step->condition_json ?? '{}';
        $condition = json_decode($conditionJson, true);

        if (empty($condition) || empty($condition['check'])) {
            return true; // No condition, always execute
        }

        // Simple condition evaluation
        // Format: "step_name.output.key == value" or "step_name.success == true"
        $check = $condition['check'];

        // Replace step references with actual values
        foreach ($this->stepOutputs as $stepName => $output) {
            $check = str_replace("{$stepName}.output", json_encode($output), $check);
            $check = str_replace("{$stepName}.success", 'true', $check);
        }

        // Very basic evaluation - just check for "true" or "false" strings
        // TODO: Implement proper expression evaluation
        $check = strtolower(trim($check));
        return $check !== 'false' && $check !== '0' && $check !== '';
    }

    /**
     * Substitute variables in a string
     * Supports: {context.key}, {step_name.output.key}, {step_name.stdout}
     */
    private function substituteVariables(string $template): string {
        // Replace context variables {context.xxx}
        $template = preg_replace_callback('/\{context\.([^}]+)\}/', function($matches) {
            $key = $matches[1];
            return $this->getNestedValue($this->context, $key) ?? '';
        }, $template);

        // Replace step output variables {step_name.xxx}
        $template = preg_replace_callback('/\{([a-z_][a-z0-9_]*)\.([^}]+)\}/', function($matches) {
            $stepName = $matches[1];
            $path = $matches[2];

            if (!isset($this->stepOutputs[$stepName])) {
                return '';
            }

            return $this->getNestedValue($this->stepOutputs[$stepName], $path) ?? '';
        }, $template);

        // Replace simple variables {xxx} - look up directly in context
        // This handles mapped variables like {shopify_store} without requiring context. prefix
        $template = preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/', function($matches) {
            $key = $matches[1];
            // First check if it's a direct context key
            if (isset($this->context[$key])) {
                $value = $this->context[$key];
                return is_array($value) ? json_encode($value) : $value;
            }
            return '';
        }, $template);

        return $template;
    }

    /**
     * Get nested value from array using dot notation
     */
    private function getNestedValue(array $data, string $path) {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Update run progress
     */
    private function updateProgress(int $completedCount): void {
        $this->run->steps_completed = $completedCount;
        $total = $this->run->steps_total ?: 1;
        $this->run->progress_percent = (int) (($completedCount / $total) * 100);
        $this->run->context_json = json_encode($this->context);
        $this->run->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->run);
    }

    /**
     * Complete the run
     */
    private function completeRun(string $status, ?string $errorMessage = null): void {
        $this->run->status = $status;
        $this->run->error_message = $errorMessage;
        $this->run->completed_at = date('Y-m-d H:i:s');
        $this->run->current_step_name = null;
        $this->run->context_json = json_encode($this->context);
        $this->run->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->run);

        $this->log('info', "Run completed with status: {$status}");
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        // Always include run_id in context
        $context['run_id'] = $this->runId;

        if ($this->logger) {
            $this->logger->$level($message, $context);
        } else {
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            error_log("[Pipeline:{$this->runId}] [{$level}] {$message}{$contextStr}");
        }
    }

    /**
     * Execute a handoff to another pipeline
     *
     * Handoff ends the current pipeline and starts another pipeline
     * at a specific entry point. The context is passed to the new pipeline.
     *
     * @param string $target Format: "pipeline-slug.entry_point" or "pipeline-slug"
     */
    private function executeHandoff(string $target): void {
        $parts = explode('.', $target, 2);
        $pipelineSlug = $parts[0];
        $entryPoint = $parts[1] ?? null;

        $this->log('info', "Executing handoff to pipeline: {$pipelineSlug}" . ($entryPoint ? " at entry: {$entryPoint}" : ""));

        // Find target pipeline
        $targetPipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
        if (!$targetPipeline || !$targetPipeline->id) {
            $this->log('error', "Handoff target pipeline not found: {$pipelineSlug}");
            $this->completeRun('failed', "Handoff failed: pipeline '{$pipelineSlug}' not found");
            return;
        }

        if (!$targetPipeline->is_active) {
            $this->log('error', "Handoff target pipeline not active: {$pipelineSlug}");
            $this->completeRun('failed', "Handoff failed: pipeline '{$pipelineSlug}' is not active");
            return;
        }

        // Determine starting step if entry point specified
        $startingStepId = null;
        if ($entryPoint) {
            // Try to find step by name
            $startingStep = Bean::findOne('pipelinesteps',
                ' pipelines_id = ? AND step_name = ? AND is_active = 1 ',
                [$targetPipeline->id, $entryPoint]
            );

            // If not found by name, try row name from pipeline's row_names_json
            if (!$startingStep) {
                $rowNames = json_decode($targetPipeline->row_names_json ?: '{}', true);
                $rowIndex = array_search($entryPoint, $rowNames);
                if ($rowIndex !== false) {
                    // Find first step in that row
                    $startingStep = Bean::findOne('pipelinesteps',
                        ' pipelines_id = ? AND row = ? AND is_active = 1 ORDER BY col ASC ',
                        [$targetPipeline->id, $rowIndex]
                    );
                }
            }

            if ($startingStep) {
                $startingStepId = $startingStep->id;
            } else {
                $this->log('warning', "Entry point not found: {$entryPoint}, starting from beginning");
            }
        }

        // Count active steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$targetPipeline->id]);

        // Create new run for target pipeline
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $newRun = Bean::dispense('pipelineruns');
        $newRun->run_uid = $runUid;
        $newRun->pipelines = $targetPipeline;
        $newRun->member_id = $this->run->member_id;
        $newRun->trigger_source = 'handoff:' . $this->pipeline->slug;
        $newRun->trigger_data_json = json_encode([
            'handoff_from' => [
                'pipeline' => $this->pipeline->slug,
                'run_id' => $this->run->id,
                'run_uid' => $this->run->run_uid
            ],
            'entry_point' => $entryPoint,
            'starting_step_id' => $startingStepId
        ]);
        $newRun->status = 'pending';
        $newRun->context_json = json_encode($this->context); // Pass current context
        $newRun->steps_total = $stepCount;
        $newRun->steps_completed = 0;
        $newRun->progress_percent = 0;
        $newRun->created_at = date('Y-m-d H:i:s');
        $newRun->updated_at = date('Y-m-d H:i:s');

        $newRunId = Bean::store($newRun);

        // Update target pipeline stats
        $targetPipeline->run_count = ($targetPipeline->run_count ?? 0) + 1;
        $targetPipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($targetPipeline);

        $this->log('info', "Created handoff run: {$runUid} (ID: {$newRunId})");

        // Mark current run as handed off (store the target run ID for UI navigation)
        $this->run->status = 'completed';
        $this->run->error_message = "Handed off to pipeline: {$pipelineSlug}";
        $this->run->handoff_run_id = $newRunId;  // Store target run for UI link
        $this->run->completed_at = date('Y-m-d H:i:s');
        $this->run->context_json = json_encode($this->context);
        $this->run->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->run);

        // Execute the new pipeline (fire and forget style - in same process for now)
        // In production, this could spawn a background job
        $executor = new PipelineExecutor($newRunId, $this->logger);
        $executor->execute();
    }

    /**
     * Execute a harvest step - gather results from parallel rows
     */
    private function executeHarvest(array $config, array $input): array {
        $policy = $config['policy'] ?? 'all_required';
        $onIncomplete = $config['on_incomplete'] ?? 'fail';
        $template = $config['template'] ?? '';

        $this->log('info', "Executing harvest with policy: {$policy}");

        // Collect all step outputs from parallel rows
        // In a full parallel implementation, we'd wait for spawned processes here
        // For now, collect what we have in stepOutputs
        $harvested = [];
        $succeeded = 0;
        $failed = 0;
        $total = 0;

        foreach ($this->stepOutputs as $stepName => $output) {
            $total++;
            $status = isset($output['error']) ? 'failed' : 'success';
            if ($status === 'success') {
                $succeeded++;
            } else {
                $failed++;
            }
            $harvested[$stepName] = [
                'status' => $status,
                'output' => $output
            ];
        }

        // Determine if harvest passed based on policy
        $passed = false;
        switch ($policy) {
            case 'all_required':
                $passed = ($failed === 0);
                break;
            case 'any_success':
                $passed = ($succeeded > 0);
                break;
            case 'best_effort':
                $passed = true;
                break;
        }

        // Build harvest metadata
        $harvestMeta = [
            'policy' => $policy,
            'passed' => $passed,
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed
        ];

        // Build output
        $output = array_merge(['_harvest' => $harvestMeta], $harvested);

        // Apply template if provided (must be valid jq expression)
        if (!empty($template)) {
            // Skip invalid templates (jq syntax, not Jinja/Twig)
            if (strpos($template, '{{') !== false || strpos($template, '}}') !== false) {
                $this->log('warning', "Harvest template appears to be Jinja/Twig syntax, not jq. Skipping template.");
            } else {
                // Use jq to transform the output with timeout
                $tempInput = json_encode($output);
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];

                // Use timeout command to prevent hanging (5 second timeout)
                $process = proc_open(['timeout', '5', 'jq', $template], $descriptors, $pipes, '/tmp', null);
                if (is_resource($process)) {
                    // Set non-blocking on output pipes to prevent deadlock
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);

                    fwrite($pipes[0], $tempInput);
                    fclose($pipes[0]);

                    // Read with timeout
                    $stdout = '';
                    $stderr = '';
                    $startTime = time();
                    while (time() - $startTime < 6) {
                        $stdout .= stream_get_contents($pipes[1]);
                        $stderr .= stream_get_contents($pipes[2]);

                        $status = proc_get_status($process);
                        if (!$status['running']) {
                            break;
                        }
                        usleep(10000); // 10ms
                    }

                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $exitCode = proc_close($process);

                    if ($exitCode === 0) {
                        $templated = json_decode(trim($stdout), true);
                        if ($templated !== null) {
                            $output = array_merge(['_harvest' => $harvestMeta], $templated);
                        }
                    } elseif ($exitCode === 124) {
                        $this->log('warning', "Harvest template jq timed out after 5 seconds");
                    } else {
                        $this->log('warning', "Harvest template jq failed (exit {$exitCode}): " . trim($stderr));
                    }
                }
            }
        }

        // Handle incomplete case
        if (!$passed && $onIncomplete === 'fail') {
            return [
                'success' => false,
                'error' => "Harvest failed: {$failed} of {$total} steps failed (policy: {$policy})",
                'output' => $output
            ];
        }

        return [
            'success' => $passed || $onIncomplete !== 'fail',
            'output' => $output
        ];
    }

    /**
     * Execute an MCP tool call step
     *
     * Connects to an MCP server and calls a tool, allowing pipelines
     * to consume tools from FastMCP or other MCP-compliant servers.
     *
     * Config options:
     *   transport        - 'stdio' or 'http' (default: stdio)
     *   command          - For stdio: command to start MCP server
     *   url              - For http: URL of MCP server
     *   mcp_server_id    - Load config from mcpservers table (alternative to command/url)
     *   tool             - Name of the tool to call (required)
     *   arguments        - Tool arguments (supports variable substitution)
     *   working_dir      - Working directory for stdio (default: /tmp)
     *   env              - Environment variables for stdio
     *   list_tools_only  - If true, just list available tools and return
     */
    private function executeMcpCall(array $config, array $input, int $timeout): array {
        require_once __DIR__ . '/McpClientService.php';

        $transport = $config['transport'] ?? 'stdio';
        $command = $config['command'] ?? '';
        $url = $config['url'] ?? '';
        $mcpServerId = $config['mcp_server_id'] ?? null;
        $toolName = $config['tool'] ?? '';
        $arguments = $config['arguments'] ?? [];
        $workingDir = $config['working_dir'] ?? '/tmp';
        $env = $config['env'] ?? null;
        $listToolsOnly = $config['list_tools_only'] ?? false;

        // If mcp_server_id is provided, load config from database
        if (!empty($mcpServerId)) {
            $serverConfig = Bean::load('mcpservers', $mcpServerId);
            if ($serverConfig && $serverConfig->id) {
                $transport = $serverConfig->server_type ?: 'stdio';

                // Build command with args for stdio
                if ($transport === 'stdio') {
                    $command = $serverConfig->command ?: '';
                    $args = json_decode($serverConfig->args_json ?: '[]', true);
                    if (!empty($args)) {
                        $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
                    }
                }

                $url = $serverConfig->url ?: '';
                $workingDir = '/tmp';

                // Parse environment variables
                if (!empty($serverConfig->env_json)) {
                    $env = json_decode($serverConfig->env_json, true);
                }

                // Parse headers for http/sse
                if (in_array($transport, ['http', 'sse']) && !empty($serverConfig->headers_json)) {
                    $config['headers'] = json_decode($serverConfig->headers_json, true);
                }
            } else {
                return ['success' => false, 'error' => "MCP server not found: {$mcpServerId}"];
            }
        }

        // Validate configuration
        if ($transport === 'stdio' && empty($command)) {
            return ['success' => false, 'error' => 'Command required for stdio transport'];
        }
        if (in_array($transport, ['http', 'sse']) && empty($url)) {
            return ['success' => false, 'error' => 'URL required for ' . $transport . ' transport'];
        }
        if (!$listToolsOnly && empty($toolName)) {
            return ['success' => false, 'error' => 'Tool name required'];
        }

        // Substitute variables in arguments
        $processedArgs = [];
        foreach ($arguments as $key => $value) {
            if (is_string($value)) {
                $processedArgs[$key] = $this->substituteVariables($value);
            } else {
                $processedArgs[$key] = $value;
            }
        }

        // Also substitute variables in command if present
        $command = $this->substituteVariables($command);
        $url = $this->substituteVariables($url);

        $this->log('info', "Connecting to MCP server", [
            'transport' => $transport,
            'command' => $command,
            'url' => $url
        ]);

        $client = new McpClientService($this->logger);

        try {
            // Build connection config
            $connectConfig = [];
            if ($transport === 'stdio') {
                $connectConfig = [
                    'command' => $command,
                    'working_dir' => $workingDir,
                    'env' => $env
                ];
            } else {
                $connectConfig = [
                    'url' => $url,
                    'headers' => $config['headers'] ?? []
                ];
            }

            // Connect
            if (!$client->connect($transport, $connectConfig)) {
                return ['success' => false, 'error' => 'Failed to connect to MCP server'];
            }

            $serverInfo = $client->getServerInfo();
            $tools = $client->listTools();

            $this->log('info', "Connected to MCP server", [
                'server' => $serverInfo['name'] ?? 'unknown',
                'tools_count' => count($tools)
            ]);

            // If just listing tools, return the list
            if ($listToolsOnly) {
                $client->disconnect();
                return [
                    'success' => true,
                    'output' => [
                        'server_info' => $serverInfo,
                        'tools' => $tools
                    ]
                ];
            }

            // Call the tool
            $result = $client->callTool($toolName, $processedArgs);

            $client->disconnect();

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Tool call failed',
                    'output' => $result
                ];
            }

            // Try to parse result text as JSON
            $parsed = null;
            $text = $result['text'] ?? '';
            if (!empty($text)) {
                // Try to extract JSON from markdown code blocks
                if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $matches)) {
                    $parsed = json_decode($matches[1], true);
                } elseif (str_starts_with(trim($text), '{') || str_starts_with(trim($text), '[')) {
                    $parsed = json_decode($text, true);
                }
            }

            return [
                'success' => true,
                'output' => [
                    'tool' => $toolName,
                    'server_info' => $serverInfo,
                    'content' => $result['content'] ?? [],
                    'text' => $text,
                    'parsed' => $parsed,
                    'isError' => $result['isError'] ?? false
                ],
                'stdout' => $text
            ];

        } catch (\Exception $e) {
            $this->log('error', "MCP call failed: " . $e->getMessage());
            $client->disconnect();
            return [
                'success' => false,
                'error' => "MCP exception: " . $e->getMessage()
            ];
        }
    }

    /**
     * Execute a Shopify GraphQL query
     *
     * Config options:
     *   connection_id  - Shopify connection ID from shopifyconnections table (required)
     *   query          - GraphQL query string (required)
     *   variables      - Variables for the query (optional, object/array)
     *
     * Example config:
     *   {
     *     "connection_id": "{context.shop_id}",
     *     "query": "query getProducts($first: Int!) { products(first: $first) { edges { node { id title } } } }",
     *     "variables": { "first": 10 }
     *   }
     */
    private function executeShopifyGraphql(array $config, array $input, int $timeout): array {
        require_once __DIR__ . '/ShopifyClient.php';

        $connectionId = $config['connection_id'] ?? null;
        $query = $config['query'] ?? '';
        $variables = $config['variables'] ?? [];

        // Substitute variables in connection_id (might come from context)
        if (is_string($connectionId)) {
            $connectionId = $this->substituteVariables($connectionId);
            $connectionId = is_numeric($connectionId) ? (int)$connectionId : null;
        }

        if (empty($connectionId)) {
            return ['success' => false, 'error' => 'Shopify connection_id is required'];
        }

        if (empty($query)) {
            return ['success' => false, 'error' => 'GraphQL query is required'];
        }

        // Substitute variables in query (allows dynamic queries)
        $query = $this->substituteVariables($query);

        // Substitute variables in GraphQL variables (allows context values)
        $processedVariables = [];
        foreach ($variables as $key => $value) {
            if (is_string($value)) {
                $substituted = $this->substituteVariables($value);
                // Try to convert to appropriate type
                if (is_numeric($substituted) && !str_contains($substituted, '.')) {
                    $processedVariables[$key] = (int)$substituted;
                } elseif (is_numeric($substituted)) {
                    $processedVariables[$key] = (float)$substituted;
                } elseif ($substituted === 'true') {
                    $processedVariables[$key] = true;
                } elseif ($substituted === 'false') {
                    $processedVariables[$key] = false;
                } else {
                    $processedVariables[$key] = $substituted;
                }
            } else {
                $processedVariables[$key] = $value;
            }
        }

        $this->log('info', "Executing Shopify GraphQL", [
            'connection_id' => $connectionId,
            'query_length' => strlen($query),
            'variables' => array_keys($processedVariables)
        ]);

        try {
            $client = new ShopifyClient($connectionId);

            if (!$client->isConnected()) {
                return [
                    'success' => false,
                    'error' => "Shopify connection {$connectionId} not found or invalid"
                ];
            }

            $result = $client->graphql($query, $processedVariables);

            $this->log('info', "Shopify GraphQL completed", [
                'success' => $result['success'],
                'has_errors' => !empty($result['errors'])
            ]);

            if (!$result['success']) {
                $errorMessages = [];
                foreach ($result['errors'] as $error) {
                    $errorMessages[] = $error['message'] ?? 'Unknown error';
                }
                return [
                    'success' => false,
                    'error' => 'GraphQL errors: ' . implode('; ', $errorMessages),
                    'output' => [
                        'data' => $result['data'],
                        'errors' => $result['errors'],
                        'extensions' => $result['extensions']
                    ]
                ];
            }

            return [
                'success' => true,
                'output' => [
                    'data' => $result['data'],
                    'extensions' => $result['extensions'],
                    'shop' => $client->getShop()
                ],
                'stdout' => json_encode($result['data'], JSON_PRETTY_PRINT)
            ];

        } catch (\Exception $e) {
            $this->log('error', "Shopify GraphQL failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => "Shopify GraphQL exception: " . $e->getMessage()
            ];
        }
    }

    /**
     * Execute a Mailgun email step
     *
     * Config options:
     *   to           - Recipient email (required). Supports variable substitution.
     *   cc           - CC recipients (optional)
     *   subject      - Email subject (required). Supports variable substitution.
     *   body         - Email body content (required). Supports variable substitution.
     *   content_type - Content type: 'markdown' (default), 'html', or 'text'
     */
    private function executeMailgun(array $config, array $input): array {
        $to = $this->substituteVariables($config['to'] ?? '', $input);
        $cc = $this->substituteVariables($config['cc'] ?? '', $input);
        $subject = $this->substituteVariables($config['subject'] ?? '', $input);
        $body = $this->substituteVariables($config['body'] ?? '', $input);
        $contentType = !empty($config['content_type']) ? $config['content_type'] : 'markdown';

        // Parse explicit attachments field
        $attachments = $this->parseMailgunAttachments($config['attachments'] ?? '', $input);

        // Also extract inline @/path/to/file references from body and add as attachments
        $body = $this->extractInlineAttachments($body, $attachments);

        if (empty($to)) {
            return [
                'success' => false,
                'error' => 'Mailgun: No recipient (to) specified'
            ];
        }

        if (empty($subject)) {
            return [
                'success' => false,
                'error' => 'Mailgun: No subject specified'
            ];
        }

        if (empty($body)) {
            return [
                'success' => false,
                'error' => 'Mailgun: No body content specified'
            ];
        }

        $this->log('info', "Sending email via Mailgun", [
            'to' => $to,
            'cc' => $cc ?: '(none)',
            'subject' => $subject,
            'content_type' => $contentType,
            'attachments' => count($attachments) . ' file(s)'
        ]);

        try {
            require_once __DIR__ . '/MailgunService.php';
            $mailgun = new MailgunService();

            if (!$mailgun->isEnabled()) {
                return [
                    'success' => false,
                    'error' => 'Mailgun is not configured. Please configure it in workspace settings.'
                ];
            }

            $success = false;
            $ccArg = !empty($cc) ? $cc : null;

            if ($contentType === 'markdown') {
                $success = $mailgun->sendMarkdownEmail($subject, $body, $to, $ccArg, $attachments);
            } elseif ($contentType === 'html') {
                $success = $mailgun->send($to, $subject, $body, strip_tags($body), $ccArg, $attachments);
            } else {
                // Plain text - wrap in basic HTML
                $htmlBody = '<pre style="font-family: sans-serif; white-space: pre-wrap;">' . htmlspecialchars($body) . '</pre>';
                $success = $mailgun->send($to, $subject, $htmlBody, $body, $ccArg, $attachments);
            }

            if ($success) {
                $this->log('info', "Email sent successfully", ['to' => $to, 'attachments' => $attachments]);
                return [
                    'success' => true,
                    'output' => [
                        'sent' => true,
                        'to' => $to,
                        'cc' => $cc,
                        'subject' => $subject,
                        'attachments' => array_map('basename', $attachments)
                    ],
                    'stdout' => "Email sent to {$to}" . (count($attachments) ? " with " . count($attachments) . " attachment(s)" : "")
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Mailgun: Failed to send email'
                ];
            }

        } catch (\Exception $e) {
            $this->log('error', "Mailgun failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => "Mailgun exception: " . $e->getMessage()
            ];
        }
    }

    /**
     * Parse Mailgun attachments config into array of file paths
     *
     * Supports:
     *   @{step_name.file}     - Reference to file from a file_write step
     *   @{context.path}       - Path from context variable
     *   @/absolute/path.pdf   - Absolute file path
     *
     * @param string $attachmentsConfig Raw attachments config (one per line)
     * @param array $input Input context for variable substitution
     * @return array Array of resolved, existing file paths
     */
    private function parseMailgunAttachments(string $attachmentsConfig, array $input): array {
        if (empty(trim($attachmentsConfig))) {
            return [];
        }

        $attachments = [];
        $lines = preg_split('/[\r\n]+/', $attachmentsConfig);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Remove @ prefix if present
            if (str_starts_with($line, '@')) {
                $line = substr($line, 1);
            }

            // Substitute variables
            $filePath = $this->substituteVariables($line, $input);

            // Validate file exists
            if (!empty($filePath) && file_exists($filePath) && is_file($filePath)) {
                $attachments[] = $filePath;
                $this->log('debug', "Mailgun attachment resolved", ['path' => $filePath]);
            } else {
                $this->log('warning', "Mailgun attachment not found", ['path' => $filePath, 'original' => $line]);
            }
        }

        return $attachments;
    }

    /**
     * Extract inline @/path/to/file references from text and add to attachments
     *
     * Scans text for patterns like @/tmp/file.txt or @/path/to/file.pdf,
     * adds valid files to the attachments array, and removes them from the text.
     *
     * @param string $text The text to scan (e.g., email body)
     * @param array &$attachments Reference to attachments array to add to
     * @return string The text with @/path references removed
     */
    private function extractInlineAttachments(string $text, array &$attachments): string {
        // Match @/path/to/file patterns (absolute paths starting with /)
        // Supports paths with alphanumeric, dash, underscore, dot, and directory separators
        $pattern = '/@(\/[a-zA-Z0-9._\/-]+\.[a-zA-Z0-9]+)/';

        $text = preg_replace_callback($pattern, function ($matches) use (&$attachments) {
            $filePath = $matches[1];

            if (file_exists($filePath) && is_file($filePath)) {
                $attachments[] = $filePath;
                $this->log('debug', "Inline attachment extracted from body", ['path' => $filePath]);
                // Remove the @/path from the text (replace with empty or filename)
                return ''; // Remove completely
            }

            // File doesn't exist, leave the text as-is but log warning
            $this->log('warning', "Inline attachment path not found", ['path' => $filePath]);
            return $matches[0]; // Keep original text
        }, $text);

        // Clean up any resulting double newlines or trailing whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Execute a file write step
     *
     * Writes content to a file in the run directory.
     *
     * Config options:
     *   filename      - Output filename (supports variable substitution)
     *   source        - Content source: 'template', 'stdin', 'step_output', 'base64'
     *   content       - Template content (for source='template')
     *   source_step   - Step name to get content from (for source='step_output')
     *   source_field  - Field path within step output (for source='step_output')
     *   base64_var    - Variable containing base64 content (for source='base64')
     *   content_type  - MIME type (optional)
     *   append        - Append to file instead of overwrite (default: false)
     */
    private function executeFileWrite(array $config, array $input): array {
        $filename = $this->substituteVariables($config['filename'] ?? 'output.txt');
        $source = $config['source'] ?? 'template';
        $append = (bool) ($config['append'] ?? false);
        $contentType = $config['content_type'] ?? '';

        // Determine content based on source type
        $content = '';

        switch ($source) {
            case 'template':
                // Template with variable substitution
                $template = $config['content'] ?? '';
                $content = $this->substituteVariables($template);
                break;

            case 'stdin':
                // Use stdin from input - check multiple sources:
                // 1. Explicit stdin if set
                // 2. Previous step's stdout (last step in stepOutputs)
                $content = $input['stdin'] ?? null;

                if ($content === null && !empty($this->stepOutputs)) {
                    // Get the most recently completed step's stdout
                    $lastStepName = array_key_last($this->stepOutputs);
                    $lastStep = $this->stepOutputs[$lastStepName];
                    $content = $lastStep['stdout'] ?? ($lastStep['raw'] ?? '');
                    $this->log('debug', "file_write stdin: using {$lastStepName}.stdout");
                }

                $content = $content ?? '';
                if (is_array($content)) {
                    $content = json_encode($content, JSON_PRETTY_PRINT);
                }
                break;

            case 'step_output':
                // Get content from specific step output
                $sourceStep = $config['source_step'] ?? '';
                $sourceField = $config['source_field'] ?? 'stdout';

                if (empty($sourceStep)) {
                    return ['success' => false, 'error' => 'Source step name required for step_output mode'];
                }

                if (!isset($this->stepOutputs[$sourceStep])) {
                    return ['success' => false, 'error' => "Step output not found: {$sourceStep}"];
                }

                $stepData = $this->stepOutputs[$sourceStep];
                $content = $this->getNestedValue($stepData, $sourceField) ?? '';

                if (is_array($content)) {
                    $content = json_encode($content, JSON_PRETTY_PRINT);
                }
                break;

            case 'base64':
                // Decode base64 content
                $base64Var = $config['base64_var'] ?? '';
                $base64Content = $this->substituteVariables($base64Var);
                $content = base64_decode($base64Content);

                if ($content === false) {
                    return ['success' => false, 'error' => 'Invalid base64 content'];
                }
                break;

            default:
                return ['success' => false, 'error' => "Unknown content source: {$source}"];
        }

        // Sanitize filename (prevent directory traversal)
        $filename = basename($filename);
        if (empty($filename)) {
            $filename = 'output.txt';
        }

        try {
            // Write to run directory
            $filePath = $this->writeRunFile($filename, $content);

            $this->log('info', "File written: {$filePath}", [
                'filename' => $filename,
                'size' => strlen($content),
                'source' => $source,
                'content_type' => $contentType
            ]);

            return [
                'success' => true,
                'output' => [
                    'file' => $filePath,
                    'filename' => $filename,
                    'size' => strlen($content),
                    'content_type' => $contentType ?: $this->guessContentType($filename)
                ],
                'stdout' => $filePath
            ];

        } catch (\Exception $e) {
            $this->log('error', "File write failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => "File write failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Guess content type from filename extension
     */
    private function guessContentType(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $types = [
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
        ];

        return $types[$ext] ?? 'application/octet-stream';
    }
}
