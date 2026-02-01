<?php
/**
 * Create the Repo Refiner Agent pipeline template.
 * This pipeline allows users to refine a GitHub repo through conversational AI.
 *
 * NOTE: After seeding, you must configure:
 * - spawn_agent step: agent_id and runner_id for your workspace
 * - send_to_agent step: mcp_server_id for your workspace's myctobot MCP server
 */

// Check if repo-refiner-agent pipeline already exists
$existing = \RedBeanPHP\R::findOne('pipelines', 'slug = ?', ['repo-refiner-agent']);
if ($existing) {
    return; // Already exists, skip creation
}

// Create the Repo Refiner Agent pipeline
$pipeline = \RedBeanPHP\R::dispense('pipelines');
$pipeline->member = $member;
$pipeline->name = 'Repo Refiner Agent';
$pipeline->slug = 'repo-refiner-agent';
$pipeline->description = 'Clone a GitHub repo, spawn an AI agent to refine it based on email instructions. Sends status updates every 15 minutes. User interacts via web form links in emails.';
$pipeline->columns_json = json_encode(['Setup', 'Work Loop', 'Cleanup']);
$pipeline->trigger_type = 'manual';
$pipeline->trigger_config_json = '{}';
$pipeline->default_context_json = '{}';
$pipeline->is_active = true;
$pipeline->is_system = false;
$pipeline->is_template = true;
$pipeline->expose_as_tool = true;
$pipeline->input_schema_json = json_encode([
    'type' => 'object',
    'properties' => [
        'repo_url' => [
            'type' => 'string',
            'description' => 'GitHub repo URL to clone (e.g., https://github.com/user/repo.git)'
        ],
        'initial_instructions' => [
            'type' => 'string',
            'description' => 'Initial instructions for the agent (optional)'
        ],
        'email' => [
            'type' => 'string',
            'description' => 'Email address for status updates and instructions'
        ]
    ],
    'required' => ['repo_url', 'email']
]);
$pipeline->run_count = 0;
$pipeline->created_at = date('Y-m-d H:i:s');
$pipeline->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pipeline);

// Step 1: Bootstrap Remote Environment (col 0)
// This runs ON THE REMOTE WORKSTATION (not locally) to prepare the environment
// The bootstrap clones the repo and sets up the working directory on the runner
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'bootstrap_remote';
$step->label = 'Bootstrap Remote';
$step->row = 0;
$step->col = 0;
$step->step_type = 'direct_exec';
$step->config_json = json_encode([
    // Bootstrap script runs on remote workstation via SSH
    'workstation_id' => '{context.runner_id}',
    'command' => implode(' && ', [
        // Clean up any previous run
        'rm -rf ~/jobs/repo-refiner-{run_uid}',
        // Create fresh working directory
        'mkdir -p ~/jobs/repo-refiner-{run_uid}',
        // Clone the repository (GIT_TERMINAL_PROMPT=0 prevents hanging on auth prompts)
        'GIT_TERMINAL_PROMPT=0 git clone {context.repo_url} ~/jobs/repo-refiner-{run_uid}',
        // Show status for logging
        'cd ~/jobs/repo-refiner-{run_uid} && git status && pwd && ls -la'
    ]),
    'working_dir' => '~'
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 0;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 2: Spawn Agent (col 1, row 0)
// NOTE: agent_id and runner_id must be configured for each workspace via context
// The agent runs on the same remote workstation where bootstrap_remote prepared the repo
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'spawn_agent';
$step->label = 'Start AI Agent';
$step->row = 0;
$step->col = 1;
$step->step_type = 'ai_agent';
$step->config_json = json_encode([
    'agent_id' => '{context.agent_id}',
    'execution_mode' => 'runner',
    'runner_id' => '{context.runner_id}',
    // Use same path as bootstrap_remote (~/jobs/repo-refiner-{run_uid} on the runner)
    'working_dir' => '~/jobs/repo-refiner-{run_uid}',
    'prompt' => "You are working on refining a codebase. The repository has already been cloned to your current directory.\n\nFirst, explore the codebase structure and understand what it does. Then wait for instructions.\n\nInitial instructions (if any): {context.initial_instructions}\n\nWhen you receive new instructions via the pipeline, implement them carefully. After each major change, commit your work with descriptive messages.\n\nUse the `job_checkpoint` MCP tool periodically to report your progress.\n\nIMPORTANT: Stay active and wait for instructions. Do not exit until told to.",
    'wait_for_completion' => false
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:send_ready_email';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 1;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 3: Send Ready Email (col 1, row 1)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'send_ready_email';
$step->label = 'Send Ready Email';
$step->row = 1;
$step->col = 1;
$step->step_type = 'mailgun';
$step->config_json = json_encode([
    'to' => '{context.email}',
    'subject' => "\xF0\x9F\xA4\x96 Repo Refiner Agent Ready - {context.repo_url}",
    'content_type' => 'markdown',
    'body' => "## Your AI Agent is Ready!\n\nI've cloned the repository and I'm ready to work on it.\n\n**Repository:** {context.repo_url}\n**Session ID:** {run_uid}\n\n---\n\n### Send Me Instructions\n\n**[\xF0\x9F\x93\x9D Click here to send instructions]({base_url}/pipelines/form/{run_uid}?step=wait_for_instructions)**\n\n---\n\n### When You're Done\n\n**[\xE2\x9C\x85 Mark as Complete]({base_url}/pipelines/input/{run_uid}?action=complete)**\n\n---\n\nYou'll receive status updates every 15 minutes while I'm working."
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:schedule_status_15m';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 2;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 4: Schedule 15m Status (col 1, row 2)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'schedule_status_15m';
$step->label = 'Schedule 15m Status';
$step->row = 2;
$step->col = 1;
$step->step_type = 'schedule_task';
$step->config_json = json_encode([
    'task_type' => 'execute_pipeline',
    'delay' => 15,
    'delay_unit' => '60',
    'pipeline_id' => '_self',
    'entry_step' => 'send_status_email',
    'input_data' => [
        'email' => '{context.email}',
        'repo_url' => '{context.repo_url}',
        'run_uid' => '{run_uid}',
        'job_uid' => '{spawn_agent.output.job_uid}'
    ]
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:wait_for_instructions';
$step->on_failure = 'next_col';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 3;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 5: Wait for Instructions (col 2, row 0)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'wait_for_instructions';
$step->label = 'Wait for Instructions';
$step->row = 0;
$step->col = 2;
$step->step_type = 'wait';
$step->config_json = json_encode([
    'wait_type' => 'await_input',
    'prompt' => 'Enter instructions for the AI agent. What would you like it to work on?',
    'timeout' => 86400,
    'source_webhook' => true,
    'source_form' => true,
    'source_mcp' => true,
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'title' => 'Action',
                'enum' => ['instructions', 'complete'],
                'default' => 'instructions'
            ],
            'message' => [
                'type' => 'string',
                'title' => 'Instructions',
                'description' => 'What should the agent work on?'
            ]
        ]
    ]
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:check_if_complete';  // Must go to condition check, not next_col
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 4;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 6: Check if Complete (col 2, row 1)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'check_if_complete';
$step->label = 'Check if Complete';
$step->row = 1;
$step->col = 2;
$step->step_type = 'condition';
$step->config_json = json_encode([
    // Use {context._input.action} to check the user's input from context
    // _input is stored in $this->context, not $this->stepOutputs
    'left' => '{context._input.action}',
    'operator' => 'equals',
    'right' => 'complete',
    'then_goto' => 'send_completion_email',
    'else_goto' => 'send_to_agent'
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 5;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 7: Send to Agent (col 2, row 2)
// TODO: mcp_server_id must be configured for each workspace
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'send_to_agent';
$step->label = 'Send to Agent';
$step->row = 2;
$step->col = 2;
$step->step_type = 'mcp_call';
$step->config_json = json_encode([
    'mcp_server_id' => '{context.mcp_server_id}',
    'tool' => 'send_to_step',
    'arguments' => [
        'run_id' => '{context.run_id}',
        'step_name' => 'spawn_agent',
        'message' => "New instructions from user:\n\n{wait_for_instructions.output.message}\n\nPlease implement these changes. Use post_message to report progress."
    ]
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:send_ack_email';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 6;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 8: Send Ack Email (col 2, row 3)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'send_ack_email';
$step->label = 'Acknowledge Email';
$step->row = 3;
$step->col = 2;
$step->step_type = 'mailgun';
$step->config_json = json_encode([
    'to' => '{context.email}',
    'subject' => "\xF0\x9F\x93\xA8 Instructions Received - Working on it!",
    'content_type' => 'markdown',
    'body' => "## Got it!\n\nI've received your instructions and I'm working on them now.\n\n**Your request:**\n> {wait_for_instructions.output.message}\n\n---\n\n**[\xF0\x9F\x93\x9D Send more instructions]({base_url}/pipelines/form/{run_uid}?step=wait_for_instructions)**\n\n**[\xE2\x9C\x85 Mark as Complete]({base_url}/pipelines/input/{run_uid}?action=complete)**"
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:wait_for_instructions';
$step->on_failure = 'goto:wait_for_instructions';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 7;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 9: Send Status Email (col 3, row 0)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'send_status_email';
$step->label = 'Send Status Update';
$step->row = 0;
$step->col = 3;
$step->step_type = 'mailgun';
$step->config_json = json_encode([
    'to' => '{context.email}',
    'subject' => "\xF0\x9F\x93\x8A Status Update - Repo Refiner Agent",
    'content_type' => 'markdown',
    'body' => "## Status Update\n\n**Repository:** {context.repo_url}\n**Session:** {context.run_uid}\n\nThe agent is still working. Check the session for detailed progress.\n\n---\n\n**[\xF0\x9F\x93\x9D Send instructions]({base_url}/pipelines/form/{context.run_uid}?step=wait_for_instructions)**\n\n**[\xE2\x9C\x85 Mark as Complete]({base_url}/pipelines/input/{context.run_uid}?action=complete)**"
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'goto:schedule_next_status';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 200;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 10: Schedule Next Status (col 3, row 1)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'schedule_next_status';
$step->label = 'Schedule Next Status';
$step->row = 1;
$step->col = 3;
$step->step_type = 'schedule_task';
$step->config_json = json_encode([
    'task_type' => 'execute_pipeline',
    'delay' => 15,
    'delay_unit' => '60',
    'pipeline_id' => '_self',
    'entry_step' => 'send_status_email',
    'input_data' => [
        'email' => '{context.email}',
        'repo_url' => '{context.repo_url}',
        'run_uid' => '{context.run_uid}',
        'job_uid' => '{context.job_uid}'
    ]
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'exit';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 201;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 11: Send Completion Email (col 4, row 0)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'send_completion_email';
$step->label = 'Send Completion Email';
$step->row = 0;
$step->col = 4;
$step->step_type = 'mailgun';
$step->config_json = json_encode([
    'to' => '{context.email}',
    'subject' => "\xE2\x9C\x85 Repo Refiner Agent - Work Complete!",
    'content_type' => 'markdown',
    'body' => "## All Done! \xF0\x9F\x8E\x89\n\nThe Repo Refiner Agent has completed its work.\n\n**Repository:** {context.repo_url}\n\nThe changes have been committed to the local clone. You can review them or push to a branch.\n\n---\n\nThank you for using the Repo Refiner Agent!"
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'next_col';
$step->on_failure = 'next_col';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 100;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);

// Step 12: Cleanup Session (col 4, row 1)
$step = \RedBeanPHP\R::dispense('pipelinesteps');
$step->pipelines_id = $pipeline->id;
$step->step_name = 'cleanup_session';
$step->label = 'Cleanup Session';
$step->row = 1;
$step->col = 4;
$step->step_type = 'reaper';
$step->config_json = json_encode([
    'job_uid' => '{spawn_agent.output.job_uid}',
    'wait_for_idle' => true,
    'force_kill' => true,
    'timeout' => 30
]);
$step->input_source = 'context';
$step->input_config_json = '[]';
$step->condition_json = '{}';
$step->on_success = 'exit';
$step->on_failure = 'exit';
$step->timeout_seconds = 300;
$step->is_active = true;
$step->sequence = 101;
$step->created_at = date('Y-m-d H:i:s');
$step->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($step);
