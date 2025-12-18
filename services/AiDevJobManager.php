<?php
/**
 * AI Developer Job Manager
 *
 * Manages AI Developer jobs using RedBeanPHP with proper conventions.
 * One record per Jira issue (issue_key is unique).
 *
 * Bean type: aiDevJob → table: ai_dev_job
 * Properties use camelCase, database uses snake_case (auto-converted)
 *
 * Usage:
 *   $manager = new AiDevJobManager($memberId);
 *   $job = $manager->getOrCreate($issueKey, $boardId);
 *   $manager->startRun($issueKey, $shardJobId);
 *   $manager->complete($issueKey, $prUrl, $prNumber);
 */

namespace app\services;

use \RedBeanPHP\R as R;

class AiDevJobManager {

    private int $memberId;

    // Job statuses
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_PR_CREATED = 'pr_created';
    const STATUS_WAITING_CLARIFICATION = 'waiting_clarification';
    const STATUS_FAILED = 'failed';
    const STATUS_COMPLETE = 'complete';

    public function __construct(int $memberId) {
        $this->memberId = $memberId;
    }

    /**
     * Get or create a job for an issue
     *
     * @param string $issueKey Jira issue key (e.g., "PROJ-123")
     * @param int $boardId Board ID
     * @param int|null $repoConnectionId Repository connection ID
     * @param string|null $cloudId Atlassian cloud ID
     * @return \RedBeanPHP\OODBBean
     */
    public function getOrCreate(string $issueKey, int $boardId, ?int $repoConnectionId = null, ?string $cloudId = null) {
        return UserDatabase::with($this->memberId, function() use ($issueKey, $boardId, $repoConnectionId, $cloudId) {
            // Try to find existing job
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);

            if (!$job) {
                // Create new job
                $job = R::dispense('ai_dev_job');
                $job->issue_key = $issueKey;
                $job->board_id = $boardId;
                $job->repo_connection_id = $repoConnectionId;
                $job->cloud_id = $cloudId;
                $job->status = self::STATUS_PENDING;
                $job->run_count = 0;
                $job->created_at = date('Y-m-d H:i:s');
                R::store($job);
            }

            return $job;
        });
    }

    /**
     * Get a job by issue key
     *
     * @param string $issueKey
     * @return \RedBeanPHP\OODBBean|null
     */
    public function get(string $issueKey) {
        return UserDatabase::with($this->memberId, function() use ($issueKey) {
            return R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
        });
    }

    /**
     * Start a new run on a job
     *
     * @param string $issueKey
     * @param string $shardJobId The shard job ID for tracking
     * @return bool
     */
    public function startRun(string $issueKey, string $shardJobId): bool {
        return UserDatabase::with($this->memberId, function() use ($issueKey, $shardJobId) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
            if (!$job) {
                return false;
            }

            $job->status = self::STATUS_RUNNING;
            $job->current_shard_job_id = $shardJobId;
            $job->started_at = date('Y-m-d H:i:s');
            $job->error_message = null;
            $job->completed_at = null;
            $job->run_count = ($job->run_count ?? 0) + 1;
            $job->updated_at = date('Y-m-d H:i:s');
            R::store($job);

            $this->log($issueKey, 'info', 'Job run started', ['shard_job_id' => $shardJobId, 'run_count' => $job->run_count]);
            return true;
        });
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
        return UserDatabase::with($this->memberId, function() use ($issueKey, $prUrl, $prNumber, $branchName, $output, $result) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
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
            R::store($job);

            $this->log($issueKey, 'info', 'PR created', ['pr_url' => $prUrl, 'pr_number' => $prNumber]);
            return true;
        });
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
        return UserDatabase::with($this->memberId, function() use ($issueKey, $error, $output) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
            if (!$job) {
                return false;
            }

            $job->status = self::STATUS_FAILED;
            $job->error_message = $error;
            $job->last_output = $output;
            $job->completed_at = date('Y-m-d H:i:s');
            $job->updated_at = date('Y-m-d H:i:s');
            R::store($job);

            $this->log($issueKey, 'error', 'Job failed', ['error' => $error]);
            return true;
        });
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
        return UserDatabase::with($this->memberId, function() use ($issueKey, $commentId, $questions) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
            if (!$job) {
                return false;
            }

            $job->status = self::STATUS_WAITING_CLARIFICATION;
            $job->clarification_comment_id = $commentId;
            $job->clarification_questions = json_encode($questions);
            $job->updated_at = date('Y-m-d H:i:s');
            R::store($job);

            $this->log($issueKey, 'info', 'Waiting for clarification', ['question_count' => count($questions)]);
            return true;
        });
    }

    /**
     * Mark a job as fully complete (e.g., PR merged, ticket done)
     *
     * @param string $issueKey
     * @return bool
     */
    public function markComplete(string $issueKey): bool {
        return UserDatabase::with($this->memberId, function() use ($issueKey) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
            if (!$job) {
                return false;
            }

            $job->status = self::STATUS_COMPLETE;
            $job->completed_at = date('Y-m-d H:i:s');
            $job->updated_at = date('Y-m-d H:i:s');
            R::store($job);

            $this->log($issueKey, 'info', 'Job marked as complete');
            return true;
        });
    }

    /**
     * Update branch name for a job
     *
     * @param string $issueKey
     * @param string $branchName
     * @return bool
     */
    public function setBranch(string $issueKey, string $branchName): bool {
        return UserDatabase::with($this->memberId, function() use ($issueKey, $branchName) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
            if (!$job) {
                return false;
            }

            $job->branch_name = $branchName;
            $job->updated_at = date('Y-m-d H:i:s');
            R::store($job);
            return true;
        });
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
     * Get all jobs
     *
     * @param int $limit
     * @return array
     */
    public function getAll(int $limit = 50): array {
        return UserDatabase::with($this->memberId, function() use ($limit) {
            $jobs = R::find('ai_dev_job', 'ORDER BY COALESCE(updated_at, created_at) DESC LIMIT ?', [$limit]);
            return array_map(fn($job) => $this->formatJob($job), array_values($jobs));
        });
    }

    /**
     * Get active jobs (running, pending, waiting)
     *
     * @return array
     */
    public function getActive(): array {
        return UserDatabase::with($this->memberId, function() {
            $jobs = R::find('ai_dev_job', 'status IN (?, ?, ?) ORDER BY started_at DESC', [
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_WAITING_CLARIFICATION
            ]);
            return array_map(fn($job) => $this->formatJob($job), array_values($jobs));
        });
    }

    /**
     * Delete a job
     *
     * @param string $issueKey
     * @return bool
     */
    public function delete(string $issueKey): bool {
        return UserDatabase::with($this->memberId, function() use ($issueKey) {
            $job = R::findOne('ai_dev_job', 'issue_key = ?', [$issueKey]);
            if ($job) {
                R::trash($job);
                return true;
            }
            return false;
        });
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
        UserDatabase::with($this->memberId, function() use ($issueKey, $level, $message, $context) {
            $log = R::dispense('ai_dev_job_log');
            $log->issue_key = $issueKey;
            $log->log_level = $level;
            $log->message = $message;
            $log->context_json = $context ? json_encode($context) : null;
            $log->created_at = date('Y-m-d H:i:s');
            R::store($log);
        });
    }

    /**
     * Get logs for a job
     *
     * @param string $issueKey
     * @param int $limit
     * @return array
     */
    public function getLogs(string $issueKey, int $limit = 100): array {
        return UserDatabase::with($this->memberId, function() use ($issueKey, $limit) {
            $logs = R::find('ai_dev_job_log', 'issue_key = ? ORDER BY created_at ASC LIMIT ?', [$issueKey, $limit]);
            return array_map(function($log) {
                return [
                    'id' => $log->id,
                    'level' => $log->log_level,
                    'message' => $log->message,
                    'context' => $log->context_json ? json_decode($log->context_json, true) : null,
                    'created_at' => $log->created_at
                ];
            }, array_values($logs));
        });
    }

    /**
     * Format a job bean to array
     *
     * @param \RedBeanPHP\OODBBean $job
     * @return array
     */
    public function formatJob($job): array {
        return [
            'id' => $job->id,
            'issue_key' => $job->issue_key,
            'board_id' => $job->board_id,
            'repo_connection_id' => $job->repo_connection_id,
            'cloud_id' => $job->cloud_id,
            'status' => $job->status,
            'current_shard_job_id' => $job->current_shard_job_id,
            'branch_name' => $job->branch_name,
            'pr_url' => $job->pr_url,
            'pr_number' => $job->pr_number,
            'clarification_comment_id' => $job->clarification_comment_id,
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
