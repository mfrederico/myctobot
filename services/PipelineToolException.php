<?php
/**
 * PipelineToolException
 *
 * Thrown by PipelineToolService when a tool operation fails.
 * Code maps to JSON-RPC error codes (-32602 for validation, -32000 for server errors).
 */

namespace app\services;

class PipelineToolException extends \RuntimeException
{
    public function __construct(string $message, int $code = -32602)
    {
        parent::__construct($message, $code);
    }
}
