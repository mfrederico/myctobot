<?php
/**
 * MCP Pipelines Controller - Pipeline Execution via MCP
 *
 * Provides MCP tools for executing pipelines from Claude Code.
 * Pipelines with expose_as_tool=1 are exposed as myctobot_{slug} tools.
 * Workspace is determined from subdomain (e.g., gwt.myctobot.ai).
 *
 * Usage in .mcp.json:
 *   {
 *     "mcpServers": {
 *       "pipelines": {
 *         "type": "http",
 *         "url": "https://gwt.myctobot.ai/mcp/pipelines",
 *         "headers": {
 *           "Authorization": "Bearer {api_key}"
 *         }
 *       }
 *     }
 *   }
 *
 * TODO: Tool Schema Refresh (notifications/tools/list_changed)
 * ----------------------------------------------------------------
 * MCP supports a `notifications/tools/list_changed` notification to inform
 * clients when the tool list has changed. For HTTP transport this is tricky
 * since there's no persistent connection. Options to implement:
 *   1. SSE endpoint - Add Server-Sent Events for real-time notifications
 *   2. Version header - Return schema version; clients detect changes and re-fetch
 *   3. WebSocket - Persistent connection for bidirectional notifications
 * Currently, clients must restart/reconnect to pick up schema changes.
 * See: https://modelcontextprotocol.io/docs/concepts/notifications
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;
use app\services\McpResponseTrait;
use app\services\ApiAuthService;

require_once __DIR__ . '/../services/McpResponseTrait.php';
require_once __DIR__ . '/../services/ApiAuthService.php';

class Mcppipelines extends Control {
    use McpResponseTrait;

    private ?int $memberId = null;
    private ?string $workspace = null;
    protected $logger;

    public function __construct() {
        // Don't call parent - MCP requests don't have sessions
        $this->logger = Flight::get('log');
        // Workspace from subdomain - set by front controller in public/index.php
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;
    }

    /**
     * MCP JSON-RPC endpoint for pipelines
     * POST /mcp/pipelines
     * Workspace is determined from subdomain: https://{workspace}.myctobot.ai/mcp/pipelines
     */
    public function index() {
        // Require workspace from subdomain
        if (empty($this->workspace)) {
            $this->sendJsonRpcError(-32600, 'Workspace required. Use https://{workspace}.myctobot.ai/mcp/pipelines', null);
            return;
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($this->workspace)) {
            $this->sendJsonRpcError(-32600, "Invalid workspace: {$this->workspace}", null);
            return;
        }

        // Read JSON-RPC request
        $rawBody = file_get_contents('php://input');
        $request = json_decode($rawBody, true);

        if (!$request || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            $this->sendJsonRpcError(-32600, 'Invalid JSON-RPC request', null);
            return;
        }

        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        $this->logger->debug("MCP Pipelines: method={$method}, workspace={$this->workspace}");

        // Handle methods
        switch ($method) {
            case 'initialize':
                $this->handleInitialize($id, $params);
                break;

            case 'notifications/initialized':
                $this->sendJsonRpcResult(['acknowledged' => true], $id);
                break;

            case 'tools/list':
                $this->handleToolsList($id);
                break;

            case 'tools/call':
                $this->handleToolCall($id, $params);
                break;

            default:
                $this->sendJsonRpcError(-32601, "Method not found: {$method}", $id);
        }
    }

    /**
     * Handle MCP initialize request
     */
    protected function handleInitialize($id, array $params): void {
        $this->sendJsonRpcResult([
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false]
            ],
            'serverInfo' => [
                'name' => 'myctobot-pipelines',
                'version' => '1.0.0'
            ]
        ], $id);
    }

    /**
     * Handle MCP tools/list request
     */
    protected function handleToolsList($id): void {
        // Authenticate with ApiAuthService
        $authResult = ApiAuthService::authenticate('mcp', 'pipelines');

        if (!$authResult['success']) {
            $this->sendJsonRpcError(-32000, $authResult['error'], $id);
            return;
        }

        $this->memberId = $authResult['member']->id;

        $tools = [];

        // Get all pipelines with expose_as_tool = 1
        $pipelines = Bean::find('pipelines', 'expose_as_tool = 1 AND is_active = 1');

        foreach ($pipelines as $pipeline) {
            $inputSchema = json_decode($pipeline->input_schema_json ?: '{}', true);
            if (empty($inputSchema) || !isset($inputSchema['type'])) {
                $inputSchema = [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => []
                ];
            }

            $tools[] = [
                'name' => 'myctobot_' . $pipeline->slug,
                'description' => $pipeline->description ?: "Execute the {$pipeline->name} pipeline",
                'inputSchema' => $inputSchema
            ];
        }

        // Add the continue_pipeline tool for multi-turn workflows
        $tools[] = [
            'name' => 'continue_pipeline',
            'description' => 'Continue a paused pipeline by providing the requested input. Use this when a pipeline returns awaiting_input status.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The pipeline run ID to continue'
                    ],
                    'input_token' => [
                        'type' => 'string',
                        'description' => 'The input token returned when pipeline paused'
                    ],
                    'input' => [
                        'type' => 'object',
                        'description' => 'The input data matching the awaited schema'
                    ]
                ],
                'required' => ['run_id', 'input_token', 'input']
            ]
        ];

        // Add schedule_pipeline tool for scheduling future pipeline runs
        $tools[] = [
            'name' => 'schedule_pipeline',
            'description' => 'Schedule a pipeline to run at a future time. Use this for reminders, delayed actions, or recurring tasks. Examples: "in 1 hour", "at 11:30 EST", "in 30 minutes".',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pipeline_slug' => [
                        'type' => 'string',
                        'description' => 'The pipeline slug to schedule (e.g., "multi-turn-input-test")'
                    ],
                    'delay_seconds' => [
                        'type' => 'integer',
                        'description' => 'Delay in seconds until the pipeline runs. Use this OR scheduled_time.'
                    ],
                    'scheduled_time' => [
                        'type' => 'string',
                        'description' => 'ISO 8601 datetime or natural time like "2026-01-30T11:30:00-05:00". Use this OR delay_seconds.'
                    ],
                    'input_data' => [
                        'type' => 'object',
                        'description' => 'Optional context data to pass to the pipeline'
                    ],
                    'entry_step' => [
                        'type' => 'string',
                        'description' => 'Optional step name to start execution from (skips earlier steps)'
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Optional description of what this scheduled task is for'
                    ]
                ],
                'required' => ['pipeline_slug']
            ]
        ];

        $this->sendJsonRpcResult(['tools' => $tools], $id);
    }

    /**
     * Handle MCP tools/call request
     */
    protected function handleToolCall($id, array $params): void {
        // Authenticate with ApiAuthService
        $authResult = ApiAuthService::authenticate('mcp', 'pipelines');

        if (!$authResult['success']) {
            $this->sendJsonRpcError(-32000, $authResult['error'], $id);
            return;
        }

        $this->memberId = $authResult['member']->id;

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            $this->sendJsonRpcError(-32602, 'Tool name is required', $id);
            return;
        }

        // Handle continue_pipeline tool for multi-turn workflows
        if ($toolName === 'continue_pipeline') {
            $this->continuePipeline($arguments, $id);
            return;
        }

        // Handle schedule_pipeline tool for scheduling future runs
        if ($toolName === 'schedule_pipeline') {
            $this->schedulePipeline($arguments, $id);
            return;
        }

        // All other tools are pipelines (prefixed with myctobot_)
        if (!str_starts_with($toolName, 'myctobot_')) {
            $this->sendJsonRpcError(-32602, "Unknown tool: {$toolName}", $id);
            return;
        }

        $this->executePipeline($toolName, $arguments, $id);
    }

    /**
     * Continue a paused pipeline with input
     *
     * Called when AI provides input to a pipeline that returned awaiting_input status.
     * Validates the input token and resumes execution.
     */
    private function continuePipeline(array $arguments, $id): void {
        $runId = $arguments['run_id'] ?? null;
        $inputToken = $arguments['input_token'] ?? null;
        $input = $arguments['input'] ?? [];

        if (!$runId) {
            $this->sendJsonRpcError(-32602, 'run_id is required', $id);
            return;
        }

        if (!$inputToken) {
            $this->sendJsonRpcError(-32602, 'input_token is required', $id);
            return;
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run->id) {
            $this->sendJsonRpcError(-32602, "Run not found: {$runId}", $id);
            return;
        }

        // Verify run is awaiting input
        if ($run->status !== 'awaiting_input') {
            $this->sendJsonRpcError(-32602, "Run is not awaiting input (status: {$run->status})", $id);
            return;
        }

        // Resume the pipeline
        require_once __DIR__ . '/../services/PipelineExecutor.php';

        try {
            $executor = new \app\services\PipelineExecutor($runId);
            $result = $executor->resumeFromAwaitInput($input, $inputToken, 'mcp');

            // Check if pipeline is now awaiting more input
            $run = Bean::load('pipelineruns', $runId);

            if ($run->status === 'awaiting_input') {
                // Find the new awaiting step
                $awaitingStep = Bean::findOne('pipelinestepruns',
                    ' pipelineruns_id = ? AND awaiting_input = 1 ',
                    [$runId]
                );

                $schema = $awaitingStep ? json_decode($awaitingStep->awaiting_input_schema_json ?: '{}', true) : [];

                $this->sendJsonRpcResult([
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
                ], $id);
                return;
            }

            // Pipeline completed or failed
            $isError = !in_array($run->status, ['completed', 'success']);

            if ($isError) {
                $this->sendJsonRpcResult([
                    'content' => [[
                        'type' => 'text',
                        'text' => "Pipeline failed: " . ($run->error_message ?: 'Unknown error')
                    ]],
                    'isError' => true
                ], $id);
            } else {
                $this->sendJsonRpcResult([
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode($result['output'] ?? ['status' => 'completed'], JSON_PRETTY_PRINT)
                    ]],
                    'isError' => false
                ], $id);
            }

        } catch (\Exception $e) {
            $this->sendJsonRpcError(-32000, 'Resume failed: ' . $e->getMessage(), $id);
        }
    }

    /**
     * Execute a pipeline as an MCP tool
     */
    private function executePipeline(string $toolName, array $arguments, $id): void {
        // Extract pipeline slug from tool name (myctobot_slug -> slug)
        $slug = substr($toolName, strlen('myctobot_'));

        $pipeline = Bean::findOne('pipelines', 'slug = ? AND expose_as_tool = 1 AND is_active = 1', [$slug]);
        if (!$pipeline) {
            $this->sendJsonRpcError(-32602, "Pipeline not found: {$slug}", $id);
            return;
        }

        // Count steps
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
        if ($stepCount === 0) {
            $this->sendJsonRpcError(-32000, 'Pipeline has no active steps', $id);
            return;
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));

        $run = Bean::dispense('pipelineruns');

        // Build context: merge default context with MCP arguments
        $context = json_decode($pipeline->default_context_json ?: '{}', true);
        $context = array_merge($context, $arguments);

        // Mark as authenticated MCP request in trigger data
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

        // Check if pipeline is awaiting input (multi-turn flow)
        if ($run->status === 'awaiting_input') {
            $awaitingStep = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input = 1 ',
                [$runId]
            );

            $schema = $awaitingStep ? json_decode($awaitingStep->awaiting_input_schema_json ?: '{}', true) : [];

            // Build form/webhook URLs for convenience
            $baseUrl = rtrim($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'], '/');
            $formUrl = $awaitingStep ? "{$baseUrl}/pipelines/form/{$runId}?token={$awaitingStep->awaiting_input_token}" : null;

            $this->sendJsonRpcResult([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'status' => 'awaiting_input',
                        'run_id' => $runId,
                        'input_token' => $awaitingStep->awaiting_input_token ?? null,
                        'prompt' => $awaitingStep->awaiting_input_prompt ?? 'Waiting for input',
                        'input_schema' => $schema,
                        'timeout_at' => $awaitingStep->awaiting_input_timeout_at ?? null,
                        'form_url' => $formUrl,
                        'output' => $context
                    ], JSON_PRETTY_PRINT)
                ]],
                'isError' => false
            ], $id);
            return;
        }

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
                'text' => "Pipeline execution failed: " . ($run->error_message ?: 'Unknown error')
            ];
        } else {
            $resultText = json_encode($lastOutput ?? $context, JSON_PRETTY_PRINT);
            $content[] = [
                'type' => 'text',
                'text' => $resultText
            ];
        }

        $this->sendJsonRpcResult([
            'content' => $content,
            'isError' => $isError
        ], $id);
    }

    /**
     * Schedule a pipeline to run at a future time
     *
     * Creates a scheduled task that will execute the pipeline after the specified delay.
     * The cron job (scripts/process-scheduled-tasks.php) processes these tasks.
     *
     * @param array $arguments {
     *   pipeline_slug: string - Required. The pipeline slug to schedule
     *   delay_seconds: int - Delay in seconds (use this OR scheduled_time)
     *   scheduled_time: string - ISO 8601 datetime (use this OR delay_seconds)
     *   input_data: object - Optional context data
     *   entry_step: string - Optional step to start from
     *   description: string - Optional description
     * }
     * @param mixed $id JSON-RPC request ID
     */
    private function schedulePipeline(array $arguments, $id): void {
        $pipelineSlug = $arguments['pipeline_slug'] ?? null;
        $delaySeconds = $arguments['delay_seconds'] ?? null;
        $scheduledTime = $arguments['scheduled_time'] ?? null;
        $inputData = $arguments['input_data'] ?? [];
        $entryStep = $arguments['entry_step'] ?? null;
        $description = $arguments['description'] ?? null;

        if (!$pipelineSlug) {
            $this->sendJsonRpcError(-32602, 'pipeline_slug is required', $id);
            return;
        }

        // Find the pipeline
        $pipeline = Bean::findOne('pipelines', 'slug = ? AND is_active = 1', [$pipelineSlug]);
        if (!$pipeline) {
            $this->sendJsonRpcError(-32602, "Pipeline not found or inactive: {$pipelineSlug}", $id);
            return;
        }

        // Calculate scheduled_for datetime
        $scheduledFor = null;
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $scheduledFor = date('Y-m-d H:i:s', time() + (int)$delaySeconds);
        } elseif ($scheduledTime) {
            // Parse ISO 8601 or other datetime formats
            $timestamp = strtotime($scheduledTime);
            if ($timestamp === false || $timestamp <= time()) {
                $this->sendJsonRpcError(-32602, "Invalid or past scheduled_time: {$scheduledTime}. Use ISO 8601 format like '2026-01-30T11:30:00-05:00' or relative like '+1 hour'.", $id);
                return;
            }
            $scheduledFor = date('Y-m-d H:i:s', $timestamp);
        } else {
            $this->sendJsonRpcError(-32602, 'Either delay_seconds or scheduled_time is required', $id);
            return;
        }

        // Validate entry_step if provided
        if ($entryStep) {
            $stepExists = Bean::findOne('pipelinesteps',
                'pipelines_id = ? AND step_name = ? AND is_active = 1',
                [$pipeline->id, $entryStep]
            );
            if (!$stepExists) {
                $this->sendJsonRpcError(-32602, "Entry step not found: {$entryStep}", $id);
                return;
            }
        }

        // Create the scheduled task
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

        // Calculate human-readable delay
        $delayReadable = $this->formatDelay(strtotime($scheduledFor) - time());

        $this->sendJsonRpcResult([
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
        ], $id);
    }

    /**
     * Format seconds as human-readable delay
     */
    private function formatDelay(int $seconds): string {
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

    /**
     * Send JSON-RPC success response
     */
    private function sendJsonRpcResult($result, $id): void {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ]);
        exit;
    }

    /**
     * Send JSON-RPC error response
     */
    private function sendJsonRpcError(int $code, string $message, $id): void {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'id' => $id
        ]);
        exit;
    }
}
