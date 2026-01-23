<?php
/**
 * MCP (Model Context Protocol) Controller
 *
 * Handles JSON-RPC 2.0 requests for MCP tools.
 * Workspace is determined from subdomain via $_SERVER['PUBLIC_ID'].
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
use app\services\ApiAuthService;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/JiraClient.php';
require_once __DIR__ . '/../services/GitHubClient.php';

class Mcp extends Control {

    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'MyCTOBot Gateway';
    private const SERVER_VERSION = '1.0.0';

    private ?int $memberId = null;
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
     * Main MCP endpoint - handles all JSON-RPC requests
     * POST /mcp
     */
    public function index() {
        // Set JSON-RPC headers
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
            $this->sendJsonRpcError(-32600, 'Method not allowed', null);
            return;
        }

        // Require workspace from subdomain
        if (empty($this->workspace)) {
            $this->sendJsonRpcError(-32600, 'Workspace required. Use https://{workspace}.myctobot.ai/mcp', null);
            return;
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($this->workspace)) {
            $this->sendJsonRpcError(-32600, "Invalid workspace: {$this->workspace}", null);
            return;
        }

        // Parse JSON-RPC request
        $body = file_get_contents('php://input');
        $request = json_decode($body, true);

        if (!$request || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            $this->sendJsonRpcError(-32700, 'Parse error', null);
            return;
        }

        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'none';
        $this->logger->debug("MCP Gateway: method={$method}, workspace={$this->workspace}, auth_header=" . substr($authHeader, 0, 30) . "...");

        // Route to handler
        switch ($method) {
            case 'initialize':
                $this->handleInitialize($id, $params);
                break;
            case 'notifications/initialized':
                $this->sendJsonRpcResult(['acknowledged' => true], $id);
                break;
            case 'ping':
                $this->sendJsonRpcResult(['pong' => true], $id);
                break;
            case 'tools/list':
                $this->handleToolsList($id);
                break;
            case 'tools/call':
                $this->handleToolsCall($id, $params);
                break;
            default:
                $this->sendJsonRpcError(-32601, "Method not found: {$method}", $id);
        }
    }

    /**
     * Handle initialize request (no auth required)
     */
    private function handleInitialize($id, array $params): void {
        // Cast to objects for MCP protocol compliance
        $this->sendJsonRpcResult((object)[
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object)[
                'tools' => (object)['listChanged' => false],
            ],
            'serverInfo' => (object)[
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'MyCTOBot MCP Gateway. Authenticate with Bearer tk_ token.',
        ], $id);
    }

    /**
     * Handle tools/list request (requires auth)
     */
    private function handleToolsList($id): void {
        $authResult = ApiAuthService::authenticate('mcp', 'tools');

        if (!$authResult['success']) {
            $this->sendJsonRpcError(-32000, $authResult['error'], $id);
            return;
        }

        $this->memberId = $authResult['member']->id;
        $tools = $this->getToolDefinitions();
        // Cast to object for MCP protocol compliance
        $this->sendJsonRpcResult((object)['tools' => $tools], $id);
    }

    /**
     * Handle tools/call request (requires auth)
     */
    private function handleToolsCall($id, array $params): void {
        $authResult = ApiAuthService::authenticate('mcp', 'tools');

        if (!$authResult['success']) {
            $this->sendJsonRpcError(-32000, $authResult['error'], $id);
            return;
        }

        $this->memberId = $authResult['member']->id;
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($name)) {
            $this->sendJsonRpcError(-32602, 'Missing tool name', $id);
            return;
        }

        try {
            $result = $this->executeTool($name, $arguments);
            $this->sendJsonRpcResult([
                'content' => [
                    ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT)]
                ],
            ], $id);
        } catch (\Exception $e) {
            $this->sendJsonRpcResult([
                'content' => [
                    ['type' => 'text', 'text' => $e->getMessage()]
                ],
                'isError' => true,
            ], $id);
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
                    'properties' => [],
                ],
            ],
        ];
    }

    /**
     * Execute a tool by name
     */
    private function executeTool(string $name, array $args): mixed {
        return match ($name) {
            'jira_get_issue' => $this->toolJiraGetIssue($args),
            'jira_comment' => $this->toolJiraComment($args),
            'jira_transition' => $this->toolJiraTransition($args),
            'jira_get_transitions' => $this->toolJiraGetTransitions($args),
            'github_get_issue' => $this->toolGitHubGetIssue($args),
            'github_comment' => $this->toolGitHubComment($args),
            'github_close_issue' => $this->toolGitHubCloseIssue($args),
            'github_add_labels' => $this->toolGitHubAddLabels($args),
            'github_create_pr' => $this->toolGitHubCreatePR($args),
            'gateway_info' => $this->toolGatewayInfo($args),
            default => throw new \Exception("Unknown tool: {$name}"),
        };
    }

    // =========================================================================
    // Jira Tools
    // =========================================================================

    private function getJiraClient(): services\JiraClient {
        $token = Bean::findOne('atlassiantoken', '(member_id = ? OR is_shared = 1)', [$this->memberId]);
        if (!$token || !$token->cloud_uid) {
            throw new \Exception('No Jira connection found. Connect Jira in settings.');
        }
        return new services\JiraClient($this->memberId, $token->cloud_uid);
    }

    private function toolJiraGetIssue(array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $jira = $this->getJiraClient();
        $issue = $jira->getIssue($issueKey);

        return [
            'key' => $issue['key'],
            'summary' => $issue['fields']['summary'] ?? '',
            'status' => $issue['fields']['status']['name'] ?? '',
            'type' => $issue['fields']['issuetype']['name'] ?? '',
            'priority' => $issue['fields']['priority']['name'] ?? '',
            'assignee' => $issue['fields']['assignee']['displayName'] ?? 'Unassigned',
            'reporter' => $issue['fields']['reporter']['displayName'] ?? '',
            'description' => $issue['fields']['description'] ?? '',
            'created' => $issue['fields']['created'] ?? '',
            'updated' => $issue['fields']['updated'] ?? '',
        ];
    }

    private function toolJiraComment(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';
        if (!$issueKey || !$message) throw new \Exception('issue_key and message required');

        $jira = $this->getJiraClient();
        $jira->addComment($issueKey, $message);

        return "Comment added to {$issueKey}";
    }

    private function toolJiraTransition(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $statusName = $args['status_name'] ?? '';
        if (!$issueKey || !$statusName) throw new \Exception('issue_key and status_name required');

        $jira = $this->getJiraClient();
        $jira->transitionIssue($issueKey, $statusName);

        return "Transitioned {$issueKey} to {$statusName}";
    }

    private function toolJiraGetTransitions(array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $jira = $this->getJiraClient();
        $transitions = $jira->getTransitions($issueKey);

        return array_map(fn($t) => [
            'id' => $t['id'],
            'name' => $t['name'],
        ], $transitions);
    }

    // =========================================================================
    // GitHub Tools
    // =========================================================================

    private function getGitHubClient(): services\GitHubClient {
        $token = Bean::findOne('githubtoken', '(member_id = ? OR is_shared = 1)', [$this->memberId]);
        if (!$token || !$token->access_token) {
            throw new \Exception('No GitHub connection found. Connect GitHub in settings.');
        }
        return new services\GitHubClient($token->access_token);
    }

    private function parseGitHubIssueKey(string $key): array {
        // Format: owner/repo#123
        if (!preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $key, $m)) {
            throw new \Exception("Invalid issue key format. Use: owner/repo#123");
        }
        return ['owner' => $m[1], 'repo' => $m[2], 'number' => (int)$m[3]];
    }

    private function toolGitHubGetIssue(array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();
        $issue = $gh->getIssue($parsed['owner'], $parsed['repo'], $parsed['number']);

        return [
            'number' => $issue['number'],
            'title' => $issue['title'],
            'state' => $issue['state'],
            'body' => $issue['body'] ?? '',
            'user' => $issue['user']['login'] ?? '',
            'labels' => array_map(fn($l) => $l['name'], $issue['labels'] ?? []),
            'created_at' => $issue['created_at'],
            'updated_at' => $issue['updated_at'],
        ];
    }

    private function toolGitHubComment(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';
        if (!$issueKey || !$message) throw new \Exception('issue_key and message required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();
        $gh->addComment($parsed['owner'], $parsed['repo'], $parsed['number'], $message);

        return "Comment added to {$issueKey}";
    }

    private function toolGitHubCloseIssue(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();

        if (!empty($args['comment'])) {
            $gh->addComment($parsed['owner'], $parsed['repo'], $parsed['number'], $args['comment']);
        }

        $gh->closeIssue($parsed['owner'], $parsed['repo'], $parsed['number']);

        return "Closed {$issueKey}";
    }

    private function toolGitHubAddLabels(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $labels = $args['labels'] ?? [];
        if (!$issueKey || empty($labels)) throw new \Exception('issue_key and labels required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();
        $gh->addLabels($parsed['owner'], $parsed['repo'], $parsed['number'], $labels);

        return "Added labels to {$issueKey}: " . implode(', ', $labels);
    }

    private function toolGitHubCreatePR(array $args): array {
        $repo = $args['repo'] ?? '';
        $title = $args['title'] ?? '';
        $head = $args['head'] ?? '';
        $base = $args['base'] ?? 'main';
        $body = $args['body'] ?? '';

        if (!$repo || !$title || !$head) {
            throw new \Exception('repo, title, and head are required');
        }

        if (!preg_match('/^([^\/]+)\/(.+)$/', $repo, $m)) {
            throw new \Exception("Invalid repo format. Use: owner/repo");
        }

        $gh = $this->getGitHubClient();
        $pr = $gh->createPullRequest($m[1], $m[2], $title, $head, $base, $body);

        return [
            'number' => $pr['number'],
            'url' => $pr['html_url'],
            'title' => $pr['title'],
            'state' => $pr['state'],
        ];
    }

    // =========================================================================
    // Gateway Tools
    // =========================================================================

    private function toolGatewayInfo(array $args): array {
        $connections = $this->authResult['member']->getConnections();

        return [
            'server' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'workspace' => $this->workspace,
            'member_id' => $this->memberId,
            'connections' => $connections,
        ];
    }

    // =========================================================================
    // JSON-RPC Helpers
    // =========================================================================

    private function sendJsonRpcResult($result, $id): void {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ]);
    }

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
    }
}
