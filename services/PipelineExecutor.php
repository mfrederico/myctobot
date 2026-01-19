<?php
/**
 * PipelineExecutor Service
 *
 * Executes pipeline runs by processing steps in sequence.
 * Handles step types: ai_agent, direct_exec, script, webhook_out, parser, wait
 *
 * Usage:
 *   $executor = new PipelineExecutor($runId, $logger);
 *   $executor->execute();
 */

namespace app\services;

use \app\Bean;
use \RedBeanPHP\R as R;

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

    public function __construct(int $runId, $logger = null) {
        $this->runId = $runId;
        $this->logger = $logger;
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

            if (empty($this->steps)) {
                $this->completeRun('completed', 'No active steps to execute');
                return true;
            }

            // Build step lookup maps
            foreach ($this->steps as $step) {
                $row = (int) $step->row;
                $col = (int) $step->col;
                $this->stepsByPosition["{$row}.{$col}"] = $step;
                $this->stepsByName[$step->step_name] = $step;
            }

            // Initialize context from pipeline defaults and trigger data
            $defaultContext = json_decode($this->pipeline->default_context_json ?: '{}', true);
            $triggerData = json_decode($this->run->trigger_data_json ?: '{}', true);
            $this->context = array_merge($defaultContext, $triggerData);

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
     * Execute steps in sequence
     */
    /**
     * Execute steps with goto flow control
     *
     * Supported flow actions for on_success/on_failure:
     *   - next_col: Move to next column in same row (default for success)
     *   - next_row: Move to first column of next row
     *   - exit: Complete/fail the pipeline
     *   - skip: Skip this failure and continue (only for on_failure)
     *   - goto:ROW.COLUMN: Jump to specific row and column (e.g., goto:2.execute)
     *   - goto:STEP_NAME: Jump to specific step by name (e.g., goto:validate_output)
     */
    private function executeSteps(): void {
        $completedCount = 0;
        $executedSteps = [];  // Track which steps have been executed
        $maxIterations = count($this->steps) * 3;  // Safety limit to prevent infinite loops
        $iterations = 0;

        // Start with first step
        $stepsArray = array_values($this->steps);
        $currentIndex = 0;

        while ($currentIndex < count($stepsArray) && $iterations < $maxIterations) {
            $iterations++;
            $step = $stepsArray[$currentIndex];

            // Check if run was cancelled
            $this->run = Bean::load('pipelineruns', $this->runId);
            if ($this->run->status === 'cancelled') {
                $this->log('info', 'Run was cancelled, stopping execution');
                return;
            }

            // Get step run record
            $stepRun = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND pipelinesteps_id = ? ',
                [$this->run->id, $step->id]
            );

            if (!$stepRun) {
                $this->log('error', "Step run not found for step: {$step->step_name}");
                $currentIndex++;
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
                $currentIndex++;
                continue;
            }

            // Update current step
            $this->run->current_step_name = $step->step_name;
            $this->run->updated_at = date('Y-m-d H:i:s');
            Bean::store($this->run);

            // Execute step with retries
            $executedSteps[$step->step_name] = true;
            $success = $this->executeStepWithRetries($step, $stepRun);

            if ($success) {
                $completedCount++;
                $this->updateProgress($completedCount);

                // Handle on_success
                $nextAction = $step->on_success ?: 'next_col';
                $nextIndex = $this->resolveNextStep($step, $nextAction, $stepsArray, $currentIndex);

                if ($nextIndex === -1) {
                    // exit
                    $this->completeRun('completed');
                    return;
                }
                $currentIndex = $nextIndex;
            } else {
                // Handle on_failure
                $failAction = $step->on_failure ?: 'exit';

                if ($failAction === 'exit') {
                    $this->completeRun('failed', "Step failed: {$step->step_name}");
                    return;
                } elseif ($failAction === 'skip') {
                    $completedCount++;
                    $this->updateProgress($completedCount);
                    $currentIndex++;
                    continue;
                } else {
                    // Handle goto on failure
                    $nextIndex = $this->resolveNextStep($step, $failAction, $stepsArray, $currentIndex);
                    if ($nextIndex === -1) {
                        $this->completeRun('failed', "Step failed: {$step->step_name}");
                        return;
                    }
                    $currentIndex = $nextIndex;
                }
            }
        }

        if ($iterations >= $maxIterations) {
            $this->completeRun('failed', 'Maximum iterations exceeded (possible infinite loop)');
            return;
        }

        // All steps completed
        $this->completeRun('completed');
    }

    /**
     * Resolve next step based on flow action
     *
     * @return int Next step index, or -1 for exit
     */
    private function resolveNextStep($currentStep, string $action, array $stepsArray, int $currentIndex): int {
        $currentRow = (int) $currentStep->row;
        $currentCol = (int) $currentStep->col;

        switch ($action) {
            case 'exit':
                return -1;

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
                $stepRun->status = 'success';
                $stepRun->output_json = json_encode($result['output'] ?? []);
                $stepRun->stdout = $result['stdout'] ?? null;
                $stepRun->stderr = $result['stderr'] ?? null;
                $stepRun->exit_code = $result['exit_code'] ?? null;
                $stepRun->completed_at = date('Y-m-d H:i:s');
                $stepRun->updated_at = date('Y-m-d H:i:s');
                Bean::store($stepRun);

                // Store output for reference by other steps
                $this->stepOutputs[$step->step_name] = $result['output'] ?? [];

                // Merge output into context if configured
                if (!empty($result['output'])) {
                    $this->context[$step->step_name] = $result['output'];
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

            case 'parser':
                return $this->executeParser($config, $input);

            case 'wait':
                return $this->executeWait($config, $input);

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
        $workingDir = $config['working_dir'] ?: '/tmp';
        $executor = $config['executor'] ?: '/bin/bash -c';
        $workstationId = $config['workstation_id'] ?: null;

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

        // Build the execution
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Execute: use executor prefix if configured, otherwise run command directly
        if (!empty($executor)) {
            // Parse executor into array parts (e.g., "/bin/bash -c" → ['/bin/bash', '-c'])
            $executorParts = preg_split('/\s+/', trim($executor));
            $executorParts[] = $command;
            $process = proc_open($executorParts, $descriptors, $pipes, $workingDir, null);
        } else {
            // Direct execution - split command into parts
            $commandParts = preg_split('/\s+/', trim($command));
            $process = proc_open($commandParts, $descriptors, $pipes, $workingDir, null);
        }

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start process'];
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
     * Execute an AI agent
     */
    private function executeAIAgent(array $config, array $input, int $timeout): array {
        $agentId = $config['agent_id'] ?? null;
        $runnerId = $config['runner_id'] ?? null;
        $prompt = $config['prompt'] ?? '';

        if (empty($agentId)) {
            return ['success' => false, 'error' => 'Agent ID required'];
        }

        // Load agent
        $agent = Bean::load('aiagents', $agentId);
        if (!$agent->id) {
            return ['success' => false, 'error' => 'Agent not found'];
        }

        // Substitute variables in prompt
        $prompt = $this->substituteVariables($prompt);

        // TODO: Actually dispatch to agent runner
        // For now, return a placeholder
        $this->log('info', "Would execute AI agent {$agent->name} with prompt: {$prompt}");

        return [
            'success' => true,
            'output' => [
                'agent_id' => $agentId,
                'prompt' => $prompt,
                'status' => 'simulated'
            ]
        ];
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
        // Replace context variables
        $template = preg_replace_callback('/\{context\.([^}]+)\}/', function($matches) {
            $key = $matches[1];
            return $this->getNestedValue($this->context, $key) ?? '';
        }, $template);

        // Replace step output variables
        $template = preg_replace_callback('/\{([a-z_][a-z0-9_]*)\.([^}]+)\}/', function($matches) {
            $stepName = $matches[1];
            $path = $matches[2];

            if (!isset($this->stepOutputs[$stepName])) {
                return '';
            }

            return $this->getNestedValue($this->stepOutputs[$stepName], $path) ?? '';
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
    private function log(string $level, string $message): void {
        if ($this->logger) {
            $this->logger->$level($message, ['run_id' => $this->runId]);
        } else {
            error_log("[Pipeline:{$this->runId}] [{$level}] {$message}");
        }
    }
}
