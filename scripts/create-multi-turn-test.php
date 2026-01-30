#!/usr/bin/env php
<?php
/**
 * Create Multi-Turn Input Test Pipeline
 *
 * Creates a test pipeline with 3 await_input steps to demonstrate multi-turn workflows.
 *
 * Usage: php scripts/create-multi-turn-test.php --workspace=gwt
 */

$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);
chdir($baseDir);

$options = getopt('', ['workspace:', 'help']);

if (isset($options['help']) || !isset($options['workspace'])) {
    echo "Usage: php scripts/create-multi-turn-test.php --workspace=SLUG\n";
    exit(1);
}

$workspace = $options['workspace'];

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';

use \app\Bean;

// Load workspace config
$configFile = $baseDir . "/conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    echo "Error: Workspace config not found: {$configFile}\n";
    exit(1);
}

$bootstrap = new \app\Bootstrap($configFile);

echo "Creating multi-turn test pipeline...\n";

// Create the pipeline
$pipeline = Bean::dispense('pipelines');
$pipeline->name = 'Multi-Turn Input Test';
$pipeline->slug = 'multi-turn-input-test';
$pipeline->description = 'Test pipeline with 3 await_input steps to demonstrate multi-turn workflows';
$pipeline->is_active = 1;
$pipeline->expose_as_tool = 1;
$pipeline->columns_json = json_encode(['Main']);
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => [
        'user_name' => ['type' => 'string', 'description' => 'Name of the user']
    ],
    'required' => ['user_name']
]);
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
$pipelineId = Bean::store($pipeline);

echo "Created pipeline ID: $pipelineId\n";

// Step 1: Initial greeting
$step1 = Bean::dispense('pipelinesteps');
$step1->pipelines_id = $pipelineId;
$step1->step_name = 'greeting';
$step1->label = 'Greeting';
$step1->step_type = 'parser';
$step1->row = 0;
$step1->col = 0;
$step1->is_active = 1;
$step1->config_json = json_encode([
    'parser_type' => 'template',
    'template' => 'Hello {context.user_name}! Welcome to the multi-turn test.'
]);
Bean::store($step1);
echo "Created step 1: greeting\n";

// Step 2: First await_input - ask favorite color
$step2 = Bean::dispense('pipelinesteps');
$step2->pipelines_id = $pipelineId;
$step2->step_name = 'ask_color';
$step2->label = 'Ask Favorite Color';
$step2->step_type = 'wait';
$step2->row = 1;
$step2->col = 0;
$step2->is_active = 1;
$step2->config_json = json_encode([
    'wait_type' => 'await_input',
    'prompt' => 'What is your favorite color?',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'color' => ['type' => 'string', 'description' => 'Your favorite color']
        ],
        'required' => ['color']
    ],
    'timeout_seconds' => 3600,
    'allowed_sources' => ['mcp', 'webhook', 'form']
]);
Bean::store($step2);
echo "Created step 2: ask_color (await_input)\n";

// Step 3: Process color response
$step3 = Bean::dispense('pipelinesteps');
$step3->pipelines_id = $pipelineId;
$step3->step_name = 'process_color';
$step3->label = 'Process Color';
$step3->step_type = 'parser';
$step3->row = 2;
$step3->col = 0;
$step3->is_active = 1;
$step3->config_json = json_encode([
    'parser_type' => 'template',
    'template' => 'Great choice! {_input.color} is a beautiful color.'
]);
Bean::store($step3);
echo "Created step 3: process_color\n";

// Step 4: Second await_input - ask favorite food
$step4 = Bean::dispense('pipelinesteps');
$step4->pipelines_id = $pipelineId;
$step4->step_name = 'ask_food';
$step4->label = 'Ask Favorite Food';
$step4->step_type = 'wait';
$step4->row = 3;
$step4->col = 0;
$step4->is_active = 1;
$step4->config_json = json_encode([
    'wait_type' => 'await_input',
    'prompt' => 'What is your favorite food?',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'food' => ['type' => 'string', 'description' => 'Your favorite food']
        ],
        'required' => ['food']
    ],
    'timeout_seconds' => 3600,
    'allowed_sources' => ['mcp', 'webhook', 'form']
]);
Bean::store($step4);
echo "Created step 4: ask_food (await_input)\n";

// Step 5: Process food response
$step5 = Bean::dispense('pipelinesteps');
$step5->pipelines_id = $pipelineId;
$step5->step_name = 'process_food';
$step5->label = 'Process Food';
$step5->step_type = 'parser';
$step5->row = 4;
$step5->col = 0;
$step5->is_active = 1;
$step5->config_json = json_encode([
    'parser_type' => 'template',
    'template' => 'Yum! {_input.food} sounds delicious.'
]);
Bean::store($step5);
echo "Created step 5: process_food\n";

// Step 6: Third await_input - ask for confirmation
$step6 = Bean::dispense('pipelinesteps');
$step6->pipelines_id = $pipelineId;
$step6->step_name = 'ask_confirm';
$step6->label = 'Confirm Choices';
$step6->step_type = 'wait';
$step6->row = 5;
$step6->col = 0;
$step6->is_active = 1;
$step6->config_json = json_encode([
    'wait_type' => 'await_input',
    'prompt' => 'Please confirm your choices. Is everything correct?',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'confirmed' => ['type' => 'boolean', 'description' => 'Confirm your choices (true/false)']
        ],
        'required' => ['confirmed']
    ],
    'timeout_seconds' => 3600,
    'allowed_sources' => ['mcp', 'webhook', 'form']
]);
Bean::store($step6);
echo "Created step 6: ask_confirm (await_input)\n";

// Step 7: Final summary
$step7 = Bean::dispense('pipelinesteps');
$step7->pipelines_id = $pipelineId;
$step7->step_name = 'summary';
$step7->label = 'Final Summary';
$step7->step_type = 'parser';
$step7->row = 6;
$step7->col = 0;
$step7->is_active = 1;
$step7->config_json = json_encode([
    'parser_type' => 'template',
    'template' => 'Thank you {context.user_name}! Your profile is complete. Confirmed: {_input.confirmed}'
]);
Bean::store($step7);
echo "Created step 7: summary\n";

echo "\n=== Pipeline Created Successfully ===\n";
echo "Pipeline ID: $pipelineId\n";
echo "MCP Tool Name: myctobot_multi-turn-input-test\n";
echo "View/Edit: https://gwt.myctobot.ai/pipelines/edit/$pipelineId\n";
echo "\nTo test via MCP, the AI should call:\n";
echo "  1. myctobot_multi-turn-input-test({user_name: 'TestUser'})\n";
echo "  2. continue_pipeline({run_id: X, input_token: 'Y', input: {color: 'blue'}})\n";
echo "  3. continue_pipeline({run_id: X, input_token: 'Y', input: {food: 'pizza'}})\n";
echo "  4. continue_pipeline({run_id: X, input_token: 'Y', input: {confirmed: true}})\n";
