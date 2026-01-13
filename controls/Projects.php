<?php
/**
 * Projects Controller
 * Manages CTO Projects dashboard and detail views
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\UserDatabaseService;
use \app\services\CompletionDetector;

require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/CompletionDetector.php';

class Projects extends BaseControls\Control {

    private $userDbConnected = false;

    /**
     * Initialize user database connection
     */
    private function initUserDb() {
        if (!$this->userDbConnected && $this->member && !empty($this->member->ceobot_db)) {
            try {
                UserDatabaseService::connect($this->member->id);
                $this->userDbConnected = true;
            } catch (\Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDbConnected;
    }

    /**
     * List all projects
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        // Get filter
        $status = $this->getParam('status');

        // Build query
        $where = '1=1';
        $params = [];

        if ($status) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        // Get projects
        $projects = Bean::find('ctoprojects', $where . ' ORDER BY created_at DESC', $params);

        // Get status counts
        $statusCounts = [
            'planning' => Bean::count('ctoprojects', 'status = ?', ['planning']),
            'in_progress' => Bean::count('ctoprojects', 'status = ?', ['in_progress']),
            'blocked' => Bean::count('ctoprojects', 'status = ?', ['blocked']),
            'completed' => Bean::count('ctoprojects', 'status = ?', ['completed']),
        ];

        // Enrich projects with epic counts
        foreach ($projects as $project) {
            $project->epic_count = Bean::count('ctoepics', 'project_id = ?', [$project->id]);
            $project->story_count = Bean::count('ctostories',
                'epic_id IN (SELECT id FROM ctoepics WHERE project_id = ?)',
                [$project->id]
            );
        }

        $this->render('projects/index', [
            'title' => 'CTO Projects',
            'projects' => $projects,
            'status' => $status,
            'statusCounts' => $statusCounts
        ]);
    }

    /**
     * View project detail
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/projects');
            return;
        }

        // Get project ID
        $projectId = $params['operation']->name ?? $this->getParam('id');
        if (!$projectId) {
            $this->flash('error', 'No project specified');
            Flight::redirect('/projects');
            return;
        }

        // Find project
        $project = is_numeric($projectId)
            ? Bean::load('ctoprojects', $projectId)
            : Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);

        if (!$project || !$project->id) {
            $this->flash('error', 'Project not found');
            Flight::redirect('/projects');
            return;
        }

        // Get epics with their stories
        $epics = Bean::find('ctoepics', 'project_id = ? ORDER BY sequence ASC', [$project->id]);
        $epicsWithStories = [];

        foreach ($epics as $epic) {
            $stories = Bean::find('ctostories', 'epic_id = ? ORDER BY sequence ASC', [$epic->id]);
            $epicsWithStories[] = [
                'epic' => $epic,
                'stories' => $stories
            ];
        }

        // Get directive info
        $directive = null;
        if ($project->directive_id) {
            $directive = Bean::load('ceodirectives', $project->directive_id);
        }

        // Get progress using CompletionDetector
        $detector = new CompletionDetector($this->member->id);
        $progress = $detector->getProjectProgress($project->id);

        // Parse JSON fields
        $goals = json_decode($project->goals, true) ?: [];
        $risks = json_decode($project->risk_assessment, true) ?: [];
        $techStack = json_decode($project->tech_stack, true) ?: [];

        $this->render('projects/view', [
            'title' => 'Project: ' . $project->name,
            'project' => $project,
            'epicsWithStories' => $epicsWithStories,
            'directive' => $directive,
            'progress' => $progress,
            'goals' => $goals,
            'risks' => $risks,
            'techStack' => $techStack
        ]);
    }

    /**
     * Pause project execution
     */
    public function pause($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized');
            return;
        }

        $projectId = $params['operation']->name ?? $this->getParam('id');
        $project = is_numeric($projectId)
            ? Bean::load('ctoprojects', $projectId)
            : Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);

        if (!$project || !$project->id) {
            Flight::jsonError('Project not found');
            return;
        }

        if ($project->status !== 'in_progress') {
            Flight::jsonError('Can only pause in-progress projects');
            return;
        }

        $project->status = 'blocked';
        $project->updated_at = date('Y-m-d H:i:s');
        Bean::store($project);

        $this->logger->info('Project paused', [
            'project_id' => $project->project_id,
            'member_id' => $this->member->id
        ]);

        Flight::jsonSuccess(['project_id' => $project->project_id], 'Project paused');
    }

    /**
     * Resume project execution
     */
    public function resume($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized');
            return;
        }

        $projectId = $params['operation']->name ?? $this->getParam('id');
        $project = is_numeric($projectId)
            ? Bean::load('ctoprojects', $projectId)
            : Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);

        if (!$project || !$project->id) {
            Flight::jsonError('Project not found');
            return;
        }

        if ($project->status !== 'blocked') {
            Flight::jsonError('Can only resume blocked projects');
            return;
        }

        $project->status = 'in_progress';
        $project->updated_at = date('Y-m-d H:i:s');
        Bean::store($project);

        $this->logger->info('Project resumed', [
            'project_id' => $project->project_id,
            'member_id' => $this->member->id
        ]);

        Flight::jsonSuccess(['project_id' => $project->project_id], 'Project resumed');
    }

    /**
     * Generate project report (JSON)
     */
    public function report($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized');
            return;
        }

        $projectId = $params['operation']->name ?? $this->getParam('id');
        $project = is_numeric($projectId)
            ? Bean::load('ctoprojects', $projectId)
            : Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);

        if (!$project || !$project->id) {
            Flight::jsonError('Project not found');
            return;
        }

        $detector = new CompletionDetector($this->member->id);
        $progress = $detector->getProjectProgress($project->id);

        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'project' => [
                'id' => $project->project_id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'completion_percentage' => $project->completion_percentage,
                'created_at' => $project->created_at,
                'goals' => json_decode($project->goals, true),
                'tech_stack' => json_decode($project->tech_stack, true),
                'estimated_effort' => $project->estimated_effort
            ],
            'progress' => $progress,
            'risks' => json_decode($project->risk_assessment, true)
        ];

        Flight::jsonSuccess($report, 'Report generated');
    }
}
