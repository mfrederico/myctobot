<?php
/**
 * User Database Service
 *
 * LEGACY COMPATIBILITY LAYER - All data is now in one MySQL database per tenant.
 * connect() and restore() are no-ops. All methods query the single MySQL database.
 *
 * Usage remains the same:
 *   UserDatabaseService::forMember($memberId, function() {
 *       $boards = Bean::findAll('jiraboards', ' ORDER BY board_name ');
 *       return $boards;
 *   });
 */

namespace app\services;

use \Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

class UserDatabaseService {

    private static $currentMemberId = null;

    /**
     * Execute a callback (legacy compatibility - no DB switching needed)
     *
     * @param int $memberId Member ID
     * @param callable $callback Function to execute
     * @return mixed Result of callback
     */
    public static function forMember(int $memberId, callable $callback) {
        self::$currentMemberId = $memberId;
        try {
            return $callback();
        } finally {
            self::$currentMemberId = null;
        }
    }

    /**
     * Connect to member's database (no-op - all data in single MySQL DB)
     *
     * @param int $memberId Member ID
     */
    public static function connect(int $memberId): void {
        self::$currentMemberId = $memberId;
    }

    /**
     * Restore to default database (no-op)
     */
    public static function restore(): void {
        self::$currentMemberId = null;
    }

    /**
     * Get the currently connected member ID
     *
     * @return int|null
     */
    public static function getCurrentMemberId(): ?int {
        return self::$currentMemberId;
    }

    // ==================== Board Management ====================

    /**
     * Get all boards
     * @return array
     */
    public static function getBoards(): array {
        return array_values(R::findAll('jiraboards', ' ORDER BY board_name ASC '));
    }

    /**
     * Get boards by cloud ID
     * @param string $cloudId
     * @return array
     */
    public static function getBoardsByCloudId(string $cloudId): array {
        return array_values(R::find('jiraboards', ' cloud_id = ? ORDER BY board_name ASC ', [$cloudId]));
    }

    /**
     * Get enabled boards
     * @return array
     */
    public static function getEnabledBoards(): array {
        return array_values(R::find('jiraboards', ' enabled = 1 ORDER BY board_name ASC '));
    }

    /**
     * Get boards with digest enabled
     * @return array
     */
    public static function getBoardsForDigest(): array {
        return array_values(R::find('jiraboards', ' enabled = 1 AND digest_enabled = 1 ORDER BY digest_time ASC '));
    }

    /**
     * Get a single board
     * @param int $id Board ID
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getBoard(int $id) {
        $board = R::load('jiraboards', $id);
        return $board->id ? $board : null;
    }

    /**
     * Get a board by Jira board ID and cloud ID
     * @param int $boardId Jira board ID
     * @param string $cloudId Cloud ID
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getBoardByJiraId(int $boardId, string $cloudId) {
        return R::findOne('jiraboards', ' board_id = ? AND cloud_id = ? ', [$boardId, $cloudId]);
    }

    /**
     * Get a board by project key
     * @param string $projectKey Project key
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getBoardByProjectKey(string $projectKey) {
        return R::findOne('jiraboards', ' project_key = ? ', [$projectKey]);
    }

    /**
     * Add a board
     * @param array $data Board data
     * @return int Board ID
     */
    public static function addBoard(array $data): int {
        $board = R::dispense('jiraboards');
        $board->board_id = $data['board_id'];
        $board->board_name = $data['board_name'];
        $board->project_key = $data['project_key'];
        $board->cloud_id = $data['cloud_id'];
        $board->board_type = $data['board_type'] ?? 'scrum';
        $board->enabled = $data['enabled'] ?? 1;
        $board->digest_enabled = $data['digest_enabled'] ?? 0;
        $board->digest_time = $data['digest_time'] ?? '08:00';
        $board->timezone = $data['timezone'] ?? 'UTC';
        $board->status_filter = $data['status_filter'] ?? 'To Do';
        $board->created_at = date('Y-m-d H:i:s');
        return R::store($board);
    }

    /**
     * Update a board
     * @param int $id Board ID
     * @param array $data Board data
     * @return bool
     */
    public static function updateBoard(int $id, array $data): bool {
        $board = R::load('jiraboards', $id);
        if (!$board->id) {
            return false;
        }

        $allowedFields = [
            'board_name', 'project_key', 'board_type', 'enabled',
            'digest_enabled', 'digest_time', 'digest_cc', 'timezone', 'status_filter',
            'priority_weights', 'goals', 'last_analysis_at', 'last_digest_at',
            'aidev_status_working', 'aidev_status_pr_created',
            'aidev_status_clarification', 'aidev_status_failed',
            'aidev_status_complete', 'aidev_anthropic_key_id'
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $board->$key = $value;
            }
        }

        $board->updated_at = date('Y-m-d H:i:s');
        R::store($board);
        return true;
    }

    /**
     * Remove a board (cascades to related data)
     * @param int $id Board ID
     * @return bool
     */
    public static function removeBoard(int $id): bool {
        $board = R::load('jiraboards', $id);
        if (!$board->id) {
            return false;
        }

        // Delete related records
        R::exec('DELETE FROM analysisresults WHERE board_id = ?', [$id]);
        R::exec('DELETE FROM digesthistory WHERE board_id = ?', [$id]);
        R::exec('DELETE FROM ticketanalysiscache WHERE board_id = ?', [$id]);

        R::trash($board);
        return true;
    }

    /**
     * Toggle board enabled status
     * @param int $id Board ID
     * @return bool|null New status or null if not found
     */
    public static function toggleBoard(int $id): ?bool {
        $board = R::load('jiraboards', $id);
        if (!$board->id) {
            return null;
        }

        $board->enabled = $board->enabled ? 0 : 1;
        $board->updated_at = date('Y-m-d H:i:s');
        R::store($board);

        return (bool) $board->enabled;
    }

    // ==================== Analysis Results ====================

    /**
     * Store analysis results
     * @param int $boardId Board ID
     * @param string $type Analysis type
     * @param array $results Analysis results
     * @param string|null $markdown Markdown content
     * @return int Analysis ID
     */
    public static function storeAnalysis(int $boardId, string $type, array $results, ?string $markdown = null): int {
        $analysis = R::dispense('analysisresults');
        $analysis->board_id = $boardId;
        $analysis->analysis_type = $type;
        $analysis->content_json = json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
        $analysis->content_markdown = $markdown;
        $analysis->issue_count = $results['issue_count'] ?? null;
        $analysis->status_filter = $results['status_filter'] ?? null;
        $analysis->created_at = date('Y-m-d H:i:s');

        $id = R::store($analysis);

        // Update board's last_analysis_at
        self::updateBoard($boardId, ['last_analysis_at' => date('Y-m-d H:i:s')]);

        return $id;
    }

    /**
     * Get an analysis result
     * @param int $id Analysis ID
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getAnalysis(int $id) {
        $analysis = R::load('analysisresults', $id);
        return $analysis->id ? $analysis : null;
    }

    /**
     * Get recent analyses for a board
     * @param int $boardId Board ID
     * @param int $limit Number of results
     * @return array
     */
    public static function getRecentAnalyses(int $boardId, int $limit = 10): array {
        return array_values(R::find('analysisresults',
            ' board_id = ? ORDER BY created_at DESC LIMIT ? ',
            [$boardId, $limit]
        ));
    }

    /**
     * Get all recent analyses across all boards
     * @param int $limit Number of results
     * @return array
     */
    public static function getAllRecentAnalyses(int $limit = 20): array {
        return R::getAll("
            SELECT a.*, b.board_name, b.project_key
            FROM analysisresults a
            JOIN jiraboards b ON a.board_id = b.id
            ORDER BY a.created_at DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Delete old analyses (keep last N per board)
     * @param int $keepCount Number of analyses to keep per board
     * @return int Number of deleted records
     */
    public static function cleanupOldAnalyses(int $keepCount = 50): int {
        $boards = self::getBoards();
        $deleted = 0;

        foreach ($boards as $board) {
            $boardId = $board->id ?? $board['id'];
            $result = R::exec("
                DELETE FROM analysisresults
                WHERE board_id = ?
                AND id NOT IN (
                    SELECT id FROM analysisresults
                    WHERE board_id = ?
                    ORDER BY created_at DESC
                    LIMIT ?
                )
            ", [$boardId, $boardId, $keepCount]);
            $deleted += $result;
        }

        return $deleted;
    }

    // ==================== Digest History ====================

    /**
     * Log a digest email
     * @param int $boardId Board ID
     * @param string $sentTo Email address
     * @param string $subject Email subject
     * @param string $contentPreview Content preview
     * @param string $status Status (sent, failed)
     * @param string|null $errorMessage Error message if failed
     * @return int
     */
    public static function logDigest(int $boardId, string $sentTo, string $subject, string $contentPreview, string $status = 'sent', ?string $errorMessage = null): int {
        $digest = R::dispense('digesthistory');
        $digest->board_id = $boardId;
        $digest->sent_to = $sentTo;
        $digest->subject = $subject;
        $digest->content_preview = substr($contentPreview, 0, 500);
        $digest->status = $status;
        $digest->error_message = $errorMessage;
        $digest->created_at = date('Y-m-d H:i:s');

        $id = R::store($digest);

        // Update board's last_digest_at
        self::updateBoard($boardId, ['last_digest_at' => date('Y-m-d H:i:s')]);

        return $id;
    }

    /**
     * Get digest history for a board
     * @param int $boardId Board ID
     * @param int $limit Number of results
     * @return array
     */
    public static function getDigestHistory(int $boardId, int $limit = 20): array {
        return array_values(R::find('digesthistory',
            ' board_id = ? ORDER BY created_at DESC LIMIT ? ',
            [$boardId, $limit]
        ));
    }

    // ==================== Enterprise Settings ====================

    /**
     * Get a setting
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function getSetting(string $key, $default = null) {
        $setting = R::findOne('enterprisesettings', ' setting_key = ? ', [$key]);
        return $setting ? $setting->setting_value : $default;
    }

    /**
     * Set a setting
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool
     */
    public static function setSetting(string $key, $value): bool {
        $setting = R::findOne('enterprisesettings', ' setting_key = ? ', [$key]);
        if (!$setting) {
            $setting = R::dispense('enterprisesettings');
            $setting->setting_key = $key;
            $setting->created_at = date('Y-m-d H:i:s');
        }
        $setting->setting_value = $value;
        $setting->updated_at = date('Y-m-d H:i:s');
        R::store($setting);
        return true;
    }

    /**
     * Get all settings
     * @return array Key-value pairs
     */
    public static function getAllSettings(): array {
        $settings = [];
        $beans = R::findAll('enterprisesettings');
        foreach ($beans as $bean) {
            $settings[$bean->setting_key] = $bean->setting_value;
        }
        return $settings;
    }

    // ==================== Statistics ====================

    /**
     * Get dashboard statistics
     * @return array
     */
    public static function getStats(): array {
        return [
            'total_boards' => R::count('jiraboards'),
            'enabled_boards' => R::count('jiraboards', ' enabled = 1 '),
            'digest_enabled_boards' => R::count('jiraboards', ' digest_enabled = 1 '),
            'total_analyses' => R::count('analysisresults'),
            'total_digests' => R::count('digesthistory', ' status = ? ', ['sent']),
            'recent_analyses' => self::getAllRecentAnalyses(5)
        ];
    }

    // ==================== Ticket Analysis Cache ====================

    /**
     * Get cached analysis for a ticket
     * @param int $boardId Board ID
     * @param string $issueKey Issue key
     * @return array|null Array with clarity_score, clarity_analysis, etc.
     */
    public static function getTicketAnalysisCache(int $boardId, string $issueKey): ?array {
        $bean = R::findOne('ticketanalysiscache', ' board_id = ? AND issue_key = ? ', [$boardId, $issueKey]);
        if (!$bean) {
            return null;
        }
        return [
            'clarity_score' => (int) $bean->clarity_score,
            'clarity_analysis' => $bean->clarity_analysis ? json_decode($bean->clarity_analysis, true) : null,
            'reporter_name' => $bean->reporter_name,
            'reporter_email' => $bean->reporter_email,
            'content_hash' => $bean->content_hash
        ];
    }

    /**
     * Set cached analysis for a ticket
     * @param int $boardId Board ID
     * @param string $issueKey Issue key
     * @param string $contentHash Hash of ticket content
     * @param array $data Analysis data
     * @return bool
     */
    public static function setTicketAnalysisCache(int $boardId, string $issueKey, string $contentHash, array $data): bool {
        $cache = R::findOne('ticketanalysiscache', ' board_id = ? AND issue_key = ? ', [$boardId, $issueKey]);
        if (!$cache) {
            $cache = R::dispense('ticketanalysiscache');
            $cache->board_id = $boardId;
            $cache->issue_key = $issueKey;
            $cache->created_at = date('Y-m-d H:i:s');
        }

        $cache->content_hash = $contentHash;
        $cache->clarity_score = $data['clarity_score'] ?? null;
        $cache->clarity_analysis = isset($data['clarity_analysis']) ? json_encode($data['clarity_analysis']) : null;
        $cache->reporter_name = $data['reporter_name'] ?? null;
        $cache->reporter_email = $data['reporter_email'] ?? null;
        $cache->updated_at = date('Y-m-d H:i:s');

        R::store($cache);
        return true;
    }

    /**
     * Check if ticket needs re-analysis based on content hash
     * @param int $boardId Board ID
     * @param string $issueKey Issue key
     * @param string $newHash New content hash
     * @return bool True if re-analysis needed
     */
    public static function shouldReanalyzeTicket(int $boardId, string $issueKey, string $newHash): bool {
        $cached = self::getTicketAnalysisCache($boardId, $issueKey);
        if (!$cached) {
            return true;
        }
        return ($cached['content_hash'] ?? '') !== $newHash;
    }

    /**
     * Generate content hash for a ticket
     * @param array $issue Issue data from Jira
     * @return string SHA256 hash
     */
    public static function generateTicketHash(array $issue): string {
        $fields = $issue['fields'] ?? [];
        $content = json_encode([
            'key' => $issue['key'] ?? '',
            'summary' => $fields['summary'] ?? '',
            'description' => $fields['description'] ?? '',
            'status' => $fields['status']['name'] ?? '',
            'priority' => $fields['priority']['name'] ?? '',
        ]);
        return hash('sha256', $content);
    }

    // ==================== AI Developer Jobs ====================

    /**
     * Get or create a job record for an issue
     * @param string $issueKey Jira issue key
     * @param int $boardId Board ID
     * @param int|null $repoConnectionId Repository connection ID
     * @param string|null $cloudId Atlassian cloud ID
     * @return \RedBeanPHP\OODBBean
     */
    public static function getOrCreateAiDevJob(string $issueKey, int $boardId, ?int $repoConnectionId = null, ?string $cloudId = null) {
        $job = R::findOne('aidevjobs', ' issue_key = ? ', [$issueKey]);
        if ($job) {
            return $job;
        }

        $job = R::dispense('aidevjobs');
        $job->issue_key = $issueKey;
        $job->board_id = $boardId;
        $job->repo_connection_id = $repoConnectionId;
        $job->cloud_id = $cloudId;
        $job->status = 'pending';
        $job->run_count = 0;
        $job->created_at = date('Y-m-d H:i:s');
        R::store($job);

        return $job;
    }

    /**
     * Get a job by issue key
     * @param string $issueKey Jira issue key
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getAiDevJob(string $issueKey) {
        return R::findOne('aidevjobs', ' issue_key = ? ', [$issueKey]);
    }

    /**
     * Update a job's status and related fields
     * @param string $issueKey Jira issue key
     * @param array $data Fields to update
     * @return bool
     */
    public static function updateAiDevJob(string $issueKey, array $data): bool {
        $job = R::findOne('aidevjobs', ' issue_key = ? ', [$issueKey]);
        if (!$job) {
            return false;
        }

        $allowedFields = [
            'status', 'current_shard_job_id', 'branch_name', 'pr_url', 'pr_number',
            'clarification_comment_id', 'clarification_questions', 'error_message',
            'run_count', 'last_output', 'last_result_json', 'files_changed',
            'commit_sha', 'started_at', 'completed_at', 'board_id', 'repo_connection_id', 'cloud_id'
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                // JSON encode arrays
                if (in_array($key, ['clarification_questions', 'files_changed', 'last_result_json']) && is_array($value)) {
                    $value = json_encode($value);
                }
                $job->$key = $value;
            }
        }

        $job->updated_at = date('Y-m-d H:i:s');
        R::store($job);
        return true;
    }

    /**
     * Start a new run on a job
     * @param string $issueKey Jira issue key
     * @param string $shardJobId The shard job ID for this run
     * @return bool
     */
    public static function startAiDevJobRun(string $issueKey, string $shardJobId): bool {
        $job = R::findOne('aidevjobs', ' issue_key = ? ', [$issueKey]);
        if (!$job) {
            return false;
        }

        $job->status = 'running';
        $job->current_shard_job_id = $shardJobId;
        $job->started_at = date('Y-m-d H:i:s');
        $job->error_message = null;
        $job->completed_at = null;
        $job->run_count = ($job->run_count ?? 0) + 1;
        $job->updated_at = date('Y-m-d H:i:s');
        R::store($job);

        return true;
    }

    /**
     * Complete a job run with PR info
     * @param string $issueKey Jira issue key
     * @param string $prUrl PR URL
     * @param int|null $prNumber PR number
     * @param string|null $branchName Branch name
     * @param string|null $output Full output log
     * @param array|null $result Result data
     * @return bool
     */
    public static function completeAiDevJob(string $issueKey, string $prUrl, ?int $prNumber, ?string $branchName = null, ?string $output = null, ?array $result = null): bool {
        return self::updateAiDevJob($issueKey, [
            'status' => 'pr_created',
            'pr_url' => $prUrl,
            'pr_number' => $prNumber,
            'branch_name' => $branchName,
            'last_output' => $output,
            'last_result_json' => $result,
            'completed_at' => date('Y-m-d H:i:s'),
            'error_message' => null
        ]);
    }

    /**
     * Mark a job as failed
     * @param string $issueKey Jira issue key
     * @param string $error Error message
     * @param string|null $output Output log
     * @return bool
     */
    public static function failAiDevJob(string $issueKey, string $error, ?string $output = null): bool {
        return self::updateAiDevJob($issueKey, [
            'status' => 'failed',
            'error_message' => $error,
            'last_output' => $output,
            'completed_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Mark a job as waiting for clarification
     * @param string $issueKey Jira issue key
     * @param string $commentId The Jira comment ID with questions
     * @param array $questions The clarification questions
     * @return bool
     */
    public static function setAiDevJobWaitingClarification(string $issueKey, string $commentId, array $questions): bool {
        return self::updateAiDevJob($issueKey, [
            'status' => 'waiting_clarification',
            'clarification_comment_id' => $commentId,
            'clarification_questions' => $questions
        ]);
    }

    /**
     * Get all jobs
     * @param int $limit Max number of jobs
     * @return array
     */
    public static function getAllAiDevJobs(int $limit = 50): array {
        return array_values(R::find('aidevjobs',
            ' ORDER BY COALESCE(updated_at, created_at) DESC LIMIT ? ',
            [$limit]
        ));
    }

    /**
     * Get active jobs (running, pending, or waiting_clarification)
     * @return array
     */
    public static function getActiveAiDevJobs(): array {
        return array_values(R::find('aidevjobs',
            " status IN ('pending', 'running', 'waiting_clarification') ORDER BY started_at DESC "
        ));
    }

    /**
     * Check if a job is currently running for an issue
     * @param string $issueKey Jira issue key
     * @return bool
     */
    public static function isAiDevJobRunning(string $issueKey): bool {
        $job = self::getAiDevJob($issueKey);
        return $job && $job->status === 'running';
    }

    /**
     * Delete a job record
     * @param string $issueKey Jira issue key
     * @return bool
     */
    public static function deleteAiDevJob(string $issueKey): bool {
        $job = R::findOne('aidevjobs', ' issue_key = ? ', [$issueKey]);
        if (!$job) {
            return false;
        }
        R::trash($job);
        return true;
    }

    /**
     * Add a log entry for a job
     * @param string $issueKey Jira issue key
     * @param string $level Log level
     * @param string $message Log message
     * @param array|null $context Context data
     * @return bool
     */
    public static function addAiDevJobLog(string $issueKey, string $level, string $message, ?array $context = null): bool {
        $job = self::getAiDevJob($issueKey);
        $jobId = $job ? ($job->current_shard_job_id ?? $issueKey) : $issueKey;

        $log = R::dispense('aidevjoblogs');
        $log->job_id = $jobId;
        $log->log_level = $level;
        $log->message = $message;
        $log->context_json = $context ? json_encode($context) : null;
        $log->created_at = date('Y-m-d H:i:s');
        R::store($log);

        return true;
    }

    /**
     * Get logs for a job
     * @param string $issueKey Jira issue key
     * @param int $limit Max number of logs
     * @return array
     */
    public static function getAiDevJobLogs(string $issueKey, int $limit = 100): array {
        $job = self::getAiDevJob($issueKey);
        if (!$job) {
            return [];
        }

        $jobId = $job->current_shard_job_id ?? $issueKey;
        return array_values(R::find('aidevjoblogs',
            ' job_id = ? ORDER BY created_at ASC LIMIT ? ',
            [$jobId, $limit]
        ));
    }

    // ==================== Anthropic Keys ====================

    /**
     * Get all Anthropic API keys
     * @return array
     */
    public static function getAnthropicKeys(): array {
        return array_values(R::findAll('anthropickeys', ' ORDER BY created_at DESC '));
    }

    /**
     * Get an Anthropic key by ID
     * @param int $id Key ID
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getAnthropicKey(int $id) {
        $key = R::load('anthropickeys', $id);
        return $key->id ? $key : null;
    }

    /**
     * Add an Anthropic API key
     * @param string $name Name
     * @param string $encryptedKey Encrypted API key
     * @param string $model Model
     * @return int Key ID
     */
    public static function addAnthropicKey(string $name, string $encryptedKey, string $model): int {
        $key = R::dispense('anthropickeys');
        $key->name = $name;
        $key->api_key = $encryptedKey;
        $key->model = $model;
        $key->created_at = date('Y-m-d H:i:s');
        return R::store($key);
    }

    /**
     * Delete an Anthropic key
     * @param int $id Key ID
     * @return bool
     */
    public static function deleteAnthropicKey(int $id): bool {
        $key = R::load('anthropickeys', $id);
        if (!$key->id) {
            return false;
        }

        // Reset boards using this key to NULL (local runner)
        R::exec('UPDATE jiraboards SET aidev_anthropic_key_id = NULL WHERE aidev_anthropic_key_id = ?', [$id]);

        R::trash($key);
        return true;
    }
}
