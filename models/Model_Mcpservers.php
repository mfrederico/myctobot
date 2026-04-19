<?php
/**
 * Mcpservers Model
 * FUSE model for mcpservers table
 *
 * Stores MCP server configurations that can be referenced in pipeline mcp_call steps.
 * Supports both stdio (subprocess) and HTTP transport types.
 */

use \RedBeanPHP\R as R;

class Model_Mcpservers extends \RedBeanPHP\SimpleModel {

    /**
     * Valid server types
     */
    private const VALID_SERVER_TYPES = ['stdio', 'http'];

    /**
     * Called before storing the bean
     */
    public function update() {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Validate server_type
        if ($this->bean->server_type && !in_array($this->bean->server_type, self::VALID_SERVER_TYPES)) {
            $this->bean->server_type = 'stdio';
        }

        // Validate env_json is valid JSON if provided
        if ($this->bean->env_json) {
            $decoded = json_decode($this->bean->env_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->env_json = '{}';
            }
        }

        // Validate headers_json is valid JSON if provided
        if ($this->bean->headers_json) {
            $decoded = json_decode($this->bean->headers_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->headers_json = '{}';
            }
        }

        // Validate args_json is valid JSON if provided
        if ($this->bean->args_json) {
            $decoded = json_decode($this->bean->args_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->args_json = '[]';
            }
        }
    }

    /**
     * Called after dispense (when creating a new bean)
     */
    public function dispense() {
        $this->bean->server_type = 'stdio';
        $this->bean->is_active = 1;
        $this->bean->is_shared = 0;
        $this->bean->env_json = '{}';
        $this->bean->headers_json = '{}';
        $this->bean->args_json = '[]';
        $this->bean->tools_json = '[]';
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    /**
     * Check if server is active
     */
    public function isActive(): bool {
        return (bool) $this->bean->is_active;
    }

    /**
     * Get environment variables as array
     */
    public function getEnv(): array {
        return json_decode($this->bean->env_json ?: '{}', true) ?: [];
    }

    /**
     * Set environment variables from array
     */
    public function setEnv(array $env): void {
        $this->bean->env_json = json_encode($env);
    }

    /**
     * Get HTTP headers as array
     */
    public function getHeaders(): array {
        return json_decode($this->bean->headers_json ?: '{}', true) ?: [];
    }

    /**
     * Set HTTP headers from array
     */
    public function setHeaders(array $headers): void {
        $this->bean->headers_json = json_encode($headers);
    }

    /**
     * Get cached tools list
     */
    public function getTools(): array {
        return json_decode($this->bean->tools_json ?: '[]', true) ?: [];
    }

    /**
     * Update cached tools list
     */
    public function setTools(array $tools): void {
        $this->bean->tools_json = json_encode($tools);
        $this->bean->last_connected_at = date('Y-m-d H:i:s');
    }

    /**
     * Mark server as having an error
     */
    public function markError(string $message): void {
        $this->bean->last_error = $message;
        $this->bean->last_error_at = date('Y-m-d H:i:s');
    }

    /**
     * Clear error status
     */
    public function clearError(): void {
        $this->bean->last_error = null;
        $this->bean->last_error_at = null;
    }

    /**
     * Test connection to the MCP server
     */
    public function testConnection(): array {
        require_once dirname(__DIR__) . '/services/McpClientService.php';

        $client = new \app\services\McpClientService();

        try {
            $transport = $this->bean->server_type ?: 'stdio';
            $config = [];

            if ($transport === 'stdio') {
                // Build command with args
                $command = $this->bean->command;
                $args = json_decode($this->bean->args_json ?: '[]', true);
                if (!empty($args)) {
                    $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
                }

                $config = [
                    'command' => $command,
                    'working_dir' => '/tmp',
                    'env' => $this->getEnv()
                ];
            } else {
                $config = [
                    'url' => $this->bean->url,
                    'headers' => $this->getHeaders()
                ];
            }

            if (!$client->connect($transport, $config)) {
                $this->markError('Failed to connect');
                return ['success' => false, 'error' => 'Failed to connect to MCP server'];
            }

            $serverInfo = $client->getServerInfo();
            $tools = $client->listTools();

            $client->disconnect();

            // Update cached data
            $this->bean->server_name = $serverInfo['name'] ?? null;
            $this->bean->server_version = $serverInfo['version'] ?? null;
            $this->setTools($tools);
            $this->clearError();
            $this->bean->is_active = 1;

            return [
                'success' => true,
                'server_info' => $serverInfo,
                'tools' => $tools
            ];

        } catch (\Exception $e) {
            $this->markError($e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Export as array for API responses
     */
    public function toArray(): array {
        return [
            'id' => $this->bean->id,
            'name' => $this->bean->name,
            'description' => $this->bean->description,
            'server_type' => $this->bean->server_type,
            'command' => $this->bean->command,
            'args' => json_decode($this->bean->args_json ?: '[]', true),
            'url' => $this->bean->url,
            'headers' => $this->getHeaders(),
            'env' => $this->getEnv(),
            'is_shared' => (bool) $this->bean->is_shared,
            'is_active' => (bool) $this->bean->is_active,
            'server_name' => $this->bean->server_name,
            'server_version' => $this->bean->server_version,
            'tools_count' => count($this->getTools()),
            'last_connected_at' => $this->bean->last_connected_at,
            'last_error' => $this->bean->last_error,
            'last_error_at' => $this->bean->last_error_at,
            'created_at' => $this->bean->created_at,
            'updated_at' => $this->bean->updated_at,
        ];
    }
}
