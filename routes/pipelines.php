<?php
/**
 * Pipeline Routes
 *
 * Custom routes for pipeline endpoints including MCP tool exposure.
 */

use \Flight as Flight;

// Pipeline MCP tool endpoints - expose pipelines as LLM tools
// GET /pipelines/mcp/tools/{tenant} - List available pipeline tools
Flight::route('GET /pipelines/mcp/tools/@tenant', ['\app\Pipelines', 'mcptools']);

// POST /pipelines/mcp/call/{tenant}/{slug} - Execute a pipeline tool
Flight::route('POST /pipelines/mcp/call/@tenant/@slug', ['\app\Pipelines', 'mcpcall']);

// Default routing for other pipeline URLs handled by FlightPHP auto-routing
require_once __DIR__ . '/default.php';
