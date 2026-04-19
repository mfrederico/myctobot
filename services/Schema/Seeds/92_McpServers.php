<?php
// Seed default MCP servers (idempotent — skips if name already exists)

$defaults = [
    ['jira', 'Jira Integration (Connected Service)', 'http', true],
    ['pipelines', 'MyCTOBot Pipeline Tools', 'http', true],
    ['playwright', 'Playwright Browser Automation', 'stdio', false],
    ['github', 'GitHub API Integration', 'stdio', false],
    ['fetch', 'HTTP Fetch Capabilities', 'stdio', false],
    ['filesystem', 'Filesystem Access', 'stdio', false],
    ['mantic', 'Semantic Code Search', 'stdio', false],
];

foreach ($defaults as $def) {
    // Skip if already exists
    $existing = \RedBeanPHP\R::findOne('mcpservers', ' name = ? ', [$def[0]]);
    if ($existing) continue;

    $bean = \RedBeanPHP\R::dispense('mcpservers');
    $bean->member_id = null;
    $bean->name = $def[0];
    $bean->description = $def[1];
    $bean->server_type = $def[2];
    $bean->command = $def[2] === 'stdio' ? 'npx' : null;
    $bean->args_json = '[]';
    $bean->url = $def[2] === 'http' ? '' : null;
    $bean->headers_json = '{}';
    $bean->env_json = '{}';
    $bean->is_shared = 1;
    $bean->is_builtin = 1;
    $bean->is_required = $def[3] ? 1 : 0;
    $bean->is_active = 1;
    $bean->created_at = date('Y-m-d H:i:s');
    $bean->updated_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);
}
