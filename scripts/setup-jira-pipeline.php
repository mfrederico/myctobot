#!/usr/bin/env php
<?php
/**
 * Setup Jira Webhook Pipeline
 *
 * Creates a pipeline that:
 * 1. Receives Jira webhooks
 * 2. Analyzes the event with an AI agent
 * 3. Outputs the analysis result
 *
 * Usage:
 *   php scripts/setup-jira-pipeline.php --workspace=gwt
 *   php scripts/setup-jira-pipeline.php --workspace=gwt --verbose
 */

$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);
chdir($baseDir);

$options = getopt('', ['workspace:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Setup Jira Webhook Pipeline\n\n";
    echo "Usage: php scripts/setup-jira-pipeline.php --workspace=<slug>\n\n";
    echo "Options:\n";
    echo "  --workspace   Workspace slug (required)\n";
    echo "  --verbose     Show detailed output\n";
    echo "  --help        Show this help\n";
    exit(0);
}

$workspaceSlug = $options['workspace'] ?? null;
$verbose = isset($options['verbose']);

if (!$workspaceSlug) {
    echo "Error: --workspace is required\n";
    exit(1);
}

require_once $baseDir . '/vendor/autoload.php';

use \app\Bean;
use \RedBeanPHP\R as R;

// Load workspace config
$configFile = $baseDir . '/conf/config.' . $workspaceSlug . '.ini';
if (!file_exists($configFile)) {
    echo "Error: Config file not found: {$configFile}\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
if (!isset($config['database'])) {
    echo "Error: No [database] section in config\n";
    exit(1);
}

// Connect to database
$db = $config['database'];
$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
R::addDatabase('workspace', $dsn, $db['user'], $db['pass'] ?? '');
R::selectDatabase('workspace');

echo "Connected to workspace: {$workspaceSlug}\n\n";

// Get first admin member for ownership
$member = Bean::findOne('member', ' level <= 50 ORDER BY id LIMIT 1 ');
if (!$member) {
    $member = Bean::findOne('member', ' 1 ORDER BY id LIMIT 1 ');
}
if (!$member) {
    echo "Error: No members found in workspace\n";
    exit(1);
}
echo "Using member: {$member->username} (ID: {$member->id})\n";

// Check if AI agent exists, create if not
$agent = Bean::findOne('aiagents', ' name = ? ', ['Jira Analyzer']);
if (!$agent) {
    echo "Creating AI agent: Jira Analyzer\n";
    $agent = Bean::dispense('aiagents');
    $agent->member_id = $member->id;
    $agent->created_by_member_id = $member->id;
    $agent->created_by_name = $member->username;
    $agent->name = 'Jira Analyzer';
    $agent->description = 'Analyzes Jira webhook events and provides insights';
    $agent->runner_type = 'anthropic_api';
    $agent->runner_config = json_encode([
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 4096
    ]);
    $agent->mcp_servers = '[]';
    $agent->hooks_config = '{}';
    $agent->is_active = true;
    $agent->is_default = false;
    $agent->created_at = date('Y-m-d H:i:s');
    $agent->updated_at = date('Y-m-d H:i:s');
    Bean::store($agent);
    echo "  Created agent ID: {$agent->id}\n";
} else {
    echo "AI agent exists: {$agent->name} (ID: {$agent->id})\n";
}

// Check if pipeline exists
$pipeline = Bean::findOne('pipelines', ' slug = ? ', ['jira-webhook-analyzer']);
if ($pipeline) {
    echo "\nPipeline already exists: {$pipeline->name} (ID: {$pipeline->id})\n";
    echo "Slug: {$pipeline->slug}\n";
    echo "Webhook URL: /pipein/{$workspaceSlug}/{$pipeline->slug}\n";
    exit(0);
}

echo "\nCreating pipeline: Jira Webhook Analyzer\n";

// Create pipeline
$pipeline = Bean::dispense('pipelines');
$pipeline->member_id = $member->id;
$pipeline->slug = 'jira-webhook-analyzer';
$pipeline->name = 'Jira Webhook Analyzer';
$pipeline->description = 'Receives Jira webhooks, analyzes with AI, and outputs insights';
$pipeline->columns_json = json_encode(['Receive', 'Analyze', 'Output']);
$pipeline->row_names_json = json_encode(['main' => 0]);
$pipeline->trigger_type = 'webhook';
$pipeline->trigger_config_json = json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'allowed_sources' => ['jira']
]);
$pipeline->default_context_json = json_encode([
    'workspace' => $workspaceSlug
]);
$pipeline->is_active = true;
$pipeline->is_template = false;
$pipeline->expose_as_tool = false;
$pipeline->input_schema_json = '{}';
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
Bean::store($pipeline);

echo "  Created pipeline ID: {$pipeline->id}\n";

// Create Step 1: Receive (parser to extract key fields)
$step1 = Bean::dispense('pipelinesteps');
$step1->pipelines_id = $pipeline->id;
$step1->step_name = 'receive';
$step1->row = 0;
$step1->col = 0;
$step1->label = 'Receive Jira Event';
$step1->step_type = 'parser';
$step1->config_json = json_encode([
    'parser_type' => 'jq',
    'expression' => '{
        event_type: .webhookEvent,
        issue_key: .issue.key,
        issue_summary: .issue.fields.summary,
        issue_status: .issue.fields.status.name,
        issue_type: .issue.fields.issuetype.name,
        project_key: .issue.fields.project.key,
        assignee: .issue.fields.assignee.displayName,
        reporter: .issue.fields.reporter.displayName,
        priority: .issue.fields.priority.name,
        description: (.issue.fields.description // "No description"),
        changelog: .changelog,
        user: .user.displayName,
        timestamp: .timestamp
    }'
]);
$step1->input_source = 'context';
$step1->input_config_json = json_encode(['key' => '_trigger']);
$step1->condition_json = '{}';
$step1->on_success = 'next_col';
$step1->on_failure = 'exit';
$step1->timeout_seconds = 30;
$step1->retry_count = 0;
$step1->is_active = true;
$step1->sequence = 1;
$step1->created_at = date('Y-m-d H:i:s');
$step1->updated_at = date('Y-m-d H:i:s');
Bean::store($step1);
echo "  Created step: {$step1->step_name}\n";

// Create Step 2: AI Analysis
$step2 = Bean::dispense('pipelinesteps');
$step2->pipelines_id = $pipeline->id;
$step2->step_name = 'analyze';
$step2->row = 0;
$step2->col = 1;
$step2->label = 'AI Analysis';
$step2->step_type = 'ai_agent';
$step2->config_json = json_encode([
    'agent_id' => $agent->id,
    'model' => 'claude-sonnet-4-20250514',
    'max_tokens' => 2048,
    'include_input' => true,
    'system_prompt' => 'You are a Jira issue analyst. Analyze the incoming Jira webhook event and provide actionable insights. Focus on:
1. What changed and why it matters
2. Any potential issues or risks
3. Suggested next steps or follow-ups

Respond in JSON format with keys: summary, analysis, risks, recommendations',
    'prompt' => 'Analyze this Jira event and provide insights:'
]);
$step2->input_source = 'getfrom:receive';
$step2->input_config_json = json_encode(['key' => 'output']);
$step2->condition_json = '{}';
$step2->on_success = 'next_col';
$step2->on_failure = 'exit';
$step2->timeout_seconds = 120;
$step2->retry_count = 1;
$step2->retry_delay_seconds = 5;
$step2->is_active = true;
$step2->sequence = 2;
$step2->created_at = date('Y-m-d H:i:s');
$step2->updated_at = date('Y-m-d H:i:s');
Bean::store($step2);
echo "  Created step: {$step2->step_name}\n";

// Create Step 3: Output (just formats the final result)
$step3 = Bean::dispense('pipelinesteps');
$step3->pipelines_id = $pipeline->id;
$step3->step_name = 'output';
$step3->row = 0;
$step3->col = 2;
$step3->label = 'Format Output';
$step3->step_type = 'parser';
$step3->config_json = json_encode([
    'parser_type' => 'jq',
    'expression' => '{
        status: "completed",
        issue_key: .receive.output.issue_key,
        event_type: .receive.output.event_type,
        ai_analysis: .analyze.output.parsed,
        raw_response: .analyze.output.response,
        processed_at: now | todate
    }'
]);
$step3->input_source = 'context';
$step3->input_config_json = '{}';
$step3->condition_json = '{}';
$step3->on_success = 'exit';
$step3->on_failure = 'exit';
$step3->timeout_seconds = 30;
$step3->is_active = true;
$step3->sequence = 3;
$step3->created_at = date('Y-m-d H:i:s');
$step3->updated_at = date('Y-m-d H:i:s');
Bean::store($step3);
echo "  Created step: {$step3->step_name}\n";

// Get trigger config for webhook secret
$triggerConfig = json_decode($pipeline->trigger_config_json, true);

echo "\n" . str_repeat('=', 60) . "\n";
echo "PIPELINE CREATED SUCCESSFULLY\n";
echo str_repeat('=', 60) . "\n\n";

echo "Pipeline: {$pipeline->name}\n";
echo "Slug: {$pipeline->slug}\n";
echo "ID: {$pipeline->id}\n\n";

echo "Webhook URL:\n";
echo "  POST /pipein/{$workspaceSlug}/{$pipeline->slug}\n\n";

echo "Authentication:\n";
echo "  Header: X-API-TOKEN: <your-api-key>\n";
echo "  -or-   Authorization: Bearer <your-api-key>\n";
echo "  -or-   ?key=<your-api-key>\n\n";

echo "Webhook Secret (optional):\n";
echo "  {$triggerConfig['secret']}\n\n";

echo "Test with curl:\n";
echo "  curl -X POST \\\n";
echo "    -H 'X-API-TOKEN: <your-api-key>' \\\n";
echo "    -H 'Content-Type: application/json' \\\n";
echo "    -d '{\"webhookEvent\":\"jira:issue_updated\",\"issue\":{\"key\":\"TEST-1\",\"fields\":{\"summary\":\"Test issue\",\"status\":{\"name\":\"In Progress\"},\"issuetype\":{\"name\":\"Task\"},\"project\":{\"key\":\"TEST\"},\"priority\":{\"name\":\"Medium\"}}}}' \\\n";
echo "    https://myctobot.ai/pipein/{$workspaceSlug}/{$pipeline->slug}\n\n";

echo "Pipeline steps:\n";
echo "  1. [Receive] Parse Jira webhook payload\n";
echo "  2. [Analyze] Run AI agent to analyze the event\n";
echo "  3. [Output]  Format and return the analysis\n\n";

R::close();
echo "Done!\n";
