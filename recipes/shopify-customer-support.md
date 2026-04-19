# Recipe: Shopify Customer Support Chatbot

## Tags
shopify, chat, customer-support, knowledge-base, dapp

## Description
Complete AI-powered customer support chatbot for a Shopify store. Includes
order lookup, return requests, FAQ search via knowledge base, and email
transcript delivery. Uses dual-model architecture: fast local model for
intent classification, cloud model for deep reasoning.

## Prerequisites
- connection:shopify — Active Shopify store connection
- connection:mailgun — For sending email transcripts
- knowledge_base:any — Store FAQ/policies (optional but recommended)

## Reuse Checklist
Before building anything, check the workspace manifest for:
1. Pipeline with description matching "customer support" or "chat orchestrator" -> CLONE instead of building from scratch
2. Dapp with slug containing "support" or "chat" -> already exists, just reconfigure
3. Knowledge base -> wire existing KB into pipeline, don't create new
4. Email transcript pipeline -> clone if similar exists

## Assembly Steps

### Step 1: Ensure Shopify connection
- CHECK: `get_workspace_manifest` sections=["connections"]
- LOOK FOR: connection with type "shopify"
- IF MISSING: Tell user to connect their store at /connections

### Step 2: Ensure Mailgun connection
- CHECK: manifest connections for type "mailgun"
- IF MISSING: Tell user to configure Mailgun at /connections

### Step 3: Create or clone chat pipeline
- CHECK: manifest pipelines for slug containing "chat" or "support"
- IF FOUND: `clone_pipeline` slug="{found_slug}" new_slug="{store}-support-chat"
  - step_overrides.classify_intent.config.system_prompt = "You are {store_name} support..."
- IF NOT FOUND: `import_pipeline` from template (check pipeline_templates for "customer-support")
  - Then customize system_prompt via `set_step`

### Step 4: Create transcript email pipeline
- CHECK: manifest pipelines for slug containing "transcript" or "email-chat"
- IF FOUND: clone it with `clone_pipeline`
- IF NOT FOUND: `import_pipeline` from template (check pipeline_templates for "transcript")

### Step 5: Create or configure knowledge base
- CHECK: manifest knowledge_bases
- IF FOUND: Note the KB slug for pipeline config
- IF NOT FOUND: Tell user to create one at /knowledgebase and upload their FAQ docs

### Step 6: Create dapp
- CHECK: manifest dapps for slug containing "support" or "chat"
- IF FOUND: Reconfigure with new pipeline binding
- IF NOT FOUND: Create new dapp from app_templates (look for "customer-support-widget")
  - Bind the chat pipeline created in Step 3
  - Set is_hosted = true

### Step 7: Verify
- `run_pipeline` slug="{store}-support-chat" with test input: {"message": "What is your return policy?"}
- Check response is coherent
- Confirm dapp accessible at /dapp/{slug}

## Customization Points
| What | Where | Default |
|------|-------|---------|
| Bot personality | classify_intent step -> system_prompt | Generic helpful assistant |
| Intent forms | dapp config_json -> intent_plugins | order_lookup, return_request |
| KB content | knowledge base docs | Empty (user uploads) |
| Email sender | email step config -> from_email | noreply@myctobot.ai |
| Fast classifier model | fast classify config | qwen2.5:3b on port 11435 |
