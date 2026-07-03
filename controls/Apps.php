<?php
/**
 * Apps Controller
 *
 * Manages tenant apps - standalone HTTP server services that can expose
 * pipelines as endpoints or run custom logic. Also handles deployment-target
 * apps (docker, shopify, wasm) with screen management, pipeline binding,
 * build/release workflows, Shopify Liquid files, and app templates.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\TenantAppService;
use \app\services\TenantAppPortManager;
use \app\services\TenantAppBuilder;
use \app\services\DetachableAppBuilder;
use \app\services\StitchClient;
use \app\lib\AppTemplates\AppTemplateRegistry;

require_once __DIR__ . '/../lib/AppTemplates/AppTemplateInterface.php';
require_once __DIR__ . '/../lib/AppTemplates/AppTemplateRegistry.php';

class Apps extends BaseControls\Control
{
    private TenantAppService $appService;
    private DetachableAppBuilder $builder;

    /**
     * Auth types for apps
     */
    private const AUTH_TYPES = [
        'none' => [
            'label' => 'No Authentication',
            'description' => 'Public access, no authentication required',
            'icon' => 'bi-unlock',
        ],
        'api_key' => [
            'label' => 'API Key',
            'description' => 'Require X-API-Key header or api_key query parameter',
            'icon' => 'bi-key',
        ],
        'bearer' => [
            'label' => 'Bearer Token',
            'description' => 'Require Authorization: Bearer token from apitokens table',
            'icon' => 'bi-shield-lock',
        ],
    ];

    /**
     * App types
     */
    private const APP_TYPES = [
        'pipeline' => [
            'label' => 'Pipeline',
            'description' => 'Expose a pipeline as an HTTP endpoint',
            'icon' => 'bi-diagram-3',
        ],
        'builder' => [
            'label' => 'Builder',
            'description' => 'Block-based app with visual builder',
            'icon' => 'bi-bricks',
        ],
        'custom' => [
            'label' => 'Custom',
            'description' => 'Custom routes with inline handlers',
            'icon' => 'bi-code-slash',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->appService = new TenantAppService(Flight::get('log'));
        $this->builder = new DetachableAppBuilder(Flight::get('log'));
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * List all apps. Accepts optional ?target= filter (local, docker, shopify, wasm).
     */
    public function index()
    {
        if (!$this->requireLogin()) return;

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $target = $this->getParam('target', '');

        // Load local service apps from TenantAppService
        $serviceApps = $this->appService->listApps($workspace);

        // Ensure each service app has a deployment_target
        foreach ($serviceApps as &$sa) {
            $sa['deployment_target'] = $sa['deployment_target'] ?? 'local';
        }
        unset($sa);

        // Load deployment apps directly from tenantapps bean (those with non-local deployment_target)
        $deploymentApps = Bean::find(
            'tenantapps',
            " workspace = ? AND deployment_target IS NOT NULL AND deployment_target != 'local' ORDER BY name ASC ",
            [$workspace]
        );

        // Merge deployment apps into the list (avoid duplicates by ID)
        $existingIds = array_column($serviceApps, 'id');
        foreach ($deploymentApps as $dApp) {
            if (in_array((int)$dApp->id, $existingIds)) continue;

            $screenCount = Bean::count('appscreens', ' tenantapps_id = ? ', [$dApp->id]);
            $pipelineCount = Bean::count('apppipelines', ' tenantapps_id = ? AND is_active = 1 ', [$dApp->id]);

            $serviceApps[] = [
                'id' => (int) $dApp->id,
                'name' => $dApp->name,
                'slug' => $dApp->slug,
                'description' => $dApp->description,
                'deployment_target' => $dApp->deployment_target ?? 'docker',
                'current_version' => $dApp->current_version,
                'build_status' => $dApp->build_status,
                'is_hosted' => (int) $dApp->is_hosted,
                'template_slug' => $dApp->template_slug,
                'stitch_project_eid' => $dApp->stitch_project_eid,
                'git_repo_url' => $dApp->git_repo_url,
                'screen_count' => $screenCount,
                'pipeline_count' => $pipelineCount,
                'pipeline_id' => $dApp->pipelines_id ?? null,
                'status' => $dApp->status ?? 'stopped',
                'created_at' => $dApp->created_at,
            ];
        }

        // Apply target filter if specified
        if (!empty($target)) {
            $serviceApps = array_filter($serviceApps, function ($app) use ($target) {
                return ($app['deployment_target'] ?? 'local') === $target;
            });
            $serviceApps = array_values($serviceApps);
        }

        // Get pipeline names for display
        $pipelineIds = array_filter(array_column($serviceApps, 'pipeline_id'));
        $pipelineNames = [];
        if (!empty($pipelineIds)) {
            // Cast to int to prevent second-order SQLi via the IN() list (LOW).
            $pipelineIds = array_map('intval', $pipelineIds);
            $pipelines = Bean::find('pipelines', ' id IN (' . implode(',', $pipelineIds) . ') ');
            foreach ($pipelines as $p) {
                $pipelineNames[$p->id] = $p->name;
            }
        }

        // Add pipeline names to apps
        foreach ($serviceApps as &$app) {
            $app['pipeline_name'] = $pipelineNames[$app['pipeline_id'] ?? 0] ?? null;
        }
        unset($app);

        $this->viewData['title'] = 'Apps';
        $this->viewData['apps'] = $serviceApps;
        $this->viewData['authTypes'] = self::AUTH_TYPES;
        $this->viewData['appTypes'] = self::APP_TYPES;
        $this->viewData['target'] = $target;

        $this->render('apps/index', $this->viewData);
    }

    /**
     * Show create/edit form
     */
    public function form()
    {
        if (!$this->requireLogin()) return;

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        $app = null;

        if ($id > 0) {
            $app = Bean::load('tenantapps', $id);
            if (!$app || !$app->id) {
                $this->flash('error', 'App not found');
                Flight::redirect('/apps');
                return;
            }
        }

        // Get available pipelines
        $pipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');
        $pipelineList = [];
        foreach ($pipelines as $p) {
            $pipelineList[] = [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
            ];
        }

        // Get available API tokens for the current user (for pipeline auth)
        $apiTokens = Bean::find('apikeys', ' member_id = ? ORDER BY name ASC ', [$this->member->id]);
        $apiTokenList = [];
        foreach ($apiTokens as $token) {
            $apiTokenList[] = [
                'id' => $token->id,
                'name' => $token->name,
                'token' => $token->token,
                'is_active' => (bool) $token->is_active,
            ];
        }

        // Load Stitch connections for screen import
        $stitchConnections = Bean::find('connections', " connector_type IN ('stitch', 'google_stitch') ORDER BY connection_name ASC ");
        if (empty($stitchConnections)) {
            $stitchConnections = Bean::findAll('stitchconnections', ' ORDER BY id ASC ');
        }

        // Load Shopify connections for shopify deployment target
        $shopifyConnections = Bean::find('connections', " connector_type = 'shopify' ORDER BY connection_name ASC ");
        if (empty($shopifyConnections)) {
            $shopifyConnections = Bean::findAll('shopifyconnections', ' ORDER BY id ASC ');
        }

        $this->viewData['title'] = $app ? 'Edit App: ' . $app->name : 'Create App';
        $this->viewData['app'] = $app;
        $this->viewData['pipelines'] = $pipelineList;
        $this->viewData['apiTokens'] = $apiTokenList;
        $this->viewData['authTypes'] = self::AUTH_TYPES;
        $this->viewData['appTypes'] = self::APP_TYPES;
        $this->viewData['stitchConnections'] = $stitchConnections;
        $this->viewData['shopifyConnections'] = $shopifyConnections;

        $this->render('apps/form', $this->viewData);
    }

    /**
     * Store new app
     */
    public function store()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/apps');
            return;
        }

        $workspace = $_SESSION['workspace_slug'] ?? 'default';

        // Validate and parse config_json
        $configJson = trim($this->getParam('config_json', '{}'));
        if (!empty($configJson)) {
            $parsed = json_decode($configJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->flash('error', 'Invalid JSON in configuration: ' . json_last_error_msg());
                Flight::redirect('/apps/form');
                return;
            }
            $configJson = json_encode($parsed); // Re-encode to normalize
        } else {
            $configJson = '{}';
        }

        $port = $this->getParam('port', '');
        $portValue = ($port !== '' && is_numeric($port)) ? (int)$port : null;
        $deploymentTarget = trim($this->getParam('deployment_target', 'local'));

        $data = [
            'name' => trim($this->getParam('name', '')),
            'slug' => trim($this->getParam('slug', '')),
            'description' => trim($this->getParam('description', '')),
            'app_type' => $this->getParam('app_type', 'pipeline'),
            'pipeline_id' => (int)$this->getParam('pipeline_id', 0) ?: null,
            'apikeys_id' => (int)$this->getParam('apikeys_id', 0) ?: null,
            'auth_type' => $this->getParam('auth_type', 'api_key'),
            'api_key' => trim($this->getParam('api_key', '')),
            'port' => $portValue,
            'expose_mcp' => (bool)$this->getParam('expose_mcp', false),
            'auto_restart' => (bool)$this->getParam('auto_restart', false),
            'health_check_path' => trim($this->getParam('health_check_path', '/health')),
            'config_json' => $configJson,
            'member_id' => $this->member->id,
            'deployment_target' => $deploymentTarget,
            'git_repo_url' => trim($this->getParam('git_repo_url', '')),
            'stitch_connection_eid' => (int)$this->getParam('stitch_connection_eid', 0) ?: null,
            'stitch_project_eid' => trim($this->getParam('stitch_project_eid', '')),
            'is_hosted' => (int) $this->getParam('is_hosted', 0),
            'template_slug' => trim($this->getParam('template_slug', '')),
            'shopify_connection_id' => (int)$this->getParam('shopify_connection_id', 0) ?: null,
        ];

        if (empty($data['name'])) {
            $this->flash('error', 'App name is required');
            Flight::redirect('/apps/form');
            return;
        }

        if ($data['app_type'] === 'pipeline' && empty($data['pipeline_id']) && $deploymentTarget === 'local') {
            $this->flash('error', 'Pipeline is required for pipeline-type apps');
            Flight::redirect('/apps/form');
            return;
        }

        try {
            // For non-local deployment targets, auto-create API key and handle deploy key
            if ($deploymentTarget !== 'local') {
                $apiKey = DetachableAppBuilder::createApiKey($this->member->id, $data['name']);
                $data['apikeys_id'] = $apiKey->id;

                // Encrypt deploy key if provided
                $deployKey = trim($this->getParam('git_deploy_key', ''));
                if (!empty($deployKey)) {
                    $data['git_deploy_key'] = \app\services\EncryptionService::encrypt($deployKey);
                }
            }

            $app = $this->appService->create($workspace, $data);
            $this->flash('success', 'App created successfully');

            // Builder apps redirect to the visual builder
            if ($data['app_type'] === 'builder') {
                Flight::redirect('/appbuilder/index/' . $app->id);
            } elseif ($deploymentTarget !== 'local') {
                Flight::redirect('/apps/screens/' . $app->id);
            } else {
                Flight::redirect('/apps/form/' . $app->id);
            }
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to create app: ' . $e->getMessage());
            Flight::redirect('/apps/form');
        }
    }

    /**
     * Update existing app
     */
    public function update()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/apps');
            return;
        }

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            $this->flash('error', 'App ID required');
            Flight::redirect('/apps');
            return;
        }

        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Validate and parse config_json
        $configJson = trim($this->getParam('config_json', '{}'));
        if (!empty($configJson)) {
            $parsed = json_decode($configJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->flash('error', 'Invalid JSON in configuration: ' . json_last_error_msg());
                Flight::redirect('/apps/form/' . $id);
                return;
            }
            $configJson = json_encode($parsed); // Re-encode to normalize
        } else {
            $configJson = '{}';
        }

        $port = $this->getParam('port', '');
        $portValue = ($port !== '' && is_numeric($port)) ? (int)$port : null;

        $data = [
            'name' => trim($this->getParam('name', '')),
            'description' => trim($this->getParam('description', '')),
            'app_type' => $this->getParam('app_type', 'pipeline'),
            'pipelines_id' => (int)$this->getParam('pipeline_id', 0) ?: null,
            'apikeys_id' => (int)$this->getParam('apikeys_id', 0) ?: null,
            'auth_type' => $this->getParam('auth_type', 'api_key'),
            'api_key' => trim($this->getParam('api_key', '')),
            'port' => $portValue,
            'expose_mcp' => (bool)$this->getParam('expose_mcp', false),
            'auto_restart' => (bool)$this->getParam('auto_restart', false),
            'health_check_path' => trim($this->getParam('health_check_path', '/health')),
            'config_json' => $configJson,
            'deployment_target' => trim($this->getParam('deployment_target', $app->deployment_target ?? 'local')),
            'git_repo_url' => trim($this->getParam('git_repo_url', $app->git_repo_url ?? '')),
            'stitch_connection_eid' => (int)$this->getParam('stitch_connection_eid', $app->stitch_connection_eid ?? 0) ?: null,
            'stitch_project_eid' => trim($this->getParam('stitch_project_eid', $app->stitch_project_eid ?? '')),
            'is_hosted' => (int) $this->getParam('is_hosted', $app->is_hosted ?? 0),
            'template_slug' => trim($this->getParam('template_slug', $app->template_slug ?? '')),
            'shopify_connection_id' => (int)$this->getParam('shopify_connection_id', $app->shopify_connection_id ?? 0) ?: null,
        ];

        // Encrypt deploy key if provided
        $deployKey = trim($this->getParam('git_deploy_key', ''));
        if (!empty($deployKey)) {
            $data['git_deploy_key'] = \app\services\EncryptionService::encrypt($deployKey);
        }

        if (empty($data['name'])) {
            $this->flash('error', 'App name is required');
            Flight::redirect('/apps/form/' . $id);
            return;
        }

        try {
            $this->appService->update($id, $data);
            $this->flash('success', 'App updated successfully');
            Flight::redirect('/apps/form/' . $id);
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to update app: ' . $e->getMessage());
            Flight::redirect('/apps/form/' . $id);
        }
    }

    /**
     * Delete app. For non-local apps, also cleans up child records.
     */
    public function delete()
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        try {
            // Check if app has non-local deployment target for child record cleanup
            $app = Bean::load('tenantapps', $id);
            if ($app && $app->id && !empty($app->deployment_target) && $app->deployment_target !== 'local') {
                // Delete child records
                $screens = Bean::find('appscreens', ' tenantapps_id = ? ', [$id]);
                foreach ($screens as $s) Bean::trash($s);

                $bindings = Bean::find('apppipelines', ' tenantapps_id = ? ', [$id]);
                foreach ($bindings as $b) Bean::trash($b);

                $releases = Bean::find('appreleases', ' tenantapps_id = ? ', [$id]);
                foreach ($releases as $r) Bean::trash($r);

                $liquidFiles = Bean::find('appliquidfiles', ' tenantapps_id = ? ', [$id]);
                foreach ($liquidFiles as $lf) Bean::trash($lf);

                // Delete API key
                if ($app->apikeys_id) {
                    $apiKey = Bean::load('apikeys', (int)$app->apikeys_id);
                    if ($apiKey && $apiKey->id) Bean::trash($apiKey);
                }

                // Remove output directory if it exists
                $outputDir = $app->box()->getOutputDir();
                if ($outputDir) {
                    $fullPath = dirname(__DIR__) . '/' . $outputDir;
                    if (is_dir($fullPath)) {
                        $this->removeDir($fullPath);
                    }
                }
            }

            $this->appService->delete($id);
            Flight::jsonSuccess(null, 'App deleted successfully');
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    // =========================================================================
    // SERVICE MANAGEMENT (start/stop/restart/status/logs)
    // =========================================================================

    /**
     * Start app
     */
    public function start()
    {
        if (!$this->requireLogin()) return;

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        try {
            $result = $this->appService->start($id);
            Flight::jsonSuccess($result, 'App started successfully');
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Stop app
     */
    public function stop()
    {
        if (!$this->requireLogin()) return;

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        try {
            $result = $this->appService->stop($id);
            Flight::jsonSuccess($result, 'App stopped successfully');
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Restart app
     */
    public function restart()
    {
        if (!$this->requireLogin()) return;

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        try {
            $result = $this->appService->restart($id);
            Flight::jsonSuccess($result, 'App restarted successfully');
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get app status
     */
    public function status()
    {
        if (!$this->requireLogin()) return;

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        try {
            $status = $this->appService->status($id);
            Flight::jsonSuccess($status);
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get app logs
     */
    public function logs()
    {
        if (!$this->requireLogin()) return;

        $id = (int)($this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        $lines = (int)$this->getParam('lines', 100);

        try {
            $logs = $this->appService->getLogs($id, $lines);
            Flight::jsonSuccess($logs);
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Generate new API key
     */
    public function generatekey()
    {
        if (!$this->requireLogin()) return;

        $key = 'app_' . bin2hex(random_bytes(16));
        Flight::jsonSuccess(['api_key' => $key]);
    }

    /**
     * API endpoint - list apps as JSON
     */
    public function api()
    {
        if (!$this->requireLogin()) return;

        $workspace = $_SESSION['workspace_slug'] ?? 'default';

        try {
            $apps = $this->appService->listApps($workspace);
            Flight::jsonSuccess(['apps' => $apps]);
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Download static app as zip
     */
    public function download($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? $this->opId() ?? $this->getParam('id') ?? 0);
        if (!$id) {
            $this->flash('error', 'App ID required');
            Flight::redirect('/apps');
            return;
        }

        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Check for non-local deployment target (docker/wasm build download)
        $deploymentTarget = $app->deployment_target ?? 'local';
        if ($deploymentTarget !== 'local') {
            try {
                $outputDir = dirname(__DIR__) . '/' . $app->box()->getOutputDir();
                if (!is_dir($outputDir)) {
                    $this->builder->fetchAllScreens((int)$app->id);
                    $this->builder->build((int)$app->id);
                }

                $zipPath = $this->builder->packageZip((int)$app->id);

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $app->slug . '.zip"');
                header('Content-Length: ' . filesize($zipPath));
                readfile($zipPath);

                unlink($zipPath);
                exit;
            } catch (\Exception $e) {
                $this->flash('error', 'Download failed: ' . $e->getMessage());
                Flight::redirect('/apps/form/' . $id);
                return;
            }
        }

        // Static build download (original logic)
        $configJson = json_decode($app->config_json ?: '{}', true) ?: [];
        if (($configJson['build_target'] ?? '') !== 'static') {
            $this->flash('error', 'Only static apps can be downloaded');
            Flight::redirect('/apps');
            return;
        }

        try {
            $builder = new TenantAppBuilder();
            $appDir = $builder->build($app->id);
            $zipPath = $builder->packageStaticZip($appDir, $app->slug);

            if (!file_exists($zipPath)) {
                throw new \RuntimeException('Zip file was not created');
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $app->slug . '.zip"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);

            // Clean up zip after sending
            unlink($zipPath);
            exit;
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to build static app: ' . $e->getMessage());
            Flight::redirect('/apps');
        }
    }

    /**
     * Get port statistics
     */
    public function ports()
    {
        if (!$this->requireLogin()) return;

        $portManager = new TenantAppPortManager();
        $stats = $portManager->getStats();
        Flight::jsonSuccess($stats);
    }

    // =========================================================================
    // SCREEN MANAGEMENT
    // =========================================================================

    /**
     * GET /apps/screens/{id} - Screen selection and route mapping
     */
    public function screens($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Get selected screens
        $selectedScreens = Bean::find(
            'appscreens',
            ' tenantapps_id = ? ORDER BY sort_order ASC, id ASC ',
            [$id]
        );

        // Get available screens from Stitch
        $availableScreens = [];
        if ($app->stitch_connection_eid && $app->stitch_project_eid) {
            try {
                $client = new StitchClient((int)$app->stitch_connection_eid);
                $result = $client->listScreens($app->stitch_project_eid);
                $screens = $result['result']['structuredContent']['screens']
                    ?? $result['result']['content'][0]['text'] ?? [];

                if (is_string($screens)) {
                    $screens = json_decode($screens, true) ?: [];
                }
                if (isset($screens['screens'])) {
                    $screens = $screens['screens'];
                }

                // Fallback to pipeline runs
                if (empty($screens)) {
                    $screens = $this->findScreensFromPipelineRuns(
                        (int) $app->stitch_connection_eid,
                        $app->stitch_project_eid
                    );
                }

                // Filter out already-selected screens
                $selectedIds = [];
                foreach ($selectedScreens as $ss) {
                    $selectedIds[$ss->stitch_screen_eid] = true;
                }

                foreach ($screens as $s) {
                    $screenId = $s['id'] ?? $s['screenId'] ?? '';
                    if (!empty($screenId) && !isset($selectedIds[$screenId])) {
                        $availableScreens[] = $s;
                    }
                }
            } catch (\Exception $e) {
                $this->flash('warning', 'Could not load Stitch screens: ' . $e->getMessage());
            }
        }

        $this->viewData['title'] = 'Screens: ' . $app->name;
        $this->viewData['app'] = $app;
        $this->viewData['selectedScreens'] = $selectedScreens;
        $this->viewData['availableScreens'] = $availableScreens;

        $this->render('apps/screens', $this->viewData);
    }

    /**
     * POST /apps/addscreen/{appId} - Add a screen to the app (AJAX)
     */
    public function addscreen($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 400);
            return;
        }

        $appId = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            Flight::jsonError('App not found', 404);
            return;
        }

        $stitchScreenId = trim($this->getParam('stitch_screen_eid', ''));
        $screenTitle = trim($this->getParam('screen_title', ''));
        $routePath = trim($this->getParam('route_path', ''));
        $isDefault = (bool)$this->getParam('is_default', false);
        $downloadUrl = trim($this->getParam('download_url', ''));

        if (empty($stitchScreenId)) {
            Flight::jsonError('Screen ID is required', 400);
            return;
        }

        // Check for duplicate
        $existing = Bean::findOne('appscreens',
            ' tenantapps_id = ? AND stitch_screen_eid = ? ',
            [$appId, $stitchScreenId]
        );
        if ($existing) {
            Flight::jsonError('Screen already added', 409);
            return;
        }

        try {
            $screen = Bean::dispense('appscreens');
            $screen->tenantapps_id = $appId;
            $screen->stitch_screen_eid = $stitchScreenId;
            $screen->screen_title = $screenTitle;
            $screen->route_path = $routePath;
            $screen->is_default = $isDefault;
            $screen->stitch_download_url = $downloadUrl;

            // Get max sort_order
            $maxSort = Bean::getCell(
                'SELECT MAX(sort_order) FROM appscreens WHERE tenantapps_id = ?',
                [$appId]
            );
            $screen->sort_order = ((int)$maxSort) + 1;

            // If this is default, unset other defaults
            if ($isDefault) {
                $this->clearDefaultScreens($appId);
            }

            // Try to fetch and cache HTML immediately
            if ($app->stitch_connection_eid && !empty($stitchScreenId)) {
                try {
                    $client = new StitchClient((int)$app->stitch_connection_eid);
                    $result = $this->fetchScreenHtmlProxy($client, $app->stitch_project_eid, $stitchScreenId, $downloadUrl);
                    if ($result['success']) {
                        $screen->html_cache = $result['content'];
                        $screen->html_cached_at = date('Y-m-d H:i:s');
                    }
                } catch (\Exception $e) {
                    // Non-fatal: HTML can be fetched later via build
                }
            }

            Bean::store($screen);

            Flight::jsonSuccess(['screen_id' => $screen->id], 'Screen added');
        } catch (\Exception $e) {
            Flight::jsonError('Failed to add screen: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /apps/removescreen/{appId} - Remove a screen (AJAX)
     */
    public function removescreen($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 400);
            return;
        }

        $appId = (int)($params[0] ?? 0);
        $screenId = (int)$this->getParam('screen_id', 0);

        $screen = Bean::findOne('appscreens',
            ' id = ? AND tenantapps_id = ? ',
            [$screenId, $appId]
        );

        if (!$screen) {
            Flight::jsonError('Screen not found', 404);
            return;
        }

        Bean::trash($screen);
        Flight::jsonSuccess(null, 'Screen removed');
    }

    /**
     * POST /apps/updatescreens/{appId} - Update screen routes/order (AJAX)
     */
    public function updatescreens($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 400);
            return;
        }

        $appId = (int)($params[0] ?? 0);
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);
        $screensData = $data['screens'] ?? [];

        foreach ($screensData as $i => $sd) {
            $screen = Bean::load('appscreens', (int)($sd['id'] ?? 0));
            if (!$screen || !$screen->id || (int)$screen->tenantapps_id !== $appId) continue;

            $screen->route_path = $sd['route_path'] ?? $screen->route_path;
            $screen->screen_title = $sd['screen_title'] ?? $screen->screen_title;
            $screen->is_default = (bool)($sd['is_default'] ?? false);
            $screen->sort_order = $i;
            Bean::store($screen);
        }

        Flight::jsonSuccess(null, 'Screens updated');
    }

    /**
     * GET /apps/addblockscreen/{appId} - Create a blank block screen and redirect to editor
     */
    public function addblockscreen($params = [])
    {
        if (!$this->requireLogin()) return;

        $appId = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Get max sort_order
        $maxSort = Bean::getCell(
            'SELECT MAX(sort_order) FROM appscreens WHERE tenantapps_id = ?',
            [$appId]
        );
        $nextSort = ((int)$maxSort) + 1;

        $screen = Bean::dispense('appscreens');
        $screen->tenantapps_id = $appId;
        $screen->screen_title = 'New Screen';
        $screen->route_path = '/new-screen-' . $nextSort;
        $screen->is_default = 0;
        $screen->sort_order = $nextSort;
        $screen->blocks_json = '[]';
        $screen->page_config_json = '{}';
        $screen->layout_type = 'single';
        Bean::store($screen);

        Flight::redirect('/appbuilder/index/' . $appId . '?screen_id=' . $screen->id);
    }

    // =========================================================================
    // PIPELINE BINDING
    // =========================================================================

    /**
     * GET /apps/pipelines/{id} - Pipeline binding config
     */
    public function pipelines($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Get current bindings
        $bindings = Bean::find('apppipelines', ' tenantapps_id = ? ', [$id]);
        $boundPipelineIds = [];
        $bindingMap = [];
        foreach ($bindings as $b) {
            $boundPipelineIds[(int)$b->pipelines_id] = true;
            $bindingMap[(int)$b->pipelines_id] = $b;
        }

        // Get all active pipelines in workspace
        $allPipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');
        $pipelineList = [];
        foreach ($allPipelines as $p) {
            $binding = $bindingMap[(int)$p->id] ?? null;
            $pipelineList[] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'is_bound' => isset($boundPipelineIds[(int)$p->id]),
                'alias' => $binding ? $binding->alias : $p->slug,
                'binding_id' => $binding ? (int)$binding->id : null,
            ];
        }

        $this->viewData['title'] = 'Pipelines: ' . $app->name;
        $this->viewData['app'] = $app;
        $this->viewData['pipelines'] = $pipelineList;

        $this->render('apps/pipelines', $this->viewData);
    }

    /**
     * POST /apps/bindpipeline/{appId} - Bind a pipeline (AJAX)
     */
    public function bindpipeline($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 400);
            return;
        }

        $appId = (int)($params[0] ?? 0);
        $pipelineId = (int)$this->getParam('pipeline_id', 0);
        $alias = trim($this->getParam('alias', ''));

        if (!$pipelineId) {
            Flight::jsonError('Pipeline ID required', 400);
            return;
        }

        // Check for existing binding
        $existing = Bean::findOne('apppipelines',
            ' tenantapps_id = ? AND pipelines_id = ? ',
            [$appId, $pipelineId]
        );

        if ($existing) {
            if (!empty($alias)) {
                $existing->alias = $alias;
            }
            $existing->is_active = 1;
            Bean::store($existing);
        } else {
            $binding = Bean::dispense('apppipelines');
            $binding->tenantapps_id = $appId;
            $binding->pipelines_id = $pipelineId;
            $binding->alias = $alias;
            $binding->is_active = 1;
            Bean::store($binding);
        }

        Flight::jsonSuccess(null, 'Pipeline bound');
    }

    /**
     * POST /apps/unbindpipeline/{appId} - Unbind a pipeline (AJAX)
     */
    public function unbindpipeline($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid request', 400);
            return;
        }

        $appId = (int)($params[0] ?? 0);
        $pipelineId = (int)$this->getParam('pipeline_id', 0);

        $binding = Bean::findOne('apppipelines',
            ' tenantapps_id = ? AND pipelines_id = ? ',
            [$appId, $pipelineId]
        );

        if ($binding) {
            Bean::trash($binding);
        }

        Flight::jsonSuccess(null, 'Pipeline unbound');
    }

    // =========================================================================
    // BUILD / RELEASE / DOWNLOAD (for docker/shopify targets)
    // =========================================================================

    /**
     * POST /apps/build/{id} - Build app files
     */
    public function build($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/apps');
            return;
        }

        $id = (int)($params[0] ?? 0);

        try {
            // Refresh screen HTML first
            $this->builder->fetchAllScreens($id);
            $outputDir = $this->builder->build($id);
            $this->flash('success', 'App built successfully at: ' . basename($outputDir));
        } catch (\Exception $e) {
            $this->flash('error', 'Build failed: ' . $e->getMessage());
        }

        Flight::redirect('/apps/form/' . $id);
    }

    /**
     * POST /apps/release/{id} - Build + Git commit + tag + push
     */
    public function release($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/apps');
            return;
        }

        $id = (int)($params[0] ?? 0);
        $version = trim($this->getParam('version', ''));
        $notes = trim($this->getParam('release_notes', ''));

        try {
            $this->builder->fetchAllScreens($id);
            $result = $this->builder->gitRelease($id, $version, $notes);
            $this->flash('success', "Released {$result['version']} (commit: " . substr($result['commit_sha'], 0, 7) . ")");
        } catch (\Exception $e) {
            $this->flash('error', 'Release failed: ' . $e->getMessage());
        }

        Flight::redirect('/apps/releases/' . $id);
    }

    /**
     * GET /apps/releases/{id} - Release history
     */
    public function releases($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        $releases = Bean::find('appreleases',
            ' tenantapps_id = ? ORDER BY id DESC ',
            [$id]
        );

        $releaseList = [];
        foreach ($releases as $r) {
            $releaseList[] = $r->box()->toArray();
        }

        $this->viewData['title'] = 'Releases: ' . $app->name;
        $this->viewData['app'] = $app;
        $this->viewData['releases'] = $releaseList;

        $this->render('apps/releases', $this->viewData);
    }

    // =========================================================================
    // SHOPIFY APP BUILDER
    // =========================================================================

    /**
     * GET /apps/buildshopify/{id} - Shopify app build UI
     */
    public function buildshopify($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Count Liquid files
        $liquidFiles = Bean::find('appliquidfiles', ' tenantapps_id = ? ', [$id]);
        $filesByType = [
            'block' => 0, 'embed' => 0, 'section' => 0,
            'snippet' => 0, 'asset_js' => 0, 'asset_css' => 0, 'locale' => 0,
        ];
        foreach ($liquidFiles as $file) {
            $filesByType[$file->file_type]++;
        }

        // Count embedded pipelines
        $pipelineCount = Bean::count('appembeddedpipelines', ' tenantapps_id = ? ', [$id]);

        // Check if bundle exists
        $workspace = $app->workspace ?: 'default';
        $outputDir = dirname(__DIR__) . '/storage/detachable/' . $workspace . '/shopify-' . $app->slug;
        $bundleExists = is_dir($outputDir) && file_exists($outputDir . '/shopify.app.toml');
        $lastBuildAt = $app->last_build_at ?: null;

        // Get Shopify connections for deployment
        require_once __DIR__ . '/../services/ShopifyClient.php';
        $shopifyConnections = \app\services\ShopifyClient::getAllConnections($this->member->id);

        $this->viewData['title'] = 'Build Shopify App: ' . $app->name;
        $this->viewData['app'] = $app;
        $this->viewData['files_by_type'] = $filesByType;
        $this->viewData['total_files'] = count($liquidFiles);
        $this->viewData['pipeline_count'] = $pipelineCount;
        $this->viewData['bundle_exists'] = $bundleExists;
        $this->viewData['last_build_at'] = $lastBuildAt;
        $this->viewData['current_version'] = $app->current_version ?? 0;
        $this->viewData['output_dir'] = $outputDir;
        $this->viewData['shopify_connections'] = $shopifyConnections;

        $this->render('apps/buildshopify', $this->viewData);
    }

    /**
     * POST /apps/dobuildshopify/{id} - Execute Shopify app build (AJAX)
     */
    public function dobuildshopify($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $id = (int)($params[0] ?? 0);
        $useCliBuilder = (bool)($this->getParam('use_cli_builder', true));

        try {
            if ($useCliBuilder) {
                // Use ShopifyAppBuilder with CLI template
                require_once __DIR__ . '/../services/ShopifyAppBuilder.php';
                $workspace = $_SESSION['workspace_slug'] ?? 'default';
                $builder = new \app\services\ShopifyAppBuilder($workspace);
                $result = $builder->build($id);

                // Update build version and timestamp
                $app = Bean::load('tenantapps', $id);
                if ($app && $app->id) {
                    $app->current_version = ($app->current_version ?? 0) + 1;
                    $app->last_build_at = date('Y-m-d H:i:s');
                    $app->build_status = 'success';
                    Bean::store($app);
                }

                Flight::jsonSuccess([
                    'app_dir' => $result['app_dir'],
                    'app_name' => $result['app_name'],
                    'next_steps' => $result['next_steps'],
                    'version' => $app->current_version ?? 1,
                    'built_at' => $app->last_build_at ?? null,
                ], 'Shopify app scaffolded successfully! Run deployment commands shown below.');
            } else {
                // Legacy builder (creates zip bundle)
                $createZip = (bool)($this->getParam('create_zip', false));
                $result = $this->builder->buildShopifyApp($id, ['create_zip' => $createZip]);

                Flight::jsonSuccess([
                    'output_dir' => $result['output_dir'],
                    'file_count' => $result['file_count'],
                    'zip_path' => $result['zip_path'],
                ], 'Shopify app bundle built successfully');
            }

        } catch (\Exception $e) {
            Flight::jsonError('Build failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /apps/downloadshopify/{id} - Download Shopify app .zip
     */
    public function downloadshopify($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? 0);

        try {
            $app = Bean::load('tenantapps', $id);
            if (!$app || !$app->id) {
                $this->flash('error', 'App not found');
                Flight::redirect('/apps');
                return;
            }

            // Build with ZIP creation
            $result = $this->builder->buildShopifyApp($id, ['create_zip' => true]);
            $zipPath = $result['zip_path'];

            if (!file_exists($zipPath)) {
                $this->flash('error', 'ZIP file not found');
                Flight::redirect('/apps/buildshopify/' . $id);
                return;
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $app->slug . '-shopify.zip"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            exit;

        } catch (\Exception $e) {
            $this->flash('error', 'Download failed: ' . $e->getMessage());
            Flight::redirect('/apps/buildshopify/' . $id);
        }
    }

    /**
     * POST /apps/deployshopify/{id} - Deploy Shopify app using CLI (proc_open)
     */
    public function deployshopify($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            Flight::jsonError('Invalid request', 403);
            return;
        }

        $id = (int)($params[0] ?? 0);

        try {
            $app = Bean::load('tenantapps', $id);
            if (!$app || !$app->id) {
                Flight::jsonError('App not found', 404);
                return;
            }

            // ShopifyAppBuilder uses /tmp/{workspace}/shopify-apps/{appSlug}
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $appSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $app->name));
            $appDir = "/tmp/{$workspace}/shopify-apps/" . $appSlug;

            if (!is_dir($appDir)) {
                Flight::jsonError('App not built yet. Click "Build" first.', 400);
                return;
            }

            if (!file_exists($appDir . '/shopify.app.toml')) {
                Flight::jsonError('Invalid app structure. Missing shopify.app.toml', 400);
                return;
            }

            // Get Shopify CLI path from config (supports different paths per environment)
            $shopifyIniPath = __DIR__ . '/../conf/shopify.ini';
            $shopifyCliPath = 'shopify'; // Default fallback

            if (file_exists($shopifyIniPath)) {
                $shopifyConfig = parse_ini_file($shopifyIniPath);
                if ($shopifyConfig && isset($shopifyConfig['cli_path'])) {
                    $shopifyCliPath = $shopifyConfig['cli_path'];
                }
            }

            // Validate CLI path exists (if absolute path)
            if ($shopifyCliPath !== 'shopify' && !file_exists($shopifyCliPath)) {
                Flight::jsonError('Shopify CLI not found at: ' . $shopifyCliPath . '. Update cli_path in conf/shopify.ini or run: which shopify', 400);
                return;
            }

            // Get Shopify Partners credentials
            // Priority: selected connection -> enterprise settings
            $shopifyToken = null;
            $clientId = null;
            $orgId = null;

            // Check if specific connection was selected
            $connectionId = $this->getParam('connection_id');
            $this->logger->info('Deploy request parameters', [
                'connection_id' => $connectionId,
                'all_params' => $_GET
            ]);

            if ($connectionId) {
                require_once __DIR__ . '/../services/ShopifyClient.php';
                $conn = \app\services\ShopifyClient::getConnection((int)$connectionId);
                if ($conn && $conn->id) {
                    $meta = json_decode($conn->metadata_json ?: '{}', true);
                    $this->logger->info('Connection metadata', [
                        'connection_id' => $conn->id,
                        'has_cli_token' => !empty($meta['shopify_cli_token']),
                        'client_id' => $meta['shopify_client_id'] ?? 'not set',
                        'org_id' => $meta['shopify_org_id'] ?? 'not set'
                    ]);

                    if (!empty($meta['shopify_cli_token'])) {
                        $shopifyToken = \app\services\EncryptionService::decrypt($meta['shopify_cli_token']);
                        $clientId = $meta['shopify_client_id'] ?? null;
                        $orgId = $meta['shopify_org_id'] ?? null;

                        $this->logger->info('Using Partners credentials from connection', [
                            'connection_id' => $connectionId,
                            'shop' => $conn->external_eid,
                            'has_client_id' => !empty($clientId),
                            'has_org_id' => !empty($orgId)
                        ]);
                    }
                }
            }

            // Fall back to enterprise settings if no connection selected
            if (!$shopifyToken) {
                $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['shopify_cli_token']);
                if ($setting && $setting->id) {
                    $shopifyToken = \app\services\EncryptionService::decrypt($setting->setting_value);
                    $this->logger->info('Using Partners credentials from enterprise settings');
                }
            }

            // Build environment variables
            $envVars = [
                'SHOPIFY_CLI_NO_ANALYTICS=1',
                'SHOPIFY_FLAG_PATH=' . escapeshellarg($appDir),
            ];

            if ($shopifyToken) {
                $envVars[] = 'SHOPIFY_CLI_PARTNERS_TOKEN=' . escapeshellarg($shopifyToken);
            }

            // Build command flags
            $flags = ['--force', '--no-color'];
            if ($clientId) {
                $flags[] = '--client-id';
                $flags[] = escapeshellarg($clientId);
            }

            // Run shopify app deploy with proc_open
            $cmd = sprintf(
                '%s cd %s && %s app deploy %s 2>&1',
                implode(' ', $envVars),
                escapeshellarg($appDir),
                escapeshellarg($shopifyCliPath),
                implode(' ', $flags)
            );

            // Log the full command for debugging
            $this->logger->info('Shopify deploy command', [
                'cmd' => $cmd,
                'client_id' => $clientId ? 'set' : 'not set',
                'org_id' => $orgId ? 'set' : 'not set'
            ]);

            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w']   // stderr
            ];

            $process = proc_open($cmd, $descriptorspec, $pipes);

            if (!is_resource($process)) {
                Flight::jsonError('Failed to start deployment process', 500);
                return;
            }

            // Close stdin (no interactive input)
            fclose($pipes[0]);

            // Read stdout in real-time
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $errorOutput = '';
            $timeout = 300; // 5 minutes
            $startTime = time();

            while (true) {
                // Check timeout
                if (time() - $startTime > $timeout) {
                    proc_terminate($process);
                    Flight::jsonError('Deployment timed out after 5 minutes', 408);
                    return;
                }

                // Read from stdout
                $line = fgets($pipes[1]);
                if ($line !== false) {
                    $output .= $line;
                }

                // Read from stderr
                $errLine = fgets($pipes[2]);
                if ($errLine !== false) {
                    $errorOutput .= $errLine;
                }

                // Check if process is still running
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // Drain remaining output
                    while (($line = fgets($pipes[1])) !== false) {
                        $output .= $line;
                    }
                    while (($errLine = fgets($pipes[2])) !== false) {
                        $errorOutput .= $errLine;
                    }
                    break;
                }

                // Small delay to prevent CPU spinning
                usleep(100000); // 0.1 seconds
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $fullOutput = $output . ($errorOutput ? "\n\nERROR OUTPUT:\n" . $errorOutput : '');

            if ($exitCode === 0) {
                // Update app with deployment info
                $app->last_deployed_at = date('Y-m-d H:i:s');
                Bean::store($app);

                Flight::jsonSuccess([
                    'output' => $fullOutput,
                    'exit_code' => $exitCode,
                ], 'Deployment completed successfully');
            } else {
                // Deployment failed - include output in response data
                Flight::json([
                    'success' => false,
                    'error' => 'Deployment failed with exit code ' . $exitCode,
                    'data' => [
                        'output' => $fullOutput,
                        'exit_code' => $exitCode,
                    ]
                ], 500);
                return;
            }

        } catch (\Exception $e) {
            $this->logger->error('Shopify deployment failed', [
                'app_id' => $id,
                'error' => $e->getMessage(),
            ]);
            Flight::jsonError('Deployment failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /apps/deploymentstatus/{runId} - Check deployment status
     */
    public function deploymentstatus($params = [])
    {
        if (!$this->requireLogin()) return;

        $runId = (int)($params[0] ?? 0);

        try {
            $run = Bean::load('pipelineruns', $runId);
            if (!$run || !$run->id) {
                Flight::jsonError('Run not found', 404);
                return;
            }

            // Get step statuses
            $steps = Bean::find('pipelinerunsteps', ' pipelineruns_id = ? ORDER BY step_order ', [$runId]);
            $stepStatuses = [];
            foreach ($steps as $step) {
                $stepStatuses[] = [
                    'name' => $step->step_name,
                    'status' => $step->status,
                    'output' => $step->output,
                    'error' => $step->error_message,
                ];
            }

            Flight::jsonSuccess([
                'status' => $run->status,
                'output' => $run->final_output,
                'error' => $run->error_message,
                'steps' => $stepStatuses,
                'completed_at' => $run->completed_at,
            ]);

        } catch (\Exception $e) {
            Flight::jsonError('Status check failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /apps/getshopifyproducts/{connectionId} - Fetch Shopify products (AJAX)
     */
    public function getshopifyproducts($params = [])
    {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);

        try {
            // Load Shopify connection from unified connections table
            $connection = Bean::load('connections', $connectionId);
            if (!$connection || !$connection->id || $connection->connector_type !== 'shopify') {
                Flight::jsonError('Shopify connection not found', 404);
                return;
            }

            // Use ShopifyClient to fetch products
            require_once __DIR__ . '/../services/ShopifyClient.php';
            $client = new \app\services\ShopifyClient($connectionId);

            if (!$client->isConnected()) {
                Flight::jsonError('Shopify connection not active', 400);
                return;
            }

            // GraphQL query to get products
            $query = <<<'GRAPHQL'
query getProducts($first: Int!) {
  products(first: $first) {
    edges {
      node {
        id
        handle
        title
        priceRangeV2 {
          minVariantPrice {
            amount
          }
        }
      }
    }
  }
}
GRAPHQL;

            $result = $client->graphql($query, ['first' => 50]);

            if (!$result['success']) {
                Flight::jsonError('Failed to fetch products: ' . ($result['error'] ?? 'Unknown error'), 500);
                return;
            }

            // Transform products for dropdown
            $products = [];
            foreach ($result['data']['products']['edges'] ?? [] as $edge) {
                $node = $edge['node'];
                $products[] = [
                    'id' => $node['id'],
                    'handle' => $node['handle'],
                    'title' => $node['title'],
                    'price' => $node['priceRangeV2']['minVariantPrice']['amount'] ?? null,
                ];
            }

            Flight::jsonSuccess(['products' => $products]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Shopify products', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            Flight::jsonError('Failed to fetch products: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // SHOPIFY LIQUID FILES
    // =========================================================================

    /**
     * GET /apps/liquidfiles/{appId} - Manage Shopify Liquid template files
     */
    public function liquidfiles($params = [])
    {
        if (!$this->requireLogin()) return;

        $id = (int)($params[0] ?? 0);
        $app = Bean::load('tenantapps', $id);
        if (!$app || !$app->id) {
            $this->flash('error', 'App not found');
            Flight::redirect('/apps');
            return;
        }

        // Load all liquid files for this app, grouped by type
        $allFiles = Bean::find(
            'appliquidfiles',
            ' tenantapps_id = ? ORDER BY file_type ASC, sort_order ASC, file_name ASC ',
            [$id]
        );

        $filesByType = [
            'block' => [],
            'embed' => [],
            'section' => [],
            'snippet' => [],
            'asset_js' => [],
            'asset_css' => [],
            'locale' => [],
        ];

        foreach ($allFiles as $file) {
            $filesByType[$file->file_type][] = $file;
        }

        // Get Shopify connections for preview
        $shopifyConnections = Bean::find('connections', " connector_type = 'shopify' AND enabled = 1 ORDER BY connection_name ASC ");

        $this->viewData['title'] = 'Shopify Theme Files: ' . $app->name;
        $this->viewData['app'] = $app;
        $this->viewData['filesByType'] = $filesByType;
        $this->viewData['shopifyConnections'] = $shopifyConnections;

        $this->render('apps/liquidfiles', $this->viewData);
    }

    /**
     * POST /apps/saveliquidfile - Create or update a liquid file (AJAX)
     */
    public function saveliquidfile($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $appId = (int)($input['app_id'] ?? 0);
        $fileId = (int)($input['file_id'] ?? 0);
        $fileType = $input['file_type'] ?? '';
        $fileName = trim($input['file_name'] ?? '');
        $fileContent = $input['file_content'] ?? '';
        $settingsSchema = $input['settings_schema'] ?? '{}';

        if (empty($appId)) {
            Flight::jsonError('App ID required', 400);
            return;
        }

        if (!in_array($fileType, ['block', 'embed', 'section', 'snippet', 'asset_js', 'asset_css', 'locale'])) {
            Flight::jsonError('Invalid file type', 400);
            return;
        }

        if (empty($fileName)) {
            Flight::jsonError('File name required', 400);
            return;
        }

        try {
            if ($fileId > 0) {
                // Update existing
                $file = Bean::load('appliquidfiles', $fileId);
                if (!$file || !$file->id || $file->tenantapps_id != $appId) {
                    Flight::jsonError('File not found', 404);
                    return;
                }
            } else {
                // Create new
                $file = Bean::dispense('appliquidfiles');
                $file->tenantapps_id = $appId;
                $file->created_at = date('Y-m-d H:i:s');
            }

            $file->file_type = $fileType;
            $file->file_name = $fileName;
            $file->file_content = $fileContent;
            $file->settings_schema = $settingsSchema;
            $file->updated_at = date('Y-m-d H:i:s');

            Bean::store($file);

            Flight::jsonSuccess([
                'file' => [
                    'id' => $file->id,
                    'file_type' => $file->file_type,
                    'file_name' => $file->file_name,
                    'updated_at' => $file->updated_at,
                ]
            ], 'File saved successfully');

        } catch (\Exception $e) {
            Flight::jsonError('Failed to save file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /apps/deleteliquidfile - Delete a liquid file (AJAX)
     */
    public function deleteliquidfile($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRFHeader()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $fileId = (int)($input['file_id'] ?? 0);

        if (empty($fileId)) {
            Flight::jsonError('File ID required', 400);
            return;
        }

        try {
            $file = Bean::load('appliquidfiles', $fileId);
            if (!$file || !$file->id) {
                Flight::jsonError('File not found', 404);
                return;
            }

            Bean::trash($file);

            Flight::jsonSuccess(null, 'File deleted successfully');

        } catch (\Exception $e) {
            Flight::jsonError('Failed to delete file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /apps/getliquidfile/{fileId} - Get liquid file content (AJAX)
     */
    public function getliquidfile($params = [])
    {
        if (!$this->requireLogin()) return;

        $fileId = (int)($params[0] ?? 0);

        if (empty($fileId)) {
            Flight::jsonError('File ID required', 400);
            return;
        }

        $file = Bean::load('appliquidfiles', $fileId);
        if (!$file || !$file->id) {
            Flight::jsonError('File not found', 404);
            return;
        }

        Flight::jsonSuccess([
            'file' => [
                'id' => $file->id,
                'file_type' => $file->file_type,
                'file_name' => $file->file_name,
                'file_content' => $file->file_content,
                'settings_schema' => $file->settings_schema,
                'updated_at' => $file->updated_at,
            ]
        ]);
    }

    // =========================================================================
    // APP TEMPLATES
    // =========================================================================

    /**
     * GET /apps/templates - Browse template gallery
     */
    public function templates()
    {
        if (!$this->requireLogin()) return;

        $templates = AppTemplateRegistry::getAll();

        $this->viewData['title'] = 'App Templates';
        $this->viewData['templates'] = $templates;

        $this->render('apps/templates', $this->viewData);
    }

    /**
     * GET /apps/templateform/{slug} - Configure a template
     */
    public function templateform($params = [])
    {
        if (!$this->requireLogin()) return;

        $slug = $params[0] ?? '';
        $template = AppTemplateRegistry::get($slug);

        if (!$template) {
            $this->flash('error', 'Template not found');
            Flight::redirect('/apps/templates');
            return;
        }

        // Resolve dynamic options for select fields
        $fieldOptions = [];
        foreach ($template->getConfigSchema() as $field) {
            if (!empty($field['options_source'])) {
                $fieldOptions[$field['name']] = $this->resolveOptionSource($field['options_source']);
            }
        }

        $this->viewData['title'] = 'Create from Template: ' . $template->getName();
        $this->viewData['template'] = $template;
        $this->viewData['fieldOptions'] = $fieldOptions;

        $this->render('apps/templateform', $this->viewData);
    }

    /**
     * POST /apps/createfromtemplate/{slug} - Create app from template
     */
    public function createfromtemplate($params = [])
    {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/apps/templates');
            return;
        }

        $slug = $params[0] ?? '';
        $template = AppTemplateRegistry::get($slug);

        if (!$template) {
            $this->flash('error', 'Template not found');
            Flight::redirect('/apps/templates');
            return;
        }

        $name = trim($this->getParam('name', ''));
        if (empty($name)) {
            $this->flash('error', 'App name is required');
            Flight::redirect('/apps/templateform/' . $slug);
            return;
        }

        // Collect template config values
        $config = [];
        foreach ($template->getConfigSchema() as $field) {
            $config[$field['name']] = $this->getParam($field['name'], $field['default'] ?? '');
        }

        // Validate
        $validation = $template->validateConfig($config);
        if (!$validation['valid']) {
            $this->flash('error', implode(', ', $validation['errors']));
            Flight::redirect('/apps/templateform/' . $slug);
            return;
        }

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $appSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $appSlug = trim($appSlug, '-');

        // Check for duplicate
        $existing = Bean::findOne('tenantapps', ' slug = ? AND workspace = ? ', [$appSlug, $workspace]);
        if ($existing) {
            $this->flash('error', 'An app with this name already exists');
            Flight::redirect('/apps/templateform/' . $slug);
            return;
        }

        try {
            // Create API key
            $apiKey = DetachableAppBuilder::createApiKey($this->member->id, $name);

            // Create the app bean
            $app = Bean::dispense('tenantapps');
            $app->name = $name;
            $app->slug = $appSlug;
            $app->description = $template->getDescription();
            $app->template_slug = $template->getSlug();
            $app->stitch_connection_eid = null;
            $app->stitch_project_eid = '';
            $app->git_repo_url = '';
            $app->apikeys_id = $apiKey->id;
            $app->member_id = $this->member->id;
            $app->workspace = $workspace;
            $app->config_json = json_encode($config);
            $app->is_hosted = 1; // Auto-enable hosted mode for template apps
            Bean::store($app);

            // Create screens from template
            $screens = $template->getScreens($config);
            foreach ($screens as $i => $screenDef) {
                $screen = Bean::dispense('appscreens');
                $screen->tenantapps_id = $app->id;
                $screen->stitch_screen_eid = 'template-' . ($i + 1);
                $screen->screen_title = $screenDef['title'];
                $screen->route_path = $screenDef['route_path'];
                $screen->is_default = $screenDef['is_default'] ? 1 : 0;
                $screen->sort_order = $i;
                $screen->html_cache = $screenDef['html'];
                $screen->html_cached_at = date('Y-m-d H:i:s');
                Bean::store($screen);
            }

            // Run post-creation hook
            $template->afterCreate((int)$app->id, $config);

            $this->flash('success', 'App created from template. Review settings and build when ready.');
            Flight::redirect('/apps/form/' . $app->id);

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to create app: ' . $e->getMessage());
            Flight::redirect('/apps/templateform/' . $slug);
        }
    }

    // =========================================================================
    // STITCH HELPERS
    // =========================================================================

    /**
     * POST /apps/createproject/{connectionId} - Create a new Stitch project (AJAX)
     */
    public function createproject($params = [])
    {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        if ($connectionId <= 0) {
            Flight::jsonError('Connection ID required', 400);
            return;
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);
        $title = trim($data['title'] ?? '');

        if (empty($title)) {
            Flight::jsonError('Project title is required', 400);
            return;
        }

        try {
            $client = new StitchClient($connectionId);
            $result = $client->createProject($title);

            // Extract project info from MCP response (same parsing as fetchStitchProjects)
            $project = $result['result']['structuredContent'] ?? [];
            if (empty($project)) {
                foreach (($result['result']['content'] ?? []) as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed) {
                            $project = $parsed;
                            break;
                        }
                    }
                }
            }

            $pName = $project['name'] ?? '';
            $pTitle = $project['title'] ?? $project['displayName'] ?? $title;
            $pId = '';
            if (preg_match('/projects\/(\d+)/', $pName, $m)) {
                $pId = $m[1];
            } elseif (isset($project['projectId'])) {
                $pId = $project['projectId'];
            }

            if (empty($pId)) {
                Flight::jsonError('Project created but could not parse project ID from response', 500);
                return;
            }

            Flight::jsonSuccess(['id' => $pId, 'title' => $pTitle]);

        } catch (\Exception $e) {
            Flight::jsonError('Failed to create project: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /apps/stitchprojects/{connectionId} - AJAX endpoint for project dropdown
     */
    public function stitchprojects($params = [])
    {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        if ($connectionId <= 0) {
            Flight::jsonError('Connection ID required', 400);
            return;
        }

        $projects = $this->fetchStitchProjects($connectionId);
        Flight::jsonSuccess(['projects' => $projects]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Fetch Stitch projects for a given connection ID (used by form and AJAX)
     */
    private function fetchStitchProjects(int $connectionId): array
    {
        $projects = [];
        try {
            $client = new StitchClient($connectionId);
            $result = $client->listProjects();

            $raw = $result['result']['structuredContent']['projects'] ?? [];

            if (empty($raw)) {
                $content = $result['result']['content'] ?? [];
                foreach ($content as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed) {
                            if (isset($parsed['projects']) && is_array($parsed['projects'])) {
                                $raw = array_merge($raw, $parsed['projects']);
                            } else {
                                $raw[] = $parsed;
                            }
                        }
                    }
                }
            }

            foreach ($raw as $project) {
                $pName = $project['name'] ?? '';
                $pTitle = $project['title'] ?? $project['displayName'] ?? 'Untitled Project';
                $pId = '';
                if (preg_match('/projects\/(\d+)/', $pName, $m)) {
                    $pId = $m[1];
                } elseif (isset($project['projectId'])) {
                    $pId = $project['projectId'];
                }
                if (!empty($pId)) {
                    $projects[] = ['id' => $pId, 'title' => $pTitle];
                }
            }
        } catch (\Exception $e) {
            // Non-fatal: return empty list
        }

        return $projects;
    }

    /**
     * Clear default screen flags for a given app
     */
    private function clearDefaultScreens(int $appId): void
    {
        $screens = Bean::find('appscreens',
            ' tenantapps_id = ? AND is_default = 1 ',
            [$appId]
        );
        foreach ($screens as $s) {
            $s->is_default = 0;
            Bean::store($s);
        }
    }

    /**
     * Fetch screen HTML via Stitch proxy (for addscreen action)
     */
    private function fetchScreenHtmlProxy(StitchClient $client, string $projectId, string $screenId, string $cachedUrl = ''): array
    {
        if (!empty($cachedUrl)) {
            $result = $client->downloadAuthenticatedUrl($cachedUrl);
            if ($result['success']) return $result;
        }

        $name = "projects/{$projectId}/screens/{$screenId}";
        $screenResult = $client->getScreen($name, $projectId, $screenId);
        $screen = $screenResult['result']['structuredContent'] ?? [];
        if (empty($screen) || !isset($screen['name'])) {
            foreach (($screenResult['result']['content'] ?? []) as $item) {
                if (isset($item['text'])) {
                    $parsed = json_decode($item['text'], true);
                    if ($parsed && isset($parsed['name'])) { $screen = $parsed; break; }
                }
            }
        }

        $downloadUrl = $screen['htmlCode']['downloadUrl']
            ?? $screen['codeDownloadUrl']
            ?? $screen['code_download_url']
            ?? '';

        if (empty($downloadUrl)) {
            return ['success' => false, 'error' => 'No download URL'];
        }

        return $client->downloadAuthenticatedUrl($downloadUrl);
    }

    /**
     * Search pipeline step run outputs for screens
     */
    private function findScreensFromPipelineRuns(int $connectionId, string $projectId): array
    {
        $screens = [];
        $seenIds = [];

        $stepRuns = Bean::find('pipelinestepruns', ' status = ? ORDER BY id DESC LIMIT 50 ', ['success']);

        foreach ($stepRuns as $sr) {
            $output = json_decode($sr->output_json ?: '{}', true);
            if (!is_array($output)) continue;

            $sc = $output['output']['structuredContent'] ?? [];
            if (empty($sc['outputComponents'])) continue;

            $outputProjectId = (string)($sc['projectId'] ?? '');
            if (!empty($outputProjectId) && $outputProjectId !== $projectId) continue;

            foreach ($sc['outputComponents'] as $comp) {
                $design = $comp['design'] ?? [];
                if (empty($design['screens'])) continue;

                foreach ($design['screens'] as $screen) {
                    $sid = $screen['id'] ?? '';
                    if (empty($sid) || isset($seenIds[$sid])) continue;

                    $screenName = $screen['name'] ?? '';
                    if (!empty($screenName) && !str_contains($screenName, "projects/{$projectId}/")) continue;

                    $seenIds[$sid] = true;
                    $screens[] = $screen;
                }
            }
        }

        return $screens;
    }

    /**
     * Resolve dynamic option sources for template config fields
     */
    private function resolveOptionSource(string $source): array
    {
        $options = [];

        switch ($source) {
            case 'shopify_connections':
                $connections = \app\services\ShopifyClient::getAllConnections($this->member->id);
                foreach ($connections as $conn) {
                    $options[] = [
                        'value' => (string)$conn->id,
                        'label' => $conn->external_name ?: $conn->external_eid,
                    ];
                }
                break;

            case 'pipelines':
                $pipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');
                foreach ($pipelines as $p) {
                    $options[] = [
                        'value' => (string)$p->id,
                        'label' => $p->name,
                    ];
                }
                break;
        }

        return $options;
    }

    /**
     * Get or create the Shopify deployment pipeline
     */
    private function getOrCreateDeploymentPipeline()
    {
        // Check if pipeline already exists - delete and recreate for now to update command
        $pipeline = Bean::findOne('pipelines', ' slug = ? ', ['shopify-cli-deploy']);

        if ($pipeline && $pipeline->id) {
            // Delete old steps
            $steps = Bean::find('pipelinesteps', ' pipelines_id = ? ', [$pipeline->id]);
            foreach ($steps as $step) {
                Bean::trash($step);
            }
            // Delete pipeline
            Bean::trash($pipeline);
        }

        // Create new deployment pipeline
        $pipeline = Bean::dispense('pipelines');
        $pipeline->name = 'Shopify CLI Deploy';
        $pipeline->slug = 'shopify-cli-deploy';
        $pipeline->description = 'Deploys Shopify app using CLI';
        $pipeline->webhook_type = 'incoming';
        $pipeline->is_active = true;
        $pipeline->created_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        // Add deployment step with working directory set separately
        $step = Bean::dispense('pipelinesteps');
        $step->pipelines_id = $pipeline->id;
        $step->step_name = 'deploy_to_shopify';
        $step->step_type = 'direct_exec';
        $step->step_order = 1;
        $step->config_json = json_encode([
            'command' => 'shopify app deploy --force --no-color',
            'working_dir' => '{context.build_dir}',
            'timeout' => 120,
        ]);
        $step->is_active = true;
        Bean::store($step);

        return $pipeline;
    }

    /**
     * Recursively remove a directory
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
