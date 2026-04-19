<?php
/**
 * Assist Sitemap Service
 *
 * Dynamically generates a sitemap from controllers for the AI Assistant.
 * Scans controls/*.php for lowercase public methods (routable endpoints).
 *
 * Usage:
 *   $sitemap = AssistSitemapService::generate();
 *   $sitemap = AssistSitemapService::generateForAssistant(); // Formatted for LLM
 */

namespace app\services;

class AssistSitemapService
{
    /**
     * Page descriptions and metadata
     * Key format: "controller" or "controller/method"
     */
    private static array $pageDescriptions = [
        // Main navigation
        'index' => [
            'title' => 'Dashboard',
            'description' => 'Main dashboard showing workspace overview, recent activity, and quick stats',
            'keywords' => ['home', 'dashboard', 'overview', 'main']
        ],

        // Apps
        'apps' => [
            'title' => 'Tenant Apps',
            'description' => 'Create and manage standalone web applications that expose pipelines as HTTP endpoints. Each app runs as its own server.',
            'keywords' => ['apps', 'applications', 'tenant apps', 'servers', 'http endpoints']
        ],
        'apps/create' => [
            'title' => 'Create App',
            'description' => 'Create a new standalone app server that exposes pipelines as HTTP endpoints with optional authentication',
            'keywords' => ['new app', 'create app', 'add app', 'http server']
        ],
        'apps/edit' => [
            'title' => 'Edit App',
            'description' => 'Edit an existing app configuration - pipeline binding, authentication, port, and MCP exposure settings',
            'keywords' => ['edit app', 'modify app', 'update app', 'app settings']
        ],

        // Detachable Apps
        'detachableapps' => [
            'title' => 'Detachable Apps',
            'description' => 'Create and manage standalone app packages. Build from templates, Stitch screens, or the block editor. Apps can be hosted directly or exported as Docker containers.',
            'keywords' => ['detachable apps', 'standalone apps', 'dapp', 'templates', 'docker', 'hosted apps']
        ],
        'detachableappbuilder' => [
            'title' => 'Detachable App Block Editor',
            'description' => 'Visual block editor for building detachable app screens. Drag-drop blocks (forms, tables, charts, KPIs) to create functional app UIs that auto-render to hosted apps.',
            'keywords' => ['block editor', 'detachable app', 'visual builder', 'blocks', 'screens', 'dapp']
        ],

        // Pipelines
        'pipelines' => [
            'title' => 'Pipelines',
            'description' => 'Automated workflows that connect triggers, steps, and actions. Create pipelines for webhooks, scheduled jobs, or multi-step workflows.',
            'keywords' => ['pipelines', 'workflows', 'automation', 'triggers']
        ],
        'pipelines/create' => [
            'title' => 'Create Pipeline',
            'description' => 'Visual pipeline builder to create a new automated workflow with triggers, steps, and actions',
            'keywords' => ['new pipeline', 'create pipeline', 'workflow builder', 'add pipeline']
        ],
        'pipelines/edit' => [
            'title' => 'Edit Pipeline',
            'description' => 'Edit an existing pipeline - modify steps, triggers, and configuration',
            'keywords' => ['edit pipeline', 'modify pipeline', 'update pipeline', 'pipeline settings']
        ],
        'studio/pipeline' => [
            'title' => 'Pipeline Studio',
            'description' => 'List and manage all pipelines in the workspace',
            'keywords' => ['pipeline list', 'pipeline studio', 'manage pipelines']
        ],

        // Agents
        'agents' => [
            'title' => 'AI Agents',
            'description' => 'AI-powered workers that execute tasks. Configure agents with MCP tools and assign them to runners.',
            'keywords' => ['agents', 'ai agents', 'ai workers', 'claude', 'llm']
        ],
        'agents/create' => [
            'title' => 'Create Agent',
            'description' => 'Create a new AI agent with system prompt, tools (MCP servers), and workstation assignment',
            'keywords' => ['new agent', 'create agent', 'add agent', 'agent profile']
        ],
        'agents/edit' => [
            'title' => 'Edit Agent',
            'description' => 'Edit an existing AI agent configuration - system prompt, tools, and workstation',
            'keywords' => ['edit agent', 'modify agent', 'update agent', 'agent settings']
        ],
        // Workstations (standalone controller)
        'workstations' => [
            'title' => 'Workstations',
            'description' => 'Machines (local or remote) that execute AI agent jobs. Register workstations and manage SSH keys here.',
            'keywords' => ['workstations', 'runners', 'servers', 'machines', 'execution', 'remote', 'local', 'ssh keys']
        ],
        'workstations/create' => [
            'title' => 'Create Workstation',
            'description' => 'Add a new workstation for AI agent job execution',
            'keywords' => ['add workstation', 'new workstation', 'create workstation', 'ssh']
        ],
        'workstations/edit' => [
            'title' => 'Edit Workstation',
            'description' => 'Edit workstation settings and SSH configuration',
            'keywords' => ['edit workstation', 'modify workstation', 'workstation settings']
        ],

        // MCP Servers
        'mcpservers' => [
            'title' => 'MCP Servers',
            'description' => 'Model Context Protocol servers that provide tools to AI agents. Configure HTTP or stdio-based servers. Create and manage servers from this page.',
            'keywords' => ['mcp', 'mcp servers', 'tools', 'model context protocol', 'create mcp', 'add mcp server']
        ],

        // Knowledge Base / RAG
        'knowledgebase' => [
            'title' => 'Knowledge Base',
            'description' => 'Upload documents (PDF, text, etc.) for RAG-based AI chat. Query your documents with AI.',
            'keywords' => ['knowledge base', 'rag', 'documents', 'pdf', 'upload', 'vector database']
        ],

        // Shopify
        'shopify' => [
            'title' => 'Shopify Connections',
            'description' => 'Connect Shopify stores for GraphQL queries and theme management. Enter store domain and API token.',
            'keywords' => ['shopify', 'store', 'ecommerce', 'api token', 'store domain']
        ],

        // Settings
        'settings' => [
            'title' => 'Settings',
            'description' => 'Workspace and account settings',
            'keywords' => ['settings', 'configuration', 'preferences']
        ],
        'settings/connections' => [
            'title' => 'Service Connections',
            'description' => 'Connect external services: Mailgun, Jira, GitHub, Anthropic API keys, etc.',
            'keywords' => ['connections', 'integrations', 'api keys', 'mailgun', 'jira', 'github', 'anthropic']
        ],
        'settings/profile' => [
            'title' => 'Profile Settings',
            'description' => 'Edit your user profile: name, email, bio, avatar',
            'keywords' => ['profile', 'account', 'user settings', 'bio', 'avatar']
        ],

        // Member
        'member/edit' => [
            'title' => 'Edit Profile',
            'description' => 'Edit your personal profile information',
            'keywords' => ['profile', 'edit profile', 'my account']
        ],

        // API Keys
        'apikeys' => [
            'title' => 'API Keys',
            'description' => 'Manage API keys for programmatic access to MyCTOBot',
            'keywords' => ['api keys', 'tokens', 'authentication', 'api access']
        ],

        // Jobs
        'jobs' => [
            'title' => 'AI Dev Jobs',
            'description' => 'View and manage AI Developer job executions',
            'keywords' => ['jobs', 'ai dev', 'executions', 'job history']
        ],

        // Jira Boards
        'boards' => [
            'title' => 'Jira Boards',
            'description' => 'Connect and manage Jira boards for AI analysis',
            'keywords' => ['jira', 'boards', 'sprints', 'issues']
        ],

        // GitHub
        'github' => [
            'title' => 'GitHub Connections',
            'description' => 'Connect GitHub repositories for code analysis and PR creation',
            'keywords' => ['github', 'repositories', 'repos', 'code', 'pull requests']
        ],

        // Repos
        'repos' => [
            'title' => 'Repository Connections',
            'description' => 'Manage connected code repositories',
            'keywords' => ['repos', 'repositories', 'git', 'code']
        ],

        // Auth
        'auth/login' => [
            'title' => 'Login',
            'description' => 'Sign in to your MyCTOBot account',
            'keywords' => ['login', 'sign in', 'authentication']
        ],
        'auth/register' => [
            'title' => 'Register',
            'description' => 'Create a new MyCTOBot account',
            'keywords' => ['register', 'sign up', 'create account']
        ],
        'auth/logout' => [
            'title' => 'Logout',
            'description' => 'Sign out of your account',
            'keywords' => ['logout', 'sign out']
        ],

        // Subscription
        'subscription' => [
            'title' => 'Subscription',
            'description' => 'Manage your subscription plan and billing',
            'keywords' => ['subscription', 'billing', 'plan', 'pricing', 'upgrade']
        ],

        // Admin
        'admin' => [
            'title' => 'Admin Panel',
            'description' => 'Administrative tools for workspace management',
            'keywords' => ['admin', 'administration', 'management']
        ],

        // Help & Docs
        'docs' => [
            'title' => 'Documentation',
            'description' => 'Help documentation and guides',
            'keywords' => ['docs', 'documentation', 'help', 'guides']
        ],
    ];

    /**
     * Controllers to skip (internal/API only)
     */
    private static array $skipControllers = [
        'Api', 'Mcp', 'Mcpjira', 'Mcpjobs', 'Mcppipelines',
        'Webhook', 'Health', 'Error', 'Cron', 'Jobexecutor',
        'Anthropic', 'App' // App is the proxy, Apps is the UI
    ];

    /**
     * Methods to skip (internal)
     */
    private static array $skipMethods = [
        'store', 'update', 'delete', 'destroy', 'save',
        'validate', 'process', 'handle', 'callback'
    ];

    /**
     * Generate the full sitemap
     *
     * @param string|null $controllerPath Path to controllers directory
     * @return array Sitemap data
     */
    public static function generate(?string $controllerPath = null): array
    {
        $controllerPath = $controllerPath ?? dirname(__DIR__) . '/controls';
        $sitemap = [];

        foreach (glob($controllerPath . '/*.php') as $file) {
            $className = basename($file, '.php');

            // Skip internal controllers
            if (in_array($className, self::$skipControllers)) {
                continue;
            }

            $controllerSlug = strtolower($className);
            $methods = self::extractMethods($file);

            foreach ($methods as $method) {
                // Skip internal methods
                if (in_array($method, self::$skipMethods)) {
                    continue;
                }

                // Build URL
                $url = $method === 'index'
                    ? "/{$controllerSlug}"
                    : "/{$controllerSlug}/{$method}";

                // Get description
                $key = $method === 'index' ? $controllerSlug : "{$controllerSlug}/{$method}";
                $meta = self::$pageDescriptions[$key] ?? null;

                $sitemap[] = [
                    'url' => $url,
                    'controller' => $controllerSlug,
                    'method' => $method,
                    'title' => $meta['title'] ?? ucfirst($controllerSlug) . ($method !== 'index' ? ' - ' . ucfirst($method) : ''),
                    'description' => $meta['description'] ?? null,
                    'keywords' => $meta['keywords'] ?? [$controllerSlug, $method],
                ];
            }
        }

        // Sort by URL
        usort($sitemap, fn($a, $b) => strcmp($a['url'], $b['url']));

        return $sitemap;
    }

    /**
     * Generate sitemap formatted for the AI assistant
     *
     * @return string Formatted sitemap for LLM context
     */
    public static function generateForAssistant(): string
    {
        $sitemap = self::generate();
        $output = [];

        $output[] = "MYCTOBOT NAVIGATION & PAGES:";
        $output[] = "";

        // Group by category
        $categories = [
            'Main' => ['index', 'dashboard'],
            'Apps & Pipelines' => ['apps', 'detachableapp', 'pipelines', 'studio'],
            'AI & Agents' => ['agents', 'workstations', 'mcpservers', 'jobs'],
            'Integrations' => ['shopify', 'github', 'boards', 'repos', 'knowledgebase'],
            'Settings' => ['settings', 'member', 'apikeys', 'subscription'],
            'Account' => ['auth'],
        ];

        foreach ($categories as $category => $controllers) {
            $categoryPages = array_filter($sitemap, function($page) use ($controllers) {
                foreach ($controllers as $ctrl) {
                    if (strpos($page['controller'], $ctrl) === 0 || $page['controller'] === $ctrl) {
                        return true;
                    }
                }
                return false;
            });

            if (empty($categoryPages)) continue;

            $output[] = "## {$category}";
            foreach ($categoryPages as $page) {
                $line = "- {$page['url']}";
                if ($page['title']) {
                    $line .= " - {$page['title']}";
                }
                if ($page['description']) {
                    $line .= ": {$page['description']}";
                }
                $output[] = $line;
            }
            $output[] = "";
        }

        // Add terminology mapping
        $output[] = "## TERMINOLOGY MAPPINGS:";
        $output[] = "- 'workstation' or 'runner' or 'execution server' = /workstations";
        $output[] = "- 'RAG' or 'knowledge base' or 'documents' or 'pdf upload' = /knowledgebase";
        $output[] = "- 'API keys' or 'tokens' = /apikeys or /settings/connections";
        $output[] = "- 'profile' or 'my account' or 'bio' = /member/edit";
        $output[] = "- 'connections' or 'integrations' or 'mailgun' or 'jira' = /settings/connections";
        $output[] = "- 'mcp' or 'mcp servers' or 'tools' = /mcpservers";
        $output[] = "";

        return implode("\n", $output);
    }

    /**
     * Find the best matching page for a query
     *
     * @param string $query User query or keywords
     * @return array|null Best matching page or null
     */
    public static function findPage(string $query): ?array
    {
        $query = strtolower($query);
        $sitemap = self::generate();
        $bestMatch = null;
        $bestScore = 0;

        foreach ($sitemap as $page) {
            $score = 0;

            // Check title
            if ($page['title'] && stripos($page['title'], $query) !== false) {
                $score += 10;
            }

            // Check description
            if ($page['description'] && stripos($page['description'], $query) !== false) {
                $score += 5;
            }

            // Check keywords
            foreach ($page['keywords'] ?? [] as $keyword) {
                if (stripos($query, $keyword) !== false || stripos($keyword, $query) !== false) {
                    $score += 3;
                }
            }

            // Check URL
            if (stripos($page['url'], $query) !== false) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $page;
            }
        }

        return $bestScore > 0 ? $bestMatch : null;
    }

    /**
     * Extract lowercase public methods from a controller file
     *
     * @param string $file Path to PHP file
     * @return array List of method names
     */
    private static function extractMethods(string $file): array
    {
        $content = file_get_contents($file);
        $methods = [];

        // Match public function declarations with all-lowercase names
        if (preg_match_all('/public\s+function\s+([a-z][a-z0-9]*)\s*\(/m', $content, $matches)) {
            $methods = $matches[1];
        }

        return array_unique($methods);
    }

    /**
     * Add or update a page description
     *
     * @param string $key Page key (controller or controller/method)
     * @param array $meta Metadata array with title, description, keywords
     */
    public static function setPageDescription(string $key, array $meta): void
    {
        self::$pageDescriptions[$key] = $meta;
    }

    /**
     * Get all page descriptions
     *
     * @return array
     */
    public static function getPageDescriptions(): array
    {
        return self::$pageDescriptions;
    }
}
