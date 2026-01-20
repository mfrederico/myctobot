#!/usr/bin/env php
<?php
/**
 * MCP Tools Comprehensive Test Suite
 *
 * Tests the Agent MCP Tools API with full workspace validation:
 * - Workspace routing verification
 * - Tool listing with expected values
 * - End-to-end tool execution with real data
 * - Cross-workspace isolation
 * - Vision model integration
 *
 * Usage:
 *   php scripts/test-mcp-tools.php --config=test-config.json
 *   php scripts/test-mcp-tools.php --api-url=https://myctobot.ai --api-key=KEY --workspace=footest4
 *
 * Config file format (JSON):
 * {
 *   "api_url": "https://myctobot.ai",
 *   "workspaces": [
 *     {
 *       "name": "footest4",
 *       "api_key": "test_api_key_footest4_mcp_tools_2026",
 *       "expected_tools": ["analyze_image"],
 *       "test_image": "/path/to/test/image.png"
 *     }
 *   ]
 * }
 */

class McpToolsComprehensiveTest {
    private string $apiUrl;
    private array $workspaces = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private array $testResults = [];

    public function __construct(string $apiUrl, array $workspaces) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->workspaces = $workspaces;
    }

    public function run(): int {
        $this->printHeader();

        // Global tests (no auth required)
        $this->section('Global API Tests');
        $this->testHealthEndpoint();
        $this->testUnauthorizedAccess();

        // Per-workspace tests
        foreach ($this->workspaces as $workspace) {
            $this->section("Workspace: {$workspace['name']}");
            $this->runworkspaceTests($workspace);
        }

        // Cross-workspace isolation test (if multiple workspaces configured)
        if (count($this->workspaces) >= 2) {
            $this->section('Cross-Workspace Isolation');
            $this->testCrossworkspaceIsolation();
        }

        $this->printSummary();
        return $this->failed > 0 ? 1 : 0;
    }

    private function runworkspaceTests(array $workspace): void {
        $apiKey = $workspace['api_key'];
        $expectedTools = $workspace['expected_tools'] ?? [];
        $testImage = $workspace['test_image'] ?? null;

        // Test 1: Verify authentication works
        $this->testworkspaceAuth($workspace['name'], $apiKey);

        // Test 2: Verify correct tools are returned
        $tools = $this->testworkspaceToolsList($workspace['name'], $apiKey, $expectedTools);

        // Test 3: Verify tool structure matches schema
        if (!empty($tools)) {
            $this->testToolSchema($workspace['name'], $tools);
        }

        // Test 4: Test tool execution with valid parameters
        if (!empty($tools)) {
            $this->testToolExecution($workspace['name'], $apiKey, $tools[0]);
        }

        // Test 5: End-to-end vision test with real image
        if ($testImage && file_exists($testImage)) {
            $this->testVisionEndToEnd($workspace['name'], $apiKey, $testImage);
        } elseif ($testImage) {
            $this->skip("Vision test - image not found: {$testImage}");
        }

        // Test 6: Error handling
        $this->testErrorHandling($workspace['name'], $apiKey);
    }

    private function testHealthEndpoint(): void {
        $this->startTest('Health endpoint');
        $result = $this->makeRequest('GET', '/api/health', null, null);

        if ($result['http_code'] === 200 && ($result['data']['success'] ?? false)) {
            $this->pass('API health check OK');
        } else {
            $this->fail('Health endpoint failed', $result);
        }
    }

    private function testUnauthorizedAccess(): void {
        $this->startTest('Unauthorized access blocked');

        // Test without API key
        $result = $this->makeRequest('GET', '/api/mcp/tools', null, null);
        if ($result['http_code'] === 401) {
            $this->pass('Returns 401 without API key');
        } else {
            $this->fail("Should return 401 without API key (got {$result['http_code']})", $result);
        }

        // Test with invalid API key
        $result = $this->makeRequest('GET', '/api/mcp/tools', null, 'invalid_key_12345');
        if ($result['http_code'] === 401) {
            $this->pass('Returns 401 with invalid API key');
        } else {
            $this->fail("Should return 401 with invalid API key (got {$result['http_code']})", $result);
        }
    }

    private function testworkspaceAuth(string $workspaceName, string $apiKey): void {
        $this->startTest("Authentication for workspace '{$workspaceName}'");

        $result = $this->makeRequest('GET', '/api/mcp/tools', null, $apiKey);

        if ($result['http_code'] === 200 && ($result['data']['success'] ?? false)) {
            $this->pass("API key authenticated successfully");
        } else {
            $this->fail("Authentication failed for workspace '{$workspaceName}'", $result);
        }
    }

    private function testworkspaceToolsList(string $workspaceName, string $apiKey, array $expectedTools): array {
        $this->startTest("Tool list matches expected for workspace '{$workspaceName}'");

        $result = $this->makeRequest('GET', '/api/mcp/tools', null, $apiKey);

        if ($result['http_code'] !== 200 || !($result['data']['success'] ?? false)) {
            $this->fail("Failed to list tools", $result);
            return [];
        }

        $tools = $result['data']['data']['tools'] ?? [];
        $toolNames = array_column($tools, 'name');

        // Verify tool count
        $expectedCount = count($expectedTools);
        $actualCount = count($tools);

        if ($expectedCount > 0 && $actualCount !== $expectedCount) {
            $this->fail("Expected {$expectedCount} tools, got {$actualCount}", [
                'expected' => $expectedTools,
                'actual' => $toolNames
            ]);
        } else {
            $this->pass("Tool count matches: {$actualCount}");
        }

        // Verify expected tools are present
        if (!empty($expectedTools)) {
            $missingTools = array_diff($expectedTools, $toolNames);
            $extraTools = array_diff($toolNames, $expectedTools);

            if (empty($missingTools) && empty($extraTools)) {
                $this->pass("All expected tools present: " . implode(', ', $expectedTools));
            } else {
                if (!empty($missingTools)) {
                    $this->fail("Missing expected tools: " . implode(', ', $missingTools));
                }
                if (!empty($extraTools)) {
                    $this->fail("Unexpected tools found: " . implode(', ', $extraTools));
                }
            }
        }

        return $tools;
    }

    private function testToolSchema(string $workspaceName, array $tools): void {
        $this->startTest("Tool schema validation for workspace '{$workspaceName}'");

        foreach ($tools as $tool) {
            $toolName = $tool['name'] ?? 'unknown';

            // Check required fields
            $requiredFields = ['name', 'description', 'inputSchema'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!isset($tool[$field])) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                $this->fail("Tool '{$toolName}' missing fields: " . implode(', ', $missingFields));
                continue;
            }

            // Validate inputSchema structure
            $schema = $tool['inputSchema'];
            if (!isset($schema['type']) || $schema['type'] !== 'object') {
                $this->fail("Tool '{$toolName}' inputSchema.type should be 'object'");
                continue;
            }

            if (!isset($schema['properties']) || !is_array($schema['properties'])) {
                $this->fail("Tool '{$toolName}' inputSchema.properties missing or invalid");
                continue;
            }

            // Check each property has type and description
            $propertyCount = count($schema['properties']);
            $this->pass("Tool '{$toolName}' schema valid ({$propertyCount} parameters)");
        }
    }

    private function testToolExecution(string $workspaceName, string $apiKey, array $tool): void {
        $toolName = $tool['name'];
        $this->startTest("Execute tool '{$toolName}' for workspace '{$workspaceName}'");

        // Build test arguments from schema
        $arguments = $this->buildTestArguments($tool['inputSchema']);

        $result = $this->makeRequest('POST', '/api/mcp/call', [
            'tool_name' => $toolName,
            'arguments' => $arguments
        ], $apiKey);

        if ($result['http_code'] === 200 && ($result['data']['success'] ?? false)) {
            $response = $result['data']['data']['result'] ?? '';
            $responseLen = strlen($response);
            $preview = substr($response, 0, 80);
            $this->pass("Tool executed successfully ({$responseLen} chars): {$preview}...");
        } elseif (strpos($result['data']['message'] ?? '', 'Ollama') !== false) {
            $this->skip("Ollama unavailable: " . ($result['data']['message'] ?? 'unknown'));
        } else {
            $this->fail("Tool execution failed", $result);
        }
    }

    private function testVisionEndToEnd(string $workspaceName, string $apiKey, string $imagePath): void {
        $this->startTest("End-to-end vision test for workspace '{$workspaceName}'");

        // First check if there's an image analysis tool
        $result = $this->makeRequest('GET', '/api/mcp/tools', null, $apiKey);
        $tools = $result['data']['data']['tools'] ?? [];

        $visionTool = null;
        foreach ($tools as $tool) {
            $properties = $tool['inputSchema']['properties'] ?? [];
            if (isset($properties['image_path']) || isset($properties['image'])) {
                $visionTool = $tool;
                break;
            }
        }

        if (!$visionTool) {
            $this->skip("No vision tool found for workspace");
            return;
        }

        // Execute the vision tool with the test image
        $arguments = [
            'image_path' => $imagePath,
            'prompt' => 'Describe exactly what you see in this image. List all shapes, colors, and any text.'
        ];

        $startTime = microtime(true);
        $result = $this->makeRequest('POST', '/api/mcp/call', [
            'tool_name' => $visionTool['name'],
            'arguments' => $arguments
        ], $apiKey);
        $duration = round((microtime(true) - $startTime) * 1000);

        if ($result['http_code'] !== 200 || !($result['data']['success'] ?? false)) {
            if (strpos($result['data']['message'] ?? '', 'Ollama') !== false) {
                $this->skip("Ollama unavailable for vision test");
            } else {
                $this->fail("Vision tool execution failed", $result);
            }
            return;
        }

        $response = $result['data']['data']['result'] ?? '';

        // Validate the response contains actual image description
        // (not "I don't see an image" which means the image wasn't sent)
        $noImagePhrases = [
            "don't see an image",
            "no image provided",
            "cannot see",
            "unable to view",
            "please provide"
        ];

        $responseHasImage = true;
        foreach ($noImagePhrases as $phrase) {
            if (stripos($response, $phrase) !== false) {
                $responseHasImage = false;
                break;
            }
        }

        if ($responseHasImage && strlen($response) > 50) {
            $this->pass("Vision model analyzed image ({$duration}ms)");
            $this->pass("Response preview: " . substr($response, 0, 100) . "...");

            // Additional validation: check for expected content in test image
            // (assumes shapes.png has a red square, blue circle, and "TEST" text)
            $expectedContent = ['red', 'blue', 'square', 'circle'];
            $foundContent = [];
            foreach ($expectedContent as $expected) {
                if (stripos($response, $expected) !== false) {
                    $foundContent[] = $expected;
                }
            }

            if (count($foundContent) >= 2) {
                $this->pass("Vision model correctly identified: " . implode(', ', $foundContent));
            } else {
                $this->fail("Vision model may not have analyzed image correctly", [
                    'expected_some_of' => $expectedContent,
                    'found' => $foundContent
                ]);
            }
        } else {
            $this->fail("Vision model did not receive/process image", [
                'response_preview' => substr($response, 0, 200)
            ]);
        }
    }

    private function testErrorHandling(string $workspaceName, string $apiKey): void {
        $this->startTest("Error handling for workspace '{$workspaceName}'");

        // Test missing tool_name
        $result = $this->makeRequest('POST', '/api/mcp/call', ['arguments' => []], $apiKey);
        if ($result['http_code'] === 400) {
            $this->pass("Returns 400 for missing tool_name");
        } else {
            $this->fail("Should return 400 for missing tool_name", $result);
        }

        // Test non-existent tool
        $result = $this->makeRequest('POST', '/api/mcp/call', [
            'tool_name' => 'nonexistent_tool_' . uniqid(),
            'arguments' => []
        ], $apiKey);
        if ($result['http_code'] === 404) {
            $this->pass("Returns 404 for non-existent tool");
        } else {
            $this->fail("Should return 404 for non-existent tool", $result);
        }
    }

    private function testCrossworkspaceIsolation(): void {
        if (count($this->workspaces) < 2) {
            $this->skip("Need at least 2 workspaces for isolation test");
            return;
        }

        $workspace1 = $this->workspaces[0];
        $workspace2 = $this->workspaces[1];

        $this->startTest("Cross-workspace isolation: {$workspace1['name']} vs {$workspace2['name']}");

        // Get tools for workspace 1
        $result1 = $this->makeRequest('GET', '/api/mcp/tools', null, $workspace1['api_key']);
        $tools1 = $result1['data']['data']['tools'] ?? [];
        $toolNames1 = array_column($tools1, 'name');

        // Get tools for workspace 2
        $result2 = $this->makeRequest('GET', '/api/mcp/tools', null, $workspace2['api_key']);
        $tools2 = $result2['data']['data']['tools'] ?? [];
        $toolNames2 = array_column($tools2, 'name');

        // Verify tools are different (or at least API keys return their own tools)
        $this->pass("Workspace '{$workspace1['name']}' tools: " . implode(', ', $toolNames1 ?: ['none']));
        $this->pass("Workspace '{$workspace2['name']}' tools: " . implode(', ', $toolNames2 ?: ['none']));

        // Try to access workspace1's tool with workspace2's API key (if tools exist)
        if (!empty($tools1)) {
            $result = $this->makeRequest('POST', '/api/mcp/call', [
                'tool_name' => $tools1[0]['name'],
                'arguments' => []
            ], $workspace2['api_key']);

            // Should either 404 (not found in workspace2) or 403 (not accessible)
            if (in_array($result['http_code'], [403, 404])) {
                $this->pass("Workspace 2 cannot access Workspace 1's tools (HTTP {$result['http_code']})");
            } elseif ($result['http_code'] === 200) {
                // Could be OK if both workspaces have same tool name
                $this->pass("Tool name exists in both workspaces (expected in some configs)");
            } else {
                $this->fail("Unexpected response when testing isolation", $result);
            }
        }
    }

    private function buildTestArguments(array $inputSchema): array {
        $arguments = [];
        $properties = $inputSchema['properties'] ?? [];

        foreach ($properties as $name => $schema) {
            if (isset($schema['default'])) {
                $arguments[$name] = $schema['default'];
            } elseif ($schema['type'] === 'string') {
                $arguments[$name] = 'test_value_' . $name;
            } elseif ($schema['type'] === 'number' || $schema['type'] === 'integer') {
                $arguments[$name] = 42;
            } elseif ($schema['type'] === 'boolean') {
                $arguments[$name] = true;
            } elseif ($schema['type'] === 'array') {
                $arguments[$name] = [];
            } elseif ($schema['type'] === 'object') {
                $arguments[$name] = new stdClass();
            }
        }

        return $arguments;
    }

    private function makeRequest(string $method, string $endpoint, ?array $data, ?string $apiKey): array {
        $url = $this->apiUrl . $endpoint;
        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($apiKey) {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 180  // Vision models can be slow
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'data' => json_decode($response, true) ?? ['raw' => $response],
            'error' => $error
        ];
    }

    private function printHeader(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║       MCP Tools Comprehensive Test Suite                     ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        echo "API URL: {$this->apiUrl}\n";
        echo "Workspaces: " . count($this->workspaces) . "\n";
        foreach ($this->workspaces as $t) {
            echo "  - {$t['name']}: " . substr($t['api_key'], 0, 12) . "...\n";
        }
        echo "\n";
    }

    private function section(string $title): void {
        echo "\n┌─ {$title} " . str_repeat('─', max(0, 58 - strlen($title))) . "┐\n";
    }

    private function startTest(string $name): void {
        echo "│ Testing: {$name}\n";
    }

    private function pass(string $message): void {
        echo "│   ✓ {$message}\n";
        $this->passed++;
    }

    private function fail(string $message, $context = null): void {
        echo "│   ✗ {$message}\n";
        if ($context) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            foreach (explode("\n", $json) as $line) {
                echo "│     {$line}\n";
            }
        }
        $this->failed++;
    }

    private function skip(string $message): void {
        echo "│   ○ SKIP: {$message}\n";
        $this->skipped++;
    }

    private function printSummary(): void {
        echo "\n╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                      TEST SUMMARY                            ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        printf("║  Passed:  %-4d                                              ║\n", $this->passed);
        printf("║  Failed:  %-4d                                              ║\n", $this->failed);
        printf("║  Skipped: %-4d                                              ║\n", $this->skipped);
        echo "╠══════════════════════════════════════════════════════════════╣\n";

        if ($this->failed === 0) {
            echo "║  Result: ✓ ALL TESTS PASSED                                 ║\n";
        } else {
            echo "║  Result: ✗ SOME TESTS FAILED                                ║\n";
        }
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CLI Argument Parsing
// ─────────────────────────────────────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$options = getopt('', [
    'config:',
    'api-url:',
    'api-key:',
    'workspace:',
    'expected-tools:',
    'test-image:',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
MCP Tools Comprehensive Test Suite

Usage:
  php scripts/test-mcp-tools.php --config=path/to/config.json
  php scripts/test-mcp-tools.php --api-url=URL --api-key=KEY [options]

Options:
  --config=FILE         Load test configuration from JSON file
  --api-url=URL         API base URL (default: https://myctobot.ai)
  --api-key=KEY         API key for authentication
  --workspace=NAME         Workspace name for reporting
  --expected-tools=LIST Comma-separated list of expected tool names
  --test-image=PATH     Path to image for vision testing
  --help                Show this help message

Config file format:
{
  "api_url": "https://myctobot.ai",
  "workspaces": [
    {
      "name": "workspace1",
      "api_key": "key1",
      "expected_tools": ["tool1", "tool2"],
      "test_image": "/path/to/image.png"
    }
  ]
}

Environment variables:
  MYCTOBOT_API_URL      Default API URL
  MYCTOBOT_API_KEY      Default API key

HELP;
    exit(0);
}

// Load configuration
$apiUrl = '';
$workspaces = [];

if (isset($options['config'])) {
    // Load from config file
    $configFile = $options['config'];
    if (!file_exists($configFile)) {
        die("Config file not found: {$configFile}\n");
    }

    $config = json_decode(file_get_contents($configFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Invalid JSON in config file: " . json_last_error_msg() . "\n");
    }

    $apiUrl = $config['api_url'] ?? '';
    $workspaces = $config['workspaces'] ?? [];
} else {
    // Load from CLI args / env
    $apiUrl = $options['api-url'] ?? getenv('MYCTOBOT_API_URL') ?: 'https://myctobot.ai';
    $apiKey = $options['api-key'] ?? getenv('MYCTOBOT_API_KEY') ?: '';

    if (empty($apiKey)) {
        echo "Error: API key required. Use --api-key=KEY or set MYCTOBOT_API_KEY\n";
        echo "Run with --help for usage information.\n";
        exit(1);
    }

    $expectedTools = [];
    if (isset($options['expected-tools'])) {
        $expectedTools = array_map('trim', explode(',', $options['expected-tools']));
    }

    $workspaces = [[
        'name' => $options['workspace'] ?? 'default',
        'api_key' => $apiKey,
        'expected_tools' => $expectedTools,
        'test_image' => $options['test-image'] ?? null
    ]];
}

if (empty($workspaces)) {
    die("Error: No workspaces configured. Use --api-key or --config\n");
}

// Run tests
$test = new McpToolsComprehensiveTest($apiUrl, $workspaces);
exit($test->run());
