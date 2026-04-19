<?php
/**
 * CRM Lead Enrichment Pipeline
 *
 * AI-powered enrichment pipeline for cold leads:
 * 1. Select unenriched cold contacts
 * 2. Research and enrich via web search + AI
 * 3. Update CRM contact records
 * 4. Notify admin with results summary
 */

$existing = \RedBeanPHP\R::findOne('pipelines', 'slug = ?', ['crm-lead-enrichment']);
if ($existing) {
    return;
}

$pipeline = \RedBeanPHP\R::dispense('pipelines');
$pipeline->member = $member;
$pipeline->name = 'CRM Lead Enrichment';
$pipeline->slug = 'crm-lead-enrichment';
$pipeline->description = 'AI-powered enrichment for cold CRM leads. Searches the web for company info, decision-makers, emails, and calculates lead scores.';
$pipeline->columns_json = json_encode(['Select', 'Enrich', 'Update', 'Notify']);
$pipeline->trigger_type = 'cron';
$pipeline->trigger_config_json = json_encode(['schedule' => '0 6 * * *']);
$pipeline->default_context_json = json_encode([
    'batch_limit' => 20,
    'workspace' => 'shipcannon',
]);
$pipeline->is_active = true;
$pipeline->is_system = false;
$pipeline->is_template = false;
$pipeline->expose_as_tool = true;
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => [
        'limit' => [
            'type' => 'integer',
            'description' => 'Max contacts to enrich in this run (default: 20)',
        ],
        'contact_id' => [
            'type' => 'integer',
            'description' => 'Optional: enrich a specific contact instead of batch',
        ],
    ],
    'required' => [],
]);
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pipeline);

// Step 1: Select unenriched leads
$step1 = \RedBeanPHP\R::dispense('pipelinesteps');
$step1->pipelines_id = $pipeline->id;
$step1->step_name = 'select_leads';
$step1->row = 0;
$step1->col = 0;
$step1->label = 'Select Leads';
$step1->step_type = 'direct_exec';
$step1->config_json = json_encode([
    'command' => 'php -r \'
        require_once "vendor/autoload.php";
        require_once "bootstrap.php";
        $app = new \app\Bootstrap("conf/config.shipcannon.ini");
        $contacts = \app\Bean::find("crmcontact",
            "(enrichment_status IS NULL OR enrichment_status = \"\" OR enrichment_status = \"pending\")" .
            " AND status_category = \"prospect\"" .
            " AND company_name IS NOT NULL AND company_name != \"\"" .
            " ORDER BY created_at ASC LIMIT " . ({context.limit} ?: 20));
        $ids = [];
        foreach ($contacts as $c) {
            $ids[] = ["id" => (int)$c->id, "company" => $c->company_name];
        }
        echo json_encode(["contacts" => $ids, "count" => count($ids)]);
    \'',
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

// Step 2: Research and enrich
$step2 = \RedBeanPHP\R::dispense('pipelinesteps');
$step2->pipelines_id = $pipeline->id;
$step2->step_name = 'research_and_enrich';
$step2->row = 0;
$step2->col = 1;
$step2->label = 'Research & Enrich';
$step2->step_type = 'direct_exec';
$step2->config_json = json_encode([
    'command' => 'php scripts/cron/cron-crm-enrichment.php --script --workspace=shipcannon --limit={context.limit}',
    'working_dir' => '/var/www/html/default/myctobot',
]);
$step2->input_source = 'prev';
$step2->input_config_json = '{}';
$step2->condition_json = '{}';
$step2->on_success = 'next_col';
$step2->on_failure = 'exit';
$step2->timeout_seconds = 600;
$step2->is_active = true;
$step2->run_parallel = false;
$step2->sequence = 2;
$step2->created_at = date('Y-m-d H:i:s');
$step2->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step2);

// Step 3: Update CRM (results already written by enrichment service)
$step3 = \RedBeanPHP\R::dispense('pipelinesteps');
$step3->pipelines_id = $pipeline->id;
$step3->step_name = 'summarize_results';
$step3->row = 0;
$step3->col = 2;
$step3->label = 'Summarize Results';
$step3->step_type = 'parser';
$step3->config_json = json_encode([
    'parser_type' => 'jq',
    'expression' => '{processed: .processed, enriched: .enriched, failed: .failed, details: [.details[] | {company, success, lead_score}]}',
]);
$step3->input_source = 'prev';
$step3->input_config_json = '{}';
$step3->condition_json = '{}';
$step3->on_success = 'next_col';
$step3->on_failure = 'exit';
$step3->timeout_seconds = 30;
$step3->is_active = true;
$step3->run_parallel = false;
$step3->sequence = 3;
$step3->created_at = date('Y-m-d H:i:s');
$step3->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step3);

// Step 4: Notify admin
$step4 = \RedBeanPHP\R::dispense('pipelinesteps');
$step4->pipelines_id = $pipeline->id;
$step4->step_name = 'notify_results';
$step4->row = 0;
$step4->col = 3;
$step4->label = 'Notify Admin';
$step4->step_type = 'mailgun';
$step4->config_json = json_encode([
    'to' => 'admin@example.com',
    'subject' => '[CRM Enrichment] {summarize_results.output.enriched}/{summarize_results.output.processed} contacts enriched',
    'content_type' => 'markdown',
    'body' => <<<'BODY'
# CRM Lead Enrichment Results

**Processed:** {summarize_results.output.processed}
**Enriched:** {summarize_results.output.enriched}
**Failed:** {summarize_results.output.failed}

## Details

{research_and_enrich.stdout}

---
*Automated by MyCTOBot Pipeline Engine*
BODY
]);
$step4->input_source = 'prev';
$step4->input_config_json = '{}';
$step4->condition_json = '{}';
$step4->on_success = 'exit';
$step4->on_failure = 'exit';
$step4->timeout_seconds = 60;
$step4->is_active = true;
$step4->run_parallel = false;
$step4->sequence = 4;
$step4->created_at = date('Y-m-d H:i:s');
$step4->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step4);
