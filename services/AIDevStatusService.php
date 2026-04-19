<?php
/**
 * AI Developer Status Service
 * Tracks progress of AI Developer jobs via database
 * Status is stored per-workspace in the aidevjobs table
 */

namespace app\services;

use RedBeanPHP\R as R;

use \app\Bean;

class AIDevStatusService {

    // Job statuses
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_WAITING_CLARIFICATION = 'waiting_clarification';
    const STATUS_PREVIEW_READY = 'preview_ready';  // Shopify preview available
    const STATUS_PR_CREATED = 'pr_created';  // PR created, waiting for Jira ticket to be done
    const STATUS_COMPLETE = 'complete';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // Steps for progress tracking
    const STEP_INITIALIZING = 'Initializing';
    const STEP_FETCHING_ISSUE = 'Fetching issue from Jira';
    const STEP_ANALYZING_REQUIREMENTS = 'Analyzing requirements';
    const STEP_CHECKING_CLARITY = 'Checking requirement clarity';
    const STEP_POSTING_QUESTIONS = 'Posting clarification questions';
    const STEP_CLONING_REPO = 'Cloning repository';
    const STEP_ANALYZING_CODEBASE = 'Analyzing codebase';
    const STEP_PLANNING_IMPLEMENTATION = 'Planning implementation';
    const STEP_IMPLEMENTING_CHANGES = 'Implementing code changes';
    const STEP_CREATING_BRANCH = 'Creating feature branch';
    const STEP_COMMITTING_CHANGES = 'Committing changes';
    const STEP_PUSHING_CHANGES = 'Pushing to remote';
    const STEP_CREATING_PR = 'Creating pull request';
    const STEP_SYNCING_SHOPIFY = 'Syncing to Shopify theme';
    const STEP_CREATING_PREVIEW = 'Creating Shopify preview';
    const STEP_RUNNING_TESTS = 'Running Playwright tests';
    const STEP_COMPLETE = 'Complete';

    /**
     * Generate an opaque job ID
     */
    private static function generateJobId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Convert a bean to an array (matching old file-based format)
     */
    private static function beanToArray($bean): array {
        if (!$bean || !$bean->id) {
            return [];
        }

        return [
            'job_uid' => $bean->job_uid ?? '',
            'member_id' => (int) $bean->member_id,
            'board_id' => (int) $bean->boards_id,  // API compatibility
            'issue_key' => $bean->issue_key ?? '',
            'project_type' => $bean->project_type ?? 'jira',
            'repo_connection_id' => $bean->repoconnections_id ? (int) $bean->repoconnections_id : null,  // API compatibility
            'cloud_uid' => $bean->cloud_uid ?? '',
            'status' => $bean->status ?? 'unknown',
            'progress' => (int) ($bean->progress ?? 0),
            'current_step' => $bean->current_step ?? '',
            'steps_completed' => json_decode($bean->steps_completed ?: '[]', true),
            'branch_name' => $bean->branch_name ?? '',
            'pr_url' => $bean->pr_url ?? '',
            'pr_number' => $bean->pr_number ? (int) $bean->pr_number : null,
            'pr_created_at' => $bean->pr_created_at ?? '',
            'clarification_comment_uid' => $bean->clarification_comment_uid ?? '',
            'clarification_questions' => json_decode($bean->clarification_questions ?: '[]', true),
            'files_changed' => json_decode($bean->files_changed ?: '[]', true),
            'commit_sha' => $bean->commit_sha ?? '',
            'error' => $bean->error_message ?? '',
            'shopify_themeid' => $bean->shopify_themeid ? (int) $bean->shopify_themeid : null,
            'shopify_preview_url' => $bean->shopify_preview_url ?? '',
            'playwright_results' => json_decode($bean->playwright_results ?: 'null', true),
            'preserve_branch' => (bool) $bean->preserve_branch,
            'started_at' => $bean->started_at ?? '',
            'updated_at' => $bean->updated_at ?? '',
            'completed_at' => $bean->completed_at ?? ''
        ];
    }

    /**
     * Create a new AI Developer job
     *
     * @param int $memberId Member ID
     * @param int|null $boardId Board ID (null for non-Jira projects like GitHub)
     * @param string $issueKey Issue key (format varies by project type)
     * @param int|null $repoConnectionId Repository connection ID
     * @param string|null $cloudId Atlassian Cloud ID (or identifier for non-Jira sources)
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     * @return string Job ID
     */
    public static function createJob(
        int $memberId,
        ?int $boardId,
        string $issueKey,
        ?int $repoConnectionId = null,
        ?string $cloudId = null,
        string $projectType = 'jira'
    ): string {
        $jobId = self::generateJobId();

        $job = Bean::dispense('aidevjobs');
        $job->job_uid = $jobId;
        $job->member_id = $memberId;
        $job->boards_id = $boardId; // null is valid for non-Jira projects
        $job->issue_key = $issueKey;
        $job->project_type = $projectType;
        $job->repoconnections_id = $repoConnectionId;
        $job->cloud_uid = $cloudId;
        $job->status = self::STATUS_PENDING;
        $job->progress = 0;
        $job->current_step = self::STEP_INITIALIZING;
        $job->steps_completed = '[]';
        $job->preserve_branch = 1;
        $job->started_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        // Also log the creation
        self::log($jobId, $memberId, 'info', 'Job created', [
            'issue_key' => $issueKey,
            'board_id' => $boardId,
            'project_type' => $projectType
        ]);

        return $jobId;
    }

    /**
     * Find job by job_uid
     */
    private static function findByJobId(int $memberId, string $jobId): ?object {
        return Bean::findOne('aidevjobs', 'job_uid = ? AND member_id = ?', [$jobId, $memberId]);
    }

    /**
     * Update job status
     */
    public static function updateStatus(
        int $memberId,
        string $jobId,
        string $step,
        int $progress,
        string $status = self::STATUS_RUNNING
    ): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $stepsCompleted = json_decode($job->steps_completed ?: '[]', true);
        $stepsCompleted[] = [
            'step' => $step,
            'progress' => $progress,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $job->status = $status;
        $job->progress = $progress;
        $job->current_step = $step;
        $job->steps_completed = json_encode($stepsCompleted);
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);
    }

    /**
     * Update additional job details
     */
    public static function updateDetails(int $memberId, string $jobId, array $details): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $allowedFields = [
            'branch_name', 'pr_url', 'pr_number', 'commit_sha',
            'shopify_themeid', 'shopify_preview_url', 'playwright_results',
            'files_changed', 'preserve_branch', 'current_runner_job_uid'
        ];

        foreach ($details as $key => $value) {
            if (in_array($key, $allowedFields)) {
                // JSON encode arrays
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $job->$key = $value;
            }
        }

        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);
    }

    /**
     * Mark job as waiting for clarification
     */
    public static function waitingClarification(
        int $memberId,
        string $jobId,
        string $commentId,
        array $questions
    ): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->status = self::STATUS_WAITING_CLARIFICATION;
        $job->current_step = 'Waiting for clarification';
        $job->clarification_comment_uid = $commentId;
        $job->clarification_questions = json_encode($questions);
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        self::log($jobId, $memberId, 'info', 'Waiting for clarification', [
            'comment_id' => $commentId,
            'question_count' => count($questions)
        ]);
    }

    /**
     * Mark job as PR created (waiting for Jira ticket to be completed)
     */
    public static function prCreated(
        int $memberId,
        string $jobId,
        string $prUrl,
        ?int $prNumber = null,
        ?string $branchName = null
    ): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->status = self::STATUS_PR_CREATED;
        $job->progress = 90;
        $job->current_step = 'PR created - waiting for Jira completion';
        $job->pr_url = $prUrl;
        if ($prNumber) {
            $job->pr_number = $prNumber;
        }
        if ($branchName) {
            $job->branch_name = $branchName;
        }
        $job->pr_created_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        self::log($jobId, $memberId, 'info', 'PR created, waiting for Jira completion', [
            'pr_url' => $prUrl,
            'pr_number' => $prNumber
        ]);
    }

    /**
     * Mark job as fully complete (Jira ticket is done)
     */
    public static function complete(
        int $memberId,
        string $jobId,
        string $prUrl,
        ?int $prNumber = null,
        ?string $branchName = null
    ): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->status = self::STATUS_COMPLETE;
        $job->progress = 100;
        $job->current_step = self::STEP_COMPLETE;
        $job->pr_url = $prUrl;
        if ($prNumber) {
            $job->pr_number = $prNumber;
        }
        if ($branchName) {
            $job->branch_name = $branchName;
        }
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        self::log($jobId, $memberId, 'info', 'Job completed', [
            'pr_url' => $prUrl,
            'pr_number' => $prNumber
        ]);
    }

    /**
     * Mark job as failed
     */
    public static function fail(int $memberId, string $jobId, string $error): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->status = self::STATUS_FAILED;
        $job->error_message = $error;
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        self::log($jobId, $memberId, 'error', 'Job failed', ['error' => $error]);
    }

    /**
     * Cancel a job
     */
    public static function cancel(int $memberId, string $jobId, string $reason = ''): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->status = self::STATUS_CANCELLED;
        $job->error_message = $reason ?: 'Job cancelled by user';
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        self::log($jobId, $memberId, 'info', 'Job cancelled', ['reason' => $reason]);
    }

    /**
     * Get job status with ownership verification
     */
    public static function getStatus(string $jobId, int $requestingMemberId): ?array {
        $job = self::findByJobId($requestingMemberId, $jobId);
        if (!$job) {
            return null;
        }

        return self::beanToArray($job);
    }

    /**
     * Get all jobs for a member
     */
    public static function getAllJobs(int $memberId, int $limit = 50): array {
        $jobs = Bean::find('aidevjobs',
            'member_id = ? ORDER BY updated_at DESC LIMIT ?',
            [$memberId, $limit]
        );

        $result = [];
        foreach ($jobs as $job) {
            $result[] = self::beanToArray($job);
        }

        return $result;
    }

    /**
     * Get active jobs for a member
     */
    public static function getActiveJobs(int $memberId): array {
        $jobs = Bean::find('aidevjobs',
            'member_id = ? AND status IN (?, ?, ?) ORDER BY updated_at DESC',
            [
                $memberId,
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_WAITING_CLARIFICATION
            ]
        );

        $result = [];
        foreach ($jobs as $job) {
            $result[] = self::beanToArray($job);
        }

        return $result;
    }

    /**
     * Get count of running jobs for a member
     */
    public static function getRunningJobsCount(int $memberId): int {
        return (int) Bean::count('aidevjobs',
            'member_id = ? AND status = ?',
            [$memberId, self::STATUS_RUNNING]
        );
    }

    /**
     * Get jobs waiting for clarification
     */
    public static function getJobsWaitingClarification(int $memberId): array {
        $jobs = Bean::find('aidevjobs',
            'member_id = ? AND status = ? ORDER BY updated_at DESC',
            [$memberId, self::STATUS_WAITING_CLARIFICATION]
        );

        $result = [];
        foreach ($jobs as $job) {
            $result[] = self::beanToArray($job);
        }

        return $result;
    }

    /**
     * Find a job by issue key (for webhook handling - returns active jobs only)
     */
    public static function findJobByIssueKey(int $memberId, string $issueKey): ?array {
        $job = Bean::findOne('aidevjobs',
            'member_id = ? AND issue_key = ? AND status IN (?, ?, ?) ORDER BY updated_at DESC',
            [
                $memberId,
                $issueKey,
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_WAITING_CLARIFICATION
            ]
        );

        if (!$job) {
            return null;
        }

        return self::beanToArray($job);
    }

    /**
     * Find existing branch for an issue key (checks ALL jobs including completed ones)
     * Used for branch affinity - reuse existing branches instead of creating new ones
     */
    public static function findBranchForIssueKey(int $memberId, string $issueKey): ?string {
        $job = Bean::findOne('aidevjobs',
            'member_id = ? AND issue_key = ? AND branch_name IS NOT NULL AND branch_name != "" ORDER BY updated_at DESC',
            [$memberId, $issueKey]
        );

        return $job ? $job->branch_name : null;
    }

    /**
     * Find all jobs for an issue key (including completed/PR states for cleanup)
     */
    public static function findAllJobsByIssueKey(int $memberId, string $issueKey): array {
        $jobs = Bean::find('aidevjobs',
            'member_id = ? AND issue_key = ? ORDER BY updated_at DESC',
            [$memberId, $issueKey]
        );

        $result = [];
        foreach ($jobs as $job) {
            $result[] = self::beanToArray($job);
        }

        return $result;
    }

    /**
     * Mark job as preview ready (Shopify preview available)
     */
    public static function previewReady(
        int $memberId,
        string $jobId,
        int $shopifyThemeId,
        string $previewUrl,
        ?array $playwrightResults = null
    ): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->status = self::STATUS_PREVIEW_READY;
        $job->progress = 75;
        $job->current_step = 'Preview ready';
        $job->shopify_themeid = $shopifyThemeId;
        $job->shopify_preview_url = $previewUrl;
        if ($playwrightResults !== null) {
            $job->playwright_results = json_encode($playwrightResults);
        }
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        self::log($jobId, $memberId, 'info', 'Shopify preview ready', [
            'theme_id' => $shopifyThemeId,
            'preview_url' => $previewUrl
        ]);
    }

    /**
     * Update Shopify theme info for a job
     */
    public static function updateShopifyTheme(
        int $memberId,
        string $jobId,
        int $themeId,
        string $previewUrl
    ): void {
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return;
        }

        $job->shopify_themeid = $themeId;
        $job->shopify_preview_url = $previewUrl;
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);
    }

    /**
     * Get Shopify theme ID for an issue (reuse across job runs)
     */
    public static function getShopifyThemeForIssue(int $memberId, string $issueKey): ?int {
        $job = Bean::findOne('aidevjobs',
            'member_id = ? AND issue_key = ? AND shopify_themeid IS NOT NULL ORDER BY updated_at DESC',
            [$memberId, $issueKey]
        );

        return $job ? (int) $job->shopify_themeid : null;
    }

    // ========================================
    // Logging Methods
    // ========================================

    /**
     * Add a log entry for a job
     */
    public static function log(
        string $jobId,
        int $memberId,
        string $level,
        string $message,
        array $context = []
    ): void {
        // Get issue_key from the job
        $job = self::findByJobId($memberId, $jobId);
        $issueKey = $job ? $job->issue_key : $jobId;

        $log = Bean::dispense('aidevjoblogs');
        $log->issue_key = $issueKey;
        $log->log_level = $level;
        $log->message = $message;
        $log->context_json = !empty($context) ? json_encode($context) : null;
        $log->created_at = date('Y-m-d H:i:s');

        Bean::store($log);
    }

    /**
     * Get all logs for a job
     */
    public static function getLogs(string $jobId, int $memberId): array {
        // Get issue_key from the job
        $job = self::findByJobId($memberId, $jobId);
        if (!$job) {
            return [];
        }

        $logs = Bean::find('aidevjoblogs',
            'issue_key = ? ORDER BY created_at ASC',
            [$job->issue_key]
        );

        $result = [];
        foreach ($logs as $log) {
            $result[] = [
                'timestamp' => $log->created_at,
                'level' => $log->log_level,
                'message' => $log->message,
                'context' => json_decode($log->context_json ?: '{}', true)
            ];
        }

        return $result;
    }

    // ========================================
    // Cleanup Methods
    // ========================================

    /**
     * Clean up old jobs for a member (older than specified days)
     */
    public static function cleanup(int $memberId, int $daysOld = 30): int {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        // Get jobs to delete
        $jobs = Bean::find('aidevjobs',
            'member_id = ? AND status IN (?, ?, ?) AND updated_at < ?',
            [
                $memberId,
                self::STATUS_COMPLETE,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
                $cutoff
            ]
        );

        $count = 0;
        foreach ($jobs as $job) {
            // Delete associated logs
            Bean::exec('DELETE FROM aidevjoblogs WHERE issue_key = ?', [$job->issue_key]);
            Bean::trash($job);
            $count++;
        }

        return $count;
    }

    /**
     * Clean up all old jobs (for cron)
     */
    public static function cleanupAll(int $daysOld = 30): int {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        // Get all old completed/failed/cancelled jobs
        $jobs = Bean::find('aidevjobs',
            'status IN (?, ?, ?) AND updated_at < ?',
            [
                self::STATUS_COMPLETE,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
                $cutoff
            ]
        );

        $count = 0;
        foreach ($jobs as $job) {
            // Delete associated logs
            Bean::exec('DELETE FROM aidevjoblogs WHERE issue_key = ?', [$job->issue_key]);
            Bean::trash($job);
            $count++;
        }

        return $count;
    }
}
