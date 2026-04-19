# Recipe: Contact Form with AI Classification

## Tags
forms, email, AI, classification, routing

## Description
AI-powered contact form that classifies incoming messages by intent
(support, billing, feature request, bug report) and routes them
appropriately. Stores submissions, sends confirmation emails, and
forwards to the right team.

## Prerequisites
- connection:mailgun — For sending confirmation and routing emails

## Reuse Checklist
Before building anything, check the workspace manifest for:
1. Pipeline with slug containing "contact" or "classifier" -> CLONE it
2. Dapp with slug containing "contact" -> already exists, reconfigure
3. Knowledge base with FAQ content -> wire into classification for better accuracy

## Assembly Steps

### Step 1: Ensure Mailgun connection
- CHECK: `get_workspace_manifest` sections=["connections"]
- LOOK FOR: connection with type "mailgun"
- IF MISSING: Tell user to configure Mailgun at /connections

### Step 2: Create or clone classifier pipeline
- CHECK: manifest pipelines for slug containing "contact-classifier"
- IF FOUND: `clone_pipeline` slug="{found_slug}" new_slug="{brand}-contact-classifier"
  - step_overrides.classify_ai.config.system_prompt = customized for brand
  - step_overrides.classify_ai.config.categories = adjusted categories
- IF NOT FOUND: `import_pipeline` from template (check pipeline_templates for "contact")
- Pipeline should have these steps:
  1. Store submission (direct_exec or parser)
  2. AI classification (llm_call or ai_agent)
  3. Route by category (switch step)
  4. Send confirmation email (email_out)
  5. Forward to team (email_out with category-specific recipient)

### Step 3: Expose as MCP tool
- `set_pipeline` pipeline_id={new_id} expose_as_tool=true
- Set input_schema: name, email, message, subject (optional), category (optional)

### Step 4: Create dapp (optional)
- If user wants a hosted form page:
- CHECK: manifest app_templates for "contact-form" template
- Create dapp, bind pipeline, set is_hosted=true
- Accessible at /dapp/{slug}

### Step 5: Verify
- `run_pipeline` slug="{brand}-contact-classifier" input={"name":"Test","email":"test@example.com","message":"I need help with my order"}
- Verify classification output
- Verify confirmation email sent

## Customization Points
| What | Where | Default |
|------|-------|---------|
| Categories | classify step config | general, support, billing, feature, bug |
| AI model | classify step config | Default workspace model |
| Routing rules | switch step config | Email forwarding by category |
| Confirmation template | email_out step | Generic thank-you |
| Team emails | route steps | Configured per category |
