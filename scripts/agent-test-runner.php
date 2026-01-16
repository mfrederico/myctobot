#!/usr/bin/env php
<?php
/**
 * Agent Test Runner - Background script for agent+workstation testing
 *
 * Runs as a background process to test an agent configuration on a workstation.
 * Results are stored in a status file that can be polled by the frontend.
 *
 * Usage:
 *   php scripts/agent-test-runner.php --tenant=gwt --agent=2 --workstation=1 --test-id=abc123
 *
 * Status file: /tmp/agent-test-{test_id}.json
 */

// Parse command line arguments
$options = getopt('', ['tenant:', 'agent:', 'workstation:', 'test-id:', 'help']);

if (isset($options['help']) || empty($options['tenant']) || empty($options['agent']) ||
    empty($options['workstation']) || empty($options['test-id'])) {
    echo "Agent Test Runner - Background test execution\n\n";
    echo "Usage: php scripts/agent-test-runner.php --tenant=<tenant> --agent=<id> --workstation=<id> --test-id=<id>\n\n";
    echo "Options:\n";
    echo "  --tenant=<name>       Required. Tenant slug\n";
    echo "  --agent=<id>          Required. Agent ID to test\n";
    echo "  --workstation=<id>    Required. Workstation/shard ID\n";
    echo "  --test-id=<id>        Required. Unique test ID for status tracking\n";
    exit(1);
}

$tenant = $options['tenant'];
$agentId = (int)$options['agent'];
$workstationId = (int)$options['workstation'];
$testId = $options['test-id'];

// Status file path
$statusFile = "/tmp/agent-test-{$testId}.json";

// Write initial status
$status = [
    'test_id' => $testId,
    'agent_id' => $agentId,
    'workstation_id' => $workstationId,
    'status' => 'running',
    'started_at' => date('Y-m-d H:i:s'),
    'message' => 'Initializing test...'
];
file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

// Change to project root
chdir(dirname(__DIR__));

try {
    // Bootstrap
    require_once 'vendor/autoload.php';
    require_once 'lib/FlightMap.php';
    require_once 'lib/Bean.php';
    require_once 'services/AgentTestService.php';

    // Load tenant config
    $configFile = "conf/config.{$tenant}.ini";
    if (!file_exists($configFile)) {
        throw new \Exception("Tenant config not found: {$configFile}");
    }

    $config = parse_ini_file($configFile, true);
    if (!$config) {
        throw new \Exception("Failed to parse config file");
    }

    // Initialize Flight config
    foreach ($config as $section => $values) {
        if (is_array($values)) {
            foreach ($values as $key => $value) {
                \Flight::set("{$section}.{$key}", $value);
            }
        }
    }

    // Initialize database
    $dbConfig = $config['database'];
    $type = $dbConfig['type'] ?? 'mysql';

    if ($type === 'sqlite') {
        $dbPath = $dbConfig['path'] ?? "database/{$tenant}.sqlite";
        \app\Bean::setup("sqlite:{$dbPath}");
    } else {
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $name = $dbConfig['name'] ?? $tenant;
        $user = $dbConfig['user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? '';
        \app\Bean::setup("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
    }

    // Update status
    $status['message'] = 'Loading agent and workstation...';
    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

    // Create test service
    $testService = \app\services\AgentTestService::fromIds($agentId, $workstationId);

    if (!$testService) {
        throw new \Exception('Agent or workstation not found');
    }

    // Get info for status
    $agentInfo = $testService->getAgentInfo();
    $wsInfo = $testService->getWorkstationInfo();

    $status['agent'] = $agentInfo;
    $status['workstation'] = $wsInfo;
    $status['message'] = 'Running Claude test on workstation...';
    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

    // Run the test
    $result = $testService->runHelloWorld();

    // Update final status
    $status['status'] = $result['success'] ? 'completed' : 'failed';
    $status['completed_at'] = date('Y-m-d H:i:s');
    $status['result'] = $result;
    $status['message'] = $result['success'] ? 'Test completed successfully' : ($result['error'] ?? 'Test failed');

    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

    // Exit with appropriate code
    exit($result['success'] ? 0 : 1);

} catch (\Exception $e) {
    // Update status with error
    $status['status'] = 'failed';
    $status['completed_at'] = date('Y-m-d H:i:s');
    $status['error'] = $e->getMessage();
    $status['message'] = 'Error: ' . $e->getMessage();

    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));

    exit(1);
}
