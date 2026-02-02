<?php
// Seed default authcontrol permissions

$permissions = [
    // Public pages (level 101)
    ['index', 'index', 101, 'Landing page'],
    ['auth', 'login', 101, 'Login page'],
    ['auth', 'dologin', 101, 'Process login'],
    ['auth', 'google', 101, 'Start Google OAuth'],
    ['auth', 'googlecallback', 101, 'Google OAuth callback'],
    ['auth', 'forgot', 101, 'Forgot password'],
    ['auth', 'resetpassword', 101, 'Reset password'],
    ['auth', 'register', 101, 'Registration page'],

    // Authenticated user pages (level 100)
    ['auth', 'logout', 100, 'Logout'],
    ['dashboard', 'index', 100, 'Main dashboard'],
    ['member', 'index', 100, 'Member index'],
    ['member', 'profile', 100, 'View profile'],
    ['member', 'edit', 100, 'Edit profile'],
    ['member', 'settings', 100, 'Member settings'],
    ['member', 'password', 100, 'Change password'],
    ['member', 'avatar', 100, 'Update avatar'],
    ['atlassian', 'index', 100, 'Atlassian connections'],
    ['atlassian', 'connect', 100, 'Start Atlassian OAuth'],
    ['atlassian', 'callback', 100, 'Atlassian OAuth callback'],
    ['atlassian', 'disconnect', 100, 'Disconnect Atlassian'],
    ['atlassian', 'refresh', 100, 'Refresh Atlassian tokens'],
    ['boards', 'index', 100, 'List boards'],
    ['boards', 'discover', 100, 'Discover Jira boards'],
    ['boards', 'add', 100, 'Add board'],
    ['boards', 'edit', 100, 'Edit board'],
    ['boards', 'remove', 100, 'Remove board'],
    ['boards', 'toggle', 100, 'Toggle board status'],
    ['analysis', 'index', 100, 'Analysis dashboard'],
    ['analysis', 'run', 100, 'Run analysis'],
    ['analysis', 'view', 100, 'View analysis'],
    ['analysis', 'email', 100, 'Email analysis'],
    ['settings', 'index', 100, 'Settings page'],
    ['settings', 'profile', 100, 'Profile settings'],
    ['settings', 'notifications', 100, 'Notification settings'],
    ['settings', 'subscription', 100, 'Subscription management'],
    ['enterprise', 'index', 100, 'Enterprise settings'],
    ['enterprise', 'github', 100, 'GitHub settings'],
    ['enterprise', 'githubcallback', 100, 'GitHub OAuth callback'],
    ['enterprise', 'repos', 100, 'Repository management'],
    ['enterprise', 'aidev', 100, 'AI Developer settings'],
    ['enterprise', 'api', 100, 'API key management'],
    ['jobs', 'index', 100, 'AI Dev jobs list'],
    ['jobs', 'view', 100, 'View job detail'],
    ['jobs', 'logs', 100, 'View job logs'],
    ['jobs', 'cancel', 100, 'Cancel job'],
    ['jobs', 'retry', 100, 'Retry job'],
    ['directives', 'index', 100, 'CEO directives list'],
    ['directives', 'view', 100, 'View directive detail'],
    ['directives', 'retry', 100, 'Retry failed directive'],
    ['directives', 'cancel', 100, 'Cancel directive'],
    ['projects', 'index', 100, 'CTO Projects dashboard'],
    ['projects', 'view', 100, 'View project detail'],
    ['projects', 'report', 100, 'Generate project report'],
    ['projects', 'pause', 100, 'Pause project execution'],
    ['projects', 'resume', 100, 'Resume project execution'],
    ['plugins', 'index', 100, 'Plugin marketplace listing'],
    ['plugins', 'search', 100, 'Search plugins'],
    ['plugins', 'autocomplete', 100, 'Plugin name autocomplete'],
    ['plugins', 'view', 100, 'View plugin details'],
    ['pluginregistry', 'index', 100, 'List discovered plugins'],
    ['pipelines', 'index', 100, 'List pipelines'],
    ['pipelines', 'create', 100, 'Create pipeline form'],
    ['pipelines', 'store', 100, 'Store new pipeline'],
    ['pipelines', 'edit', 100, 'Edit pipeline'],
    ['pipelines', 'update', 100, 'Update pipeline'],
    ['pipelines', 'delete', 100, 'Delete pipeline'],
    ['pipelines', 'savestep', 100, 'Save pipeline step'],
    ['pipelines', 'deletestep', 100, 'Delete pipeline step'],
    ['pipelines', 'getstep', 100, 'Get pipeline step'],
    ['pipelines', 'runs', 100, 'View pipeline runs'],
    ['pipelines', 'viewrun', 100, 'View run details'],
    ['pipelines', 'trigger', 100, 'Trigger pipeline run'],
    ['pipelines', 'cancelrun', 100, 'Cancel pipeline run'],
    ['pipelines', 'runstatus', 100, 'Get run status'],
    ['pipein', 'index', 101, 'Pipeline webhook endpoint'],
    ['mcpservers', 'index', 100, 'List MCP servers'],
    ['mcpservers', 'list', 100, 'API list MCP servers'],
    ['mcpservers', 'store', 100, 'Create MCP server'],
    ['mcpservers', 'update', 100, 'Update MCP server'],
    ['mcpservers', 'delete', 100, 'Delete MCP server'],
    ['mcpservers', 'toggleshared', 100, 'Toggle shared status'],
    ['mcpservers', 'toggleactive', 100, 'Toggle active status'],
    ['mcpservers', 'test', 100, 'Test MCP server connection'],
    // Studio Persona Dashboards
    ['studio', 'index', 100, 'Studio dashboard (redirect or selector)'],
    ['studio', 'developer', 100, 'Developer Studio dashboard'],
    ['studio', 'commerce', 100, 'Commerce Studio dashboard'],
    ['studio', 'pipeline', 100, 'Pipeline Studio dashboard'],
    ['studio', 'select', 100, 'Show studio selector'],
    ['studio', 'setstudio', 100, 'Set studio preference'],

    // Orchestration (formerly Studio orchestration features)
    ['orchestration', 'index', 100, 'Orchestration dashboard'],
    ['orchestration', 'templates', 100, 'Browse orchestration templates'],
    ['orchestration', 'template', 100, 'View template details'],
    ['orchestration', 'wizard', 100, 'Orchestration wizard'],
    ['orchestration', 'wizardstep', 100, 'Save wizard step'],
    ['orchestration', 'aiassist', 100, 'AI wizard assistance'],
    ['orchestration', 'finalize', 100, 'Finalize wizard and create project'],
    ['orchestration', 'project', 100, 'View orchestration project'],
    ['orchestration', 'execute', 100, 'Execute orchestration project'],
    ['orchestration', 'pause', 100, 'Pause orchestration project'],
    ['orchestration', 'resume', 100, 'Resume orchestration project'],
    ['orchestration', 'delete', 100, 'Delete orchestration project'],
    ['orchestration', 'history', 100, 'View project history'],

    // Tenant Apps
    ['apps', 'index', 100, 'List tenant apps'],
    ['apps', 'form', 100, 'Create/edit app form'],
    ['apps', 'store', 100, 'Create new app'],
    ['apps', 'update', 100, 'Update app'],
    ['apps', 'delete', 100, 'Delete app'],
    ['apps', 'start', 100, 'Start app'],
    ['apps', 'stop', 100, 'Stop app'],
    ['apps', 'restart', 100, 'Restart app'],
    ['apps', 'status', 100, 'Get app status'],
    ['apps', 'logs', 100, 'Get app logs'],
    ['apps', 'generatekey', 100, 'Generate API key'],
    ['apps', 'api', 100, 'List apps API'],
    ['apps', 'ports', 100, 'Get port statistics'],

    // Admin pages (level 50)
    ['admin', 'index', 50, 'Admin dashboard'],
    ['admin', 'members', 50, 'Manage members'],
    ['admin', 'runners', 50, 'Manage workstations'],
    ['admin', 'createrunner', 50, 'Create workstation'],
    ['admin', 'editrunner', 50, 'Edit workstation'],
    ['admin', 'deleterunner', 50, 'Delete workstation'],
    ['admin', 'testrunner', 50, 'Test workstation'],
    ['admin', 'diagnoserunner', 50, 'Diagnose workstation'],
    ['apikeys', 'index', 50, 'List API keys'],
    ['apikeys', 'store', 50, 'Create API key'],
    ['apikeys', 'update', 50, 'Update API key'],
    ['apikeys', 'delete', 50, 'Delete API key'],
    ['apikeys', 'regenerate', 50, 'Regenerate API key token'],
    ['apikeys', 'toggle', 50, 'Toggle API key status'],
    ['apikeys', 'scopes', 50, 'Get available scopes'],

    // System endpoints (level 1 - ROOT only)
    ['api', 'crondigest', 1, 'Cron digest endpoint'],
    ['webhook', 'jira', 1, 'Jira webhook'],
    ['webhook', 'github', 1, 'GitHub webhook'],
    ['webhook', 'mailgun', 101, 'Mailgun incoming email webhook'],
];

foreach ($permissions as $perm) {
    // Check if permission already exists (idempotent)
    $existing = \RedBeanPHP\R::findOne('authcontrol', 'control = ? AND method = ?', [$perm[0], $perm[1]]);
    if ($existing) {
        // Optionally update level/description if changed
        if ($existing->level != $perm[2] || $existing->description != $perm[3]) {
            $existing->level = $perm[2];
            $existing->description = $perm[3];
            \RedBeanPHP\R::store($existing);
        }
        continue;
    }

    $bean = \RedBeanPHP\R::dispense('authcontrol');
    $bean->control = $perm[0];
    $bean->method = $perm[1];
    $bean->level = $perm[2];
    $bean->description = $perm[3];
    $bean->created_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);
}
