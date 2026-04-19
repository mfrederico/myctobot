#!/usr/bin/env php
<?php
/**
 * AI Page Assistant WebSocket Server
 *
 * Standalone OpenSwoole WebSocket server for the Page Assistant feature.
 * Listens on port 9510 by default.
 *
 * Usage:
 *   php scripts/assist-websocket-server.php [--port=9510] [--host=0.0.0.0]
 *
 * Configuration via environment variables:
 *   ASSIST_WS_PORT - WebSocket port (default: 9510)
 *   ASSIST_WS_HOST - Bind host (default: 0.0.0.0)
 *   OLLAMA_HOST - Ollama API URL (default: http://localhost:11434)
 *   OLLAMA_MODEL - Model to use (default: qwen3-coder:30b)
 *
 * Test with wscat:
 *   wscat -c ws://localhost:9510
 *   > {"type":"chat","message":"Hello","context":{"page":"test"}}
 */

// Check for OpenSwoole extension
if (!extension_loaded('openswoole')) {
    echo "ERROR: OpenSwoole extension is not loaded.\n";
    echo "Install with: pecl install openswoole\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['port:', 'host:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
AI Page Assistant WebSocket Server

Usage:
  php scripts/assist-websocket-server.php [options]

Options:
  --port=PORT    WebSocket port (default: 9510)
  --host=HOST    Bind host (default: 0.0.0.0)
  --help         Show this help

Environment Variables:
  ASSIST_WS_PORT   WebSocket port
  ASSIST_WS_HOST   Bind host
  OLLAMA_HOST      Ollama API URL (default: http://localhost:11434)
  OLLAMA_MODEL     Model name (default: qwen3-coder:30b)

HELP;
    exit(0);
}

// Set up paths
$basePath = dirname(__DIR__);
chdir($basePath);

// Load assistant configuration from ini file
$configFile = $basePath . '/conf/assistant.ini';
$config = file_exists($configFile) ? parse_ini_file($configFile, true) : [];

// Configuration (ini file -> env vars -> defaults)
$port = (int)($options['port'] ?? $config['websocket']['port'] ?? getenv('ASSIST_WS_PORT') ?: 9510);
$host = $options['host'] ?? $config['websocket']['host'] ?? getenv('ASSIST_WS_HOST') ?: '0.0.0.0';
$ollamaHost = $config['ollama']['host'] ?? getenv('OLLAMA_HOST') ?: 'http://localhost:11434';
$ollamaModel = $config['ollama']['model'] ?? getenv('OLLAMA_MODEL') ?: 'qwen3-coder:30b';
$ollamaContextSize = (int)($config['ollama']['context_size'] ?? getenv('OLLAMA_CONTEXT_SIZE') ?: 65536);

// RAG service configuration
$ragServiceUrl = $config['rag']['service_url'] ?? getenv('RAG_SERVICE_URL') ?: 'http://localhost:9501';
// parse_ini_file returns "1" for true, "0" or "" for false
$ragEnabledRaw = $config['rag']['enabled'] ?? '1';
$ragEnabled = in_array(strtolower((string)$ragEnabledRaw), ['1', 'true', 'yes', 'on'], true);
$ragKbSlug = $config['rag']['kb_slug'] ?? null;
$ragSimilarityThreshold = (float)($config['rag']['similarity_threshold'] ?? 0.4);
$ragMaxResults = (int)($config['rag']['max_results'] ?? 3);

// MCP configuration
$mcpEnabledRaw = $config['mcp']['enabled'] ?? '0';
$mcpEnabled = in_array(strtolower((string)$mcpEnabledRaw), ['1', 'true', 'yes', 'on'], true);
$mcpConfigPath = $config['mcp']['config_path'] ?? null;
if ($mcpConfigPath && !str_starts_with($mcpConfigPath, '/')) {
    $mcpConfigPath = $basePath . '/' . $mcpConfigPath;
}

// Load composer autoloader
require_once $basePath . '/vendor/autoload.php';

// Load required services manually (not in composer autoload)
require_once $basePath . '/services/AssistSitemapService.php';
require_once $basePath . '/services/PageAssistantService.php';
require_once $basePath . '/services/AssistKnowledgeStore.php';
require_once $basePath . '/services/AssistWebSocketHandler.php';

use OpenSwoole\WebSocket\Server;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use app\services\AssistWebSocketHandler;
use app\services\PageAssistantService;

echo "========================================\n";
echo "AI Page Assistant WebSocket Server\n";
echo "========================================\n";
echo "Port: {$port}\n";
echo "Host: {$host}\n";
echo "Ollama: {$ollamaHost}\n";
echo "Model: {$ollamaModel}\n";
echo "Context: {$ollamaContextSize}\n";
echo "RAG Service: {$ragServiceUrl}\n";
echo "RAG Enabled: " . ($ragEnabled ? 'yes' : 'no') . "\n";
if ($ragKbSlug) echo "RAG KB: {$ragKbSlug}\n";
echo "MCP Tools: " . ($mcpEnabled ? 'yes' : 'no') . "\n";
if ($mcpConfigPath) echo "MCP Config: {$mcpConfigPath}\n";
echo "========================================\n";

// Test Ollama connection
echo "Testing Ollama connection...\n";
$testService = new PageAssistantService([
    'ollama_host' => $ollamaHost,
    'model' => $ollamaModel
]);
$testResult = $testService->testConnection();
if ($testResult['success']) {
    echo "✓ Ollama connected\n";
    if (!$testResult['model_available']) {
        echo "⚠ Warning: Model '{$ollamaModel}' not found. Available: " . implode(', ', $testResult['models']) . "\n";
    }
} else {
    echo "✗ Ollama connection failed: {$testResult['message']}\n";
    echo "  Server will start but chat won't work until Ollama is available.\n";
}
echo "========================================\n";

// Create WebSocket server
$server = new Server($host, $port);

// Server settings
$server->set([
    'worker_num' => 2,
    'max_request' => 10000,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
]);

// Create handler
$handler = null;

// Server start
$server->on('Start', function (Server $server) use ($port, $host) {
    echo "Server started at ws://{$host}:{$port}\n";
    echo "Press Ctrl+C to stop\n";

    // Write PID file
    $pidFile = dirname(__DIR__) . '/storage/assist-ws.pid';
    file_put_contents($pidFile, $server->master_pid);
});

// Worker start - initialize handler per worker
$server->on('WorkerStart', function (Server $server, int $workerId) use (&$handler, $ollamaHost, $ollamaModel, $ollamaContextSize, $ragEnabled, $ragSimilarityThreshold, $ragMaxResults, $mcpEnabled, $mcpConfigPath) {
    $handler = new AssistWebSocketHandler($server, [
        'ollama_host' => $ollamaHost,
        'model' => $ollamaModel,
        'max_tokens' => $ollamaContextSize,
        'use_rag_service' => $ragEnabled,
        'use_mcp_tools' => $mcpEnabled,
        'mcp_config_path' => $mcpConfigPath,
    ]);
    echo "Worker {$workerId} started\n";
});

// Handle HTTP requests (for health check)
$server->on('Request', function (Request $request, Response $response) {
    $path = $request->server['request_uri'] ?? '/';

    if ($path === '/health' || $path === '/') {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status' => 'ok',
            'service' => 'assist-websocket',
            'time' => date('c')
        ]));
        return;
    }

    $response->status(404);
    $response->end('Not Found');
});

// WebSocket open
$server->on('Open', function (Server $server, Request $request) use (&$handler) {
    if ($handler) {
        $handler->onOpen($request, $request->fd);
    }
});

// WebSocket message
$server->on('Message', function (Server $server, Frame $frame) use (&$handler) {
    if ($handler) {
        $handler->onMessage($frame);
    }
});

// WebSocket close
$server->on('Close', function (Server $server, int $fd) use (&$handler) {
    if ($handler) {
        $handler->onClose($fd);
    }
});

// Handle shutdown
$server->on('Shutdown', function () {
    echo "\nServer shutting down...\n";

    // Remove PID file
    $pidFile = dirname(__DIR__) . '/storage/assist-ws.pid';
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
});

// Start server
$server->start();
