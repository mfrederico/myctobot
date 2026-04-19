<?php
/**
 * ManualTool - Tool implementation with explicit schema definition
 *
 * Unlike FunctionTool which auto-generates schemas via reflection,
 * ManualTool accepts the name, description, inputSchema, and handler
 * directly. This preserves exact existing tool schemas (with defaults,
 * descriptions, etc.) for backward compatibility.
 */

namespace app;

use Fastmcphp\Tools\Tool;
use Fastmcphp\Tools\ToolResult;
use Fastmcphp\Server\Context;

class ManualTool implements Tool
{
    private \Closure $handler;

    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        callable $handler,
    ) {
        $this->handler = $handler(...);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function execute(array $arguments, ?Context $context = null): ToolResult
    {
        try {
            $result = ($this->handler)($arguments);
            return ToolResult::fromValue($result);
        } catch (\Exception $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toMcpTool(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }
}
