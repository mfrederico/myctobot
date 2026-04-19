<?php
/**
 * Test script for Agent + Workstation combined testing
 *
 * Usage: php scripts/test/test-agent-workstation.php --agent=ID --workstation=ID [--quick]
 *
 * Options:
 *   --agent=ID       Agent ID to test
 *   --workstation=ID Workstation/shard ID to use
 *   --quick          Just test SSH connectivity (skip Claude test)
 *   --list           List available agents and workstations
 *   --workspace=SLUG    Workspace database to use (required)
 */

// Change to project root
chdir(dirname(__DIR__, 2));

// Parse command line arguments
$options = getopt('', ['agent:', 'workstation:', 'quick', 'list', 'workspace:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Agent + Workstation Test Script

Usage: php scripts/test/test-agent-workstation.php --workspace=<workspace> [options]

Options:
  --workspace=<name>       Required. Workspace slug (e.g., gwt)
  --agent=<id>          Agent ID to test
  --workstation=<id>    Workstation/shard ID to use
  --quick               Just test SSH connectivity (skip Claude test)
  --list                List available agents and workstations
  --help                Show this help message

Examples:
  php scripts/test/test-agent-workstation.php --workspace=gwt --list
  php scripts/test/test-agent-workstation.php --workspace=gwt --agent=2 --workstation=1
  php scripts/test/test-agent-workstation.php --workspace=gwt --agent=2 --workstation=1 --quick

HELP;
    exit(0);
}

if (empty($options['workspace'])) {
    echo "Error: --workspace is required\n";
    echo "Usage: php scripts/test/test-agent-workstation.php --workspace=<workspace> --list\n";
    exit(1);
}

$workspace = $options['workspace'];

// Bootstrap
require_once 'vendor/autoload.php';
require_once 'lib/FlightMap.php';
require_once 'lib/Bean.php';
require_once 'services/AgentTestService.php';

use \Flight as Flight;
use \app\Bean;
use \app\services\AgentTestService;

// Load workspace config
$configFile = "conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    echo "Error: Workspace config not found: {$configFile}\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
if (!$config) {
    echo "Error: Failed to parse config file\n";
    exit(1);
}

// Initialize Flight config
foreach ($config as $section => $values) {
    if (is_array($values)) {
        foreach ($values as $key => $value) {
            Flight::set("{$section}.{$key}", $value);
        }
    }
}

// Initialize database
try {
    $dbConfig = $config['database'];
    $type = $dbConfig['type'] ?? 'mysql';

    if ($type === 'sqlite') {
        $dbPath = $dbConfig['path'] ?? "database/{$workspace}.sqlite";
        Bean::setup("sqlite:{$dbPath}");
    } else {
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $name = $dbConfig['name'] ?? $workspace;
        $user = $dbConfig['user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? '';
        Bean::setup("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
    }
} catch (\Exception $e) {
    echo "Error: Database initialization failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Convert options to args format for backward compatibility
$args = [
    'agent' => $options['agent'] ?? null,
    'workstation' => $options['workstation'] ?? null,
    'quick' => isset($options['quick']),
    'list' => isset($options['list']),
    'workspace' => $workspace
];

echo "===========================================\n";
echo "Agent + Workstation Test\n";
echo "===========================================\n\n";

// List mode
if ($args['list']) {
    echo "Available Agents:\n";
    echo str_repeat('-', 60) . "\n";

    $agents = AgentTestService::getActiveAgents();
    if (empty($agents)) {
        echo "  No active agents found\n";
    } else {
        foreach ($agents as $agent) {
            $modelInfo = $agent['use_ollama']
                ? "Ollama: {$agent['model']}"
                : "Anthropic: {$agent['model']}";
            printf("  ID: %-3d | %-20s | %s\n", $agent['id'], $agent['name'], $modelInfo);
        }
    }

    echo "\nAvailable Workstations:\n";
    echo str_repeat('-', 60) . "\n";

    $workstations = AgentTestService::getActiveWorkstations();
    if (empty($workstations)) {
        echo "  No active workstations found\n";
    } else {
        foreach ($workstations as $ws) {
            printf("  ID: %-3d | %-20s | %s@%s\n",
                $ws['id'], $ws['name'], $ws['ssh_user'], $ws['host']);
        }
    }

    exit(0);
}

// Validate required arguments
if (!isset($args['agent']) || !isset($args['workstation'])) {
    echo "Usage: php scripts/test/test-agent-workstation.php --agent=ID --workstation=ID [--quick]\n";
    echo "\nOptions:\n";
    echo "  --agent=ID       Agent ID to test\n";
    echo "  --workstation=ID Workstation/shard ID to use\n";
    echo "  --quick          Just test SSH connectivity\n";
    echo "  --list           List available agents and workstations\n";
    echo "  --workspace=SLUG    Workspace database to use\n";
    exit(1);
}

$agentId = (int)$args['agent'];
$workstationId = (int)$args['workstation'];
$quickTest = $args['quick'];

echo "Agent ID: {$agentId}\n";
echo "Workstation ID: {$workstationId}\n";
echo "Mode: " . ($quickTest ? "Quick SSH Test" : "Full Hello World Test") . "\n";
echo "\n";

// Create test service
$testService = AgentTestService::fromIds($agentId, $workstationId);

if (!$testService) {
    echo "ERROR: Agent or workstation not found!\n";
    exit(1);
}

// Display info
$agentInfo = $testService->getAgentInfo();
$wsInfo = $testService->getWorkstationInfo();

echo "Agent: {$agentInfo['name']}\n";
if ($agentInfo['use_ollama']) {
    echo "  Provider: Ollama\n";
    echo "  Host: {$agentInfo['ollama_host']}\n";
    echo "  Model: {$agentInfo['ollama_model']}\n";
} else {
    echo "  Provider: Anthropic\n";
    echo "  Model: {$agentInfo['anthropic_model']}\n";
}

echo "\nWorkstation: {$wsInfo['name']}\n";
echo "  Host: {$wsInfo['ssh_user']}@{$wsInfo['host']}:{$wsInfo['ssh_port']}\n";
echo "\n";

// Run test
echo str_repeat('=', 50) . "\n";
if ($quickTest) {
    echo "Running SSH connectivity test...\n";
    $result = $testService->quickSSHTest();

    if ($result['connected']) {
        echo "\n✓ SSH Connection successful!\n";
        echo "  Time: {$result['time_ms']}ms\n";
    } else {
        echo "\n✗ SSH Connection failed!\n";
        echo "  Error: {$result['error']}\n";
    }
} else {
    echo "Running Hello World test...\n";
    echo "(This may take up to 90 seconds)\n\n";

    $result = $testService->runHelloWorld();

    if ($result['success']) {
        echo "\n✓ Test PASSED!\n";
        echo "  Duration: {$result['duration_ms']}ms\n";
        echo "  Exit code: {$result['exit_code']}\n";
    } else {
        echo "\n✗ Test FAILED!\n";
        echo "  Error: {$result['error']}\n";
        echo "  Duration: {$result['duration_ms']}ms\n";
    }

    if (!empty($result['warning'])) {
        echo "\n⚠ Warning: {$result['warning']}\n";
    }

    if (!empty($result['output'])) {
        echo "\n--- Claude Output ---\n";
        echo $result['output'];
        echo "\n--- End Output ---\n";
    }
}

echo "\n";
$exitSuccess = ($result['success'] ?? false) || ($result['connected'] ?? false);
exit($exitSuccess ? 0 : 1);
