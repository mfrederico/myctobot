<?php
/**
 * MCP Pipelines Controller - Pipeline Execution via MCP
 *
 * Provides MCP tools for executing pipelines from Claude Code.
 * Pipelines with expose_as_tool=1 are exposed as myctobot_{slug} tools.
 *
 * Usage in .mcp.json:
 *   {
 *     "mcpServers": {
 *       "pipelines": {
 *         "type": "http",
 *         "url": "https://myctobot.ai/mcp/gwt/pipelines",
 *         "headers": {
 *           "Authorization": "Bearer {api_key}"
 *         }
 *       }
 *     }
 *   }
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
    }

    /**
     * MCP JSON-RPC endpoint for pipelines
     * POST /mcp/{workspace}/pipelines
     *
     * @param string $workspace Workspace slug from URL
     */
    public function index(string $workspace = null) {
        $this->workspace = $workspace;

        if (empty($workspace)) {
            $this->sendJsonRpcError(-32600, 'Workspace required in URL', null);
            return;
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            $this->sendJsonRpcError(-32600, "Invalid workspace: {$workspace}", null);
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

        $this->logger->debug("MCP Pipelines: method={$method}, workspace={$workspace}");

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

        // All tools here are pipelines (prefixed with myctobot_)
        if (!str_starts_with($toolName, 'myctobot_')) {
            $this->sendJsonRpcError(-32602, "Unknown tool: {$toolName}", $id);
            return;
        }

        $this->executePipeline($toolName, $arguments, $id);
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
