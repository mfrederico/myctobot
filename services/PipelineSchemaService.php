<?php
/**
 * PipelineSchemaService - Provides step type schemas for pipeline building
 *
 * Returns config schemas for all step types to enable AI agents to build
 * pipelines programmatically. Each step type has a defined config_schema
 * that describes the required and optional configuration fields.
 */

namespace app\services;

class PipelineSchemaService
{
    /**
     * Get schemas for all step types
     *
     * @return array Array of step type definitions with config schemas
     */
    public static function getAllStepTypeSchemas(): array
    {
        return [
            'direct_exec' => [
                'type' => 'direct_exec',
                'category' => 'core',
                'label' => 'Shell Command',
                'description' => 'Execute a shell command with stdin/stdout. Can run locally or on remote workstations via SSH.',
                'config_schema' => [
                    'command' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The shell command or code to execute. Supports variable substitution: {context.key}, {step_name.output.key}'
                    ],
                    'executor' => [
                        'type' => 'string',
                        'default' => '/bin/bash -c',
                        'description' => 'How to run the command. Options: /bin/bash -c, /bin/sh -c, /usr/bin/python3 -c, /usr/bin/php -r, node -e, or empty for direct execution'
                    ],
                    'working_dir' => [
                        'type' => 'string',
                        'default' => '/tmp',
                        'description' => 'Working directory for command execution'
                    ],
                    'workstation_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Execute on a remote workstation via SSH instead of locally'
                    ]
                ]
            ],

            'script' => [
                'type' => 'script',
                'category' => 'core',
                'label' => 'Script',
                'description' => 'Pull and execute a script from a connected repository.',
                'config_schema' => [
                    'repo_id' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'ID of the repository connection'
                    ],
                    'script_path' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Path to the script within the repository (e.g., scripts/deploy.sh)'
                    ],
                    'args' => [
                        'type' => 'string',
                        'description' => 'Command line arguments to pass to the script'
                    ]
                ]
            ],

            'file_write' => [
                'type' => 'file_write',
                'category' => 'core',
                'label' => 'Write File',
                'description' => 'Write content to a file in the run directory. Useful for generating reports, storing intermediate data.',
                'config_schema' => [
                    'filename' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Filename to write. Supports variables: {step.output}.csv'
                    ],
                    'source' => [
                        'type' => 'enum',
                        'values' => ['template', 'stdin', 'step_output', 'base64'],
                        'default' => 'template',
                        'description' => 'Content source: template (with variables), stdin (previous step output), step_output (specific field), base64 (decode)'
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Content when source=template. Supports {step_name.stdout} or {step_name.output.field}'
                    ],
                    'source_step' => [
                        'type' => 'string',
                        'description' => 'Step name when source=step_output'
                    ],
                    'source_field' => [
                        'type' => 'string',
                        'description' => 'Output field when source=step_output (e.g., output.data or stdout)'
                    ],
                    'base64_var' => [
                        'type' => 'string',
                        'description' => 'Variable containing base64 content when source=base64'
                    ],
                    'content_type' => [
                        'type' => 'enum',
                        'values' => ['', 'text/plain', 'text/csv', 'application/json', 'image/png', 'image/jpeg', 'application/pdf', 'application/octet-stream'],
                        'description' => 'Optional content type (auto-detected if empty)'
                    ],
                    'append' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Append to existing file instead of overwriting'
                    ]
                ]
            ],

            'parser' => [
                'type' => 'parser',
                'category' => 'core',
                'label' => 'Parser',
                'description' => 'Transform data using jq, PHP, or regex. Receives previous step output as input.',
                'config_schema' => [
                    'parser_type' => [
                        'type' => 'enum',
                        'values' => ['jq', 'php', 'regex'],
                        'default' => 'jq',
                        'description' => 'Parser type: jq for JSON transforms, php for PHP code, regex for pattern extraction'
                    ],
                    'expression' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The expression to evaluate. jq: .data.items[] | {id, name}. php: return $input["data"]. regex: capture groups.'
                    ]
                ]
            ],

            'ai_agent' => [
                'type' => 'ai_agent',
                'category' => 'ai',
                'label' => 'AI Agent',
                'description' => 'Run an AI agent (implementation, verification, or custom). Requires configured agents.',
                'config_schema' => [
                    'agent_id' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'ID of the configured AI agent to run'
                    ],
                    'runner_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Specific runner to use (defaults to auto-selection)'
                    ],
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Prompt template. Supports {context.key} and {step_name.output.key} variables'
                    ]
                ]
            ],

            'shopify_graphql' => [
                'type' => 'shopify_graphql',
                'category' => 'ecommerce',
                'label' => 'Shopify GraphQL',
                'description' => 'Execute GraphQL queries against a connected Shopify store.',
                'config_schema' => [
                    'connection_id' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'ID of the Shopify connection'
                    ],
                    'query' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'GraphQL query string. See https://shopify.dev/docs/api/admin-graphql'
                    ],
                    'variables' => [
                        'type' => 'object',
                        'description' => 'Variables object for the GraphQL query. Values support variable substitution.'
                    ]
                ]
            ],

            'mailgun' => [
                'type' => 'mailgun',
                'category' => 'communication',
                'label' => 'Send Email',
                'description' => 'Send email via Mailgun. Requires Mailgun configuration in workspace settings.',
                'config_schema' => [
                    'to' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Recipient email(s). Comma-separated. Supports variables: user@example.com, {context.recipient}'
                    ],
                    'cc' => [
                        'type' => 'string',
                        'description' => 'CC recipients (comma-separated)'
                    ],
                    'subject' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Email subject. Supports {context.key} variables.'
                    ],
                    'content_type' => [
                        'type' => 'enum',
                        'values' => ['markdown', 'html', 'text'],
                        'default' => 'markdown',
                        'description' => 'Content type: markdown (converted to HTML), html, or text'
                    ],
                    'body' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Email body. Supports {context.key}, {step_name.output}, {prev.output} variables.'
                    ],
                    'attachments' => [
                        'type' => 'string',
                        'description' => 'Attachments (one per line). Use @{step_name.file} to attach files from file_write steps.'
                    ]
                ]
            ],

            'webhook_out' => [
                'type' => 'webhook_out',
                'category' => 'integration',
                'label' => 'Webhook',
                'description' => 'POST data to an external service URL.',
                'config_schema' => [
                    'url' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Target URL (e.g., https://api.example.com/webhook)'
                    ],
                    'method' => [
                        'type' => 'enum',
                        'values' => ['POST', 'PUT', 'PATCH'],
                        'default' => 'POST',
                        'description' => 'HTTP method'
                    ],
                    'headers' => [
                        'type' => 'object',
                        'description' => 'HTTP headers as JSON object (e.g., {"Authorization": "Bearer ..."})'
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Request body template (JSON). Supports {prev.output.status} and other variables.'
                    ]
                ]
            ],

            'mcp_call' => [
                'type' => 'mcp_call',
                'category' => 'integration',
                'label' => 'MCP Call',
                'description' => 'Call a tool on an MCP server. Supports stdio (subprocess) or HTTP transport.',
                'config_schema' => [
                    'server_id' => [
                        'type' => 'integer',
                        'description' => 'ID of a configured MCP server (optional, use inline config if not provided)'
                    ],
                    'tool' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The tool name to call on the MCP server'
                    ],
                    'transport' => [
                        'type' => 'enum',
                        'values' => ['stdio', 'http'],
                        'default' => 'stdio',
                        'description' => 'Transport type (only used with inline config)'
                    ],
                    'command' => [
                        'type' => 'string',
                        'description' => 'Command to start MCP server (for stdio transport)'
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'MCP server URL (for http transport)'
                    ],
                    'arguments' => [
                        'type' => 'object',
                        'description' => 'Tool arguments as JSON object. Supports {context.key} and {step.output.key} variables.'
                    ],
                    'list_tools_only' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'If true, list available tools instead of calling one'
                    ]
                ]
            ],

            'wait' => [
                'type' => 'wait',
                'category' => 'flow',
                'label' => 'Wait',
                'description' => 'Wait for a delay, manual approval, webhook, or external input (MCP, form, webhook).',
                'config_schema' => [
                    'wait_type' => [
                        'type' => 'enum',
                        'values' => ['delay', 'approval', 'webhook', 'await_input'],
                        'default' => 'delay',
                        'description' => 'Wait type: delay (seconds), approval (manual), webhook (incoming), await_input (MCP/form/webhook)'
                    ],
                    'duration' => [
                        'type' => 'integer',
                        'default' => 60,
                        'description' => 'Duration in seconds (for delay type)'
                    ],
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Prompt shown when waiting for input (for await_input type)'
                    ],
                    'input_schema' => [
                        'type' => 'object',
                        'description' => 'JSON Schema defining expected input structure (for await_input type)'
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'default' => 86400,
                        'description' => 'Timeout in seconds for await_input (default: 24 hours)'
                    ],
                    'source_mcp' => [
                        'type' => 'boolean',
                        'default' => true,
                        'description' => 'Allow input from MCP tool calls'
                    ],
                    'source_webhook' => [
                        'type' => 'boolean',
                        'default' => true,
                        'description' => 'Allow input from webhook POST'
                    ],
                    'source_form' => [
                        'type' => 'boolean',
                        'default' => true,
                        'description' => 'Allow input from web form'
                    ]
                ]
            ],

            'harvest' => [
                'type' => 'harvest',
                'category' => 'flow',
                'label' => 'Harvest',
                'description' => 'Gather results from parallel execution rows. Use after parallel steps to collect outputs.',
                'config_schema' => [
                    'policy' => [
                        'type' => 'enum',
                        'values' => ['all_required', 'any_success', 'best_effort'],
                        'default' => 'all_required',
                        'description' => 'all_required: fail if any failed. any_success: pass if one succeeded. best_effort: always pass.'
                    ],
                    'on_incomplete' => [
                        'type' => 'enum',
                        'values' => ['fail', 'continue', 'goto'],
                        'default' => 'fail',
                        'description' => 'Action when policy not met: fail pipeline, continue with partial results, or goto error handler'
                    ],
                    'template' => [
                        'type' => 'string',
                        'description' => 'Optional jq expression to reshape harvested results'
                    ]
                ]
            ],

            'schedule_task' => [
                'type' => 'schedule_task',
                'category' => 'flow',
                'label' => 'Schedule Task',
                'description' => 'Schedule a future task (pipeline, webhook, revert). Non-blocking - pipeline continues immediately.',
                'config_schema' => [
                    'task_type' => [
                        'type' => 'enum',
                        'values' => ['execute_pipeline', 'webhook_call', 'revert_action'],
                        'default' => 'execute_pipeline',
                        'description' => 'Type of task to schedule'
                    ],
                    'delay' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Delay value (combined with delay_unit)'
                    ],
                    'delay_unit' => [
                        'type' => 'enum',
                        'values' => ['1', '60', '3600', '86400'],
                        'default' => '3600',
                        'description' => 'Delay unit: 1=seconds, 60=minutes, 3600=hours, 86400=days'
                    ],
                    'pipeline_id' => [
                        'type' => 'string',
                        'description' => 'Pipeline ID to execute, or "_self" for the current pipeline'
                    ],
                    'entry_step' => [
                        'type' => 'string',
                        'description' => 'Optional step name to start execution from'
                    ],
                    'input_data' => [
                        'type' => 'object',
                        'description' => 'Context data to pass to the scheduled pipeline. Supports variable substitution.'
                    ],
                    'webhook_url' => [
                        'type' => 'string',
                        'description' => 'URL for webhook_call task type'
                    ],
                    'webhook_method' => [
                        'type' => 'enum',
                        'values' => ['POST', 'PUT', 'GET'],
                        'default' => 'POST',
                        'description' => 'HTTP method for webhook'
                    ],
                    'webhook_headers' => [
                        'type' => 'object',
                        'description' => 'Headers for webhook call'
                    ],
                    'webhook_body' => [
                        'type' => 'string',
                        'description' => 'Body for webhook call (JSON)'
                    ]
                ]
            ],

            'condition' => [
                'type' => 'condition',
                'category' => 'flow',
                'label' => 'Condition (If/Then/Else)',
                'description' => 'Evaluate a condition and branch to different steps. Like an if/else statement.',
                'config_schema' => [
                    'left' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Left side of comparison. Supports variables: {prev.output.timed_out}'
                    ],
                    'operator' => [
                        'type' => 'enum',
                        'values' => ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_equal', 'less_equal', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty', 'is_true', 'is_false', 'regex'],
                        'default' => 'equals',
                        'description' => 'Comparison operator'
                    ],
                    'right' => [
                        'type' => 'string',
                        'description' => 'Right side of comparison (not needed for unary operators like is_empty, is_true)'
                    ],
                    'then_goto' => [
                        'type' => 'string',
                        'description' => 'Step name to jump to if condition is TRUE (empty = continue to next step)'
                    ],
                    'else_goto' => [
                        'type' => 'string',
                        'description' => 'Step name to jump to if condition is FALSE (empty = continue to next step)'
                    ]
                ]
            ],

            'switch' => [
                'type' => 'switch',
                'category' => 'flow',
                'label' => 'Switch (Multi-Branch)',
                'description' => 'Evaluate a value against multiple cases and branch accordingly. Like a switch/case statement.',
                'config_schema' => [
                    'value' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The value to match against cases. Supports variable substitution: {prev.output.status}'
                    ],
                    'cases' => [
                        'type' => 'object',
                        'required' => true,
                        'description' => 'Object mapping case values to step names. Example: {"pending": "handle_pending", "active": "handle_active"}'
                    ],
                    'default' => [
                        'type' => 'string',
                        'description' => 'Step name when no case matches (empty = continue to next step)'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get schema for a specific step type
     *
     * @param string $stepType
     * @return array|null
     */
    public static function getStepTypeSchema(string $stepType): ?array
    {
        $schemas = self::getAllStepTypeSchemas();
        return $schemas[$stepType] ?? null;
    }

    /**
     * Get all step types grouped by category (for UI display)
     *
     * @return array
     */
    public static function getStepTypesByCategory(): array
    {
        $schemas = self::getAllStepTypeSchemas();
        $grouped = [];

        foreach ($schemas as $type => $schema) {
            $category = $schema['category'] ?? 'core';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][$type] = $schema;
        }

        return $grouped;
    }

    /**
     * Validate a step config against its schema
     *
     * @param string $stepType
     * @param array $config
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateStepConfig(string $stepType, array $config): array
    {
        $schema = self::getStepTypeSchema($stepType);
        if (!$schema) {
            return ['valid' => false, 'errors' => ["Unknown step type: {$stepType}"]];
        }

        $errors = [];
        $configSchema = $schema['config_schema'] ?? [];

        foreach ($configSchema as $field => $fieldSchema) {
            $isRequired = $fieldSchema['required'] ?? false;
            $value = $config[$field] ?? null;

            // Check required fields
            if ($isRequired && ($value === null || $value === '')) {
                $errors[] = "Field '{$field}' is required for step type '{$stepType}'";
                continue;
            }

            // Skip validation if field is not provided and not required
            if ($value === null || $value === '') {
                continue;
            }

            // Type validation
            $expectedType = $fieldSchema['type'] ?? 'string';

            switch ($expectedType) {
                case 'integer':
                    if (!is_numeric($value)) {
                        $errors[] = "Field '{$field}' must be an integer";
                    }
                    break;

                case 'boolean':
                    if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                        $errors[] = "Field '{$field}' must be a boolean";
                    }
                    break;

                case 'enum':
                    $allowedValues = $fieldSchema['values'] ?? [];
                    if (!in_array($value, $allowedValues, true)) {
                        $errors[] = "Field '{$field}' must be one of: " . implode(', ', $allowedValues);
                    }
                    break;

                case 'object':
                    if (!is_array($value) && !is_object($value)) {
                        // Try to parse as JSON if it's a string
                        if (is_string($value)) {
                            $decoded = json_decode($value, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $errors[] = "Field '{$field}' must be a valid JSON object";
                            }
                        } else {
                            $errors[] = "Field '{$field}' must be an object";
                        }
                    }
                    break;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get the variable syntax reference
     *
     * @return array
     */
    public static function getVariableSyntaxReference(): array
    {
        return [
            'context' => [
                'pattern' => '{context.key}',
                'description' => 'Access pipeline context variables passed at run time',
                'examples' => ['{context.customer_id}', '{context.product_handle}']
            ],
            'step_output' => [
                'pattern' => '{step_name.output.key}',
                'description' => 'Access output from a named previous step',
                'examples' => ['{get_products.output.data.products}', '{fetch_data.output.count}']
            ],
            'step_stdout' => [
                'pattern' => '{step_name.stdout}',
                'description' => 'Access raw stdout from a named previous step',
                'examples' => ['{run_script.stdout}']
            ],
            'previous' => [
                'pattern' => '{prev.output}',
                'description' => 'Access the immediately previous step output',
                'examples' => ['{prev.output}', '{prev.output.status}']
            ],
            'run_directory' => [
                'pattern' => '{run_directory}',
                'description' => 'Path to the current run\'s file storage directory',
                'examples' => ['{run_directory}/report.csv']
            ],
            'run_uid' => [
                'pattern' => '{run_uid}',
                'description' => 'Unique identifier for the current pipeline run',
                'examples' => ['{run_uid}']
            ]
        ];
    }
}
