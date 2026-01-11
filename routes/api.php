<?php
/**
 * API Routes
 *
 * Custom routes for API endpoints that don't follow the default pattern.
 */

use \Flight as Flight;

// MCP API endpoints (tenant-agnostic - uses API key to determine tenant)
Flight::route('GET /api/mcp/tools', ['\app\Api', 'mcpTools']);
Flight::route('POST /api/mcp/call', ['\app\Api', 'mcpCall']);

// MCP API endpoints (tenant-specific - tenant encoded in URL for explicit routing)
// Format: /api/mcp/{tenant}/tools and /api/mcp/{tenant}/call
Flight::route('GET /api/mcp/@tenant/tools', ['\app\Api', 'mcpToolsWithTenant']);
Flight::route('POST /api/mcp/@tenant/call', ['\app\Api', 'mcpCallWithTenant']);

// MCP JSON-RPC endpoint (for HTTP MCP server protocol)
// This is the main endpoint Claude Code's MCP client connects to
Flight::route('POST /api/mcp/@tenant', ['\app\Api', 'mcpJsonRpc']);

// MCP config endpoint - returns ready-to-use .mcp.json for an agent
Flight::route('GET /api/mcp/@tenant/config/@agentId', ['\app\Api', 'mcpConfig']);

// API Health check
Flight::route('GET /api/health', function() {
    Flight::jsonSuccess(['status' => 'ok', 'timestamp' => date('c')]);
});

// Fall through to default routes for other /api/* paths
Flight::defaultRoute();
