<?php
/**
 * TenantAppBuilder - Generates app server code from templates
 *
 * Takes a tenantapps bean and generates the complete server code
 * by substituting variables into the template files.
 */

namespace app\services;

use app\Bean;
use app\services\AppBlockRenderer;
use app\services\AppSecurityManager;

class TenantAppBuilder
{
    /**
     * Base paths - dynamically detected
     */
    private static ?string $rootPath = null;

    private static function getRootPath(): string
    {
        if (self::$rootPath === null) {
            self::$rootPath = dirname(__DIR__);
        }
        return self::$rootPath;
    }

    private static function getTemplateDir(): string
    {
        return self::getRootPath() . '/templates/tenantapp';
    }

    private static function getWasmTemplateDir(): string
    {
        return self::getRootPath() . '/templates/tenantapp-wasm';
    }

    private static function getStaticTemplateDir(): string
    {
        return self::getRootPath() . '/templates/tenantapp-static';
    }

    private static function getStorageDir(): string
    {
        return self::getRootPath() . '/storage/apps';
    }

    /**
     * Build complete app directory structure
     *
     * @param int $appId The app ID
     * @return string Path to the app directory
     * @throws \RuntimeException If app not found or build fails
     */
    public function build(int $appId): string
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        $workspace = $app->workspace;
        $slug = $app->slug;
        $appDir = self::getStorageDir() . "/{$workspace}/{$slug}";

        // Create directory structure
        $this->ensureDirectories($appDir);

        // Builder apps get a different generation pipeline
        if ($app->app_type === 'builder') {
            return $this->buildBuilderApp($app, $appDir);
        }

        // Generate files from templates
        $this->generateFile($appDir . '/server.php', $this->generateServer($app));
        $this->generateFile($appDir . '/config.php', $this->generateConfig($app));
        $this->generateFile($appDir . '/bootstrap.php', $this->generateBootstrap($app));

        // Initialize datastore if schema is defined in config_json
        $this->initializeDatastore($app, $workspace, $slug);

        return $appDir;
    }

    /**
     * Build a block-based builder app
     *
     * Loads screens from appscreens table, renders blocks into views,
     * generates controllers, layout, security middleware, and pipeline proxy.
     */
    private function buildBuilderApp($app, string $appDir): string
    {
        // Check build target
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        if (($configJson['build_target'] ?? '') === 'wasm') {
            return $this->buildWasmApp($app, $appDir);
        }
        if (($configJson['build_target'] ?? '') === 'static') {
            return $this->buildStaticApp($app, $appDir);
        }

        $workspace = $app->workspace;
        $slug = $app->slug;

        // Create additional directories for builder apps
        foreach (['/controllers', '/views', '/assets'] as $subdir) {
            $dir = $appDir . $subdir;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Load screens
        $screens = Bean::find('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC, id ASC ', [$app->id]);
        if (empty($screens)) {
            throw new \RuntimeException('No screens defined for builder app');
        }

        $screenList = [];
        foreach ($screens as $s) {
            $screenList[] = [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'route_path' => $s->route_path,
                'layout_type' => $s->layout_type,
                'blocks' => $s->box()->getBlocks(),
                'page_config' => $s->box()->getPageConfig(),
                'is_default' => (bool) $s->is_default,
            ];
        }

        // Parse app config
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $securityConfig = $configJson['security'] ?? AppSecurityManager::getDefaultConfig();
        $appConfig = [
            'name' => $app->name,
            'slug' => $app->slug,
            'workspace' => $workspace,
            'theme_color' => $configJson['theme_color'] ?? '#6366f1',
        ];

        // Get API token for pipeline calls
        $apiToken = '';
        if ($app->apikeys_id) {
            $apiKey = Bean::load('apikeys', $app->apikeys_id);
            if ($apiKey && $apiKey->token) {
                $apiToken = $apiKey->token;
            }
        }

        // Render block views for each screen
        $renderer = new AppBlockRenderer();
        $routeMap = [];

        foreach ($screenList as $screen) {
            $viewContent = $renderer->renderPage($screen, $appConfig);
            $viewFile = $appDir . '/views/' . $screen['slug'] . '.php';
            $this->generateFile($viewFile, $viewContent);
            $routeMap[$screen['route_path']] = $screen['slug'];
        }

        // Generate layout
        $layoutContent = $renderer->generateLayout($screenList, $appConfig);
        $this->generateFile($appDir . '/views/layout.php', $layoutContent);

        // Generate auth page if needed
        if (($securityConfig['auth_type'] ?? 'none') !== 'none') {
            $authPageContent = $renderer->generateAuthPage($securityConfig);
            $this->generateFile($appDir . '/views/auth.php', $authPageContent);
        }

        // Generate the OpenSwoole server with builder-specific handlers
        $serverContent = $this->generateBuilderServer($app, $screenList, $securityConfig, $apiToken, $routeMap);
        $this->generateFile($appDir . '/server.php', $serverContent);

        // Standard files
        $this->generateFile($appDir . '/config.php', $this->generateConfig($app));
        $this->generateFile($appDir . '/bootstrap.php', $this->generateBootstrap($app));

        // Initialize datastore
        $this->initializeDatastore($app, $workspace, $slug);

        return $appDir;
    }

    /**
     * Generate the server.php for a builder app
     *
     * Instead of a single pipeline proxy, builder apps have:
     * - Multi-screen routing based on appscreens
     * - Security middleware (auth, pipeline whitelist, input scoping)
     * - Pipeline proxy with scoped inputs
     * - Auth endpoints (verify, login, logout)
     */
    private function generateBuilderServer($app, array $screens, array $securityConfig, string $apiToken, array $routeMap): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/server.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Server template not found');
        }

        $workspace = addslashes($app->workspace);

        // Build auth handler
        $authHandler = AppSecurityManager::generateAuthMiddleware($securityConfig);

        // Build route handlers for each screen
        $routeHandlers = [];

        // Auth endpoints
        $verifyEndpoint = AppSecurityManager::generateVerifyEndpoint($securityConfig, $workspace);
        if ($verifyEndpoint) {
            $routeHandlers[] = $verifyEndpoint;
        }

        // Pipeline proxy
        $routeHandlers[] = AppSecurityManager::generatePipelineProxy($securityConfig, $workspace, addslashes($apiToken));

        // Screen routes - serve generated HTML views
        foreach ($screens as $screen) {
            $routePath = addslashes($screen['route_path']);
            $viewFile = addslashes($screen['slug']);
            $screenName = addslashes($screen['name']);

            $routeHandlers[] = <<<PHP
        // Screen: {$screenName} ({$routePath})
        if (\$path === '{$routePath}' && \$method === 'GET') {
            \$response->header('Content-Type', 'text/html; charset=utf-8');

            // Read the layout
            \$layoutFile = __DIR__ . '/views/layout.php';
            \$viewFile = __DIR__ . '/views/{$viewFile}.php';

            if (file_exists(\$viewFile)) {
                \$pageContent = file_get_contents(\$viewFile);
            } else {
                \$pageContent = '<div class="alert alert-warning">View not found</div>';
            }

            // Inject page content into layout
            if (file_exists(\$layoutFile)) {
                ob_start();
                include \$layoutFile;
                \$html = ob_get_clean();
            } else {
                \$html = \$pageContent;
            }

            \$response->end(\$html);
            return;
        }
PHP;
        }

        // Root handler for non-default routes
        $defaultScreen = null;
        foreach ($screens as $s) {
            if ($s['is_default'] || $s['route_path'] === '/') {
                $defaultScreen = $s;
                break;
            }
        }

        if (!$defaultScreen && !empty($screens)) {
            $defaultScreen = $screens[0];
        }

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{AUTH_HANDLER}}'] = $authHandler;
        $replacements['{{ROUTE_HANDLERS}}'] = implode("\n\n", $routeHandlers);
        $replacements['{{HELPER_FUNCTIONS}}'] = $this->generateBuilderHelperFunctions($securityConfig);

        return strtr($template, $replacements);
    }

    /**
     * Generate helper functions for builder app servers
     */
    private function generateBuilderHelperFunctions(array $securityConfig): string
    {
        $authType = $securityConfig['auth_type'] ?? 'none';
        $authPageFunc = '';

        if ($authType !== 'none') {
            // Generate the renderAuthPage function
            $renderer = new AppBlockRenderer();
            $authPageHtml = $renderer->generateAuthPage($securityConfig);
            $escapedHtml = addslashes($authPageHtml);
            // Use heredoc for the auth page to avoid escaping issues
            $authPageFunc = <<<'PHP'
function renderAuthPage($config) {
    $viewFile = __DIR__ . '/views/auth.php';
    if (file_exists($viewFile)) {
        return file_get_contents($viewFile);
    }
    return '<h1>Authentication Required</h1>';
}
PHP;
        }

        return $authPageFunc;
    }

    // ─── Static HTML Build Target ────────────────────────────────

    /**
     * Build a static HTML export (no PHP, no server)
     *
     * Generates a single index.html with all screens inline + app-config.js.
     * The app calls MyCTOBot pipeline API for all data/logic.
     */
    private function buildStaticApp($app, string $appDir): string
    {
        // Load screens
        $screens = Bean::find('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC, id ASC ', [$app->id]);
        if (empty($screens)) {
            throw new \RuntimeException('No screens defined for builder app');
        }

        $screenList = [];
        foreach ($screens as $s) {
            $screenList[] = [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'route_path' => $s->route_path,
                'layout_type' => $s->layout_type,
                'blocks' => $s->box()->getBlocks(),
                'page_config' => $s->box()->getPageConfig(),
                'is_default' => (bool) $s->is_default,
            ];
        }

        // Parse app config
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $appConfig = [
            'name' => $app->name,
            'slug' => $app->slug,
            'workspace' => $app->workspace,
            'theme_color' => $configJson['theme_color'] ?? '#6366f1',
        ];

        // Get API token for pipeline calls
        $apiToken = '';
        if ($app->apikeys_id) {
            $apiKey = Bean::load('apikeys', $app->apikeys_id);
            if ($apiKey && $apiKey->token) {
                $apiToken = $apiKey->token;
            }
        }

        // Build API base URL
        $apiBaseUrl = "https://{$app->workspace}.myctobot.ai";

        // Collect allowed pipeline slugs from all blocks
        $allowedPipelines = [];
        foreach ($screenList as $screen) {
            foreach ($screen['blocks'] as $block) {
                $binding = $block['data_binding'] ?? [];
                if (!empty($binding['pipeline_slug'])) {
                    $allowedPipelines[] = $binding['pipeline_slug'];
                }
                $events = $block['events'] ?? [];
                if (!empty($events['onSubmit']['pipeline_slug'])) {
                    $allowedPipelines[] = $events['onSubmit']['pipeline_slug'];
                }
            }
        }
        $allowedPipelines = array_values(array_unique($allowedPipelines));

        // Generate index.html
        $indexHtml = $this->generateStaticHtml($app, $screenList, $appConfig);
        $this->generateFile($appDir . '/index.html', $indexHtml);

        // Generate app-config.js
        $configJs = $this->generateStaticConfig($app, $screenList, $appConfig, $apiBaseUrl, $apiToken, $allowedPipelines);
        $this->generateFile($appDir . '/app-config.js', $configJs);

        return $appDir;
    }

    /**
     * Generate the static index.html with all screens embedded inline
     */
    private function generateStaticHtml($app, array $screens, array $appConfig): string
    {
        $template = file_get_contents(self::getStaticTemplateDir() . '/index.html.tpl');
        if ($template === false) {
            throw new \RuntimeException('Static index.html template not found');
        }

        $renderer = new AppBlockRenderer();

        // Build nav items
        $navHtml = '';
        foreach ($screens as $screen) {
            $screenName = htmlspecialchars($screen['name']);
            $routePath = htmlspecialchars($screen['route_path']);
            $navHtml .= "<li class=\"nav-item\"><a class=\"nav-link\" href=\"#{$routePath}\">{$screenName}</a></li>\n";
        }

        // Build screen sections — each screen's block HTML in a hidden div
        $sectionsHtml = '';
        foreach ($screens as $screen) {
            $slug = htmlspecialchars($screen['slug']);
            $blockHtml = $renderer->renderPage($screen, $appConfig);
            $activeClass = $screen['is_default'] ? ' active' : '';
            $sectionsHtml .= "<div class=\"screen{$activeClass}\" id=\"screen-{$slug}\">\n{$blockHtml}\n</div>\n";
        }

        // Generate runtime JS
        $runtimeJs = $this->generateStaticRuntimeJs();

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{THEME_COLOR}}'] = htmlspecialchars($appConfig['theme_color']);
        $replacements['{{NAV_ITEMS}}'] = $navHtml;
        $replacements['{{SCREEN_SECTIONS}}'] = $sectionsHtml;
        $replacements['{{RUNTIME_JS}}'] = $runtimeJs;

        return strtr($template, $replacements);
    }

    /**
     * Generate app-config.js from template
     */
    private function generateStaticConfig($app, array $screens, array $appConfig, string $apiBaseUrl, string $apiToken, array $allowedPipelines): string
    {
        $template = file_get_contents(self::getStaticTemplateDir() . '/app-config.js.tpl');
        if ($template === false) {
            throw new \RuntimeException('Static app-config.js template not found');
        }

        // Build screens JSON for the router
        $screensData = [];
        foreach ($screens as $screen) {
            $screensData[] = [
                'name' => $screen['name'],
                'slug' => $screen['slug'],
                'route' => $screen['route_path'],
                'is_default' => $screen['is_default'],
            ];
        }

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{API_BASE_URL}}'] = addslashes($apiBaseUrl);
        $replacements['{{API_TOKEN}}'] = addslashes($apiToken);
        $replacements['{{SCREENS_JSON}}'] = json_encode($screensData, JSON_PRETTY_PRINT);
        $replacements['{{ALLOWED_PIPELINES_JSON}}'] = json_encode($allowedPipelines);

        return strtr($template, $replacements);
    }

    /**
     * Generate the JS runtime for static apps
     *
     * Adapts the existing layout JS with:
     * - External API callPipeline() with Bearer auth + JWT
     * - Hash-based client-side router
     * - store_token and logout action handlers
     */
    private function generateStaticRuntimeJs(): string
    {
        return <<<'JS'
// Static App Runtime
document.addEventListener('DOMContentLoaded', function() {
    initRouter();
    initForms();
    initCharts();
    initMarkdown();
});

const appState = {};

function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
}

// ─── Pipeline API ────────────────────────────────────────

async function callPipeline(slug, input = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (APP_CONFIG.apiToken) {
        headers['Authorization'] = 'Bearer ' + APP_CONFIG.apiToken;
    }
    const jwt = localStorage.getItem('app_jwt');
    if (jwt) {
        headers['X-App-User-Token'] = jwt;
    }
    const resp = await fetch(APP_CONFIG.apiBaseUrl + '/api/pipelines/' + encodeURIComponent(slug) + '/run?sync=true', {
        method: 'POST',
        headers,
        body: JSON.stringify(input)
    });
    return resp.json();
}

// ─── Hash Router ─────────────────────────────────────────

function initRouter() {
    function showScreen(hash) {
        const route = hash.replace('#', '') || '/';
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));

        const screen = APP_CONFIG.screens.find(s => s.route === route);
        if (screen) {
            const el = document.getElementById('screen-' + screen.slug);
            if (el) {
                el.classList.add('active');
                initScreenData(el);
            }
        } else {
            // Fallback to default screen
            const def = APP_CONFIG.screens.find(s => s.is_default) || APP_CONFIG.screens[0];
            if (def) {
                const el = document.getElementById('screen-' + def.slug);
                if (el) el.classList.add('active');
            }
        }

        // Update active nav
        document.querySelectorAll('#app-nav .nav-link').forEach(a => {
            a.classList.toggle('active', a.getAttribute('href') === '#' + route);
        });
    }

    window.addEventListener('hashchange', () => showScreen(location.hash));
    showScreen(location.hash || '#/');
}

function initScreenData(screenEl) {
    screenEl.querySelectorAll('[data-pipeline]').forEach(async el => {
        if (el.dataset.loaded) return;
        const slug = el.dataset.pipeline;
        if (!slug) return;
        try {
            const result = await callPipeline(slug);
            if (result.success) {
                updateBlockData(el, result.data || result.output);
            }
            el.dataset.loaded = '1';
        } catch (e) {
            console.error('Pipeline load failed:', slug, e);
        }
    });
}

// ─── Block Data Rendering ────────────────────────────────

function updateBlockData(el, data) {
    // KPI cards
    el.querySelectorAll('[data-kpi]').forEach(kpi => {
        const key = kpi.dataset.kpi;
        const fmt = kpi.dataset.format;
        let val = data[key] ?? '-';
        if (fmt === 'currency' && typeof val === 'number') val = '$' + val.toLocaleString();
        else if (fmt === 'number' && typeof val === 'number') val = val.toLocaleString();
        kpi.textContent = val;
    });

    // Tables
    const colDefs = el.dataset.columns ? JSON.parse(el.dataset.columns) : null;
    if (colDefs) {
        const tbody = el.querySelector('tbody');
        const rows = Array.isArray(data) ? data : (data.rows || data.items || data.edges?.map(e => e.node) || []);
        if (tbody && rows.length) {
            tbody.innerHTML = rows.map(row => {
                return '<tr>' + colDefs.map(col => {
                    let val = row[col.field] ?? '';
                    if (col.render === 'badge' && col.badge_map) {
                        const color = esc(col.badge_map[val] || 'secondary').replace(/[^a-z0-9-]/gi, '');
                        return '<td><span class="badge bg-' + color + '">' + esc(val) + '</span></td>';
                    }
                    if (col.render === 'currency') return '<td>$' + Number(val).toLocaleString() + '</td>';
                    if (col.render === 'date') return '<td>' + esc(new Date(val).toLocaleDateString()) + '</td>';
                    return '<td>' + esc(val) + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }
    }

    // List groups
    const listGroup = el.querySelector('.list-group');
    if (listGroup && !colDefs) {
        const items = Array.isArray(data) ? data : (data.items || []);
        if (items.length) {
            listGroup.innerHTML = items.map(item => {
                const text = typeof item === 'string' ? item : (item.name || item.title || JSON.stringify(item));
                return '<li class="list-group-item">' + esc(text) + '</li>';
            }).join('');
        }
    }
}

// ─── Forms ───────────────────────────────────────────────

function initForms() {
    document.querySelectorAll('[data-block-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type=submit]');
            btn.querySelector('.btn-text').classList.add('d-none');
            btn.querySelector('.btn-loading').classList.remove('d-none');
            btn.disabled = true;

            const formData = Object.fromEntries(new FormData(form));
            const eventConfig = form.dataset.onSubmit ? JSON.parse(form.dataset.onSubmit) : null;

            try {
                if (eventConfig && eventConfig.action === 'run_pipeline') {
                    const result = await callPipeline(eventConfig.pipeline_slug, formData);
                    if (result.success && eventConfig.on_success) {
                        handleAction(eventConfig.on_success, result);
                    } else if (!result.success && eventConfig.on_error) {
                        handleAction(eventConfig.on_error, result);
                    }
                }
            } catch (err) {
                console.error('Form submit error:', err);
            } finally {
                btn.querySelector('.btn-text').classList.remove('d-none');
                btn.querySelector('.btn-loading').classList.add('d-none');
                btn.disabled = false;
            }
        });
    });

    // Button actions
    document.querySelectorAll('[data-action="run_pipeline"]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const slug = btn.dataset.pipeline;
            if (!slug) return;
            btn.disabled = true;
            try {
                await callPipeline(slug);
            } finally {
                btn.disabled = false;
            }
        });
    });

    // Refresh buttons
    document.querySelectorAll('[data-refresh]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.refresh;
            const el = document.getElementById(targetId);
            if (el && el.dataset.pipeline) {
                delete el.dataset.loaded;
                callPipeline(el.dataset.pipeline).then(r => {
                    if (r.success) updateBlockData(el, r.data || r.output);
                    el.dataset.loaded = '1';
                });
            }
        });
    });
}

// ─── Actions ─────────────────────────────────────────────

function handleAction(action, data) {
    if (!action) return;
    if (action.action === 'navigate') {
        let target = action.target || '/';
        target = target.replace(/\{(\w+)\}/g, (_, k) =>
            encodeURIComponent(data[k] || data?.data?.[k] || ''));
        if (/^\s*(javascript|data|vbscript)\s*:/i.test(target)) target = '/';
        location.hash = '#' + target;
    }
    else if (action.action === 'store_token') {
        const token = data?.data?.token || data?.output?.token || data?.token;
        if (token) localStorage.setItem('app_jwt', token);
        // Also navigate if a target is specified
        if (action.target) {
            location.hash = '#' + action.target;
        }
    }
    else if (action.action === 'logout') {
        localStorage.removeItem('app_jwt');
        location.hash = '#' + (action.target || '/login');
    }
    else if (action.action === 'show_alert') {
        const msg = (action.message || '').replace(/\{error\}/g, esc(data.error || data.message || ''));
        const variant = (action.variant || 'danger').replace(/[^a-z0-9-]/gi, '');
        const alertEl = document.createElement('div');
        alertEl.className = 'alert alert-' + variant + ' alert-dismissible fade show';
        alertEl.innerHTML = esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        document.querySelector('.container').prepend(alertEl);
    }
    else if (action.action === 'refresh_block') {
        const el = document.getElementById(action.block_id);
        if (el && el.dataset.pipeline) {
            delete el.dataset.loaded;
            callPipeline(el.dataset.pipeline).then(r => {
                if (r.success) updateBlockData(el, r.data || r.output);
                el.dataset.loaded = '1';
            });
        }
    }
}

// ─── Charts ──────────────────────────────────────────────

function initCharts() {
    document.querySelectorAll('[data-chart-config]').forEach(el => {
        const config = JSON.parse(el.dataset.chartConfig);
        const canvas = el.querySelector('canvas');
        if (!canvas) return;
        el._chart = new Chart(canvas, {
            type: config.chart_type || 'bar',
            data: { labels: [], datasets: [] },
            options: { responsive: true, plugins: { title: { display: false } } }
        });
    });
}

// ─── Markdown ────────────────────────────────────────────

function initMarkdown() {
    if (typeof marked !== 'undefined') {
        marked.setOptions({ breaks: true, gfm: true });
        const renderer = new marked.Renderer();
        const origLink = renderer.link.bind(renderer);
        renderer.link = function(href, title, text) {
            if (/^\s*(javascript|data|vbscript)\s*:/i.test(href || '')) href = '#';
            return origLink(href, title, text);
        };
        marked.use({ renderer });
    }
    document.querySelectorAll('[data-markdown]').forEach(el => {
        if (typeof marked !== 'undefined') {
            let html = marked.parse(el.dataset.markdown);
            html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
            html = html.replace(/on\w+\s*=\s*["'][^"']*["']/gi, '');
            el.innerHTML = html;
        } else {
            el.textContent = el.dataset.markdown;
        }
    });
}
JS;
    }

    /**
     * Package the static app as a downloadable zip
     *
     * @return string Path to the generated zip file
     */
    public function packageStaticZip(string $appDir, string $slug): string
    {
        $zipPath = $appDir . '/' . $slug . '.zip';

        // Remove old zip if exists
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Failed to create zip file: {$zipPath}");
        }

        // Add index.html
        $indexFile = $appDir . '/index.html';
        if (file_exists($indexFile)) {
            $zip->addFile($indexFile, $slug . '/index.html');
        }

        // Add app-config.js
        $configFile = $appDir . '/app-config.js';
        if (file_exists($configFile)) {
            $zip->addFile($configFile, $slug . '/app-config.js');
        }

        $zip->close();

        return $zipPath;
    }

    // ─── WASM Build Target ─────────────────────────────────────

    /**
     * Build a block-based builder app for the WASM (php-cgi-wasm + Node.js) target
     *
     * Generates:
     * - www/index.php      (CGI router)
     * - www/bootstrap.php   (helper functions, no OpenSwoole deps)
     * - www/views/*.php     (reused from AppBlockRenderer)
     * - server.mjs          (Node.js HTTP server)
     * - package.json        (npm dependencies)
     */
    private function buildWasmApp($app, string $appDir): string
    {
        $workspace = $app->workspace;
        $slug = $app->slug;

        // Create WASM-specific directory structure
        $wwwDir = $appDir . '/www';
        foreach ([$wwwDir, $wwwDir . '/views'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Load screens (same as regular builder)
        $screens = Bean::find('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC, id ASC ', [$app->id]);
        if (empty($screens)) {
            throw new \RuntimeException('No screens defined for builder app');
        }

        $screenList = [];
        foreach ($screens as $s) {
            $screenList[] = [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'route_path' => $s->route_path,
                'layout_type' => $s->layout_type,
                'blocks' => $s->box()->getBlocks(),
                'page_config' => $s->box()->getPageConfig(),
                'is_default' => (bool) $s->is_default,
            ];
        }

        // Parse app config
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $securityConfig = $configJson['security'] ?? AppSecurityManager::getDefaultConfig();
        $appConfig = [
            'name' => $app->name,
            'slug' => $app->slug,
            'workspace' => $workspace,
            'theme_color' => $configJson['theme_color'] ?? '#6366f1',
        ];

        // Render block views (reused as-is - pure HTML/Bootstrap, no OpenSwoole deps)
        $renderer = new AppBlockRenderer();
        $routeMap = [];

        foreach ($screenList as $screen) {
            $viewContent = $renderer->renderPage($screen, $appConfig);
            $viewFile = $wwwDir . '/views/' . $screen['slug'] . '.php';
            $this->generateFile($viewFile, $viewContent);
            $routeMap[$screen['route_path']] = $screen['slug'];
        }

        // Generate layout (reused as-is)
        $layoutContent = $renderer->generateLayout($screenList, $appConfig);
        $this->generateFile($wwwDir . '/views/layout.php', $layoutContent);

        // Generate auth page if needed
        if (($securityConfig['auth_type'] ?? 'none') !== 'none') {
            $authPageContent = $renderer->generateAuthPage($securityConfig);
            $this->generateFile($wwwDir . '/views/auth.php', $authPageContent);
        }

        // Get API token for pipeline calls
        $apiToken = '';
        if ($app->apikeys_id) {
            $apiKey = Bean::load('apikeys', $app->apikeys_id);
            if ($apiKey && $apiKey->token) {
                $apiToken = $apiKey->token;
            }
        }

        // Generate CGI-specific files
        $this->generateFile(
            $wwwDir . '/index.php',
            $this->generateWasmRouter($app, $screenList, $securityConfig, $apiToken, $routeMap)
        );
        $this->generateFile($wwwDir . '/bootstrap.php', $this->generateWasmBootstrap($app));

        // Generate Node.js server + package.json (project root, not www/)
        $this->generateFile($appDir . '/server.mjs', $this->generateWasmServer($app));
        $this->generateFile($appDir . '/package.json', $this->generateWasmPackageJson($app));

        return $appDir;
    }

    /**
     * Generate the Node.js server.mjs from WASM template
     */
    private function generateWasmServer($app): string
    {
        $template = file_get_contents(self::getWasmTemplateDir() . '/server.mjs.tpl');
        if ($template === false) {
            throw new \RuntimeException('WASM server template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{PORT}}'] = (string) ($app->port ?: 3000);

        return strtr($template, $replacements);
    }

    /**
     * Generate package.json from WASM template
     */
    private function generateWasmPackageJson($app): string
    {
        $template = file_get_contents(self::getWasmTemplateDir() . '/package.json.tpl');
        if ($template === false) {
            throw new \RuntimeException('WASM package.json template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        return strtr($template, $replacements);
    }

    /**
     * Generate CGI bootstrap.php from WASM template
     */
    private function generateWasmBootstrap($app): string
    {
        $template = file_get_contents(self::getWasmTemplateDir() . '/bootstrap.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('WASM bootstrap template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        return strtr($template, $replacements);
    }

    /**
     * Generate the CGI index.php router for WASM target
     *
     * Converts OpenSwoole $request/$response patterns to standard CGI APIs:
     * - $_SERVER['PATH_INFO'] instead of $request->server['request_uri']
     * - header() instead of $response->header()
     * - echo + exit instead of $response->end()
     * - file_get_contents('php://input') instead of $request->getContent()
     */
    private function generateWasmRouter($app, array $screens, array $securityConfig, string $apiToken, array $routeMap): string
    {
        $template = file_get_contents(self::getWasmTemplateDir() . '/index.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('WASM index.php template not found');
        }

        $workspace = addslashes($app->workspace);
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];

        // Build auth handler for CGI
        $authHandler = $this->generateWasmAuthHandler($securityConfig);

        // Build route handlers
        $routeHandlers = [];

        // Auth endpoints (CGI-adapted)
        $verifyEndpoint = $this->generateWasmVerifyEndpoint($securityConfig, $workspace, $apiToken);
        if ($verifyEndpoint) {
            $routeHandlers[] = $verifyEndpoint;
        }

        // Pipeline proxy (CGI-adapted)
        $routeHandlers[] = $this->generateWasmPipelineProxy($securityConfig, $workspace, $apiToken);

        // Screen routes
        foreach ($screens as $screen) {
            $routePath = addslashes($screen['route_path']);
            $viewSlug = addslashes($screen['slug']);
            $screenName = addslashes($screen['name']);

            $routeHandlers[] = <<<PHP
// Screen: {$screenName} ({$routePath})
if (!\$_handled && \$pathInfo === '{$routePath}' && \$method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo renderView('{$viewSlug}');
    \$_handled = true;
}
PHP;
        }

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{AUTH_HANDLER}}'] = $authHandler;
        $replacements['{{ROUTE_HANDLERS}}'] = implode("\n\n", $routeHandlers);
        $replacements['{{CORS_ORIGIN}}'] = addslashes($configJson['cors_origin'] ?? '*');

        return strtr($template, $replacements);
    }

    /**
     * Generate CGI-compatible auth middleware (replaces OpenSwoole $request/$response patterns)
     */
    private function generateWasmAuthHandler(array $securityConfig): string
    {
        $authType = $securityConfig['auth_type'] ?? 'none';

        if ($authType === 'none') {
            return <<<'PHP'
// No authentication required
$authSession = [];
PHP;
        }

        return <<<PHP
// Authentication: {$authType}
\$authSession = [];
\$sessionToken = \$_COOKIE['app_session'] ?? \$_GET['session_token'] ?? null;

if (\$sessionToken) {
    // Note: In WASM mode, session validation requires outbound HTTP.
    // For the PoC, sessions are not persisted. This is a stub.
    \$authSession = [];
}

// Protected routes require authentication
\$publicPaths = ['/health', '/auth/verify', '/auth/login', '/auth/callback', '/auth/check'];
if (empty(\$authSession) && !in_array(\$pathInfo, \$publicPaths)) {
    \$accept = \$_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos(\$accept, 'application/json') !== false) {
        jsonResponse(['error' => 'Authentication required'], 401);
    } else {
        header('Location: /auth/verify', true, 302);
        \$_handled = true;
    }
}
PHP;
    }

    /**
     * Generate CGI-compatible pipeline proxy (stub for PoC - outbound HTTP not available)
     */
    private function generateWasmPipelineProxy(array $securityConfig, string $workspace, string $apiToken): string
    {
        return <<<'PHP'
// Pipeline proxy endpoint (WASM mode - outbound HTTP not available in PoC)
if (!$_handled && $pathInfo === '/api/pipeline' && $method === 'POST') {
    jsonResponse([
        'error' => 'Pipeline proxy not available in WASM mode',
        'info' => 'Outbound HTTP requires Vrzno extension configuration',
    ], 501);
}
PHP;
    }

    /**
     * Generate CGI-compatible auth verify endpoint (stub for PoC)
     */
    private function generateWasmVerifyEndpoint(array $securityConfig, string $workspace, string $apiToken): string
    {
        $authType = $securityConfig['auth_type'] ?? 'none';

        if ($authType === 'none') {
            return '';
        }

        return <<<'PHP'
// Auth verification page
if (!$_handled && $pathInfo === '/auth/verify' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $viewFile = __DIR__ . '/views/auth.php';
    if (file_exists($viewFile)) {
        readfile($viewFile);
    } else {
        echo '<h1>Authentication Required</h1>';
    }
    $_handled = true;
}

// Auth verification POST (WASM mode - outbound HTTP not available in PoC)
if (!$_handled && $pathInfo === '/auth/verify' && $method === 'POST') {
    jsonResponse([
        'error' => 'Auth verification not available in WASM mode',
        'info' => 'Outbound HTTP requires Vrzno extension configuration',
    ], 501);
}

// Auth check
if (!$_handled && $pathInfo === '/auth/check') {
    jsonResponse([
        'authenticated' => !empty($authSession),
        'email' => $authSession['email'] ?? null,
    ]);
}

// Logout
if (!$_handled && $pathInfo === '/auth/logout') {
    setcookie('app_session', '', time() - 3600, '/');
    header('Location: /auth/verify', true, 302);
    $_handled = true;
}
PHP;
    }

    /**
     * Initialize app datastore from config_json schema definition
     *
     * If config_json contains a "datastore" key with a "schema" definition,
     * this will create the SQLite database and tables for the app.
     *
     * Example config_json:
     * {
     *   "datastore": {
     *     "type": "sqlite",
     *     "schema": {
     *       "submissions": {
     *         "id": "INTEGER PRIMARY KEY AUTOINCREMENT",
     *         "name": "TEXT NOT NULL",
     *         "email": "TEXT NOT NULL",
     *         "created_at": "DATETIME DEFAULT CURRENT_TIMESTAMP"
     *       }
     *     }
     *   }
     * }
     */
    private function initializeDatastore($app, string $workspace, string $slug): void
    {
        $config = json_decode($app->config_json ?: '{}', true) ?: [];
        $datastoreConfig = $config['datastore'] ?? null;

        if (!$datastoreConfig || !isset($datastoreConfig['schema'])) {
            return;
        }

        require_once __DIR__ . '/AppDatastore.php';

        try {
            $datastore = AppDatastore::forApp($workspace, $slug);
            $datastore->ensureSchema($datastoreConfig['schema']);
        } catch (\Exception $e) {
            // Log but don't fail the build - datastore issues shouldn't prevent app creation
            error_log("Warning: Failed to initialize datastore for {$workspace}/{$slug}: " . $e->getMessage());
        }
    }

    /**
     * Ensure all required directories exist
     */
    private function ensureDirectories(string $appDir): void
    {
        $dirs = [
            $appDir,
            $appDir . '/storage',
            $appDir . '/storage/logs',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$dir}");
                }
            }
        }
    }

    /**
     * Write content to file
     */
    private function generateFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    /**
     * Generate server.php content
     */
    public function generateServer($app): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/server.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Server template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{AUTH_HANDLER}}'] = $this->generateAuthHandler($app->auth_type, $app->api_key);
        $replacements['{{ROUTE_HANDLERS}}'] = $this->generateRouteHandlers($app);
        $replacements['{{HELPER_FUNCTIONS}}'] = $this->generateHelperFunctions();

        return strtr($template, $replacements);
    }

    /**
     * Generate helper functions for the server (form rendering, etc.)
     */
    private function generateHelperFunctions(): string
    {
        return <<<'PHP'
/**
 * Render the SPA form HTML
 */
function renderFormHtml(string $appName, string $appSlug, string $description, array $fields, ?string $apiKey = null): string
{
    $fieldsHtml = '';
    foreach ($fields as $field) {
        $required = $field['required'] ? 'required' : '';
        $requiredBadge = $field['required'] ? '<span class="text-danger">*</span>' : '';
        $inputType = 'text';
        $inputTag = 'input';

        // Map JSON schema types to HTML input types
        switch ($field['type']) {
            case 'integer':
            case 'number':
                $inputType = 'number';
                break;
            case 'boolean':
                $inputType = 'checkbox';
                break;
            case 'string':
            default:
                // Check for email/url patterns in name or description
                if (stripos($field['name'], 'email') !== false) {
                    $inputType = 'email';
                } elseif (stripos($field['name'], 'url') !== false || stripos($field['name'], 'repo') !== false) {
                    $inputType = 'url';
                } elseif (stripos($field['name'], 'password') !== false || stripos($field['name'], 'secret') !== false) {
                    $inputType = 'password';
                } elseif (stripos($field['name'], 'instruction') !== false || stripos($field['name'], 'description') !== false || stripos($field['name'], 'message') !== false) {
                    $inputTag = 'textarea';
                }
                break;
        }

        $fieldId = htmlspecialchars($field['name']);
        $fieldLabel = ucwords(str_replace(['_', '-'], ' ', $field['name']));
        $fieldDesc = htmlspecialchars($field['description']);
        $defaultValue = htmlspecialchars($field['default'] ?? '');

        if ($inputTag === 'textarea') {
            $fieldsHtml .= <<<HTML
            <div class="mb-3">
                <label for="{$fieldId}" class="form-label">{$fieldLabel} {$requiredBadge}</label>
                <textarea class="form-control" id="{$fieldId}" name="{$fieldId}" rows="3" {$required} placeholder="{$fieldDesc}">{$defaultValue}</textarea>
                <div class="form-text">{$fieldDesc}</div>
            </div>
HTML;
        } elseif ($inputType === 'checkbox') {
            $checked = $field['default'] ? 'checked' : '';
            $fieldsHtml .= <<<HTML
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="{$fieldId}" name="{$fieldId}" {$checked}>
                <label class="form-check-label" for="{$fieldId}">{$fieldLabel}</label>
                <div class="form-text">{$fieldDesc}</div>
            </div>
HTML;
        } else {
            $fieldsHtml .= <<<HTML
            <div class="mb-3">
                <label for="{$fieldId}" class="form-label">{$fieldLabel} {$requiredBadge}</label>
                <input type="{$inputType}" class="form-control" id="{$fieldId}" name="{$fieldId}" value="{$defaultValue}" {$required} placeholder="{$fieldDesc}">
                <div class="form-text">{$fieldDesc}</div>
            </div>
HTML;
        }
    }

    // If no fields defined, show a generic JSON input
    if (empty($fieldsHtml)) {
        $fieldsHtml = <<<HTML
        <div class="mb-3">
            <label for="jsonInput" class="form-label">Request Data (JSON)</label>
            <textarea class="form-control font-monospace" id="jsonInput" name="jsonInput" rows="5" placeholder='{"key": "value"}'>{}</textarea>
            <div class="form-text">Enter your request data as JSON</div>
        </div>
HTML;
    }

    $appNameHtml = htmlspecialchars($appName);
    $descriptionHtml = htmlspecialchars($description);
    $apiKeyJs = $apiKey ? "'" . addslashes($apiKey) . "'" : 'null';
    $brandingUrl = SiteConfig::getBaseUrl();

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{$appNameHtml}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
        }
        * { -webkit-tap-highlight-color: transparent; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            min-height: -webkit-fill-available;
            padding: 16px;
        }
        html { height: -webkit-fill-available; }
        .app-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 500px;
            margin: 0 auto;
        }
        .app-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8b5cf6 100%);
            color: white;
            padding: 20px;
        }
        .app-header h1 {
            font-size: 1.25rem;
            margin: 0;
            font-weight: 600;
        }
        .app-header p {
            margin: 6px 0 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }
        .app-body { padding: 20px; }
        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            padding: 14px 24px;
            font-weight: 500;
            font-size: 1rem;
        }
        .btn-primary:hover, .btn-primary:active {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        .form-control, .form-check-input {
            font-size: 16px; /* Prevents iOS zoom */
            padding: 12px;
        }
        .form-control:focus, .form-check-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }
        .form-label { font-weight: 500; }
        .form-text { font-size: 0.8rem; }
        #resultArea { display: none; margin-top: 20px; }
        #resultArea.show { display: block; }
        .result-success {
            background: #ecfdf5;
            border: 1px solid #10b981;
            border-radius: 12px;
            padding: 16px;
        }
        .result-error {
            background: #fef2f2;
            border: 1px solid #ef4444;
            border-radius: 12px;
            padding: 16px;
        }
        .result-pending {
            background: #fffbeb;
            border: 1px solid #f59e0b;
            border-radius: 12px;
            padding: 16px;
        }
        .spinner-border { width: 1rem; height: 1rem; }
        pre {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.8rem;
            max-height: 200px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .powered-by {
            text-align: center;
            padding: 16px;
            color: rgba(255,255,255,0.8);
            font-size: 0.8rem;
        }
        .powered-by a { color: white; }
        @media (max-width: 576px) {
            body { padding: 12px; }
            .app-header { padding: 16px; }
            .app-body { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="app-card">
        <div class="app-header">
            <h1><i class="bi bi-app-indicator me-2"></i>{$appNameHtml}</h1>
            <p>{$descriptionHtml}</p>
        </div>
        <div class="app-body">
            <form id="appForm" autocomplete="off">
                {$fieldsHtml}
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span class="btn-text"><i class="bi bi-play-fill me-2"></i>Run</span>
                        <span class="btn-loading d-none"><span class="spinner-border me-2"></span>Processing...</span>
                    </button>
                </div>
            </form>

            <div id="resultArea">
                <h6 class="mb-3"><i class="bi bi-terminal me-2"></i>Result</h6>
                <div id="resultContent"></div>
            </div>
        </div>
    </div>
    <div class="powered-by">
        Powered by <a href="{$brandingUrl}" target="_blank">MyCTOBot</a>
    </div>

    <script>
        const form = document.getElementById('appForm');
        const submitBtn = document.getElementById('submitBtn');
        const resultArea = document.getElementById('resultArea');
        const resultContent = document.getElementById('resultContent');
        const apiKey = {$apiKeyJs};

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.querySelector('.btn-text').classList.add('d-none');
            submitBtn.querySelector('.btn-loading').classList.remove('d-none');
            resultArea.classList.remove('show');

            let data = {};
            const jsonInput = document.getElementById('jsonInput');

            if (jsonInput) {
                try {
                    data = JSON.parse(jsonInput.value || '{}');
                } catch (err) {
                    showResult('error', 'Invalid JSON: ' + err.message);
                    resetButton();
                    return;
                }
            } else {
                const formData = new FormData(form);
                for (let [key, value] of formData.entries()) {
                    const input = document.getElementById(key);
                    if (input && input.type === 'checkbox') {
                        data[key] = input.checked;
                    } else if (input && input.type === 'number') {
                        data[key] = value ? Number(value) : null;
                    } else {
                        data[key] = value;
                    }
                }
            }

            try {
                const headers = { 'Content-Type': 'application/json' };
                if (apiKey) headers['X-API-Key'] = apiKey;

                const response = await fetch('/run', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    showResult('success', result);
                } else if (result.error) {
                    showResult('error', result);
                } else {
                    showResult('pending', result);
                }
            } catch (err) {
                showResult('error', { error: 'Request failed', message: err.message });
            }

            resetButton();
        });

        function showResult(type, data) {
            const icons = {
                success: '<i class="bi bi-check-circle-fill text-success me-2"></i>',
                error: '<i class="bi bi-x-circle-fill text-danger me-2"></i>',
                pending: '<i class="bi bi-hourglass-split text-warning me-2"></i>'
            };
            let html = '<div class="result-' + type + '">';

            if (typeof data === 'string') {
                html += icons[type] + escapeHtml(data);
            } else {
                const title = data.success ? 'Success' : (data.error || 'Result');
                html += '<strong>' + icons[type] + escapeHtml(title) + '</strong>';
                if (data.message) html += '<p class="mb-2 mt-2">' + escapeHtml(data.message) + '</p>';
                if (data.run_id) html += '<p class="mb-2"><small class="text-muted">Run ID: ' + data.run_id + '</small></p>';
                if (data.output !== undefined && data.output !== null && data.output !== true) {
                    html += '<pre>' + escapeHtml(JSON.stringify(data.output, null, 2)) + '</pre>';
                }
                if (data.missing_fields) html += '<p class="mb-0"><strong>Missing:</strong> ' + data.missing_fields.join(', ') + '</p>';
            }

            html += '</div>';
            resultContent.innerHTML = html;
            resultArea.classList.add('show');
            resultArea.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function resetButton() {
            submitBtn.disabled = false;
            submitBtn.querySelector('.btn-text').classList.remove('d-none');
            submitBtn.querySelector('.btn-loading').classList.add('d-none');
        }

        function escapeHtml(text) {
            if (typeof text !== 'string') return String(text);
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
HTML;
}
PHP;
    }

    /**
     * Generate config.php content
     */
    public function generateConfig($app): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/config.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Config template not found');
        }

        $routes = json_decode($app->routes_json ?: '[]', true) ?: [];
        $mcpTools = json_decode($app->mcp_tools_json ?: '[]', true) ?: [];

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{HOST}}'] = '127.0.0.1';
        $replacements['{{PORT}}'] = (string)($app->port ?: 9600);
        $replacements['{{WORKERS}}'] = '2';
        $replacements['{{APP_ID}}'] = (string)$app->id;
        $replacements['{{AUTH_TYPE}}'] = $app->auth_type ?: 'none';
        $replacements['{{API_KEY}}'] = $app->api_key ?: '';
        $replacements['{{PIPELINE_ID}}'] = $app->pipelines_id ? (string)$app->pipelines_id : 'null';
        $replacements['{{APP_TYPE}}'] = $app->app_type ?: 'pipeline';
        $replacements['{{DEBUG}}'] = 'true';  // TODO: Make configurable
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $replacements['{{CORS_ORIGIN}}'] = addslashes($configJson['cors_origin'] ?? '*');
        $replacements['{{ROUTES_JSON}}'] = var_export($routes, true);
        $replacements['{{EXPOSE_MCP}}'] = $app->expose_mcp ? 'true' : 'false';
        $replacements['{{MCP_TOOLS_JSON}}'] = var_export($mcpTools, true);

        return strtr($template, $replacements);
    }

    /**
     * Generate bootstrap.php content
     */
    public function generateBootstrap($app): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/bootstrap.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Bootstrap template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        return strtr($template, $replacements);
    }

    /**
     * Get common template replacements
     */
    private function getCommonReplacements($app): array
    {
        return [
            '{{APP_NAME}}' => $app->name,
            '{{APP_SLUG}}' => $app->slug,
            '{{WORKSPACE}}' => $app->workspace,
            '{{GENERATED_AT}}' => date('Y-m-d H:i:s'),
            '{{HEALTH_PATH}}' => $app->health_check_path ?: '/health',
            '{{MYCTOBOT_ROOT}}' => self::getRootPath(),
        ];
    }

    /**
     * Generate authentication handler code
     */
    public function generateAuthHandler(string $authType, ?string $apiKey): string
    {
        switch ($authType) {
            case 'none':
                return '// No authentication required';

            case 'api_key':
                $escapedKey = addslashes($apiKey ?? '');
                return <<<PHP
    // API Key Authentication
    \$providedKey = getApiKey(\$request);
    \$expectedKey = '{$escapedKey}';

    if (empty(\$expectedKey)) {
        // No API key configured - allow access
    } elseif (\$providedKey !== \$expectedKey) {
        \$logger->warning('Invalid API key', ['ip' => \$ip, 'path' => \$path]);
        \$response->status(401);
        \$response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => 'Invalid or missing API key. Provide via X-API-Key header or api_key query parameter.',
        ]));
        return;
    }
PHP;

            case 'bearer':
                return <<<'PHP'
    // Bearer Token Authentication
    $token = getBearerToken($request);
    if (empty($token)) {
        $logger->warning('Missing bearer token', ['ip' => $ip, 'path' => $path]);
        $response->status(401);
        $response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => 'Bearer token required.',
        ]));
        return;
    }

    // Validate token against apitokens table
    $tokenBean = \app\Bean::findOne('apitokens', ' token = ? AND is_active = 1 ', [$token]);
    if (!$tokenBean) {
        $logger->warning('Invalid bearer token', ['ip' => $ip, 'path' => $path]);
        $response->status(401);
        $response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => 'Invalid bearer token.',
        ]));
        return;
    }
PHP;

            default:
                return '// Unknown auth type - no authentication';
        }
    }

    /**
     * Generate route handlers
     */
    public function generateRouteHandlers($app): string
    {
        $handlers = [];

        // Root endpoint - app info
        $handlers[] = $this->generateRootHandler($app);

        // Pipeline execution endpoint (if pipeline-backed)
        if ($app->app_type === 'pipeline' && $app->pipelines_id) {
            $handlers[] = $this->generatePipelineHandler($app);
        }

        // Custom routes from routes_json
        $customRoutes = json_decode($app->routes_json ?: '[]', true) ?: [];
        foreach ($customRoutes as $route) {
            if (!empty($route['path']) && !empty($route['handler'])) {
                $handlers[] = $this->generateCustomRouteHandler($route);
            }
        }

        // MCP endpoint (if exposed)
        if ($app->expose_mcp) {
            $handlers[] = $this->generateMcpHandler($app);
        }

        // Admin dashboard (always available, protected by API key)
        $handlers[] = $this->generateAdminHandler($app);

        return implode("\n\n", $handlers);
    }

    /**
     * Generate root endpoint handler - SPA form UI
     */
    private function generateRootHandler($app): string
    {
        $name = addslashes($app->name);
        $slug = addslashes($app->slug);
        $description = addslashes($app->description ?: '');
        $pipelineId = $app->pipelines_id ? (int)$app->pipelines_id : 0;

        return <<<PHP
        // Root endpoint - SPA form UI
        if (\$path === '/' && \$method === 'GET') {
            // Check if JSON requested (API mode)
            \$acceptHeader = \$request->header['accept'] ?? '';
            if (strpos(\$acceptHeader, 'application/json') !== false) {
                \$response->end(json_encode([
                    'app' => '{$slug}',
                    'name' => '{$name}',
                    'description' => '{$description}',
                    'endpoints' => [
                        'GET /' => 'App form UI (or JSON with Accept: application/json)',
                        'GET /health' => 'Health check',
                        'POST /run' => 'Execute pipeline',
                    ],
                ]));
                return;
            }

            // Load pipeline to get input schema
            \$formFields = [];
            \$pipelineId = {$pipelineId};
            if (\$pipelineId) {
                \$pipeline = \\app\\Bean::load('pipelines', \$pipelineId);
                if (\$pipeline && \$pipeline->input_schema_json) {
                    \$schema = json_decode(\$pipeline->input_schema_json, true) ?: [];
                    \$properties = \$schema['properties'] ?? [];
                    \$required = \$schema['required'] ?? [];

                    foreach (\$properties as \$fieldName => \$fieldDef) {
                        \$formFields[] = [
                            'name' => \$fieldName,
                            'type' => \$fieldDef['type'] ?? 'string',
                            'description' => \$fieldDef['description'] ?? '',
                            'required' => in_array(\$fieldName, \$required),
                            'default' => \$fieldDef['default'] ?? '',
                        ];
                    }
                }
            }

            // Render HTML SPA
            \$response->header('Content-Type', 'text/html; charset=utf-8');
            \$html = renderFormHtml('{$name}', '{$slug}', '{$description}', \$formFields, \$config['api_key'] ?? null);
            \$response->end(\$html);
            return;
        }
PHP;
    }

    /**
     * Generate pipeline execution handler
     *
     * Uses HTTP calls to the Pipeline API instead of embedding PipelineExecutor.
     * This keeps the tenant app as a thin HTTP proxy/router.
     */
    private function generatePipelineHandler($app): string
    {
        $pipelineId = (int)$app->pipelines_id;
        $slug = addslashes($app->slug);
        $workspace = addslashes($app->workspace);

        // Get the pipeline slug for the API call
        $pipeline = Bean::load('pipelines', $pipelineId);
        $pipelineSlug = $pipeline ? addslashes($pipeline->slug) : '';

        // Get the API token for authenticating with the pipeline API
        $apiToken = '';
        if ($app->apikeys_id) {
            $apiKey = Bean::load('apikeys', $app->apikeys_id);
            if ($apiKey && $apiKey->token) {
                $apiToken = addslashes($apiKey->token);
            }
        }

        return <<<PHP
        // Pipeline execution endpoint - proxies to Pipeline API
        if (\$path === '/run' && \$method === 'POST') {
            \$body = parseJsonBody(\$request);

            // Validate JSON (OpenSwoole uses getContent(), Swoole uses rawContent())
            \$rawBody = method_exists(\$request, 'getContent') ? \$request->getContent() : \$request->rawContent();
            if (\$body === null && !empty(\$rawBody)) {
                \$response->status(400);
                \$response->end(json_encode([
                    'error' => 'Invalid JSON',
                    'message' => 'Request body must be valid JSON',
                ]));
                return;
            }

            // Add app metadata to the request body (no underscore prefix - matches pipeline context vars)
            \$body = \$body ?: [];
            \$body['app_slug'] = '{$slug}';
            \$body['source_ip'] = \$ip;
            \$body['user_agent'] = \$request->header['user-agent'] ?? '';

            // Make HTTP request to the Pipeline API
            \$pipelineSlug = '{$pipelineSlug}';
            \$apiToken = '{$apiToken}';
            \$pipelineApiUrl = "https://{$workspace}.myctobot.ai/api/pipelines/{\$pipelineSlug}/run?sync=true";

            \$logger->info('Proxying to Pipeline API', [
                'pipeline_slug' => \$pipelineSlug,
                'api_url' => \$pipelineApiUrl,
                'input_keys' => array_keys(\$body),
            ]);

            try {
                // Use cURL for the HTTP request
                \$ch = curl_init(\$pipelineApiUrl);
                curl_setopt_array(\$ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(\$body),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . \$apiToken,
                        'X-Workspace: {$workspace}',
                    ],
                    CURLOPT_TIMEOUT => 300, // 5 minute timeout for pipeline execution
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                \$apiResponse = curl_exec(\$ch);
                \$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
                \$curlError = curl_error(\$ch);
                curl_close(\$ch);

                if (\$curlError) {
                    \$logger->error('Pipeline API request failed', [
                        'error' => \$curlError,
                        'pipeline_slug' => \$pipelineSlug,
                    ]);
                    \$response->status(502);
                    \$response->end(json_encode([
                        'error' => 'Pipeline API Error',
                        'message' => 'Failed to connect to pipeline service: ' . \$curlError,
                    ]));
                    return;
                }

                // Parse the API response
                \$result = json_decode(\$apiResponse, true);

                if (\$httpCode >= 400) {
                    \$logger->warning('Pipeline API returned error', [
                        'http_code' => \$httpCode,
                        'response' => \$result,
                    ]);
                    \$response->status(\$httpCode);
                    \$response->end(\$apiResponse);
                    return;
                }

                // Forward the successful response
                \$logger->info('Pipeline API response', [
                    'http_code' => \$httpCode,
                    'success' => \$result['success'] ?? false,
                    'run_id' => \$result['data']['run_id'] ?? null,
                ]);

                \$response->end(\$apiResponse);
                return;

            } catch (\\Throwable \$e) {
                \$logger->error('Pipeline proxy failed', [
                    'error' => \$e->getMessage(),
                    'pipeline_slug' => \$pipelineSlug,
                ]);

                \$response->status(500);
                \$response->end(json_encode([
                    'success' => false,
                    'error' => 'Pipeline Error',
                    'message' => \$e->getMessage(),
                ]));
                return;
            }
        }
PHP;
    }

    /**
     * Generate custom route handler
     */
    private function generateCustomRouteHandler(array $route): string
    {
        $path = addslashes($route['path']);
        $methods = is_array($route['methods'] ?? null)
            ? $route['methods']
            : [$route['method'] ?? 'GET'];
        $methodsStr = "'" . implode("', '", $methods) . "'";
        $handler = $route['handler'];

        return <<<PHP
        // Custom route: {$path}
        if (\$path === '{$path}' && in_array(\$method, [{$methodsStr}])) {
            {$handler}
            return;
        }
PHP;
    }

    /**
     * Generate MCP endpoint handler
     */
    private function generateMcpHandler($app): string
    {
        return <<<'PHP'
        // MCP JSON-RPC endpoint
        if ($path === '/mcp' && $method === 'POST') {
            $body = parseJsonBody($request);

            if (!$body || !isset($body['jsonrpc']) || $body['jsonrpc'] !== '2.0') {
                $response->end(json_encode([
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => -32600,
                        'message' => 'Invalid Request',
                    ],
                    'id' => $body['id'] ?? null,
                ]));
                return;
            }

            $rpcMethod = $body['method'] ?? '';
            $rpcParams = $body['params'] ?? [];
            $rpcId = $body['id'] ?? null;

            // Handle MCP methods
            switch ($rpcMethod) {
                case 'initialize':
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'result' => [
                            'protocolVersion' => '2024-11-05',
                            'serverInfo' => [
                                'name' => $config['app_name'],
                                'version' => '1.0.0',
                            ],
                            'capabilities' => [
                                'tools' => new \stdClass(),
                            ],
                        ],
                        'id' => $rpcId,
                    ]));
                    return;

                case 'tools/list':
                    $tools = $config['mcp_tools'] ?? [];
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'result' => [
                            'tools' => $tools,
                        ],
                        'id' => $rpcId,
                    ]));
                    return;

                case 'tools/call':
                    // TODO: Implement tool execution
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'error' => [
                            'code' => -32601,
                            'message' => 'Tool execution not implemented',
                        ],
                        'id' => $rpcId,
                    ]));
                    return;

                default:
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'error' => [
                            'code' => -32601,
                            'message' => 'Method not found',
                        ],
                        'id' => $rpcId,
                    ]));
                    return;
            }
        }
PHP;
    }

    /**
     * Generate admin dashboard handler
     */
    private function generateAdminHandler($app): string
    {
        $name = addslashes($app->name);
        $slug = addslashes($app->slug);
        $workspace = addslashes($app->workspace);
        $apiKey = addslashes($app->api_key ?: '');

        return <<<PHP
        // Admin dashboard - view datastore contents (requires API key if configured)
        if (strpos(\$path, '/admin') === 0 && \$method === 'GET') {
            // Get base path for URL generation (supports proxy)
            \$baseUrl = \$request->header['x-forwarded-prefix'] ?? '';

            \$expectedKey = '{$apiKey}';

            // Only require auth if API key is configured
            if (!empty(\$expectedKey)) {
                \$providedKey = getApiKey(\$request);
                \$queryKey = \$request->get['api_key'] ?? null;

                // Accept key from either header or query string
                if (\$providedKey !== \$expectedKey && \$queryKey !== \$expectedKey) {
                    \$response->status(401);
                    \$response->header('Content-Type', 'text/html');
                    \$response->end('
                        <!DOCTYPE html>
                        <html><head><title>Admin Login</title>
                        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                        </head><body class="bg-light">
                        <div class="container mt-5" style="max-width: 400px;">
                            <div class="card shadow">
                                <div class="card-header bg-primary text-white"><h5 class="mb-0">Admin Login</h5></div>
                                <div class="card-body">
                                    <form method="get" action="/admin">
                                        <div class="mb-3">
                                            <label class="form-label">API Key</label>
                                            <input type="password" name="api_key" class="form-control" required autofocus>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100">Login</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        </body></html>
                    ');
                    return;
                }
            }

            // Parse path for table selection
            \$pathParts = explode('/', trim(\$path, '/'));
            \$table = \$pathParts[1] ?? null;
            \$recordId = \$pathParts[2] ?? null;

            // Load datastore
            require_once '{$this->getRootPath()}/services/AppDatastore.php';
            try {
                \$datastore = \\app\\services\\AppDatastore::forApp('{$workspace}', '{$slug}');
            } catch (\\Exception \$e) {
                \$response->header('Content-Type', 'text/html');
                \$response->end('<h3>No datastore configured for this app</h3>');
                return;
            }

            // Get list of tables
            \$tables = \$datastore->raw("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            // Build HTML
            \$apiKeyParam = urlencode(\$request->get['api_key'] ?? '');
            \$html = '<!DOCTYPE html><html><head><title>Admin - {$name}</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
                <style>
                    .table-responsive { max-height: 70vh; overflow: auto; }
                    .table th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
                    pre { white-space: pre-wrap; word-break: break-word; max-width: 300px; }
                    .badge { font-size: 0.75rem; }
                </style>
            </head><body class="bg-light">
                <nav class="navbar navbar-dark bg-primary mb-4">
                    <div class="container-fluid">
                        <a class="navbar-brand" href="' . \$baseUrl . '/admin?api_key=' . \$apiKeyParam . '"><i class="bi bi-database me-2"></i>{$name} Admin</a>
                        <a href="' . \$baseUrl . '/" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to App</a>
                    </div>
                </nav>
                <div class="container-fluid">';

            // Sidebar with tables
            \$html .= '<div class="row"><div class="col-md-2">
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-table me-2"></i>Tables</div>
                    <div class="list-group list-group-flush">';

            foreach (\$tables as \$t) {
                \$tName = \$t['name'];
                \$count = \$datastore->raw("SELECT COUNT(*) as cnt FROM {\$tName}")[0]['cnt'] ?? 0;
                \$active = (\$table === \$tName) ? 'active' : '';
                \$html .= "<a href='{\$baseUrl}/admin/{\$tName}?api_key={\$apiKeyParam}' class='list-group-item list-group-item-action d-flex justify-content-between {\$active}'>
                    {\$tName} <span class='badge bg-secondary'>{\$count}</span></a>";
            }

            \$html .= '</div></div></div>';

            // Main content
            \$html .= '<div class="col-md-10">';

            if (\$table) {
                // Show table data
                \$rows = \$datastore->raw("SELECT * FROM {\$table} ORDER BY id DESC LIMIT 100");
                \$html .= "<div class='card'><div class='card-header d-flex justify-content-between align-items-center'>
                    <span><i class='bi bi-table me-2'></i>{\$table}</span>
                    <span class='badge bg-primary'>" . count(\$rows) . " rows (limit 100)</span>
                </div>";

                if (!empty(\$rows)) {
                    \$html .= '<div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-hover mb-0">';
                    \$html .= '<thead><tr>';
                    foreach (array_keys(\$rows[0]) as \$col) {
                        \$html .= "<th>{\$col}</th>";
                    }
                    \$html .= '</tr></thead><tbody>';

                    foreach (\$rows as \$row) {
                        \$html .= '<tr>';
                        foreach (\$row as \$col => \$val) {
                            if (\$val === null) {
                                \$html .= '<td><span class="text-muted">null</span></td>';
                            } elseif (is_string(\$val) && strlen(\$val) > 100) {
                                \$preview = htmlspecialchars(substr(\$val, 0, 100)) . '...';
                                \$html .= "<td><span title='" . htmlspecialchars(\$val) . "'>{\$preview}</span></td>";
                            } elseif (is_string(\$val) && (\$val[0] ?? '') === '{') {
                                \$html .= '<td><pre>' . htmlspecialchars(\$val) . '</pre></td>';
                            } else {
                                \$html .= '<td>' . htmlspecialchars((string) \$val) . '</td>';
                            }
                        }
                        \$html .= '</tr>';
                    }

                    \$html .= '</tbody></table></div></div>';
                } else {
                    \$html .= '<div class="card-body text-muted">No records found</div>';
                }

                \$html .= '</div>';
            } else {
                \$html .= '<div class="card"><div class="card-body text-center text-muted py-5">
                    <i class="bi bi-arrow-left-circle" style="font-size: 3rem;"></i>
                    <p class="mt-3">Select a table from the sidebar to view data</p>
                </div></div>';
            }

            \$html .= '</div></div></div></body></html>';

            \$response->header('Content-Type', 'text/html; charset=utf-8');
            \$response->end(\$html);
            return;
        }
PHP;
    }

    /**
     * Get the app directory path
     */
    public function getAppDir(string $workspace, string $slug): string
    {
        return self::getStorageDir() . "/{$workspace}/{$slug}";
    }

    /**
     * Check if app has been built
     */
    public function isBuilt(string $workspace, string $slug): bool
    {
        $appDir = $this->getAppDir($workspace, $slug);
        return file_exists($appDir . '/server.php')
            || file_exists($appDir . '/server.mjs')
            || file_exists($appDir . '/index.html');
    }

    /**
     * Clean up app directory
     */
    public function cleanup(string $workspace, string $slug): bool
    {
        $appDir = $this->getAppDir($workspace, $slug);
        if (!is_dir($appDir)) {
            return true;
        }

        // Recursively delete directory
        $this->recursiveDelete($appDir);
        return !is_dir($appDir);
    }

    /**
     * Recursively delete a directory
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
