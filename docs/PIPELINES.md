# Pipeline System Reference

This document describes how pipelines work, including context variables, MCP tool exposure, and variable substitution.

## Overview

Pipelines are configurable workflows that execute steps in sequence (rows) and parallel (columns). Each step can:
- Execute shell commands
- Call external APIs
- Run AI agents
- Transform data
- Send notifications

```
Row 0: [Step A] [Step B] [Step C]  ← Execute in parallel
           ↓
Row 1: [Step D] [Step E]          ← Wait for Row 0, then parallel
           ↓
Row 2: [Step F]                   ← Wait for Row 1
```

## Variable Substitution

Steps can reference variables using `{variable.path}` syntax. Variables are resolved at runtime.

### Variable Sources

| Prefix | Source | Example |
|--------|--------|---------|
| `context.` | Input parameters (from MCP or trigger) | `{context.product_handle}` |
| `step_name.` | Output from a previous step | `{query_products.output.data.title}` |
| (none) | Built-in run variables | `{run_id}`, `{pipeline_name}` |

### Built-in Variables

Always available in every step:

| Variable | Type | Description |
|----------|------|-------------|
| `run_id` | integer | Current run ID |
| `run_uid` | string | Unique run identifier (UUID) |
| `run_directory` | string | File storage path for this run |
| `pipeline_name` | string | Pipeline name |
| `pipeline_slug` | string | Pipeline slug |
| `started_at` | datetime | Run start time |
| `trigger_source` | string | What triggered the run |

### Context Variables

Context variables are input parameters passed when the pipeline is triggered. They're referenced with the `{context.xxx}` prefix.

**Defining context variables:**
1. Add `{context.variable_name}` anywhere in a step's configuration
2. Use "Derive from Steps" to auto-generate the input schema
3. The variable becomes a required input when triggering the pipeline

**Example - Shopify GraphQL step:**
```json
{
  "connection_id": "{context.shop_id}",
  "query": "query getProduct($handle: String!) { productByHandle(handle: $handle) { id title } }",
  "variables": {
    "handle": "{context.product_handle}"
  }
}
```

This step requires two context variables: `shop_id` and `product_handle`.

### Step Output Variables

Reference outputs from previous steps using `{step_name.output.path}`:

```json
{
  "body": "Product title: {query_products.output.data.productByHandle.title}"
}
```

**Available step output fields:**

| Field | Description |
|-------|-------------|
| `step_name.output` | Full output object |
| `step_name.output.xxx` | Nested output path |
| `step_name.stdout` | Raw stdout (for shell commands) |
| `step_name.stderr` | Raw stderr (for shell commands) |
| `step_name.exit_code` | Exit code (for shell commands) |

## MCP Tool Exposure

Pipelines can be exposed as MCP tools, allowing AI agents to trigger them.

### Enabling MCP Exposure

1. Open pipeline edit page
2. Expand "Pipeline Settings"
3. Toggle "Enable MCP Tool Access"
4. Define the input schema (or use "Derive from Steps")
5. Save

### Input Schema

The input schema defines what parameters the MCP tool accepts. It uses JSON Schema format.

**Basic schema:**
```json
{
  "type": "object",
  "properties": {
    "product_handle": {
      "type": "string",
      "description": "The Shopify product handle to query"
    },
    "shop_id": {
      "type": "integer",
      "description": "Shopify connection ID from shopifyconnections table"
    }
  },
  "required": ["product_handle", "shop_id"]
}
```

**Schema fields:**

| Field | Required | Description |
|-------|----------|-------------|
| `type` | Yes | Always `"object"` for MCP tools |
| `properties` | Yes | Object defining each parameter |
| `properties.xxx.type` | Yes | Parameter type: `string`, `integer`, `boolean`, `object`, `array` |
| `properties.xxx.description` | Recommended | Help text for AI agents |
| `required` | Recommended | Array of required parameter names |

### Derive from Steps

The "Derive from Steps" button automatically generates an input schema by scanning first-row steps for `{context.xxx}` variables.

**How it works:**
1. Scans all active steps in row 0
2. Extracts `{context.xxx}` patterns from step configurations
3. Creates a schema with each variable as a required string property

**Example:**

If your steps contain:
- `{context.product_handle}` in a GraphQL variables field
- `{context.shop_id}` in a connection_id field

Clicking "Derive from Steps" generates:
```json
{
  "type": "object",
  "properties": {
    "product_handle": {
      "type": "string",
      "description": "The product handle value (update this description for MCP)"
    },
    "shop_id": {
      "type": "string",
      "description": "The shop id value (update this description for MCP)"
    }
  },
  "required": ["product_handle", "shop_id"]
}
```

**IMPORTANT: After deriving, update the descriptions!**

The placeholder descriptions help remind you to add meaningful context. AI agents rely on descriptions to understand what values to provide.

**After deriving, you should:**
1. **Update descriptions** - Replace placeholders with clear, specific descriptions
2. Adjust types if needed (e.g., change `string` to `integer`)
3. Mark optional parameters by removing from `required` array

### MCP Endpoint

Once exposed, the pipeline is available at:
```
https://{workspace}.myctobot.ai/pipelines/mcptools
```

AI agents call the tool as `myctobot_{pipeline_slug}`.

**Example MCP tool call:**
```json
{
  "name": "myctobot_flash_sale",
  "arguments": {
    "product_handle": "summer-dress-collection",
    "shop_id": "3"
  }
}
```

## Step Types Reference

### direct_exec
Run a shell command on a workstation.

```json
{
  "step_type": "direct_exec",
  "config_json": {
    "command": "echo 'Processing {context.item}'",
    "working_dir": "/tmp",
    "workstation_id": 1
  }
}
```

### shopify_graphql
Execute GraphQL against a Shopify store.

```json
{
  "step_type": "shopify_graphql",
  "config_json": {
    "connection_id": "{context.shop_id}",
    "query": "query { shop { name } }",
    "variables": {}
  }
}
```

### ai_agent
Call an AI model (Claude, etc.).

```json
{
  "step_type": "ai_agent",
  "config_json": {
    "prompt": "Analyze this data: {previous_step.output}",
    "system_prompt": "You are a data analyst.",
    "model": "claude-sonnet-4-20250514",
    "max_tokens": 4096
  }
}
```

### parser
Transform data using jq or regex.

```json
{
  "step_type": "parser",
  "config_json": {
    "parser_type": "jq",
    "expression": ".data.products.edges | map(.node)"
  }
}
```

### webhook_out
POST to an external URL.

```json
{
  "step_type": "webhook_out",
  "config_json": {
    "url": "https://api.example.com/notify",
    "method": "POST",
    "headers": {
      "Authorization": "Bearer {context.api_token}"
    },
    "body": {
      "message": "{previous_step.output.summary}"
    }
  }
}
```

### email_out
Send email via Mailgun.

```json
{
  "step_type": "email_out",
  "config_json": {
    "to": "{context.recipient_email}",
    "subject": "Pipeline Complete: {pipeline_name}",
    "body": "Results: {final_step.output}"
  }
}
```

### mcp_call
Call an external MCP tool.

```json
{
  "step_type": "mcp_call",
  "config_json": {
    "transport": "http",
    "url": "https://api.example.com/mcp",
    "tool": "search_documents",
    "arguments": {
      "query": "{context.search_term}"
    }
  }
}
```

## Best Practices

### Naming Context Variables

Use descriptive snake_case names:
- `product_handle` (what it is)
- `shop_connection_id` (clarifies it's an ID)
- `recipient_email` (clear purpose)

Avoid:
- `x`, `data`, `input` (too vague)
- `productHandle` (use snake_case)

### Schema Descriptions (Critical for MCP)

**Descriptions are how AI agents understand your tool.** Without good descriptions, AI agents won't know:
- What values to provide
- What format is expected
- What the parameter is used for

Write descriptions that help AI agents:

**Good** - specific, includes format and example:
```json
{
  "product_handle": {
    "type": "string",
    "description": "Shopify product handle (URL slug), e.g., 'blue-summer-dress'"
  },
  "discount_percent": {
    "type": "integer",
    "description": "Discount percentage to apply (0-100), e.g., 20 for 20% off"
  }
}
```

**Bad** - vague, no context:
```json
{
  "product_handle": {
    "type": "string",
    "description": "The handle"
  },
  "discount_percent": {
    "type": "string",
    "description": "The discount percent value (update this description for MCP)"
  }
}
```

The second example shows what "Derive from Steps" generates - **always replace the placeholder!**

### Error Handling

Context variables are **required by default**. If a variable is missing:
- The pipeline will fail at the step using it
- Error message: `Missing context variable: xxx`

To make a variable optional:
1. Remove it from the `required` array in the schema
2. Handle the empty case in your step config

## Troubleshooting

### "No {context.xxx} variables found"

This means no first-row steps use context variables. Check:
- Are your steps in row 0?
- Are steps marked as active?
- Is the variable syntax correct? (`{context.xxx}` not `{{context.xxx}}`)

### Variable not being substituted

- Check the variable path is correct
- Ensure the source step completed successfully
- Check for typos in step names (they're case-sensitive)

### MCP tool not appearing

- Is "Enable MCP Tool Access" toggled on?
- Is the pipeline saved?
- Is the pipeline active?
- Does the input schema have valid JSON?

## API Reference

### Extract Variables Endpoint

```
GET /pipelines/extractvariables?id={pipeline_id}
```

Returns context variables found in first-row steps:

```json
{
  "success": true,
  "data": {
    "schema": {
      "type": "object",
      "properties": {
        "product_handle": { "type": "string" }
      },
      "required": ["product_handle"]
    },
    "variables": ["product_handle"]
  }
}
```

### MCP Tools Endpoint

```
GET /pipelines/mcptools/{workspace}
```

Returns MCP tool definitions for all exposed pipelines.

### Trigger Pipeline

```
POST /pipelines/trigger/{pipeline_id}
Content-Type: application/json

{
  "context": {
    "product_handle": "summer-dress",
    "shop_id": 3
  }
}
```

## Background Execution

When pipelines are triggered via MCP tools, they execute in the background. This prevents blocking the MCP caller (Claude) while long-running steps complete.

### How It Works

1. MCP tool call received (e.g., `myctobot_flash_sale`)
2. Pipeline run is created with `status: pending`
3. Background process spawned via `scripts/runpipe.php`
4. MCP response returns immediately with run info
5. Caller polls for completion using `get_run_context`

### MCP Response Format

When you call an MCP pipeline tool, you immediately receive:

```json
{
  "status": "running",
  "run_id": 123,
  "run_uid": "run-abc123def456",
  "message": "Pipeline 'Flash Sale' started in background. Use get_run_context with run_id=123 to check status and results.",
  "status_url": "https://workspace.myctobot.ai/pipelines/status/123"
}
```

### Polling for Results

Use the `get_run_context` MCP tool to check status:

```json
{
  "name": "get_run_context",
  "arguments": {
    "run_id": 123
  }
}
```

The context includes the current run status and all step outputs.

### CLI Execution

For direct CLI execution (cron, scripts), use `scripts/runpipe.php`:

```bash
# Run by slug
php scripts/runpipe.php --workspace=gwt --pipeline=my-pipeline

# Run by ID
php scripts/runpipe.php --workspace=gwt --pipeline-id=123

# Execute existing run
php scripts/runpipe.php --workspace=gwt --run-id=456

# With context data
php scripts/runpipe.php --workspace=gwt --pipeline=my-pipeline --context='{"key":"value"}'

# Verbose output
php scripts/runpipe.php --workspace=gwt --pipeline=my-pipeline --verbose
```

### Stuck Run Recovery

The cron job `scripts/cron-await-timeouts.php` handles stuck runs:

1. **Awaiting input timeouts** - Steps waiting for user input past their `timeout_at`
2. **Stuck running steps** - Steps in `running` status longer than their `timeout_seconds`

Each step honors its configured `timeout_seconds` (default: 300s). If a background process crashes, the cron marks the step and run as failed.

**Crontab entry:**
```
* * * * * cd /path/to/myctobot && php scripts/cron-await-timeouts.php --script >> log/cron-timeouts.log 2>&1
```
