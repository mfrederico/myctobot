<?php
/**
 * Analysis Status Service
 * Tracks progress of long-running analysis jobs via JSON files
 * Status is stored per-user to ensure isolation
 */

namespace app\services;

class AnalysisStatusService {

    private static string $statusDir = __DIR__ . '/../storage/analysis_status';

    /**
     * Initialize storage directory for a member
     */
    private static function ensureDir(int $memberId): string {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Generate an opaque job ID (no member ID exposed)
     * Member ID is stored inside the status file only
     */
    private static function generateJobId(): string {
        // Opaque format: random bytes only to prevent enumeration attacks
        return bin2hex(random_bytes(16));
    }

    /**
     * Get status file path for a job
     */
    private static function getStatusPath(int $memberId, string $jobId): string {
        $dir = self::ensureDir($memberId);
        // Use hash of jobId for filename to prevent directory traversal
        $safeFilename = hash('sha256', $jobId) . '.json';
        return $dir . '/' . $safeFilename;
    }

    /**
     * Create a new analysis job
     */
    public static function createJob(int $memberId, int $boardId, string $boardName): string {
        $jobId = self::generateJobId();

        $status = [
            'job_id' => $jobId,
            'member_id' => $memberId,
            'board_id' => $boardId,
            'board_name' => $boardName,
            'status' => 'pending',
            'progress' => 0,
            'current_step' => 'Initializing...',
            'steps_completed' => [],
            'error' => null,
            'analysis_id' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'completed_at' => null
        ];

        file_put_contents(self::getStatusPath($memberId, $jobId), json_encode($status, JSON_PRETTY_PRINT));

        return $jobId;
    }

    /**
     * Update job status
     * @param int $memberId Member ID who owns this job
     * @param string $jobId The job ID
     * @param string $step Current step description
     * @param int $progress Progress percentage (0-100)
     * @param string $status Job status (running, pending, etc.)
     */
    public static function updateStatus(int $memberId, string $jobId, string $step, int $progress, string $status = 'running'): void {
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = $status;
        $data['progress'] = $progress;
        $data['current_step'] = $step;
        $data['steps_completed'][] = [
            'step' => $step,
            'progress' => $progress,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $data['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Mark job as complete
     * @param int $memberId Member ID who owns this job
     * @param string $jobId The job ID
     * @param int $analysisId The resulting analysis ID
     */
    public static function complete(int $memberId, string $jobId, int $analysisId): void {
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = 'complete';
        $data['progress'] = 100;
        $data['current_step'] = 'Analysis complete!';
        $data['analysis_id'] = $analysisId;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['completed_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Mark job as failed
     * @param int $memberId Member ID who owns this job
     * @param string $jobId The job ID
     * @param string $error Error message
     */
    public static function fail(int $memberId, string $jobId, string $error): void {
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = 'failed';
        $data['error'] = $error;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['completed_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get job status (with ownership verification)
     * Returns null if job doesn't exist or doesn't belong to the member
     * @param string $jobId The opaque job ID
     * @param int $requestingMemberId Member ID requesting the status
     */
    public static function getStatus(string $jobId, int $requestingMemberId): ?array {
        // Look for the job in the requesting member's directory
        $path = self::getStatusPath($requestingMemberId, $jobId);
        if (!file_exists($path)) {
            return null; // Job doesn't exist for this user
        }

        $data = json_decode(file_get_contents($path), true);

        // Verify member ID in data matches (defense in depth)
        if (($data['member_id'] ?? null) !== $requestingMemberId) {
            return null;
        }

        return $data;
    }

    /**
     * Get active jobs for a member
     */
    public static function getActiveJobs(int $memberId): array {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return [];
        }

        $jobs = [];
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && in_array($data['status'], ['pending', 'running'])) {
                $jobs[] = $data;
            }
        }

        return $jobs;
    }

    /**
     * Clean up old status files for a member (older than 1 hour)
     */
    public static function cleanup(int $memberId): int {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            if (filemtime($file) < time() - 3600) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Clean up all old status files (for cron)
     */
    public static function cleanupAll(): int {
        if (!is_dir(self::$statusDir)) {
            return 0;
        }

        $count = 0;
        $memberDirs = glob(self::$statusDir . '/member_*', GLOB_ONLYDIR);

        foreach ($memberDirs as $dir) {
            $files = glob($dir . '/*.json');
            foreach ($files as $file) {
                if (filemtime($file) < time() - 3600) {
                    unlink($file);
                    $count++;
                }
            }
            // Remove empty directories
            if (count(glob($dir . '/*')) === 0) {
                rmdir($dir);
            }
        }

        return $count;
    }
}
