<?php
// Seed default authcontrol permissions
// Organized by access level, then controller

$permissions = [

    // =========================================================================
    // PUBLIC endpoints (level 101) - No login required
    // =========================================================================

    // Landing / marketing pages
    ['index', 'index', 101, 'Landing page'],
    ['legal', 'terms', 101, 'Terms of Service'],
    ['legal', 'privacy', 101, 'Privacy Policy'],
    ['health', 'index', 101, 'Health check endpoint'],

    // Auth flow
    ['auth', 'login', 101, 'Login page'],
    ['auth', 'dologin', 101, 'Process login'],
    ['auth', 'google', 101, 'Start Google OAuth'],
    ['auth', 'googlecallback', 101, 'Google OAuth callback'],
    ['auth', 'forgot', 101, 'Forgot password'],
    ['auth', 'resetpassword', 101, 'Reset password'],
    ['auth', 'register', 101, 'Registration page'],

    // Contact form
    ['contact', 'index', 101, 'Contact form page'],
    ['contact', 'submit', 101, 'Submit contact form'],

    // Lead capture API
    ['apileads', 'shopifyai', 101, 'Public lead capture for Shopify AI landing page'],

    // Customer support widget (public, token-based auth)
    ['customersupport', 'index', 101, 'Customer support widget embed'],
    ['customersupport', 'verify', 101, 'Verify customer order + email'],
    ['customersupport', 'chat', 101, 'Customer support chat interface'],
    ['customersupport', 'message', 101, 'Send message to support agent'],
    ['customersupport', 'poll', 101, 'Poll for new messages'],
    ['customersupport', 'escalate', 101, 'Escalate to human support'],
    ['customersupport', 'status', 101, 'Check session status'],
    ['customersupport', 'transcript', 101, 'Email transcript to customer'],

    // Embeddable widget endpoints
    ['widget', 'index', 101, 'Widget script endpoint'],
    ['widget', 'js', 101, 'Widget JS endpoint'],
    ['widget', 'css', 101, 'Widget CSS endpoint'],
    ['widget', 'status', 101, 'Widget status check'],

    // Webhooks (public, use internal auth)
    ['webhook', 'jira', 1, 'Jira webhook'],
    ['webhook', 'github', 1, 'GitHub webhook'],
    ['webhook', 'mailgun', 101, 'Mailgun incoming email webhook'],
    ['webhook', 'aidev', 101, 'AI Developer shard callback webhook'],
    ['webhook', 'digest', 101, 'Digest callback webhook'],

    // Pipeline public endpoints (handle own auth)
    ['pipein', 'index', 101, 'Pipeline webhook endpoint'],
    ['pipelines', 'form', 101, 'Pipeline form input page'],
    ['pipelines', 'formsubmit', 101, 'Pipeline form submission'],
    ['pipelines', 'input', 101, 'Pipeline webhook input'],
    ['pipelines', 'mcpcall', 101, 'MCP tool call API'],
    ['pipelines', 'mcptools', 101, 'MCP tools list API'],
    ['pipelines', 'runsync', 101, 'Sync pipeline execution API'],
    ['pipelines', 'runsyncapi', 101, 'Sync pipeline API with tenant in URL'],

    // App proxy
    ['app', 'index', 101, 'App proxy endpoint'],

    // Hosted detachable apps
    ['dapp', 'index', 101, 'Hosted detachable app serving'],

    // API endpoints (public, use bearer token auth)
    ['api', 'index', 101, 'API status endpoint'],
    ['api', 'auth', 101, 'Auth token validation'],
    ['api', 'health', 101, 'API health check'],
    ['api', 'ceo', 101, 'CEO directive endpoints'],
    ['api', 'crondigest', 1, 'Cron digest endpoint'],
    ['api', 'jobexecutor', 101, 'Job executor integration'],
    ['api', 'jobs', 101, 'Job status updates'],
    ['api', 'mcp', 101, 'MCP tools gateway'],
    ['api', 'pipelines', 101, 'Run pipeline via API'],
    ['api', 'pipelinestatus', 101, 'Pipeline status'],
    ['api', 'pm', 101, 'PM Assistant context'],
    ['api', 'runner', 101, 'Runner API endpoints'],
    ['api', 'workstations', 101, 'List workstations API'],

    // MCP endpoints (public, use header-based auth)
    ['mcp', 'jira', 101, 'MCP Jira HTTP endpoint'],
    ['mcp', 'crm', 101, 'MCP CRM tools endpoint'],
    ['mcp', 'pipelines', 101, 'MCP Pipelines endpoint'],
    ['mcp', 'tools', 101, 'MCP tools management'],

    // Runner API (legacy)
    ['runnerapi', 'runnercallback', 101, 'Runner job completion callback'],
    ['runnerapi', 'runnerjobstatus', 100, 'Get runner job status'],
    ['runnerapi', 'runnerjoboutput', 100, 'Get runner job output'],
    ['runnerapi', 'runners', 100, 'List available runners'],

    // Stripe
    ['stripe', 'checkout', 100, 'Stripe checkout'],
    ['stripe', 'portal', 100, 'Stripe billing portal'],
    ['stripe', 'webhook', 101, 'Stripe webhooks'],
    ['stripe', 'cancel', 100, 'Cancel subscription'],
    ['stripe', 'reactivate', 100, 'Reactivate subscription'],

    // Shard analysis (public callback)
    ['analysis', 'shardaidev', 101, 'AI Developer shard endpoint'],

    // Error pages
    ['error', 'forbidden', 100, 'Forbidden error page'],

    // =========================================================================
    // MEMBER endpoints (level 100) - Logged-in users
    // =========================================================================

    // Auth
    ['auth', 'logout', 100, 'Logout'],

    // Dashboard
    ['dashboard', 'index', 100, 'Main dashboard'],

    // Member profile
    ['member', 'index', 100, 'Member index'],
    ['member', 'profile', 100, 'View profile'],
    ['member', 'edit', 100, 'Edit profile'],
    ['member', 'update', 100, 'Update member'],
    ['member', 'view', 100, 'View member'],
    ['member', 'settings', 100, 'Member settings'],
    ['member', 'password', 100, 'Change password'],
    ['member', 'avatar', 100, 'Update avatar'],

    // Settings
    ['settings', 'index', 100, 'Settings page'],
    ['settings', 'profile', 100, 'Profile settings'],
    ['settings', 'notifications', 100, 'Notification settings'],
    ['settings', 'subscription', 100, 'Subscription management'],
    ['settings', 'connections', 100, 'Connected Services Management'],
    ['settings', 'dismisswizard', 100, 'Dismiss setup wizard'],
    ['settings', 'repos', 100, 'Repository settings'],

    // Assist (detached chat window)
    ['assist', 'chat', 100, 'Detached assistant chat window'],

    // Connections (unified connector)
    ['connections', 'index', 100, 'List all connections'],
    ['connections', 'connect', 100, 'Initiate OAuth connection'],
    ['connections', 'callback', 101, 'OAuth callback (public for provider redirect)'],
    ['connections', 'disconnect', 100, 'Disconnect a connection'],
    ['connections', 'test', 100, 'Test a connection'],
    ['connections', 'add', 100, 'Add manual API key connection'],

    // Atlassian
    ['atlassian', 'index', 100, 'Atlassian connections'],
    ['atlassian', 'connect', 100, 'Start Atlassian OAuth'],
    ['atlassian', 'callback', 100, 'Atlassian OAuth callback'],
    ['atlassian', 'disconnect', 100, 'Disconnect Atlassian'],
    ['atlassian', 'refresh', 100, 'Refresh Atlassian tokens'],

    // Boards
    ['boards', 'index', 100, 'List boards'],
    ['boards', 'discover', 100, 'Discover Jira boards'],
    ['boards', 'add', 100, 'Add board'],
    ['boards', 'edit', 100, 'Edit board'],
    ['boards', 'remove', 100, 'Remove board'],
    ['boards', 'toggle', 100, 'Toggle board status'],

    // Analysis
    ['analysis', 'index', 100, 'Analysis dashboard'],
    ['analysis', 'run', 100, 'Run analysis'],
    ['analysis', 'view', 100, 'View analysis'],
    ['analysis', 'email', 100, 'Email analysis'],
    ['analysis', 'progress', 100, 'View analysis progress'],
    ['analysis', 'status', 100, 'Get analysis job status'],
    ['analysis', 'runshard', 100, 'Run shard analysis'],
    ['analysis', 'shardprogress', 100, 'View shard analysis progress'],
    ['analysis', 'shardstatus', 100, 'Get shard job status'],

    // Enterprise
    ['enterprise', 'index', 100, 'Enterprise settings'],
    ['enterprise', 'github', 100, 'GitHub settings'],
    ['enterprise', 'githubcallback', 100, 'GitHub OAuth callback'],
    ['enterprise', 'repos', 100, 'Repository management'],
    ['enterprise', 'aidev', 100, 'AI Developer settings'],
    ['enterprise', 'api', 100, 'API key management'],
    ['enterprise', 'settings', 100, 'Enterprise settings page'],
    ['enterprise', 'jobs', 100, 'AI Dev jobs'],
    ['enterprise', 'joblogs', 100, 'AI Dev job logs'],
    ['enterprise', 'jobstatus', 100, 'AI Dev job status'],
    ['enterprise', 'startjob', 100, 'Start AI Dev job'],
    ['enterprise', 'completejob', 100, 'Mark AI Dev job complete'],
    ['enterprise', 'resumejob', 100, 'Resume AI Dev job'],
    ['enterprise', 'retryjob', 100, 'Retry AI Dev job'],
    ['enterprise', 'testkey', 100, 'Test API key'],
    ['enterprise', 'mapboard', 100, 'Map board to repo'],
    ['enterprise', 'connectrepo', 100, 'Connect repository'],
    ['enterprise', 'upgradescopes', 100, 'Upgrade OAuth scopes'],

    // Jobs
    ['jobs', 'index', 100, 'AI Dev jobs list'],
    ['jobs', 'view', 100, 'View job detail'],
    ['jobs', 'logs', 100, 'View job logs'],
    ['jobs', 'cancel', 100, 'Cancel job'],
    ['jobs', 'retry', 100, 'Retry job'],

    // Directives
    ['directives', 'index', 100, 'CEO directives list'],
    ['directives', 'view', 100, 'View directive detail'],
    ['directives', 'retry', 100, 'Retry failed directive'],
    ['directives', 'cancel', 100, 'Cancel directive'],

    // Projects
    ['projects', 'index', 100, 'CTO Projects dashboard'],
    ['projects', 'view', 100, 'View project detail'],
    ['projects', 'report', 100, 'Generate project report'],
    ['projects', 'pause', 100, 'Pause project execution'],
    ['projects', 'resume', 100, 'Resume project execution'],

    // Plugins
    ['plugins', 'index', 100, 'Plugin marketplace listing'],
    ['plugins', 'search', 100, 'Search plugins'],
    ['plugins', 'autocomplete', 100, 'Plugin name autocomplete'],
    ['plugins', 'view', 100, 'View plugin details'],
    ['pluginregistry', 'index', 100, 'List discovered plugins'],

    // Pipelines
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
    ['pipelines', 'pipelinestepslist', 100, 'Get pipeline steps list'],

    // MCP Servers
    ['mcpservers', 'index', 100, 'List MCP servers'],
    ['mcpservers', 'list', 100, 'API list MCP servers'],
    ['mcpservers', 'store', 100, 'Create MCP server'],
    ['mcpservers', 'update', 100, 'Update MCP server'],
    ['mcpservers', 'delete', 100, 'Delete MCP server'],
    ['mcpservers', 'toggleshared', 100, 'Toggle shared status'],
    ['mcpservers', 'toggleactive', 100, 'Toggle active status'],
    ['mcpservers', 'test', 100, 'Test MCP server connection'],

    // Studio Persona Dashboards
    ['studio', 'index', 100, 'Studio dashboard'],
    ['studio', 'developer', 100, 'Developer Studio dashboard'],
    ['studio', 'commerce', 100, 'Commerce Studio dashboard'],
    ['studio', 'pipeline', 100, 'Pipeline Studio dashboard'],
    ['studio', 'select', 100, 'Show studio selector'],
    ['studio', 'setstudio', 100, 'Set studio preference'],

    // Orchestration
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

    // Pipeline Studio
    ['pipelinestudio', 'index', 100, 'Pipeline Studio dashboard'],
    ['pipelinestudio', 'templates', 100, 'Browse studio templates'],
    ['pipelinestudio', 'template', 100, 'View template details'],
    ['pipelinestudio', 'wizard', 100, 'Studio wizard'],
    ['pipelinestudio', 'wizardstep', 100, 'Save wizard step'],
    ['pipelinestudio', 'aiassist', 100, 'AI wizard assistance'],
    ['pipelinestudio', 'finalize', 100, 'Finalize wizard and create project'],
    ['pipelinestudio', 'project', 100, 'View studio project'],
    ['pipelinestudio', 'execute', 100, 'Execute studio project'],
    ['pipelinestudio', 'pause', 100, 'Pause studio project'],
    ['pipelinestudio', 'resume', 100, 'Resume studio project'],
    ['pipelinestudio', 'delete', 100, 'Delete studio project'],
    ['pipelinestudio', 'history', 100, 'View project history'],

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
    ['apps', 'download', 100, 'Download app'],

    // App Builder
    ['appbuilder', 'index', 100, 'App Builder main view'],
    ['appbuilder', 'screens', 100, 'List app screens'],
    ['appbuilder', 'screen', 100, 'Create/update screen'],
    ['appbuilder', 'deletescreen', 100, 'Delete screen'],
    ['appbuilder', 'reorder', 100, 'Reorder blocks/screens'],
    ['appbuilder', 'preview', 100, 'Preview app'],
    ['appbuilder', 'blocktypes', 100, 'Get block type registry'],
    ['appbuilder', 'deploy', 100, 'Build and deploy app'],
    ['appbuilder', 'savesecurity', 100, 'Save app security config'],

    // Detachable Apps
    ['detachableapps', 'index', 100, 'List detachable apps'],
    ['detachableapps', 'form', 100, 'Create/edit detachable app form'],
    ['detachableapps', 'store', 100, 'Create new detachable app'],
    ['detachableapps', 'update', 100, 'Update detachable app'],
    ['detachableapps', 'delete', 100, 'Delete detachable app'],
    ['detachableapps', 'screens', 100, 'Manage app screens'],
    ['detachableapps', 'addscreen', 100, 'Add screen to app'],
    ['detachableapps', 'removescreen', 100, 'Remove screen from app'],
    ['detachableapps', 'updatescreens', 100, 'Update screen routes/order'],
    ['detachableapps', 'pipelines', 100, 'Manage pipeline bindings'],
    ['detachableapps', 'bindpipeline', 100, 'Bind pipeline to app'],
    ['detachableapps', 'unbindpipeline', 100, 'Unbind pipeline from app'],
    ['detachableapps', 'build', 100, 'Build app files'],
    ['detachableapps', 'release', 100, 'Release app version'],
    ['detachableapps', 'releases', 100, 'View release history'],
    ['detachableapps', 'download', 100, 'Download app zip'],
    ['detachableapps', 'stitchprojects', 100, 'AJAX load Stitch projects'],
    ['detachableapps', 'createproject', 100, 'Create Stitch project inline'],
    ['detachableapps', 'templates', 100, 'Browse app templates'],
    ['detachableapps', 'templateform', 100, 'Configure app template'],
    ['detachableapps', 'createfromtemplate', 100, 'Create app from template'],
    ['detachableapps', 'addblockscreen', 100, 'Add blank block screen'],

    // Detachable App Block Editor
    ['detachableappbuilder', 'index', 100, 'Block editor for detachable apps'],
    ['detachableappbuilder', 'screens', 100, 'List screens (JSON)'],
    ['detachableappbuilder', 'screen', 100, 'Save screen blocks'],
    ['detachableappbuilder', 'deletescreen', 100, 'Delete screen'],
    ['detachableappbuilder', 'reorder', 100, 'Reorder blocks/screens'],
    ['detachableappbuilder', 'preview', 100, 'Preview rendered blocks'],
    ['detachableappbuilder', 'pipeline', 100, 'Pipeline proxy for preview'],
    ['detachableappbuilder', 'blocktypes', 100, 'Block type registry'],

    // Shopify stores
    ['shopify', 'index', 100, 'Shopify stores list'],
    ['shopify', 'add', 100, 'Add Shopify store'],
    ['shopify', 'connect', 100, 'Start Shopify OAuth'],
    ['shopify', 'callback', 100, 'Shopify OAuth callback'],
    ['shopify', 'test', 100, 'Test Shopify connection'],
    ['shopify', 'disconnect', 100, 'Disconnect Shopify store'],
    ['shopify', 'linkrepo', 100, 'Link repo to store'],
    ['shopify', 'unlinkrepo', 100, 'Unlink repo from store'],
    ['shopify', 'crawlerconfig', 100, 'Crawler config'],
    ['shopify', 'savecrawlerconfig', 100, 'Save crawler config'],
    ['shopify', 'crawldiscover', 100, 'Discover URLs'],
    ['shopify', 'crawlpages', 100, 'Crawl pages'],
    ['shopify', 'crawlstatus', 100, 'Crawl status'],
    ['shopify', 'crawledpages', 100, 'List crawled pages'],
    ['shopify', 'crawlsearch', 100, 'Search crawled content'],
    ['shopify', 'crawlreset', 100, 'Reset crawler'],

    // Stitch
    ['stitch', 'index', 100, 'Stitch connections page'],
    ['stitch', 'connect', 100, 'Start Stitch OAuth flow'],
    ['stitch', 'callback', 100, 'Handle Stitch OAuth callback'],
    ['stitch', 'test', 100, 'Test Stitch connection'],
    ['stitch', 'disconnect', 100, 'Disconnect Stitch account'],
    ['stitch', 'projects', 100, 'List Stitch projects'],
    ['stitch', 'browse', 100, 'Browse Stitch screens'],
    ['stitch', 'screens', 100, 'List project screens'],
    ['stitch', 'viewscreen', 100, 'View screen details'],
    ['stitch', 'downloadhtml', 100, 'Download screen HTML'],
    ['stitch', 'previewhtml', 100, 'Preview screen HTML'],
    ['stitch', 'screendata', 100, 'Get screen data'],
    ['stitch', 'updateproject', 100, 'Update GCP project ID'],

    // Schedules
    ['schedules', 'index', 100, 'View schedules list'],
    ['schedules', 'create', 100, 'Create new schedule'],
    ['schedules', 'edit', 100, 'Edit schedule'],
    ['schedules', 'store', 100, 'Store new schedule'],
    ['schedules', 'update', 100, 'Update schedule'],
    ['schedules', 'toggle', 100, 'Toggle schedule active status'],
    ['schedules', 'delete', 100, 'Delete schedule'],
    ['schedules', 'preview', 100, 'Preview schedule runs'],
    ['schedules', 'calendardata', 100, 'Calendar events JSON'],

    // Workstations
    ['workstations', 'index', 100, 'List workstations'],
    ['workstations', 'create', 100, 'Create workstation form'],
    ['workstations', 'edit', 100, 'Edit workstation'],
    ['workstations', 'delete', 100, 'Delete workstation'],
    ['workstations', 'test', 100, 'Test workstation connection'],
    ['workstations', 'diagnose', 100, 'Run workstation diagnostic'],
    ['workstations', 'helloworld', 100, 'Test Claude CLI on workstation'],
    ['workstations', 'testwithagent', 100, 'Test workstation with agent'],
    ['workstations', 'teststatus', 100, 'Get test status'],
    ['workstations', 'testagents', 100, 'Get available agents for testing'],
    ['workstations', 'testjobexecutor', 100, 'Test job executor'],
    ['workstations', 'health', 100, 'Health check all workstations'],
    ['workstations', 'sshkeys', 100, 'List SSH keys'],
    ['workstations', 'generatesshkey', 100, 'Generate SSH key'],
    ['workstations', 'getsshkey', 100, 'Get SSH key details'],
    ['workstations', 'deletesshkey', 100, 'Delete SSH key'],

    // =========================================================================
    // SALES endpoints (level 75) - Sales Representatives
    // =========================================================================

    // Agreement (digital signature for sales reps)
    ['agreement', 'index', 75, 'View agreement for signing'],
    ['agreement', 'sign', 75, 'Submit signed agreement'],
    ['agreement', 'view', 75, 'View signed agreement'],

    // =========================================================================
    // ADMIN endpoints (level 50) - Administrators
    // =========================================================================

    // CRM admin settings
    ['crm', 'settings', 50, 'Prospect pipeline settings'],
    ['crm', 'dosettings', 50, 'Save prospect pipeline settings'],

    // Admin
    ['admin', 'index', 50, 'Admin dashboard'],
    ['admin', 'members', 50, 'Manage members'],
    ['admin', 'addmember', 50, 'Add member'],
    ['admin', 'editMember', 50, 'Edit member'],
    ['admin', 'resendinvite', 50, 'Resend member invite'],
    ['admin', 'runners', 50, 'Manage workstations'],
    ['admin', 'createrunner', 50, 'Create workstation'],
    ['admin', 'editrunner', 50, 'Edit workstation'],
    ['admin', 'deleterunner', 50, 'Delete workstation'],
    ['admin', 'testrunner', 50, 'Test workstation'],
    ['admin', 'diagnoserunner', 50, 'Diagnose workstation'],
    ['admin', 'settings', 50, 'Admin settings'],
    ['admin', 'cache', 50, 'Cache management'],
    ['admin', 'shards', 50, 'View Claude Code shards'],
    ['admin', 'createshard', 50, 'Create new shard'],
    ['admin', 'editshard', 50, 'Edit existing shard'],
    ['admin', 'deleteshard', 50, 'Delete shard'],
    ['admin', 'testshard', 50, 'Test shard connection'],
    ['admin', 'shardhealth', 50, 'Health check all shards'],
    ['admin', 'diagnoseshard', 50, 'Diagnose shard connection'],
    ['admin', 'shardmcp', 50, 'Manage shard MCP servers'],

    // API Keys
    ['apikeys', 'index', 50, 'List API keys'],
    ['apikeys', 'store', 50, 'Create API key'],
    ['apikeys', 'update', 50, 'Update API key'],
    ['apikeys', 'delete', 50, 'Delete API key'],
    ['apikeys', 'regenerate', 50, 'Regenerate API key token'],
    ['apikeys', 'toggle', 50, 'Toggle API key status'],
    ['apikeys', 'scopes', 50, 'Get available scopes'],

    // Live Chat (agent endpoints)
    ['livechat', 'index', 100, 'Live chat agent dashboard'],
    ['livechat', 'chat', 100, 'View single chat session'],
    ['livechat', 'pickup', 100, 'Pick up queued chat'],
    ['livechat', 'reply', 100, 'Reply to customer in chat'],
    ['livechat', 'resolve', 100, 'Resolve/close chat session'],
    ['livechat', 'transfer', 100, 'Transfer chat to another agent'],
    ['livechat', 'poll', 100, 'Poll for new chat messages'],
    ['livechat', 'status', 100, 'Toggle agent online/away/offline'],
    ['livechat', 'dashboard', 100, 'AJAX refresh dashboard data'],
    ['livechat', 'intents', 100, 'Get available intent forms for session'],
    ['livechat', 'sendform', 100, 'Send intent form to customer'],
    ['livechat', 'snippets', 100, 'Quick reply snippets CRUD'],

    // Live Chat (new features)
    ['livechat', 'sessions', 100, 'View all chat sessions'],
    ['livechat', 'viewsession', 100, 'View session transcript'],
    ['livechat', 'takeover', 100, 'Take over bot conversation'],
    ['livechat', 'typing', 100, 'Signal agent typing status'],

    // Live Chat (admin endpoints)
    ['livechat', 'admin', 50, 'Live chat admin overlord view'],
    ['livechat', 'assign', 50, 'Force-assign chat to agent'],
    ['livechat', 'agents', 50, 'Manage CS agents'],
    ['livechat', 'knowledge', 50, 'Manage live chat knowledge base'],
    ['livechat', 'chatbots', 50, 'Manage chatbot instances'],
    ['livechat', 'chatbotsave', 50, 'Save chatbot config'],
    ['livechat', 'chatbottoken', 50, 'Generate chatbot widget token'],
    ['livechat', 'chatbottriggers', 50, 'Save chatbot trigger rules'],
    ['livechat', 'chatbotkbs', 50, 'Link knowledge bases to chatbot'],
    ['livechat', 'chatbotcrawl', 50, 'Trigger chatbot knowledge crawl'],
    ['livechat', 'chatbotkbdocs', 50, 'View chatbot knowledge base documents'],
    ['livechat', 'chatbotdelete', 50, 'Archive/delete chatbot'],

    // Agents
    ['agents', 'index', 100, 'List AI agents'],
    ['agents', 'create', 100, 'Create agent form'],
    ['agents', 'store', 100, 'Store new agent'],
    ['agents', 'edit', 100, 'Edit agent'],
    ['agents', 'update', 100, 'Update agent'],
    ['agents', 'getcapabilities', 100, 'Get agent capabilities'],
    ['agents', 'tools', 100, 'Agent tools management'],

    // Anthropic
    ['anthropic', 'index', 50, 'Anthropic keys management'],
    ['anthropic', 'keys', 50, 'List Anthropic keys'],
    ['anthropic', 'addkey', 50, 'Add Anthropic key'],
    ['anthropic', 'test', 50, 'Test Anthropic connection'],
    ['anthropic', 'testkey', 50, 'Test specific key'],
    ['anthropic', 'toggleshare', 50, 'Toggle key sharing'],
    ['anthropic', 'clearWarning', 50, 'Clear key warning'],

    // Analysis (admin)
    ['analysis', 'sharddigest', 50, 'Shard digest management'],

    // Atlassian (admin)
    ['atlassian', 'refreshwebhook', 50, 'Refresh Jira webhook'],

    // Contact admin
    ['contact', 'admin', 50, 'View all contact messages'],
    ['contact', 'view', 50, 'View contact message'],
    ['contact', 'respond', 50, 'Respond to contact message'],
    ['contact', 'status', 50, 'Update contact message status'],
    ['contact', 'delete', 50, 'Delete contact message'],

    // Docs
    ['docs', 'index', 50, 'Documentation home'],
    ['docs', 'api', 50, 'API documentation'],
    ['docs', 'cli', 50, 'CLI documentation'],
    ['docs', 'caching', 50, 'Caching documentation'],

    // Enterprise (admin)
    ['enterprise', 'assignagent', 50, 'Assign agent to job'],
    ['enterprise', 'shopify', 50, 'Shopify enterprise settings'],
    ['enterprise', 'startsharded', 50, 'Start sharded job'],

    // GitHub (admin)
    ['github', 'repos', 50, 'GitHub repositories'],
    ['github', 'repolist', 50, 'List GitHub repos'],
    ['github', 'disconnectrepo', 50, 'Disconnect GitHub repo'],

    // Help
    ['help', 'index', 50, 'Help page'],

    // MCP Jobs
    ['mcpjobs', 'handle', 100, 'Handle MCP job request'],

    // Pipelines
    ['pipelines', 'run', 100, 'Run pipeline directly'],
    ['pipelines', 'status', 100, 'Pipeline engine status'],

    // Shopify (admin)
    ['shopify', 'update', 50, 'Update Shopify store'],

    // Settings (admin)
    ['settings', 'export', 50, 'Export settings'],

    // =========================================================================
    // ROOT endpoints (level 1) - Super admin only
    // =========================================================================

    // Admin (root)
    ['admin', 'permissions', 1, 'Permission management'],
    ['admin', 'sshkeys', 1, 'SSH key management'],
    ['admin', 'generatesshkey', 1, 'Generate SSH key'],
    ['admin', 'getsshkey', 1, 'Get SSH key'],
    ['admin', 'testagents', 1, 'Test agents'],
    ['admin', 'testhelloworld', 1, 'Test hello world'],
    ['admin', 'testjobexecutor', 1, 'Test job executor'],
    ['admin', 'testwithagent', 1, 'Test with agent'],
    ['admin', 'teststatus', 1, 'Get test status'],

    // Agents (member-level except workstations)
    ['agents', 'delete', 100, 'Delete agent'],
    ['agents', 'createfromwizard', 100, 'Create agent from wizard'],
    ['agents', 'getmodels', 100, 'Get available models'],
    ['agents', 'linkmcp', 100, 'Link MCP server to agent'],
    ['agents', 'testagent', 100, 'Test agent'],
    ['agents', 'teststatus', 100, 'Get agent test status'],
    ['agents', 'workstations', 100, 'Agent workstation config'],

    // Atlassian (root)
    ['atlassian', 'upgradescopes', 1, 'Upgrade Atlassian scopes'],

    // App Builder (root)
    ['appbuilder', 'pipeline', 1, 'App Builder pipeline config'],

    // Docs (root)
    ['docs', 'pipelines', 1, 'Pipeline documentation'],

    // Enterprise (root)
    ['enterprise', 'job', 1, 'Enterprise job detail'],

    // GitHub (root)
    ['github', 'index', 1, 'GitHub settings'],
    ['github', 'connect', 1, 'Start GitHub OAuth'],
    ['github', 'callback', 1, 'GitHub OAuth callback'],
    ['github', 'disconnect', 1, 'Disconnect GitHub'],
    ['github', 'addrepo', 1, 'Add GitHub repo'],
    ['github', 'mapboard', 1, 'Map board to GitHub'],
    ['github', 'unmapboard', 1, 'Unmap board from GitHub'],
    ['github', 'toggleissues', 1, 'Toggle GitHub issues sync'],
    ['github', 'assignagent', 1, 'Assign agent to GitHub repo'],
    ['github', 'updateslug', 1, 'Update GitHub slug'],

    // Jobs (root)
    ['jobs', 'status', 1, 'Job executor status'],
    ['jobs', 'cleanup', 101, 'Job cleanup (API auth)'],

    // Knowledge Base
    ['knowledgebase', 'index', 1, 'Knowledge base management'],
    ['knowledgebase', 'createkb', 1, 'Create knowledge base'],
    ['knowledgebase', 'upload', 1, 'Upload documents'],
    ['knowledgebase', 'chat', 100, 'Knowledge base chat'],
    ['knowledgebase', 'query', 1, 'Query knowledge base'],
    ['knowledgebase', 'executequery', 1, 'Execute knowledge base query'],

    // Mailgun
    ['mailgun', 'index', 1, 'Mailgun settings'],
    ['mailgun', 'save', 1, 'Save Mailgun config'],
    ['mailgun', 'test', 1, 'Test Mailgun connection'],
    ['mailgun', 'sendtest', 1, 'Send test email'],

    // Pipelines (member-level except engine management)
    ['pipelines', 'enginestatus', 50, 'Pipeline engine status'],
    ['pipelines', 'export', 100, 'Export pipeline'],
    ['pipelines', 'import', 100, 'Import pipeline'],
    ['pipelines', 'extractvariables', 100, 'Extract step variables'],
    ['pipelines', 'getstepvariables', 100, 'Get step variables'],
    ['pipelines', 'interactivestatus', 100, 'Interactive run status'],
    ['pipelines', 'messages', 100, 'Pipeline run messages'],
    ['pipelines', 'movestep', 100, 'Move step position'],
    ['pipelines', 'rerunfrom', 100, 'Rerun from step'],
    ['pipelines', 'resumerun', 100, 'Resume paused run'],
    ['pipelines', 'savestepmappings', 100, 'Save step mappings'],
    ['pipelines', 'startengine', 50, 'Start pipeline engine'],
    ['pipelines', 'stepnext', 100, 'Advance to next step'],
    ['pipelines', 'stopengine', 50, 'Stop pipeline engine'],
    ['pipelines', 'submitmappings', 100, 'Submit step mappings'],
    ['pipelines', 'testcommand', 100, 'Test step command'],
    ['pipelines', 'testparser', 100, 'Test step parser'],
    ['pipelines', 'triggerinteractive', 100, 'Trigger interactive run'],
    ['pipelines', 'updatestepparallel', 100, 'Update step parallel config'],
    ['pipelines', 'wizard', 100, 'Pipeline wizard'],
    ['pipelines', 'wizardsubmit', 100, 'Submit pipeline wizard'],

    // Runners (root)
    ['runners', 'index', 1, 'Runners management'],

    // Settings (root)
    ['settings', 'boards', 1, 'Board settings'],

    // Shopify (member)
    ['shopify', 'generatewidgettoken', 100, 'Generate widget token'],
    ['shopify', 'linksupportpipeline', 100, 'Link support pipeline'],
    ['shopify', 'publishwidget', 100, 'Publish/unpublish widget to Shopify'],
    ['shopify', 'widgetstatus', 100, 'Check widget install status'],
    ['shopify', 'testquery', 100, 'Test GraphQL query'],
    ['shopify', 'themes', 100, 'Manage Shopify themes'],

    // Stitch (root)
    ['stitch', 'tools', 1, 'Stitch MCP tools'],
    ['stitch', 'calltool', 1, 'Call Stitch MCP tool'],

    // Studio (root)
    ['studio', 'apps', 1, 'Studio apps config'],
    ['studio', 'dev', 1, 'Studio dev config'],
    ['studio', 'pipelines', 1, 'Studio pipelines config'],
    ['studios', 'index', 1, 'Studios management'],
    ['studios', 'switch', 1, 'Switch studio'],
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
