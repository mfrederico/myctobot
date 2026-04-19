<?php
/**
 * DEPRECATED - Redirect stub for Phase 2 UX consolidation.
 *
 * All orchestration functionality has been merged into the unified
 * Pipelines controller. This stub redirects legacy URLs so that
 * bookmarks, back-buttons, and external links still work.
 *
 * @see controls/Pipelines.php
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Orchestration extends BaseControls\Control {

    /**
     * /orchestration -> /pipelines?tab=templates
     */
    public function index() {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/templates -> /pipelines?tab=templates
     */
    public function templates() {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/template/{id} -> /pipelines?tab=templates
     */
    public function template($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/wizard/{templateId} -> /pipelines/templatewizard/{templateId}
     */
    public function wizard($params = []) {
        $templateId = (int) ($params[0] ?? 0);
        if ($templateId) {
            Flight::redirect('/pipelines/templatewizard/' . $templateId);
        } else {
            Flight::redirect('/pipelines?tab=templates');
        }
    }

    /**
     * /orchestration/wizardstep/{id} -> /pipelines/templatewizardstep/{id}
     */
    public function wizardstep($params = []) {
        $id = (int) ($params[0] ?? 0);
        if ($id) {
            Flight::redirect('/pipelines/templatewizardstep/' . $id);
        } else {
            Flight::redirect('/pipelines?tab=templates');
        }
    }

    /**
     * /orchestration/finalize/{id} -> /pipelines/templatefinalize/{id}
     */
    public function finalize($params = []) {
        $id = (int) ($params[0] ?? 0);
        if ($id) {
            Flight::redirect('/pipelines/templatefinalize/' . $id);
        } else {
            Flight::redirect('/pipelines?tab=templates');
        }
    }

    /**
     * /orchestration/project/{id} -> /pipelines/edit/{pipeline_id} if project has a pipeline,
     * otherwise /pipelines?tab=templates
     */
    public function project($params = []) {
        $id = (int) ($params[0] ?? 0);
        if ($id) {
            $project = Bean::load('studioprojects', $id);
            if ($project && $project->pipelines_id) {
                Flight::redirect('/pipelines/edit/' . $project->pipelines_id);
                return;
            }
        }
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/createproject -> /pipelines?tab=templates
     */
    public function createproject() {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/deleteproject -> /pipelines?tab=templates
     */
    public function deleteproject($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/execute -> /pipelines?tab=templates
     */
    public function execute($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/pause -> /pipelines?tab=templates
     */
    public function pause($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/resume -> /pipelines?tab=templates
     */
    public function resume($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/delete -> /pipelines?tab=templates
     */
    public function delete($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/history -> /pipelines?tab=templates
     */
    public function history($params = []) {
        Flight::redirect('/pipelines?tab=templates');
    }

    /**
     * /orchestration/aiassist -> /pipelines?tab=templates
     */
    public function aiassist() {
        Flight::redirect('/pipelines?tab=templates');
    }
}
