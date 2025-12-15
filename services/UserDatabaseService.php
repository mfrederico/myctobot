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
            'last_analysis_at', 'last_digest_at'
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
}
