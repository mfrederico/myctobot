# Multi-LLM Agent Orchestration

## Overview

Enable agent profiles to use different LLM backends (Claude, Ollama, OpenAI, etc.) and orchestrate them together on tasks.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    MyCTOBot Orchestrator                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐             │
│  │ Claude CLI  │    │   Ollama    │    │   OpenAI    │             │
│  │  (Primary)  │◄──►│  (via MCP)  │    │  (via MCP)  │             │
│  │             │    │             │    │             │             │
│  │ - Runs in   │    │ - Local     │    │ - Cloud     │             │
│  │   tmux      │    │ - Fast      │    │ - GPT-4     │             │
│  │ - Full      │    │ - Code      │    │ - Analysis  │             │
│  │   tooling   │    │   review    │    │             │             │
│  └─────────────┘    └─────────────┘    └─────────────┘             │
│         │                  ▲                  ▲                     │
│         │                  │                  │                     │
│         └──────────────────┴──────────────────┘                     │
│                    MCP Tool Calls                                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Agent Providers

| Provider | Transport | Use Case |
|----------|-----------|----------|
| `claude_cli` | tmux + Claude Code | Primary orchestrator, full tooling |
| `claude_api` | Anthropic API | Subagents, background tasks |
| `ollama` | HTTP API / MCP | Local inference, code review, quick tasks |
| `openai` | HTTP API / MCP | GPT-4 for analysis, alternative perspective |
| `custom_http` | HTTP API | Any OpenAI-compatible endpoint |

## Database Schema

### `aiagents` table updates

```sql
ALTER TABLE aiagents ADD COLUMN provider VARCHAR(50) DEFAULT 'claude_cli';
ALTER TABLE aiagents ADD COLUMN provider_config JSON;
ALTER TABLE aiagents ADD COLUMN capabilities JSON;
ALTER TABLE aiagents ADD COLUMN expose_as_mcp TINYINT(1) DEFAULT 0;
ALTER TABLE aiagents ADD COLUMN mcp_tool_name VARCHAR(100);
```

### Provider Config Examples

**Claude CLI:**
```json
{
  "model": "sonnet",
  "dangerously_skip_permissions": true,
  "max_turns": 50
}
```

**Claude API:**
```json
{
  "model": "claude-sonnet-4-20250514",
  "max_tokens": 8192,
  "api_key_setting": "anthropic_api_key"
}
```

**Ollama:**
```json
{
  "host": "http://localhost:11434",
  "model": "llama3",
  "context_length": 8192,
  "temperature": 0.7
}
```

**OpenAI:**
```json
{
  "model": "gpt-4-turbo",
  "api_key_setting": "openai_api_key",
  "organization": "org-xxx"
}
```

**Custom HTTP (OpenAI-compatible):**
```json
{
  "endpoint": "http://localhost:8000/v1/chat/completions",
  "model": "local-model",
  "api_key": "optional"
}
```

## Capabilities System

Each agent declares what it can do:

```json
{
  "capabilities": [
    "code_implementation",
    "code_review",
    "browser_testing",
    "requirements_analysis",
    "documentation",
    "security_audit"
  ]
}
```

The orchestrator uses capabilities to route tasks:
- "Review this code" → agent with `code_review` capability
- "Implement this feature" → agent with `code_implementation`

## MCP Exposure

When `expose_as_mcp = true`, the agent becomes callable by other agents:

```json
{
  "mcp_tool_name": "ollama_review",
  "mcp_description": "Get a code review from local Ollama instance",
  "mcp_parameters": {
    "code": "The code to review",
    "context": "Optional context about the code"
  }
}
```

This generates an MCP server that:
1. Receives the tool call from Claude
2. Forwards to the agent's provider (Ollama API)
3. Returns the response

## UI: Agent Profile Editor

```
┌─────────────────────────────────────────────────────────────────┐
│  Edit Agent: Code Reviewer                                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  [General] [Provider] [MCP Servers] [Hooks] [Capabilities]      │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│  Provider Tab:                                                  │
│                                                                 │
│  Provider Type: [Ollama (Local) ▼]                              │
│                                                                 │
│  ┌─ Ollama Configuration ─────────────────────────────────────┐│
│  │                                                            ││
│  │  Host:  [http://localhost:11434        ]                   ││
│  │                                                            ││
│  │  Model: [llama3          ▼] [Refresh Models]               ││
│  │         Available: llama3, codellama, mistral              ││
│  │                                                            ││
│  │  Context Length: [8192    ]                                ││
│  │  Temperature:    [0.7     ]                                ││
│  │                                                            ││
│  │  [Test Connection]  ✓ Connected - 3 models available       ││
│  └────────────────────────────────────────────────────────────┘│
│                                                                 │
│  ┌─ MCP Exposure ─────────────────────────────────────────────┐│
│  │                                                            ││
│  │  [x] Expose this agent as MCP tool for other agents        ││
│  │                                                            ││
│  │  Tool Name:   [ollama_review        ]                      ││
│  │  Description: [Get code review from local Ollama          ]││
│  │                                                            ││
│  └────────────────────────────────────────────────────────────┘│
│                                                                 │
│                                    [Cancel] [Save Agent]        │
└─────────────────────────────────────────────────────────────────┘
```

## Orchestration Flow

### Example: Code Implementation with Multi-LLM Review

1. **Job starts** with primary agent (Claude CLI)
2. **Claude implements** the feature
3. **Claude calls** `ollama_review` MCP tool for code review
4. **Ollama reviews** and returns feedback
5. **Claude addresses** feedback
6. **Claude calls** `gpt_analyze` for requirements verification
7. **GPT confirms** requirements are met
8. **Claude creates** PR

### Orchestrator Prompt Injection

When building the prompt, inject available agents:

```markdown
## Available AI Assistants

You can delegate tasks to these specialized agents via MCP:

- **ollama_review** (Ollama/llama3): Fast local code review. Good for quick
  feedback on implementation details. Call with code snippets.

- **gpt_analyst** (OpenAI/gpt-4): Requirements analysis and verification.
  Good for checking if implementation matches requirements.

Usage example:
- After implementing, call `ollama_review` with your code changes
- Before creating PR, call `gpt_analyst` to verify requirements
```

## Implementation Plan

### Phase 1: Schema & Provider Abstraction
- [ ] Add provider columns to aiagents table
- [ ] Create `LLMProviderInterface`
- [ ] Implement `ClaudeCliProvider`, `OllamaProvider`, `OpenAIProvider`
- [ ] Provider factory class

### Phase 2: Agent Editor UI
- [ ] Add Provider tab to /agents/edit
- [ ] Provider-specific config forms (dynamic)
- [ ] Test connection functionality
- [ ] MCP exposure configuration

### Phase 3: MCP Bridge
- [ ] Create MCP server that bridges to non-Claude agents
- [ ] Dynamic tool registration based on exposed agents
- [ ] Request/response handling

### Phase 4: Orchestrator Integration
- [ ] Update AIDevAgentOrchestrator to inject available agents
- [ ] Update local-aidev-full.php to configure MCP bridges
- [ ] Add agent selection to job configuration

## File Structure

```
services/
├── LLMProviders/
│   ├── LLMProviderInterface.php
│   ├── LLMProviderFactory.php
│   ├── ClaudeCliProvider.php
│   ├── ClaudeApiProvider.php
│   ├── OllamaProvider.php
│   ├── OpenAIProvider.php
│   └── CustomHttpProvider.php
├── AgentMcpBridge.php          # Exposes agents as MCP tools
└── MultiAgentOrchestrator.php  # Routes tasks to appropriate agents

scripts/
└── mcp-agent-bridge.php        # MCP server for agent bridging
```

## Security Considerations

1. **API Keys**: Store encrypted in enterprisesettings, never in agent config directly
2. **Local Ollama**: Verify host is localhost or trusted network
3. **MCP Exposure**: Only expose agents the user explicitly enables
4. **Rate Limiting**: Prevent runaway costs with API-based providers
