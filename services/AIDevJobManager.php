<?php
/**
 * AI Developer Job Manager
 *
 * Manages AI Developer jobs using RedBeanPHP.
 * One record per Jira issue (issue_key is unique).
 *
 * Usage:
 *   $manager = new AIDevJobManager($memberId);
 *   $job = $manager->getOrCreate($issueKey, $boardId);
 *   $manager->startRun($issueKey, $runnerJobId);
 *   $manager->complete($issueKey, $prUrl, $prNumber);
 */

namespace app\services;

use \RedBeanPHP\R as R;
use \app\Bean;

class AIDevJobManager {

    private int $memberId;
    private ?string $tenant;

    // Job statuses
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_PR_CREATED = 'pr_created';
    const STATUS_WAITING_CLARIFICATION = 'waiting_clarification';
    const STATUS_FAILED = 'failed';
    const STATUS_COMPLETE = 'complete';

    public function __construct(int $memberId, ?string $tenant = null) {
        $this->memberId = $memberId;
        $this->tenant = $tenant ?? ($_SESSION['tenant_slug'] ?? 'default');
    }

    /**
     * Get or create a job for an issue
     *
     * @param string $issueKey Jira issue key (e.g., "PROJ-123")
     * @param int $boardId Board ID
     * @param int|null $repoConnectionId Repository connection ID
     * @param string|null $cloudId Atlassian cloud ID (external ID, not a FK)
     * @return \RedBeanPHP\OODBBean
     */
    public function getOrCreate(string $issueKey, int $boardId, ?int $repoConnectionId = null, ?string $cloudId = null) {
        // Try to find existing job
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);

        if (!$job) {
            // Create new job
            $job = Bean::dispense('aidevjobs');
            $job->issue_key = $issueKey;
            $job->boards_id = $boardId;
            $job->repoconnections_id = $repoConnectionId;
            $job->cloud_uid = $cloudId;
            $job->member_id = $this->memberId;
            $job->status = self::STATUS_PENDING;
            $job->run_count = 0;
            $job->created_at = date('Y-m-d H:i:s');
            Bean::store($job);
        }

        return $job;
    }

    /**
     * Get a job by issue key
     *
     * @param string $issueKey
     * @return \RedBeanPHP\OODBBean|null
     */
    public function get(string $issueKey) {
        return Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
    }

    /**
     * Start a new run on a job
     *
     * @param string $issueKey
     * @param string $runnerJobId The runner job ID for tracking
     * @return bool
     */
    public function startRun(string $issueKey, string $runnerJobId): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->status = self::STATUS_RUNNING;
        $job->current_runner_job_uid = $runnerJobId;
        $job->started_at = date('Y-m-d H:i:s');
        $job->error_message = null;
        $job->completed_at = null;
        $job->run_count = ($job->run_count ?? 0) + 1;
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        $this->log($issueKey, 'info', 'Job run started', ['runner_job_uid' => $runnerJobId, 'run_count' => $job->run_count]);
        return true;
    }

    /**
     * Mark job as completed with PR info
     *
     * @param string $issueKey
     * @param string $prUrl PR URL
     * @param int|null $prNumber PR number
     * @param string|null $branchName Branch name
     * @param string|null $output Full output log
     * @param array|null $result Result data
     * @return bool
     */
    public function complete(string $issueKey, string $prUrl, ?int $prNumber = null, ?string $branchName = null, ?string $output = null, ?array $result = null): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->status = self::STATUS_PR_CREATED;
        $job->pr_url = $prUrl;
        $job->pr_number = $prNumber;
        $job->branch_name = $branchName;
        $job->last_output = $output;
        $job->last_result_json = $result ? json_encode($result) : null;
        $job->completed_at = date('Y-m-d H:i:s');
        $job->error_message = null;
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        $this->log($issueKey, 'info', 'PR created', ['pr_url' => $prUrl, 'pr_number' => $prNumber]);
        return true;
    }

    /**
     * Mark job as failed
     *
     * @param string $issueKey
     * @param string $error Error message
     * @param string|null $output Output log
     * @return bool
     */
    public function fail(string $issueKey, string $error, ?string $output = null): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->status = self::STATUS_FAILED;
        $job->error_message = $error;
        $job->last_output = $output;
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        $this->log($issueKey, 'error', 'Job failed', ['error' => $error]);
        return true;
    }

    /**
     * Set job as waiting for clarification
     *
     * @param string $issueKey
     * @param string $commentId Jira comment ID with questions
     * @param array $questions List of clarification questions
     * @return bool
     */
    public function waitForClarification(string $issueKey, string $commentId, array $questions): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->status = self::STATUS_WAITING_CLARIFICATION;
        $job->clarification_comment_uid = $commentId;
        $job->clarification_questions = json_encode($questions);
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        $this->log($issueKey, 'info', 'Waiting for clarification', ['question_count' => count($questions)]);
        return true;
    }

    /**
     * Mark a job as fully complete (e.g., PR merged, ticket done)
     *
     * @param string $issueKey
     * @return bool
     */
    public function markComplete(string $issueKey): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->status = self::STATUS_COMPLETE;
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        $this->log($issueKey, 'info', 'Job marked as complete');
        return true;
    }

    /**
     * Update branch name for a job
     *
     * @param string $issueKey
     * @param string $branchName
     * @return bool
     */
    public function setBranch(string $issueKey, string $branchName): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->branch_name = $branchName;
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);
        return true;
    }

    /**
     * Check if a job is currently running
     *
     * @param string $issueKey
     * @return bool
     */
    public function isRunning(string $issueKey): bool {
        $job = $this->get($issueKey);
        return $job && $job->status === self::STATUS_RUNNING;
    }

    /**
     * Get all jobs for this member
     *
     * @param int $limit
     * @return array
     */
    public function getAll(int $limit = 50): array {
        $jobs = Bean::find('aidevjobs', 'member_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT ?', [$this->memberId, $limit]);
        return array_map(fn($job) => $this->formatJob($job), array_values($jobs));
    }

    /**
     * Get active jobs for this member (running, pending, waiting)
     * Excludes stale jobs that have been running for more than 30 minutes without updates
     *
     * @return array
     */
    public function getActive(): array {
        // Stale threshold: jobs running for more than 30 minutes are considered abandoned
        $staleThreshold = date('Y-m-d H:i:s', strtotime('-30 minutes'));

        $jobs = Bean::find('aidevjobs',
            'member_id = ? AND status IN (?, ?, ?) AND (
                status != ? OR updated_at > ? OR started_at > ?
            ) ORDER BY started_at DESC',
            [
                $this->memberId,
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_WAITING_CLARIFICATION,
                self::STATUS_RUNNING,  // Only apply staleness check to running jobs
                $staleThreshold,
                $staleThreshold
            ]
        );
        return array_map(fn($job) => $this->formatJob($job), array_values($jobs));
    }

    /**
     * Mark stale running jobs as failed
     * Jobs running for more than the threshold without updates are considered abandoned
     *
     * @param int $hoursThreshold Hours before a running job is considered stale (default: 2)
     * @return int Number of jobs marked as failed
     */
    public function cleanupStaleJobs(int $hoursThreshold = 2): int {
        $staleThreshold = date('Y-m-d H:i:s', strtotime("-{$hoursThreshold} hours"));

        $staleJobs = Bean::find('aidevjobs',
            'member_id = ? AND status = ? AND updated_at < ? AND started_at < ?',
            [
                $this->memberId,
                self::STATUS_RUNNING,
                $staleThreshold,
                $staleThreshold
            ]
        );

        $count = 0;
        foreach ($staleJobs as $job) {
            $job->status = self::STATUS_FAILED;
            $job->error_message = 'Job timed out - marked as stale after ' . $hoursThreshold . ' hours';
            $job->updated_at = date('Y-m-d H:i:s');
            Bean::store($job);
            $count++;
        }

        return $count;
    }

    /**
     * Delete a job
     *
     * @param string $issueKey
     * @return bool
     */
    public function delete(string $issueKey): bool {
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if ($job) {
            Bean::trash($job);
            return true;
        }
        return false;
    }

    /**
     * Add a log entry for a job
     *
     * @param string $issueKey
     * @param string $level Log level (info, warning, error)
     * @param string $message
     * @param array|null $context
     */
    public function log(string $issueKey, string $level, string $message, ?array $context = null): void {
        $log = Bean::dispense('aidevjoblogs');
        $log->issue_key = $issueKey;
        $log->log_level = $level;
        $log->message = $message;
        $log->context_json = $context ? json_encode($context) : null;
        $log->created_at = date('Y-m-d H:i:s');
        Bean::store($log);
    }

    /**
     * Get logs for a job
     *
     * @param string $issueKey
     * @param int $limit
     * @return array
     */
    public function getLogs(string $issueKey, int $limit = 100): array {
        $logs = Bean::find('aidevjoblogs', 'issue_key = ? ORDER BY created_at ASC LIMIT ?', [$issueKey, $limit]);
        return array_map(function($log) {
            return [
                'id' => $log->id,
                'level' => $log->log_level,
                'message' => $log->message,
                'context' => $log->context_json ? json_decode($log->context_json, true) : null,
                'created_at' => $log->created_at
            ];
        }, array_values($logs));
    }

    /**
     * Get the base path for aoe-php storage
     * Uses a consistent path that works for both CLI and web contexts
     *
     * @return string
     */
    private function getAoeStoragePath(): string {
        // Check config first
        $configPath = \Flight::get('aoe.storage_path');
        if ($configPath && is_dir($configPath)) {
            return $configPath;
        }

        // Default to /tmp/.aoe-php (accessible by both CLI and web)
        $defaultPath = '/tmp/.aoe-php';
        if (is_dir($defaultPath)) {
            return $defaultPath;
        }

        // Fallback to project-relative path
        return dirname(__DIR__) . '/data/aoe';
    }

    /**
     * Check if a session is actually running for an issue using aoe-php
     *
     * @param string $issueKey
     * @return bool True if session is running, false otherwise
     */
    private function isSessionActuallyRunning(string $issueKey): bool {
        try {
            $aoePath = dirname(__DIR__) . '/../aoe-php/vendor/autoload.php';
            if (!file_exists($aoePath)) {
                return false;
            }
            require_once $aoePath;

            $tenantSlug = $this->tenant ?: 'default';
            \Aoe\Tenant\TenantContext::set($tenantSlug);

            // Use consistent storage path that works for both CLI and web
            $basePath = $this->getAoeStoragePath();
            $storage = new \Aoe\Session\Storage($tenantSlug, $basePath);
            $tmux = new \Aoe\Tmux\TmuxService($tenantSlug);

            // Find session by reference (issue key)
            foreach ($storage->loadAll() as $session) {
                if ($session->reference === $issueKey || $session->title === $issueKey) {
                    $tmuxName = $session->getTmuxName();
                    if ($tmux->sessionExistsByName($tmuxName)) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            // If we can't check, assume not running to be safe
        }

        return false;
    }

    /**
     * Get the real status of a job, checking aoe-php for running jobs
     *
     * @param \RedBeanPHP\OODBBean $job
     * @return string The real status
     */
    private function getRealStatus($job): string {
        // Only check running jobs
        if ($job->status !== self::STATUS_RUNNING) {
            return $job->status;
        }

        // Check if session is actually running
        if ($this->isSessionActuallyRunning($job->issue_key)) {
            return self::STATUS_RUNNING;
        }

        // Session not running - mark as failed in database
        $job->status = self::STATUS_FAILED;
        $job->error_message = 'Session ended unexpectedly';
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        return self::STATUS_FAILED;
    }

    /**
     * Format a job bean to array
     *
     * @param \RedBeanPHP\OODBBean $job
     * @return array
     */
    public function formatJob($job): array {
        // Get real status (checks aoe-php for running jobs)
        $realStatus = $this->getRealStatus($job);

        return [
            'id' => $job->id,
            'issue_key' => $job->issue_key,
            'board_id' => $job->boards_id,  // API compatibility
            'repo_connection_id' => $job->repoconnections_id,  // API compatibility
            'cloud_uid' => $job->cloud_uid,
            'status' => $realStatus,
            'current_runner_job_uid' => $job->current_runner_job_uid,
            'branch_name' => $job->branch_name,
            'pr_url' => $job->pr_url,
            'pr_number' => $job->pr_number,
            'clarification_comment_uid' => $job->clarification_comment_uid,
            'clarification_questions' => $job->clarification_questions ? json_decode($job->clarification_questions, true) : [],
            'error_message' => $job->error_message,
            'run_count' => $job->run_count ?? 0,
            'last_output' => $job->last_output,
            'last_result' => $job->last_result_json ? json_decode($job->last_result_json, true) : null,
            'files_changed' => $job->files_changed ? json_decode($job->files_changed, true) : [],
            'commit_sha' => $job->commit_sha,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at
        ];
    }
}
