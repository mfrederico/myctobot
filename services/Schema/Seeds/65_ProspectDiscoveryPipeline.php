<?php
/**
 * Prospect Discovery Pipeline
 *
 * Full sales prospect pipeline that runs daily:
 * 1. Discover - Search for ecommerce stores via Google/Serper
 * 2. Analyze  - Fetch pages, detect platform, AI analysis for self-shipping
 * 3. Push     - Push qualified leads into CRM as cold prospects
 * 4. Enrich   - Web search + AI to fill missing contact details & lead scores
 * 5. Outreach - Email sequence for warm/hot enriched leads
 *
 * Each step wraps an existing PHP script. All config reads from
 * ProspectSettingsService (DB -> INI -> defaults).
 */

$existing = \RedBeanPHP\R::findOne('pipelines', 'slug = ?', ['prospect-discovery']);
if ($existing) {
    return;
}

$workingDir = '/var/www/html/default/myctobot';

$pipeline = \RedBeanPHP\R::dispense('pipelines');
$pipeline->member = $member;
$pipeline->name = 'Prospect Discovery Pipeline';
$pipeline->slug = 'prospect-discovery';
$pipeline->description = 'Daily prospect pipeline: discover ecommerce stores, analyze for self-shipping, push qualified leads to CRM, enrich with AI, and run outreach sequences.';
$pipeline->columns_json = json_encode(['Discover', 'Analyze', 'Push to CRM', 'Enrich', 'Outreach']);
$pipeline->trigger_type = 'cron';
$pipeline->trigger_config_json = json_encode(['schedule' => '0 6 * * *']);
$pipeline->default_context_json = json_encode([
    'workspace' => 'shipcannon',
]);
$pipeline->is_active = true;
$pipeline->is_system = false;
$pipeline->is_template = false;
$pipeline->expose_as_tool = true;
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => (object)[
        'workspace' => [
            'type' => 'string',
            'description' => 'Workspace slug (default: shipcannon)',
        ],
        'dry_run' => [
            'type' => 'boolean',
            'description' => 'Preview mode - no database writes (default: false)',
        ],
    ],
    'required' => [],
]);
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pipeline);

$now = date('Y-m-d H:i:s');

// ─── Step 1: Discover ───────────────────────────────────────────────────────
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'discover';
$step->label = 'Discover Stores';
$step->row = 0;
$step->col = 0;
$step->step_type = 'direct_exec';
$step->config_json = json_encode([
    'command' => 'php scripts/cron/cron-prospect-discover.php --script --workspace={context.workspace} --verbose 2>&1',
    'working_dir' => $workingDir,
]);
$step->input_source = 'context';
$step->input_config_json = '{}';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'next_col';
$step->timeout_seconds = 300;
$step->retry_count = 1;
$step->retry_delay_seconds = 60;
$step->is_active = true;
$step->continue_on_failure = true;
$step->sequence = 1;
$step->created_at = $now;
$step->updated_at = $now;
\RedBeanPHP\R::store($step);

// ─── Step 2: Analyze ────────────────────────────────────────────────────────
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'analyze';
$step->label = 'Analyze Stores';
$step->row = 0;
$step->col = 1;
$step->step_type = 'direct_exec';
$step->config_json = json_encode([
    'command' => 'php scripts/cron/cron-prospect-analyze.php --script --workspace={context.workspace} --verbose 2>&1',
    'working_dir' => $workingDir,
]);
$step->input_source = 'prev';
$step->input_config_json = '{}';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'next_col';
$step->timeout_seconds = 1800;
$step->retry_count = 1;
$step->retry_delay_seconds = 120;
$step->is_active = true;
$step->continue_on_failure = true;
$step->sequence = 2;
$step->created_at = $now;
$step->updated_at = $now;
\RedBeanPHP\R::store($step);

// ─── Step 3: Push to CRM ───────────────────────────────────────────────────
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'push_to_crm';
$step->label = 'Push to CRM';
$step->row = 0;
$step->col = 2;
$step->step_type = 'direct_exec';
$step->config_json = json_encode([
    'command' => 'php scripts/cron/cron-prospect-push.php --script --workspace={context.workspace} --verbose 2>&1',
    'working_dir' => $workingDir,
]);
$step->input_source = 'prev';
$step->input_config_json = '{}';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'next_col';
$step->timeout_seconds = 300;
$step->retry_count = 0;
$step->is_active = true;
$step->continue_on_failure = true;
$step->sequence = 3;
$step->created_at = $now;
$step->updated_at = $now;
\RedBeanPHP\R::store($step);

// ─── Step 4: Enrich ─────────────────────────────────────────────────────────
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'enrich';
$step->label = 'Enrich Contacts';
$step->row = 0;
$step->col = 3;
$step->step_type = 'direct_exec';
$step->config_json = json_encode([
    'command' => 'php scripts/cron/cron-crm-enrichment.php --script --workspace={context.workspace} --verbose 2>&1',
    'working_dir' => $workingDir,
]);
$step->input_source = 'prev';
$step->input_config_json = '{}';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'next_col';
$step->timeout_seconds = 1800;
$step->retry_count = 1;
$step->retry_delay_seconds = 120;
$step->is_active = true;
$step->continue_on_failure = true;
$step->sequence = 4;
$step->created_at = $now;
$step->updated_at = $now;
\RedBeanPHP\R::store($step);

// ─── Step 5: Outreach ───────────────────────────────────────────────────────
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'outreach';
$step->label = 'Email Outreach';
$step->row = 0;
$step->col = 4;
$step->step_type = 'direct_exec';
$step->config_json = json_encode([
    'command' => 'php scripts/cron/cron-crm-outreach.php --script --workspace={context.workspace} --verbose 2>&1',
    'working_dir' => $workingDir,
]);
$step->input_source = 'prev';
$step->input_config_json = '{}';
$step->condition_json = '{}';
$step->on_success = 'exit';
$step->on_failure = 'exit';
$step->timeout_seconds = 600;
$step->retry_count = 0;
$step->is_active = true;
$step->continue_on_failure = false;
$step->sequence = 5;
$step->created_at = $now;
$step->updated_at = $now;
\RedBeanPHP\R::store($step);

// ─── Schedule: Daily at 6 AM ────────────────────────────────────────────────
$existingSchedule = \RedBeanPHP\R::findOne('scheduledrecurring', 'pipeline_id = ?', [$pipeline->id]);
if (!$existingSchedule) {
    $schedule = \RedBeanPHP\R::dispense('scheduledrecurring');
    $schedule->pipeline_id = $pipeline->id;
    $schedule->name = 'Daily Prospect Discovery';
    $schedule->description = 'Run the full prospect discovery pipeline daily at 6 AM';
    $schedule->schedule_type = 'cron';
    $schedule->schedule_config_json = json_encode(['schedule' => '0 6 * * *']);
    $schedule->timezone = 'America/Chicago';
    $schedule->max_concurrent = 1;
    $schedule->on_overlap = 'skip';
    $schedule->is_active = true;
    $schedule->input_data_json = json_encode(['workspace' => 'shipcannon']);
    $schedule->run_count = 0;
    $schedule->current_running = 0;
    $schedule->created_at = $now;
    $schedule->updated_at = $now;
    \RedBeanPHP\R::store($schedule);
}
