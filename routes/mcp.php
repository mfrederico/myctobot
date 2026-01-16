<?php
/**
 * MCP Routes
 *
 * Routes for Model Context Protocol HTTP endpoints.
 * These use tenant-aware URLs: /mcp/{tenant}/jira
 * where tenant is the domain ID (e.g., gwt-myctobot-ai)
 */

use \Flight as Flight;

// Tenant-aware MCP Jira endpoint: /mcp/{tenant}/jira
// The tenant parameter is the domain ID from TmuxManager::getDomainId()
// e.g., /mcp/gwt-myctobot-ai/jira
Flight::route('POST|GET|OPTIONS /mcp/@tenant/jira', function($tenant) {
    $controller = new \app\Mcp();
    $controller->jirawithtenant($tenant);
});

// MCP Jira endpoint without tenant (uses Basic Auth for tenant identification)
Flight::route('POST|GET|OPTIONS /mcp/jira', function() {
    $controller = new \app\Mcp();
    $controller->jira();
});

// MCP Jobs endpoint for job status callbacks
// /mcp/{tenant}/jobs - AI Dev runners call this to report completion
Flight::route('POST|GET|OPTIONS /mcp/@tenant/jobs', function($tenant) {
    $controller = new \app\Mcpjobs();
    $controller->handlewithtenant($tenant);
});
