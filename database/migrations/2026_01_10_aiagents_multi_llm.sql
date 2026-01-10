-- Migration: Add multi-LLM provider support to aiagents table
-- Date: 2026-01-10

-- Add provider type column
ALTER TABLE aiagents ADD COLUMN provider VARCHAR(50) DEFAULT 'claude_cli';

-- Add provider-specific configuration (JSON)
ALTER TABLE aiagents ADD COLUMN provider_config JSON;

-- Add capabilities (what this agent can do)
ALTER TABLE aiagents ADD COLUMN capabilities JSON;

-- Add MCP exposure settings
ALTER TABLE aiagents ADD COLUMN expose_as_mcp TINYINT(1) DEFAULT 0;
ALTER TABLE aiagents ADD COLUMN mcp_tool_name VARCHAR(100);
ALTER TABLE aiagents ADD COLUMN mcp_tool_description TEXT;

-- Example data after migration:
-- provider: 'ollama'
-- provider_config: '{"host": "http://localhost:11434", "model": "llama3", "temperature": 0.7}'
-- capabilities: '["code_review", "documentation"]'
-- expose_as_mcp: 1
-- mcp_tool_name: 'ollama_review'
-- mcp_tool_description: 'Get code review from local Ollama instance'
