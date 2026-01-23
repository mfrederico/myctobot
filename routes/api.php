<?php
/**
 * API Routes
 *
 * Custom routes for API endpoints that don't follow the default pattern.
 */

use \Flight as Flight;

// MCP API endpoints (workspace-agnostic - uses API key to determine workspace)
Flight::route('GET /api/mcp/tools', ['\app\Api', 'mcptools']);
Flight::route('POST /api/mcp/call', ['\app\Api', 'mcpcall']);

// MCP API endpoints (workspace-specific - REST style for agent tools)
// Note: MCP JSON-RPC servers use subdomain routing (e.g., https://gwt.myctobot.ai/mcp/pipelines)
Flight::route('GET /api/mcp/@workspace/tools', ['\app\Api', 'mcptoolswithworkspace']);
Flight::route('POST /api/mcp/@workspace/call', ['\app\Api', 'mcpcallwithworkspace']);

// MCP config endpoint - returns ready-to-use .mcp.json for an agent
Flight::route('GET /api/mcp/@workspace/config/@agentId', ['\app\Api', 'mcpconfig']);

// API index/health check
Flight::route('GET /api', function() {
    Flight::jsonSuccess(['status' => 'ok', 'service' => 'myctobot-api', 'timestamp' => date('c')]);
});

Flight::route('GET /api/health', function() {
    Flight::jsonSuccess(['status' => 'ok', 'timestamp' => date('c')]);
});

// Auth validation endpoint - used by external services to validate API keys
Flight::route('POST /api/auth/validate', ['\app\Api', 'validate']);

// Workstations/Runners endpoint - list active workstations for job execution
// Requires workspace in URL and API key from workspace's config [api].api_key
Flight::route('GET /api/workstations/@workspace', ['\app\Api', 'workstations']);

// Job Executor integration endpoints (ping/pong validation)
// PONG: Job-executor calls back to validate job and get details
Flight::route('POST /api/jobexecutor/validate', ['\app\Jobexecutor', 'validate']);
// Status updates (called by job-executor to report progress)
Flight::route('POST /api/jobexecutor/status', ['\app\Jobexecutor', 'status']);

// CEO Directive endpoints - secure message reception for CEO directives
// Requires API key authentication via X-API-Key header or Authorization: Bearer token
Flight::route('POST /api/ceo/directive', ['\app\Ceodirective', 'receive']);
Flight::route('GET /api/ceo/directive/@id', ['\app\Ceodirective', 'get']);

// PM Assistant context endpoint - returns project/epic/story data for PM chatbot
// Used by RAG service to fetch live data instead of duplicating queries
Flight::route('GET /api/pm/context/@workspace', ['\app\Api', 'pmcontext']);

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
