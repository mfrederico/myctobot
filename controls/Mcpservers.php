<?php
/**
 * MCP Servers Controller
 * Manages shareable MCP server configurations
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;

class Mcpservers extends BaseControls\Control {

    /**
     * List all MCP servers (own + shared)
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        // Get own servers + shared servers
        $servers = Bean::find('mcpservers',
            ' (member_id = ? OR is_shared = 1) ORDER BY name ASC',
            [$this->member->id]
        );

        $serverList = [];
        foreach ($servers as $server) {
            $serverList[] = $this->formatServer($server);
        }

        $this->viewData['title'] = 'MCP Server Library';
        $this->viewData['servers'] = $serverList;
        $this->viewData['presets'] = $this->getPresets();

        $this->render('mcpservers/index', $this->viewData);
    }

    /**
     * API: List servers for modal/dropdown
     */
    public function list($params = []) {
        if (!$this->requireLogin()) return;

        // Get own servers + shared servers
        $servers = Bean::find('mcpservers',
            ' (member_id = ? OR is_shared = 1) ORDER BY name ASC',
            [$this->member->id]
        );

        $serverList = [];
        foreach ($servers as $server) {
            $serverList[] = $this->formatServer($server);
        }

        Flight::jsonSuccess($serverList);
    }

    /**
     * Create a new MCP server
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $input = $this->getParam('json') ? json_decode($this->getParam('json'), true) : $_POST;

        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $serverType = $input['server_type'] ?? 'stdio';

        if (empty($name)) {
            Flight::jsonError('Server name is required', 400);
            return;
        }

        // Check for duplicate name (within own + shared)
        $existing = Bean::findOne('mcpservers',
            ' name = ? AND (member_id = ? OR is_shared = 1)',
            [$name, $this->member->id]
        );
        if ($existing) {
            Flight::jsonError('A server with this name already exists', 400);
            return;
        }

        $server = Bean::dispense('mcpservers');
        $server->member_id = $this->member->id;
        $server->name = $name;
        $server->description = $description;
        $server->server_type = $serverType;
        $server->is_shared = 0;
        $server->is_active = 1;
        $server->created_at = date('Y-m-d H:i:s');
        $server->updated_at = date('Y-m-d H:i:s');

        if ($serverType === 'stdio') {
            $server->command = trim($input['command'] ?? '');
            $args = $input['args'] ?? [];
            if (is_string($args)) {
                $args = array_map('trim', explode(',', $args));
                $args = array_filter($args);
            }
            $server->args_json = json_encode(array_values($args));
            $server->url = null;
            $server->headers_json = '{}';
        } else {
            $server->url = trim($input['url'] ?? '');
            $headers = $input['headers'] ?? [];
            if (is_string($headers)) {
                $headers = json_decode($headers, true) ?: [];
            }
            $server->headers_json = json_encode($headers);
            $server->command = null;
            $server->args_json = '[]';
        }

        // Environment variables
        $env = $input['env'] ?? [];
        if (is_string($env)) {
            $env = json_decode($env, true) ?: [];
        }
        $server->env_json = json_encode($env);

        Bean::store($server);

        Flight::jsonSuccess($this->formatServer($server), 'MCP server created');
    }

    /**
     * Update an existing MCP server
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = (int) ($this->opId() ?? $params[0] ?? 0);
        $server = Bean::load('mcpservers', $serverId);

        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Only owner can update (unless admin)
        if ($server->member_id != $this->member->id && $this->member->level > LEVELS['ADMIN']) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $input = $this->getParam('json') ? json_decode($this->getParam('json'), true) : $_POST;

        $name = trim($input['name'] ?? $server->name);
        $description = trim($input['description'] ?? $server->description);
        $serverType = $input['server_type'] ?? $server->server_type;

        // Check for duplicate name (excluding self)
        $existing = Bean::findOne('mcpservers',
            ' name = ? AND id != ? AND (member_id = ? OR is_shared = 1)',
            [$name, $serverId, $this->member->id]
        );
        if ($existing) {
            Flight::jsonError('A server with this name already exists', 400);
            return;
        }

        $server->name = $name;
        $server->description = $description;
        $server->server_type = $serverType;
        $server->updated_at = date('Y-m-d H:i:s');

        if ($serverType === 'stdio') {
            $server->command = trim($input['command'] ?? $server->command);
            if (isset($input['args'])) {
                $args = $input['args'];
                if (is_string($args)) {
                    $args = array_map('trim', explode(',', $args));
                    $args = array_filter($args);
                }
                $server->args_json = json_encode(array_values($args));
            }
        } else {
            if (isset($input['url'])) {
                $server->url = trim($input['url']);
            }
            if (isset($input['headers'])) {
                $headers = $input['headers'];
                if (is_string($headers)) {
                    $headers = json_decode($headers, true) ?: [];
                }
                $server->headers_json = json_encode($headers);
            }
        }

        // Environment variables
        if (isset($input['env'])) {
            $env = $input['env'];
            if (is_string($env)) {
                $env = json_decode($env, true) ?: [];
            }
            $server->env_json = json_encode($env);
        }

        // Sharing flag (only owner can change)
        if (isset($input['is_shared']) && $server->member_id == $this->member->id) {
            $server->is_shared = $input['is_shared'] ? 1 : 0;
        }

        Bean::store($server);

        Flight::jsonSuccess($this->formatServer($server), 'MCP server updated');
    }

    /**
     * Delete an MCP server
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = (int) ($this->opId() ?? $params[0] ?? 0);
        $server = Bean::load('mcpservers', $serverId);

        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Only owner can delete (unless admin)
        if ($server->member_id != $this->member->id && $this->member->level > LEVELS['ADMIN']) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // Check if server is linked to any agents
        $linkedAgents = $server->sharedAiagentsList;
        if (count($linkedAgents) > 0) {
            $agentNames = array_map(fn($a) => $a->name, $linkedAgents);
            Flight::jsonError('Server is linked to agents: ' . implode(', ', $agentNames), 400);
            return;
        }

        Bean::trash($server);

        Flight::jsonSuccess(null, 'MCP server deleted');
    }

    /**
     * Toggle shared status
     */
    public function toggleshared($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = (int) ($this->opId() ?? $params[0] ?? 0);
        $server = Bean::load('mcpservers', $serverId);

        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Only owner can toggle sharing
        if ($server->member_id != $this->member->id) {
            Flight::jsonError('Only the owner can change sharing settings', 403);
            return;
        }

        $server->is_shared = $server->is_shared ? 0 : 1;
        $server->updated_at = date('Y-m-d H:i:s');
        Bean::store($server);

        $status = $server->is_shared ? 'shared with workspace' : 'private';
        Flight::jsonSuccess($this->formatServer($server), "Server is now $status");
    }

    /**
     * Toggle active status
     */
    public function toggleactive($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = (int) ($this->opId() ?? $params[0] ?? 0);
        $server = Bean::load('mcpservers', $serverId);

        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Only owner can toggle active status
        if ($server->member_id != $this->member->id) {
            Flight::jsonError('Only the owner can change active status', 403);
            return;
        }

        $server->is_active = $server->is_active ? 0 : 1;
        $server->updated_at = date('Y-m-d H:i:s');
        Bean::store($server);

        $status = $server->is_active ? 'active' : 'inactive';
        Flight::jsonSuccess($this->formatServer($server), "Server is now $status");
    }

    /**
     * Link server to agent (many-to-many)
     */
    public function linkagent($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = $params[0] ?? 0;
        $agentId = $this->getParam('agent_id');

        $server = Bean::load('mcpservers', $serverId);
        $agent = Bean::load('aiagents', $agentId);

        if (!$server->id || !$agent->id) {
            Flight::jsonError('Server or agent not found', 404);
            return;
        }

        // Check access to server
        if ($server->member_id != $this->member->id && !$server->is_shared) {
            Flight::jsonError('Access denied to this server', 403);
            return;
        }

        // Link via RedBeanPHP shared list
        $agent->sharedMcpserversList[] = $server;
        Bean::store($agent);

        Flight::jsonSuccess(null, 'Server linked to agent');
    }

    /**
     * Unlink server from agent
     */
    public function unlinkagent($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = $params[0] ?? 0;
        $agentId = $this->getParam('agent_id');

        $server = Bean::load('mcpservers', $serverId);
        $agent = Bean::load('aiagents', $agentId);

        if (!$server->id || !$agent->id) {
            Flight::jsonError('Server or agent not found', 404);
            return;
        }

        // Remove from shared list
        unset($agent->sharedMcpserversList[$server->id]);
        Bean::store($agent);

        Flight::jsonSuccess(null, 'Server unlinked from agent');
    }

    /**
     * Test connection to an MCP server
     * Returns server info and available tools
     */
    public function test($params = []) {
        if (!$this->requireLogin()) return;

        $serverId = (int) ($this->opId() ?? $params[0] ?? 0);
        $server = Bean::load('mcpservers', $serverId);

        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Check access to server
        if ($server->member_id != $this->member->id && !$server->is_shared) {
            Flight::jsonError('Access denied to this server', 403);
            return;
        }

        require_once __DIR__ . '/../services/McpClientService.php';

        $client = new \app\services\McpClientService(Flight::get('log'));

        try {
            // Build connection config
            $transport = $server->server_type ?: 'stdio';
            $config = [];

            if ($transport === 'stdio') {
                // Build command with args
                $command = $server->command;
                $args = json_decode($server->args_json ?: '[]', true);
                if (!empty($args)) {
                    $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
                }

                $config = [
                    'command' => $command,
                    'working_dir' => '/tmp',
                    'env' => json_decode($server->env_json ?: '{}', true)
                ];
            } else {
                $config = [
                    'url' => $server->url,
                    'headers' => json_decode($server->headers_json ?: '{}', true)
                ];
            }

            if (!$client->connect($transport, $config)) {
                // Update server status
                $server->last_error = 'Failed to connect';
                $server->last_error_at = date('Y-m-d H:i:s');
                Bean::store($server);

                Flight::jsonError('Failed to connect to MCP server', 500);
                return;
            }

            $serverInfo = $client->getServerInfo();
            $tools = $client->listTools();
            $capabilities = $client->getCapabilities();

            $client->disconnect();

            // Update server with cached info
            $server->server_name = $serverInfo['name'] ?? null;
            $server->server_version = $serverInfo['version'] ?? null;
            $server->tools_json = json_encode($tools);
            $server->capabilities_json = json_encode($capabilities);
            $server->last_connected_at = date('Y-m-d H:i:s');
            $server->last_error = null;
            $server->last_error_at = null;
            Bean::store($server);

            Flight::jsonSuccess([
                'server_info' => $serverInfo,
                'capabilities' => $capabilities,
                'tools' => $tools,
                'tools_count' => count($tools)
            ], 'Connection successful');

        } catch (\Exception $e) {
            $client->disconnect();

            // Update server status
            $server->last_error = $e->getMessage();
            $server->last_error_at = date('Y-m-d H:i:s');
            Bean::store($server);

            Flight::jsonError('Connection failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Call a tool on an MCP server (for testing)
     */
    public function calltool($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $serverId = (int) ($this->opId() ?? $params[0] ?? 0);
        $server = Bean::load('mcpservers', $serverId);

        if (!$server->id) {
            Flight::jsonError('Server not found', 404);
            return;
        }

        // Check access to server
        if ($server->member_id != $this->member->id && !$server->is_shared) {
            Flight::jsonError('Access denied to this server', 403);
            return;
        }

        $toolName = $this->getParam('tool');
        $arguments = $this->getParam('arguments');

        if (empty($toolName)) {
            Flight::jsonError('Tool name is required', 400);
            return;
        }

        // Parse arguments if string
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?: [];
        }

        require_once __DIR__ . '/../services/McpClientService.php';

        $client = new \app\services\McpClientService(Flight::get('log'));

        try {
            // Build connection config
            $transport = $server->server_type ?: 'stdio';
            $config = [];

            if ($transport === 'stdio') {
                $command = $server->command;
                $args = json_decode($server->args_json ?: '[]', true);
                if (!empty($args)) {
                    $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
                }

                $config = [
                    'command' => $command,
                    'working_dir' => '/tmp',
                    'env' => json_decode($server->env_json ?: '{}', true)
                ];
            } else {
                $config = [
                    'url' => $server->url,
                    'headers' => json_decode($server->headers_json ?: '{}', true)
                ];
            }

            if (!$client->connect($transport, $config)) {
                Flight::jsonError('Failed to connect to MCP server', 500);
                return;
            }

            $result = $client->callTool($toolName, $arguments);

            $client->disconnect();

            if (!$result['success']) {
                Flight::jsonError($result['error'] ?? 'Tool call failed', 400);
                return;
            }

            Flight::jsonSuccess([
                'tool' => $toolName,
                'arguments' => $arguments,
                'result' => $result
            ], 'Tool executed successfully');

        } catch (\Exception $e) {
            $client->disconnect();
            Flight::jsonError('Tool call failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Format server bean for JSON response
     */
    private function formatServer($server): array {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'description' => $server->description,
            'server_type' => $server->server_type,
            'command' => $server->command,
            'args' => json_decode($server->args_json ?: '[]', true),
            'url' => $server->url,
            'headers' => json_decode($server->headers_json ?: '{}', true),
            'env' => json_decode($server->env_json ?: '{}', true),
            'is_shared' => (bool) $server->is_shared,
            'is_active' => (bool) $server->is_active,
            'is_own' => $server->member_id == $this->member->id,
            'created_at' => $server->created_at,
            'updated_at' => $server->updated_at
        ];
    }

    /**
     * Get common MCP server presets
     */
    private function getPresets(): array {
        // Get workspace-specific values for pipelines preset
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $baseUrl = Flight::get('app.baseurl') ?: 'https://myctobot.ai';
        if (strpos($baseUrl, 'localhost') === false && strpos($baseUrl, '127.0.0.1') === false) {
            $baseUrl = preg_replace('/^http:/', 'https:', $baseUrl);
        }

        // Try to find an existing API key with mcp:* scope for this member
        $mcpApiKey = '';
        if ($this->member) {
            $apiKey = Bean::findOne('apikeys',
                ' member_id = ? AND is_active = 1 AND (scopes_json LIKE ? OR scopes_json LIKE ?)',
                [$this->member->id, '%"mcp:%', '%"*:*"%']
            );
            if ($apiKey) {
                $mcpApiKey = $apiKey->token;
            }
        }

        // Use subdomain-based URL pattern: https://{workspace}.{domain}/mcp/...
        $subdomainUrl = \app\services\SiteConfig::getWorkspaceUrl($workspaceSlug);

        return [
            'myctobot-gateway' => [
                'name' => 'myctobot',
                'description' => 'MyCTOBot Gateway (Jira, GitHub, Shopify tools) - Use this for remote agents',
                'server_type' => 'http',
                'url' => "{$subdomainUrl}/mcp",
                'headers' => ['Authorization' => 'Bearer ' . ($mcpApiKey ?: 'tk_CREATE_KEY_AT_APIKEYS_WITH_MCP_SCOPE')],
                'env' => [],
                'note' => $mcpApiKey ? 'HTTP endpoint for remote agents - includes jira_*, github_*, shopify_* tools' : 'Create an API key at /apikeys with mcp:* scope'
            ],
            'pipelines' => [
                'name' => 'pipelines',
                'description' => 'MyCTOBot Pipeline Tools',
                'server_type' => 'http',
                'url' => "{$subdomainUrl}/mcp/pipelines",
                'headers' => ['Authorization' => 'Bearer ' . ($mcpApiKey ?: 'tk_CREATE_KEY_AT_APIKEYS_WITH_MCP_SCOPE')],
                'env' => [],
                'note' => $mcpApiKey ? '' : 'Create an API key at /apikeys with mcp:* scope'
            ],
            'github' => [
                'name' => 'github',
                'description' => 'GitHub API integration',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-github'],
                'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => '${GITHUB_TOKEN}']
            ],
            'fetch' => [
                'name' => 'fetch',
                'description' => 'HTTP fetch capabilities',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-fetch'],
                'env' => []
            ],
            'mantic' => [
                'name' => 'mantic',
                'description' => 'Semantic code search',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', 'mantic-mcp'],
                'env' => []
            ],
            'filesystem' => [
                'name' => 'filesystem',
                'description' => 'File system access',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/path/to/allowed/dir'],
                'env' => []
            ],
            'memory' => [
                'name' => 'memory',
                'description' => 'Persistent memory storage',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-memory'],
                'env' => []
            ],
            'puppeteer' => [
                'name' => 'puppeteer',
                'description' => 'Browser automation (Puppeteer)',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-puppeteer'],
                'env' => []
            ],
            'playwright' => [
                'name' => 'playwright',
                'description' => 'Browser automation (Playwright)',
                'server_type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@playwright/mcp@latest'],
                'env' => []
            ]
        ];
    }
}
