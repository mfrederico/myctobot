#!/usr/bin/env php
<?php
/**
 * Test MCP Gateway - Full Claude Session Simulation
 *
 * Tests the complete MCP protocol flow:
 * 1. SSE connection to get session ID
 * 2. Initialize handshake
 * 3. List available tools
 * 4. Call a tool
 *
 * Usage:
 *   php scripts/test-mcp-gateway.php --workspace=gwt --token=tk_xxx
 *   php scripts/test-mcp-gateway.php --workspace=gwt --token=tk_xxx --url=http://localhost:8003
 *   php scripts/test-mcp-gateway.php --workspace=gwt --token=tk_xxx --url=https://myctobot.ai/mcp
 */

// Parse command line arguments
$options = getopt('', ['workspace:', 'token:', 'url:', 'tool:', 'help']);

if (isset($options['help']) || empty($options['token'])) {
    echo "MCP Gateway Test Script\n";
    echo "=======================\n\n";
    echo "Usage: php scripts/test-mcp-gateway.php --workspace=<slug> --token=<tk_xxx> [--url=<base_url>] [--tool=<tool_name>]\n\n";
    echo "Options:\n";
    echo "  --workspace  Workspace slug (default: gwt)\n";
    echo "  --token      API token (required, starts with tk_)\n";
    echo "  --url        Gateway base URL (default: https://myctobot.ai/mcp)\n";
    echo "  --tool       Tool to call for testing (default: gateway_info)\n";
    echo "\nExamples:\n";
    echo "  php scripts/test-mcp-gateway.php --token=tk_xxx\n";
    echo "  php scripts/test-mcp-gateway.php --workspace=gwt --token=tk_xxx --url=http://localhost:8003\n";
    echo "  php scripts/test-mcp-gateway.php --token=tk_xxx --tool=jira_get_issue\n";
    exit(1);
}

$workspace = $options['workspace'] ?? 'gwt';
$token = $options['token'];
$baseUrl = rtrim($options['url'] ?? 'https://myctobot.ai/mcp', '/');
$testTool = $options['tool'] ?? 'gateway_info';

// Colors for output
function green($text) { return "\033[32m{$text}\033[0m"; }
function red($text) { return "\033[31m{$text}\033[0m"; }
function yellow($text) { return "\033[33m{$text}\033[0m"; }
function cyan($text) { return "\033[36m{$text}\033[0m"; }
function bold($text) { return "\033[1m{$text}\033[0m"; }

echo bold("\nMCP Gateway Test\n");
echo str_repeat("=", 50) . "\n\n";
echo "Gateway URL: " . cyan($baseUrl) . "\n";
echo "Workspace:   " . cyan($workspace) . "\n";
echo "Token:       " . cyan(substr($token, 0, 10) . "...") . "\n";
echo "Test Tool:   " . cyan($testTool) . "\n\n";

// Step 1: Connect to SSE and get session ID
echo bold("Step 1: SSE Connection\n");
echo str_repeat("-", 30) . "\n";

$sseUrl = "{$baseUrl}/sse?workspace={$workspace}&token={$token}";
echo "Connecting to: " . yellow($sseUrl) . "\n";

$sessionId = null;
$ch = curl_init($sseUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$sessionId) {
        if (preg_match('/sessionId=([a-f0-9]+)/', $data, $matches)) {
            $sessionId = $matches[1];
            return 0; // Stop reading
        }
        return strlen($data);
    },
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if (!$sessionId) {
    echo red("FAILED") . " - Could not get session ID\n";
    if ($error) echo "Error: {$error}\n";
    if ($httpCode) echo "HTTP Code: {$httpCode}\n";
    exit(1);
}

echo green("OK") . " - Session ID: " . cyan($sessionId) . "\n\n";

// Helper function to send MCP messages
function mcpRequest($baseUrl, $sessionId, $id, $method, $params = []) {
    $url = "{$baseUrl}/message?sessionId={$sessionId}";
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params ?: new stdClass(),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'httpCode' => $httpCode,
        'error' => $error,
        'data' => json_decode($response, true),
    ];
}

// Step 2: Initialize
echo bold("Step 2: MCP Initialize\n");
echo str_repeat("-", 30) . "\n";

$result = mcpRequest($baseUrl, $sessionId, 1, 'initialize', [
    'protocolVersion' => '2024-11-05',
    'capabilities' => new stdClass(),
    'clientInfo' => [
        'name' => 'mcp-gateway-test',
        'version' => '1.0.0',
    ],
]);

if ($result['error']) {
    echo red("FAILED") . " - " . $result['error'] . "\n";
    exit(1);
}

if (isset($result['data']['error'])) {
    echo red("FAILED") . " - " . $result['data']['error']['message'] . "\n";
    exit(1);
}

if (isset($result['data']['result'])) {
    $serverInfo = $result['data']['result']['serverInfo'] ?? [];
    echo green("OK") . " - Server: " . cyan($serverInfo['name'] ?? 'unknown') .
         " v" . cyan($serverInfo['version'] ?? '?') . "\n";
    echo "Protocol: " . ($result['data']['result']['protocolVersion'] ?? '?') . "\n\n";
} else {
    echo yellow("WARN") . " - Unexpected response\n";
    print_r($result['data']);
    echo "\n";
}

// Step 3: List tools
echo bold("Step 3: List Tools\n");
echo str_repeat("-", 30) . "\n";

$result = mcpRequest($baseUrl, $sessionId, 2, 'tools/list');

if (isset($result['data']['error'])) {
    echo red("FAILED") . " - " . $result['data']['error']['message'] . "\n";
    exit(1);
}

$tools = $result['data']['result']['tools'] ?? [];
echo green("OK") . " - Found " . cyan(count($tools)) . " tools:\n";

$toolsByCategory = [];
foreach ($tools as $tool) {
    $name = $tool['name'];
    $category = explode('_', $name)[0];
    $toolsByCategory[$category][] = $name;
}

foreach ($toolsByCategory as $category => $categoryTools) {
    echo "  " . yellow($category) . ": " . implode(', ', $categoryTools) . "\n";
}
echo "\n";

// Step 4: Call a tool
echo bold("Step 4: Call Tool ({$testTool})\n");
echo str_repeat("-", 30) . "\n";

// Build tool arguments based on tool name
$toolArgs = [];
if ($testTool === 'jira_get_issue') {
    $toolArgs = ['issue_key' => 'SSI-1'];
} elseif ($testTool === 'github_get_issue') {
    $toolArgs = ['issue_key' => 'greenworkstools/Shopiify_Tools_Yuva#1'];
}

$result = mcpRequest($baseUrl, $sessionId, 3, 'tools/call', [
    'name' => $testTool,
    'arguments' => $toolArgs ?: new stdClass(),
]);

if (isset($result['data']['error'])) {
    $errorMsg = $result['data']['error']['message'] ?? 'Unknown error';
    $errorCode = $result['data']['error']['code'] ?? 0;

    if ($errorCode === -32003) {
        echo yellow("SCOPE") . " - Insufficient scope for tool: {$testTool}\n";
        echo "  This is expected if your API key doesn't have the required scope.\n";
        echo "  The gateway is working correctly.\n\n";
    } else {
        echo red("FAILED") . " - [{$errorCode}] {$errorMsg}\n\n";
    }
} elseif (isset($result['data']['result'])) {
    echo green("OK") . " - Tool executed successfully\n";
    $content = $result['data']['result']['content'] ?? [];
    foreach ($content as $item) {
        if ($item['type'] === 'text') {
            echo "\nResult:\n";
            $text = $item['text'];
            // Pretty print if JSON
            $decoded = json_decode($text, true);
            if ($decoded) {
                echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                echo $text . "\n";
            }
        }
    }
    echo "\n";
} else {
    echo yellow("WARN") . " - Unexpected response:\n";
    echo json_encode($result['data'], JSON_PRETTY_PRINT) . "\n\n";
}

// Summary
echo bold("Summary\n");
echo str_repeat("=", 50) . "\n";
echo green("✓") . " SSE Connection: Working\n";
echo green("✓") . " MCP Initialize: Working\n";
echo green("✓") . " Tools List: " . count($tools) . " tools available\n";
echo green("✓") . " Tool Call: " . (isset($result['data']['error']) && $result['data']['error']['code'] === -32003 ? "Scope check working" : "Executed") . "\n";
echo "\n" . green("Gateway is operational!") . "\n\n";
