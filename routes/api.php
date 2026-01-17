<?php
/**
 * API Routes
 *
 * Custom routes for API endpoints that don't follow the default pattern.
 */

use \Flight as Flight;

// MCP API endpoints (tenant-agnostic - uses API key to determine tenant)
Flight::route('GET /api/mcp/tools', ['\app\Api', 'mcptools']);
Flight::route('POST /api/mcp/call', ['\app\Api', 'mcpcall']);

// MCP API endpoints (tenant-specific - tenant encoded in URL for explicit routing)
// Format: /api/mcp/{tenant}/tools and /api/mcp/{tenant}/call
Flight::route('GET /api/mcp/@tenant/tools', ['\app\Api', 'mcptoolswithtenant']);
Flight::route('POST /api/mcp/@tenant/call', ['\app\Api', 'mcpcallwithtenant']);

// MCP JSON-RPC endpoint (for HTTP MCP server protocol)
// This is the main endpoint Claude Code's MCP client connects to
Flight::route('POST /api/mcp/@tenant', ['\app\Api', 'mcpjsonrpc']);

// MCP config endpoint - returns ready-to-use .mcp.json for an agent
Flight::route('GET /api/mcp/@tenant/config/@agentId', ['\app\Api', 'mcpconfig']);

// API index/health check
Flight::route('GET /api', function() {
    Flight::jsonSuccess(['status' => 'ok', 'service' => 'myctobot-api', 'timestamp' => date('c')]);
});

Flight::route('GET /api/health', function() {
    Flight::jsonSuccess(['status' => 'ok', 'timestamp' => date('c')]);
});

// CEO Directive endpoints - secure message reception for CEO directives
// Requires API key authentication via X-API-Key header or Authorization: Bearer token
Flight::route('POST /api/ceo/directive', ['\app\Ceodirective', 'receive']);
Flight::route('GET /api/ceo/directive/@id', ['\app\Ceodirective', 'get']);

// PM Assistant context endpoint - returns project/epic/story data for PM chatbot
// Used by RAG service to fetch live data instead of duplicating queries
Flight::route('GET /api/pm/context/@tenant', ['\app\Api', 'pmcontext']);

// Runner API endpoints - remote runner job execution
// GET /api/runner/boot - Bootstrap script (no auth)
Flight::route('GET /api/runner/boot', ['\app\Runnerapi', 'boot']);

// Job-specific endpoints (require X-Job-Token header)
Flight::route('GET /api/runner/jobs/@jobId/manifest', ['\app\Runnerapi', 'manifest']);
Flight::route('GET /api/runner/jobs/@jobId/runner', ['\app\Runnerapi', 'runner']);
Flight::route('GET /api/runner/jobs/@jobId/files/@filename', ['\app\Runnerapi', 'files']);
Flight::route('POST /api/runner/jobs/@jobId/status', ['\app\Runnerapi', 'status']);
Flight::route('POST /api/runner/jobs/@jobId/log', ['\app\Runnerapi', 'log']);
Flight::route('POST /api/runner/jobs/@jobId/complete', ['\app\Runnerapi', 'complete']);
