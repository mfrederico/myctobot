<?php
/**
 * MCP CRM Controller - CRM Tools via MCP
 *
 * Provides MCP tools for reading/writing CRM data from the assistant chatbot
 * and pipeline agents. Follows the Mcppipelines.php pattern exactly.
 *
 * Auth: Bearer token via McpGatewayAuthProvider
 * Workspace: from subdomain or X-Workspace header
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;
use app\services\McpGatewayAuthProvider;
use app\services\CrmToolService;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Protocol\Request as JsonRpcRequest;

require_once __DIR__ . '/../services/ApiAuthService.php';
require_once __DIR__ . '/../services/CrmToolService.php';

class Mcpcrm extends Control
{
    private const SERVER_NAME = 'myctobot-crm';
    private const SERVER_VERSION = '1.0.0';

    private ?int $memberId = null;
    private ?string $workspace = null;
    protected $logger;

    public function __construct()
    {
        // Don't call parent - MCP requests don't have sessions
        $this->logger = Flight::get('log');
        $this->workspace = $_SERVER['WORKSPACE']
            ?? $_SERVER['HTTP_X_WORKSPACE']
            ?? null;
    }

    /**
     * MCP JSON-RPC endpoint for CRM
     * POST /mcp/crm
     */
    public function index()
    {
        // CORS headers
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, 'Method not allowed');
            return;
        }

        if (empty($this->workspace)) {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, 'Workspace required. Use subdomain or X-Workspace header');
            return;
        }

        if (!WorkspaceResolver::switchDatabase($this->workspace)) {
            echo JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, "Invalid workspace: {$this->workspace}");
            return;
        }

        $body = file_get_contents('php://input');

        try {
            $message = JsonRpc::parse($body);
        } catch (JsonRpcException $e) {
            echo $e->toJson();
            return;
        }

        $this->logger->debug("MCP CRM: method=" . $message->method . ", workspace={$this->workspace}");

        $authProvider = new McpGatewayAuthProvider();

        $server = new Server(
            name: self::SERVER_NAME,
            version: self::SERVER_VERSION,
            instructions: 'MyCTOBot CRM tools. Authenticate with Bearer tk_ token.',
            logger: $this->logger,
        );

        $server->setAuthProvider($authProvider, required: true);

        // Register CRM tools
        $workspace = $this->workspace;
        $logger = $this->logger;

        $defs = CrmToolService::getToolDefinitions();
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

        $authRequest = AuthRequest::fromHttp(getallheaders() ?: [], $_GET, $body);

        // Auto-initialize for stateless HTTP
        if ($message->method !== 'initialize') {
            $server->handle(new JsonRpcRequest(id: '_init', method: 'initialize', params: [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'http-auto', 'version' => '1.0'],
            ]));
        }

        $response = $server->handle($message, $authRequest);

        if ($response !== null) {
            echo $response;
        }
    }

    private function dispatchTool(string $toolName, array $args, McpGatewayAuthProvider $authProvider, string $workspace, $logger): \Fastmcphp\Tools\ToolResult
    {
        $memberId = $authProvider->getMemberId();
        $svc = new CrmToolService($workspace, $memberId, $logger);

        $result = match($toolName) {
            'crm_list_contacts' => $svc->listContacts($args),
            'crm_get_contact' => $svc->getContact($args),
            'crm_create_contact' => $svc->createContact($args),
            'crm_update_contact' => $svc->updateContact($args),
            'crm_add_note' => $svc->addNote($args),
            'crm_log_touch' => $svc->logTouch($args),
            'crm_move_stage' => $svc->moveStage($args),
            'crm_search' => $svc->searchContacts($args),
            'crm_compose_message' => $svc->composeMessage($args),
            'crm_enrich_contact' => $svc->enrichContact($args),
            'crm_get_stats' => $svc->getStats($args),
            'linkedin_search_companies' => $svc->linkedinSearchCompanies($args),
            'linkedin_search_people' => $svc->linkedinSearchPeople($args),
            'linkedin_enrich_contact' => $svc->linkedinEnrichContact($args),
            default => throw new \RuntimeException("Unknown tool: {$toolName}"),
        };

        $isError = !empty($result['isError']);
        $text = $result['content'][0]['text'] ?? json_encode($result, JSON_PRETTY_PRINT);
        return $isError
            ? \Fastmcphp\Tools\ToolResult::error($text)
            : \Fastmcphp\Tools\ToolResult::text($text);
    }
}
