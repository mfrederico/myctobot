<?php
/**
 * MCP Routes
 *
 * Routes for Model Context Protocol HTTP endpoints.
 * These use workspace-aware URLs: /mcp/workspace/jira
 * where workspace is the domain ID (e.g., gwt-myctobot-ai)
 */

use \Flight as Flight;

// workspace-aware MCP Jira endpoint: /mcp/workspace/jira
// The workspace parameter is the domain ID from TmuxManager::getDomainId()
// e.g., /mcp/gwt-myctobot-ai/jira
Flight::route('POST|GET|OPTIONS /mcp/@workspace/jira', function($workspace) {
    $controller = new \app\Mcpjira();
    $controller->jirawithworkspace($workspace);
});

// MCP Jira endpoint without workspace (uses Basic Auth for workspace identification)
Flight::route('POST|GET|OPTIONS /mcp/jira', function() {
    $controller = new \app\Mcpjira();
    $controller->jira();
});

// MCP Jobs endpoint for job status callbacks
// /mcp/workspace/jobs - AI Dev runners call this to report completion
Flight::route('POST|GET|OPTIONS /mcp/@workspace/jobs', function($workspace) {
    $controller = new \app\Mcpjobs();
    $controller->handlewithworkspace($workspace);
});

// MCP Pipelines endpoint - execute pipelines as MCP tools
// /mcp/workspace/pipelines - Claude Code calls this to run pipelines
Flight::route('POST|GET|OPTIONS /mcp/@workspace/pipelines', function($workspace) {
    $controller = new \app\Mcppipelines();
    $controller->index($workspace);
});
