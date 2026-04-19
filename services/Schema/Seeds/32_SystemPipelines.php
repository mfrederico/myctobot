<?php
/**
 * Create system pipelines that are required for core functionality.
 * These pipelines are marked with is_system=1 and cannot be deleted.
 */

// Check if agent-test pipeline already exists
$existing = \RedBeanPHP\R::findOne('pipelines', 'slug = ?', ['agent-test']);
if ($existing) {
    return; // Already exists, skip creation
}

// Create the Agent Test pipeline
$pipeline = \RedBeanPHP\R::dispense('pipelines');
$pipeline->member = $member;
$pipeline->name = 'Agent Test';
$pipeline->slug = 'agent-test';
$pipeline->description = 'Reusable pipeline to test any agent. Spawns the agent, waits for completion, and terminates the session gracefully.';
$pipeline->columns_json = json_encode(['Spawn', 'Wait', 'Reap']);
$pipeline->trigger_type = 'manual';
$pipeline->trigger_config_json = '{}';
$pipeline->default_context_json = '{}';
$pipeline->is_active = true;
$pipeline->is_system = true;
$pipeline->is_template = false;
$pipeline->expose_as_tool = true;
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => [
        'agent_id' => [
            'type' => 'integer',
            'description' => 'ID of the agent to test'
        ],
        'runner_id' => [
            'type' => 'integer',
            'description' => 'ID of the workstation/runner to run on'
        ]
    ],
    'required' => ['agent_id', 'runner_id']
]);
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pipeline);

// Step 1: Spawn Agent
$step1 = \RedBeanPHP\R::dispense('pipelinesteps');
$step1->pipelines_id = $pipeline->id;
$step1->step_name = 'spawn_agent';
$step1->row = 0;
$step1->col = 0;
$step1->label = 'Spawn Agent';
$step1->step_type = 'ai_agent';
$step1->config_json = json_encode([
    'agent_id' => '{context.agent_id}',
    'execution_mode' => 'runner',
    'runner_id' => '{context.runner_id}',
    'prompt' => 'Hello! Please respond with a brief greeting to confirm you are working. Include the current directory path in your response. After responding, call the `job_complete` MCP tool with success=true and summary="Test completed successfully" to mark this test as complete.',
    'wait_for_completion' => false,
    'timeout' => 120
]);
$step1->input_source = 'context';
$step1->input_config_json = '{}';
$step1->condition_json = '{}';
$step1->on_success = 'next_col';
$step1->on_failure = 'exit';
$step1->timeout_seconds = 120;
$step1->is_active = true;
$step1->run_parallel = false;
$step1->sequence = 1;
$step1->created_at = date('Y-m-d H:i:s');
$step1->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step1);

// Step 2: Wait for Completion (Harvest)
$step2 = \RedBeanPHP\R::dispense('pipelinesteps');
$step2->pipelines_id = $pipeline->id;
$step2->step_name = 'wait_complete';
$step2->row = 0;
$step2->col = 1;
$step2->label = 'Wait for Completion';
$step2->step_type = 'harvest';
$step2->config_json = json_encode([
    'policy' => 'all_required',
    'timeout' => 120
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

// Step 3: Terminate Session (Reaper)
$step3 = \RedBeanPHP\R::dispense('pipelinesteps');
$step3->pipelines_id = $pipeline->id;
$step3->step_name = 'reap_session';
$step3->row = 0;
$step3->col = 2;
$step3->label = 'Terminate Session';
$step3->step_type = 'reaper';
$step3->config_json = json_encode([
    'job_uid' => '{spawn_agent.output.job_key}',
    'wait_for_idle' => true,
    'timeout' => 30,
    'force_kill' => false
]);
$step3->input_source = 'prev';
$step3->input_config_json = '{}';
$step3->condition_json = '{}';
$step3->on_success = 'exit';
$step3->on_failure = 'exit';
$step3->timeout_seconds = 60;
$step3->is_active = true;
$step3->run_parallel = false;
$step3->sequence = 3;
$step3->created_at = date('Y-m-d H:i:s');
$step3->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step3);
