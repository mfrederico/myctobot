# Recipe: Shopify Flash Sale Orchestrator

## Tags
shopify, commerce, flash-sale, scheduling

## Description
Automated flash sale orchestrator for Shopify stores. Temporarily reduces product
prices, runs the sale for a configured duration, then restores original prices.
Includes scheduling support for timed sales and email notifications.

## Prerequisites
- connection:shopify — Active Shopify store connection with write access
- connection:mailgun — For sale notification emails (optional)

## Reuse Checklist
Before building anything, check the workspace manifest for:
1. Pipeline with slug containing "flash-sale" or "sale-orchestrator" -> CLONE it
2. Pipeline with slug containing "shopify-graphql" -> reuse as reference for GraphQL patterns
3. Existing schedule for sales -> update rather than create new

## Assembly Steps

### Step 1: Ensure Shopify connection
- CHECK: `get_workspace_manifest` sections=["connections"]
- LOOK FOR: connection with type "shopify"
- IF MISSING: Tell user to connect their store at /connections

### Step 2: Create or clone flash sale pipeline
- CHECK: manifest pipelines for slug containing "flash-sale"
- IF FOUND: `clone_pipeline` slug="{found_slug}" new_slug="{store}-flash-sale"
  - step_overrides as needed for discount percentage, duration
- IF NOT FOUND: `import_pipeline` from template (check pipeline_templates for "flash-sale")
- Pipeline should have these steps:
  1. Fetch product by handle (shopify_graphql)
  2. Store original price in context
  3. Apply discount (shopify_graphql mutation)
  4. Wait for sale duration (wait step)
  5. Restore original price (shopify_graphql mutation)
  6. Send completion notification (email_out, optional)

### Step 3: Expose as MCP tool
- `set_pipeline` pipeline_id={new_id} expose_as_tool=true
- Set input_schema with product_handle parameter

### Step 4: Create schedule (optional)
- If user wants recurring sales: `create_schedule` with appropriate timing
- Example: weekly flash sale every Friday at 10am

### Step 5: Verify
- `run_pipeline` slug="{store}-flash-sale" input={"product_handle": "test-product"}
- Verify price changed in Shopify admin
- Verify price restored after duration

## Customization Points
| What | Where | Default |
|------|-------|---------|
| Discount percentage | pipeline step config | 20% |
| Sale duration | wait step config | 1 hour |
| Product selection | input_schema | Single product by handle |
| Notification email | email_out step | Store owner email |
| Schedule | create_schedule | Manual trigger |
