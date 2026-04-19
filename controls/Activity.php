<?php
/**
 * Activity Controller
 * Unified timeline of pipeline runs and AI developer jobs
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Activity extends BaseControls\Control {

    /**
     * Unified activity timeline
     * Supports filters: ?type=pipeline|aidev&status=running|completed|failed&period=today|week|month
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Get filter params
        $typeFilter = $this->getParam('type', 'all');
        $statusFilter = $this->getParam('status', 'all');
        $periodFilter = $this->getParam('period', 'week');

        // Calculate date range
        $dateFrom = match($periodFilter) {
            'today' => date('Y-m-d 00:00:00'),
            'week' => date('Y-m-d 00:00:00', strtotime('-7 days')),
            'month' => date('Y-m-d 00:00:00', strtotime('-30 days')),
            default => date('Y-m-d 00:00:00', strtotime('-7 days')),
        };

        $activities = [];

        // Pipeline runs
        if ($typeFilter === 'all' || $typeFilter === 'pipeline') {
            $sql = ' created_at >= ? ';
            $params = [$dateFrom];

            if ($statusFilter !== 'all') {
                $sql .= ' AND status = ? ';
                $params[] = $statusFilter;
            }

            $sql .= ' ORDER BY created_at DESC LIMIT 100 ';
            $runs = Bean::find('pipelineruns', $sql, $params);

            foreach ($runs as $run) {
                $pipeline = Bean::load('pipelines', (int) $run->pipelines_id);
                $activities[] = [
                    'type' => 'pipeline_run',
                    'id' => (int) $run->id,
                    'title' => $pipeline && $pipeline->id ? $pipeline->name : 'Pipeline #' . $run->pipelines_id,
                    'subtitle' => 'Run #' . $run->id . ($run->run_uid ? ' (' . $run->run_uid . ')' : ''),
                    'status' => $run->status,
                    'trigger_source' => $run->trigger_source,
                    'detail_url' => '/pipelines/viewrun/' . $run->id,
                    'error_message' => $run->error_message,
                    'created_at' => $run->created_at,
                    'completed_at' => $run->completed_at,
                ];
            }
        }

        // AI Dev jobs
        if ($typeFilter === 'all' || $typeFilter === 'aidev') {
            $sql = ' created_at >= ? ';
            $params = [$dateFrom];

            if ($statusFilter !== 'all') {
                $sql .= ' AND status = ? ';
                $params[] = $statusFilter;
            }

            $sql .= ' ORDER BY created_at DESC LIMIT 100 ';
            $jobs = Bean::find('aidevjobs', $sql, $params);

            foreach ($jobs as $job) {
                $activities[] = [
                    'type' => 'aidev_job',
                    'id' => (int) $job->id,
                    'title' => $job->issue_key ?: 'Job #' . $job->id,
                    'subtitle' => $job->summary ?? '',
                    'status' => $job->status,
                    'trigger_source' => 'ai_dev',
                    'detail_url' => '/jobs/view/' . $job->id,
                    'error_message' => $job->error_message ?? null,
                    'created_at' => $job->created_at,
                    'completed_at' => $job->completed_at ?? null,
                ];
            }
        }

        // Sort all activities by created_at DESC
        usort($activities, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $this->viewData['title'] = 'Activity';
        $this->viewData['activities'] = $activities;
        $this->viewData['typeFilter'] = $typeFilter;
        $this->viewData['statusFilter'] = $statusFilter;
        $this->viewData['periodFilter'] = $periodFilter;

        $this->render('activity/index', $this->viewData);
    }
}
