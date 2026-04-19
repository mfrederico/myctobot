<?php
/**
 * CRM Outreach Sequence Pipeline
 *
 * 4-step email sequence for enriched warm/hot leads:
 * 1. Select eligible contacts (enriched, scored >= 40, warm/hot)
 * 2. Compose personalized email via AI
 * 3. Send via Mailgun
 * 4. Update tracking (outreach_status, step, touch log)
 */

$existing = \RedBeanPHP\R::findOne('pipelines', 'slug = ?', ['crm-outreach-sequence']);
if ($existing) {
    return;
}

$pipeline = \RedBeanPHP\R::dispense('pipelines');
$pipeline->member = $member;
$pipeline->name = 'CRM Outreach Sequence';
$pipeline->slug = 'crm-outreach-sequence';
$pipeline->description = 'Automated multi-step email outreach for enriched CRM leads. Sends personalized emails with 3-day gaps between sequence steps.';
$pipeline->columns_json = json_encode(['Select', 'Compose', 'Send', 'Track']);
$pipeline->trigger_type = 'cron';
$pipeline->trigger_config_json = json_encode(['schedule' => '0 9 * * *']);
$pipeline->default_context_json = json_encode([
    'workspace' => 'shipcannon',
    'max_daily_sends' => 20,
    'gap_days' => 3,
    'min_lead_score' => 40,
]);
$pipeline->is_active = true;
$pipeline->is_system = false;
$pipeline->is_template = false;
$pipeline->expose_as_tool = true;
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => [
        'contact_id' => [
            'type' => 'integer',
            'description' => 'Optional: send outreach to a specific contact',
        ],
        'max_sends' => [
            'type' => 'integer',
            'description' => 'Max emails to send this run (default: 20)',
        ],
    ],
    'required' => [],
]);
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pipeline);

// Step 1: Select eligible contacts
$step1 = \RedBeanPHP\R::dispense('pipelinesteps');
$step1->pipelines_id = $pipeline->id;
$step1->step_name = 'select_contacts';
$step1->row = 0;
$step1->col = 0;
$step1->label = 'Select Contacts';
$step1->step_type = 'direct_exec';
$step1->config_json = json_encode([
    'command' => 'php scripts/cron/cron-crm-outreach.php --script --workspace=shipcannon --select-only --limit={context.max_sends} --json',
    'working_dir' => '/var/www/html/default/myctobot',
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

// Step 2: Compose personalized email
$step2 = \RedBeanPHP\R::dispense('pipelinesteps');
$step2->pipelines_id = $pipeline->id;
$step2->step_name = 'compose_email';
$step2->row = 0;
$step2->col = 1;
$step2->label = 'Compose Email';
$step2->step_type = 'ai_agent';
$step2->config_json = json_encode([
    'execution_mode' => 'api',
    'model' => 'claude-sonnet-4-20250514',
    'max_tokens' => 1000,
    'json_output' => true,
    'prompt' => <<<'PROMPT'
You are a sales development rep for ShipCannon, a 3PL/fulfillment software company.
Write a personalized outreach email for this contact.

**Contact Info:**
{select_contacts.stdout}

**Current sequence step:** {context.outreach_step}

**Email Sequence Templates:**
- Step 0 (Initial intro): Mention their company by name, what you noticed about their fulfillment/shipping. Keep it brief and personal. Ask a question.
- Step 1 (Follow-up): Share a specific value proposition relevant to their business size/type. Mention one concrete benefit.
- Step 2 (Case study): Share a brief success story of a similar company that saved time/money with ShipCannon.
- Step 3 (Break-up): "Last chance" pattern. Brief, direct, give them an easy out while leaving door open.

**Rules:**
- Keep subject under 50 chars, no spam words
- Keep body under 150 words
- Sound human, not corporate
- Include a clear CTA (question or link)
- Never use "Dear Sir/Madam" or generic salutations

Return ONLY valid JSON:
{"to": "email@example.com", "subject": "subject line", "body": "email body in plain text"}
PROMPT
]);
$step2->input_source = 'prev';
$step2->input_config_json = '{}';
$step2->condition_json = '{}';
$step2->on_success = 'next_col';
$step2->on_failure = 'exit';
$step2->timeout_seconds = 120;
$step2->is_active = true;
$step2->run_parallel = false;
$step2->sequence = 2;
$step2->created_at = date('Y-m-d H:i:s');
$step2->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step2);

// Step 3: Send via Mailgun
$step3 = \RedBeanPHP\R::dispense('pipelinesteps');
$step3->pipelines_id = $pipeline->id;
$step3->step_name = 'send_email';
$step3->row = 0;
$step3->col = 2;
$step3->label = 'Send Email';
$step3->step_type = 'mailgun';
$step3->config_json = json_encode([
    'to' => '{compose_email.output.to}',
    'subject' => '{compose_email.output.subject}',
    'body' => '{compose_email.output.body}',
    'from_name' => 'ShipCannon Team',
]);
$step3->input_source = 'prev';
$step3->input_config_json = '{}';
$step3->condition_json = '{}';
$step3->on_success = 'next_col';
$step3->on_failure = 'exit';
$step3->timeout_seconds = 60;
$step3->is_active = true;
$step3->run_parallel = false;
$step3->sequence = 3;
$step3->created_at = date('Y-m-d H:i:s');
$step3->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step3);

// Step 4: Update tracking
$step4 = \RedBeanPHP\R::dispense('pipelinesteps');
$step4->pipelines_id = $pipeline->id;
$step4->step_name = 'update_tracking';
$step4->row = 0;
$step4->col = 3;
$step4->label = 'Update Tracking';
$step4->step_type = 'direct_exec';
$step4->config_json = json_encode([
    'command' => 'php scripts/cron/cron-crm-outreach.php --script --workspace=shipcannon --update-tracking --contact-id={context.contact_id} --json',
    'working_dir' => '/var/www/html/default/myctobot',
]);
$step4->input_source = 'prev';
$step4->input_config_json = '{}';
$step4->condition_json = '{}';
$step4->on_success = 'exit';
$step4->on_failure = 'exit';
$step4->timeout_seconds = 30;
$step4->is_active = true;
$step4->run_parallel = false;
$step4->sequence = 4;
$step4->created_at = date('Y-m-d H:i:s');
$step4->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step4);
