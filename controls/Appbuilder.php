<?php
/**
 * Appbuilder Controller
 *
 * Block-based visual app builder for creating Bootstrap 5 applications
 * wired to MyCTOBot pipelines. Apps deploy as OpenSwoole tenant apps.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\AppBlockRegistry;
use \app\services\AppBlockRenderer;
use \app\services\AppSecurityManager;
use \app\services\TenantAppBuilder;
use \app\services\TenantAppService;

class Appbuilder extends BaseControls\Control
{
    /**
     * Check if app is a deployment target (non-local)
     */
    private function isDeploymentApp($app): bool
    {
        $target = $app->deployment_target ?? 'local';
        return !in_array($target, ['local', '']);
    }

    /**
     * Main builder view
     * GET /appbuilder/{app_id}
     */
    public function index()
    {
        if (!$this->requireLogin()) return;

        $appId = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$appId) {
            $this->flash('error', 'App ID required');
            Flight::redirect('/apps');
            return;
        }

        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Get screens for this app
        $screens = Bean::find('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC, id ASC ', [$appId]);

        // If no screens, create a default one
        if (empty($screens)) {
            $screen = Bean::dispense('appscreens');
            $screen->tenantapps_id = $appId;
            $screen->name = 'Home';
            $screen->slug = 'home';
            $screen->route_path = '/';
            $screen->is_default = 1;
            $screen->sort_order = 0;
            $screen->blocks_json = '[]';
            Bean::store($screen);
            $screens = [$screen];
        }

        $screenList = [];
        foreach ($screens as $s) {
            $screenList[] = $this->screenToArray($s);
        }

        // Get available pipelines for data binding (enriched with output schemas)
        $pipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');
        $pipelineList = [];
        foreach ($pipelines as $p) {
            $pData = [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description ?? '',
            ];

            // Fetch output schema from last completed run
            $lastRun = Bean::findOne('pipelineruns',
                ' pipelines_id = ? AND status = ? ORDER BY completed_at DESC LIMIT 1 ',
                [(int)$p->id, 'completed']
            );
            if ($lastRun && $lastRun->output_json) {
                $runOutput = json_decode($lastRun->output_json, true);
                if (is_array($runOutput)) {
                    $schema = [];
                    foreach ($runOutput as $key => $value) {
                        if (is_array($value) && !empty($value) && isset($value[0]) && is_array($value[0])) {
                            $schema[$key] = 'array[' . implode(',', array_keys($value[0])) . ']';
                        } else {
                            $schema[$key] = gettype($value);
                        }
                    }
                    $pData['output_schema'] = $schema;
                }
            }

            $pipelineList[] = $pData;
        }

        // Parse app security config
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $security = $configJson['security'] ?? AppSecurityManager::getDefaultConfig();

        $this->viewData['title'] = 'App Builder: ' . $app->name;
        $this->viewData['hideSidebar'] = true;
        $this->viewData['hideFooter'] = true;
        $this->viewData['app'] = $app;
        $this->viewData['screens'] = $screenList;
        $this->viewData['pipelines'] = $pipelineList;
        $this->viewData['blockTypes'] = AppBlockRegistry::getGrouped();
        $this->viewData['allBlockTypes'] = AppBlockRegistry::getAll();
        $this->viewData['actions'] = AppBlockRegistry::getActions();
        $this->viewData['authTypes'] = AppSecurityManager::AUTH_TYPES;
        $this->viewData['security'] = $security;

        // For deployment apps (docker/shopify/etc), set builderConfig for the shared view
        if ($this->isDeploymentApp($app)) {
            $builderConfig = [
                'saveScreenUrl'   => "/appbuilder/screen/{$appId}",
                'deleteScreenUrl' => "/appbuilder/deletescreen",
                'saveSecurityUrl' => null,
                'previewUrl'      => "/appbuilder/preview/{$appId}",
                'deployUrl'       => null,
                'backUrl'         => "/apps/screens/{$appId}",
                'backLabel'       => "Screens",
                'channelName'     => "appbuilder_preview_{$appId}",
                'showDeploy'      => false,
                'showSecurity'    => false,
            ];

            // App context for Page Assistant
            $boundPipelines = Bean::find('apppipelines',
                ' tenantapps_id = ? AND is_active = 1 ', [$appId]);
            $pipelineAliases = [];
            foreach ($boundPipelines as $bp) {
                $pipelineAliases[] = [
                    'alias' => $bp->alias,
                    'pipelines_id' => (int)$bp->pipelines_id,
                    'description' => $bp->description ?? '',
                ];
            }

            $builderConfig['dappContext'] = json_encode([
                'app_name' => $app->name,
                'slug' => $app->slug,
                'is_hosted' => (bool)$app->is_hosted,
                'hosted_url' => $app->is_hosted ? '/dapp/' . $app->slug : null,
                'template_slug' => $app->template_slug ?? null,
                'bound_pipelines' => $pipelineAliases,
            ]);

            $this->viewData['builderConfig'] = $builderConfig;
            $this->viewData['authTypes'] = [];
            $this->viewData['security'] = [];
        }

        $this->render('appbuilder/index', $this->viewData);
    }

    /**
     * List screens for an app (JSON)
     * GET /appbuilder/screens/{app_id}
     */
    public function screens()
    {
        if (!$this->requireLogin()) return;

        $appId = (int) ($this->opId() ?? $this->getParam('app_id') ?? 0);
        if (!$appId) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        $screens = Bean::find('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC, id ASC ', [$appId]);
        $result = [];
        foreach ($screens as $s) {
            $result[] = $this->screenToArray($s);
        }

        Flight::jsonSuccess(['screens' => $result]);
    }

    /**
     * Create or update a screen (JSON)
     * POST /appbuilder/screen/{app_id}
     */
    public function screen()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $appId = (int) ($this->opId() ?? $this->getParam('app_id') ?? 0);
        if (!$appId) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        $input = $this->getJsonInput();
        $screenId = $input['id'] ?? null;

        if ($screenId) {
            // Update existing
            $screen = Bean::load('appscreens', $screenId);
            if (!$screen || !$screen->id || (int)$screen->tenantapps_id !== $appId) {
                Flight::jsonError('Screen not found', 404);
                return;
            }
        } else {
            // Create new
            $screen = Bean::dispense('appscreens');
            $screen->tenantapps_id = $appId;
        }

        if (isset($input['name'])) $screen->name = trim($input['name']);
        if (isset($input['slug'])) $screen->slug = trim($input['slug']);
        if (isset($input['route_path'])) $screen->route_path = trim($input['route_path']);
        if (isset($input['layout_type'])) $screen->layout_type = $input['layout_type'];
        if (isset($input['blocks'])) $screen->blocks_json = json_encode($input['blocks']);
        if (isset($input['page_config'])) $screen->page_config_json = json_encode($input['page_config']);
        if (isset($input['is_default'])) $screen->is_default = (int) $input['is_default'];
        if (isset($input['sort_order'])) $screen->sort_order = (int) $input['sort_order'];

        try {
            Bean::store($screen);

            // For deployment apps, auto-render blocks to html_cache for hosted serving
            $app = Bean::load('tenantapps', $appId);
            if ($app && $this->isDeploymentApp($app) && isset($input['blocks'])) {
                $this->renderToHtmlCache($screen, $app);
                $app = Bean::load('tenantapps', $appId); // Reload in case hosted was auto-enabled
            }

            $responseData = $this->screenToArray($screen);
            if ($app && $app->is_hosted) {
                $responseData['hosted_url'] = '/dapp/' . $app->slug;
            }
            Flight::jsonSuccess($responseData, $screenId ? 'Screen updated' : 'Screen created');
        } catch (\Exception $e) {
            Flight::jsonError('Failed to save screen: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a screen
     * POST /appbuilder/deletescreen/{screen_id}
     */
    public function deletescreen()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $screenId = (int) ($this->opId() ?? $this->getParam('screen_id') ?? 0);
        if (!$screenId) {
            Flight::jsonError('Screen ID required', 400);
            return;
        }

        $screen = Bean::load('appscreens', $screenId);
        if (!$screen || !$screen->id) {
            Flight::jsonError('Screen not found', 404);
            return;
        }

        // Verify the screen's app exists and belongs to this workspace
        $app = Bean::load('tenantapps', (int) $screen->tenantapps_id);
        if (!$app || !$app->id) {
            Flight::jsonError('Screen not found', 404);
            return;
        }

        try {
            Bean::trash($screen);
            Flight::jsonSuccess(null, 'Screen deleted');
        } catch (\Exception $e) {
            Flight::jsonError('Failed to delete screen: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reorder blocks within a screen or reorder screens
     * POST /appbuilder/reorder/{app_id}
     */
    public function reorder()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $appId = (int) ($this->opId() ?? $this->getParam('app_id') ?? 0);
        $input = $this->getJsonInput();

        if (isset($input['screen_id']) && isset($input['block_order'])) {
            // Reorder blocks within a screen
            $screen = Bean::load('appscreens', (int)$input['screen_id']);
            if (!$screen || !$screen->id || (int)$screen->tenantapps_id !== $appId) {
                Flight::jsonError('Screen not found', 404);
                return;
            }

            $screen->reorderBlocks($input['block_order']);
            Bean::store($screen);
            Flight::jsonSuccess(null, 'Blocks reordered');
        } elseif (isset($input['screen_order'])) {
            // Reorder screens
            foreach ($input['screen_order'] as $i => $sid) {
                $screen = Bean::load('appscreens', (int)$sid);
                if ($screen && $screen->id && (int)$screen->tenantapps_id === $appId) {
                    $screen->sort_order = $i;
                    Bean::store($screen);
                }
            }
            Flight::jsonSuccess(null, 'Screens reordered');
        } else {
            Flight::jsonError('Invalid reorder request', 400);
        }
    }

    /**
     * Preview the app (renders blocks in an iframe-friendly page)
     * GET /appbuilder/preview/{app_id}
     */
    public function preview()
    {
        if (!$this->requireLogin()) return;

        $appId = (int) ($this->opId() ?? $this->getParam('app_id') ?? 0);
        $screenId = (int) ($this->getParam('screen_id') ?? 0);
        $screenRoute = $this->opType() ?? '';

        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            Flight::jsonError('App not found', 404);
            return;
        }

        // Get screen by route_path, screen_id, or default
        $screen = null;
        if ($screenRoute) {
            $routePath = '/' . ltrim($screenRoute, '/');
            $screen = Bean::findOne('appscreens',
                ' tenantapps_id = ? AND route_path = ? ', [$appId, $routePath]);
        }
        if (!$screen && $screenId) {
            $screen = Bean::load('appscreens', $screenId);
        }
        if (!$screen || !$screen->id) {
            $screen = Bean::findOne('appscreens', ' tenantapps_id = ? AND is_default = 1 ', [$appId]);
            if (!$screen) {
                $screen = Bean::findOne('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC ', [$appId]);
            }
        }

        if (!$screen || !$screen->id) {
            echo '<div class="text-center text-muted py-5">No screens configured yet.</div>';
            return;
        }

        $renderer = new AppBlockRenderer();
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];

        // Get all screens for nav
        $screens = Bean::find('appscreens', ' tenantapps_id = ? ORDER BY sort_order ASC ', [$appId]);
        $screenList = [];
        foreach ($screens as $s) {
            $screenList[] = [
                'id' => (int) $s->id,
                'name' => $s->name ?: ($s->screen_title ?: 'Untitled'),
                'route_path' => $s->route_path,
                'slug' => $s->slug ?? '',
            ];
        }

        $appConfig = [
            'name' => $app->name,
            'theme_color' => $configJson['theme_color'] ?? '#6366f1',
        ];

        // Render the page blocks
        $screenData = [
            'blocks' => json_decode($screen->blocks_json ?: '[]', true) ?: [],
            'layout_type' => $screen->layout_type ?: 'single',
            'page_config' => json_decode($screen->page_config_json ?: '{}', true) ?: [],
        ];

        $blocksHtml = $renderer->renderPage($screenData, $appConfig);
        $runtimeJs = $renderer->getRuntimeJs('/appbuilder/pipeline');

        // Output a complete preview page
        $appName = h($app->name);
        $themeColor = h($appConfig['theme_color']);

        $navHtml = '';
        foreach ($screenList as $s) {
            $sName = h($s['name']);
            $route = ltrim($s['route_path'] ?? '', '/');
            $active = ($s['slug'] ?? '') === ($screen->slug ?? '') || ($s['route_path'] ?? '') === ($screen->route_path ?? '') ? ' active' : '';
            $href = $route ? "/appbuilder/preview/{$appId}/{$route}" : "/appbuilder/preview/{$appId}";
            $navHtml .= "<li class=\"nav-item\"><a class=\"nav-link{$active}\" href=\"{$href}\">{$sName}</a></li>";
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: {$appName}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root { --app-primary: {$themeColor}; }
        .navbar { background-color: var(--app-primary) !important; }
        .btn-primary { background-color: var(--app-primary); border-color: var(--app-primary); }
        .chat-messages { display: flex; flex-direction: column; }
        .chat-message { max-width: 80%; margin-bottom: 0.5rem; }
        .chat-message.user { align-self: flex-end; }
        .chat-message.assistant { align-self: flex-start; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">{$appName}</a>
            <ul class="navbar-nav me-auto">{$navHtml}</ul>
        </div>
    </nav>
    <div class="container">
        {$blocksHtml}
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    {$runtimeJs}
    // Auto-refresh when builder saves changes
    const _bc = new BroadcastChannel('appbuilder_preview_{$appId}');
    _bc.onmessage = () => window.location.reload();
    </script>
</body>
</html>
HTML;
    }

    /**
     * Pipeline proxy for preview mode (session-authenticated)
     * POST /appbuilder/pipeline
     * Body: { "pipeline": "slug", "input": {} }
     */
    public function pipeline()
    {
        if (!$this->requireLogin()) return;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $slug = $body['pipeline'] ?? '';
        $inputData = $body['input'] ?? [];

        if (empty($slug)) {
            Flight::jsonError('Pipeline slug required', 400);
            return;
        }

        $pipeline = Bean::findOne('pipelines', 'slug = ? AND is_active = 1', [$slug]);
        if (!$pipeline) {
            Flight::jsonError("Pipeline not found: {$slug}", 404);
            return;
        }

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = 'run-' . bin2hex(random_bytes(8));
        $run->pipelines_id = $pipeline->id;
        $run->member_id = $this->member->id;
        $run->trigger_source = 'preview';
        $run->status = 'pending';
        $run->context_json = json_encode($inputData);
        $run->created_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $workspace = $_SERVER['WORKSPACE'] ?? null;
        $result = \app\services\PipelineDispatcher::dispatch($run->id, $workspace, true);

        if ($result['success']) {
            $run = Bean::load('pipelineruns', (int) $run->id);
            $output = json_decode($run->output_json ?: '{}', true);

            // If output_json is empty, extract from last step run
            if (empty($output)) {
                $lastStep = Bean::findOne('pipelinestepruns',
                    'pipelineruns_id = ? AND status = ? ORDER BY id DESC',
                    [$run->id, 'success']
                );
                if ($lastStep) {
                    $stepOut = json_decode($lastStep->output_json ?: '{}', true);
                    $output = $stepOut['output'] ?? $stepOut;
                }
            }

            Flight::jsonSuccess($output);
        } else {
            Flight::jsonError('Pipeline execution failed: ' . ($result['error'] ?? 'Unknown'), 500);
        }
    }

    /**
     * Get block type registry (JSON)
     * GET /appbuilder/blocktypes
     */
    public function blocktypes()
    {
        if (!$this->requireLogin()) return;

        Flight::jsonSuccess([
            'categories' => AppBlockRegistry::CATEGORIES,
            'grouped' => AppBlockRegistry::getGrouped(),
            'actions' => AppBlockRegistry::getActions(),
            'auth_types' => AppSecurityManager::AUTH_TYPES,
        ]);
    }

    /**
     * Build and deploy the app
     * POST /appbuilder/deploy/{app_id}
     */
    public function deploy()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $appId = (int) ($this->opId() ?? $this->getParam('app_id') ?? 0);
        if (!$appId) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            Flight::jsonError('App not found', 404);
            return;
        }

        try {
            // Build the app
            $builder = new TenantAppBuilder();
            $appDir = $builder->build($appId);

            // Start or restart the app
            $appService = new TenantAppService(Flight::get('log'));
            if ($app->status === 'running') {
                $result = $appService->restart($appId);
            } else {
                $result = $appService->start($appId);
            }

            Flight::jsonSuccess([
                'app_dir' => $appDir,
                'status' => $result,
            ], 'App deployed successfully');
        } catch (\Exception $e) {
            Flight::jsonError('Deploy failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Save security config for the app
     * POST /appbuilder/savesecurity/{app_id}
     */
    public function savesecurity()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $appId = (int) ($this->opId() ?? $this->getParam('app_id') ?? 0);
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            Flight::jsonError('App not found', 404);
            return;
        }

        $input = $this->getJsonInput();
        $security = $input['security'] ?? [];

        // Validate
        $errors = AppSecurityManager::validateConfig($security);
        if (!empty($errors)) {
            Flight::jsonError('Validation errors: ' . implode(', ', $errors), 400);
            return;
        }

        // Save to app config_json
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $configJson['security'] = $security;
        $app->config_json = json_encode($configJson);

        try {
            Bean::store($app);
            Flight::jsonSuccess(null, 'Security config saved');
        } catch (\Exception $e) {
            Flight::jsonError('Failed to save: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Convert a screen bean to array, always decoding blocks_json
     */
    private function screenToArray($screen): array
    {
        return [
            'id' => (int) $screen->id,
            'tenantapps_id' => (int) $screen->tenantapps_id,
            'name' => $screen->name ?: ($screen->screen_title ?: 'Untitled'),
            'slug' => $screen->slug ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($screen->name ?: $screen->screen_title ?: 'untitled'))),
            'route_path' => $screen->route_path,
            'layout_type' => $screen->layout_type ?: 'single',
            'blocks' => json_decode($screen->blocks_json ?: '[]', true) ?: [],
            'page_config' => json_decode($screen->page_config_json ?: '{}', true) ?: [],
            'is_default' => (bool) $screen->is_default,
            'sort_order' => (int) $screen->sort_order,
        ];
    }

    // =========================================================================
    // DEPLOYMENT APP HELPERS (html_cache, auto-bind, etc.)
    // =========================================================================

    /**
     * Render blocks to html_cache for immediate hosted serving (deployment apps)
     */
    private function renderToHtmlCache($screen, $app): void
    {
        try {
            $blocks = json_decode($screen->blocks_json ?: '[]', true) ?: [];
            if (empty($blocks)) return;

            $renderer = new AppBlockRenderer();
            $configJson = json_decode($app->config_json ?: '{}', true) ?: [];

            $screenData = [
                'blocks' => $blocks,
                'layout_type' => $screen->layout_type ?: 'single',
                'page_config' => json_decode($screen->page_config_json ?: '{}', true) ?: [],
            ];
            $appConfig = [
                'name' => $app->name,
                'theme_color' => $configJson['theme_color'] ?? '#6366f1',
            ];

            $blocksHtml = $renderer->renderPage($screenData, $appConfig);
            $runtimeJs = $renderer->getRuntimeJs('/dapp/' . $app->slug . '/api/pipeline');

            // Build screen navigation for multi-screen apps
            $allScreens = Bean::find('appscreens',
                ' tenantapps_id = ? ORDER BY sort_order ASC ', [(int) $app->id]);
            $navHtml = '';
            if (count($allScreens) > 1) {
                $slug = $app->slug;
                foreach ($allScreens as $s) {
                    $sName = htmlspecialchars($s->name ?: $s->screen_title ?: 'Untitled', ENT_QUOTES, 'UTF-8');
                    $route = ltrim($s->route_path ?? '', '/');
                    $active = ((int)$s->id === (int)$screen->id) ? ' active' : '';
                    $href = $route && $route !== '/' ? "/dapp/{$slug}/{$route}" : "/dapp/{$slug}";
                    $navHtml .= "<li class=\"nav-item\"><a class=\"nav-link{$active}\" href=\"{$href}\">{$sName}</a></li>";
                }
            }

            $screen->html_cache = $this->buildFullPageHtml($blocksHtml, $runtimeJs, $app, $navHtml);
            $screen->html_cached_at = date('Y-m-d H:i:s');
            Bean::store($screen);

            // Auto-enable hosted mode on first block save
            if (!$app->is_hosted) {
                $app->is_hosted = 1;
                Bean::store($app);
            }

            // Auto-bind any pipelines referenced in blocks
            $this->autoBindPipelines($blocks, $app);
        } catch (\Exception $e) {
            $logger = Flight::get('log');
            $logger->warning('Failed to render blocks to html_cache', [
                'screen_id' => $screen->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-bind pipelines referenced in blocks to the app's apppipelines table.
     */
    private function autoBindPipelines(array $blocks, $app): void
    {
        $slugs = $this->extractPipelineSlugs($blocks);
        if (empty($slugs)) return;

        $appId = (int) $app->id;

        foreach ($slugs as $slug) {
            $pipeline = Bean::findOne('pipelines', ' slug = ? AND is_active = 1 ', [$slug]);
            if (!$pipeline) continue;

            $existing = Bean::findOne('apppipelines',
                ' tenantapps_id = ? AND pipelines_id = ? ', [$appId, (int)$pipeline->id]);
            if ($existing) continue;

            $binding = Bean::dispense('apppipelines');
            $binding->tenantapps_id = $appId;
            $binding->pipelines_id = (int) $pipeline->id;
            $binding->alias = $slug;
            $binding->is_active = 1;
            Bean::store($binding);
        }
    }

    /**
     * Recursively extract all pipeline slugs from blocks (including nested rows/tabs).
     */
    private function extractPipelineSlugs(array $blocks): array
    {
        $slugs = [];
        foreach ($blocks as $block) {
            if (!empty($block['data_binding']['pipeline_slug'])) {
                $slugs[] = $block['data_binding']['pipeline_slug'];
            }
            if (!empty($block['events'])) {
                foreach ($block['events'] as $event) {
                    if (is_array($event) && ($event['action'] ?? '') === 'run_pipeline' && !empty($event['pipeline_slug'])) {
                        $slugs[] = $event['pipeline_slug'];
                    }
                }
            }
            if (!empty($block['config']['columns'])) {
                foreach ($block['config']['columns'] as $col) {
                    $slugs = array_merge($slugs, $this->extractPipelineSlugs($col['blocks'] ?? []));
                }
            }
            if (!empty($block['config']['tabs'])) {
                foreach ($block['config']['tabs'] as $tab) {
                    $slugs = array_merge($slugs, $this->extractPipelineSlugs($tab['blocks'] ?? []));
                }
            }
        }
        return array_unique($slugs);
    }

    /**
     * Build a complete HTML page from rendered blocks + runtime JS
     */
    private function buildFullPageHtml(string $blocksHtml, string $runtimeJs, $app, string $navHtml = ''): string
    {
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        $appName = htmlspecialchars($app->name, ENT_QUOTES, 'UTF-8');
        $themeColor = htmlspecialchars($configJson['theme_color'] ?? '#6366f1', ENT_QUOTES, 'UTF-8');

        $navSection = $navHtml ? "<ul class=\"navbar-nav me-auto\">{$navHtml}</ul>" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root { --app-primary: {$themeColor}; }
        .navbar { background-color: var(--app-primary) !important; }
        .btn-primary { background-color: var(--app-primary); border-color: var(--app-primary); }
        .chat-messages { display: flex; flex-direction: column; }
        .chat-message { max-width: 80%; margin-bottom: 0.5rem; }
        .chat-message.user { align-self: flex-end; }
        .chat-message.assistant { align-self: flex-start; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">{$appName}</a>
            {$navSection}
        </div>
    </nav>
    <div class="container">
        {$blocksHtml}
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    {$runtimeJs}
    </script>
</body>
</html>
HTML;
    }
}
