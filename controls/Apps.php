<?php
/**
 * Apps Controller
 *
 * Manages tenant apps - standalone HTTP server services that can expose
 * pipelines as endpoints or run custom logic.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\TenantAppService;
use \app\services\TenantAppPortManager;

class Apps extends BaseControls\Control
{
    private TenantAppService $appService;

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
    }

    /**
     * List all apps
     */
    public function index()
    {
        if (!$this->requireLogin()) return;

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $apps = $this->appService->listApps($workspace);

        // Get pipeline names for display
        $pipelineIds = array_filter(array_column($apps, 'pipeline_id'));
        $pipelineNames = [];
        if (!empty($pipelineIds)) {
            $pipelines = Bean::find('pipelines', ' id IN (' . implode(',', $pipelineIds) . ') ');
            foreach ($pipelines as $p) {
                $pipelineNames[$p->id] = $p->name;
            }
        }

        // Add pipeline names to apps
        foreach ($apps as &$app) {
            $app['pipeline_name'] = $pipelineNames[$app['pipeline_id']] ?? null;
        }

        $this->viewData['title'] = 'Tenant Apps';
        $this->viewData['apps'] = $apps;
        $this->viewData['authTypes'] = self::AUTH_TYPES;
        $this->viewData['appTypes'] = self::APP_TYPES;

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

        $this->viewData['title'] = $app ? 'Edit App: ' . $app->name : 'Create App';
        $this->viewData['app'] = $app;
        $this->viewData['pipelines'] = $pipelineList;
        $this->viewData['authTypes'] = self::AUTH_TYPES;
        $this->viewData['appTypes'] = self::APP_TYPES;

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

        $data = [
            'name' => trim($this->getParam('name', '')),
            'slug' => trim($this->getParam('slug', '')),
            'description' => trim($this->getParam('description', '')),
            'app_type' => $this->getParam('app_type', 'pipeline'),
            'pipeline_id' => (int)$this->getParam('pipeline_id', 0) ?: null,
            'auth_type' => $this->getParam('auth_type', 'api_key'),
            'api_key' => trim($this->getParam('api_key', '')),
            'expose_mcp' => (bool)$this->getParam('expose_mcp', false),
            'auto_restart' => (bool)$this->getParam('auto_restart', false),
            'health_check_path' => trim($this->getParam('health_check_path', '/health')),
            'member_id' => $this->member->id,
        ];

        if (empty($data['name'])) {
            $this->flash('error', 'App name is required');
            Flight::redirect('/apps/form');
            return;
        }

        if ($data['app_type'] === 'pipeline' && empty($data['pipeline_id'])) {
            $this->flash('error', 'Pipeline is required for pipeline-type apps');
            Flight::redirect('/apps/form');
            return;
        }

        try {
            $app = $this->appService->create($workspace, $data);
            $this->flash('success', 'App created successfully');
            Flight::redirect('/apps/form/' . $app->id);
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

        $data = [
            'name' => trim($this->getParam('name', '')),
            'description' => trim($this->getParam('description', '')),
            'app_type' => $this->getParam('app_type', 'pipeline'),
            'pipelines_id' => (int)$this->getParam('pipeline_id', 0) ?: null,
            'auth_type' => $this->getParam('auth_type', 'api_key'),
            'api_key' => trim($this->getParam('api_key', '')),
            'expose_mcp' => (bool)$this->getParam('expose_mcp', false),
            'auto_restart' => (bool)$this->getParam('auto_restart', false),
            'health_check_path' => trim($this->getParam('health_check_path', '/health')),
        ];

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
     * Delete app
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
            $this->appService->delete($id);
            Flight::jsonSuccess(null, 'App deleted successfully');
        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

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
     * Get port statistics
     */
    public function ports()
    {
        if (!$this->requireLogin()) return;

        $portManager = new TenantAppPortManager();
        $stats = $portManager->getStats();
        Flight::jsonSuccess($stats);
    }
}
