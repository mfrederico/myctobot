<?php
/**
 * Runners Controller
 * Handles runner management and job execution on runners
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\Bean;
use \app\services\RunnerService;
use \app\services\RunnerRouter;

require_once __DIR__ . '/../services/RunnerService.php';
require_once __DIR__ . '/../services/RunnerRouter.php';

class Runners extends BaseControls\Control {

    /**
     * List available runners for the current user
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        // Get runners available to this member
        $memberRunners = RunnerService::getMemberRunners($this->member->id);

        if (empty($memberRunners)) {
            $memberRunners = RunnerService::getDefaultRunners();
        }

        // Add health status
        foreach ($memberRunners as &$runner) {
            $runner['stats'] = RunnerService::getRunnerStats($runner['id']);
            $runner['capabilities'] = json_decode($runner['capabilities'] ?? '[]', true);
        }

        $this->json([
            'success' => true,
            'runners' => $memberRunners
        ]);
    }

    /**
     * Callback endpoint for runner job completion
     */
    public function callback($params = []) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || empty($data['job_uid'])) {
            $this->json(['success' => false, 'error' => 'Invalid callback data']);
            return;
        }

        $jobId = $data['job_uid'];
        $status = $data['status'] ?? 'unknown';

        if ($status === 'completed') {
            RunnerRouter::updateJobResult($jobId, $data['result'] ?? []);
            $this->logger->info('Runner job completed', ['job_uid' => $jobId]);
        } elseif ($status === 'failed') {
            RunnerRouter::updateJobStatus($jobId, 'failed', $data['error'] ?? 'Unknown error');
            $this->logger->error('Runner job failed', ['job_uid' => $jobId, 'error' => $data['error'] ?? '']);
        }

        $this->json(['success' => true]);
    }

    /**
     * Get runner job status
     */
    public function jobstatus($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $this->opId() ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        $status = RunnerRouter::getJobStatus($jobId);

        if (!$status) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        // Verify ownership
        if ($status['member_id'] != $this->member->id) {
            $this->json(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $this->json([
            'success' => true,
            'status' => $status
        ]);
    }

    /**
     * Get runner job output
     */
    public function joboutput($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $this->opId() ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        // Verify ownership first
        $job = RunnerRouter::getJobStatus($jobId);
        if (!$job || $job['member_id'] != $this->member->id) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $output = RunnerRouter::getJobOutput($jobId);

        $this->json([
            'success' => true,
            'output' => $output
        ]);
    }
}
