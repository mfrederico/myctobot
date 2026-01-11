#!/usr/bin/env php
<?php
/**
 * Agent-to-Agent Communication Test
 *
 * This script sets up and tests communication between two Claude agents:
 * 1. A "coordinator" agent using Claude API (orchestrator)
 * 2. A "worker" agent using Ollama (specialized task executor)
 *
 * The coordinator calls the worker's MCP tools to perform tasks.
 *
 * Usage:
 *   php scripts/test-agent-communication.php --tenant=footest4 --setup
 *   php scripts/test-agent-communication.php --tenant=footest4 --run
 *   php scripts/test-agent-communication.php --tenant=footest4 --cleanup
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';

use RedBeanPHP\R as R;

// Parse arguments
$args = [];
foreach ($argv as $arg) {
    if (preg_match('/^--(\w+)(?:=(.+))?$/', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$tenant = $args['tenant'] ?? null;
$action = null;
if (isset($args['setup'])) $action = 'setup';
if (isset($args['run'])) $action = 'run';
if (isset($args['cleanup'])) $action = 'cleanup';
if (isset($args['status'])) $action = 'status';
if (isset($args['help'])) $action = 'help';

if (!$tenant || !$action || $action === 'help') {
    echo <<<HELP
Agent-to-Agent Communication Test
==================================

This script demonstrates MCP-based communication between two Claude agents.

Usage:
  php scripts/test-agent-communication.php --tenant=<tenant> --<action>

Actions:
  --setup     Create test agents (coordinator + worker) in database
  --run       Start both agents in tmux sessions and run test
  --status    Check status of running agent sessions
  --cleanup   Stop sessions and remove test agents

Options:
  --tenant=<name>   Required. Tenant slug (e.g., footest4)
  --help            Show this help

Example:
  # Set up test agents
  php scripts/test-agent-communication.php --tenant=footest4 --setup

  # Run the communication test
  php scripts/test-agent-communication.php --tenant=footest4 --run

  # Check status
  php scripts/test-agent-communication.php --tenant=footest4 --status

  # Clean up when done
  php scripts/test-agent-communication.php --tenant=footest4 --cleanup

Architecture:
  ┌─────────────────────┐    MCP call    ┌─────────────────────┐
  │  Coordinator Agent  │ ──────────────→│    Worker Agent     │
  │  (Claude API)       │                │    (Ollama)         │
  │                     │ ←──────────────│                     │
  └─────────────────────┘    response    └─────────────────────┘

  The coordinator spawns as a tmux session using Claude Code CLI with
  Anthropic API. It has an MCP server configured that points to the
  worker agent's tools.

  The worker runs as a separate process (or tmux session) with Ollama
  backend. When the coordinator calls its MCP tools, the HTTP request
  goes to myctobot.ai/api/mcp, which routes to the worker's LLM.

HELP;
    exit($action === 'help' ? 0 : 1);
}

// Load tenant config
$configFile = BASE_PATH . "/conf/config.{$tenant}.ini";
if (!file_exists($configFile)) {
    echo "Error: Tenant config not found: $configFile\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
if (!$config || empty($config['database'])) {
    echo "Error: Invalid tenant config\n";
    exit(1);
}

// Connect to tenant database
$dbConfig = $config['database'];
$type = $dbConfig['type'] ?? 'mysql';

if ($type === 'sqlite') {
    $dsn = 'sqlite:' . ($dbConfig['file'] ?? BASE_PATH . "/database/{$tenant}.sqlite");
    R::setup($dsn);
} else {
    $host = $dbConfig['host'] ?? 'localhost';
    $port = $dbConfig['port'] ?? 3306;
    $name = $dbConfig['name'] ?? $tenant;
    $user = $dbConfig['user'] ?? 'root';
    $pass = $dbConfig['pass'] ?? '';
    $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
    R::setup($dsn, $user, $pass);
}

R::freeze(false);

// Get member for this tenant (use --email if provided, otherwise first non-system member)
$memberEmail = $args['email'] ?? null;
if ($memberEmail) {
    $member = R::findOne('member', 'email = ?', [$memberEmail]);
} else {
    // Try to find a real user (not system accounts)
    $member = R::findOne('member', "email NOT LIKE '%@system.local' ORDER BY id ASC");
    if (!$member) {
        $member = R::findOne('member', 'ORDER BY id ASC LIMIT 1');
    }
}
if (!$member) {
    echo "Error: No member found in tenant database\n";
    exit(1);
}
$memberId = (int) $member->id;
echo "Using member ID: $memberId ({$member->email})\n";

// Action handlers
switch ($action) {
    case 'setup':
        setupAgents($memberId, $tenant, $config);
        break;
    case 'run':
        runTest($memberId, $tenant, $config);
        break;
    case 'status':
        checkStatus($tenant);
        break;
    case 'cleanup':
        cleanup($memberId, $tenant);
        break;
}

/**
 * Set up the test agents
 */
function setupAgents(int $memberId, string $tenant, array $config) {
    echo "\n=== Setting up test agents ===\n";

    // Check for existing test agents
    $existing = R::find('aiagents', 'member_id = ? AND name LIKE ?', [$memberId, 'Test%Agent%']);
    if (count($existing) > 0) {
        echo "Found " . count($existing) . " existing test agent(s). Use --cleanup first to remove them.\n";
        foreach ($existing as $a) {
            echo "  - {$a->name} (ID: {$a->id})\n";
        }
        return;
    }

    // Create Worker Agent (Ollama-based)
    echo "\nCreating Worker Agent (Ollama)...\n";
    $worker = R::dispense('aiagents');
    $worker->member_id = $memberId;
    $worker->name = 'Test Worker Agent';
    $worker->description = 'Ollama-based worker for testing agent communication';
    $worker->provider = 'claude_cli';
    $worker->provider_config = json_encode([
        'use_ollama' => true,
        'ollama_host' => 'http://localhost:11434',
        'ollama_model' => 'llama3.2-vision:latest'
    ]);
    $worker->mcp_servers = json_encode([]);
    $worker->hooks_config = json_encode([]);
    $worker->capabilities = json_encode(['code_review', 'code_generation']);
    $worker->is_active = 1;
    $worker->is_default = 0;
    $worker->expose_as_mcp = 1;
    $worker->mcp_tool_name = 'ollama_worker';
    $worker->mcp_tool_description = 'Call local Ollama model for code review and generation';
    $worker->created_at = date('Y-m-d H:i:s');
    $workerId = R::store($worker);
    echo "  Created Worker Agent ID: $workerId\n";

    // Create tools for worker
    echo "  Adding tools to Worker Agent...\n";

    $reviewTool = R::dispense('agenttools');
    $reviewTool->agent_id = $workerId;
    $reviewTool->tool_name = 'review_code';
    $reviewTool->tool_description = 'Review code for issues and improvements';
    $reviewTool->parameters_schema = json_encode([
        ['name' => 'code', 'type' => 'string', 'description' => 'Code to review', 'required' => true],
        ['name' => 'language', 'type' => 'string', 'description' => 'Programming language', 'required' => false, 'default' => 'auto']
    ]);
    $reviewTool->prompt_template = <<<PROMPT
You are a code reviewer. Analyze the following code and provide:
1. A brief summary of what the code does
2. Any bugs or issues found
3. Suggestions for improvement

Language: {language}

Code to review:
```
{code}
```

Provide your review in a concise, structured format.
PROMPT;
    $reviewTool->is_active = 1;
    $reviewTool->created_at = date('Y-m-d H:i:s');
    R::store($reviewTool);
    echo "    - review_code tool\n";

    $generateTool = R::dispense('agenttools');
    $generateTool->agent_id = $workerId;
    $generateTool->tool_name = 'generate_code';
    $generateTool->tool_description = 'Generate code based on requirements';
    $generateTool->parameters_schema = json_encode([
        ['name' => 'requirements', 'type' => 'string', 'description' => 'What the code should do', 'required' => true],
        ['name' => 'language', 'type' => 'string', 'description' => 'Programming language', 'required' => true],
        ['name' => 'style', 'type' => 'string', 'description' => 'Coding style hints', 'required' => false]
    ]);
    $generateTool->prompt_template = <<<PROMPT
Generate {language} code that meets the following requirements:

{requirements}

{style}

Output ONLY the code with brief inline comments. No explanation needed.
PROMPT;
    $generateTool->is_active = 1;
    $generateTool->created_at = date('Y-m-d H:i:s');
    R::store($generateTool);
    echo "    - generate_code tool\n";

    // Get API base URL from tenant config
    $baseUrl = $config['app']['baseurl'] ?? 'https://myctobot.ai';
    if (strpos($baseUrl, 'localhost') === false) {
        $baseUrl = preg_replace('/^http:/', 'https:', $baseUrl);
    }

    // Create Coordinator Agent (Claude API)
    echo "\nCreating Coordinator Agent (Claude API)...\n";
    $coordinator = R::dispense('aiagents');
    $coordinator->member_id = $memberId;
    $coordinator->name = 'Test Coordinator Agent';
    $coordinator->description = 'Claude API coordinator that orchestrates worker agents';
    $coordinator->provider = 'claude_cli';
    $coordinator->provider_config = json_encode([
        'model' => 'sonnet'
    ]);
    // Configure MCP server to call the worker
    $coordinator->mcp_servers = json_encode([
        [
            'name' => 'ollama_worker',
            'type' => 'http',
            'url' => "{$baseUrl}/api/mcp/{$tenant}",
            'headers' => [
                'X-API-Key' => '${MYCTOBOT_API_KEY}',
                'X-Tenant' => $tenant
            ]
        ]
    ]);
    $coordinator->hooks_config = json_encode([]);
    $coordinator->capabilities = json_encode(['orchestration', 'planning']);
    $coordinator->is_active = 1;
    $coordinator->is_default = 0;
    $coordinator->expose_as_mcp = 0;
    $coordinator->created_at = date('Y-m-d H:i:s');
    $coordinatorId = R::store($coordinator);
    echo "  Created Coordinator Agent ID: $coordinatorId\n";

    echo "\n=== Setup Complete ===\n";
    echo "\nNext steps:\n";
    echo "1. Make sure you have an API key set up for tenant '$tenant'\n";
    echo "2. Run: php scripts/test-agent-communication.php --tenant=$tenant --run\n";
    echo "\nMCP Configuration for Coordinator:\n";
    echo json_encode([
        'mcpServers' => [
            'ollama_worker' => [
                'type' => 'http',
                'url' => "{$baseUrl}/api/mcp/{$tenant}",
                'headers' => [
                    'X-API-Key' => '${MYCTOBOT_API_KEY}',
                    'X-Tenant' => $tenant
                ]
            ]
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Run the communication test
 */
function runTest(int $memberId, string $tenant, array $config) {
    echo "\n=== Running Agent Communication Test ===\n";

    // Find our test agents
    $coordinator = R::findOne('aiagents', 'member_id = ? AND name = ?', [$memberId, 'Test Coordinator Agent']);
    $worker = R::findOne('aiagents', 'member_id = ? AND name = ?', [$memberId, 'Test Worker Agent']);

    if (!$coordinator || !$worker) {
        echo "Error: Test agents not found. Run --setup first.\n";
        exit(1);
    }

    echo "Found Coordinator Agent: ID {$coordinator->id}\n";
    echo "Found Worker Agent: ID {$worker->id}\n";

    // Get API key for this tenant
    $mainConfigFile = BASE_PATH . '/conf/config.ini';
    $mainConfig = parse_ini_file($mainConfigFile, true);

    // Check for API key in environment or prompt
    $apiKey = getenv('MYCTOBOT_API_KEY');
    if (!$apiKey) {
        // Try to get from main database
        $mainDbConfig = $mainConfig['database'] ?? [];
        if (!empty($mainDbConfig)) {
            R::addDatabase('main', "mysql:host={$mainDbConfig['host']};dbname={$mainDbConfig['name']}", $mainDbConfig['user'], $mainDbConfig['pass']);
            R::selectDatabase('main');
            $mainMember = R::findOne('member', 'ceobot_db = ?', [$tenant]);
            if ($mainMember && $mainMember->api_token) {
                $apiKey = $mainMember->api_token;
                echo "Found API key for tenant '$tenant'\n";
            }
            R::selectDatabase('default');
        }
    }

    if (!$apiKey) {
        echo "\nError: No API key found. Set MYCTOBOT_API_KEY environment variable.\n";
        echo "Or ensure the member has api_token set in the main database.\n";
        exit(1);
    }

    // Create work directory
    $workDir = "/tmp/agent-comm-test-{$tenant}";
    if (!is_dir($workDir)) {
        mkdir($workDir, 0755, true);
    }

    // Generate .mcp.json for coordinator - use tenant config
    $baseUrl = $config['app']['baseurl'] ?? 'https://myctobot.ai';
    if (strpos($baseUrl, 'localhost') === false) {
        $baseUrl = preg_replace('/^http:/', 'https:', $baseUrl);
    }

    $mcpConfig = [
        'mcpServers' => [
            'ollama_worker' => [
                'type' => 'http',
                'url' => "{$baseUrl}/api/mcp/{$tenant}",
                'headers' => [
                    'X-API-Key' => $apiKey,
                    'X-Tenant' => $tenant
                ]
            ]
        ]
    ];

    $mcpFile = "{$workDir}/.mcp.json";
    file_put_contents($mcpFile, json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Generated MCP config: $mcpFile\n";

    // Create test prompt file
    $testPrompt = <<<PROMPT
You are a coordinator agent that orchestrates other AI agents via MCP tools.

You have access to an "ollama_worker" MCP server with these tools:
- review_code: Review code for issues and improvements
- generate_code: Generate code based on requirements

Your task:
1. First, use the ollama_worker tools to generate a simple Python function that calculates fibonacci numbers
2. Then, use the review_code tool to review the generated code
3. Summarize both the generated code and the review findings

Important: When calling MCP tools, use the mcp__ollama_worker__<tool_name> format.

Begin by generating the fibonacci code.
PROMPT;

    $promptFile = "{$workDir}/test-prompt.txt";
    file_put_contents($promptFile, $testPrompt);
    echo "Generated test prompt: $promptFile\n";

    // Check if tmux session already exists
    $sessionName = "agent-test-{$tenant}";
    exec("tmux has-session -t " . escapeshellarg($sessionName) . " 2>/dev/null", $output, $exitCode);

    if ($exitCode === 0) {
        echo "\nTmux session '$sessionName' already exists.\n";
        echo "Use --status to check or --cleanup to remove it.\n";
        return;
    }

    echo "\n=== Starting Coordinator Agent in tmux ===\n";
    echo "Session: $sessionName\n";
    echo "Work dir: $workDir\n";

    // Create a runner script to avoid shell escaping issues
    $runnerScript = <<<BASH
#!/bin/bash
cd {$workDir}
export MYCTOBOT_API_KEY='{$apiKey}'

echo "=== Starting Claude with MCP config ==="
echo "Work dir: \$(pwd)"
echo "MCP config: .mcp.json"
echo ""

# Run claude with the prompt from file
claude --print "\$(cat test-prompt.txt)"

echo ""
echo "=== Test Complete ==="
read -p "Press Enter to close..."
BASH;

    $runnerFile = "{$workDir}/run-test.sh";
    file_put_contents($runnerFile, $runnerScript);
    chmod($runnerFile, 0755);
    echo "Generated runner script: $runnerFile\n";

    // Create tmux session running the script
    $tmuxCmd = sprintf(
        'tmux new-session -d -s %s -c %s %s',
        escapeshellarg($sessionName),
        escapeshellarg($workDir),
        escapeshellarg($runnerFile)
    );

    echo "\nExecuting: $tmuxCmd\n\n";
    system($tmuxCmd, $returnVar);

    if ($returnVar !== 0) {
        echo "Error: Failed to create tmux session\n";
        exit(1);
    }

    echo "=== Tmux session started ===\n";
    echo "\nTo view the session:\n";
    echo "  tmux attach -t $sessionName\n";
    echo "\nTo check status:\n";
    echo "  php scripts/test-agent-communication.php --tenant=$tenant --status\n";
    echo "\nTo clean up:\n";
    echo "  php scripts/test-agent-communication.php --tenant=$tenant --cleanup\n";
}

/**
 * Check status of running sessions
 */
function checkStatus(string $tenant) {
    echo "\n=== Agent Communication Test Status ===\n";

    $sessionName = "agent-test-{$tenant}";

    // Check tmux session
    exec("tmux has-session -t " . escapeshellarg($sessionName) . " 2>/dev/null", $output, $exitCode);

    if ($exitCode === 0) {
        echo "\nTmux session: $sessionName (RUNNING)\n";

        // Get pane content
        exec("tmux capture-pane -t " . escapeshellarg($sessionName) . " -p 2>/dev/null | tail -30", $paneContent);
        if (!empty($paneContent)) {
            echo "\n--- Recent output (last 30 lines) ---\n";
            echo implode("\n", $paneContent) . "\n";
            echo "--- End output ---\n";
        }

        echo "\nTo attach: tmux attach -t $sessionName\n";
    } else {
        echo "\nTmux session: $sessionName (NOT RUNNING)\n";
    }

    // Check work directory
    $workDir = "/tmp/agent-comm-test-{$tenant}";
    if (is_dir($workDir)) {
        echo "\nWork directory: $workDir\n";
        $files = glob("$workDir/*");
        foreach ($files as $f) {
            echo "  - " . basename($f) . "\n";
        }
    }
}

/**
 * Clean up test resources
 */
function cleanup(int $memberId, string $tenant) {
    echo "\n=== Cleaning up Agent Communication Test ===\n";

    $sessionName = "agent-test-{$tenant}";

    // Kill tmux session
    exec("tmux has-session -t " . escapeshellarg($sessionName) . " 2>/dev/null", $output, $exitCode);
    if ($exitCode === 0) {
        echo "Killing tmux session: $sessionName\n";
        system("tmux kill-session -t " . escapeshellarg($sessionName));
    }

    // Remove work directory
    $workDir = "/tmp/agent-comm-test-{$tenant}";
    if (is_dir($workDir)) {
        echo "Removing work directory: $workDir\n";
        system("rm -rf " . escapeshellarg($workDir));
    }

    // Remove test agents
    echo "Removing test agents...\n";

    // Find and delete tools first
    $agents = R::find('aiagents', 'member_id = ? AND name LIKE ?', [$memberId, 'Test%Agent%']);
    foreach ($agents as $agent) {
        $tools = R::find('agenttools', 'agent_id = ?', [$agent->id]);
        foreach ($tools as $tool) {
            echo "  - Deleting tool: {$tool->tool_name}\n";
            R::trash($tool);
        }
        echo "  - Deleting agent: {$agent->name} (ID: {$agent->id})\n";
        R::trash($agent);
    }

    echo "\n=== Cleanup Complete ===\n";
}
