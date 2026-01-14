<?php
/**
 * R&D: Advanced Tool Use Experiment
 *
 * Tests Anthropic's advanced tool use features:
 * 1. Tool Search Tool - Dynamic tool discovery
 * 2. Programmatic Tool Calling - Claude writes code to orchestrate tools
 * 3. Tool Use Examples - Better parameter handling
 *
 * Based on: https://www.anthropic.com/engineering/advanced-tool-use
 *
 * Usage:
 *   php scripts/rd-advanced-tool-use.php --test=basic
 *   php scripts/rd-advanced-tool-use.php --test=tool-search
 *   php scripts/rd-advanced-tool-use.php --test=programmatic
 *   php scripts/rd-advanced-tool-use.php --test=examples
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use GuzzleHttp\Client;

class AdvancedToolUseExperiment
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private bool $verbose;

    // Beta flag for advanced tool use
    private const BETA_VERSION = 'advanced-tool-use-2025-11-20';

    // Recommended models for advanced tool use
    private const RECOMMENDED_MODELS = [
        'claude-sonnet-4-5-20250929',  // Latest Sonnet 4.5
        'claude-opus-4-20250514',       // Opus 4
        'claude-sonnet-4-20250514',     // Sonnet 4
    ];

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-20250514', bool $verbose = true)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->verbose = $verbose;

        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => self::BETA_VERSION,  // Enable advanced tool use
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
        ]);

        $this->log("Initialized with model: {$this->model}");
        $this->log("Beta version: " . self::BETA_VERSION);
    }

    /**
     * Test 1: Basic Tool Use (baseline)
     * Standard tool calling without advanced features
     */
    public function testBasicToolUse(): array
    {
        $this->log("\n=== TEST: Basic Tool Use ===");

        $tools = [
            [
                'name' => 'get_weather',
                'description' => 'Get current weather for a location',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'type' => 'string',
                            'description' => 'City name or coordinates'
                        ],
                        'units' => [
                            'type' => 'string',
                            'enum' => ['celsius', 'fahrenheit'],
                            'description' => 'Temperature units'
                        ]
                    ],
                    'required' => ['location']
                ]
            ],
            [
                'name' => 'search_database',
                'description' => 'Search internal database for records',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 10]
                    ],
                    'required' => ['query']
                ]
            ]
        ];

        return $this->callWithTools(
            "What's the weather in San Francisco?",
            $tools
        );
    }

    /**
     * Test 2: Tool Search Tool
     * Dynamic tool discovery to reduce context bloat
     */
    public function testToolSearch(): array
    {
        $this->log("\n=== TEST: Tool Search Tool ===");

        // Define many tools, but mark most as deferred
        $tools = [
            // The tool search tool itself
            [
                'type' => 'tool_search_tool_regex_20251119',
                'name' => 'tool_search_tool_regex'
            ],
            // Always-loaded essential tools
            [
                'name' => 'help',
                'description' => 'Get help with available tools',
                'input_schema' => ['type' => 'object', 'properties' => []]
            ],
            // Deferred tools (loaded on-demand)
            [
                'name' => 'jira_create_issue',
                'description' => 'Create a new Jira issue',
                'defer_loading' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'issue_type' => ['type' => 'string', 'enum' => ['Bug', 'Story', 'Task']]
                    ],
                    'required' => ['project', 'summary']
                ]
            ],
            [
                'name' => 'jira_comment',
                'description' => 'Add a comment to a Jira issue',
                'defer_loading' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_key' => ['type' => 'string'],
                        'message' => ['type' => 'string']
                    ],
                    'required' => ['issue_key', 'message']
                ]
            ],
            [
                'name' => 'github_create_pr',
                'description' => 'Create a GitHub pull request',
                'defer_loading' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'repo' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'head' => ['type' => 'string'],
                        'base' => ['type' => 'string'],
                        'body' => ['type' => 'string']
                    ],
                    'required' => ['repo', 'title', 'head', 'base']
                ]
            ],
            [
                'name' => 'github_add_label',
                'description' => 'Add labels to a GitHub issue or PR',
                'defer_loading' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'repo' => ['type' => 'string'],
                        'issue_number' => ['type' => 'integer'],
                        'labels' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ],
                    'required' => ['repo', 'issue_number', 'labels']
                ]
            ],
            [
                'name' => 'slack_send_message',
                'description' => 'Send a message to a Slack channel',
                'defer_loading' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'channel' => ['type' => 'string'],
                        'message' => ['type' => 'string']
                    ],
                    'required' => ['channel', 'message']
                ]
            ],
            [
                'name' => 'database_query',
                'description' => 'Execute a read-only database query',
                'defer_loading' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'params' => ['type' => 'array']
                    ],
                    'required' => ['query']
                ]
            ]
        ];

        $this->log("Total tools defined: " . count($tools));
        $this->log("Deferred tools: " . count(array_filter($tools, fn($t) => $t['defer_loading'] ?? false)));

        return $this->callWithTools(
            "I need to create a bug report in Jira for project MYPROJ about a login error",
            $tools
        );
    }

    /**
     * Test 3: Programmatic Tool Calling
     * Claude writes code to orchestrate tools
     */
    public function testProgrammaticToolCalling(): array
    {
        $this->log("\n=== TEST: Programmatic Tool Calling ===");

        $tools = [
            // Code execution tool
            [
                'type' => 'code_execution_20250825',
                'name' => 'code_execution'
            ],
            // Tools that can only be called from code execution
            [
                'name' => 'get_team_members',
                'description' => 'Get list of team members for a department',
                'allowed_callers' => ['code_execution_20250825'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'department' => ['type' => 'string']
                    ],
                    'required' => ['department']
                ]
            ],
            [
                'name' => 'get_user_tasks',
                'description' => 'Get tasks assigned to a user',
                'allowed_callers' => ['code_execution_20250825'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['open', 'in_progress', 'done']]
                    ],
                    'required' => ['user_id']
                ]
            ],
            [
                'name' => 'get_task_details',
                'description' => 'Get detailed information about a task',
                'allowed_callers' => ['code_execution_20250825'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'string']
                    ],
                    'required' => ['task_id']
                ]
            ]
        ];

        return $this->callWithTools(
            "Find all open tasks for the engineering team and summarize which ones are overdue",
            $tools
        );
    }

    /**
     * Test 4: Tool Use Examples
     * Provide examples to improve parameter handling
     */
    public function testToolUseExamples(): array
    {
        $this->log("\n=== TEST: Tool Use Examples ===");

        $tools = [
            [
                'name' => 'create_ticket',
                'description' => 'Create a support ticket with various priority levels and optional fields',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Brief title describing the issue'],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'medium', 'high', 'critical'],
                            'description' => 'Ticket priority level'
                        ],
                        'labels' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Tags to categorize the ticket'
                        ],
                        'reporter' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'contact' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'email' => ['type' => 'string'],
                                        'phone' => ['type' => 'string']
                                    ]
                                ]
                            ]
                        ],
                        'due_date' => ['type' => 'string', 'description' => 'ISO 8601 date format'],
                        'escalation' => [
                            'type' => 'object',
                            'properties' => [
                                'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                                'notify_manager' => ['type' => 'boolean'],
                                'sla_hours' => ['type' => 'integer']
                            ]
                        ]
                    ],
                    'required' => ['title']
                ],
                // Examples showing realistic usage patterns
                'input_examples' => [
                    // Critical production issue - full details
                    [
                        'title' => 'Login page returns 500 error',
                        'priority' => 'critical',
                        'labels' => ['bug', 'authentication', 'production'],
                        'reporter' => [
                            'id' => 'USR-12345',
                            'name' => 'Jane Smith',
                            'contact' => [
                                'email' => 'jane@acme.com',
                                'phone' => '+1-555-0123'
                            ]
                        ],
                        'due_date' => '2024-11-06',
                        'escalation' => [
                            'level' => 2,
                            'notify_manager' => true,
                            'sla_hours' => 4
                        ]
                    ],
                    // Feature request - minimal fields
                    [
                        'title' => 'Add dark mode support',
                        'labels' => ['feature-request']
                    ],
                    // Documentation update - just title
                    [
                        'title' => 'Update API documentation'
                    ]
                ]
            ]
        ];

        return $this->callWithTools(
            "Create a critical ticket for a database connection timeout affecting all users in production. The issue was reported by John Doe (john@company.com, user ID EMP-789). We need this fixed within 2 hours.",
            $tools
        );
    }

    /**
     * Make API call with tools
     */
    private function callWithTools(string $message, array $tools): array
    {
        $this->log("User message: {$message}");
        $this->log("Tools count: " . count($tools));

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'tools' => $tools,
            'messages' => [
                ['role' => 'user', 'content' => $message]
            ]
        ];

        $startTime = microtime(true);

        try {
            $response = $this->client->post('/v1/messages', [
                'json' => $payload
            ]);

            $elapsed = round(microtime(true) - $startTime, 2);
            $data = json_decode($response->getBody()->getContents(), true);

            $this->log("Response received in {$elapsed}s");
            $this->log("Stop reason: " . ($data['stop_reason'] ?? 'unknown'));
            $this->log("Input tokens: " . ($data['usage']['input_tokens'] ?? 0));
            $this->log("Output tokens: " . ($data['usage']['output_tokens'] ?? 0));

            // Parse response content
            $textContent = '';
            $toolCalls = [];

            foreach ($data['content'] ?? [] as $block) {
                if ($block['type'] === 'text') {
                    $textContent .= $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    $toolCalls[] = [
                        'id' => $block['id'],
                        'name' => $block['name'],
                        'input' => $block['input']
                    ];
                }
            }

            if ($textContent) {
                $this->log("Text response: " . substr($textContent, 0, 200) . (strlen($textContent) > 200 ? '...' : ''));
            }

            if ($toolCalls) {
                $this->log("Tool calls made: " . count($toolCalls));
                foreach ($toolCalls as $call) {
                    $this->log("  - {$call['name']}: " . json_encode($call['input']));
                }
            }

            return [
                'success' => true,
                'elapsed' => $elapsed,
                'usage' => $data['usage'] ?? [],
                'stop_reason' => $data['stop_reason'] ?? null,
                'text' => $textContent,
                'tool_calls' => $toolCalls,
                'raw' => $data
            ];

        } catch (\Exception $e) {
            $this->log("ERROR: " . $e->getMessage());

            // Try to extract error details from response
            $errorBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $this->log("Error response: " . $errorBody);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_body' => $errorBody
            ];
        }
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[" . date('H:i:s') . "] {$message}\n";
        }
    }
}

// CLI Entry Point
if (php_sapi_name() === 'cli') {
    $options = getopt('', ['test:', 'model:', 'help', 'quiet']);

    if (isset($options['help'])) {
        echo <<<HELP
Advanced Tool Use R&D Experiment

Usage:
  php scripts/rd-advanced-tool-use.php [options]

Options:
  --test=TYPE    Test to run: basic, tool-search, programmatic, examples, all
  --model=MODEL  Claude model to use (default: claude-sonnet-4-20250514)
  --quiet        Suppress verbose output
  --help         Show this help

Examples:
  php scripts/rd-advanced-tool-use.php --test=basic
  php scripts/rd-advanced-tool-use.php --test=all --model=claude-opus-4-20250514
  php scripts/rd-advanced-tool-use.php --test=tool-search --quiet

HELP;
        exit(0);
    }

    // Get API key from config
    $apiKey = Flight::get('anthropic.api_key');
    if (!$apiKey) {
        echo "ERROR: No Anthropic API key configured\n";
        echo "Set anthropic.api_key in your config.ini\n";
        exit(1);
    }

    $model = $options['model'] ?? Flight::get('anthropic.model') ?? 'claude-sonnet-4-20250514';
    $verbose = !isset($options['quiet']);
    $test = $options['test'] ?? 'basic';

    echo "===========================================\n";
    echo " Advanced Tool Use R&D Experiment\n";
    echo "===========================================\n";
    echo "Model: {$model}\n";
    echo "Test: {$test}\n";
    echo "===========================================\n\n";

    $experiment = new AdvancedToolUseExperiment($apiKey, $model, $verbose);

    $results = [];

    switch ($test) {
        case 'basic':
            $results['basic'] = $experiment->testBasicToolUse();
            break;
        case 'tool-search':
            $results['tool-search'] = $experiment->testToolSearch();
            break;
        case 'programmatic':
            $results['programmatic'] = $experiment->testProgrammaticToolCalling();
            break;
        case 'examples':
            $results['examples'] = $experiment->testToolUseExamples();
            break;
        case 'all':
            $results['basic'] = $experiment->testBasicToolUse();
            $results['tool-search'] = $experiment->testToolSearch();
            $results['programmatic'] = $experiment->testProgrammaticToolCalling();
            $results['examples'] = $experiment->testToolUseExamples();
            break;
        default:
            echo "Unknown test: {$test}\n";
            echo "Valid tests: basic, tool-search, programmatic, examples, all\n";
            exit(1);
    }

    echo "\n===========================================\n";
    echo " Results Summary\n";
    echo "===========================================\n";

    foreach ($results as $name => $result) {
        $status = $result['success'] ? '✓' : '✗';
        $tokens = isset($result['usage'])
            ? "({$result['usage']['input_tokens']} in / {$result['usage']['output_tokens']} out)"
            : '';
        $toolCalls = isset($result['tool_calls']) ? count($result['tool_calls']) . ' tool calls' : '';

        echo "{$status} {$name}: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . " {$tokens} {$toolCalls}\n";

        if (!$result['success']) {
            echo "  Error: {$result['error']}\n";
        }
    }

    echo "\n";
}
