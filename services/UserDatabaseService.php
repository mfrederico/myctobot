<?php
/**
 * User Database Service
 * Manages per-user SQLite databases for Jira board data and analysis results
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class UserDatabaseService {

    private $db;
    private $dbPath;
    private $memberId;
    private $dbHash;

    /**
     * Constructor
     *
     * @param int $memberId Member ID
     */
    public function __construct($memberId) {
        $this->memberId = $memberId;
        $member = R::load('member', $memberId);

        if (!$member || !$member->id) {
            throw new \Exception("Member not found: {$memberId}");
        }

        if (empty($member->ceobot_db)) {
            throw new \Exception("User database not initialized for member: {$memberId}");
        }

        $this->dbHash = $member->ceobot_db;
        $dbDir = Flight::get('ceobot.user_db_path') ?? 'database/';
        $this->dbPath = $dbDir . $this->dbHash . '.sqlite';

        if (!file_exists($this->dbPath)) {
            throw new \Exception("User database file not found: {$this->dbPath}");
        }

        $this->db = new \SQLite3($this->dbPath);
        $this->db->busyTimeout(5000);

        // Run migrations for new columns
        $this->runMigrations();
    }

    /**
     * Run database migrations for schema updates
     */
    private function runMigrations() {
        // Get current columns in jira_boards
        $result = $this->db->query("PRAGMA table_info(jira_boards)");
        $columns = [];
        while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[$col['name']] = true;
        }

        // Add digest_cc column if it doesn't exist
        if (!isset($columns['digest_cc'])) {
            $this->db->exec("ALTER TABLE jira_boards ADD COLUMN digest_cc TEXT DEFAULT ''");
        }

        // Add priority_weights column if it doesn't exist
        if (!isset($columns['priority_weights'])) {
            $this->db->exec("ALTER TABLE jira_boards ADD COLUMN priority_weights TEXT");
        }

        // Add goals column if it doesn't exist
        if (!isset($columns['goals'])) {
            $this->db->exec("ALTER TABLE jira_boards ADD COLUMN goals TEXT");
        }

        // Create ticket_analysis_cache table if it doesn't exist
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ticket_analysis_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_id INTEGER NOT NULL,
                issue_key TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                clarity_score INTEGER,
                clarity_analysis TEXT,
                reporter_name TEXT,
                reporter_email TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                UNIQUE(board_id, issue_key),
                FOREIGN KEY (board_id) REFERENCES jira_boards(id) ON DELETE CASCADE
            )
        ");

        // Create indexes for ticket_analysis_cache
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ticket_cache_board ON ticket_analysis_cache(board_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ticket_cache_hash ON ticket_analysis_cache(content_hash)");

        // Migrate ai_dev_jobs to new schema (one record per issue_key)
        $this->migrateAiDevJobs();
    }

    /**
     * Migrate ai_dev_jobs table to new schema (one record per issue_key)
     */
    private function migrateAiDevJobs() {
        // Check if table exists
        $tableExists = $this->db->querySingle(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='ai_dev_jobs'"
        );

        if (!$tableExists) {
            // Create new table from scratch
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_dev_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    issue_key TEXT NOT NULL UNIQUE,
                    board_id INTEGER NOT NULL,
                    repo_connection_id INTEGER,
                    cloud_id TEXT,
                    status TEXT DEFAULT 'pending',
                    current_shard_job_id TEXT,
                    branch_name TEXT,
                    pr_url TEXT,
                    pr_number INTEGER,
                    clarification_comment_id TEXT,
                    clarification_questions TEXT,
                    error_message TEXT,
                    run_count INTEGER DEFAULT 0,
                    last_output TEXT,
                    last_result_json TEXT,
                    files_changed TEXT,
                    commit_sha TEXT,
                    started_at TEXT,
                    completed_at TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT,
                    FOREIGN KEY (repo_connection_id) REFERENCES repo_connections(id) ON DELETE SET NULL
                )
            ");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_status ON ai_dev_jobs(status)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_issue ON ai_dev_jobs(issue_key)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_board ON ai_dev_jobs(board_id)");
            return;
        }

        // Check if we need to migrate (old schema has job_id column, new has current_shard_job_id)
        $result = $this->db->query("PRAGMA table_info(ai_dev_jobs)");
        $columns = [];
        while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[$col['name']] = true;
        }

        // Already migrated
        if (isset($columns['current_shard_job_id'])) {
            return;
        }

        // Need to migrate - create new table, copy data, swap
        $this->db->exec("BEGIN TRANSACTION");

        try {
            // Create new table
            $this->db->exec("
                CREATE TABLE ai_dev_jobs_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    issue_key TEXT NOT NULL UNIQUE,
                    board_id INTEGER NOT NULL,
                    repo_connection_id INTEGER,
                    cloud_id TEXT,
                    status TEXT DEFAULT 'pending',
                    current_shard_job_id TEXT,
                    branch_name TEXT,
                    pr_url TEXT,
                    pr_number INTEGER,
                    clarification_comment_id TEXT,
                    clarification_questions TEXT,
                    error_message TEXT,
                    run_count INTEGER DEFAULT 0,
                    last_output TEXT,
                    last_result_json TEXT,
                    files_changed TEXT,
                    commit_sha TEXT,
                    started_at TEXT,
                    completed_at TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT,
                    FOREIGN KEY (repo_connection_id) REFERENCES repo_connections(id) ON DELETE SET NULL
                )
            ");

            // Migrate data - keep most recent job per issue_key
            $this->db->exec("
                INSERT OR REPLACE INTO ai_dev_jobs_new (
                    issue_key, board_id, repo_connection_id, status, current_shard_job_id,
                    branch_name, pr_url, pr_number, clarification_comment_id,
                    error_message, run_count, started_at, completed_at, created_at, updated_at
                )
                SELECT
                    issue_key, board_id, repo_connection_id, status, job_id,
                    branch_name, pr_url, pr_number, clarification_comment_id,
                    error_message, 1, started_at, completed_at, created_at, updated_at
                FROM ai_dev_jobs
                WHERE id IN (
                    SELECT MAX(id) FROM ai_dev_jobs GROUP BY issue_key
                )
            ");

            // Drop old table and rename new
            $this->db->exec("DROP TABLE ai_dev_jobs");
            $this->db->exec("ALTER TABLE ai_dev_jobs_new RENAME TO ai_dev_jobs");

            // Recreate indexes
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_status ON ai_dev_jobs(status)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_issue ON ai_dev_jobs(issue_key)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_board ON ai_dev_jobs(board_id)");

            $this->db->exec("COMMIT");
        } catch (\Exception $e) {
            $this->db->exec("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Destructor - close database connection
     */
    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }

    // ==================== Board Management ====================

    /**
     * Get all boards
     *
     * @return array
     */
    public function getBoards() {
        $result = $this->db->query("
            SELECT * FROM jira_boards
            ORDER BY board_name ASC
        ");

        $boards = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $boards[] = $row;
        }

        return $boards;
    }

    /**
     * Get boards by cloud ID
     *
     * @param string $cloudId Cloud ID
     * @return array
     */
    public function getBoardsByCloudId($cloudId) {
        $stmt = $this->db->prepare("
            SELECT * FROM jira_boards
            WHERE cloud_id = :cloud_id
            ORDER BY board_name ASC
        ");
        $stmt->bindValue(':cloud_id', $cloudId, SQLITE3_TEXT);
        $result = $stmt->execute();

        $boards = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $boards[] = $row;
        }

        return $boards;
    }

    /**
     * Get enabled boards
     *
     * @return array
     */
    public function getEnabledBoards() {
        $result = $this->db->query("
            SELECT * FROM jira_boards
            WHERE enabled = 1
            ORDER BY board_name ASC
        ");

        $boards = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $boards[] = $row;
        }

        return $boards;
    }

    /**
     * Get boards with digest enabled
     *
     * @return array
     */
    public function getBoardsForDigest() {
        $result = $this->db->query("
            SELECT * FROM jira_boards
            WHERE enabled = 1 AND digest_enabled = 1
            ORDER BY digest_time ASC
        ");

        $boards = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $boards[] = $row;
        }

        return $boards;
    }

    /**
     * Get a single board
     *
     * @param int $id Board ID
     * @return array|null
     */
    public function getBoard($id) {
        $stmt = $this->db->prepare("SELECT * FROM jira_boards WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();

        return $result->fetchArray(SQLITE3_ASSOC) ?: null;
    }

    /**
     * Get a board by Jira board ID and cloud ID
     *
     * @param int $boardId Jira board ID
     * @param string $cloudId Cloud ID
     * @return array|null
     */
    public function getBoardByJiraId($boardId, $cloudId) {
        $stmt = $this->db->prepare("
            SELECT * FROM jira_boards
            WHERE board_id = :board_id AND cloud_id = :cloud_id
        ");
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':cloud_id', $cloudId, SQLITE3_TEXT);
        $result = $stmt->execute();

        return $result->fetchArray(SQLITE3_ASSOC) ?: null;
    }

    /**
     * Add a board
     *
     * @param array $data Board data
     * @return int|false Board ID or false on failure
     */
    public function addBoard($data) {
        $stmt = $this->db->prepare("
            INSERT INTO jira_boards (
                board_id, board_name, project_key, cloud_id, board_type,
                enabled, digest_enabled, digest_time, timezone, status_filter
            ) VALUES (
                :board_id, :board_name, :project_key, :cloud_id, :board_type,
                :enabled, :digest_enabled, :digest_time, :timezone, :status_filter
            )
        ");

        $stmt->bindValue(':board_id', $data['board_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':board_name', $data['board_name'], SQLITE3_TEXT);
        $stmt->bindValue(':project_key', $data['project_key'], SQLITE3_TEXT);
        $stmt->bindValue(':cloud_id', $data['cloud_id'], SQLITE3_TEXT);
        $stmt->bindValue(':board_type', $data['board_type'] ?? 'scrum', SQLITE3_TEXT);
        $stmt->bindValue(':enabled', $data['enabled'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':digest_enabled', $data['digest_enabled'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':digest_time', $data['digest_time'] ?? '08:00', SQLITE3_TEXT);
        $stmt->bindValue(':timezone', $data['timezone'] ?? 'UTC', SQLITE3_TEXT);
        $stmt->bindValue(':status_filter', $data['status_filter'] ?? 'To Do', SQLITE3_TEXT);

        if ($stmt->execute()) {
            return $this->db->lastInsertRowID();
        }

        return false;
    }

    /**
     * Update a board
     *
     * @param int $id Board ID
     * @param array $data Board data
     * @return bool
     */
    public function updateBoard($id, $data) {
        $fields = [];
        $values = [':id' => $id];

        $allowedFields = [
            'board_name', 'project_key', 'board_type', 'enabled',
            'digest_enabled', 'digest_time', 'digest_cc', 'timezone', 'status_filter',
            'priority_weights', 'goals',
            'last_analysis_at', 'last_digest_at',
            // AI Developer status transition settings
            'aidev_status_working', 'aidev_status_pr_created',
            'aidev_status_clarification', 'aidev_status_failed'
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "{$key} = :{$key}";
                $values[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = :updated_at";
        $values[':updated_at'] = date('Y-m-d H:i:s');

        $sql = "UPDATE jira_boards SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        foreach ($values as $key => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }

        return $stmt->execute() !== false;
    }

    /**
     * Remove a board
     *
     * @param int $id Board ID
     * @return bool
     */
    public function removeBoard($id) {
        // Delete associated analysis results first
        $stmt = $this->db->prepare("DELETE FROM analysis_results WHERE board_id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        // Delete digest history
        $stmt = $this->db->prepare("DELETE FROM digest_history WHERE board_id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        // Delete the board
        $stmt = $this->db->prepare("DELETE FROM jira_boards WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    /**
     * Toggle board enabled status
     *
     * @param int $id Board ID
     * @return bool New status
     */
    public function toggleBoard($id) {
        $board = $this->getBoard($id);
        if (!$board) {
            return false;
        }

        $newStatus = $board['enabled'] ? 0 : 1;
        $this->updateBoard($id, ['enabled' => $newStatus]);

        return $newStatus;
    }

    // ==================== Analysis Results ====================

    /**
     * Store analysis results
     *
     * @param int $boardId Board ID
     * @param string $type Analysis type
     * @param array $results Analysis results
     * @param string|null $markdown Markdown content
     * @return int|false Analysis ID or false on failure
     */
    public function storeAnalysis($boardId, $type, $results, $markdown = null) {
        $stmt = $this->db->prepare("
            INSERT INTO analysis_results (
                board_id, analysis_type, content_json, content_markdown,
                issue_count, status_filter
            ) VALUES (
                :board_id, :analysis_type, :content_json, :content_markdown,
                :issue_count, :status_filter
            )
        ");

        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':analysis_type', $type, SQLITE3_TEXT);

        // Sanitize UTF-8 before encoding
        $cleanResults = $this->sanitizeUtf8($results);
        $jsonContent = json_encode($cleanResults, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonContent === false) {
            throw new \Exception('json_encode error: ' . json_last_error_msg());
        }
        $stmt->bindValue(':content_json', $jsonContent, SQLITE3_TEXT);
        $stmt->bindValue(':content_markdown', $markdown, SQLITE3_TEXT);
        $stmt->bindValue(':issue_count', $results['issue_count'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':status_filter', $results['status_filter'] ?? null, SQLITE3_TEXT);

        if ($stmt->execute()) {
            $analysisId = $this->db->lastInsertRowID();

            // Update board's last_analysis_at
            $this->updateBoard($boardId, ['last_analysis_at' => date('Y-m-d H:i:s')]);

            return $analysisId;
        }

        return false;
    }

    /**
     * Get an analysis result
     *
     * @param int $id Analysis ID
     * @return array|null
     */
    public function getAnalysis($id) {
        $stmt = $this->db->prepare("SELECT * FROM analysis_results WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            $row['content'] = json_decode($row['content_json'], true);
        }

        return $row ?: null;
    }

    /**
     * Get recent analyses for a board
     *
     * @param int $boardId Board ID
     * @param int $limit Number of results
     * @return array
     */
    public function getRecentAnalyses($boardId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM analysis_results
            WHERE board_id = :board_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $analyses = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['content'] = json_decode($row['content_json'], true);
            $analyses[] = $row;
        }

        return $analyses;
    }

    /**
     * Get all recent analyses across all boards
     *
     * @param int $limit Number of results
     * @return array
     */
    public function getAllRecentAnalyses($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT a.*, b.board_name, b.project_key
            FROM analysis_results a
            JOIN jira_boards b ON a.board_id = b.id
            ORDER BY a.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $analyses = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['content'] = json_decode($row['content_json'], true);
            $analyses[] = $row;
        }

        return $analyses;
    }

    /**
     * Delete old analyses (keep last N per board)
     *
     * @param int $keepCount Number of analyses to keep per board
     * @return int Number of deleted records
     */
    public function cleanupOldAnalyses($keepCount = 50) {
        $boards = $this->getBoards();
        $deleted = 0;

        foreach ($boards as $board) {
            $stmt = $this->db->prepare("
                DELETE FROM analysis_results
                WHERE board_id = :board_id
                AND id NOT IN (
                    SELECT id FROM analysis_results
                    WHERE board_id = :board_id2
                    ORDER BY created_at DESC
                    LIMIT :keep_count
                )
            ");
            $stmt->bindValue(':board_id', $board['id'], SQLITE3_INTEGER);
            $stmt->bindValue(':board_id2', $board['id'], SQLITE3_INTEGER);
            $stmt->bindValue(':keep_count', $keepCount, SQLITE3_INTEGER);
            $stmt->execute();

            $deleted += $this->db->changes();
        }

        return $deleted;
    }

    // ==================== Digest History ====================

    /**
     * Log a digest email
     *
     * @param int $boardId Board ID
     * @param string $sentTo Email address
     * @param string $subject Email subject
     * @param string $contentPreview Content preview
     * @param string $status Status (sent, failed)
     * @param string|null $errorMessage Error message if failed
     * @return int|false
     */
    public function logDigest($boardId, $sentTo, $subject, $contentPreview, $status = 'sent', $errorMessage = null) {
        $stmt = $this->db->prepare("
            INSERT INTO digest_history (
                board_id, sent_to, subject, content_preview, status, error_message
            ) VALUES (
                :board_id, :sent_to, :subject, :content_preview, :status, :error_message
            )
        ");

        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':sent_to', $sentTo, SQLITE3_TEXT);
        $stmt->bindValue(':subject', $subject, SQLITE3_TEXT);
        $stmt->bindValue(':content_preview', substr($contentPreview, 0, 500), SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':error_message', $errorMessage, SQLITE3_TEXT);

        if ($stmt->execute()) {
            // Update board's last_digest_at
            $this->updateBoard($boardId, ['last_digest_at' => date('Y-m-d H:i:s')]);

            return $this->db->lastInsertRowID();
        }

        return false;
    }

    /**
     * Get digest history for a board
     *
     * @param int $boardId Board ID
     * @param int $limit Number of results
     * @return array
     */
    public function getDigestHistory($boardId, $limit = 20) {
        $stmt = $this->db->prepare("
            SELECT * FROM digest_history
            WHERE board_id = :board_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $history[] = $row;
        }

        return $history;
    }

    // ==================== User Settings ====================

    /**
     * Get a setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getSetting($key, $default = null) {
        $stmt = $this->db->prepare("SELECT value FROM user_settings WHERE key = :key");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['value'] : $default;
    }

    /**
     * Set a setting
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool
     */
    public function setSetting($key, $value) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO user_settings (key, value, updated_at)
            VALUES (:key, :value, :updated_at)
        ");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    /**
     * Get all settings
     *
     * @return array
     */
    public function getAllSettings() {
        $result = $this->db->query("SELECT * FROM user_settings");

        $settings = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }

    // ==================== Statistics ====================

    /**
     * Get dashboard statistics
     *
     * @return array
     */
    public function getStats() {
        $stats = [
            'total_boards' => 0,
            'enabled_boards' => 0,
            'digest_enabled_boards' => 0,
            'total_analyses' => 0,
            'total_digests' => 0,
            'recent_analyses' => []
        ];

        // Board counts
        $result = $this->db->query("SELECT COUNT(*) as count FROM jira_boards");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_boards'] = $row['count'];

        $result = $this->db->query("SELECT COUNT(*) as count FROM jira_boards WHERE enabled = 1");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['enabled_boards'] = $row['count'];

        $result = $this->db->query("SELECT COUNT(*) as count FROM jira_boards WHERE digest_enabled = 1");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['digest_enabled_boards'] = $row['count'];

        // Analysis count
        $result = $this->db->query("SELECT COUNT(*) as count FROM analysis_results");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_analyses'] = $row['count'];

        // Digest count
        $result = $this->db->query("SELECT COUNT(*) as count FROM digest_history WHERE status = 'sent'");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_digests'] = $row['count'];

        // Recent analyses
        $stats['recent_analyses'] = $this->getAllRecentAnalyses(5);

        return $stats;
    }

    // ==================== Ticket Analysis Cache ====================

    /**
     * Get cached analysis for a ticket
     *
     * @param int $boardId Board ID
     * @param string $issueKey Issue key (e.g., "PROJ-123")
     * @return array|null
     */
    public function getTicketAnalysisCache($boardId, $issueKey) {
        $stmt = $this->db->prepare("
            SELECT * FROM ticket_analysis_cache
            WHERE board_id = :board_id AND issue_key = :issue_key
        ");
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':issue_key', $issueKey, SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row && $row['clarity_analysis']) {
            $row['clarity_analysis'] = json_decode($row['clarity_analysis'], true);
        }

        return $row ?: null;
    }

    /**
     * Set cached analysis for a ticket
     *
     * @param int $boardId Board ID
     * @param string $issueKey Issue key
     * @param string $contentHash Hash of ticket content
     * @param array $data Analysis data (clarity_score, clarity_analysis, reporter_name, reporter_email)
     * @return bool
     */
    public function setTicketAnalysisCache($boardId, $issueKey, $contentHash, $data) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO ticket_analysis_cache (
                board_id, issue_key, content_hash, clarity_score, clarity_analysis,
                reporter_name, reporter_email, updated_at
            ) VALUES (
                :board_id, :issue_key, :content_hash, :clarity_score, :clarity_analysis,
                :reporter_name, :reporter_email, :updated_at
            )
        ");

        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':issue_key', $issueKey, SQLITE3_TEXT);
        $stmt->bindValue(':content_hash', $contentHash, SQLITE3_TEXT);
        $stmt->bindValue(':clarity_score', $data['clarity_score'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':clarity_analysis', isset($data['clarity_analysis']) ? json_encode($data['clarity_analysis']) : null, SQLITE3_TEXT);
        $stmt->bindValue(':reporter_name', $data['reporter_name'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':reporter_email', $data['reporter_email'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    /**
     * Check if ticket needs re-analysis based on content hash
     *
     * @param int $boardId Board ID
     * @param string $issueKey Issue key
     * @param string $newHash New content hash
     * @return bool True if re-analysis needed
     */
    public function shouldReanalyzeTicket($boardId, $issueKey, $newHash) {
        $cached = $this->getTicketAnalysisCache($boardId, $issueKey);

        if (!$cached) {
            return true; // No cache exists
        }

        return $cached['content_hash'] !== $newHash;
    }

    /**
     * Get all cached analyses for a board
     *
     * @param int $boardId Board ID
     * @return array
     */
    public function getAllTicketAnalysisCache($boardId) {
        $stmt = $this->db->prepare("
            SELECT * FROM ticket_analysis_cache
            WHERE board_id = :board_id
            ORDER BY issue_key ASC
        ");
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $caches = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['clarity_analysis']) {
                $row['clarity_analysis'] = json_decode($row['clarity_analysis'], true);
            }
            $caches[$row['issue_key']] = $row;
        }

        return $caches;
    }

    /**
     * Clear old ticket analysis cache for a board
     *
     * @param int $boardId Board ID
     * @param int $daysOld Delete entries older than this many days
     * @return int Number of deleted records
     */
    public function clearOldTicketAnalysisCache($boardId, $daysOld = 30) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $stmt = $this->db->prepare("
            DELETE FROM ticket_analysis_cache
            WHERE board_id = :board_id AND updated_at < :cutoff
        ");
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->changes();
    }

    /**
     * Generate content hash for a ticket
     *
     * @param array $issue Issue data from Jira
     * @return string SHA256 hash
     */
    public static function generateTicketHash($issue) {
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

    // ==================== Helpers ====================

    /**
     * Recursively sanitize UTF-8 strings in an array
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private function sanitizeUtf8($data) {
        if (is_string($data)) {
            // Convert to UTF-8 and remove invalid sequences
            $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            // Remove any remaining invalid UTF-8 characters
            return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeUtf8($value);
            }
        }

        return $data;
    }

    // ==================== AI Developer Jobs ====================

    /**
     * Get or create a job record for an issue
     *
     * @param string $issueKey Jira issue key
     * @param int $boardId Board ID
     * @param int|null $repoConnectionId Repository connection ID
     * @param string|null $cloudId Atlassian cloud ID
     * @return array Job record
     */
    public function getOrCreateAiDevJob(string $issueKey, int $boardId, ?int $repoConnectionId = null, ?string $cloudId = null): array {
        $stmt = $this->db->prepare("SELECT * FROM ai_dev_jobs WHERE issue_key = :issue_key");
        $stmt->bindValue(':issue_key', $issueKey, SQLITE3_TEXT);
        $result = $stmt->execute();
        $job = $result->fetchArray(SQLITE3_ASSOC);

        if ($job) {
            return $job;
        }

        // Create new job record
        $stmt = $this->db->prepare("
            INSERT INTO ai_dev_jobs (issue_key, board_id, repo_connection_id, cloud_id, status, run_count, created_at)
            VALUES (:issue_key, :board_id, :repo_id, :cloud_id, 'pending', 0, datetime('now'))
        ");
        $stmt->bindValue(':issue_key', $issueKey, SQLITE3_TEXT);
        $stmt->bindValue(':board_id', $boardId, SQLITE3_INTEGER);
        $stmt->bindValue(':repo_id', $repoConnectionId, $repoConnectionId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':cloud_id', $cloudId, $cloudId ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->execute();

        return $this->getAiDevJob($issueKey);
    }

    /**
     * Get a job by issue key
     *
     * @param string $issueKey Jira issue key
     * @return array|null
     */
    public function getAiDevJob(string $issueKey): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ai_dev_jobs WHERE issue_key = :issue_key");
        $stmt->bindValue(':issue_key', $issueKey, SQLITE3_TEXT);
        $result = $stmt->execute();
        $job = $result->fetchArray(SQLITE3_ASSOC);

        if ($job) {
            $job['clarification_questions'] = json_decode($job['clarification_questions'] ?? '[]', true);
            $job['files_changed'] = json_decode($job['files_changed'] ?? '[]', true);
            $job['last_result'] = json_decode($job['last_result_json'] ?? '{}', true);
        }

        return $job ?: null;
    }

    /**
     * Update a job's status and related fields
     *
     * @param string $issueKey Jira issue key
     * @param array $data Fields to update
     * @return bool
     */
    public function updateAiDevJob(string $issueKey, array $data): bool {
        $allowedFields = [
            'status', 'current_shard_job_id', 'branch_name', 'pr_url', 'pr_number',
            'clarification_comment_id', 'clarification_questions', 'error_message',
            'run_count', 'last_output', 'last_result_json', 'files_changed',
            'commit_sha', 'started_at', 'completed_at', 'board_id', 'repo_connection_id', 'cloud_id'
        ];

        $fields = [];
        $values = [':issue_key' => $issueKey];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                // JSON encode arrays
                if (in_array($key, ['clarification_questions', 'files_changed']) && is_array($value)) {
                    $value = json_encode($value);
                }
                if ($key === 'last_result_json' && is_array($value)) {
                    $value = json_encode($value);
                }
                $fields[] = "{$key} = :{$key}";
                $values[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = datetime('now')";

        $sql = "UPDATE ai_dev_jobs SET " . implode(', ', $fields) . " WHERE issue_key = :issue_key";
        $stmt = $this->db->prepare($sql);

        foreach ($values as $key => $value) {
            if (is_null($value)) {
                $stmt->bindValue($key, null, SQLITE3_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, SQLITE3_INTEGER);
            } else {
                $stmt->bindValue($key, $value, SQLITE3_TEXT);
            }
        }

        return $stmt->execute() !== false;
    }

    /**
     * Start a new run on a job
     *
     * @param string $issueKey Jira issue key
     * @param string $shardJobId The shard job ID for this run
     * @return bool
     */
    public function startAiDevJobRun(string $issueKey, string $shardJobId): bool {
        return $this->updateAiDevJob($issueKey, [
            'status' => 'running',
            'current_shard_job_id' => $shardJobId,
            'started_at' => date('Y-m-d H:i:s'),
            'error_message' => null,
            'completed_at' => null
        ]);

        // Also increment run_count
        $this->db->exec("UPDATE ai_dev_jobs SET run_count = run_count + 1 WHERE issue_key = '" . $this->db->escapeString($issueKey) . "'");

        return true;
    }

    /**
     * Complete a job run with PR info
     *
     * @param string $issueKey Jira issue key
     * @param string $prUrl PR URL
     * @param int|null $prNumber PR number
     * @param string|null $branchName Branch name
     * @param string|null $output Full output log
     * @param array|null $result Result data
     * @return bool
     */
    public function completeAiDevJob(string $issueKey, string $prUrl, ?int $prNumber, ?string $branchName = null, ?string $output = null, ?array $result = null): bool {
        return $this->updateAiDevJob($issueKey, [
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
     *
     * @param string $issueKey Jira issue key
     * @param string $error Error message
     * @param string|null $output Output log
     * @return bool
     */
    public function failAiDevJob(string $issueKey, string $error, ?string $output = null): bool {
        return $this->updateAiDevJob($issueKey, [
            'status' => 'failed',
            'error_message' => $error,
            'last_output' => $output,
            'completed_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Mark a job as waiting for clarification
     *
     * @param string $issueKey Jira issue key
     * @param string $commentId The Jira comment ID with questions
     * @param array $questions The clarification questions
     * @return bool
     */
    public function setAiDevJobWaitingClarification(string $issueKey, string $commentId, array $questions): bool {
        return $this->updateAiDevJob($issueKey, [
            'status' => 'waiting_clarification',
            'clarification_comment_id' => $commentId,
            'clarification_questions' => $questions
        ]);
    }

    /**
     * Get all jobs (for listing)
     *
     * @param int $limit Max number of jobs
     * @return array
     */
    public function getAllAiDevJobs(int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT * FROM ai_dev_jobs
            ORDER BY COALESCE(updated_at, created_at) DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['clarification_questions'] = json_decode($row['clarification_questions'] ?? '[]', true);
            $row['files_changed'] = json_decode($row['files_changed'] ?? '[]', true);
            $jobs[] = $row;
        }

        return $jobs;
    }

    /**
     * Get active jobs (running, pending, or waiting_clarification)
     *
     * @return array
     */
    public function getActiveAiDevJobs(): array {
        $result = $this->db->query("
            SELECT * FROM ai_dev_jobs
            WHERE status IN ('pending', 'running', 'waiting_clarification')
            ORDER BY started_at DESC
        ");

        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['clarification_questions'] = json_decode($row['clarification_questions'] ?? '[]', true);
            $row['files_changed'] = json_decode($row['files_changed'] ?? '[]', true);
            $jobs[] = $row;
        }

        return $jobs;
    }

    /**
     * Check if a job is currently running for an issue
     *
     * @param string $issueKey Jira issue key
     * @return bool
     */
    public function isAiDevJobRunning(string $issueKey): bool {
        $job = $this->getAiDevJob($issueKey);
        return $job && $job['status'] === 'running';
    }

    /**
     * Delete a job record
     *
     * @param string $issueKey Jira issue key
     * @return bool
     */
    public function deleteAiDevJob(string $issueKey): bool {
        $stmt = $this->db->prepare("DELETE FROM ai_dev_jobs WHERE issue_key = :issue_key");
        $stmt->bindValue(':issue_key', $issueKey, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }

    /**
     * Add a log entry for a job
     *
     * @param string $issueKey Jira issue key
     * @param string $level Log level
     * @param string $message Log message
     * @param array|null $context Context data
     * @return bool
     */
    public function addAiDevJobLog(string $issueKey, string $level, string $message, ?array $context = null): bool {
        // First get the current_shard_job_id for this issue
        $job = $this->getAiDevJob($issueKey);
        $jobId = $job['current_shard_job_id'] ?? $issueKey;

        $stmt = $this->db->prepare("
            INSERT INTO ai_dev_job_logs (job_id, log_level, message, context_json, created_at)
            VALUES (:job_id, :level, :message, :context, datetime('now'))
        ");
        $stmt->bindValue(':job_id', $jobId, SQLITE3_TEXT);
        $stmt->bindValue(':level', $level, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':context', $context ? json_encode($context) : null, $context ? SQLITE3_TEXT : SQLITE3_NULL);

        return $stmt->execute() !== false;
    }

    /**
     * Get logs for a job
     *
     * @param string $issueKey Jira issue key
     * @param int $limit Max number of logs
     * @return array
     */
    public function getAiDevJobLogs(string $issueKey, int $limit = 100): array {
        $job = $this->getAiDevJob($issueKey);
        if (!$job) {
            return [];
        }

        $jobId = $job['current_shard_job_id'] ?? $issueKey;

        $stmt = $this->db->prepare("
            SELECT * FROM ai_dev_job_logs
            WHERE job_id = :job_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':job_id', $jobId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $logs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['context'] = json_decode($row['context_json'] ?? '{}', true);
            $logs[] = $row;
        }

        return array_reverse($logs); // Oldest first for display
    }
}
