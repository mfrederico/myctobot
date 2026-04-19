<?php
/**
 * MCP Response Trait
 *
 * Provides JSON-RPC 2.0 response helpers for MCP controllers.
 */

namespace app\services;

trait McpResponseTrait
{
    /**
     * Build a JSON-RPC 2.0 error response array
     *
     * @param mixed $id Request ID (can be null for notifications)
     * @param int $code Error code
     * @param string $message Error message
     * @return array
     */
    protected function errorResponse($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }

    /**
     * Send a JSON-RPC 2.0 error response and output to stdout
     *
     * @param mixed $id Request ID
     * @param int $code Error code
     * @param string $message Error message
     */
    protected function sendError($id, int $code, string $message): void
    {
        echo json_encode($this->errorResponse($id, $code, $message));
    }

    /**
     * Handle a JSON-RPC request by dispatching to the appropriate handler
     *
     * Implementing classes must provide:
     * - handleInitialize($id, array $params): array
     * - handleToolsList($id): array
     * - handleToolCall($id, array $params): array
     *
     * @param array $request JSON-RPC request
     * @return array JSON-RPC response
     */
    protected function handleRequest(array $request): array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($id, $params);

            case 'tools/list':
                return $this->handleToolsList($id);

            case 'tools/call':
                return $this->handleToolCall($id, $params);

            case 'notifications/initialized':
                return []; // No response needed for notifications

            default:
                return $this->errorResponse($id, -32601, "Method not found: {$method}");
        }
    }
}
