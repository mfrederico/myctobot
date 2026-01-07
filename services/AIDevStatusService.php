<?php
/**
 * AI Developer Status Service
 * Tracks progress of AI Developer jobs via JSON files
 * Status is stored per-user to ensure isolation
 */

namespace app\services;

class AIDevStatusService {

    private static string $statusDir = __DIR__ . '/../storage/aidev_status';

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
     * Get domain identifier for multi-tenant isolation
     */
    private static function getDomainId(): string {
        // Use TmuxManager if available, otherwise derive from Flight config
        if (class_exists('\\app\\TmuxManager')) {
            return \app\TmuxManager::getDomainId();
        }
        // Fallback: derive from baseurl
        $baseUrl = \Flight::get('app.baseurl') ?? \Flight::get('baseurl') ?? 'localhost';
        $domainId = preg_replace('/^https?:\/\//', '', $baseUrl);
        $domainId = preg_replace('/[^a-zA-Z0-9]/', '-', $domainId);
        return trim($domainId, '-') ?: 'default';
    }

    /**
     * Initialize storage directory for a member (with domain isolation)
     */
    private static function ensureDir(int $memberId): string {
        $domainId = self::getDomainId();
        $dir = self::$statusDir . '/' . $domainId . '/member_' . $memberId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Generate an opaque job ID
     */
    private static function generateJobId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get status file path for a job
     */
    private static function getStatusPath(int $memberId, string $jobId): string {
        $dir = self::ensureDir($memberId);
        $safeFilename = hash('sha256', $jobId) . '.json';
        return $dir . '/' . $safeFilename;
    }

    /**
     * Create a new AI Developer job
     *
     * @param int $memberId Member ID
     * @param int $boardId Board ID
     * @param string $issueKey Jira issue key
     * @param int|null $repoConnectionId Repository connection ID
     * @param string|null $cloudId Atlassian Cloud ID
     * @return string Job ID
     */
    public static function createJob(
        int $memberId,
        int $boardId,
        string $issueKey,
        ?int $repoConnectionId = null,
        ?string $cloudId = null
    ): string {
        $jobId = self::generateJobId();

        $status = [
            'job_id' => $jobId,
            'member_id' => $memberId,
            'board_id' => $boardId,
            'issue_key' => $issueKey,
            'repo_connection_id' => $repoConnectionId,
            'cloud_id' => $cloudId,
            'status' => self::STATUS_PENDING,
            'progress' => 0,
            'current_step' => self::STEP_INITIALIZING,
            'steps_completed' => [],
            'branch_name' => null,
            'pr_url' => null,
            'pr_number' => null,
            'clarification_comment_id' => null,
            'clarification_questions' => [],
            'files_changed' => [],
            'commit_sha' => null,
            'error' => null,
            // Shopify integration
            'shopify_theme_id' => null,
            'shopify_preview_url' => null,
            'playwright_results' => null,
            'preserve_branch' => true,  // Don't delete branch until ticket Done
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'completed_at' => null
        ];

        file_put_contents(self::getStatusPath($memberId, $jobId), json_encode($status, JSON_PRETTY_PRINT));

        // Also log the creation
        self::log($jobId, $memberId, 'info', 'Job created', [
            'issue_key' => $issueKey,
            'board_id' => $boardId
        ]);

        return $jobId;
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
     * Update additional job details
     */
    public static function updateDetails(int $memberId, string $jobId, array $details): void {
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);

        foreach ($details as $key => $value) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = self::STATUS_WAITING_CLARIFICATION;
        $data['current_step'] = 'Waiting for clarification';
        $data['clarification_comment_id'] = $commentId;
        $data['clarification_questions'] = $questions;
        $data['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

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
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = self::STATUS_PR_CREATED;
        $data['progress'] = 90;
        $data['current_step'] = 'PR created - waiting for Jira completion';
        $data['pr_url'] = $prUrl;
        if ($prNumber) {
            $data['pr_number'] = $prNumber;
        }
        if ($branchName) {
            $data['branch_name'] = $branchName;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['pr_created_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

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
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = self::STATUS_COMPLETE;
        $data['progress'] = 100;
        $data['current_step'] = self::STEP_COMPLETE;
        $data['pr_url'] = $prUrl;
        if ($prNumber) {
            $data['pr_number'] = $prNumber;
        }
        if ($branchName) {
            $data['branch_name'] = $branchName;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['completed_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        self::log($jobId, $memberId, 'info', 'Job completed', [
            'pr_url' => $prUrl,
            'pr_number' => $prNumber
        ]);
    }

    /**
     * Mark job as failed
     */
    public static function fail(int $memberId, string $jobId, string $error): void {
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = self::STATUS_FAILED;
        $data['error'] = $error;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['completed_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        self::log($jobId, $memberId, 'error', 'Job failed', ['error' => $error]);
    }

    /**
     * Cancel a job
     */
    public static function cancel(int $memberId, string $jobId, string $reason = ''): void {
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = self::STATUS_CANCELLED;
        $data['error'] = $reason ?: 'Job cancelled by user';
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['completed_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        self::log($jobId, $memberId, 'info', 'Job cancelled', ['reason' => $reason]);
    }

    /**
     * Get job status with ownership verification
     */
    public static function getStatus(string $jobId, int $requestingMemberId): ?array {
        $path = self::getStatusPath($requestingMemberId, $jobId);
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        // Verify member ID (defense in depth)
        if (($data['member_id'] ?? null) !== $requestingMemberId) {
            return null;
        }

        return $data;
    }

    /**
     * Get all jobs for a member
     */
    public static function getAllJobs(int $memberId, int $limit = 50): array {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return [];
        }

        $jobs = [];
        $files = glob($dir . '/*.json');

        // Sort by modification time, newest first
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $jobs[] = $data;
            }
        }

        return $jobs;
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
            if ($data && in_array($data['status'], [
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_WAITING_CLARIFICATION
            ])) {
                $jobs[] = $data;
            }
        }

        return $jobs;
    }

    /**
     * Get count of running jobs for a member
     */
    public static function getRunningJobsCount(int $memberId): int {
        $activeJobs = self::getActiveJobs($memberId);
        $count = 0;
        foreach ($activeJobs as $job) {
            if ($job['status'] === self::STATUS_RUNNING) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get jobs waiting for clarification
     */
    public static function getJobsWaitingClarification(int $memberId): array {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return [];
        }

        $jobs = [];
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['status'] === self::STATUS_WAITING_CLARIFICATION) {
                $jobs[] = $data;
            }
        }

        return $jobs;
    }

    /**
     * Find a job by issue key (for webhook handling)
     */
    public static function findJobByIssueKey(int $memberId, string $issueKey): ?array {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['issue_key'] === $issueKey &&
                in_array($data['status'], [
                    self::STATUS_PENDING,
                    self::STATUS_RUNNING,
                    self::STATUS_WAITING_CLARIFICATION
                ])) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Find existing branch for an issue key (checks ALL jobs including completed ones)
     * Used for branch affinity - reuse existing branches instead of creating new ones
     */
    public static function findBranchForIssueKey(int $memberId, string $issueKey): ?string {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.json');
        $latestBranch = null;
        $latestTime = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['issue_key'] === $issueKey && !empty($data['branch_name'])) {
                // Get the most recent branch (by updated_at time)
                $updatedAt = strtotime($data['updated_at'] ?? $data['created_at'] ?? '');
                if ($updatedAt > $latestTime) {
                    $latestTime = $updatedAt;
                    $latestBranch = $data['branch_name'];
                }
            }
        }

        return $latestBranch;
    }

    /**
     * Find all jobs for an issue key (including completed/PR states for cleanup)
     */
    public static function findAllJobsByIssueKey(int $memberId, string $issueKey): array {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return [];
        }

        $jobs = [];
        $files = glob($dir . '/*.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['issue_key'] === $issueKey) {
                $jobs[] = $data;
            }
        }

        // Sort by updated_at descending
        usort($jobs, fn($a, $b) => strtotime($b['updated_at'] ?? 0) - strtotime($a['updated_at'] ?? 0));

        return $jobs;
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
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['status'] = self::STATUS_PREVIEW_READY;
        $data['progress'] = 75;
        $data['current_step'] = 'Preview ready';
        $data['shopify_theme_id'] = $shopifyThemeId;
        $data['shopify_preview_url'] = $previewUrl;
        if ($playwrightResults !== null) {
            $data['playwright_results'] = $playwrightResults;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

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
        $path = self::getStatusPath($memberId, $jobId);
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $data['shopify_theme_id'] = $themeId;
        $data['shopify_preview_url'] = $previewUrl;
        $data['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get Shopify theme ID for an issue (reuse across job runs)
     */
    public static function getShopifyThemeForIssue(int $memberId, string $issueKey): ?int {
        $jobs = self::findAllJobsByIssueKey($memberId, $issueKey);

        foreach ($jobs as $job) {
            if (!empty($job['shopify_theme_id'])) {
                return (int)$job['shopify_theme_id'];
            }
        }

        return null;
    }

    // ========================================
    // Logging Methods
    // ========================================

    /**
     * Get log file path for a job
     */
    private static function getLogPath(int $memberId, string $jobId): string {
        $dir = self::ensureDir($memberId) . '/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safeFilename = hash('sha256', $jobId) . '.log';
        return $dir . '/' . $safeFilename;
    }

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
        $logPath = self::getLogPath($memberId, $jobId);

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        file_put_contents($logPath, json_encode($entry) . "\n", FILE_APPEND);
    }

    /**
     * Get all logs for a job
     */
    public static function getLogs(string $jobId, int $memberId): array {
        $logPath = self::getLogPath($memberId, $jobId);
        if (!file_exists($logPath)) {
            return [];
        }

        $logs = [];
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $logs[] = $entry;
            }
        }

        return $logs;
    }

    // ========================================
    // Cleanup Methods
    // ========================================

    /**
     * Clean up old status files for a member (older than 24 hours)
     */
    public static function cleanup(int $memberId): int {
        $dir = self::$statusDir . '/member_' . $memberId;
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $maxAge = 86400; // 24 hours

        // Clean status files
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            if (filemtime($file) < time() - $maxAge) {
                unlink($file);
                $count++;
            }
        }

        // Clean log files
        $logDir = $dir . '/logs';
        if (is_dir($logDir)) {
            $logs = glob($logDir . '/*.log');
            foreach ($logs as $log) {
                if (filemtime($log) < time() - $maxAge) {
                    unlink($log);
                }
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
            // Get member ID from directory name
            $memberId = (int)str_replace(self::$statusDir . '/member_', '', $dir);
            $count += self::cleanup($memberId);

            // Remove empty directories
            if (count(glob($dir . '/*')) === 0) {
                rmdir($dir);
            }
        }

        return $count;
    }
}
