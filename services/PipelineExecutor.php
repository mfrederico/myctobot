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
use \app\services\LLMProviders\OllamaProvider;
use \app\services\TmuxService;

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
    private ?array $previousStepOutput = null;  // For {prev.output} substitution
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

    // Debug/step-by-step mode - pause after EVERY step regardless of mappings
    private bool $debugMode = false;

    // Single-step execution mode - execute only one step then pause
    private bool $singleStepMode = false;

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
     * Enable debug/step-by-step mode (pause after EVERY step)
     */
    public function setDebugMode(bool $enabled = true): self {
        $this->debugMode = $enabled;
        return $this;
    }

    /**
     * Check if debug mode is enabled
     */
    public function isDebugMode(): bool {
        return $this->debugMode;
    }

    /**
     * Enable single-step mode (execute exactly one step then pause)
     */
    public function setSingleStepMode(bool $enabled = true): self {
        $this->singleStepMode = $enabled;
        return $this;
    }

    /**
     * Atomically claim a step run for execution using InnoDB row locking
     *
     * Prevents race conditions where multiple processes try to execute the same step.
     * Uses SELECT ... FOR UPDATE to lock the row during the claim check.
     *
     * @param int $stepRunId The pipelinesteprun ID to claim
     * @param array $validStatuses Statuses that are valid for claiming (default: pending, awaiting_input)
     * @param string $newStatus Status to set when claimed (default: running)
     * @return array ['claimed' => bool, 'step_run' => array|null, 'reason' => string|null]
     */
    public function claimStepForExecution(int $stepRunId, array $validStatuses = ['pending', 'awaiting_input'], string $newStatus = 'running'): array {
        try {
            Bean::begin();

            // Lock the row - other transactions will wait
            $placeholders = implode(',', array_fill(0, count($validStatuses), '?'));
            $params = array_merge([$stepRunId], $validStatuses);

            $row = Bean::getRow(
                "SELECT * FROM pipelinestepruns
                 WHERE id = ? AND status IN ({$placeholders})
                 FOR UPDATE",
                $params
            );

            if (!$row) {
                Bean::rollback();
                return [
                    'claimed' => false,
                    'step_run' => null,
                    'reason' => 'Step not found or not in valid status for claiming'
                ];
            }

            // Claim it by updating status
            Bean::exec(
                "UPDATE pipelinestepruns SET status = ?, started_at = ? WHERE id = ?",
                [$newStatus, date('Y-m-d H:i:s'), $stepRunId]
            );

            Bean::commit();

            return [
                'claimed' => true,
                'step_run' => $row,
                'reason' => null
            ];

        } catch (\Exception $e) {
            Bean::rollback();
            $this->log('error', "Failed to claim step run {$stepRunId}: " . $e->getMessage());
            return [
                'claimed' => false,
                'step_run' => null,
                'reason' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Claim an awaiting_input step for timeout processing
     *
     * Similar to claimStepForExecution but specifically checks timeout_at.
     *
     * @param int $stepRunId The pipelinesteprun ID to claim
     * @return array ['claimed' => bool, 'step_run' => array|null, 'reason' => string|null]
     */
    public function claimStepForTimeout(int $stepRunId): array {
        try {
            Bean::begin();

            // Lock the row and check it's awaiting_input AND past timeout
            $row = Bean::getRow(
                "SELECT * FROM pipelinestepruns
                 WHERE id = ?
                   AND status = 'awaiting_input'
                   AND awaiting_input_timeout_at IS NOT NULL
                   AND awaiting_input_timeout_at < NOW()
                 FOR UPDATE",
                [$stepRunId]
            );

            if (!$row) {
                Bean::rollback();
                return [
                    'claimed' => false,
                    'step_run' => null,
                    'reason' => 'Step not found, not awaiting input, or not yet timed out'
                ];
            }

            // Claim it - set to 'timed_out' status so we can distinguish from user input
            Bean::exec(
                "UPDATE pipelinestepruns SET status = 'timed_out', updated_at = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $stepRunId]
            );

            Bean::commit();

            return [
                'claimed' => true,
                'step_run' => $row,
                'reason' => null
            ];

        } catch (\Exception $e) {
            Bean::rollback();
            $this->log('error', "Failed to claim step run {$stepRunId} for timeout: " . $e->getMessage());
            return [
                'claimed' => false,
                'step_run' => null,
                'reason' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Resume a pipeline that's awaiting external input
     *
     * Called when input arrives from any source (MCP, webhook, form, email).
     * Validates the input against the expected schema and resumes execution.
     *
     * @param array $input The input data from the external source
     * @param string $inputToken The token that was provided when pausing
     * @param string $source The source of the input (mcp, webhook, form, email)
     * @return array Result with success status and output
     */
    public function resumeFromAwaitInput(array $input, string $inputToken, string $source = 'api'): array {
        try {
            // Load run
            $this->run = Bean::load('pipelineruns', $this->runId);
            if (!$this->run->id) {
                return ['success' => false, 'error' => "Run not found: {$this->runId}"];
            }

            // Verify run is in awaiting_input status
            if ($this->run->status !== 'awaiting_input') {
                return [
                    'success' => false,
                    'error' => "Run is not awaiting input (status: {$this->run->status})"
                ];
            }

            // Find the step run that's awaiting input
            $awaitingStepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input = 1 ',
                [$this->run->id]
            );

            if (!$awaitingStepRun) {
                return ['success' => false, 'error' => 'No step found awaiting input'];
            }

            // Validate input token
            if ($awaitingStepRun->awaiting_input_token !== $inputToken) {
                return ['success' => false, 'error' => 'Invalid input token'];
            }

            // For timeout source, skip the timeout check (cron already verified it)
            // and the step has already been claimed by claimStepForTimeout
            $isTimeout = ($source === 'timeout' || !empty($input['_timed_out']));

            if (!$isTimeout) {
                // Check timeout for user input
                if ($awaitingStepRun->awaiting_input_timeout_at &&
                    strtotime($awaitingStepRun->awaiting_input_timeout_at) < time()) {
                    return ['success' => false, 'error' => 'Input window has expired'];
                }

                // Validate source is allowed
                $allowedSources = json_decode($awaitingStepRun->awaiting_input_sources_json ?: '[]', true);
                if (!empty($allowedSources) && !in_array($source, $allowedSources)) {
                    return ['success' => false, 'error' => "Input source '{$source}' is not allowed"];
                }

                // For user input, atomically claim the step to prevent race with timeout cron
                $claim = $this->claimStepForExecution($awaitingStepRun->id, ['awaiting_input'], 'running');
                if (!$claim['claimed']) {
                    return ['success' => false, 'error' => 'Step already being processed (possibly timed out)'];
                }
            }

            // TODO: Validate input against schema
            // $schema = json_decode($awaitingStepRun->awaiting_input_schema_json, true);
            // $this->validateInputAgainstSchema($input, $schema);

            // Load pipeline
            $this->pipeline = Bean::load('pipelines', $this->run->pipelines_id);
            if (!$this->pipeline->id) {
                return ['success' => false, 'error' => 'Pipeline not found'];
            }

            // Restore context
            $this->context = json_decode($this->run->context_json ?: '{}', true);

            // Inject the input into context
            $this->context['_input'] = $input;
            $this->context['_input_source'] = $source;
            $this->context['_input_received_at'] = date('Y-m-d H:i:s');

            // Get the step that was waiting
            $step = Bean::load('pipelinesteps', $awaitingStepRun->pipelinesteps_id);

            // Store input as the step's output (so next steps can reference it)
            $stepOutput = [
                'output' => $input,
                'input_source' => $source,
                'input_received_at' => date('Y-m-d H:i:s'),
                'timed_out' => $isTimeout
            ];
            $this->stepOutputs[$step->step_name] = $stepOutput;
            $this->context[$step->step_name] = $stepOutput;

            // Clear awaiting state on step run
            $awaitingStepRun->awaiting_input = 0;
            $awaitingStepRun->status = $isTimeout ? 'failed' : 'success';
            $awaitingStepRun->output_json = json_encode($stepOutput);
            $awaitingStepRun->completed_at = date('Y-m-d H:i:s');
            $awaitingStepRun->updated_at = date('Y-m-d H:i:s');
            if (!$isTimeout) {
                // Only store if not already claimed by claimStepForTimeout
                Bean::store($awaitingStepRun);
            } else {
                // For timeout, just update the output fields (status already set by claimStepForTimeout)
                Bean::exec(
                    "UPDATE pipelinestepruns SET awaiting_input = 0, status = 'failed', output_json = ?, completed_at = ?, updated_at = ? WHERE id = ?",
                    [json_encode($stepOutput), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $awaitingStepRun->id]
                );
            }

            // Update run status to running
            $this->run->status = 'running';
            $this->run->context_json = json_encode($this->context);
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            $this->log('info', "Resuming from await_input at step: {$step->step_name}, source: {$source}, timed_out: " . ($isTimeout ? 'yes' : 'no'));

            // Load step lookups for execution
            $this->loadStepLookups();

            // Find the index of the awaiting step
            $stepsArray = array_values($this->steps);
            $currentStepIndex = 0;
            foreach ($stepsArray as $idx => $s) {
                if ($s->id == $step->id) {
                    $currentStepIndex = $idx;
                    break;
                }
            }

            if ($isTimeout) {
                // For timeout, use on_failure flow
                $onFailure = $step->on_failure ?? 'exit';
                $this->log('info', "Timeout triggered on_failure: {$onFailure}");

                if ($onFailure === 'exit') {
                    // Mark run as failed
                    $this->run->status = 'failed';
                    $this->run->error_message = 'Await input timed out';
                    $this->run->completed_at = date('Y-m-d H:i:s');
                    $this->run->updated_at = date('Y-m-d H:i:s');
                    Bean::store($this->run);
                } elseif (strpos($onFailure, 'goto:') === 0) {
                    // Jump to specified step
                    $targetStepName = substr($onFailure, 5);
                    $targetStep = $this->stepsByName[$targetStepName] ?? null;

                    if ($targetStep) {
                        // Find index of target step
                        foreach ($stepsArray as $idx => $s) {
                            if ($s->step_name === $targetStepName) {
                                $this->executeStepsFromIndex($idx, $stepsArray);
                                break;
                            }
                        }
                    } else {
                        $this->log('error', "on_failure goto target not found: {$targetStepName}");
                        $this->run->status = 'failed';
                        $this->run->error_message = "on_failure goto target not found: {$targetStepName}";
                        Bean::store($this->run);
                    }
                }
                // 'retry' not applicable for await_input timeout
            } else {
                // For user input, continue to next step (on_success flow)
                $resumeIndex = $currentStepIndex + 1;
                if ($resumeIndex < count($stepsArray)) {
                    $this->executeStepsFromIndex($resumeIndex, $stepsArray);
                }
            }

            // Reload run to get final status
            $this->run = Bean::load('pipelineruns', $this->runId);

            return [
                'success' => true,
                'status' => $this->run->status,
                'output' => $this->context,
                'steps_completed' => $this->run->steps_completed,
                'steps_total' => $this->run->steps_total
            ];

        } catch (PipelinePausedException $e) {
            // Pipeline paused again (another await_input step)
            $stepOutput = $e->getStepOutput();
            return [
                'success' => true,
                'status' => 'awaiting_input',
                'awaiting_input' => true,
                'output' => $stepOutput
            ];
        } catch (\Exception $e) {
            $this->log('error', "Error resuming from await input: " . $e->getMessage());

            // Mark run as failed
            if ($this->run) {
                $this->run->status = 'failed';
                $this->run->error_message = $e->getMessage();
                $this->run->completed_at = date('Y-m-d H:i:s');
                $this->run->updated_at = date('Y-m-d H:i:s');
                Bean::store($this->run);
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute steps starting from a given index
     * Helper for resume functionality
     */
    private function executeStepsFromIndex(int $startIndex, array $stepsArray): void {
        for ($i = $startIndex; $i < count($stepsArray); $i++) {
            $step = $stepsArray[$i];

            $stepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND pipelinesteps_id = ? ',
                [$this->run->id, $step->id]
            );

            if (!$stepRun) {
                // Create step run if it doesn't exist
                $stepRun = Bean::dispense('pipelinestepruns');
                $stepRun->pipelineruns_id = $this->run->id;
                $stepRun->pipelinesteps_id = $step->id;
                $stepRun->row = $step->row;
                $stepRun->col = $step->col;
                $stepRun->status = 'pending';
                $stepRun->created_at = date('Y-m-d H:i:s');
                Bean::store($stepRun);
            }

            if ($stepRun->status === 'success' || $stepRun->status === 'skipped') {
                continue; // Already completed
            }

            // If step was previously failed/completed but we got here via goto, reset it
            if (in_array($stepRun->status, ['failed', 'awaiting_input'])) {
                $this->log('info', "Resetting step '{$step->step_name}' from {$stepRun->status} to pending for re-execution");
                $stepRun->status = 'pending';
                $stepRun->error_message = null;
                $stepRun->awaiting_input = 0;
                $stepRun->awaiting_input_token = null;
                $stepRun->awaiting_input_timeout_at = null;
                $stepRun->attempt_number = 0;
                Bean::store($stepRun);
            }

            // Execute the step
            $success = $this->executeStepWithRetries($step, $stepRun);

            if (!$success) {
                // Handle failure based on on_failure setting
                $failAction = $step->on_failure ?: 'exit';
                if ($failAction === 'exit') {
                    $this->run->status = 'failed';
                    $this->run->error_message = "Step failed: {$step->step_name}";
                    $this->run->completed_at = date('Y-m-d H:i:s');
                    Bean::store($this->run);
                    return;
                } elseif (strpos($failAction, 'goto:') === 0) {
                    $targetStepName = substr($failAction, 5);
                    foreach ($stepsArray as $idx => $s) {
                        if ($s->step_name === $targetStepName) {
                            $i = $idx - 1; // Will be incremented by loop
                            break;
                        }
                    }
                }
                // For skip/ignore, continue to next step
            } else {
                // Handle on_success flow
                $successAction = $step->on_success ?: 'next_col';
                if (strpos($successAction, 'goto:') === 0) {
                    $targetStepName = substr($successAction, 5);
                    foreach ($stepsArray as $idx => $s) {
                        if ($s->step_name === $targetStepName) {
                            $i = $idx - 1; // Will be incremented by loop
                            break;
                        }
                    }
                } elseif ($successAction === 'exit') {
                    // Exit the pipeline successfully
                    $this->run->status = 'completed';
                    $this->run->completed_at = date('Y-m-d H:i:s');
                    Bean::store($this->run);
                    return;
                }
                // For 'next_col' or default, continue to next step
            }

            // Update progress
            $this->run->steps_completed = ($this->run->steps_completed ?? 0) + 1;
            Bean::store($this->run);
        }

        // All steps completed
        $this->run->status = 'completed';
        $this->run->completed_at = date('Y-m-d H:i:s');
        Bean::store($this->run);
    }

    /**
     * Execute exactly one step and pause
     *
     * This is the core method for the debugger's "Next Step" button.
     * Returns information about what was executed and what comes next.
     *
     * @return array ['success' => bool, 'step_executed' => [...], 'next_step' => [...], 'run_status' => string]
     */
    public function stepNext(): array {
        try {
            // Enable single-step mode
            $this->singleStepMode = true;

            // Load run and pipeline
            $this->run = Bean::load('pipelineruns', $this->runId);
            if (!$this->run->id) {
                return ['success' => false, 'error' => "Run not found: {$this->runId}"];
            }

            // Check if run is in a resumable state
            if (!in_array($this->run->status, ['paused', 'pending', 'running'])) {
                return [
                    'success' => false,
                    'error' => "Run cannot be stepped (status: {$this->run->status})",
                    'run_status' => $this->run->status
                ];
            }

            $this->pipeline = Bean::load('pipelines', $this->run->pipelines_id);
            if (!$this->pipeline->id) {
                return ['success' => false, 'error' => "Pipeline not found for run: {$this->runId}"];
            }

            // Load step lookups
            $this->loadStepLookups();

            // Load context from run
            $this->context = json_decode($this->run->context_json ?: '{}', true);

            // Ensure run directory is in context
            $this->context['run_directory'] = $this->getRunDirectory();

            // Set interactive mode (required for pausing to work)
            $this->interactiveMode = true;

            // Check if this is a fresh run or resuming from a paused state
            $awaitingStepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input = 1 ',
                [$this->run->id]
            );

            if ($awaitingStepRun) {
                // Resuming from a paused step - clear the awaiting flag and find next step
                $awaitingStepRun->awaiting_input = 0;
                $awaitingStepRun->updated_at = date('Y-m-d H:i:s');
                Bean::store($awaitingStepRun);

                // Auto-apply saved mappings for the step that was awaiting
                $pausedStep = Bean::load('pipelinesteps', $awaitingStepRun->pipelinesteps_id);
                $stepOutput = json_decode($awaitingStepRun->output_json ?: '{}', true);
                $this->autoApplySavedMappings($pausedStep, $stepOutput);

                // Load outputs from completed steps
                $this->loadCompletedStepOutputs();

                // Ensure the paused step's output is LAST in the array so it's the "previous step" for stdin
                // This is important because prepareStepInput uses end($this->stepOutputs) for stdin passthrough
                unset($this->stepOutputs[$pausedStep->step_name]);
                $this->stepOutputs[$pausedStep->step_name] = $stepOutput;

                // Find the resume index
                $stepsArray = array_values($this->steps);
                $pausedStepIndex = null;
                foreach ($stepsArray as $idx => $step) {
                    if ($step->id == $awaitingStepRun->pipelinesteps_id) {
                        $pausedStepIndex = $idx;
                        break;
                    }
                }

                if ($pausedStepIndex === null) {
                    return ['success' => false, 'error' => 'Could not find paused step in step list'];
                }

                $this->resumeFromStepIndex = $pausedStepIndex + 1;

            } else if ($this->run->status === 'pending') {
                // First step of a fresh run
                $this->run->status = 'running';
                $this->run->started_at = date('Y-m-d H:i:s');
                $this->run->steps_total = count($this->steps);
                $this->run->updated_at = date('Y-m-d H:i:s');
                Bean::store($this->run);

                // Initialize step runs if not already done
                $existingStepRuns = Bean::count('pipelinestepruns',
                    ' pipelineruns_id = ? ',
                    [$this->run->id]
                );
                if ($existingStepRuns === 0) {
                    $this->initializeStepRuns();
                }

                $this->resumeFromStepIndex = 0;
            } else {
                // Running state but no awaiting step - shouldn't happen normally
                // Try to continue from last completed step
                $lastCompletedStep = Bean::findOne('pipelinestepruns',
                    ' pipelineruns_id = ? AND status = ? ORDER BY id DESC ',
                    [$this->run->id, 'success']
                );

                if ($lastCompletedStep) {
                    $stepsArray = array_values($this->steps);
                    foreach ($stepsArray as $idx => $step) {
                        if ($step->id == $lastCompletedStep->pipelinesteps_id) {
                            $this->resumeFromStepIndex = $idx + 1;
                            break;
                        }
                    }
                }

                if ($this->resumeFromStepIndex === null) {
                    $this->resumeFromStepIndex = 0;
                }
            }

            // Update run status to running
            $this->run->status = 'running';
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            // Execute steps - will stop after one step due to singleStepMode
            $this->executeSteps();

            // Reload run to get updated status
            $this->run = Bean::load('pipelineruns', $this->runId);

            // Get the step that was just executed
            $executedStepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND (status = ? OR status = ? OR awaiting_input = 1) ORDER BY id DESC ',
                [$this->run->id, 'success', 'failure']
            );

            $executedStepInfo = null;
            if ($executedStepRun) {
                $stepDef = Bean::load('pipelinesteps', $executedStepRun->pipelinesteps_id);
                $executedStepInfo = [
                    'step_id' => $executedStepRun->pipelinesteps_id,
                    'step_name' => $executedStepRun->step_name,
                    'label' => $stepDef->label ?? $executedStepRun->step_name,
                    'row' => (int) $executedStepRun->row,
                    'col' => (int) $executedStepRun->col,
                    'status' => $executedStepRun->status,
                    'output' => json_decode($executedStepRun->output_json ?: '{}', true),
                    'stdout' => $executedStepRun->stdout,
                    'stderr' => $executedStepRun->stderr,
                    'exit_code' => $executedStepRun->exit_code,
                    'duration_ms' => $executedStepRun->duration_ms,
                    'error_message' => $executedStepRun->error_message
                ];
            }

            // Calculate next step info
            $nextStepInfo = $this->calculateNextStep();

            return [
                'success' => true,
                'step_executed' => $executedStepInfo,
                'next_step' => $nextStepInfo,
                'run_status' => $this->run->status,
                'steps_completed' => $this->run->steps_completed,
                'steps_total' => $this->run->steps_total,
                'progress_percent' => $this->run->progress_percent
            ];

        } catch (PipelinePausedException $e) {
            // Step executed and paused - this is expected
            $this->run = Bean::load('pipelineruns', $this->runId);

            $executedStepRun = Bean::load('pipelinestepruns', $e->getStepRunId());
            $stepDef = Bean::load('pipelinesteps', $executedStepRun->pipelinesteps_id);

            $executedStepInfo = [
                'step_id' => $executedStepRun->pipelinesteps_id,
                'step_name' => $executedStepRun->step_name,
                'label' => $stepDef->label ?? $executedStepRun->step_name,
                'row' => (int) $executedStepRun->row,
                'col' => (int) $executedStepRun->col,
                'status' => $executedStepRun->status,
                'output' => $e->getStepOutput(),
                'stdout' => $executedStepRun->stdout,
                'stderr' => $executedStepRun->stderr,
                'exit_code' => $executedStepRun->exit_code,
                'duration_ms' => $executedStepRun->duration_ms
            ];

            $nextStepInfo = $this->calculateNextStep();

            return [
                'success' => true,
                'step_executed' => $executedStepInfo,
                'next_step' => $nextStepInfo,
                'run_status' => 'paused',
                'steps_completed' => $this->run->steps_completed,
                'steps_total' => $this->run->steps_total,
                'progress_percent' => $this->run->progress_percent
            ];

        } catch (\Exception $e) {
            $this->log('error', "Step execution failed: " . $e->getMessage());

            // Reload run to get the current state
            $this->run = Bean::load('pipelineruns', $this->runId);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'run_status' => $this->run->status ?? 'failed'
            ];
        }
    }

    /**
     * Calculate what the next step will be based on current state
     *
     * @return array|null Next step info or null if pipeline is complete
     */
    private function calculateNextStep(): ?array {
        // Reload steps if needed
        if (empty($this->steps)) {
            $this->loadStepLookups();
        }

        $stepsArray = array_values($this->steps);

        // Find the awaiting step (just paused) or the last completed step
        $awaitingStepRun = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND awaiting_input = 1 ',
            [$this->run->id]
        );

        if ($awaitingStepRun) {
            // Find what comes after the awaiting step
            foreach ($stepsArray as $idx => $step) {
                if ($step->id == $awaitingStepRun->pipelinesteps_id) {
                    // Check on_success to determine actual next step
                    $onSuccess = $step->on_success ?: 'next_col';

                    if ($onSuccess === 'exit') {
                        // Pipeline will complete
                        return null;
                    } elseif (strpos($onSuccess, 'goto:') === 0) {
                        $target = substr($onSuccess, 5);
                        if (isset($this->stepsByName[$target])) {
                            $nextStep = $this->stepsByName[$target];
                            return [
                                'step_id' => $nextStep->id,
                                'step_name' => $nextStep->step_name,
                                'label' => $nextStep->label ?? $nextStep->step_name,
                                'row' => (int) $nextStep->row,
                                'col' => (int) $nextStep->col,
                                'reason' => "goto:{$target}"
                            ];
                        }
                    }

                    // Default: next step by sequence
                    if ($idx + 1 < count($stepsArray)) {
                        $nextStep = $stepsArray[$idx + 1];
                        return [
                            'step_id' => $nextStep->id,
                            'step_name' => $nextStep->step_name,
                            'label' => $nextStep->label ?? $nextStep->step_name,
                            'row' => (int) $nextStep->row,
                            'col' => (int) $nextStep->col,
                            'reason' => 'next_col'
                        ];
                    }

                    // No more steps
                    return null;
                }
            }
        }

        // Check if there's a pending step
        $pendingStepRun = Bean::findOne('pipelinestepruns',
            ' pipelineruns_id = ? AND status = ? ORDER BY row ASC, col ASC ',
            [$this->run->id, 'pending']
        );

        if ($pendingStepRun) {
            $step = Bean::load('pipelinesteps', $pendingStepRun->pipelinesteps_id);
            if ($step->id) {
                return [
                    'step_id' => $step->id,
                    'step_name' => $step->step_name,
                    'label' => $step->label ?? $step->step_name,
                    'row' => (int) $step->row,
                    'col' => (int) $step->col,
                    'reason' => 'pending'
                ];
            }
        }

        // All steps completed
        return null;
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

            // Load outputs from all completed steps
            $this->loadCompletedStepOutputs();

            // Ensure the paused step's output is LAST in the array so it's the "previous step" for stdin
            // This is important because prepareStepInput uses end($this->stepOutputs) for stdin passthrough
            // We must unset and re-add to move it to the end (PHP arrays maintain insertion order)
            $pausedStep = $stepsArray[$pausedStepIndex];
            unset($this->stepOutputs[$pausedStep->step_name]);
            $this->stepOutputs[$pausedStep->step_name] = $stepOutput;

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
     *
     * In debug mode (step-by-step), pause after EVERY step regardless of mappings.
     * In normal interactive mode, only pause if step has no saved mappings.
     */
    private function shouldPauseAfterStep($step): bool {
        // Single-step mode always pauses (used by stepnext endpoint)
        if ($this->singleStepMode) {
            return true;
        }

        if (!$this->interactiveMode) {
            return false;
        }

        // Debug mode: pause after EVERY step, regardless of saved mappings
        if ($this->debugMode) {
            return true;
        }

        // Normal interactive mode: only pause if no saved mappings
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
     * Pause the pipeline for external input (await_input wait type)
     *
     * Unlike pauseForInput() which is for interactive variable mapping,
     * this pauses waiting for input from external sources:
     * - MCP continue_pipeline call
     * - Webhook POST
     * - Web form submission
     * - Email reply
     *
     * @throws PipelinePausedException
     */
    private function pauseForAwaitInput($step, $stepRun, array $awaitResult): void {
        $this->log('info', "Pausing for external input at step: {$step->step_name}");

        $inputToken = $awaitResult['input_token'];
        $inputSchema = $awaitResult['input_schema'] ?? [];
        $prompt = $awaitResult['prompt'] ?? 'Waiting for input';
        $timeoutSeconds = $awaitResult['timeout_seconds'] ?? 86400;
        $allowedSources = $awaitResult['allowed_sources'] ?? ['mcp', 'webhook', 'form'];

        // Mark step run as awaiting input with metadata
        // Use PHP time() for consistency - both storage and comparison use PHP timezone
        $timeoutAt = date('Y-m-d H:i:s', time() + $timeoutSeconds);

        $stepRun->status = 'awaiting_input';
        $stepRun->awaiting_input = 1;
        $stepRun->awaiting_input_token = $inputToken;
        $stepRun->awaiting_input_schema_json = json_encode($inputSchema);
        $stepRun->awaiting_input_prompt = $prompt;
        $stepRun->awaiting_input_timeout_at = $timeoutAt;
        $stepRun->awaiting_input_sources_json = json_encode($allowedSources);
        $stepRun->updated_at = date('Y-m-d H:i:s');
        Bean::store($stepRun);

        // Mark run as awaiting_input (distinct from 'paused' which is for interactive mode)
        $this->run->status = 'awaiting_input';
        $this->run->context_json = json_encode($this->context);
        $this->run->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->run);

        // Send notifications if configured
        if (!empty($awaitResult['notification'])) {
            $this->sendAwaitInputNotification($awaitResult['notification'], $inputToken);
        }

        // Build response with URLs for input
        $baseUrl = $this->getBaseUrl();
        $stepOutput = [
            'status' => 'awaiting_input',
            'input_token' => $inputToken,
            'prompt' => $prompt,
            'input_schema' => $inputSchema,
            'allowed_sources' => $allowedSources,
            'timeout_at' => $timeoutAt,
            'form_url' => "{$baseUrl}/pipelines/form/{$this->runId}?token={$inputToken}",
            'webhook_url' => "{$baseUrl}/pipelines/input/{$this->runId}?token={$inputToken}",
            'context' => $this->context
        ];

        throw new PipelinePausedException($stepRun->id, $stepOutput);
    }

    /**
     * Send notification when pipeline is awaiting input
     */
    private function sendAwaitInputNotification(array $notification, string $inputToken): void {
        // TODO: Implement email/SMS notification
        // For now, just log it
        $this->log('info', "Would send await input notification", [
            'notification' => $notification,
            'input_token' => $inputToken
        ]);
    }

    /**
     * Get the base URL for the application
     */
    private function getBaseUrl(): string {
        $workspace = $this->context['_workspace'] ?? $_SERVER['WORKSPACE'] ?? 'default';
        $host = $_SERVER['HTTP_HOST'] ?? 'myctobot.ai';

        // If host already has workspace prefix, use as-is
        if (strpos($host, '.') !== false && strpos($host, $workspace) === 0) {
            return "https://{$host}";
        }

        // Otherwise, prepend workspace
        if ($workspace && $workspace !== 'default') {
            return "https://{$workspace}.myctobot.ai";
        }

        return "https://myctobot.ai";
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

            // Check for entry_step - start execution from a specific step
            $entryStep = $this->run->entry_step ?? null;
            if ($entryStep && $this->resumeFromStepIndex === null) {
                $stepsArray = array_values($this->steps);
                foreach ($stepsArray as $idx => $step) {
                    if ($step->step_name === $entryStep) {
                        $this->resumeFromStepIndex = $idx;
                        $this->log('info', "Starting from entry_step: {$entryStep} (index {$idx})");
                        break;
                    }
                }
                if ($this->resumeFromStepIndex === null) {
                    $this->log('warning', "Entry step not found: {$entryStep}, starting from beginning");
                }
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

            // Add workspace and base URL context
            $workspace = \Flight::get('workspace') ?? $_SERVER['WORKSPACE'] ?? null;
            if (!$workspace) {
                throw new \RuntimeException('Workspace not configured');
            }
            $this->context['workspace'] = $workspace;
            $baseUrl = \Flight::get('app.baseurl') ?? "https://{$workspace}.myctobot.ai";
            $this->context['base_url'] = rtrim($baseUrl, '/');

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
        $stepsArray = array_values($this->steps);
        $entryIndex = $this->resumeFromStepIndex ?? 0;

        foreach ($stepsArray as $idx => $step) {
            $stepRun = Bean::dispense('pipelinestepruns');
            $stepRun->pipelineruns = $this->run;
            $stepRun->pipelinesteps = $step;
            $stepRun->step_name = $step->step_name;
            $stepRun->row = $step->row;
            $stepRun->col = $step->col;

            // Mark steps before entry_step as skipped
            if ($idx < $entryIndex) {
                $stepRun->status = 'skipped';
                $stepRun->error_message = 'Skipped due to entry_step';
            } else {
                $stepRun->status = 'pending';
            }

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

            // Check if this step is parallel - if so, run all parallel steps in the same column
            if ($step->run_parallel) {
                $parallelCol = (int) $step->col;
                $this->log('info', "Entering parallel execution at column {$parallelCol}");
                $completedCount = $this->executeParallelRows($stepsArray, $currentIndex, $completedCount);

                // Find the next step in the next column (non-parallel or parallel in a different column)
                $foundNext = false;
                for ($i = 0; $i < count($stepsArray); $i++) {
                    $checkStep = $stepsArray[$i];
                    $checkCol = (int) $checkStep->col;

                    // Must be in a column AFTER the parallel column
                    if ($checkCol > $parallelCol) {
                        $currentIndex = $i;
                        $foundNext = true;
                        $this->log('info', "Continuing from column {$checkCol} step {$checkStep->step_name} (after parallel column {$parallelCol})");
                        break;
                    }
                }

                if (!$foundNext) {
                    // No more steps after parallel column, we're done
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
     * Execute all parallel steps in the same column
     *
     * When a step with run_parallel=1 is encountered, find ALL parallel steps
     * in that same column (regardless of row) and execute them together.
     * This enables true parallel agent dispatch: all agents in the same column
     * are dispatched at once, then execution continues to the next column.
     *
     * @return int Updated completed count
     */
    private function executeParallelRows(array $stepsArray, int $startIndex, int $completedCount): int {
        $startStep = $stepsArray[$startIndex];
        $parallelCol = (int) $startStep->col;

        // Find all parallel steps in the same column
        $parallelSteps = [];
        foreach ($stepsArray as $step) {
            if ($step->run_parallel && (int) $step->col === $parallelCol) {
                $parallelSteps[] = $step;
            }
        }

        $this->log('info', "Found " . count($parallelSteps) . " parallel steps in column {$parallelCol}");

        // Execute all parallel steps (they should all be dispatched without blocking)
        foreach ($parallelSteps as $step) {
            $this->log('info', "Executing parallel step: {$step->step_name} (row {$step->row})");

            // Find step run record
            $stepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND pipelinesteps_id = ? ',
                [$this->run->id, $step->id]
            );

            if (!$stepRun) {
                $this->log('error', "Step run not found for parallel step: {$step->step_name}");
                continue;
            }

            // Check condition
            if (!$this->evaluateStepCondition($step)) {
                $this->log('info', "Parallel step skipped due to condition: {$step->step_name}");
                $stepRun->status = 'skipped';
                $stepRun->completed_at = date('Y-m-d H:i:s');
                $stepRun->updated_at = date('Y-m-d H:i:s');
                Bean::store($stepRun);
                $completedCount++;
                continue;
            }

            // Update current step
            $this->run->current_step_name = $step->step_name;
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            // Execute step (with wait_for_completion=false, this returns quickly)
            $success = $this->executeStepWithRetries($step, $stepRun);

            if ($success) {
                $completedCount++;
                $this->updateProgress($completedCount);
            }
            // Note: For parallel steps, we continue even if one fails
            // The harvest step will collect all results
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
            if (!$this->evaluateStepCondition($step)) {
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
        if (!$this->evaluateStepCondition($step)) {
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

            // Check if step returned a _goto directive (used by condition/switch steps)
            $stepOutput = $this->stepOutputs[$step->step_name]['output'] ?? [];
            $gotoDirective = $stepOutput['_goto'] ?? null;

            if (!empty($gotoDirective)) {
                // Use the _goto directive from step output
                $nextAction = 'goto:' . $gotoDirective;
                $this->log('info', "Following _goto directive: {$gotoDirective}");
            } else {
                $nextAction = $step->on_success ?: 'next_col';
            }

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
                // Build stepOutput with proper structure for variable substitution
                // Variables use paths like {step.output.field} and {step.stdout}
                $rawOutput = $result['output'] ?? [];
                if (!is_array($rawOutput)) {
                    $rawOutput = ['raw' => $rawOutput];
                }

                // Structure stepOutput so variable paths work correctly:
                // - {step.output.field} accesses the parsed output
                // - {step.stdout} accesses raw stdout
                // - {step.stderr} accesses raw stderr
                $stepOutput = [
                    'output' => $rawOutput,
                    'stdout' => $result['stdout'] ?? null,
                    'stderr' => $result['stderr'] ?? null,
                    'exit_code' => $result['exit_code'] ?? null
                ];

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
                $this->previousStepOutput = $stepOutput;  // Track for {prev.xxx} substitution

                // Merge output into context if configured
                if (!empty($stepOutput)) {
                    $this->context[$step->step_name] = $stepOutput;
                }

                // Check if step is awaiting external input (await_input wait type)
                if (!empty($result['awaiting_input'])) {
                    $this->pauseForAwaitInput($step, $stepRun, $result);
                    // pauseForAwaitInput throws PipelinePausedException
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
                // Pass step info for session naming
                $config['_step_name'] = $step->step_name;
                $config['_step_id'] = $step->id;
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

            case 'condition':
                return $this->executeCondition($config, $input);

            case 'switch':
                return $this->executeSwitch($config, $input);

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
     *   model          - Optional model override (uses agent's configured model if not specified)
     *   max_tokens     - Optional max tokens (default: 4096)
     *   include_input  - Whether to include step input in the prompt (default: true)
     *
     * The agent's provider and provider_config determine which LLM backend is used.
     * Supported providers: ollama, claude_cli (with optional use_ollama), claude_api
     */
    private function executeAIAgent(array $config, array $input, int $timeout): array {
        $agentId = $config['agent_id'] ?? null;
        $executionMode = $config['execution_mode'] ?? 'api';
        $prompt = $config['prompt'] ?? '';
        $systemPrompt = $config['system_prompt'] ?? 'You are a helpful assistant processing pipeline data. Analyze the input and respond with structured JSON when appropriate.';
        $maxTokens = (int)($config['max_tokens'] ?? 4096);
        $includeContext = $config['include_context'] ?? $config['include_input'] ?? true;
        $jsonOutput = $config['json_output'] ?? false;

        if (empty($agentId)) {
            return ['success' => false, 'error' => 'Agent ID required'];
        }

        // Load agent
        $agent = Bean::load('aiagents', $agentId);
        if (!$agent->id) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        // Substitute variables in prompts
        $prompt = $this->substituteVariables($prompt);
        $systemPrompt = $this->substituteVariables($systemPrompt);

        // Build user message with context data if requested
        $userMessage = $prompt;
        if ($includeContext && !empty($input)) {
            $inputJson = json_encode($input, JSON_PRETTY_PRINT);
            $userMessage = "{$prompt}\n\n## Pipeline Context\n```json\n{$inputJson}\n```";
        }

        // For runner execution mode, inject pipeline-specific context and MCP tools
        if ($executionMode === 'runner') {
            $stepName = $config['_step_name'] ?? 'agent';
            $pipelineName = $this->pipeline->name ?? 'Unknown Pipeline';
            $pipelineContext = <<<PIPELINE

# Pipeline Agent Task

You are an AI agent running as step **{$stepName}** in the pipeline "{$pipelineName}".

**Pipeline Run ID:** {$this->runId}
**Step Name:** {$stepName}

## Your Task

Complete the task described below. When finished, call `job_complete` to signal completion.

## MCP Tools Available

### Completion (REQUIRED when done)
- **job_complete(success, summary)** - Signal your step is complete
  - `success`: true if task completed successfully, false if failed
  - `summary`: Brief description of what you accomplished

### Inter-Agent Communication
- **get_run_context(run_id)** - Get shared pipeline context (data from other steps)
- **update_run_context(run_id, key, value)** - Store data for other agents to access
- **list_run_sessions(run_id)** - See which other agents are currently active
- **send_to_step(run_id, target_step, message)** - Send a message to another running agent

### Other Available Tools
You also have access to filesystem tools (Read, Write, Edit, Glob, Grep, Bash) and any MCP servers configured for this workspace.

## Important

1. Focus on completing your specific task
2. Use `update_run_context` to share important results with other agents
3. **Always call `job_complete(success=true/false, summary="...")` when done**
4. If you encounter errors, call `job_complete(success=false, summary="Error: ...")`

---

PIPELINE;
            $userMessage = $pipelineContext . "\n## Task\n\n" . $userMessage;
        }

        $this->log('info', "Executing AI agent: {$agent->name}", [
            'execution_mode' => $executionMode,
            'prompt_length' => strlen($userMessage)
        ]);

        // Route based on execution mode
        if ($executionMode === 'runner') {
            // Full Claude CLI session on a runner with MCP tools
            return $this->executeAIAgentOnRunner($agent, $config, $userMessage, $timeout);
        }

        // API mode - direct LLM call
        $provider = $agent->provider ?: 'claude_api';
        $providerConfig = json_decode($agent->provider_config ?: '{}', true);

        try {
            // Check for Ollama provider (direct or via claude_cli with use_ollama)
            $useOllama = ($provider === 'ollama') ||
                         ($provider === 'claude_cli' && !empty($providerConfig['use_ollama']));

            if ($useOllama) {
                $result = $this->executeOllamaAgent($agent, $provider, $providerConfig, $userMessage, $systemPrompt, $timeout);
            } elseif ($provider === 'claude_api') {
                $model = $config['model'] ?? $providerConfig['model'] ?? 'claude-sonnet-4-20250514';
                $result = $this->executeClaudeApiAgent($agent, $model, $userMessage, $systemPrompt, $maxTokens, $timeout);
            } elseif ($provider === 'claude_cli') {
                // For claude_cli provider without use_ollama, suggest using runner mode
                return [
                    'success' => false,
                    'error' => "Agent '{$agent->name}' uses Claude CLI. Set execution_mode: 'runner' to run " .
                               "on a workstation with full MCP tool access, or configure 'use_ollama' for API mode."
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Unsupported provider '{$provider}'. Use execution_mode: 'runner' for full CLI sessions."
                ];
            }

            // Parse JSON output if requested
            if ($jsonOutput && $result['success'] && isset($result['output']['response'])) {
                $response = $result['output']['response'];
                // Try to extract JSON from response
                if (preg_match('/```json\s*([\s\S]*?)\s*```/', $response, $matches)) {
                    $jsonStr = $matches[1];
                } else {
                    $jsonStr = $response;
                }
                $parsed = json_decode($jsonStr, true);
                if ($parsed !== null) {
                    $result['output']['parsed'] = $parsed;
                }
            }

            return $result;

        } catch (\Exception $e) {
            $this->log('error', "AI agent exception: {$e->getMessage()}");
            return ['success' => false, 'error' => "AI agent exception: {$e->getMessage()}"];
        }
    }

    /**
     * Execute AI agent on a runner using full Claude CLI with MCP tools
     *
     * This spawns a complete Claude Code session on a workstation, giving the agent
     * access to all configured MCP servers. The agent can:
     * - Call MCP tools (Shopify, GitHub, other pipelines, etc.)
     * - Create files, run commands
     * - Spawn other pipelines via schedule_pipeline MCP tool
     *
     * @param object $agent The AI agent bean
     * @param array $config Step configuration
     * @param string $prompt The fully-substituted prompt
     * @param int $timeout Max execution time in seconds
     * @return array Result with success, output, etc.
     */
    private function executeAIAgentOnRunner($agent, array $config, string $prompt, int $timeout): array {
        $runnerId = $config['runner_id'] ?? $agent->runners_id ?? null;
        $waitForCompletion = $config['wait_for_completion'] ?? true;

        $this->log('info', "Executing agent on runner", [
            'agent' => $agent->name,
            'runner_id' => $runnerId,
            'wait' => $waitForCompletion,
            'timeout' => $timeout
        ]);

        // Load runner if specified
        $runner = null;
        if ($runnerId) {
            $runner = Bean::load('runners', $runnerId);
            if (!$runner->id) {
                return ['success' => false, 'error' => "Runner {$runnerId} not found"];
            }
        }

        // Create a unique job identifier for this pipeline step
        // Format: PIPE-{run_id}-{step_name} for clear session naming
        $stepName = $config['_step_name'] ?? 'agent';
        $jobKey = sprintf('PIPE-%d-%s', $this->runId, $stepName);
        $jobUid = 'pipe-' . $this->runId . '-' . bin2hex(random_bytes(8));

        // Create aidevjobs record
        $job = Bean::dispense('aidevjobs');
        $job->job_uid = $jobUid;
        $job->issue_key = $jobKey;
        $job->aiagents_id = $agent->id;
        $job->runners_id = $runnerId;
        $job->member_id = $this->run->member_id;
        $job->boards_id = null;  // Not a Jira job
        $job->trigger_source = 'pipeline:' . ($this->pipeline->slug ?? $this->pipeline->id);
        $job->pipelineruns_id = $this->runId;
        $job->status = 'pending';
        $job->phase = 'pipeline-agent';
        $job->run_count = 0;
        $job->prompt = $prompt;
        $job->queue_metadata = json_encode([
            'pipeline_id' => $this->pipeline->id,
            'pipeline_name' => $this->pipeline->name,
            'run_id' => $this->runId,
            'run_uid' => $this->run->run_uid,
            'step_name' => $stepName,
            'step_id' => $config['_step_id'] ?? null,
            'resident_mode' => true,  // Claude stays alive for pipeline duration
            'context' => $this->context
        ]);
        $job->created_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        $jobId = Bean::store($job);

        $this->log('info', "Created AI dev job", ['job_id' => $jobId, 'job_key' => $jobKey]);

        // Dispatch the job to the runner
        $dispatchResult = $this->dispatchAgentJob($job, $agent, $runner, $prompt);

        if (!$dispatchResult['success']) {
            $job->status = 'failed';
            $job->error_message = $dispatchResult['error'];
            $job->updated_at = date('Y-m-d H:i:s');
            Bean::store($job);
            return $dispatchResult;
        }

        // If not waiting for completion, return immediately
        if (!$waitForCompletion) {
            return [
                'success' => true,
                'output' => [
                    'job_id' => $jobId,
                    'job_key' => $jobKey,
                    'status' => 'dispatched',
                    'message' => 'Agent job dispatched, not waiting for completion'
                ]
            ];
        }

        // Poll for completion
        $startTime = time();
        $pollInterval = 5;  // seconds

        while ((time() - $startTime) < $timeout) {
            sleep($pollInterval);

            // Reload job to check status
            $job = Bean::load('aidevjobs', $jobId);

            $this->log('debug', "Polling agent job status", [
                'job_id' => $jobId,
                'status' => $job->status,
                'elapsed' => time() - $startTime
            ]);

            if (in_array($job->status, ['complete', 'pr_created', 'failed'])) {
                break;
            }
        }

        // Final status check
        $job = Bean::load('aidevjobs', $jobId);

        if ($job->status === 'failed') {
            return [
                'success' => false,
                'error' => $job->error_message ?: 'Agent job failed',
                'output' => [
                    'job_id' => $jobId,
                    'job_key' => $jobKey,
                    'status' => $job->status,
                    'last_output' => $job->last_output
                ]
            ];
        }

        if (!in_array($job->status, ['complete', 'pr_created'])) {
            return [
                'success' => false,
                'error' => "Agent job timed out after {$timeout}s (status: {$job->status})",
                'output' => [
                    'job_id' => $jobId,
                    'job_key' => $jobKey,
                    'status' => $job->status
                ]
            ];
        }

        // Parse result
        $result = json_decode($job->last_result_json ?: '{}', true);

        return [
            'success' => true,
            'output' => [
                'job_id' => $jobId,
                'job_key' => $jobKey,
                'status' => $job->status,
                'pr_url' => $job->pr_url,
                'pr_number' => $job->pr_number,
                'branch_name' => $job->branch_name,
                'result' => $result,
                'last_output' => $job->last_output
            ]
        ];
    }

    /**
     * Dispatch an agent job to a runner
     *
     * @param object $job The aidevjobs bean
     * @param object $agent The aiagents bean
     * @param object|null $runner The runners bean (null for local)
     * @param string $prompt The prompt text
     * @return array Result with success status
     */
    private function dispatchAgentJob($job, $agent, $runner, string $prompt): array {
        $workspace = $this->context['workspace'] ?? $_SESSION['workspace_slug'] ?? 'default';

        // Use TmuxService to spawn the job (same pattern as AIDevJobService)
        $tmux = new TmuxService($job->member_id, $job->issue_key, null, $workspace);

        // Write prompt to the work directory
        $promptFile = $tmux->getWorkDir() . '/prompt.txt';
        @mkdir($tmux->getWorkDir(), 0755, true);
        file_put_contents($promptFile, $prompt);

        // Build workstation config from runner if specified
        $workstation = null;
        if ($runner && $runner->host && $runner->host !== 'localhost') {
            $workstation = [
                'host' => $runner->host,
                'user' => $runner->ssh_user ?? 'claudeuser',
                'port' => $runner->ssh_port ?? 22
            ];
            $this->log('info', 'Using remote workstation', [
                'runner' => $runner->name,
                'host' => $runner->host,
                'port' => $workstation['port']
            ]);
        }

        $scriptPath = dirname(__DIR__) . '/scripts/job-dispatcher.php';

        $this->log('info', "Dispatching agent job via TmuxService", [
            'issue_key' => $job->issue_key,
            'workspace' => $workspace,
            'workstation' => $workstation ? $workstation['host'] : 'local'
        ]);

        // Update job status to running
        $job->status = 'running';
        $job->started_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        // Spawn using TmuxService (handles SSH, tmux creation, etc.)
        if ($tmux->spawnWithScript($scriptPath, false, $job->job_uid, null, $workspace, 'pipeline', $workstation)) {
            $this->log('info', 'Agent job spawned successfully', [
                'session' => $tmux->getSessionName()
            ]);

            return [
                'success' => true,
                'message' => 'Job dispatched',
                'session' => $tmux->getSessionName()
            ];
        }

        // Failed to spawn
        $job->status = 'failed';
        $job->error_message = 'Failed to spawn tmux session';
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        return [
            'success' => false,
            'error' => 'Failed to spawn tmux session'
        ];
    }

    /**
     * Execute agent using Ollama provider
     */
    private function executeOllamaAgent($agent, string $provider, array $providerConfig, string $userMessage, string $systemPrompt, int $timeout): array {
        // Determine host and model based on provider type
        if ($provider === 'ollama') {
            $ollamaHost = $providerConfig['host'] ?? $providerConfig['base_url'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['model'] ?? '';
        } else {
            // claude_cli with use_ollama
            $ollamaHost = $providerConfig['ollama_host'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['ollama_model'] ?? '';
        }

        if (empty($ollamaModel)) {
            return [
                'success' => false,
                'error' => "Agent '{$agent->name}' has Ollama enabled but no model specified in provider_config. " .
                           "Please configure ollama_model (for claude_cli) or model (for ollama provider)."
            ];
        }

        $this->log('info', "Using Ollama provider", [
            'host' => $ollamaHost,
            'model' => $ollamaModel
        ]);

        try {
            // Use OllamaProvider for the request
            $ollamaProvider = new OllamaProvider([
                'host' => $ollamaHost,
                'model' => $ollamaModel
            ]);

            // Use chat API with system prompt
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ];

            $result = $ollamaProvider->chat($messages, [
                'model' => $ollamaModel
            ]);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => "Ollama request failed: " . ($result['error'] ?? 'Unknown error')
                ];
            }

            $content = $result['response'] ?? '';

            // Try to parse response as JSON
            $parsed = null;
            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $content, $matches)) {
                $parsed = json_decode($matches[1], true);
            } elseif (str_starts_with(trim($content), '{') || str_starts_with(trim($content), '[')) {
                $parsed = json_decode($content, true);
            }

            $this->log('info', "Ollama agent completed", [
                'response_length' => strlen($content),
                'has_json' => $parsed !== null
            ]);

            return [
                'success' => true,
                'output' => [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'provider' => 'ollama',
                    'model' => $ollamaModel,
                    'response' => $content,
                    'parsed' => $parsed,
                    'usage' => $result['usage'] ?? null
                ]
            ];

        } catch (\Exception $e) {
            $this->log('error', "Ollama agent failed: {$e->getMessage()}");
            return ['success' => false, 'error' => "Ollama error: {$e->getMessage()}"];
        }
    }

    /**
     * Execute agent using Claude API (Anthropic)
     */
    private function executeClaudeApiAgent($agent, string $model, string $userMessage, string $systemPrompt, int $maxTokens, int $timeout): array {
        $memberId = $this->run->member_id ?? 1;
        $apiKey = AnthropicKeyService::getApiKeyForAgent($agent, $memberId);

        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => "Agent '{$agent->name}' is configured to use Claude API but no Anthropic API key is available. " .
                           "Please assign an API key to this agent or switch to a different provider."
            ];
        }

        $this->log('info', "Using Claude API provider", ['model' => $model]);

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

            $this->log('info', "Claude API agent completed", [
                'response_length' => strlen($content),
                'has_json' => $parsed !== null
            ]);

            return [
                'success' => true,
                'output' => [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'provider' => 'claude_api',
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
            $this->log('error', "Claude API agent failed: {$errorMessage}");
            return ['success' => false, 'error' => "Claude API error: {$errorMessage}"];
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

        // Use stdin directly - it's the raw passthrough from previous step
        // Context is available separately for variable substitution
        $inputData = trim($input['stdin'] ?? '');

        // PHP expressions can generate data without input (like generators)
        // Only require input for jq and regex which transform existing data
        if (empty($inputData) && $parserType !== 'php') {
            return ['success' => false, 'error' => 'No input data available. Set Input Source to "STDIN from previous step" or "Get from specific step".'];
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

            case 'php':
                // PHP expression evaluator - can generate or transform data
                // Expression should be a PHP statement that returns a value
                // Available variables: $input (the input data), $context (pipeline context)
                try {
                    $context = $input['context'] ?? [];
                    $evalInput = $inputData ? json_decode($inputData, true) : null;

                    // Create a closure to isolate the eval scope
                    $evalFn = function($input, $context, $expression) {
                        // The expression should return a value
                        return eval($expression);
                    };

                    $result = $evalFn($evalInput, $context, $expression);

                    // Ensure we return an array
                    if (!is_array($result)) {
                        $result = ['value' => $result];
                    }

                    return [
                        'success' => true,
                        'output' => $result,
                        'stdout' => json_encode($result)
                    ];
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'error' => 'PHP eval error: ' . $e->getMessage()
                    ];
                }

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

            case 'await_input':
                // Pause pipeline and wait for input from MCP, webhook, form, or email
                $inputToken = bin2hex(random_bytes(16));
                $timeoutSeconds = $config['timeout_seconds'] ?? 86400;
                $allowedSources = $config['allowed_sources'] ?? ['mcp', 'webhook', 'form'];

                $this->log('info', "Awaiting input with token: {$inputToken}");

                return [
                    'success' => true,
                    'awaiting_input' => true,
                    'input_token' => $inputToken,
                    'input_schema' => $config['input_schema'] ?? [],
                    'prompt' => $config['prompt'] ?? 'Waiting for input',
                    'timeout_seconds' => $timeoutSeconds,
                    'allowed_sources' => $allowedSources,
                    'notification' => $config['notification'] ?? null,
                    'output' => [
                        'status' => 'awaiting_input',
                        'input_token' => $inputToken,
                        'prompt' => $config['prompt'] ?? 'Waiting for input',
                        'timeout_at' => date('Y-m-d H:i:s', time() + $timeoutSeconds)
                    ]
                ];

            default:
                return ['success' => false, 'error' => "Unknown wait type: {$waitType}"];
        }
    }

    /**
     * Execute a condition step - evaluate condition and return goto directive
     *
     * Returns a _goto directive in output that the executor will follow.
     * This allows if/then/else branching in pipelines.
     *
     * Config options:
     *   left      - Left side of comparison (supports variable substitution)
     *   operator  - Comparison operator (equals, not_equals, greater_than, etc.)
     *   right     - Right side of comparison (supports variable substitution)
     *   then_goto - Step to go to if condition is TRUE (step name or row.col)
     *   else_goto - Step to go to if condition is FALSE (step name or row.col)
     */
    private function executeCondition(array $config, array $input): array {
        $left = $this->substituteVariables($config['left'] ?? '');
        $operator = $config['operator'] ?? 'equals';
        $right = $this->substituteVariables($config['right'] ?? '');
        $thenGoto = $config['then_goto'] ?? null;
        $elseGoto = $config['else_goto'] ?? null;

        // Evaluate the condition
        $result = $this->evaluateCondition($left, $operator, $right);

        $this->log('info', "Condition: '{$left}' {$operator} '{$right}' = " . ($result ? 'TRUE' : 'FALSE'));

        // Determine which branch to take
        $gotoTarget = $result ? $thenGoto : $elseGoto;

        return [
            'success' => true,
            'output' => [
                'condition_result' => $result,
                'left_value' => $left,
                'operator' => $operator,
                'right_value' => $right,
                'branch_taken' => $result ? 'then' : 'else',
                '_goto' => $gotoTarget  // Special directive for executor
            ]
        ];
    }

    /**
     * Evaluate a condition expression
     */
    private function evaluateCondition(string $left, string $operator, string $right): bool {
        // Convert to appropriate types for comparison
        $leftNum = is_numeric($left) ? floatval($left) : null;
        $rightNum = is_numeric($right) ? floatval($right) : null;

        switch ($operator) {
            case 'equals':
                return $left === $right || ($leftNum !== null && $rightNum !== null && $leftNum === $rightNum);

            case 'not_equals':
                return $left !== $right && !($leftNum !== null && $rightNum !== null && $leftNum === $rightNum);

            case 'greater_than':
                if ($leftNum !== null && $rightNum !== null) {
                    return $leftNum > $rightNum;
                }
                return strcmp($left, $right) > 0;

            case 'less_than':
                if ($leftNum !== null && $rightNum !== null) {
                    return $leftNum < $rightNum;
                }
                return strcmp($left, $right) < 0;

            case 'greater_equal':
                if ($leftNum !== null && $rightNum !== null) {
                    return $leftNum >= $rightNum;
                }
                return strcmp($left, $right) >= 0;

            case 'less_equal':
                if ($leftNum !== null && $rightNum !== null) {
                    return $leftNum <= $rightNum;
                }
                return strcmp($left, $right) <= 0;

            case 'contains':
                return strpos($left, $right) !== false;

            case 'not_contains':
                return strpos($left, $right) === false;

            case 'starts_with':
                return strpos($left, $right) === 0;

            case 'ends_with':
                return substr($left, -strlen($right)) === $right;

            case 'is_empty':
                return empty($left) || $left === 'null' || $left === 'false';

            case 'is_not_empty':
                return !empty($left) && $left !== 'null' && $left !== 'false';

            case 'is_true':
                return in_array(strtolower($left), ['true', '1', 'yes', 'on'], true);

            case 'is_false':
                return in_array(strtolower($left), ['false', '0', 'no', 'off', ''], true) || empty($left);

            case 'regex':
                return preg_match('/' . $right . '/', $left) === 1;

            default:
                $this->log('warning', "Unknown operator: {$operator}, defaulting to equals");
                return $left === $right;
        }
    }

    /**
     * Execute a switch step - evaluate value against multiple cases
     *
     * Like a switch/case statement. Matches value against cases and returns goto directive.
     *
     * Config options:
     *   value   - Value to switch on (supports variable substitution)
     *   cases   - Object mapping values to goto targets: {"value1": "step1", "value2": "step2"}
     *   default - Step to go to if no case matches
     */
    private function executeSwitch(array $config, array $input): array {
        $value = $this->substituteVariables($config['value'] ?? '');
        $cases = $config['cases'] ?? [];
        $defaultGoto = $config['default'] ?? null;

        $this->log('info', "Switch on value: '{$value}'");

        // Find matching case
        $matchedCase = null;
        $gotoTarget = null;

        foreach ($cases as $caseValue => $caseGoto) {
            if ($value === (string)$caseValue) {
                $matchedCase = $caseValue;
                $gotoTarget = $caseGoto;
                break;
            }
        }

        // Use default if no match
        if ($gotoTarget === null) {
            $matchedCase = '_default';
            $gotoTarget = $defaultGoto;
        }

        $this->log('info', "Switch matched case: '{$matchedCase}' -> goto:{$gotoTarget}");

        return [
            'success' => true,
            'output' => [
                'switch_value' => $value,
                'matched_case' => $matchedCase,
                'available_cases' => array_keys($cases),
                '_goto' => $gotoTarget  // Special directive for executor
            ]
        ];
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

        // Support delay + delay_unit format (from pipeline builder)
        if ($delaySeconds === 0 && isset($config['delay'])) {
            $delay = (int) $config['delay'];
            $delayUnit = (int) ($config['delay_unit'] ?? 3600); // Default to hours
            $delaySeconds = $delay * $delayUnit;
        }

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

            // Build payload based on task type
            $payload = $config['payload'] ?? [];
            if ($taskType === 'execute_pipeline') {
                // Store pipeline execution details
                $payload['pipeline_id'] = $config['pipeline_id'] ?? null;
                $payload['entry_step'] = $config['entry_step'] ?? null;
                // Substitute variables in input_data
                $inputData = $config['input_data'] ?? [];
                if (!empty($inputData)) {
                    $inputData = json_decode($this->substituteVariables(json_encode($inputData)), true) ?? $inputData;
                }
                $payload['input_data'] = $inputData;
            } elseif ($taskType === 'webhook_call') {
                // Store webhook details
                $payload['webhook_url'] = $this->substituteVariables($config['webhook_url'] ?? '');
                $payload['webhook_method'] = $config['webhook_method'] ?? 'POST';
                $payload['webhook_headers'] = $config['webhook_headers'] ?? [];
                $payload['webhook_body'] = $this->substituteVariables($config['webhook_body'] ?? '');
            }
            $task->payload_json = json_encode($payload);

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
     *
     * Context/ENV is ALWAYS available for {variable} substitution.
     * Input source determines what comes in as stdin (raw passthrough).
     */
    private function prepareStepInput($step): array {
        $inputSource = $step->input_source ?? 'context';
        $inputConfig = json_decode($step->input_config_json ?: '{}', true);

        // Determine stdin based on input source
        $stdin = '';
        switch ($inputSource) {
            case 'stdin':
                // Raw stdout passthrough from previous step
                $prevOutput = end($this->stepOutputs) ?: [];
                $stdin = $prevOutput['stdout'] ?? '';
                break;

            case 'getfrom':
                // Raw stdout from a specific step
                $stepName = $inputConfig['step'] ?? '';
                if (!empty($stepName) && isset($this->stepOutputs[$stepName])) {
                    $stdin = $this->stepOutputs[$stepName]['stdout'] ?? '';
                }
                break;

            case 'context':
            default:
                // No stdin, just context
                $stdin = '';
                break;
        }

        // Always return both stdin and context
        return [
            'stdin' => $stdin,
            'context' => $this->context,
        ];
    }

    /**
     * Evaluate step condition (checks condition_json to decide if step should run)
     */
    private function evaluateStepCondition($step): bool {
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
        // Log available step outputs for debugging
        $this->log('debug', 'substituteVariables called', [
            'template_preview' => substr($template, 0, 200),
            'available_step_outputs' => array_keys($this->stepOutputs),
            'context_keys' => array_keys($this->context)
        ]);

        // Replace context variables {context.xxx}
        $template = preg_replace_callback('/\{context\.([^}]+)\}/', function($matches) {
            $key = $matches[1];
            $value = $this->getNestedValue($this->context, $key);
            $this->log('debug', "Substituting context variable", [
                'key' => $key,
                'found' => $value !== null,
                'value_preview' => $value !== null ? substr((string)$value, 0, 100) : 'NULL'
            ]);
            return $value ?? '';
        }, $template);

        // Replace step output variables {step_name.xxx}
        // Also handles special {prev.xxx} to reference the previous step's output
        $template = preg_replace_callback('/\{([a-z_][a-z0-9_]*)\.([^}]+)\}/', function($matches) {
            $stepName = $matches[1];
            $path = $matches[2];

            // Handle {prev.xxx} - reference the previous step's output
            if ($stepName === 'prev' && $this->previousStepOutput !== null) {
                $value = $this->getNestedValue($this->previousStepOutput, $path);
                $this->log('debug', "Substituting prev variable", [
                    'path' => $path,
                    'found' => $value !== null
                ]);
                return $value ?? '';
            }

            if (!isset($this->stepOutputs[$stepName])) {
                $this->log('warning', "Step output not found for substitution", [
                    'step_name' => $stepName,
                    'path' => $path,
                    'available_steps' => array_keys($this->stepOutputs)
                ]);
                return '';
            }

            $value = $this->getNestedValue($this->stepOutputs[$stepName], $path);
            $this->log('debug', "Substituting step variable", [
                'step_name' => $stepName,
                'path' => $path,
                'found' => $value !== null,
                'value_preview' => $value !== null ? substr((string)$value, 0, 100) : 'NULL'
            ]);
            return $value ?? '';
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

        // Kill all resident Claude sessions for this pipeline run
        $this->killAllResidentSessions();

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
     * Wait for all running agent jobs for this pipeline run to complete
     *
     * @param int $timeout Maximum wait time in seconds
     */
    private function waitForAgentJobs(int $timeout = 600): void {
        $startTime = time();
        $pollInterval = 5;  // Poll every 5 seconds

        while ((time() - $startTime) < $timeout) {
            // Find all running jobs for this pipeline run
            $runningJobs = Bean::find('aidevjobs',
                'pipelineruns_id = ? AND status IN (?, ?)',
                [$this->runId, 'pending', 'running']
            );

            if (empty($runningJobs)) {
                $this->log('info', "All agent jobs completed");
                break;
            }

            $jobCount = count($runningJobs);
            $elapsed = time() - $startTime;
            $this->log('debug', "Waiting for {$jobCount} agent jobs", [
                'elapsed' => $elapsed,
                'timeout' => $timeout
            ]);

            sleep($pollInterval);
        }

        // Collect results from completed jobs and update stepOutputs
        $completedJobs = Bean::find('aidevjobs',
            'pipelineruns_id = ? AND status IN (?, ?, ?)',
            [$this->runId, 'complete', 'pr_created', 'failed']
        );

        foreach ($completedJobs as $job) {
            // Extract step name from job key (format: PIPE-{run_id}-{step_name})
            if (preg_match('/^PIPE-\d+-(.+)$/', $job->issue_key, $matches)) {
                $stepName = $matches[1];

                // If this step isn't already in stepOutputs, add it
                if (!isset($this->stepOutputs[$stepName])) {
                    $result = json_decode($job->last_result_json ?: '{}', true);

                    if ($job->status === 'failed') {
                        $this->stepOutputs[$stepName] = [
                            'error' => $job->error_message ?: 'Job failed',
                            'job_id' => $job->id,
                            'job_status' => $job->status
                        ];
                    } else {
                        $this->stepOutputs[$stepName] = [
                            'job_id' => $job->id,
                            'job_key' => $job->issue_key,
                            'status' => $job->status,
                            'pr_url' => $job->pr_url,
                            'pr_number' => $job->pr_number,
                            'branch_name' => $job->branch_name,
                            'result' => $result,
                            'last_output' => $job->last_output
                        ];
                    }

                    // Also add to context
                    $this->context[$stepName] = $this->stepOutputs[$stepName];

                    $this->log('info', "Harvested agent job result", [
                        'step' => $stepName,
                        'status' => $job->status,
                        'job_id' => $job->id
                    ]);
                }
            }
        }

        // Update run context with harvested results
        $this->run->context_json = json_encode($this->context);
        Bean::store($this->run);
    }

    /**
     * Execute a harvest step - gather results from parallel rows
     *
     * Harvest ALWAYS waits for any running agent jobs to complete before collecting results.
     * This enables true parallel agent execution: dispatch agents with wait_for_completion=false,
     * then harvest waits for all of them to finish and collects the results.
     */
    private function executeHarvest(array $config, array $input): array {
        $policy = $config['policy'] ?? 'all_required';
        $onIncomplete = $config['on_incomplete'] ?? 'fail';
        $template = $config['template'] ?? '';
        $timeout = $config['timeout'] ?? 600;  // Default 10 minutes

        $this->log('info', "Executing harvest with policy: {$policy}");

        // First, wait for any running agent jobs for this pipeline run
        $this->waitForAgentJobs($timeout);

        // Collect all step outputs from parallel rows
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

            $output = [
                'data' => $result['data'],
                'extensions' => $result['extensions'],
                'shop' => $client->getShop()
            ];
            return [
                'success' => true,
                'output' => $output,
                'stdout' => json_encode($output, JSON_PRETTY_PRINT)
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
                    $this->log('debug', "file_write stdin: using {$lastStepName}.stdout", [
                        'stdout_len' => is_string($content) ? strlen($content) : 'N/A'
                    ]);
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

    // =====================================================
    // Resident Session Management (uses TmuxService)
    // =====================================================

    /**
     * Get TmuxService for a step's resident session
     *
     * @param string $stepName The step name
     * @return TmuxService|null Service instance if session exists
     */
    public function getResidentSession(string $stepName): ?TmuxService {
        $workspace = $this->context['workspace'] ?? 'dev';
        $issueKey = sprintf('PIPE-%d-%s', $this->runId, $stepName);

        $service = new TmuxService(
            $this->run->member_id,
            $issueKey,
            null,
            $workspace
        );

        return $service->exists() ? $service : null;
    }

    /**
     * Send a command to a resident Claude session
     *
     * @param string $stepName The step name (session identifier)
     * @param string $command The command/prompt to send
     * @return array Result with success status
     */
    public function sendToResidentSession(string $stepName, string $command): array {
        $service = $this->getResidentSession($stepName);

        if (!$service) {
            return [
                'success' => false,
                'error' => "No resident session found for step: {$stepName}"
            ];
        }

        $result = $service->sendMessage($command);

        $this->log('info', "Sent command to resident session", [
            'step_name' => $stepName,
            'session' => $service->getActiveSessionName(),
            'command_length' => strlen($command),
            'result' => $result
        ]);

        return [
            'success' => $result,
            'session' => $service->getActiveSessionName(),
            'message' => $result ? 'Command sent' : 'Failed to send'
        ];
    }

    /**
     * Kill all resident sessions for this pipeline run
     * Called when pipeline completes or fails
     */
    public function killAllResidentSessions(): void {
        $workspace = $this->context['workspace'] ?? 'dev';
        $pattern = "aoe-{$workspace}-PIPE-{$this->runId}-";

        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $output);
        foreach ($output as $session) {
            if (str_starts_with($session, $pattern)) {
                exec("tmux kill-session -t " . escapeshellarg($session) . " 2>/dev/null");
                $this->log('info', "Killed resident session", ['session' => $session]);
            }
        }
    }

    /**
     * Check if a resident session exists for a step
     */
    public function hasResidentSession(string $stepName): bool {
        return $this->getResidentSession($stepName) !== null;
    }

    /**
     * Get status of a resident session
     */
    public function getResidentSessionStatus(string $stepName): ?array {
        $service = $this->getResidentSession($stepName);
        return $service ? $service->getStatus() : null;
    }

    /**
     * Capture output from a resident session
     */
    public function captureResidentSessionOutput(string $stepName, int $lines = 50): ?string {
        $service = $this->getResidentSession($stepName);
        return $service ? $service->captureSnapshot($lines) : null;
    }
}
