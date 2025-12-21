<?php
/**
 * User Database Connection Manager
 *
 * Handles RedBeanPHP connections to per-user SQLite databases.
 * Each user has their own isolated database file.
 *
 * IMPORTANT: Use the Bean wrapper class for all bean operations!
 * - Bean types must be all lowercase, no underscores: Bean::dispense('aidevjobs')
 * - Use Bean::findOne(), Bean::find(), Bean::store(), etc.
 * - See lib/Bean.php for the full API
 *
 * Usage:
 *   use \app\Bean;
 *
 *   UserDatabase::connect($memberId);
 *   $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
 *   Bean::store($job);
 *   UserDatabase::disconnect();
 *
 * Or use the callback pattern (auto-disconnects):
 *   $job = UserDatabase::with($memberId, function() use ($issueKey) {
 *       return Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
 *   });
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class UserDatabase {

    private static array $connections = [];
    private static ?string $previousDb = null;

    /**
     * Connect to a user's database
     *
     * The user database should already be registered by Flight::getMember() when the
     * member was loaded. This method just selects it. If not registered, it will
     * add it as a fallback (for CLI scripts, etc.).
     *
     * @param int $memberId Member ID
     * @return void
     * @throws \Exception if member or database not found
     */
    public static function connect(int $memberId): void {
        $dbKey = 'user_' . $memberId;

        // Try to select the database (should be registered by Flight::getMember())
        try {
            R::selectDatabase($dbKey);
            self::$previousDb = 'default';
            // Run migrations to ensure schema is up to date
            self::runMigrations();
            return;
        } catch (\Throwable $e) {
            // Database not in registry - need to add it (fallback for CLI, etc.)
        }

        // Make sure we're on the default database before loading member
        R::selectDatabase('default');

        // Load member info from default database
        $member = R::load('member', $memberId);
        if (!$member->id) {
            throw new \Exception("Member not found: {$memberId}");
        }

        // Note: ceobot_db is a legacy column name - using underscore to match existing code
        if (empty($member->ceobot_db)) {
            throw new \Exception("User database not initialized for member: {$memberId}");
        }

        $dbDir = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbPath = $dbDir . $member->ceobot_db . '.sqlite';

        if (!file_exists($dbPath)) {
            throw new \Exception("User database file not found: {$dbPath}");
        }

        // Store current database and add user database
        self::$previousDb = 'default';
        R::addDatabase($dbKey, 'sqlite:' . $dbPath);
        R::selectDatabase($dbKey);

        self::$connections[$dbKey] = $dbPath;

        // Run migrations for this database
        self::runMigrations();
    }

    /**
     * Disconnect from user database and return to default
     */
    public static function disconnect(): void {
        if (self::$previousDb) {
            R::selectDatabase(self::$previousDb);
            self::$previousDb = null;
        } else {
            R::selectDatabase('default');
        }
    }

    /**
     * Execute callback with user database connected
     *
     * @param int $memberId Member ID
     * @param callable $callback Function to execute
     * @return mixed Result of callback
     */
    public static function with(int $memberId, callable $callback) {
        self::connect($memberId);
        try {
            return $callback();
        } finally {
            self::disconnect();
        }
    }

    /**
     * Run migrations for the connected user database
     *
     * Note: R::exec is used here for DDL (schema creation), not CRUD.
     * This is the only acceptable use of R::exec per conventions.
     *
     * IMPORTANT: Table names must be all lowercase, no underscores.
     * This matches RedBeanPHP R::dispense() requirements.
     */
    private static function runMigrations(): void {
        // Create aidevjobs table (AI Developer job tracking)
        R::exec("
            CREATE TABLE IF NOT EXISTS aidevjobs (
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
                updated_at TEXT
            )
        ");

        // Create indexes for aidevjobs
        R::exec("CREATE INDEX IF NOT EXISTS idx_aidevjobs_status ON aidevjobs(status)");
        R::exec("CREATE INDEX IF NOT EXISTS idx_aidevjobs_issue ON aidevjobs(issue_key)");
        R::exec("CREATE INDEX IF NOT EXISTS idx_aidevjobs_board ON aidevjobs(board_id)");

        // Create aidevjoblogs table (AI Developer job logs)
        R::exec("
            CREATE TABLE IF NOT EXISTS aidevjoblogs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_key TEXT NOT NULL,
                log_level TEXT DEFAULT 'info',
                message TEXT NOT NULL,
                context_json TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        R::exec("CREATE INDEX IF NOT EXISTS idx_aidevjoblogs_issue ON aidevjoblogs(issue_key)");

        // Create enterprisesettings table (Enterprise tier settings like API keys)
        R::exec("
            CREATE TABLE IF NOT EXISTS enterprisesettings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_key TEXT NOT NULL UNIQUE,
                setting_value TEXT,
                is_encrypted INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )
        ");
        R::exec("CREATE INDEX IF NOT EXISTS idx_enterprisesettings_key ON enterprisesettings(setting_key)");

        // Create repoconnections table (GitHub/GitLab repository connections)
        R::exec("
            CREATE TABLE IF NOT EXISTS repoconnections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider TEXT NOT NULL DEFAULT 'github',
                repo_url TEXT NOT NULL,
                access_token TEXT,
                is_encrypted INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT
            )
        ");

        // Create boardrepomapping table (Maps Jira boards to repositories)
        R::exec("
            CREATE TABLE IF NOT EXISTS boardrepomapping (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_id INTEGER NOT NULL,
                repo_connection_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        R::exec("CREATE INDEX IF NOT EXISTS idx_boardrepomapping_board ON boardrepomapping(board_id)");
    }

    /**
     * Check if connected to a user database
     */
    public static function isConnected(): bool {
        return self::$previousDb !== null;
    }

    /**
     * Get the database path for a member (for debugging/admin)
     */
    public static function getDbPath(int $memberId): ?string {
        $dbKey = 'user_' . $memberId;
        return self::$connections[$dbKey] ?? null;
    }
}
