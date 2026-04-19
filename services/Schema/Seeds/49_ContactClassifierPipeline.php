<?php
/**
 * Contact Classifier Pipeline
 *
 * AI-powered contact form classifier that:
 * 1. Stores submissions to app's SQLite datastore
 * 2. Classifies using Claude (spam, prospect, support, service)
 * 3. Routes based on classification
 * 4. Sends email notifications for non-spam
 *
 * This pipeline is designed to be used with the contact-classifier tenant app.
 */

// Check if pipeline already exists
$existing = \RedBeanPHP\R::findOne('pipelines', 'slug = ?', ['contact-classifier']);
if ($existing) {
    return; // Already exists, skip creation
}

// Create the Contact Classifier pipeline
$pipeline = \RedBeanPHP\R::dispense('pipelines');
$pipeline->member = $member;
$pipeline->name = 'Contact Classifier';
$pipeline->slug = 'contact-classifier';
$pipeline->description = 'AI-powered contact form classifier. Stores submissions, classifies intent, and routes appropriately.';
$pipeline->columns_json = json_encode(['Receive', 'Classify', 'Route', 'Notify']);
$pipeline->trigger_type = 'manual';
$pipeline->trigger_config_json = '{}';
$pipeline->default_context_json = json_encode([
    'app_slug' => 'contact-classifier'
]);
$pipeline->is_active = true;
$pipeline->is_system = false;
$pipeline->is_template = false;
$pipeline->expose_as_tool = true;
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => [
        'name' => [
            'type' => 'string',
            'description' => 'Your name'
        ],
        'email' => [
            'type' => 'string',
            'description' => 'Your email address'
        ],
        'category' => [
            'type' => 'string',
            'description' => 'Message category (general, support, billing, feature, bug, other)'
        ],
        'subject' => [
            'type' => 'string',
            'description' => 'Subject line'
        ],
        'message' => [
            'type' => 'string',
            'description' => 'Your message'
        ]
    ],
    'required' => ['name', 'email', 'message']
]);
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pipeline);

// ============================================================================
// Step 1: Store Submission (Col 0)
// ============================================================================
$step1 = \RedBeanPHP\R::dispense('pipelinesteps');
$step1->pipelines_id = $pipeline->id;
$step1->step_name = 'store_submission';
$step1->row = 0;
$step1->col = 0;
$step1->label = 'Store Submission';
$step1->step_type = 'datastore_query';
$step1->config_json = json_encode([
    'app_slug' => '{context.app_slug}',
    'operation' => 'insert',
    'table' => 'submissions',
    'data' => [
        'name' => '{context.name}',
        'email' => '{context.email}',
        'category' => '{context.category}',
        'subject' => '{context.subject}',
        'message' => '{context.message}',
        'ip_address' => '{context.source_ip}',
        'referrer' => '{context.referrer}',
        'user_agent' => '{context.user_agent}',
        'status' => 'new',
        'created_at' => 'NOW()'
    ]
]);
$step1->input_source = 'context';
$step1->input_config_json = '{}';
$step1->condition_json = '{}';
$step1->on_success = 'next_col';
$step1->on_failure = 'exit';
$step1->timeout_seconds = 30;
$step1->is_active = true;
$step1->run_parallel = false;
$step1->sequence = 1;
$step1->created_at = date('Y-m-d H:i:s');
$step1->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step1);

// ============================================================================
// Step 2: Classify with AI (Col 1)
// ============================================================================
$step2 = \RedBeanPHP\R::dispense('pipelinesteps');
$step2->pipelines_id = $pipeline->id;
$step2->step_name = 'classify_ai';
$step2->row = 0;
$step2->col = 1;
$step2->label = 'Classify with AI';
$step2->step_type = 'ai_agent';
$step2->config_json = json_encode([
    'execution_mode' => 'api',
    'model' => 'claude-3-haiku-20240307',
    'max_tokens' => 500,
    'json_output' => true,
    'prompt' => <<<'PROMPT'
Analyze this contact form submission and classify it.

**Submission:**
- Name: {context.name}
- Email: {context.email}
- Category: {context.category}
- Subject: {context.subject}
- Message: {context.message}

**Classification Rules:**
- **spam**: Obvious spam, promotional content, suspicious links, gibberish, or mass-mailing patterns
- **prospect**: Interest in products/services, pricing inquiries, demo requests, partnership opportunities
- **support**: Technical issues, bugs, account problems, how-to questions, existing customer needs help
- **service**: General inquiries, feedback, suggestions, not fitting other categories

**For prospects:** Estimate a lead_score (1-100) based on:
- Clear buying intent (80-100)
- Exploring options (50-79)
- Just curious (1-49)

**For support:** Set priority:
- critical: Service down, security issue, data loss
- high: Major feature broken, blocking work
- medium: Feature issue, workaround exists
- low: Minor issue, cosmetic, general questions

Respond with ONLY valid JSON (no markdown, no explanation):
{"classification": "spam|prospect|support|service", "confidence": 0.0-1.0, "reasoning": "brief explanation", "lead_score": null|1-100, "priority": null|"low"|"medium"|"high"|"critical"}
PROMPT
]);
$step2->input_source = 'prev';
$step2->input_config_json = '{}';
$step2->condition_json = '{}';
$step2->on_success = 'next_col';
$step2->on_failure = 'exit';
$step2->timeout_seconds = 60;
$step2->is_active = true;
$step2->run_parallel = false;
$step2->sequence = 2;
$step2->created_at = date('Y-m-d H:i:s');
$step2->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step2);

// ============================================================================
// Step 3: Route based on classification (Col 2)
// ============================================================================
$step3 = \RedBeanPHP\R::dispense('pipelinesteps');
$step3->pipelines_id = $pipeline->id;
$step3->step_name = 'route_switch';
$step3->row = 0;
$step3->col = 2;
$step3->label = 'Route';
$step3->step_type = 'switch';
$step3->config_json = json_encode([
    'value' => '{classify_ai.output.classification}',
    'cases' => [
        'spam' => 'update_spam',
        'prospect' => 'update_prospect',
        'support' => 'update_support',
        'service' => 'update_service'
    ],
    'default' => 'update_service'
]);
$step3->input_source = 'prev';
$step3->input_config_json = '{}';
$step3->condition_json = '{}';
$step3->on_success = 'dynamic';
$step3->on_failure = 'exit';
$step3->timeout_seconds = 30;
$step3->is_active = true;
$step3->run_parallel = false;
$step3->sequence = 3;
$step3->created_at = date('Y-m-d H:i:s');
$step3->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step3);

// ============================================================================
// Step 4a: Update as Spam (Row 1)
// ============================================================================
$step4a = \RedBeanPHP\R::dispense('pipelinesteps');
$step4a->pipelines_id = $pipeline->id;
$step4a->step_name = 'update_spam';
$step4a->row = 1;
$step4a->col = 2;
$step4a->label = 'Mark Spam';
$step4a->step_type = 'datastore_query';
$step4a->config_json = json_encode([
    'app_slug' => '{context.app_slug}',
    'operation' => 'update',
    'table' => 'submissions',
    'data' => [
        'classification' => 'spam',
        'confidence' => '{classify_ai.output.confidence}',
        'reasoning' => '{classify_ai.output.reasoning}',
        'status' => 'archived',
        'classified_at' => 'NOW()'
    ],
    'where' => [
        'id' => '{store_submission.output.last_insert_id}'
    ]
]);
$step4a->input_source = 'prev';
$step4a->input_config_json = '{}';
$step4a->condition_json = '{}';
$step4a->on_success = 'exit';
$step4a->on_failure = 'exit';
$step4a->timeout_seconds = 30;
$step4a->is_active = true;
$step4a->run_parallel = false;
$step4a->sequence = 4;
$step4a->created_at = date('Y-m-d H:i:s');
$step4a->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step4a);

// ============================================================================
// Step 4b: Update as Prospect (Row 2)
// ============================================================================
$step4b = \RedBeanPHP\R::dispense('pipelinesteps');
$step4b->pipelines_id = $pipeline->id;
$step4b->step_name = 'update_prospect';
$step4b->row = 2;
$step4b->col = 2;
$step4b->label = 'Mark Prospect';
$step4b->step_type = 'datastore_query';
$step4b->config_json = json_encode([
    'app_slug' => '{context.app_slug}',
    'operation' => 'update',
    'table' => 'submissions',
    'data' => [
        'classification' => 'prospect',
        'confidence' => '{classify_ai.output.confidence}',
        'reasoning' => '{classify_ai.output.reasoning}',
        'lead_score' => '{classify_ai.output.lead_score}',
        'status' => 'processed',
        'classified_at' => 'NOW()'
    ],
    'where' => [
        'id' => '{store_submission.output.last_insert_id}'
    ]
]);
$step4b->input_source = 'prev';
$step4b->input_config_json = '{}';
$step4b->condition_json = '{}';
$step4b->on_success = 'goto:notify_prospect';
$step4b->on_failure = 'exit';
$step4b->timeout_seconds = 30;
$step4b->is_active = true;
$step4b->run_parallel = false;
$step4b->sequence = 5;
$step4b->created_at = date('Y-m-d H:i:s');
$step4b->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step4b);

// ============================================================================
// Step 4c: Update as Support (Row 3)
// ============================================================================
$step4c = \RedBeanPHP\R::dispense('pipelinesteps');
$step4c->pipelines_id = $pipeline->id;
$step4c->step_name = 'update_support';
$step4c->row = 3;
$step4c->col = 2;
$step4c->label = 'Mark Support';
$step4c->step_type = 'datastore_query';
$step4c->config_json = json_encode([
    'app_slug' => '{context.app_slug}',
    'operation' => 'update',
    'table' => 'submissions',
    'data' => [
        'classification' => 'support',
        'confidence' => '{classify_ai.output.confidence}',
        'reasoning' => '{classify_ai.output.reasoning}',
        'priority' => '{classify_ai.output.priority}',
        'status' => 'processed',
        'classified_at' => 'NOW()'
    ],
    'where' => [
        'id' => '{store_submission.output.last_insert_id}'
    ]
]);
$step4c->input_source = 'prev';
$step4c->input_config_json = '{}';
$step4c->condition_json = '{}';
$step4c->on_success = 'goto:notify_support';
$step4c->on_failure = 'exit';
$step4c->timeout_seconds = 30;
$step4c->is_active = true;
$step4c->run_parallel = false;
$step4c->sequence = 6;
$step4c->created_at = date('Y-m-d H:i:s');
$step4c->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step4c);

// ============================================================================
// Step 4d: Update as Service (Row 4)
// ============================================================================
$step4d = \RedBeanPHP\R::dispense('pipelinesteps');
$step4d->pipelines_id = $pipeline->id;
$step4d->step_name = 'update_service';
$step4d->row = 4;
$step4d->col = 2;
$step4d->label = 'Mark Service';
$step4d->step_type = 'datastore_query';
$step4d->config_json = json_encode([
    'app_slug' => '{context.app_slug}',
    'operation' => 'update',
    'table' => 'submissions',
    'data' => [
        'classification' => 'service',
        'confidence' => '{classify_ai.output.confidence}',
        'reasoning' => '{classify_ai.output.reasoning}',
        'status' => 'processed',
        'classified_at' => 'NOW()'
    ],
    'where' => [
        'id' => '{store_submission.output.last_insert_id}'
    ]
]);
$step4d->input_source = 'prev';
$step4d->input_config_json = '{}';
$step4d->condition_json = '{}';
$step4d->on_success = 'goto:notify_general';
$step4d->on_failure = 'exit';
$step4d->timeout_seconds = 30;
$step4d->is_active = true;
$step4d->run_parallel = false;
$step4d->sequence = 7;
$step4d->created_at = date('Y-m-d H:i:s');
$step4d->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step4d);

// ============================================================================
// Step 5a: Notify Sales (Prospect) (Col 3, Row 2)
// ============================================================================
$step5a = \RedBeanPHP\R::dispense('pipelinesteps');
$step5a->pipelines_id = $pipeline->id;
$step5a->step_name = 'notify_prospect';
$step5a->row = 2;
$step5a->col = 3;
$step5a->label = 'Notify Sales';
$step5a->step_type = 'mailgun';
$step5a->config_json = json_encode([
    'to' => 'sales@myctobot.ai',
    'subject' => '[Lead Score: {classify_ai.output.lead_score}] New Prospect: {context.subject}',
    'content_type' => 'markdown',
    'body' => <<<'BODY'
# New Prospect Contact

**Lead Score:** {classify_ai.output.lead_score}/100
**Confidence:** {classify_ai.output.confidence}

## Contact Details
- **Name:** {context.name}
- **Email:** {context.email}
- **Category:** {context.category}

## Subject
{context.subject}

## Message
{context.message}

---
**AI Analysis:**
{classify_ai.output.reasoning}

---
*Submission ID: {store_submission.output.last_insert_id}*
BODY
]);
$step5a->input_source = 'prev';
$step5a->input_config_json = '{}';
$step5a->condition_json = '{}';
$step5a->on_success = 'exit';
$step5a->on_failure = 'exit';
$step5a->timeout_seconds = 60;
$step5a->is_active = true;
$step5a->run_parallel = false;
$step5a->sequence = 8;
$step5a->created_at = date('Y-m-d H:i:s');
$step5a->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step5a);

// ============================================================================
// Step 5b: Notify Support (Col 3, Row 3)
// ============================================================================
$step5b = \RedBeanPHP\R::dispense('pipelinesteps');
$step5b->pipelines_id = $pipeline->id;
$step5b->step_name = 'notify_support';
$step5b->row = 3;
$step5b->col = 3;
$step5b->label = 'Notify Support';
$step5b->step_type = 'mailgun';
$step5b->config_json = json_encode([
    'to' => 'support@myctobot.ai',
    'subject' => '[{classify_ai.output.priority}] Support Request: {context.subject}',
    'content_type' => 'markdown',
    'body' => <<<'BODY'
# Support Request

**Priority:** {classify_ai.output.priority}
**Confidence:** {classify_ai.output.confidence}

## Contact Details
- **Name:** {context.name}
- **Email:** {context.email}
- **Category:** {context.category}

## Subject
{context.subject}

## Message
{context.message}

---
**AI Analysis:**
{classify_ai.output.reasoning}

---
*Submission ID: {store_submission.output.last_insert_id}*
BODY
]);
$step5b->input_source = 'prev';
$step5b->input_config_json = '{}';
$step5b->condition_json = '{}';
$step5b->on_success = 'exit';
$step5b->on_failure = 'exit';
$step5b->timeout_seconds = 60;
$step5b->is_active = true;
$step5b->run_parallel = false;
$step5b->sequence = 9;
$step5b->created_at = date('Y-m-d H:i:s');
$step5b->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step5b);

// ============================================================================
// Step 5c: Notify General (Service) (Col 3, Row 4)
// ============================================================================
$step5c = \RedBeanPHP\R::dispense('pipelinesteps');
$step5c->pipelines_id = $pipeline->id;
$step5c->step_name = 'notify_general';
$step5c->row = 4;
$step5c->col = 3;
$step5c->label = 'Notify General';
$step5c->step_type = 'mailgun';
$step5c->config_json = json_encode([
    'to' => 'hello@myctobot.ai',
    'subject' => '[General] Contact: {context.subject}',
    'content_type' => 'markdown',
    'body' => <<<'BODY'
# General Contact

**Classification:** Service/General
**Confidence:** {classify_ai.output.confidence}

## Contact Details
- **Name:** {context.name}
- **Email:** {context.email}
- **Category:** {context.category}

## Subject
{context.subject}

## Message
{context.message}

---
**AI Analysis:**
{classify_ai.output.reasoning}

---
*Submission ID: {store_submission.output.last_insert_id}*
BODY
]);
$step5c->input_source = 'prev';
$step5c->input_config_json = '{}';
$step5c->condition_json = '{}';
$step5c->on_success = 'exit';
$step5c->on_failure = 'exit';
$step5c->timeout_seconds = 60;
$step5c->is_active = true;
$step5c->run_parallel = false;
$step5c->sequence = 10;
$step5c->created_at = date('Y-m-d H:i:s');
$step5c->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step5c);
