<?php
/**
 * Pipeline Studio Controller
 * Automation-focused workspace for workflows
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Studiopipeline extends BaseControls\Control {

    /**
     * Pipeline Studio main view
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Set studio preference
        Flight::setStudio('pipeline');

        // Get studio configuration
        $studioKey = 'pipeline';
        $studio = Studio::getStudioConfig($studioKey);
        $studios = Studio::getAllStudios();

        // Get pipelines
        $pipelines = Bean::find('pipelines',
            ' member_id = ? ORDER BY updated_at DESC LIMIT 10 ',
            [$this->member->id]
        );

        // Get recent runs
        $runs = Bean::find('pipelineruns',
            ' member_id = ? ORDER BY created_at DESC LIMIT 10 ',
            [$this->member->id]
        );

        // Get MCP servers
        $servers = Bean::find('mcpservers',
            ' member_id = ? ORDER BY name ASC LIMIT 5 ',
            [$this->member->id]
        );

        // Get orchestration templates
        $templates = Bean::find('studiotemplates',
            ' is_active = 1 ORDER BY name ASC LIMIT 6 '
        );

        $this->render('studio/pipeline', [
            'title' => 'Pipeline Studio',
            'studio' => $studio,
            'studios' => $studios,
            'studioKey' => $studioKey,
            'pipelines' => $pipelines,
            'runs' => $runs,
            'servers' => $servers,
            'templates' => $templates
        ]);
    }
}
