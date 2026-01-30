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
require_once __DIR__ . '/../services/PipelineSchemaService.php';
require_once __DIR__ . '/../services/StepTypeRegistry.php';

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
        // Authenticate during initialize
        $authResult = ApiAuthService::authenticate('mcp', 'pipelines');

        $response = [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false]
            ],
            'serverInfo' => [
                'name' => 'myctobot-pipelines',
                'version' => '1.0.0'
            ]
        ];

        // Add auth info if authenticated
        if ($authResult['success']) {
            $this->memberId = $authResult['member']->id;
            $response['serverInfo']['authenticated'] = true;
            $response['serverInfo']['member'] = $authResult['member']->email;
        }

        $this->sendJsonRpcResult($response, $id);
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

            // CRITICAL: Ensure properties is always an object, not an array
            // JSON Schema requires {"properties": {}} not {"properties": []}
            if (isset($inputSchema['properties']) && is_array($inputSchema['properties']) && empty($inputSchema['properties'])) {
                $inputSchema['properties'] = (object) [];
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

        // Add get_pipeline_building_blocks tool for AI pipeline creation
        $tools[] = [
            'name' => 'get_pipeline_building_blocks',
            'description' => 'Get all available step types, their configuration schemas, and your integrations. Use this before creating a pipeline to understand what building blocks are available.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => []
            ]
        ];

        // Add create_pipeline tool for AI pipeline creation
        $tools[] = [
            'name' => 'create_pipeline',
            'description' => 'Create a new pipeline from a structured JSON definition. Use get_pipeline_building_blocks first to understand available step types and your integrations.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Human-readable pipeline name'
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Optional URL-friendly slug. Auto-generated from name if omitted.'
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Description of what the pipeline does'
                    ],
                    'columns' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Column headers for the pipeline grid (e.g., ["Fetch", "Process", "Notify"])'
                    ],
                    'trigger_type' => [
                        'type' => 'string',
                        'enum' => ['manual', 'webhook', 'cron'],
                        'description' => 'How the pipeline is triggered. Default: manual'
                    ],
                    'steps' => [
                        'type' => 'array',
                        'description' => 'Array of step definitions',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'step_name' => ['type' => 'string', 'description' => 'Unique identifier (lowercase, underscores)'],
                                'label' => ['type' => 'string', 'description' => 'Display label'],
                                'row' => ['type' => 'integer', 'description' => 'Grid row (0-based)'],
                                'col' => ['type' => 'integer', 'description' => 'Grid column (0-based)'],
                                'step_type' => ['type' => 'string', 'description' => 'Step type from building blocks'],
                                'config' => ['type' => 'object', 'description' => 'Step-specific configuration'],
                                'on_success' => ['type' => 'string', 'description' => 'Action on success: next_col, goto:step_name, exit'],
                                'on_failure' => ['type' => 'string', 'description' => 'Action on failure: exit, goto:step_name, retry']
                            ],
                            'required' => ['step_name', 'row', 'col', 'step_type', 'config']
                        ]
                    ],
                    'expose_as_tool' => [
                        'type' => 'boolean',
                        'description' => 'Expose this pipeline as an MCP tool. Default: false'
                    ],
                    'input_schema' => [
                        'type' => 'object',
                        'description' => 'JSON Schema for pipeline inputs (required if expose_as_tool is true)'
                    ],
                    'dry_run' => [
                        'type' => 'boolean',
                        'description' => 'If true, validate only without creating. Returns validation result.'
                    ]
                ],
                'required' => ['name', 'steps']
            ]
        ];

        // =====================================================
        // Inter-Agent Communication Tools
        // =====================================================

        // Send message to another step's resident Claude session
        $tools[] = [
            'name' => 'send_to_step',
            'description' => 'Send a message/command to another step\'s resident Claude session. Use this for inter-agent communication within a pipeline run.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The pipeline run ID'
                    ],
                    'step_name' => [
                        'type' => 'string',
                        'description' => 'The target step name (e.g., "analyze_data", "fetch_customers")'
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'The message or command to send to the Claude session'
                    ]
                ],
                'required' => ['run_id', 'step_name', 'message']
            ]
        ];

        // Get pipeline run context (shared state)
        $tools[] = [
            'name' => 'get_run_context',
            'description' => 'Get the current pipeline run\'s shared context. This is the shared state between all steps and agents.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The pipeline run ID'
                    ],
                    'key' => [
                        'type' => 'string',
                        'description' => 'Optional specific key to retrieve. If omitted, returns entire context.'
                    ]
                ],
                'required' => ['run_id']
            ]
        ];

        // Update pipeline run context (shared state)
        $tools[] = [
            'name' => 'update_run_context',
            'description' => 'Update the pipeline run\'s shared context. Use this to share data between agents.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The pipeline run ID'
                    ],
                    'key' => [
                        'type' => 'string',
                        'description' => 'The context key to update'
                    ],
                    'value' => [
                        'description' => 'The value to set (any JSON-serializable type)'
                    ]
                ],
                'required' => ['run_id', 'key', 'value']
            ]
        ];

        // Mark step/job as complete
        $tools[] = [
            'name' => 'mark_step_complete',
            'description' => 'Mark the current step as complete. Use this when you\'ve finished your assigned task in a resident session.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The pipeline run ID'
                    ],
                    'step_name' => [
                        'type' => 'string',
                        'description' => 'The step name to mark complete'
                    ],
                    'output' => [
                        'type' => 'object',
                        'description' => 'Optional output data from this step'
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Brief summary of what was accomplished'
                    ]
                ],
                'required' => ['run_id', 'step_name']
            ]
        ];

        // List active sessions for a run
        $tools[] = [
            'name' => 'list_run_sessions',
            'description' => 'List all active Claude sessions for a pipeline run. Shows which agents are currently running.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The pipeline run ID'
                    ]
                ],
                'required' => ['run_id']
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

        // Handle get_pipeline_building_blocks tool for AI pipeline creation
        if ($toolName === 'get_pipeline_building_blocks') {
            $this->getPipelineBuildingBlocks($id);
            return;
        }

        // Handle create_pipeline tool for AI pipeline creation
        if ($toolName === 'create_pipeline') {
            $this->createPipeline($arguments, $id);
            return;
        }

        // =====================================================
        // Inter-Agent Communication Tools
        // =====================================================

        // Send message to another step's session
        if ($toolName === 'send_to_step') {
            $this->sendToStep($arguments, $id);
            return;
        }

        // Get pipeline run context
        if ($toolName === 'get_run_context') {
            $this->getRunContext($arguments, $id);
            return;
        }

        // Update pipeline run context
        if ($toolName === 'update_run_context') {
            $this->updateRunContext($arguments, $id);
            return;
        }

        // Mark step as complete
        if ($toolName === 'mark_step_complete') {
            $this->markStepComplete($arguments, $id);
            return;
        }

        // List active sessions for a run
        if ($toolName === 'list_run_sessions') {
            $this->listRunSessions($arguments, $id);
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

            // Check if resume failed
            if (isset($result['success']) && $result['success'] === false) {
                $this->sendJsonRpcError(-32000, $result['error'] ?? 'Resume failed', $id);
                return;
            }

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
     * Get all pipeline building blocks (step types, integrations, variable syntax)
     *
     * Returns everything an AI needs to know to build a pipeline:
     * - All step types with their config schemas
     * - User's available integrations (Shopify stores, workstations, etc.)
     * - Variable substitution syntax reference
     */
    private function getPipelineBuildingBlocks($id): void {
        $result = [
            'step_types' => services\PipelineSchemaService::getAllStepTypeSchemas(),
            'integrations' => $this->getUserIntegrations(),
            'variable_syntax' => services\PipelineSchemaService::getVariableSyntaxReference(),
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

        $this->sendJsonRpcResult([
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ], $id);
    }

    /**
     * Get user's available integrations
     */
    private function getUserIntegrations(): array {
        $integrations = [
            'shopify_stores' => [],
            'workstations' => [],
            'mcp_servers' => [],
            'repositories' => [],
            'ai_agents' => [],
            'mailgun_configured' => false
        ];

        // Get Shopify connections
        $shopifyConnections = Bean::find('shopifyconnections', ' 1=1 ORDER BY shop_name ASC ');
        foreach ($shopifyConnections as $conn) {
            $integrations['shopify_stores'][] = [
                'id' => (int) $conn->id,
                'name' => $conn->shop_name ?: $conn->shop_domain,
                'domain' => $conn->shop_domain,
                'connection_name' => $conn->connection_name
            ];
        }

        // Get workstations (runners)
        $runners = Bean::find('runners', ' is_active = 1 ORDER BY name ASC ');
        foreach ($runners as $runner) {
            $integrations['workstations'][] = [
                'id' => (int) $runner->id,
                'name' => $runner->name,
                'host' => $runner->host,
                'type' => $runner->runner_type ?? 'ssh'
            ];
        }

        // Get MCP servers
        $mcpServers = Bean::find('mcpservers', ' is_active = 1 ORDER BY name ASC ');
        foreach ($mcpServers as $server) {
            $integrations['mcp_servers'][] = [
                'id' => (int) $server->id,
                'name' => $server->name,
                'type' => $server->server_type
            ];
        }

        // Get repositories
        $repos = Bean::find('repoconnections', ' is_active = 1 ORDER BY repo_name ASC ');
        foreach ($repos as $repo) {
            $integrations['repositories'][] = [
                'id' => (int) $repo->id,
                'name' => $repo->repo_full_name ?? $repo->repo_name,
                'slug' => $repo->slug
            ];
        }

        // Get AI agents
        $agents = Bean::find('aiagents', ' is_active = 1 ORDER BY name ASC ');
        foreach ($agents as $agent) {
            $integrations['ai_agents'][] = [
                'id' => (int) $agent->id,
                'name' => $agent->name,
                'description' => $agent->description
            ];
        }

        // Check Mailgun configuration
        $mailgunKey = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_api_key']);
        $integrations['mailgun_configured'] = !empty($mailgunKey?->setting_value);

        return $integrations;
    }

    /**
     * Create a new pipeline from structured JSON definition
     *
     * @param array $arguments Pipeline definition
     * @param mixed $id JSON-RPC request ID
     */
    private function createPipeline(array $arguments, $id): void {
        $name = trim($arguments['name'] ?? '');
        $slug = trim($arguments['slug'] ?? '');
        $description = trim($arguments['description'] ?? '');
        $columns = $arguments['columns'] ?? ['Start', 'Execute', 'Complete'];
        $triggerType = $arguments['trigger_type'] ?? 'manual';
        $steps = $arguments['steps'] ?? [];
        $exposeAsTool = $arguments['expose_as_tool'] ?? false;
        $inputSchema = $arguments['input_schema'] ?? [];
        $dryRun = $arguments['dry_run'] ?? false;

        // Validation
        $errors = [];
        $warnings = [];

        if (empty($name)) {
            $errors[] = 'Pipeline name is required';
        }

        if (empty($steps)) {
            $errors[] = 'At least one step is required';
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
            $errors[] = "A pipeline with slug '{$slug}' already exists";
        }

        // Validate trigger type
        $validTriggerTypes = ['manual', 'webhook', 'cron'];
        if (!in_array($triggerType, $validTriggerTypes)) {
            $errors[] = "Invalid trigger_type. Must be one of: " . implode(', ', $validTriggerTypes);
        }

        // Validate columns
        if (!is_array($columns) || empty($columns)) {
            $columns = ['Start', 'Execute', 'Complete'];
        }

        // Validate steps
        $stepNames = [];
        $stepTypesUsed = [];
        $integrations = $this->getUserIntegrations();

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;

            // Required fields
            if (empty($step['step_name'])) {
                $errors[] = "Step #{$stepNum}: step_name is required";
                continue;
            }

            $stepName = $step['step_name'];

            // Validate step_name format
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $stepName)) {
                $errors[] = "Step '{$stepName}': step_name must start with lowercase letter and contain only lowercase letters, numbers, and underscores";
            }

            // Check for duplicate step names
            if (in_array($stepName, $stepNames)) {
                $errors[] = "Step '{$stepName}': duplicate step_name";
            }
            $stepNames[] = $stepName;

            // Validate step_type
            $stepType = $step['step_type'] ?? '';
            $typeSchema = services\PipelineSchemaService::getStepTypeSchema($stepType);
            if (!$typeSchema) {
                $errors[] = "Step '{$stepName}': unknown step_type '{$stepType}'";
                continue;
            }
            $stepTypesUsed[] = $stepType;

            // Validate row/col
            if (!isset($step['row']) || !is_numeric($step['row']) || $step['row'] < 0) {
                $errors[] = "Step '{$stepName}': row must be a non-negative integer";
            }
            if (!isset($step['col']) || !is_numeric($step['col']) || $step['col'] < 0) {
                $errors[] = "Step '{$stepName}': col must be a non-negative integer";
            }

            // Validate config against schema
            $config = $step['config'] ?? [];
            $configValidation = services\PipelineSchemaService::validateStepConfig($stepType, $config);
            if (!$configValidation['valid']) {
                foreach ($configValidation['errors'] as $configError) {
                    $errors[] = "Step '{$stepName}': {$configError}";
                }
            }

            // Validate integration references
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

        // Dry run mode - return validation result
        if ($dryRun) {
            $valid = empty($errors);
            $result = [
                'valid' => $valid,
                'pipeline_preview' => [
                    'name' => $name,
                    'slug' => $slug,
                    'step_count' => count($steps),
                    'step_types_used' => array_unique($stepTypesUsed),
                    'columns' => $columns
                ],
                'errors' => $errors,
                'warnings' => $warnings
            ];

            if ($valid) {
                $result['message'] = 'Pipeline is valid. Call again with dry_run=false to create.';
            } else {
                $result['message'] = 'Pipeline validation failed. Fix the errors and try again.';
            }

            $this->sendJsonRpcResult([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT)
                ]],
                'isError' => !$valid
            ], $id);
            return;
        }

        // If there are validation errors, don't create
        if (!empty($errors)) {
            $this->sendJsonRpcResult([
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
            ], $id);
            return;
        }

        // Create the pipeline
        $pipeline = Bean::dispense('pipelines');
        $pipeline->member_id = $this->memberId;
        $pipeline->slug = $slug;
        $pipeline->name = $name;
        $pipeline->description = $description;
        $pipeline->columns_json = json_encode($columns);
        $pipeline->trigger_type = $triggerType;
        $pipeline->trigger_config_json = '{}';
        $pipeline->default_context_json = '{}';
        $pipeline->is_active = 1;
        $pipeline->expose_as_tool = $exposeAsTool ? 1 : 0;
        $pipeline->input_schema_json = $exposeAsTool && !empty($inputSchema) ? json_encode($inputSchema) : '{}';
        $pipeline->run_count = 0;
        $pipeline->created_at = date('Y-m-d H:i:s');
        $pipeline->updated_at = date('Y-m-d H:i:s');

        $pipelineId = Bean::store($pipeline);

        // Create the steps
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

            $stepId = Bean::store($step);
            $createdSteps[] = [
                'id' => $stepId,
                'step_name' => $step->step_name,
                'step_type' => $step->step_type
            ];
        }

        // Build result
        $baseUrl = rtrim($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'], '/');
        $editUrl = "{$baseUrl}/pipelines/edit/{$pipelineId}";

        $result = [
            'success' => true,
            'pipeline' => [
                'id' => $pipelineId,
                'name' => $name,
                'slug' => $slug,
                'step_count' => count($createdSteps),
                'edit_url' => $editUrl
            ],
            'steps' => $createdSteps,
            'warnings' => $warnings,
            'message' => "Pipeline '{$name}' created successfully with " . count($createdSteps) . " steps."
        ];

        $this->sendJsonRpcResult([
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ], $id);
    }

    // =====================================================
    // Inter-Agent Communication Methods
    // =====================================================

    /**
     * Send a message to another step's resident Claude session
     */
    private function sendToStep(array $arguments, $id): void {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $stepName = $arguments['step_name'] ?? '';
        $message = $arguments['message'] ?? '';

        if (!$runId || !$stepName || !$message) {
            $this->sendJsonRpcError(-32602, 'run_id, step_name, and message are required', $id);
            return;
        }

        // Load the run
        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            $this->sendJsonRpcError(-32602, "Pipeline run {$runId} not found", $id);
            return;
        }

        // Load pipeline for context
        $pipeline = Bean::load('pipelines', $run->pipelines_id);

        // Use TmuxService to send message
        $workspace = \Flight::get('workspace') ?? $_SERVER['WORKSPACE'] ?? 'dev';
        $issueKey = sprintf('PIPE-%d-%s', $runId, $stepName);

        require_once __DIR__ . '/../services/TmuxService.php';
        $tmuxService = new \app\services\TmuxService(
            $run->member_id,
            $issueKey,
            null,
            $workspace
        );

        if (!$tmuxService->exists()) {
            $this->sendJsonRpcError(-32602, "No active session found for step '{$stepName}' in run {$runId}", $id);
            return;
        }

        $result = $tmuxService->sendMessage($message);

        $this->sendJsonRpcResult([
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
        ], $id);
    }

    /**
     * Get pipeline run context (shared state)
     */
    private function getRunContext(array $arguments, $id): void {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $key = $arguments['key'] ?? null;

        if (!$runId) {
            $this->sendJsonRpcError(-32602, 'run_id is required', $id);
            return;
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            $this->sendJsonRpcError(-32602, "Pipeline run {$runId} not found", $id);
            return;
        }

        $context = json_decode($run->context_json ?: '{}', true);

        // If specific key requested, return just that
        if ($key !== null) {
            $value = $context[$key] ?? null;
            $this->sendJsonRpcResult([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'run_id' => $runId,
                        'key' => $key,
                        'value' => $value
                    ], JSON_PRETTY_PRINT)
                ]],
                'isError' => false
            ], $id);
            return;
        }

        // Return entire context
        $this->sendJsonRpcResult([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'run_id' => $runId,
                    'context' => $context
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ], $id);
    }

    /**
     * Update pipeline run context (shared state)
     */
    private function updateRunContext(array $arguments, $id): void {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $key = $arguments['key'] ?? '';
        $value = $arguments['value'] ?? null;

        if (!$runId || !$key) {
            $this->sendJsonRpcError(-32602, 'run_id and key are required', $id);
            return;
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            $this->sendJsonRpcError(-32602, "Pipeline run {$runId} not found", $id);
            return;
        }

        // Update context
        $context = json_decode($run->context_json ?: '{}', true);
        $context[$key] = $value;
        $run->context_json = json_encode($context);
        $run->updated_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $this->sendJsonRpcResult([
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
        ], $id);
    }

    /**
     * Mark a step as complete
     */
    private function markStepComplete(array $arguments, $id): void {
        $runId = (int) ($arguments['run_id'] ?? 0);
        $stepName = $arguments['step_name'] ?? '';
        $output = $arguments['output'] ?? null;
        $summary = $arguments['summary'] ?? '';

        if (!$runId || !$stepName) {
            $this->sendJsonRpcError(-32602, 'run_id and step_name are required', $id);
            return;
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            $this->sendJsonRpcError(-32602, "Pipeline run {$runId} not found", $id);
            return;
        }

        // Find the aidevjob for this step
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

        // Update run context with step output
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

        $this->sendJsonRpcResult([
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
        ], $id);
    }

    /**
     * List active sessions for a pipeline run
     */
    private function listRunSessions(array $arguments, $id): void {
        $runId = (int) ($arguments['run_id'] ?? 0);

        if (!$runId) {
            $this->sendJsonRpcError(-32602, 'run_id is required', $id);
            return;
        }

        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            $this->sendJsonRpcError(-32602, "Pipeline run {$runId} not found", $id);
            return;
        }

        $workspace = \Flight::get('workspace') ?? $_SERVER['WORKSPACE'] ?? 'dev';
        $pattern = "aoe-{$workspace}-PIPE-{$runId}-";

        // List tmux sessions matching the pattern
        $sessions = [];
        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $output);
        foreach ($output as $session) {
            if (str_starts_with($session, $pattern)) {
                // Extract step name from session
                // Format: aoe-{workspace}-PIPE-{runId}-{step_name}-{hash}
                // Note: step_name may contain hyphens (e.g., run_agent -> run-agent)
                // So we remove the prefix and suffix (hash is always last segment)
                $remainder = substr($session, strlen($pattern));  // e.g., "run-agent-9238e21a"
                $lastHyphen = strrpos($remainder, '-');
                $stepNameSanitized = $lastHyphen !== false
                    ? substr($remainder, 0, $lastHyphen)   // e.g., "run-agent"
                    : $remainder;
                // Convert hyphens back to underscores (original step_name format)
                $stepName = str_replace('-', '_', $stepNameSanitized);

                $sessions[] = [
                    'session_name' => $session,
                    'step_name' => $stepName,
                    'pattern' => $pattern
                ];
            }
        }

        $this->sendJsonRpcResult([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'run_id' => $runId,
                    'active_sessions' => $sessions,
                    'count' => count($sessions)
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ], $id);
    }

    /**
     * Generate a URL-safe slug from a name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'pipeline-' . time();
    }

    /**
     * Sanitize a user-provided slug
     */
    private function sanitizeSlug(string $slug): string {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'pipeline-' . time();
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
