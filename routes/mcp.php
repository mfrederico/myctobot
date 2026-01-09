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
    $controller->jiraWithTenant($tenant);
});

// Legacy endpoint without tenant (still works with Basic Auth)
// /mcp/jira - backwards compatible
Flight::route('POST|GET|OPTIONS /mcp/jira', function() {
    $controller = new \app\Mcp();
    $controller->jira();
});
