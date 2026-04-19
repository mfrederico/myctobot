<?php
/**
 * MCP Pipelines Controller - Pipeline Execution via MCP
 *
 * Provides MCP tools for managing and executing pipelines from Claude Code.
 * Uses fastmcphp Server for JSON-RPC 2.0 dispatch (same pattern as Mcp.php).
 *
 * Pipelines with expose_as_tool=1 are exposed as myctobot_{slug} tools.
 *
 * Workspace resolution (in priority order):
 *   1. Subdomain: https://{workspace}.myctobot.ai/mcp/pipelines
 *   2. X-Workspace header (allows single .mcp.json entry for all tenants)
 *
 * Handler logic in services:
 * - PipelineToolService: All pipeline/run/schedule/inter-agent operations
 * - KnowledgeBaseToolService: RAG search and query tools
 * - ComponentRegistryService: Workspace manifest
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;
use app\services\McpGatewayAuthProvider;
use app\services\ApiAuthService;
use app\services\PipelineToolService;
use app\services\PipelineToolException;
use app\services\KnowledgeBaseToolService;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Protocol\Request as JsonRpcRequest;
use app\ManualTool;

// Load services
require_once __DIR__ . '/../services/ApiAuthService.php';
require_once __DIR__ . '/../services/PipelineToolService.php';
require_once __DIR__ . '/../services/KnowledgeBaseToolService.php';
require_once __DIR__ . '/../services/ComponentRegistryService.php';

class Mcppipelines extends Control
{
    private const SERVER_NAME = 'myctobot-pipelines';
    private const SERVER_VERSION = '2.0.0';

    private ?int $memberId = null;
    private ?string $workspace = null;
    protected $logger;

    public function __construct()
    {
        // Don't call parent - MCP requests don't have sessions
        $this->logger = Flight::get('log');
        // Workspace from subdomain (set by front controller) or X-Workspace header
        $this->workspace = $_SERVER['WORKSPACE']
            ?? $_SERVER['HTTP_X_WORKSPACE']
            ?? null;
    }

    /**
     * MCP JSON-RPC endpoint for pipelines
     * POST /mcp/pipelines
     */
    public function index()
    {
        // CORS headers
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, 'Method not allowed');
            return;
        }

        // Require workspace from subdomain or header
        if (empty($this->workspace)) {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, 'Workspace required. Use subdomain (https://{workspace}.myctobot.ai/mcp/pipelines) or X-Workspace header');
            return;
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($this->workspace)) {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, "Invalid workspace: {$this->workspace}");
            return;
        }

        // Parse JSON-RPC request
        $body = file_get_contents('php://input');

        try {
            $message = JsonRpc::parse($body);
        } catch (JsonRpcException $e) {
            echo $e->toJson();
            return;
        }

        $this->logger->debug("MCP Pipelines: method=" . $message->method . ", workspace={$this->workspace}");

        // Build auth provider
        $authProvider = new McpGatewayAuthProvider();

        // Build server
        $server = new Server(
            name: self::SERVER_NAME,
            version: self::SERVER_VERSION,
            instructions: 'MyCTOBot Pipeline tools. Authenticate with Bearer tk_ token.',
            logger: $this->logger,
        );

        $server->setAuthProvider($authProvider, required: true);

        // Register all tools
        $workspace = $this->workspace;
        $logger = $this->logger;

        // Register static tool definitions (pipeline CRUD, runs, schedules, inter-agent, KB, manifest)
        $defs = $this->getToolDefinitions();
        foreach ($defs as $def) {
            $toolName = $def['name'];
            $server->addTool(new ManualTool(
                $def['name'],
                $def['description'],
                $def['inputSchema'],
                function (array $args) use ($toolName, $authProvider, $workspace, $logger) {
                    return $this->dispatchTool($toolName, $args, $authProvider, $workspace, $logger);
                }
            ));
        }

        // Register dynamic pipeline tools (myctobot_*)
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
            if (isset($inputSchema['properties']) && is_array($inputSchema['properties']) && empty($inputSchema['properties'])) {
                $inputSchema['properties'] = (object) [];
            }

            $toolName = 'myctobot_' . $pipeline->slug;
            $pipelineSlug = $pipeline->slug;
            $server->addTool(new ManualTool(
                $toolName,
                $pipeline->description ?: "Execute the {$pipeline->name} pipeline",
                $inputSchema,
                function (array $args) use ($pipelineSlug, $authProvider, $workspace, $logger) {
                    $svc = new PipelineToolService($workspace, $authProvider->getMemberId(), $logger);
                    $result = $svc->executePipeline($pipelineSlug, $args);
                    $isError = !empty($result['isError']);
                    $text = $result['content'][0]['text'] ?? json_encode($result, JSON_PRETTY_PRINT);
                    return $isError
                        ? \Fastmcphp\Tools\ToolResult::error($text)
                        : \Fastmcphp\Tools\ToolResult::text($text);
                }
            ));
        }

        // Build auth request from HTTP headers
        $authRequest = AuthRequest::fromHttp(getallheaders() ?: [], $_GET, $body);

        // HTTP transport is stateless — auto-initialize so tools/call works
        // without requiring a separate initialize request per connection.
        if ($message->method !== 'initialize') {
            $server->handle(new JsonRpcRequest(id: '_init', method: 'initialize', params: [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'http-auto', 'version' => '1.0'],
            ]));
        }

        // Handle and respond
        $response = $server->handle($message, $authRequest);

        if ($response !== null) {
            echo $response;
        }
    }

    /**
     * Dispatch a tool call to the appropriate service
     */
    private function dispatchTool(string $toolName, array $args, McpGatewayAuthProvider $authProvider, string $workspace, $logger): \Fastmcphp\Tools\ToolResult
    {
        $memberId = $authProvider->getMemberId();
        $svc = new PipelineToolService($workspace, $memberId, $logger);

        $result = match($toolName) {
            // Pipeline CRUD
            'list_pipelines' => $svc->listPipelines($args),
            'get_pipeline_components' => $svc->getPipelineComponents($args),
            'get_pipeline' => $svc->getPipeline($args),
            'set_pipeline' => $svc->setPipeline($args),
            'delete_pipeline' => $svc->deletePipeline($args),

            // Step CRUD
            'get_step' => $svc->getStep($args),
            'set_step' => $svc->setStep($args),
            'delete_step' => $svc->deleteStep($args),

            // Run operations
            'run_pipeline' => $svc->runPipeline($args),
            'continue_pipeline' => $svc->continuePipeline($args),
            'schedule_pipeline' => $svc->schedulePipeline($args),
            'list_runs' => $svc->listRuns($args),
            'get_run' => $svc->getRun($args),
            'cancel_run' => $svc->cancelRun($args),

            // Export/import/clone
            'export_pipeline' => $svc->exportPipeline($args),
            'import_pipeline' => $svc->importPipeline($args),
            'clone_pipeline' => $svc->clonePipeline($args),

            // Schedule management
            'create_schedule' => $svc->createSchedule($args),
            'list_schedules' => $svc->listSchedules($args),
            'get_schedule' => $svc->getSchedule($args),
            'update_schedule' => $svc->updateSchedule($args),
            'delete_schedule' => $svc->deleteSchedule($args),

            // Inter-agent communication
            'send_to_step' => $svc->sendToStep($args),
            'get_run_context' => $svc->getRunContext($args),
            'update_run_context' => $svc->updateRunContext($args),
            'mark_step_complete' => $svc->markStepComplete($args),
            'list_run_sessions' => $svc->listRunSessions($args),
            'post_message' => $svc->postMessage($args),

            // Knowledge base tools
            'list_knowledge_bases' => (new KnowledgeBaseToolService($workspace, $logger))->listKnowledgeBases($args),
            'search_knowledge_base' => (new KnowledgeBaseToolService($workspace, $logger))->searchKnowledgeBase($args),
            'query_knowledge_base' => (new KnowledgeBaseToolService($workspace, $logger))->queryKnowledgeBase($args),

            // Component registry
            'get_workspace_manifest' => (new \app\services\ComponentRegistryService($workspace, $logger))->getWorkspaceManifest($args),

            default => throw new \RuntimeException("Unknown tool: {$toolName}"),
        };

        // Services return raw MCP content format: ['content' => [['type' => 'text', 'text' => '...']], 'isError' => false]
        // Convert to ToolResult so fastmcphp Server serializes correctly (no double-wrapping)
        $isError = !empty($result['isError']);
        $text = $result['content'][0]['text'] ?? json_encode($result, JSON_PRETTY_PRINT);
        return $isError
            ? \Fastmcphp\Tools\ToolResult::error($text)
            : \Fastmcphp\Tools\ToolResult::text($text);
    }

    /**
     * Get all static tool definitions (inlined from former traits)
     */
    private function getToolDefinitions(): array
    {
        return array_merge(
            $this->getPipelineToolDefinitions(),
            $this->getRunToolDefinitions(),
            $this->getScheduleToolDefinitions(),
            $this->getInterAgentToolDefinitions(),
            KnowledgeBaseToolService::getToolDefinitions(),
            $this->getComponentRegistryToolDefinitions(),
        );
    }

    // =========================================================================
    // Pipeline CRUD tool definitions
    // =========================================================================

    private function getPipelineToolDefinitions(): array
    {
        return [
            [
                'name' => 'list_pipelines',
                'description' => 'List all pipelines in your workspace. Returns basic info: id, name, slug, trigger_type, is_active, step_count.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'include_inactive' => ['type' => 'boolean', 'description' => 'Include inactive pipelines. Default: false']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'get_pipeline_components',
                'description' => 'Get all available step types, their configuration schemas, and your integrations. Use this before creating a pipeline to understand what building blocks are available.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []]
            ],
            [
                'name' => 'get_pipeline',
                'description' => 'Get pipeline details including its steps. Use this to inspect a pipeline before modifying it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID to retrieve'],
                        'slug' => ['type' => 'string', 'description' => 'The pipeline slug (alternative to pipeline_id)'],
                        'include_steps' => ['type' => 'boolean', 'description' => 'Include step details in response. Default: true']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'get_step',
                'description' => 'Get the configuration of a pipeline step. Use this to inspect current settings before updating.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'step_id' => ['type' => 'integer', 'description' => 'The step ID to retrieve'],
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID (use with step_name)'],
                        'pipeline_slug' => ['type' => 'string', 'description' => 'The pipeline slug (use with step_name, alternative to pipeline_id)'],
                        'step_name' => ['type' => 'string', 'description' => 'The step name to retrieve (use with pipeline_id or pipeline_slug)']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'set_step',
                'description' => 'Create or update a pipeline step. If step_id is provided, updates existing step. Use this for quick changes to step settings, config, or routing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'step_id' => ['type' => 'integer', 'description' => 'The step ID to update'],
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID (use with step_name)'],
                        'pipeline_slug' => ['type' => 'string', 'description' => 'The pipeline slug (use with step_name, alternative to pipeline_id)'],
                        'step_name' => ['type' => 'string', 'description' => 'The step name to update (use with pipeline_id or pipeline_slug)'],
                        'label' => ['type' => 'string', 'description' => 'New display label'],
                        'step_type' => ['type' => 'string', 'description' => 'New step type (e.g., llm_call, direct_exec, switch)'],
                        'config' => ['type' => 'object', 'description' => 'New step configuration (replaces config_json)'],
                        'on_success' => ['type' => 'string', 'description' => 'Action on success: next_col, goto:step_name, exit, dynamic'],
                        'on_failure' => ['type' => 'string', 'description' => 'Action on failure: exit, goto:step_name, retry'],
                        'row' => ['type' => 'integer', 'description' => 'Grid row (0-based)'],
                        'col' => ['type' => 'integer', 'description' => 'Grid column (0-based)'],
                        'sequence' => ['type' => 'integer', 'description' => 'Execution sequence number'],
                        'is_active' => ['type' => 'boolean', 'description' => 'Whether the step is active'],
                        'timeout_seconds' => ['type' => 'integer', 'description' => 'Step timeout in seconds']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'set_pipeline',
                'description' => 'Create or update a pipeline. If pipeline_id is provided, updates existing pipeline. Otherwise creates new. Use get_pipeline_building_blocks first to understand available step types.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'Pipeline ID to update. If omitted, creates a new pipeline.'],
                        'name' => ['type' => 'string', 'description' => 'Human-readable pipeline name'],
                        'slug' => ['type' => 'string', 'description' => 'Optional URL-friendly slug. Auto-generated from name if omitted.'],
                        'description' => ['type' => 'string', 'description' => 'Description of what the pipeline does'],
                        'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Column headers for the pipeline grid (e.g., ["Fetch", "Process", "Notify"])'],
                        'trigger_type' => ['type' => 'string', 'enum' => ['manual', 'webhook', 'cron'], 'description' => 'How the pipeline is triggered. Default: manual'],
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
                        'expose_as_tool' => ['type' => 'boolean', 'description' => 'Expose this pipeline as an MCP tool. Default: false'],
                        'input_schema' => ['type' => 'object', 'description' => 'JSON Schema for pipeline inputs (required if expose_as_tool is true)'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate only without creating. Returns validation result.']
                    ],
                    'required' => ['name', 'steps']
                ]
            ],
            [
                'name' => 'delete_pipeline',
                'description' => 'Delete a pipeline and all its steps. This is destructive and cannot be undone.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID to delete'],
                        'slug' => ['type' => 'string', 'description' => 'The pipeline slug (alternative to pipeline_id)'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to confirm deletion']
                    ],
                    'required' => ['confirm']
                ]
            ],
            [
                'name' => 'delete_step',
                'description' => 'Delete a step from a pipeline.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'step_id' => ['type' => 'integer', 'description' => 'The step ID to delete'],
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID (use with step_name)'],
                        'pipeline_slug' => ['type' => 'string', 'description' => 'The pipeline slug (use with step_name)'],
                        'step_name' => ['type' => 'string', 'description' => 'The step name to delete (use with pipeline_id or pipeline_slug)']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'export_pipeline',
                'description' => 'Export a pipeline as JSON. Use this to backup, duplicate, or transfer pipelines.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID to export'],
                        'slug' => ['type' => 'string', 'description' => 'The pipeline slug (alternative to pipeline_id)']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'import_pipeline',
                'description' => 'Import a pipeline from JSON export. Use this to duplicate or transfer pipelines. The JSON should match the format from export_pipeline.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_json' => ['type' => 'object', 'description' => 'The pipeline export JSON object (from export_pipeline)'],
                        'new_name' => ['type' => 'string', 'description' => 'Optional new name for the imported pipeline (to avoid conflicts)'],
                        'new_slug' => ['type' => 'string', 'description' => 'Optional new slug for the imported pipeline (to avoid conflicts)'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate only without importing. Returns validation result.']
                    ],
                    'required' => ['pipeline_json']
                ]
            ],
            [
                'name' => 'clone_pipeline',
                'description' => 'Clone an existing pipeline with optional customizations. Creates a new pipeline from an existing one, optionally overriding step configs and pipeline settings. Use this to create tenant-specific versions of template pipelines.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'Source pipeline ID to clone'],
                        'slug' => ['type' => 'string', 'description' => 'Source pipeline slug (alternative to pipeline_id)'],
                        'new_name' => ['type' => 'string', 'description' => 'Name for the cloned pipeline'],
                        'new_slug' => ['type' => 'string', 'description' => 'Slug for the cloned pipeline'],
                        'step_overrides' => ['type' => 'object', 'description' => 'Map of step_name to partial overrides. Use "config" key for config_json merge. Example: {"classify_intent": {"config": {"system_prompt": "..."}}}'],
                        'pipeline_overrides' => ['type' => 'object', 'description' => 'Partial pipeline-level overrides. Example: {"description": "...", "expose_as_tool": true}']
                    ],
                    'required' => []
                ]
            ],
        ];
    }

    // =========================================================================
    // Run management tool definitions
    // =========================================================================

    private function getRunToolDefinitions(): array
    {
        return [
            [
                'name' => 'run_pipeline',
                'description' => 'Trigger a pipeline run. Returns immediately with run_id for tracking.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'The pipeline ID to run'],
                        'slug' => ['type' => 'string', 'description' => 'The pipeline slug (alternative to pipeline_id)'],
                        'input' => ['type' => 'object', 'description' => 'Input data to pass to the pipeline context'],
                        'entry_step' => ['type' => 'string', 'description' => 'Optional step name to start from (skips earlier steps)']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'continue_pipeline',
                'description' => 'Continue a paused pipeline by providing the requested input. Use this when a pipeline returns awaiting_input status.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID to continue'],
                        'input_token' => ['type' => 'string', 'description' => 'The input token returned when pipeline paused'],
                        'input' => ['type' => 'object', 'description' => 'The input data matching the awaited schema']
                    ],
                    'required' => ['run_id', 'input_token', 'input']
                ]
            ],
            [
                'name' => 'schedule_pipeline',
                'description' => 'Schedule a pipeline to run at a future time. Use this for reminders, delayed actions, or recurring tasks. Examples: "in 1 hour", "at 11:30 EST", "in 30 minutes".',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_slug' => ['type' => 'string', 'description' => 'The pipeline slug to schedule (e.g., "multi-turn-input-test")'],
                        'delay_seconds' => ['type' => 'integer', 'description' => 'Delay in seconds until the pipeline runs. Use this OR scheduled_time.'],
                        'scheduled_time' => ['type' => 'string', 'description' => 'ISO 8601 datetime or natural time like "2026-01-30T11:30:00-05:00". Use this OR delay_seconds.'],
                        'input_data' => ['type' => 'object', 'description' => 'Optional context data to pass to the pipeline'],
                        'entry_step' => ['type' => 'string', 'description' => 'Optional step name to start execution from (skips earlier steps)'],
                        'description' => ['type' => 'string', 'description' => 'Optional description of what this scheduled task is for']
                    ],
                    'required' => ['pipeline_slug']
                ]
            ],
            [
                'name' => 'list_runs',
                'description' => 'List pipeline runs. Filter by pipeline, status, or date range.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'Filter by pipeline ID'],
                        'pipeline_slug' => ['type' => 'string', 'description' => 'Filter by pipeline slug'],
                        'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'completed', 'failed', 'awaiting_input', 'cancelled'], 'description' => 'Filter by status'],
                        'limit' => ['type' => 'integer', 'description' => 'Max runs to return. Default: 20']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'get_run',
                'description' => 'Get detailed information about a pipeline run, including step statuses and output.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID'],
                        'include_step_runs' => ['type' => 'boolean', 'description' => 'Include individual step run details. Default: true'],
                        'include_context' => ['type' => 'boolean', 'description' => 'Include full context JSON. Default: false']
                    ],
                    'required' => ['run_id']
                ]
            ],
            [
                'name' => 'cancel_run',
                'description' => 'Cancel a running pipeline. Marks the run as cancelled and attempts to stop active processes.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID to cancel'],
                        'reason' => ['type' => 'string', 'description' => 'Optional reason for cancellation']
                    ],
                    'required' => ['run_id']
                ]
            ],
        ];
    }

    // =========================================================================
    // Schedule management tool definitions
    // =========================================================================

    private function getScheduleToolDefinitions(): array
    {
        return [
            [
                'name' => 'create_schedule',
                'description' => 'Create a recurring schedule for a pipeline. Supports daily, weekly, monthly, hourly, minutely, or cron expressions. The schedule persists and survives failures/restarts.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'Pipeline ID to schedule'],
                        'pipeline_slug' => ['type' => 'string', 'description' => 'Pipeline slug (alternative to pipeline_id)'],
                        'name' => ['type' => 'string', 'description' => 'Name for this schedule (e.g., "Daily morning email")'],
                        'schedule_type' => ['type' => 'string', 'enum' => ['once', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'cron'], 'description' => 'Type of schedule'],
                        'schedule_config' => ['type' => 'object', 'description' => 'Schedule configuration. For daily: {hour, minute}. For weekly: {hour, minute, days_of_week: [0-6]}. For cron: {cron_expression}. For once: {datetime}.'],
                        'timezone' => ['type' => 'string', 'description' => 'Timezone (e.g., "America/New_York"). Default: UTC'],
                        'max_concurrent' => ['type' => 'integer', 'description' => 'Max concurrent runs allowed. Default: 1'],
                        'on_overlap' => ['type' => 'string', 'enum' => ['skip', 'queue', 'allow'], 'description' => 'What to do if previous run still running. Default: skip'],
                        'input_data' => ['type' => 'object', 'description' => 'Input data to pass to the pipeline context']
                    ],
                    'required' => ['name', 'schedule_type']
                ]
            ],
            [
                'name' => 'list_schedules',
                'description' => 'List all recurring schedules.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline_id' => ['type' => 'integer', 'description' => 'Filter by pipeline ID'],
                        'pipeline_slug' => ['type' => 'string', 'description' => 'Filter by pipeline slug'],
                        'include_inactive' => ['type' => 'boolean', 'description' => 'Include inactive schedules. Default: false']
                    ],
                    'required' => []
                ]
            ],
            [
                'name' => 'get_schedule',
                'description' => 'Get details of a specific schedule including next run times.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'schedule_id' => ['type' => 'integer', 'description' => 'Schedule ID'],
                        'preview_runs' => ['type' => 'integer', 'description' => 'Number of future runs to preview. Default: 5']
                    ],
                    'required' => ['schedule_id']
                ]
            ],
            [
                'name' => 'update_schedule',
                'description' => 'Update an existing schedule.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'schedule_id' => ['type' => 'integer', 'description' => 'Schedule ID to update'],
                        'name' => ['type' => 'string', 'description' => 'New name'],
                        'schedule_type' => ['type' => 'string', 'enum' => ['once', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'cron']],
                        'schedule_config' => ['type' => 'object', 'description' => 'New schedule configuration'],
                        'timezone' => ['type' => 'string'],
                        'max_concurrent' => ['type' => 'integer'],
                        'on_overlap' => ['type' => 'string', 'enum' => ['skip', 'queue', 'allow']],
                        'is_active' => ['type' => 'boolean', 'description' => 'Enable or disable the schedule']
                    ],
                    'required' => ['schedule_id']
                ]
            ],
            [
                'name' => 'delete_schedule',
                'description' => 'Delete a recurring schedule.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'schedule_id' => ['type' => 'integer', 'description' => 'Schedule ID to delete']
                    ],
                    'required' => ['schedule_id']
                ]
            ],
        ];
    }

    // =========================================================================
    // Inter-agent communication tool definitions
    // =========================================================================

    private function getInterAgentToolDefinitions(): array
    {
        return [
            [
                'name' => 'send_to_step',
                'description' => 'Send a message/command to another step\'s resident Claude session. Use this for inter-agent communication within a pipeline run.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID'],
                        'step_name' => ['type' => 'string', 'description' => 'The target step name (e.g., "analyze_data", "fetch_customers")'],
                        'message' => ['type' => 'string', 'description' => 'The message or command to send to the Claude session']
                    ],
                    'required' => ['run_id', 'step_name', 'message']
                ]
            ],
            [
                'name' => 'get_run_context',
                'description' => 'Get the current pipeline run\'s shared context. This is the shared state between all steps and agents.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID'],
                        'key' => ['type' => 'string', 'description' => 'Optional specific key to retrieve. If omitted, returns entire context.']
                    ],
                    'required' => ['run_id']
                ]
            ],
            [
                'name' => 'update_run_context',
                'description' => 'Update the pipeline run\'s shared context. Use this to share data between agents.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID'],
                        'key' => ['type' => 'string', 'description' => 'The context key to update'],
                        'value' => ['type' => 'string', 'description' => 'The value to set (JSON-encoded string for complex types)']
                    ],
                    'required' => ['run_id', 'key', 'value']
                ]
            ],
            [
                'name' => 'mark_step_complete',
                'description' => 'Mark the current step as complete. Use this when you\'ve finished your assigned task in a resident session.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID'],
                        'step_name' => ['type' => 'string', 'description' => 'The step name to mark complete'],
                        'output' => ['type' => 'object', 'description' => 'Optional output data from this step'],
                        'summary' => ['type' => 'string', 'description' => 'Brief summary of what was accomplished']
                    ],
                    'required' => ['run_id', 'step_name']
                ]
            ],
            [
                'name' => 'list_run_sessions',
                'description' => 'List all active Claude sessions for a pipeline run. Shows which agents are currently running.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID']
                    ],
                    'required' => ['run_id']
                ]
            ],
            [
                'name' => 'post_message',
                'description' => 'Post a status update or message to the pipeline chat. Use this to communicate progress to the user.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer', 'description' => 'The pipeline run ID'],
                        'message' => ['type' => 'string', 'description' => 'The message to post']
                    ],
                    'required' => ['run_id', 'message']
                ]
            ],
        ];
    }

    // =========================================================================
    // Component registry tool definitions
    // =========================================================================

    private function getComponentRegistryToolDefinitions(): array
    {
        return [
            [
                'name' => 'get_workspace_manifest',
                'description' => 'Returns a complete manifest of everything in the workspace: connections, pipelines, dapps, knowledge bases, templates, step types, and connectors. Use this to discover what already exists before building new components.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'sections' => [
                            'type' => 'array',
                            'description' => 'Optional filter. Default: all sections. Available: workspace_info, connections, pipelines, dapps, knowledge_bases, pipeline_templates, app_templates, step_types, connectors, summary',
                            'items' => ['type' => 'string']
                        ]
                    ],
                    'required' => []
                ]
            ]
        ];
    }
}
