# Pipeline Creation Skill

You are helping the user create a new pipeline. Follow these steps:

## Step 1: Gather Requirements

Ask the user about:
1. **Purpose**: What should this pipeline do?
2. **Trigger**: How should it start?
   - `manual` - User triggers via MCP tool or UI
   - `webhook` - External service calls a URL
   - `cron` - Scheduled execution
3. **Inputs**: What data does it need to start?

## Step 2: Discover Available Components

Call `get_pipeline_components` to see:
- Available step types (llm_call, direct_exec, shopify_graphql, etc.)
- User's integrations (Shopify stores, workstations, AI agents, etc.)
- Variable substitution syntax

## Step 3: Design the Pipeline

Based on requirements, design:
1. **Columns**: Logical groupings (e.g., ["Fetch", "Process", "Notify"])
2. **Steps**: Each step needs:
   - `step_name`: lowercase_with_underscores (e.g., `fetch_data`)
   - `step_type`: From available types
   - `row`/`col`: Grid position (0-based)
   - `config`: Type-specific configuration
   - `on_success`: Usually `next_col` or `goto:step_name`
   - `on_failure`: Usually `exit` or `goto:error_handler`

## Step 4: Validate First

Always use `dry_run: true` first:
```json
{
  "name": "My Pipeline",
  "steps": [...],
  "dry_run": true
}
```

Review the validation result before creating.

## Step 5: Create and Test

1. Call `set_pipeline` with `dry_run: false`
2. Call `run_pipeline` with test input
3. Monitor with `get_run` and `get_run_context`

## Common Patterns

### Linear Pipeline
```
[Step A] -> [Step B] -> [Step C]
col: 0      col: 1      col: 2
on_success: next_col for all
```

### Conditional Routing
```
[Classify] -> [Switch] -> [Path A] or [Path B]
Use switch step with cases mapping to step names
```

### Error Handling
```
[Main Step] --on_failure--> [Error Handler] -> [Notify]
```

### Parallel Execution
```
Row 0: [Step A1] -> [Step A2]
Row 1: [Step B1] -> [Step B2]
Both rows execute in parallel within each column
```

## Variable Substitution

- `{context.key}` - Pipeline input or shared context
- `{step_name.output.field}` - Previous step's output
- `{step_name.stdout}` - Raw stdout from direct_exec steps
