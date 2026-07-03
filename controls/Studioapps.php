<?php
/**
 * App Studio Controller
 * Microservice-focused workspace for building tenant apps
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\TenantAppService;

class Studioapps extends BaseControls\Control {

    /**
     * App Studio main view
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Set studio preference
        Flight::setStudio('apps');

        // Get studio configuration
        $studioKey = 'apps';
        $studio = Studio::getStudioConfig($studioKey);
        $studios = Studio::getAllStudios();

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $appService = new TenantAppService(Flight::get('log'));

        // Get all apps for this workspace
        $apps = $appService->listApps($workspace);

        // Get pipeline names for display
        $pipelineIds = array_filter(array_column($apps, 'pipeline_id'));
        $pipelineNames = [];
        if (!empty($pipelineIds)) {
            // Cast to int to prevent second-order SQLi via the IN() list (LOW).
            $pipelineIds = array_map('intval', $pipelineIds);
            $pipelines = Bean::find('pipelines', ' id IN (' . implode(',', $pipelineIds) . ') ');
            foreach ($pipelines as $p) {
                $pipelineNames[$p->id] = $p->name;
            }
        }

        // Add pipeline names and check status
        $runningCount = 0;
        $stoppedCount = 0;
        foreach ($apps as &$app) {
            $app['pipeline_name'] = $pipelineNames[$app['pipeline_id']] ?? null;
            if ($app['status'] === 'running') {
                $runningCount++;
            } else {
                $stoppedCount++;
            }
        }

        // Get available pipelines for quick create
        $pipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');
        $pipelineList = [];
        foreach ($pipelines as $p) {
            $pipelineList[] = [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
            ];
        }

        // Get recent pipeline runs (from apps)
        $recentRuns = Bean::find('pipelineruns',
            ' 1 ORDER BY created_at DESC LIMIT 10 '
        );

        $this->render('studio/apps', [
            'title' => 'App Studio',
            'studio' => $studio,
            'studios' => $studios,
            'studioKey' => $studioKey,
            'apps' => $apps,
            'pipelines' => $pipelineList,
            'recentRuns' => $recentRuns,
            'runningCount' => $runningCount,
            'stoppedCount' => $stoppedCount,
            'totalApps' => count($apps),
        ]);
    }
}
