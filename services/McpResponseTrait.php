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
}
