# Unified Pipeline Input Gateway Design

## Overview

This document describes the architecture for pausing pipelines to await input from multiple sources (MCP, webhook, web form, email), then resuming execution when input arrives.

## The Problem

When a pipeline needs user input mid-execution (e.g., "which item do you want to return?"), we need to:
1. PAUSE the pipeline without blocking
2. Return partial results to the caller
3. Accept input from ANY source (AI, form, webhook, email)
4. RESUME execution with the new input
5. Chain the result back to the original caller

## Architecture

```
Pipeline pauses: "I need input matching this schema"
                         │
                         ▼
              ┌─────────────────────┐
              │  Input Gateway      │
              │  /pipelines/input/  │
              │  {run_id}           │
              └─────────────────────┘
                         ▲
         ┌───────────────┼───────────────┐───────────────┐
         │               │               │               │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │   MCP   │    │ Webhook │    │Web Form │    │  Email  │
    │continue_│    │  POST   │    │ Submit  │    │ Parser  │
    │pipeline │    │         │    │         │    │         │
    └─────────┘    └─────────┘    └─────────┘    └─────────┘
```

All input sources POST to the same unified endpoint. The pipeline doesn't care WHERE input comes from.

## Database Schema

Add to `pipelineruns` table:

```sql
ALTER TABLE pipelineruns ADD COLUMN awaiting_input TINYINT DEFAULT 0;
ALTER TABLE pipelineruns ADD COLUMN awaiting_step_id INT NULL;
ALTER TABLE pipelineruns ADD COLUMN awaiting_schema_json JSON NULL;
ALTER TABLE pipelineruns ADD COLUMN awaiting_prompt TEXT NULL;
ALTER TABLE pipelineruns ADD COLUMN awaiting_timeout_at DATETIME NULL;
ALTER TABLE pipelineruns ADD COLUMN awaiting_sources_json JSON NULL;
ALTER TABLE pipelineruns ADD COLUMN input_token VARCHAR(64) NULL;
```

New status value: `awaiting_input`

## Wait Step Configuration

New wait type `await_input`:

```json
{
  "step_type": "wait",
  "config_json": {
    "wait_type": "await_input",
    "input_schema": {
      "type": "object",
      "properties": {
        "selected_item": { "type": "string", "description": "Item ID to return" },
        "reason": { "type": "string", "description": "Return reason" }
      },
      "required": ["selected_item"]
    },
    "prompt": "Which item would you like to return?",
    "timeout_seconds": 86400,
    "allowed_sources": ["mcp", "webhook", "form", "email"],
    "notification": {
      "email": "{context.customer_email}",
      "subject": "Action required: Select return item",
      "template": "return_selection"
    }
  }
}
```

## PipelineExecutor Changes

### executeWait() - Handle await_input

```php
case 'await_input':
    // Generate input token for security
    $inputToken = bin2hex(random_bytes(16));

    // Save pause state to database
    $run = Bean::load('pipelineruns', $this->runId);
    $run->status = 'awaiting_input';
    $run->awaiting_input = 1;
    $run->awaiting_step_id = $this->currentStepId;
    $run->awaiting_schema_json = json_encode($config['input_schema'] ?? []);
    $run->awaiting_prompt = $config['prompt'] ?? 'Waiting for input';
    $run->awaiting_timeout_at = date('Y-m-d H:i:s', time() + ($config['timeout_seconds'] ?? 86400));
    $run->awaiting_sources_json = json_encode($config['allowed_sources'] ?? ['mcp', 'webhook', 'form']);
    $run->input_token = $inputToken;
    $run->context_json = json_encode($this->context); // Save current context
    Bean::store($run);

    // Send notifications if configured
    if (!empty($config['notification'])) {
        $this->sendAwaitingNotification($config['notification']);
    }

    // Return special response - don't continue to next step
    return [
        'success' => true,
        'awaiting_input' => true,
        'input_token' => $inputToken,
        'input_schema' => $config['input_schema'] ?? [],
        'prompt' => $config['prompt'] ?? 'Waiting for input',
        'output' => $this->context,
        'form_url' => "/pipelines/form/{$this->runId}?token={$inputToken}",
        'webhook_url' => "/pipelines/input/{$this->runId}?token={$inputToken}"
    ];
```

### Resume Logic

```php
public function resumeFromInput(int $runId, array $input, string $source): array {
    $run = Bean::load('pipelineruns', $runId);

    // Validate run is awaiting input
    if (!$run->awaiting_input) {
        throw new \Exception('Pipeline is not awaiting input');
    }

    // Validate timeout
    if (strtotime($run->awaiting_timeout_at) < time()) {
        throw new \Exception('Input window has expired');
    }

    // Validate input against schema
    $schema = json_decode($run->awaiting_schema_json, true);
    // TODO: JSON Schema validation

    // Restore context and inject input
    $this->context = json_decode($run->context_json, true);
    $this->context['_input'] = $input;
    $this->context['_input_source'] = $source;

    // Clear awaiting state
    $run->awaiting_input = 0;
    $run->status = 'running';
    Bean::store($run);

    // Resume execution from the step AFTER the await step
    return $this->executeFromStep($run->awaiting_step_id + 1);
}
```

## Input Sources

### 1. MCP - continue_pipeline Tool

```php
// In Mcppipelines.php - new tool
case 'continue_pipeline':
    $runId = $params['arguments']['run_id'] ?? null;
    $input = $params['arguments']['input'] ?? [];
    $token = $params['arguments']['token'] ?? null;

    $run = Bean::load('pipelineruns', $runId);
    if ($run->input_token !== $token) {
        return $this->sendJsonRpcError(-32602, 'Invalid input token', $id);
    }

    $executor = new PipelineExecutor($run->pipelines_id, $this->memberId);
    $result = $executor->resumeFromInput($runId, $input, 'mcp');

    return $this->sendJsonRpcResult($result, $id);
```

### 2. Webhook - POST /pipelines/input/{run_id}

```php
// In Pipelines.php
public function input($params = []) {
    $runId = (int) $this->opId();
    $token = $this->getParam('token');
    $inputData = json_decode(file_get_contents('php://input'), true);

    $run = Bean::load('pipelineruns', $runId);

    if (!$run->id || !$run->awaiting_input) {
        Flight::jsonError('Pipeline not awaiting input', 400);
        return;
    }

    if ($run->input_token !== $token) {
        Flight::jsonError('Invalid token', 403);
        return;
    }

    $executor = new PipelineExecutor($run->pipelines_id, $this->memberId);
    $result = $executor->resumeFromInput($runId, $inputData, 'webhook');

    Flight::jsonSuccess($result);
}
```

### 3. Web Form - GET/POST /pipelines/form/{run_id}

```php
// GET - Render form based on input_schema
public function form($params = []) {
    $runId = (int) $this->opId();
    $token = $this->getParam('token');

    $run = Bean::load('pipelineruns', $runId);

    // Validate
    if (!$run->id || !$run->awaiting_input || $run->input_token !== $token) {
        $this->render('pipelines/form_error', ['error' => 'Invalid or expired link']);
        return;
    }

    $schema = json_decode($run->awaiting_schema_json, true);
    $prompt = $run->awaiting_prompt;
    $context = json_decode($run->context_json, true);

    $this->render('pipelines/form_input', [
        'run_id' => $runId,
        'token' => $token,
        'schema' => $schema,
        'prompt' => $prompt,
        'context' => $context
    ]);
}

// POST - Submit form, resume pipeline
public function formsubmit($params = []) {
    $runId = (int) $this->opId();
    $token = $this->getParam('token');
    $inputData = $_POST;
    unset($inputData['token']); // Remove token from input

    // ... same validation as webhook ...

    $result = $executor->resumeFromInput($runId, $inputData, 'form');

    // Redirect to success page or show result
    $this->render('pipelines/form_success', ['result' => $result]);
}
```

### 4. Email Reply (Future)

Email parser service watches for replies to `pipeline+{run_id}@myctobot.ai`:

```php
// In email webhook handler
public function inboundEmail() {
    $to = $this->getParam('to'); // pipeline+123@myctobot.ai
    $body = $this->getParam('body');

    // Extract run_id from address
    preg_match('/pipeline\+(\d+)@/', $to, $matches);
    $runId = $matches[1] ?? null;

    // Parse body into structured input (may use AI)
    $input = $this->parseEmailResponse($body, $run->awaiting_schema_json);

    $executor->resumeFromInput($runId, $input, 'email');
}
```

## Multi-Turn MCP Flow Example

### Returns Flow

```
1. Customer: "I want to return something"

2. AI calls MCP: start_return_flow(email: "john@example.com", order: "#1234")

3. Pipeline executes:
   - Step 1: verify_order → success, order found
   - Step 2: get_returnable_items → [item1, item2, item3]
   - Step 3: await_input (wait_type: await_input)
     - Pauses pipeline
     - Sends email to customer with form link
     - Returns to MCP:
       {
         "status": "awaiting_input",
         "continuation_token": "run-abc123",
         "input_token": "xyz789",
         "prompt": "Which item would you like to return?",
         "data": { "items": [...] },
         "form_url": "/pipelines/form/123?token=xyz789"
       }

4. AI tells customer: "I found your order! Which item: blue shirt, red pants, or green hat?"
   (Also mentions: "Or use this link to select: {form_url}")

5. Customer replies: "The blue shirt"

6. AI calls MCP: continue_pipeline(run_id: 123, token: "xyz789", input: { "selected_item": "blue-shirt" })

7. Pipeline resumes:
   - Step 4: calculate_return_cost → $5.99
   - Step 5: await_input (confirm cost)
     - Returns: { "status": "awaiting_input", "prompt": "Shipping is $5.99. Proceed?" }

8. AI: "Shipping will cost $5.99. Should I proceed?"

9. Customer: "Yes"

10. AI calls: continue_pipeline(run_id: 123, input: { "confirmed": true })

11. Pipeline resumes:
    - Step 6: create_return → return created
    - Step 7: generate_label → label URL
    - Step 8: send_email → label sent
    - Pipeline completes

12. AI: "Done! I've created your return and sent the label to your email."
```

## UI Changes

### Wait Step Config Panel

Add to `views/pipelines/components/_config_wait.php`:

```html
<option value="await_input">Await Input (from MCP, form, webhook, or email)</option>
```

When `await_input` selected, show:
- Input Schema (JSON editor)
- Prompt text
- Timeout (seconds)
- Allowed sources (checkboxes: MCP, Webhook, Form, Email)
- Notification settings (optional email/SMS to send)

### Pipeline Run View

When status = `awaiting_input`, show:
- Current prompt
- Expected input schema
- Form link (copyable)
- Webhook URL (copyable)
- Time remaining until timeout
- "Provide Input" button (opens form in modal)

## Implementation Checklist

1. [x] Add database fields to pipelineruns (auto-created by RedBeanPHP on pipelinestepruns)
2. [x] Add `await_input` case to PipelineExecutor::executeWait()
3. [x] Add resumeFromAwaitInput() method to PipelineExecutor
4. [x] Update wait step config UI with await_input option
5. [x] Add /pipelines/input/{run_id} endpoint
6. [x] Add /pipelines/form/{run_id} GET/POST endpoints
7. [x] Create form_input.php view (renders form from schema)
8. [x] Add continue_pipeline tool to Mcppipelines.php
9. [x] Update pipeline run view to show awaiting state
10. [x] Add awaiting_input response handling to MCP executePipeline
11. [ ] Test full flow: MCP start → pause → MCP continue → complete
12. [ ] Test form flow: MCP start → pause → form submit → complete

## Security Considerations

1. **Input tokens** - Random tokens prevent unauthorized input submission
2. **Timeout** - Awaiting state expires to prevent indefinite waits
3. **Source validation** - Can restrict which sources are allowed per step
4. **Schema validation** - Input must match expected schema
5. **Member ownership** - Only pipeline owner or authorized users can provide input

## Future Enhancements

1. **Email reply parsing** - Use AI to extract structured data from email replies
2. **SMS input** - Parse SMS replies for simple confirmations
3. **Slack/Teams integration** - Interactive buttons that submit input
4. **Multi-input aggregation** - Wait for input from multiple parties before resuming
5. **Conditional routing** - Different next steps based on input values
