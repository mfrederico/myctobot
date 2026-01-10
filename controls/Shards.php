<?php
/**
 * Shards Controller
 * Handles shard management and job execution on shards
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\Bean;
use \app\services\ShardService;
use \app\services\ShardRouter;

require_once __DIR__ . '/../services/ShardService.php';
require_once __DIR__ . '/../services/ShardRouter.php';
require_once __DIR__ . '/../lib/Bean.php';

class Shards extends BaseControls\Control {

    /**
     * List available shards for the current user
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        // Get shards available to this member
        $memberShards = ShardService::getMemberShards($this->member->id);

        if (empty($memberShards)) {
            $memberShards = ShardService::getDefaultShards();
        }

        // Add health status
        foreach ($memberShards as &$shard) {
            $shard['stats'] = ShardService::getShardStats($shard['id']);
            $shard['capabilities'] = json_decode($shard['capabilities'] ?? '[]', true);
        }

        $this->json([
            'success' => true,
            'shards' => $memberShards
        ]);
    }

    /**
     * Callback endpoint for shard job completion
     */
    public function callback($params = []) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || empty($data['job_id'])) {
            $this->json(['success' => false, 'error' => 'Invalid callback data']);
            return;
        }

        $jobId = $data['job_id'];
        $status = $data['status'] ?? 'unknown';

        if ($status === 'completed') {
            ShardRouter::updateJobResult($jobId, $data['result'] ?? []);
            $this->logger->info('Shard job completed', ['job_id' => $jobId]);
        } elseif ($status === 'failed') {
            ShardRouter::updateJobStatus($jobId, 'failed', $data['error'] ?? 'Unknown error');
            $this->logger->error('Shard job failed', ['job_id' => $jobId, 'error' => $data['error'] ?? '']);
        }

        $this->json(['success' => true]);
    }

    /**
     * Get shard job status
     */
    public function jobstatus($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $params['operation']->name ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        $status = ShardRouter::getJobStatus($jobId);

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
     * Get shard job output
     */
    public function joboutput($params = []) {
        if (!$this->requireLogin()) return;

        $jobId = $params['operation']->name ?? '';
        if (empty($jobId)) {
            $this->json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        // Verify ownership first
        $job = ShardRouter::getJobStatus($jobId);
        if (!$job || $job['member_id'] != $this->member->id) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $output = ShardRouter::getJobOutput($jobId);

        $this->json([
            'success' => true,
            'output' => $output
        ]);
    }
}
