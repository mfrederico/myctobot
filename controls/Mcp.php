<?php
/**
 * MCP (Model Context Protocol) Controller
 *
 * Handles JSON-RPC 2.0 requests for MCP tools using fastmcphp Server.
 * Workspace is determined from subdomain via $_SERVER['WORKSPACE'].
 *
 * Endpoints (via DefaultRoute auto-routing):
 *   POST /mcp           - Main gateway (index method)
 *   POST /mcp/jira      - Jira tools (jira method -> Mcpjira controller)
 *   POST /mcp/jobs      - Job callbacks (jobs method -> Mcpjobs controller)
 *   POST /mcp/pipelines - Pipeline execution (pipelines method -> Mcppipelines controller)
 *
 * Auth: Bearer token (tk_xxx)
 *
 * Usage in .mcp.json:
 *   {
 *     "mcpServers": {
 *       "myctobot": {
 *         "type": "http",
 *         "url": "https://gwt.myctobot.ai/mcp",
 *         "headers": {
 *           "Authorization": "Bearer {api_key}"
 *         }
 *       }
 *     }
 *   }
 */

namespace app;

use Flight;
use app\BaseControls\Control;
use app\services\McpGatewayAuthProvider;
use app\services\McpGatewayToolService;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Protocol\Request as JsonRpcRequest;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/JiraClient.php';
require_once __DIR__ . '/../services/GitHubClient.php';

class Mcp extends Control {

    private const SERVER_NAME = 'MyCTOBot Gateway';
    private const SERVER_VERSION = '1.0.0';

    private ?string $workspace = null;
    protected $logger;

    public function __construct() {
        // Don't call parent - MCP requests are stateless (no session)
        $this->logger = Flight::get('log');
        // Workspace from subdomain - set by front controller in public/index.php
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;
    }

    // =========================================================================
    // Sub-endpoint delegation (lazy loading pattern)
    // /mcp/jira -> jira() -> Mcpjira controller
    // /mcp/jobs -> jobs() -> Mcpjobs controller
    // /mcp/pipelines -> pipelines() -> Mcppipelines controller
    // =========================================================================

    /**
     * Jira MCP tools endpoint
     * POST /mcp/jira
     */
    public function jira(): void {
        $controller = new Mcpjira();
        $controller->index();
    }

    /**
     * Job status callbacks endpoint
     * POST /mcp/jobs
     */
    public function jobs(): void {
        $controller = new Mcpjobs();
        $controller->index();
    }

    /**
     * Pipeline execution endpoint
     * POST /mcp/pipelines
     */
    public function pipelines(): void {
        $controller = new Mcppipelines();
        $controller->index();
    }

    /**
     * CRM tools endpoint
     * POST /mcp/crm
     */
    public function crm(): void {
        $controller = new Mcpcrm();
        $controller->index();
    }

    /**
     * Main MCP endpoint - handles all JSON-RPC requests via fastmcphp Server
     * POST /mcp
     */
    public function index() {
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

        // Require workspace from subdomain
        if (empty($this->workspace)) {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, 'Workspace required. Use https://{workspace}.myctobot.ai/mcp');
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

        $this->logger->debug("MCP Gateway: method=" . $message->method . ", workspace={$this->workspace}");

        // Build auth provider (stores member info for tool handlers)
        $authProvider = new McpGatewayAuthProvider();

        // Build server
        $server = new Server(
            name: self::SERVER_NAME,
            version: self::SERVER_VERSION,
            instructions: 'MyCTOBot MCP Gateway. Authenticate with Bearer tk_ token.',
            logger: $this->logger,
        );

        $server->setAuthProvider($authProvider, required: true);

        // Register tools from definitions
        $workspace = $this->workspace;
        $logger = $this->logger;

        foreach ($this->getToolDefinitions() as $def) {
            $toolName = $def['name'];
            $server->addTool(new ManualTool(
                $def['name'],
                $def['description'],
                $def['inputSchema'],
                function (array $args) use ($toolName, $authProvider, $workspace, $logger) {
                    // Lazy-create tool service with authenticated member
                    $service = new McpGatewayToolService(
                        $authProvider->getMemberId(),
                        $authProvider->getMemberBean(),
                        $workspace,
                        $logger,
                    );
                    return $service->dispatch($toolName, $args);
                }
            ));
        }

        // Build auth request from HTTP headers
        $authRequest = AuthRequest::fromHttp(getallheaders() ?: [], $_GET, $body);

        // HTTP transport is stateless — auto-initialize the Server so tools/call works
        // without requiring a separate initialize request per connection.
        // Real initialize requests still work and return capabilities normally.
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
     * Get all available tool definitions
     */
    private function getToolDefinitions(): array {
        return [
            // Jira tools
            [
                'name' => 'jira_get_issue',
                'description' => 'Get details of a Jira issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Jira issue key (e.g., PROJ-123)'],
                    ],
                    'required' => ['issue_key'],
                ],
            ],
            [
                'name' => 'jira_comment',
                'description' => 'Post a comment to a Jira issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Jira issue key'],
                        'message' => ['type' => 'string', 'description' => 'Comment text'],
                    ],
                    'required' => ['issue_key', 'message'],
                ],
            ],
            [
                'name' => 'jira_transition',
                'description' => 'Transition a Jira issue to a new status',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Jira issue key'],
                        'status_name' => ['type' => 'string', 'description' => 'Target status name'],
                    ],
                    'required' => ['issue_key', 'status_name'],
                ],
            ],
            [
                'name' => 'jira_get_transitions',
                'description' => 'Get available transitions for a Jira issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Jira issue key'],
                    ],
                    'required' => ['issue_key'],
                ],
            ],
            // GitHub tools
            [
                'name' => 'github_get_issue',
                'description' => 'Get details of a GitHub issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Issue key (owner/repo#123)'],
                    ],
                    'required' => ['issue_key'],
                ],
            ],
            [
                'name' => 'github_comment',
                'description' => 'Post a comment to a GitHub issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Issue key (owner/repo#123)'],
                        'message' => ['type' => 'string', 'description' => 'Comment text'],
                    ],
                    'required' => ['issue_key', 'message'],
                ],
            ],
            [
                'name' => 'github_close_issue',
                'description' => 'Close a GitHub issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Issue key (owner/repo#123)'],
                        'comment' => ['type' => 'string', 'description' => 'Optional closing comment'],
                    ],
                    'required' => ['issue_key'],
                ],
            ],
            [
                'name' => 'github_add_labels',
                'description' => 'Add labels to a GitHub issue',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string', 'description' => 'Issue key (owner/repo#123)'],
                        'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Labels to add'],
                    ],
                    'required' => ['issue_key', 'labels'],
                ],
            ],
            [
                'name' => 'github_create_pr',
                'description' => 'Create a pull request',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'repo' => ['type' => 'string', 'description' => 'Repository (owner/repo)'],
                        'title' => ['type' => 'string', 'description' => 'PR title'],
                        'head' => ['type' => 'string', 'description' => 'Source branch'],
                        'base' => ['type' => 'string', 'description' => 'Target branch', 'default' => 'main'],
                        'body' => ['type' => 'string', 'description' => 'PR description', 'default' => ''],
                    ],
                    'required' => ['repo', 'title', 'head'],
                ],
            ],
            // Gateway info
            [
                'name' => 'gateway_info',
                'description' => 'Get information about this MCP gateway',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            // Knowledge Base tools
            [
                'name' => 'kb_query',
                'description' => 'Query a knowledge base with a question and get an AI-generated answer based on the documents',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'description' => 'The question to ask the knowledge base'],
                        'kb_slug' => ['type' => 'string', 'description' => 'Optional: specific knowledge base slug to query. If not provided, queries all workspace knowledge.'],
                        'similarity_threshold' => ['type' => 'number', 'description' => 'Optional: minimum similarity score (0-1, default 0.4)'],
                        'max_results' => ['type' => 'integer', 'description' => 'Optional: maximum number of source chunks to return (default 5)'],
                    ],
                    'required' => ['question'],
                ],
            ],
            [
                'name' => 'kb_list',
                'description' => 'List all available knowledge bases in the workspace',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'kb_search',
                'description' => 'Search knowledge base documents and return matching chunks without AI interpretation',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query'],
                        'kb_slug' => ['type' => 'string', 'description' => 'Optional: specific knowledge base slug to search'],
                        'limit' => ['type' => 'integer', 'description' => 'Optional: maximum results (default 10)'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }
}
