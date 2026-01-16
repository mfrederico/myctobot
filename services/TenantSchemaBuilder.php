<?php
/**
 * TenantSchemaBuilder - Creates tenant database schema using RedBeanPHP
 *
 * Uses RedBeanPHP associations to create tables with proper foreign keys.
 * Non-FK identifiers use _uid suffix to avoid RedBeanPHP's integer inference.
 *
 * @see https://redbeanphp.com/index.php?p=/association
 */

namespace app\services;

use RedBeanPHP\R as R;

class TenantSchemaBuilder {

    private string $dbName;
    private string $dbUser;
    private string $dbPass;
    private string $dbHost;

    // Temporary beans for associations
    private $member;
    private $board;
    private $repo;
    private $directive;

    // Track which beans were CREATED (temp) vs FOUND (existing) - only delete created ones
    private bool $memberIsTemp = false;
    private bool $boardIsTemp = false;
    private bool $repoIsTemp = false;
    private bool $directiveIsTemp = false;

    // Track if force-drop has been done this session
    private bool $forceDropDone = false;

    public function __construct(string $dbName, string $dbUser, string $dbPass, string $dbHost = 'localhost') {
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->dbHost = $dbHost;
    }

    /**
     * Build the complete tenant schema using RedBeanPHP
     */
    public function build(): void {
        // Add tenant database as a secondary connection
        $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
        R::addDatabase('tenant_provision', $dsn, $this->dbUser, $this->dbPass);
        R::selectDatabase('tenant_provision');
        R::freeze(false); // Allow schema modifications

        // Create base tables first (no dependencies)
        $this->member = $this->createMemberTable();
        $this->createAuthControlTable();

        // Create tables with member association
        $this->createJiraBoardsTable();
        $this->board = $this->createBoardsTable(); // Generic boards - used for FK associations
        $this->boardIsTemp = true; // Mark for cleanup
        $this->repo = $this->createRepoConnectionsTable();
        $this->repoIsTemp = true; // Mark for cleanup
        $this->createAtlassianTokenTable();
        $this->createGitHubTokensTable();
        $this->createEnterpriseSettingsTable();
        $this->createAnthropicKeysTable();
        $this->createAIAgentsTable();
        $this->addAiagentsToRepoConnections(); // Add aiagents_id FK to repoconnections
        $this->createMcpServersTable();
        $this->createSSHKeysTable();

        // Create tables with board/repo associations
        $this->createBoardRepoMappingTable();
        $this->createAnalysisResultsTable();
        $this->createDigestHistoryTable();
        $this->createTicketAnalysisCacheTable();
        $this->createAIDevJobsTable();
        $this->createAIDevJobLogsTable();
        $this->createDirectivesTable();
        $this->directive = $this->createCeoDirectivesTable();
        $this->createDirectiveLogsTable();
        $this->createCtoProjectsTable();
        $this->createCtoEpicsTable();
        $this->createCtoStoriesTable();
        $this->createProjectsTable();
        $this->createPluginTables();


        // Clean up temporary beans
        $this->cleanupTempBeans();

        // Insert default authcontrol permissions
        $this->insertDefaultPermissions();

        // Switch back to default database
        R::selectDatabase('default');
    }

    /**
     * Run a specific migration (create table method) on an existing database
     * Usage: $builder->runMigration('SSHKeys') to run createSSHKeysTable()
     *
     * @param string $tableName The table name (e.g., 'SSHKeys', 'AIAgents')
     * @param bool $force Force run even if already recorded as migrated
     * @return array Result with success status and message
     */
    public function runMigration(string $tableName, bool $force = false): array {
        // Support createXxxTable, renameXxx, and migrateXxx methods
        if (str_starts_with($tableName, 'Rename')) {
            $methodName = lcfirst($tableName); // RenameBoardIdToJiraboardsId -> renameBoardIdToJiraboardsId
        } elseif (str_starts_with($tableName, 'Migrate')) {
            $methodName = lcfirst($tableName); // MigrateJiraboardsToBoards -> migrateJiraboardsToBoards
        } else {
            $methodName = 'create' . $tableName . 'Table';
        }

        if (!method_exists($this, $methodName)) {
            return [
                'success' => false,
                'error' => "Migration method '$methodName' not found"
            ];
        }

        try {
            // Use a unique connection name per tenant to avoid collisions
            $connName = 'migration_' . md5($this->dbName);

            // Connect to tenant database (check if already connected)
            $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
            try {
                R::selectDatabase($connName);
            } catch (\Exception $e) {
                // Database not registered yet, add it
                R::addDatabase($connName, $dsn, $this->dbUser, $this->dbPass);
                R::selectDatabase($connName);
            }
            R::freeze(false);

            // Ensure migrations table exists
            $this->ensureMigrationsTable();

            // Check if already migrated (unless forced)
            if (!$force) {
                $existing = R::findOne('schemamigrations', 'migration_name = ?', [$tableName]);
                if ($existing && $existing->id) {
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => "Migration '$tableName' already applied on " . $existing->applied_at
                    ];
                }
            }

            // Set up base dependencies if needed (pass force to allow dropping bad tables)
            $this->setupMigrationDependencies($force);

            // Try to run the migration
            $migrationError = null;
            try {
                $this->$methodName();
            } catch (\Exception $e) {
                $migrationError = $e;
            }

            // If migration failed and force is set, drop the table and retry
            if ($migrationError && $force) {
                $actualTableName = $this->getActualTableName($tableName);

                // Drop the table completely
                $this->dropTable($actualTableName);

                // Reset dependencies (they may have been affected)
                $this->member = null;
                $this->board = null;
                $this->repo = null;
                $this->directive = null;
                $this->setupMigrationDependencies($force);

                // Retry the migration
                $this->$methodName();
                $migrationError = null; // Success on retry
            }

            // If still have an error, throw it
            if ($migrationError) {
                throw $migrationError;
            }

            // Record the migration
            $this->recordMigration($tableName);

            // Clean up temporary beans
            $this->cleanupTempBeans();

            return [
                'success' => true,
                'rebuilt' => $force,
                'message' => "Migration '$tableName' completed successfully"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Map migration name to actual database table name
     * RedBeanPHP tables are always lowercase
     */
    private function getActualTableName(string $migrationName): string {
        return strtolower($migrationName);
    }

    /**
     * Ensure the schemamigrations table exists
     */
    private function ensureMigrationsTable(): void {
        // Check if table exists by trying to query it
        try {
            R::getAll('SELECT 1 FROM schemamigrations LIMIT 1');
        } catch (\Exception $e) {
            // Table doesn't exist, create it - ensure unfrozen
            R::freeze(false);
            $bean = R::dispense('schemamigrations');
            $bean->migration_name = '_schema_init';
            $bean->applied_at = date('Y-m-d H:i:s');
            $bean->batch = 0;
            R::store($bean);
            R::trash($bean);
        }
    }

    /**
     * Record a completed migration in the tracking table (if not already recorded)
     */
    private function recordMigration(string $tableName): void {
        R::freeze(false); // Ensure unfrozen for schema modifications

        // Check if already recorded (don't duplicate on force re-runs)
        $existing = R::findOne('schemamigrations', 'migration_name = ?', [$tableName]);
        if ($existing && $existing->id) {
            // Update the applied_at timestamp instead of creating duplicate
            $existing->applied_at = date('Y-m-d H:i:s');
            R::store($existing);
            return;
        }

        // Get current batch number
        $lastBatch = R::getCell('SELECT MAX(batch) FROM schemamigrations') ?? 0;

        $bean = R::dispense('schemamigrations');
        $bean->migration_name = $tableName;
        $bean->applied_at = date('Y-m-d H:i:s');
        $bean->batch = $lastBatch + 1;
        R::store($bean);
    }

    /**
     * Mark a migration as applied without running it
     * Used for existing databases that already have the tables
     *
     * @param string $tableName The table name (e.g., 'SSHKeys')
     * @return array Result with success status
     */
    public function markMigration(string $tableName): array {
        // Validate migration exists
        $availableMigrations = self::getAvailableMigrations();
        if (!in_array($tableName, $availableMigrations)) {
            return [
                'success' => false,
                'error' => "Migration '$tableName' not found"
            ];
        }

        try {
            $connName = 'migration_' . md5($this->dbName);
            $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
            try {
                R::selectDatabase($connName);
            } catch (\Exception $e) {
                R::addDatabase($connName, $dsn, $this->dbUser, $this->dbPass);
                R::selectDatabase($connName);
            }
            R::freeze(false);

            $this->ensureMigrationsTable();

            // Check if already recorded
            $existing = R::findOne('schemamigrations', 'migration_name = ?', [$tableName]);
            if ($existing && $existing->id) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => "Migration '$tableName' already marked as applied"
                ];
            }

            // Record it
            $this->recordMigration($tableName);

            return [
                'success' => true,
                'message' => "Migration '$tableName' marked as applied"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mark all migrations as applied for a tenant
     *
     * @return array Result with summary
     */
    public function markAllMigrations(): array {
        $migrations = self::getAvailableMigrations();
        $marked = [];
        $skipped = [];
        $errors = [];

        foreach ($migrations as $migration) {
            $result = $this->markMigration($migration);
            if ($result['success']) {
                if (!empty($result['skipped'])) {
                    $skipped[] = $migration;
                } else {
                    $marked[] = $migration;
                }
            } else {
                $errors[] = ['migration' => $migration, 'error' => $result['error']];
            }
        }

        return [
            'success' => empty($errors),
            'marked' => $marked,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => count($marked) . ' marked, ' . count($skipped) . ' already applied'
        ];
    }

    /**
     * Mark migrations as applied for a tenant by slug
     *
     * @param string|array $migrations Migration name(s) or 'all'
     * @param string $tenantSlug Tenant slug
     * @return array Result
     */
    public static function markForTenant($migrations, string $tenantSlug): array {
        $projectDir = dirname(__DIR__);
        $configFile = $projectDir . '/conf/config.' . $tenantSlug . '.ini';

        if (!file_exists($configFile)) {
            return ['success' => false, 'error' => "Config file not found"];
        }

        $config = parse_ini_file($configFile, true);
        if (!isset($config['database'])) {
            return ['success' => false, 'error' => "No [database] section in config"];
        }

        $db = $config['database'];
        $builder = new self(
            $db['name'],
            $db['user'],
            $db['pass'] ?? '',
            $db['host'] ?? 'localhost'
        );

        if ($migrations === 'all') {
            return $builder->markAllMigrations();
        }

        if (is_string($migrations)) {
            return $builder->markMigration($migrations);
        }

        // Array of migrations
        $marked = [];
        $skipped = [];
        $errors = [];

        foreach ($migrations as $migration) {
            $result = $builder->markMigration($migration);
            if ($result['success']) {
                if (!empty($result['skipped'])) {
                    $skipped[] = $migration;
                } else {
                    $marked[] = $migration;
                }
            } else {
                $errors[] = ['migration' => $migration, 'error' => $result['error']];
            }
        }

        return [
            'success' => empty($errors),
            'marked' => $marked,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Get list of pending migrations for a tenant
     *
     * @return array Result with pending migrations list
     */
    public function getPendingMigrations(): array {
        try {
            $connName = 'migration_' . md5($this->dbName);
            $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
            try {
                R::selectDatabase($connName);
            } catch (\Exception $e) {
                R::addDatabase($connName, $dsn, $this->dbUser, $this->dbPass);
                R::selectDatabase($connName);
            }
            R::freeze(false);

            $this->ensureMigrationsTable();

            $allMigrations = self::getAvailableMigrations();
            $appliedRows = R::getAll('SELECT migration_name FROM schemamigrations');
            $applied = array_column($appliedRows, 'migration_name');

            $pending = array_diff($allMigrations, $applied);

            R::close();

            return [
                'success' => true,
                'pending' => array_values($pending),
                'applied' => $applied
            ];

        } catch (\Exception $e) {
            try { R::close(); } catch (\Exception $ignore) {}
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Run all pending migrations
     *
     * @return array Result with summary of what was run
     */
    public function runPendingMigrations(): array {
        $pendingResult = $this->getPendingMigrations();
        if (!$pendingResult['success']) {
            return $pendingResult;
        }

        $pending = $pendingResult['pending'];
        if (empty($pending)) {
            return [
                'success' => true,
                'message' => 'All migrations are up to date',
                'ran' => []
            ];
        }

        $ran = [];
        $failed = [];

        foreach ($pending as $migration) {
            $result = $this->runMigration($migration);
            if ($result['success'] && empty($result['skipped'])) {
                $ran[] = $migration;
            } elseif (!$result['success']) {
                $failed[] = ['migration' => $migration, 'error' => $result['error']];
            }
        }

        return [
            'success' => empty($failed),
            'ran' => $ran,
            'failed' => $failed,
            'message' => count($ran) . ' migrations applied' . (count($failed) ? ', ' . count($failed) . ' failed' : '')
        ];
    }

    /**
     * Run pending migrations for a specific tenant by slug
     *
     * @param string $tenantSlug Tenant slug (e.g., 'gwt', 'tiknix')
     * @return array Result with summary of what was run
     */
    public static function syncTenant(string $tenantSlug): array {
        $projectDir = dirname(__DIR__);
        $configFile = $projectDir . '/conf/config.' . $tenantSlug . '.ini';

        if (!file_exists($configFile)) {
            return [
                'success' => false,
                'error' => "Config file not found: $configFile"
            ];
        }

        $config = parse_ini_file($configFile, true);

        if (!isset($config['database'])) {
            return [
                'success' => false,
                'error' => "No [database] section in config file"
            ];
        }

        $db = $config['database'];
        if (empty($db['name']) || empty($db['user'])) {
            return [
                'success' => false,
                'error' => "Missing database name or user in config"
            ];
        }

        $builder = new self(
            $db['name'],
            $db['user'],
            $db['pass'] ?? '',
            $db['host'] ?? 'localhost'
        );

        return $builder->runPendingMigrations();
    }

    /**
     * Get migration status for a tenant
     *
     * @param string $tenantSlug Tenant slug
     * @return array Status with applied and pending migrations
     */
    public static function getTenantStatus(string $tenantSlug): array {
        $projectDir = dirname(__DIR__);
        $configFile = $projectDir . '/conf/config.' . $tenantSlug . '.ini';

        if (!file_exists($configFile)) {
            return [
                'success' => false,
                'error' => "Config file not found: $configFile"
            ];
        }

        $config = parse_ini_file($configFile, true);

        if (!isset($config['database'])) {
            return [
                'success' => false,
                'error' => "No [database] section in config file"
            ];
        }

        $db = $config['database'];
        $builder = new self(
            $db['name'],
            $db['user'],
            $db['pass'] ?? '',
            $db['host'] ?? 'localhost'
        );

        return $builder->getPendingMigrations();
    }

    /**
     * Get list of available migrations (create*Table methods)
     */
    public static function getAvailableMigrations(): array {
        $methods = get_class_methods(self::class);
        $migrations = [];

        foreach ($methods as $method) {
            // Match createXxxTable methods (table creation)
            if (preg_match('/^create(.+)Table$/', $method, $matches)) {
                $migrations[] = $matches[1];
            }
            // Match renameXxx methods (column/table renames)
            if (preg_match('/^rename(.+)$/', $method, $matches)) {
                $migrations[] = 'Rename' . $matches[1];
            }
            // Match migrateXxx methods (data migrations)
            if (preg_match('/^migrate(.+)$/', $method, $matches)) {
                $migrations[] = 'Migrate' . $matches[1];
            }
        }

        sort($migrations);
        return $migrations;
    }

    /**
     * Run a migration for a specific tenant by slug
     * Loads config from conf/config.{tenant}.ini automatically
     *
     * @param string $migration Migration name (e.g., 'SSHKeys')
     * @param string $tenantSlug Tenant slug (e.g., 'gwt', 'tiknix')
     * @return array Result with success status and message
     */
    public static function runMigrationForTenant(string $migration, string $tenantSlug): array {
        $projectDir = dirname(__DIR__);
        $configFile = $projectDir . '/conf/config.' . $tenantSlug . '.ini';

        if (!file_exists($configFile)) {
            return [
                'success' => false,
                'error' => "Config file not found: $configFile"
            ];
        }

        $config = parse_ini_file($configFile, true);

        if (!isset($config['database'])) {
            return [
                'success' => false,
                'error' => "No [database] section in config file"
            ];
        }

        $db = $config['database'];
        if (empty($db['name']) || empty($db['user'])) {
            return [
                'success' => false,
                'error' => "Missing database name or user in config"
            ];
        }

        $builder = new self(
            $db['name'],
            $db['user'],
            $db['pass'] ?? '',
            $db['host'] ?? 'localhost'
        );

        return $builder->runMigration($migration);
    }

    /**
     * Get list of available tenants from config files
     *
     * @return array Array of tenant info [slug => ['db_name' => ..., 'config_file' => ...]]
     */
    public static function getAvailableTenants(): array {
        $projectDir = dirname(__DIR__);
        $tenants = [];
        $configFiles = glob($projectDir . '/conf/config.*.ini');

        foreach ($configFiles as $configFile) {
            $filename = basename($configFile);

            // Skip example config
            if (strpos($filename, 'example') !== false) {
                continue;
            }

            // Extract tenant slug from filename: config.{slug}.ini
            if (preg_match('/^config\.(.+)\.ini$/', $filename, $matches)) {
                $slug = $matches[1];
                $config = parse_ini_file($configFile, true);

                if (isset($config['database'])) {
                    $tenants[$slug] = [
                        'slug' => $slug,
                        'config_file' => $configFile,
                        'db_name' => $config['database']['name'] ?? null,
                        'db_user' => $config['database']['user'] ?? null,
                        'db_host' => $config['database']['host'] ?? 'localhost'
                    ];
                }
            }
        }

        ksort($tenants);
        return $tenants;
    }

    /**
     * Set up dependencies needed for migrations (member, board, repo beans)
     * @param bool $force If true, drop and rebuild tables that fail due to schema issues
     */
    private function setupMigrationDependencies(bool $force = false): void {
        // If force mode, drop all core tables upfront to avoid FK type mismatches (only once per session)
        if ($force && !$this->forceDropDone) {
            $this->dropAllCoreTables();
            $this->forceDropDone = true;
        }

        // Try to load existing member, or create temp one
        $this->member = R::findOne('member', ' LIMIT 1 ');
        $this->memberIsTemp = false;
        if (!$this->member) {
            $this->member = $this->createDependencyBean('member', $force, function() {
                $bean = R::dispense('member');
                $bean->email = 'migration@temp.local';
                $bean->username = 'migration_temp';
                $bean->password = 'x';
                $bean->display_name = 'Migration Temp';
                $bean->level = 100;
                $bean->status = 'active';
                $bean->created_at = date('Y-m-d H:i:s');
                R::store($bean);
                return $bean;
            });
            $this->memberIsTemp = true;
        }

        // Try to load existing board, or create temp one (prefer generic boards table)
        $this->board = R::findOne('boards', ' LIMIT 1 ');
        $this->boardIsTemp = false;
        if (!$this->board) {
            // Check jiraboards table as alternate source
            $this->board = R::findOne('jiraboards', ' LIMIT 1 ');
        }
        if (!$this->board) {
            $this->board = $this->createDependencyBean('boards', $force, function() {
                $bean = R::dispense('boards');
                $bean->member = $this->member;
                $bean->type = 'jira';
                $bean->name = 'Migration Temp Board';
                $bean->provider_config_json = '{}';
                $bean->created_at = date('Y-m-d H:i:s');
                R::store($bean);
                return $bean;
            });
            $this->boardIsTemp = true;
        }

        // Try to load existing repo, or create temp one
        $this->repo = R::findOne('repoconnections', ' LIMIT 1 ');
        $this->repoIsTemp = false;
        if (!$this->repo) {
            $this->repo = $this->createDependencyBean('repoconnections', $force, function() {
                $bean = R::dispense('repoconnections');
                $bean->member = $this->member;
                $bean->provider = 'github';
                $bean->repo_name = 'migration-temp';
                $bean->created_at = date('Y-m-d H:i:s');
                R::store($bean);
                return $bean;
            });
            $this->repoIsTemp = true;
        }

        // Try to load existing directive
        $this->directive = R::findOne('ceodirectives', ' LIMIT 1 ');
        $this->directiveIsTemp = false;  // Directives are never created as temp
    }

    /**
     * Drop all core tables to allow fresh rebuild (used in force mode)
     */
    private function dropAllCoreTables(): void {
        // All tables that might exist - drop everything except schemamigrations
        $coreTables = [
            // New schema tables
            'aidevjoblogs', 'aidevjobs', 'analysisresults', 'anthropickeys',
            'atlassiantoken', 'boardrepomapping', 'ceodirectives', 'ctoepics',
            'ctoprojects', 'ctostories', 'digesthistory', 'directivelogs',
            'directives', 'enterprisesettings', 'githubtokens', 'projects',
            'sshkeys', 'ticketanalysiscache', 'aiagents', 'mcpservers',
            'aiagents_mcpservers', 'installedplugins', 'pluginscans', 'discoveredplugins',
            // Additional tables
            'agenttools', 'claudeshards', 'digestjobs', 'knowledgebases',
            'ragdocuments', 'settings', 'shardjobs', 'shopifyconnections',
            'subscription', 'usersettings',
            // Parent tables last
            'repoconnections', 'boards', 'jiraboards', 'member', 'authcontrol'
        ];

        R::exec('SET FOREIGN_KEY_CHECKS = 0', []);
        foreach ($coreTables as $table) {
            // Safe: table names are hardcoded in this method
            R::exec(sprintf('DROP TABLE IF EXISTS `%s`', $table), []);
        }
        R::exec('SET FOREIGN_KEY_CHECKS = 1', []);
    }

    /**
     * Create a dependency bean, with force-drop-retry logic
     */
    private function createDependencyBean(string $tableName, bool $force, callable $creator) {
        try {
            return $creator();
        } catch (\Exception $e) {
            if ($force) {
                // Drop the table completely and retry (wipe only truncates)
                $this->dropTable($tableName);
                return $creator();
            }
            throw $e;
        }
    }

    /**
     * Drop a table by name (whitelisted tables only for safety)
     */
    private function dropTable(string $tableName): void {
        // Whitelist of allowed tables to drop
        $allowed = [
            'member', 'boards', 'jiraboards', 'repoconnections', 'aiagents', 'aidevjobs',
            'aidevjoblogs', 'analysisresults', 'anthropickeys', 'atlassiantoken',
            'authcontrol', 'boardrepomapping', 'ceodirectives', 'ctoepics',
            'ctoprojects', 'ctostories', 'digesthistory', 'directivelogs',
            'directives', 'enterprisesettings', 'githubtokens', 'projects',
            'sshkeys', 'ticketanalysiscache', 'discoveredplugins', 'pluginscans',
            'installedplugins', 'mcpservers', 'aiagents_mcpservers'
        ];

        if (!in_array($tableName, $allowed, true)) {
            throw new \Exception("Table '$tableName' not in whitelist for drop");
        }

        R::exec('SET FOREIGN_KEY_CHECKS = 0', []);
        // Safe: tableName is validated against whitelist above
        R::exec(sprintf('DROP TABLE IF EXISTS `%s`', $tableName), []);
        R::exec('SET FOREIGN_KEY_CHECKS = 1', []);
    }

    private function createMemberTable() {
        $bean = R::dispense('member');
        $bean->email = 'schema@example.com';
        $bean->username = 'schemauser';
        $bean->password = str_repeat('x', 255);
        $bean->display_name = 'Schema User';
        $bean->level = 100;
        $bean->status = 'active';
        $bean->first_name = 'Schema';
        $bean->last_name = 'User';
        $bean->bio = 'Schema bio placeholder';
        $bean->avatar_url = 'https://example.com/avatar.png';
        $bean->timezone = 'America/New_York';
        $bean->last_login = date('Y-m-d H:i:s');
        $bean->login_count = 0;
        $bean->failed_logins = 0;
        $bean->lockout_until = null;
        $bean->remember_token = 'schema_remember_token';
        $bean->google_uid = 'schema_google_uid'; // _uid suffix - string not FK
        $bean->email_verified = true;
        $bean->verification_token = 'schema_verification_token';
        $bean->verified_at = date('Y-m-d H:i:s');
        $bean->reset_token = 'schema_reset_token';
        $bean->reset_expires = date('Y-m-d H:i:s');
        $bean->invite_token = 'schema_invite_token';
        $bean->invite_sent_at = date('Y-m-d H:i:s');
        $bean->invite_expires_at = date('Y-m-d H:i:s');
        $bean->invited_by = 0;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        // Add unique constraint
        R::exec('ALTER TABLE `member` ADD UNIQUE INDEX IF NOT EXISTS `uk_email` (`email`)');

        return $bean;
    }

    private function createAuthControlTable(): void {
        $bean = R::dispense('authcontrol');
        $bean->control = 'schema';
        $bean->method = 'index';
        $bean->level = 101;
        $bean->description = 'Schema placeholder';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->validcount = 0;
        R::store($bean);
        R::trash($bean);
    }

    private function createAtlassianTokenTable(): void {
        $bean = R::dispense('atlassiantoken');
        $bean->member = $this->member; // Creates member_id FK automatically
        $bean->access_token = 'schema_access_token_placeholder';
        $bean->refresh_token = 'schema_refresh_token_placeholder';
        $bean->token_type = 'Bearer';
        $bean->expires_at = date('Y-m-d H:i:s');
        $bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; // _uid suffix - string not FK
        $bean->site_url = 'https://example.atlassian.net';
        $bean->site_name = 'Schema Example Site';
        $bean->scopes = 'read:jira-work read:jira-user offline_access';
        $bean->is_shared = false; // Workspace sharing flag
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Add unique constraint
        R::exec('ALTER TABLE `atlassiantoken` ADD UNIQUE INDEX IF NOT EXISTS `uk_member_cloud` (`member_id`, `cloud_uid`)');
    }

    private function createJiraBoardsTable() {
        $bean = R::dispense('jiraboards');
        $bean->member = $this->member; // Creates member_id FK
        $bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; // _uid suffix - string not FK
        $bean->board_uid = 1; // External Jira board ID
        $bean->board_name = 'Schema Template Board';
        $bean->board_type = 'scrum';
        $bean->project_key = 'SCHEMA';
        $bean->project_name = 'Schema Template Project';
        $bean->is_active = true;
        $bean->is_shared = false; // Workspace sharing flag
        $bean->digest_enabled = true;
        $bean->digest_frequency = 'daily';
        $bean->digest_time = '09:00';
        $bean->digest_day = 1;
        $bean->last_digest_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    private function createRepoConnectionsTable() {
        $bean = R::dispense('repoconnections');
        $bean->member = $this->member; // Creates member_id FK
        $bean->created_by_member_id = $this->member; // Cross-DB ref to default.member
        $bean->provider = 'github';
        $bean->repo_owner = 'schema-owner';
        $bean->repo_name = 'schema-repo';
        $bean->repo_full_name = 'schema-owner/schema-repo';
        $bean->default_branch = 'main';
        $bean->clone_url = 'https://github.com/schema-owner/schema-repo.git';
        $bean->access_token = 'schema_access_token';
        $bean->enabled = true;
        $bean->issues_enabled = true;
        $bean->webhook_uid = 'schema_webhook_uid'; // _uid suffix - string not FK
        $bean->webhook_secret = 'schema_webhook_secret';
        $bean->is_active = true;
        $bean->last_webhook_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    /**
     * Add aiagents_id FK column to repoconnections table
     * Called after aiagents table is created
     */
    private function addAiagentsToRepoConnections(): void {
        // Update the repo bean to add aiagents_id column
        if ($this->repo && $this->repo->id) {
            $this->repo->aiagents_id = null; // Creates aiagents_id column (nullable FK)
            R::store($this->repo);
        }
    }

    private function createAIDevJobsTable(): void {
        $bean = R::dispense('aidevjobs');
        $bean->job_uid = 'schema-job-uid-placeholder'; // _uid suffix - string not FK
        $bean->member = $this->member; // Creates member_id FK
        $bean->boards = $this->board; // Creates boards_id FK
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK
        $bean->issue_key = 'SCHEMA-1';
        $bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; // _uid suffix
        $bean->status = 'pending';
        $bean->progress = 0;
        $bean->current_step = 'Initializing';
        $bean->steps_completed = '[]';
        $bean->current_shard_job_uid = 'schema_shard_job_uid'; // _uid suffix
        $bean->branch_name = 'feature/schema-branch';
        $bean->pr_url = 'https://github.com/example/repo/pull/1';
        $bean->pr_number = 0;
        $bean->pr_created_at = date('Y-m-d H:i:s');
        $bean->clarification_comment_uid = 'schema_comment_uid'; // _uid suffix
        $bean->clarification_questions = '[]';
        $bean->error_message = 'Schema placeholder error message';
        $bean->run_count = 0;
        $bean->last_output = 'Schema placeholder output';
        $bean->last_result_json = '{}';
        $bean->files_changed = '[]';
        $bean->commit_sha = 'abc123def456';
        $bean->shopify_themeid = 0; // This IS an integer
        $bean->shopify_preview_url = 'https://example.myshopify.com';
        $bean->playwright_results = '[]';
        $bean->preserve_branch = true;
        $bean->started_at = date('Y-m-d H:i:s');
        $bean->completed_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        $bean->project_type = 'jira';
        $bean->queue_metadata = '{}';
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `steps_completed` JSON');
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `clarification_questions` JSON');
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `files_changed` JSON');
        R::exec('ALTER TABLE `aidevjobs` MODIFY COLUMN `playwright_results` JSON');

        // Add unique constraint
        R::exec('ALTER TABLE `aidevjobs` ADD UNIQUE INDEX IF NOT EXISTS `uk_job_uid` (`job_uid`)');
    }

    private function createAIDevJobLogsTable(): void {
        $bean = R::dispense('aidevjoblogs');
        $bean->issue_key = 'SCHEMA-1';
        $bean->log_level = 'info';
        $bean->message = 'Schema log message';
        $bean->context_json = '{}';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createAnalysisResultsTable(): void {
        $bean = R::dispense('analysisresults');
        $bean->boards = $this->board; // Creates boards_id FK
        $bean->analysis_type = 'sprint';
        $bean->content_json = '{}';
        $bean->content_markdown = 'Schema analysis content';
        $bean->issue_count = 0;
        $bean->status_filter = 'all';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createDigestHistoryTable(): void {
        $bean = R::dispense('digesthistory');
        $bean->boards = $this->board; // Creates boards_id FK
        $bean->member = $this->member; // Creates member_id FK
        $bean->digest_type = 'daily';
        $bean->content_markdown = 'Schema digest content';
        $bean->email_sent = false;
        $bean->email_to = 'schema@example.com';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createTicketAnalysisCacheTable(): void {
        $bean = R::dispense('ticketanalysiscache');
        $bean->boards = $this->board; // Creates boards_id FK
        $bean->issue_key = 'SCHEMA-1';
        $bean->content_hash = 'schema_content_hash';
        $bean->clarity_score = 0;
        $bean->clarity_analysis = '{}';
        $bean->reporter_name = 'Schema Reporter';
        $bean->reporter_email = 'reporter@example.com';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `ticketanalysiscache` MODIFY COLUMN `clarity_analysis` JSON');
    }

    private function createEnterpriseSettingsTable(): void {
        $bean = R::dispense('enterprisesettings');
        $bean->member = $this->member; // Creates member_id FK
        $bean->setting_key = 'schema_key';
        $bean->setting_value = 'schema_value';
        $bean->setting_type = 'string';
        $bean->is_encrypted = false;
        $bean->is_shared = false; // Workspace sharing flag
        $bean->description = 'Schema setting';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createGitHubTokensTable(): void {
        $bean = R::dispense('githubtokens');
        $bean->member = $this->member; // Creates member_id FK
        $bean->access_token = 'gho_schema_placeholder_token';
        $bean->token_type = 'bearer';
        $bean->scope = 'repo,user';
        $bean->github_user_uid = 'schema_github_user_uid'; // _uid suffix
        $bean->github_login = 'schema-user';
        $bean->github_name = 'Schema User';
        $bean->github_avatar = 'https://github.com/avatar.png';
        $bean->expires_at = date('Y-m-d H:i:s');
        $bean->refresh_token = 'ghr_schema_placeholder_refresh';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createBoardRepoMappingTable(): void {
        $bean = R::dispense('boardrepomapping');
        $bean->boards = $this->board; // Creates boards_id FK
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK
        $bean->is_primary = true;
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createAnthropicKeysTable(): void {
        $bean = R::dispense('anthropickeys');
        $bean->member = $this->member; // created_by_member association
        $bean->created_by_name = 'Schema User';
        $bean->name = 'Schema Key';
        $bean->api_key = 'sk-ant-schema-placeholder';
        $bean->model = 'claude-sonnet-4-20250514';
        $bean->shared = false;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createAIAgentsTable(): void {
        $bean = R::dispense('aiagents');
        $bean->member = $this->member; // Creates member_id FK
        $bean->created_by_member_id = $this->member;
        $bean->created_by_name = 'Schema User';
        $bean->name = 'Default Agent';
        $bean->description = 'Schema agent description';
        $bean->runner_type = 'claude_cli';
        $bean->runner_config = '{}';
        $bean->mcp_servers = '[]';
        $bean->hooks_config = '{}';
        $bean->anthropickeys_id = null; // FK to anthropickeys table (1:1 key assignment)
        $bean->is_active = true;
        $bean->is_default = false;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `runner_config` JSON');
        R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `mcp_servers` JSON');
        R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `hooks_config` JSON');
    }

    private function createMcpServersTable(): void {
        $bean = R::dispense('mcpservers');
        $bean->member = $this->member; // Creates member_id FK (who created it)
        $bean->name = 'Schema MCP Server';
        $bean->description = 'Schema server description';
        $bean->server_type = 'stdio'; // stdio or http
        $bean->command = 'npx'; // For stdio: command to run
        $bean->args_json = '[]'; // For stdio: command arguments (JSON array)
        $bean->url = 'https://example.com/mcp'; // For http: server URL
        $bean->headers_json = '{}'; // For http: request headers (JSON object)
        $bean->env_json = '{}'; // Environment variables (JSON object)
        $bean->is_shared = false; // Workspace sharing flag
        $bean->is_active = true;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `mcpservers` MODIFY COLUMN `args_json` JSON');
        R::exec('ALTER TABLE `mcpservers` MODIFY COLUMN `headers_json` JSON');
        R::exec('ALTER TABLE `mcpservers` MODIFY COLUMN `env_json` JSON');
    }

    private function createSSHKeysTable(): void {
        $bean = R::dispense('sshkeys');
        $bean->member = $this->member; // Creates member_id FK (who created it)
        $bean->name = 'Schema SSH Key';
        $bean->description = 'Schema key description';
        $bean->key_type = 'ecdsa'; // ecdsa, ed25519, rsa
        $bean->public_key = str_repeat('x', 500); // Public key content
        $bean->private_key_encrypted = str_repeat('x', 2000); // Encrypted private key
        $bean->fingerprint = 'SHA256:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $bean->is_shared = false; // Workspace sharing flag
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->last_used_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);
    }

    private function createDirectivesTable(): void {
        $bean = R::dispense('directives');
        $bean->directive_uid = 'schema-directive-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->directive_type = 'email';
        $bean->subject = 'Schema directive subject';
        $bean->content = 'Schema directive content';
        $bean->source_type = 'email';
        $bean->source_uid = 'schema_source_uid'; // _uid suffix
        $bean->source_email = 'schema@example.com';
        $bean->status = 'pending';
        $bean->processed_content = 'Schema processed content';
        $bean->context_json = '{}';
        $bean->actions_json = '[]';
        $bean->results_json = '{}';
        $bean->error_message = 'Schema error message';
        $bean->retry_count = 0;
        $bean->scheduled_at = date('Y-m-d H:i:s');
        $bean->started_at = date('Y-m-d H:i:s');
        $bean->completed_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `directives` MODIFY COLUMN `context_json` JSON');
        R::exec('ALTER TABLE `directives` MODIFY COLUMN `actions_json` JSON');
        R::exec('ALTER TABLE `directives` MODIFY COLUMN `results_json` JSON');
    }

    private function createCeoDirectivesTable() {
        $bean = R::dispense('ceodirectives');
        $bean->directive_uid = 'schema-ceodirective-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->email_from = 'ceo@example.com';
        $bean->email_subject = 'Schema directive subject';
        $bean->email_body = 'Schema directive body';
        $bean->email_message_uid = 'schema-message-id';
        $bean->parsed_intent = 'project';
        $bean->parsed_summary = 'Schema summary';
        $bean->parsed_requirements = '[]';
        $bean->project_uid = null; // Reference to ctoprojects.project_uid (string identifier)
        $bean->status = 'received';
        $bean->current_phase = 'initial';
        $bean->error_message = 'Schema error';
        $bean->approval_mode = 'auto';
        $bean->response_sent_at = date('Y-m-d H:i:s');
        $bean->response_content = 'Schema response';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `ceodirectives` MODIFY COLUMN `parsed_requirements` JSON');

        return $bean; // Keep for ctoprojects association
    }

    private function createDirectiveLogsTable(): void {
        $bean = R::dispense('directivelogs');
        $bean->ceodirectives = $this->directive; // Creates ceodirectives_id FK
        $bean->directive_uid = 'schema-directive-log-uid'; // _uid suffix for string identifier
        $bean->event_type = 'status_change';
        $bean->event_data = '{}';
        $bean->message = 'Schema log message';
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `directivelogs` MODIFY COLUMN `event_data` JSON');
    }

    private function createCtoProjectsTable(): void {
        $bean = R::dispense('ctoprojects');
        $bean->project_uid = 'schema-ctoproject-uid'; // _uid suffix
        $bean->ceodirectives = $this->directive; // Creates ceodirectives_id FK
        $bean->member = $this->member; // Creates member_id FK
        $bean->boards = $this->board; // Creates boards_id FK (board_id equivalent)
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK (github_repo_id equivalent)
        $bean->name = 'Schema CTO Project';
        $bean->project_type = 'jira';
        $bean->description = 'Schema project description';
        $bean->goals = '[]';
        $bean->estimated_effort = 'medium';
        $bean->risk_assessment = '{}';
        $bean->tech_stack = '[]';
        $bean->jira_project_key = 'SCHEMA';
        $bean->status = 'planning';
        $bean->completion_percentage = 0;
        $bean->milestones = '[]';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `goals` JSON');
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `risk_assessment` JSON');
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `tech_stack` JSON');
        R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `milestones` JSON');
    }

    private function createCtoEpicsTable(): void {
        $bean = R::dispense('ctoepics');
        $bean->epic_uid = 'schema-ctoepic-uid'; // _uid suffix
        $bean->ctoprojects = R::findOne('ctoprojects'); // FK placeholder - will be null
        $bean->title = 'Schema CTO Epic';
        $bean->description = 'Schema epic description';
        $bean->acceptance_criteria = '[]';
        $bean->jira_epic_key = 'SCHEMA-1';
        $bean->jira_epic_uid = 'schema-jira-epic-uid';
        $bean->status = 'backlog';
        $bean->story_count = 0;
        $bean->stories_completed = 0;
        $bean->priority = 0;
        $bean->sequence = 0;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `ctoepics` MODIFY COLUMN `acceptance_criteria` JSON');
    }

    private function createCtoStoriesTable(): void {
        $bean = R::dispense('ctostories');
        $bean->story_uid = 'schema-ctostory-uid'; // _uid suffix
        $bean->ctoepics = R::findOne('ctoepics'); // FK placeholder - will be null
        $bean->title = 'Schema CTO Story';
        $bean->description = 'Schema story description';
        $bean->acceptance_criteria = '[]';
        $bean->story_points = 0;
        $bean->sequence = 0; // Order within epic
        $bean->jira_issue_key = 'SCHEMA-2';
        $bean->jira_issue_uid = 'schema-jira-issue-uid';
        $bean->aidev_job_uid = 'schema-aidev-job-uid';
        $bean->status = 'pending_review';
        $bean->blocker_reason = 'Schema blocker';
        $bean->depends_on = '[]';
        $bean->verified_at = date('Y-m-d H:i:s');
        $bean->verification_result = '{}';
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `ctostories` MODIFY COLUMN `acceptance_criteria` JSON');
        R::exec('ALTER TABLE `ctostories` MODIFY COLUMN `depends_on` JSON');
        R::exec('ALTER TABLE `ctostories` MODIFY COLUMN `verification_result` JSON');
    }

    private function createProjectsTable(): void {
        $bean = R::dispense('projects');
        $bean->project_uid = 'schema-project-uid'; // _uid suffix
        $bean->member = $this->member; // Creates member_id FK
        $bean->boards = $this->board; // Creates boards_id FK
        $bean->repoconnections = $this->repo; // Creates repoconnections_id FK
        $bean->name = 'Schema Project';
        $bean->description = 'Schema project description';
        $bean->project_type = 'feature';
        $bean->status = 'pending';
        $bean->priority = 'medium';
        $bean->epic_key = 'SCHEMA-1';
        $bean->goals_json = '[]';
        $bean->constraints_json = '{}';
        $bean->context_json = '{}';
        $bean->plan_json = '[]';
        $bean->progress_json = '{}';
        $bean->current_phase = 'planning';
        $bean->current_step = 0;
        $bean->total_steps = 0;
        $bean->error_message = 'Schema error';
        $bean->started_at = date('Y-m-d H:i:s');
        $bean->completed_at = date('Y-m-d H:i:s');
        $bean->paused_at = date('Y-m-d H:i:s');
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        R::trash($bean);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `goals_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `constraints_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `context_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `plan_json` JSON');
        R::exec('ALTER TABLE `projects` MODIFY COLUMN `progress_json` JSON');
    }

    private function createPluginTables(): void {
        // Discovered plugins
        $discovered = R::dispense('discoveredplugins');
        $discovered->plugin_uid = 'schema-plugin-uid'; // _uid suffix
        $discovered->repoconnections = $this->repo; // Creates repoconnections_id FK
        $discovered->repo_owner = 'schema-owner';
        $discovered->repo_name = 'schema-plugin';
        $discovered->plugin_name = 'Schema Plugin';
        $discovered->plugin_description = 'Schema plugin description';
        $discovered->plugin_version = '1.0.0';
        $discovered->plugin_author = 'Schema Author';
        $discovered->plugin_main = 'src/Plugin.php';
        $discovered->plugin_requires = '{}';
        $discovered->plugin_json = '{}';
        $discovered->status = 'discovered';
        $discovered->validation_error = 'Schema validation error';
        $discovered->discovered_at = date('Y-m-d H:i:s');
        $discovered->last_scanned_at = date('Y-m-d H:i:s');
        $discovered->created_at = date('Y-m-d H:i:s');
        $discovered->updated_at = date('Y-m-d H:i:s');
        R::store($discovered);

        // Plugin scans
        $scan = R::dispense('pluginscans');
        $scan->scan_uid = 'schema-scan-uid'; // _uid suffix
        $scan->repoconnections = $this->repo; // Creates repoconnections_id FK
        $scan->status = 'running';
        $scan->repos_scanned = 0;
        $scan->plugins_found = 0;
        $scan->errors_encountered = 0;
        $scan->error_log = '[]';
        $scan->started_at = date('Y-m-d H:i:s');
        $scan->completed_at = date('Y-m-d H:i:s');
        $scan->created_at = date('Y-m-d H:i:s');
        R::store($scan);
        R::trash($scan);

        // Installed plugins
        $installed = R::dispense('installedplugins');
        $installed->plugin_uid = 'schema-installed-plugin-uid'; // _uid suffix
        $installed->discoveredplugins = $discovered; // Creates discoveredplugins_id FK
        $installed->member = $this->member; // Creates member_id FK
        $installed->installed_version = '1.0.0';
        $installed->is_active = true;
        $installed->config_json = '{}';
        $installed->installed_at = date('Y-m-d H:i:s');
        $installed->updated_at = date('Y-m-d H:i:s');
        R::store($installed);
        R::trash($installed);

        R::trash($discovered);

        // Apply JSON column types (MariaDB needs explicit JSON type)
        R::exec('ALTER TABLE `discoveredplugins` MODIFY COLUMN `plugin_requires` JSON');
        R::exec('ALTER TABLE `discoveredplugins` MODIFY COLUMN `plugin_json` JSON');
        R::exec('ALTER TABLE `pluginscans` MODIFY COLUMN `error_log` JSON');
        R::exec('ALTER TABLE `installedplugins` MODIFY COLUMN `config_json` JSON');
    }

    /**
     * Clean up temporary beans used for schema creation
     * IMPORTANT: Only delete beans we CREATED, not existing ones we found
     */
    private function cleanupTempBeans(): void {
        // Only delete beans that were created as temporary placeholders
        if ($this->directive && $this->directiveIsTemp) {
            R::trash($this->directive);
        }
        if ($this->repo && $this->repoIsTemp) {
            R::trash($this->repo);
        }
        if ($this->board && $this->boardIsTemp) {
            R::trash($this->board);
        }
        if ($this->member && $this->memberIsTemp) {
            R::trash($this->member);
        }
    }

    /**
     * Insert default permission entries for authcontrol
     */
    private function insertDefaultPermissions(): void {
        $permissions = [
            // Public pages (level 101)
            ['index', 'index', 101, 'Landing page'],
            ['auth', 'login', 101, 'Login page'],
            ['auth', 'dologin', 101, 'Process login'],
            ['auth', 'google', 101, 'Start Google OAuth'],
            ['auth', 'googlecallback', 101, 'Google OAuth callback'],
            ['auth', 'forgot', 101, 'Forgot password'],
            ['auth', 'resetpassword', 101, 'Reset password'],
            ['auth', 'register', 101, 'Registration page'],

            // Authenticated user pages (level 100)
            ['auth', 'logout', 100, 'Logout'],
            ['dashboard', 'index', 100, 'Main dashboard'],
            ['member', 'index', 100, 'Member index'],
            ['member', 'profile', 100, 'View profile'],
            ['member', 'edit', 100, 'Edit profile'],
            ['member', 'settings', 100, 'Member settings'],
            ['member', 'password', 100, 'Change password'],
            ['member', 'avatar', 100, 'Update avatar'],
            ['atlassian', 'index', 100, 'Atlassian connections'],
            ['atlassian', 'connect', 100, 'Start Atlassian OAuth'],
            ['atlassian', 'callback', 100, 'Atlassian OAuth callback'],
            ['atlassian', 'disconnect', 100, 'Disconnect Atlassian'],
            ['atlassian', 'refresh', 100, 'Refresh Atlassian tokens'],
            ['boards', 'index', 100, 'List boards'],
            ['boards', 'discover', 100, 'Discover Jira boards'],
            ['boards', 'add', 100, 'Add board'],
            ['boards', 'edit', 100, 'Edit board'],
            ['boards', 'remove', 100, 'Remove board'],
            ['boards', 'toggle', 100, 'Toggle board status'],
            ['analysis', 'index', 100, 'Analysis dashboard'],
            ['analysis', 'run', 100, 'Run analysis'],
            ['analysis', 'view', 100, 'View analysis'],
            ['analysis', 'email', 100, 'Email analysis'],
            ['settings', 'index', 100, 'Settings page'],
            ['settings', 'profile', 100, 'Profile settings'],
            ['settings', 'notifications', 100, 'Notification settings'],
            ['settings', 'subscription', 100, 'Subscription management'],
            ['enterprise', 'index', 100, 'Enterprise settings'],
            ['enterprise', 'github', 100, 'GitHub settings'],
            ['enterprise', 'githubcallback', 100, 'GitHub OAuth callback'],
            ['enterprise', 'repos', 100, 'Repository management'],
            ['enterprise', 'aidev', 100, 'AI Developer settings'],
            ['enterprise', 'api', 100, 'API key management'],
            ['jobs', 'index', 100, 'AI Dev jobs list'],
            ['jobs', 'view', 100, 'View job detail'],
            ['jobs', 'logs', 100, 'View job logs'],
            ['jobs', 'cancel', 100, 'Cancel job'],
            ['jobs', 'retry', 100, 'Retry job'],
            ['directives', 'index', 100, 'CEO directives list'],
            ['directives', 'view', 100, 'View directive detail'],
            ['directives', 'retry', 100, 'Retry failed directive'],
            ['directives', 'cancel', 100, 'Cancel directive'],
            ['projects', 'index', 100, 'CTO Projects dashboard'],
            ['projects', 'view', 100, 'View project detail'],
            ['projects', 'report', 100, 'Generate project report'],
            ['projects', 'pause', 100, 'Pause project execution'],
            ['projects', 'resume', 100, 'Resume project execution'],
            ['plugins', 'index', 100, 'Plugin marketplace listing'],
            ['plugins', 'search', 100, 'Search plugins'],
            ['plugins', 'autocomplete', 100, 'Plugin name autocomplete'],
            ['plugins', 'view', 100, 'View plugin details'],
            ['pluginregistry', 'index', 100, 'List discovered plugins'],

            // Admin pages (level 50)
            ['admin', 'index', 50, 'Admin dashboard'],
            ['admin', 'members', 50, 'Manage members'],

            // System endpoints (level 1 - ROOT only)
            ['api', 'crondigest', 1, 'Cron digest endpoint'],
            ['webhook', 'jira', 1, 'Jira webhook'],
            ['webhook', 'github', 1, 'GitHub webhook'],
            ['webhook', 'mailgun', 101, 'Mailgun incoming email webhook'],
        ];

        foreach ($permissions as $perm) {
            $bean = R::dispense('authcontrol');
            $bean->control = $perm[0];
            $bean->method = $perm[1];
            $bean->level = $perm[2];
            $bean->description = $perm[3];
            $bean->created_at = date('Y-m-d H:i:s');
            R::store($bean);
        }
    }

    // ========================================
    // Column Rename Migrations
    // ========================================

    /**
     * Rename board_id to jiraboards_id across all tables
     * This aligns with RedBeanPHP's FK naming convention: {beantype}_id
     *
     * Note: Uses R::exec for DDL statements - table names are from hardcoded whitelist
     */
    private function renameBoardIdToJiraboardsId(): void {
        // Whitelisted tables that have board_id column to rename
        $tables = [
            'aidevjobs',
            'analysisresults',
            'boardrepomapping',
            'digesthistory',
            'digestjobs',
            'ticketanalysiscache',
        ];

        foreach ($tables as $table) {
            $this->doColumnRename($table, 'board_id', 'jiraboards_id', 'INT(11) UNSIGNED DEFAULT NULL');
        }
    }

    /**
     * Rename repo_connection_id and repo_id to repoconnections_id
     * This aligns with RedBeanPHP's FK naming convention: {beantype}_id
     */
    private function renameRepoConnectionIdToRepoconnectionsId(): void {
        // Whitelisted tables with columns to rename
        $renames = [
            ['aidevjobs', 'repo_connection_id'],
            ['aidevjobs', 'repo_id'],
            ['boardrepomapping', 'repo_connection_id'],
            ['shopifyconnections', 'repo_connection_id'],
            ['discoveredplugins', 'repo_connection_id'],
            ['pluginscans', 'repo_connection_id'],
        ];

        foreach ($renames as $rename) {
            $this->doColumnRename($rename[0], $rename[1], 'repoconnections_id', 'INT(11) UNSIGNED DEFAULT NULL');
        }
    }

    /**
     * Helper: Rename a column if it exists and target doesn't exist
     * Uses whitelisted table/column names only (no user input)
     *
     * @param string $table Table name (from whitelist)
     * @param string $oldColumn Old column name
     * @param string $newColumn New column name
     * @param string $columnDef Column definition for ALTER
     */
    private function doColumnRename(string $table, string $oldColumn, string $newColumn, string $columnDef): void {
        // Whitelist validation for table names
        $allowedTables = [
            'aidevjobs', 'analysisresults', 'boardrepomapping', 'digesthistory',
            'digestjobs', 'ticketanalysiscache', 'shopifyconnections',
            'discoveredplugins', 'pluginscans'
        ];
        if (!in_array($table, $allowedTables, true)) {
            return;
        }

        // Check if table exists and has the old column
        try {
            $columns = R::inspect($table);
        } catch (\Exception $e) {
            return; // Table doesn't exist, skip
        }
        if (!$columns || !isset($columns[$oldColumn])) {
            return;
        }

        // Skip if target column already exists
        if (isset($columns[$newColumn])) {
            return;
        }

        // Build and execute DDL (table/column names are whitelisted, not user input)
        $sql = sprintf(
            'ALTER TABLE `%s` CHANGE COLUMN `%s` `%s` %s',
            $table, $oldColumn, $newColumn, $columnDef
        );
        R::exec($sql);

        // Update indexes - drop old, create new
        $this->safeDropIndex($table, 'idx_board');
        $this->safeDropIndex($table, 'idx_repo');
        $this->safeDropIndex($table, sprintf('index_foreignkey_%s_board', $table));
        $this->safeDropIndex($table, sprintf('index_foreignkey_%s_repo', $table));
        $this->safeDropIndex($table, sprintf('index_foreignkey_%s_repo_connection', $table));

        // Create new index
        $indexName = sprintf('index_foreignkey_%s_%s', $table, str_replace('_id', '', $newColumn));
        $this->safeCreateIndex($table, $indexName, $newColumn);
    }

    /**
     * Helper: Safely drop an index (ignore if doesn't exist)
     */
    private function safeDropIndex(string $table, string $indexName): void {
        $sql = sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $indexName);
        try {
            R::exec($sql);
        } catch (\Exception $e) {
            // Index doesn't exist, ignore
        }
    }

    /**
     * Helper: Safely create an index (ignore if already exists)
     */
    private function safeCreateIndex(string $table, string $indexName, string $column): void {
        $sql = sprintf('CREATE INDEX `%s` ON `%s` (`%s`)', $indexName, $table, $column);
        try {
            R::exec($sql);
        } catch (\Exception $e) {
            // Index already exists, ignore
        }
    }

    /**
     * Create generic boards table
     * Supports multiple board types: jira, github, monday, zoho, etc.
     * This is the provider-agnostic abstraction layer.
     */
    private function createBoardsTable() {
        $bean = R::dispense('boards');
        $bean->member = $this->member; // Creates member_id FK
        $bean->type = 'jira'; // jira, github, monday, zoho, etc.
        $bean->external_uid = 'cloud_id:board_id'; // Provider-specific unique identifier
        $bean->name = 'Schema Template Board';
        $bean->project_key = 'SCHEMA';
        $bean->project_name = 'Schema Template Project';
        $bean->is_active = true;
        $bean->digest_enabled = true;
        $bean->digest_frequency = 'daily';
        $bean->digest_time = '09:00';
        $bean->digest_day = 1;
        $bean->last_digest_at = date('Y-m-d H:i:s');
        $bean->provider_config_json = '{}'; // Provider-specific settings (JSON)
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
        return $bean;
    }

    /**
     * Migration: Copy jiraboards data to generic boards table
     * Sets type='jira' and builds external_uid from cloud_uid:board_uid
     */
    private function migrateJiraboardsToBoards(): void {
        // First ensure boards table exists
        try {
            R::inspect('boards');
        } catch (\Exception $e) {
            // Create boards table if it doesn't exist
            $tempMember = R::dispense('member');
            $tempMember->email = 'migration@temp.local';
            R::store($tempMember);

            $bean = R::dispense('boards');
            $bean->member = $tempMember;
            $bean->type = 'jira';
            $bean->external_uid = 'temp';
            $bean->name = 'temp';
            $bean->project_key = 'TEMP';
            $bean->is_active = false;
            $bean->provider_config_json = '{}';
            $bean->created_at = date('Y-m-d H:i:s');
            R::store($bean);
            R::trash($bean);
            R::trash($tempMember);
        }

        // Check if jiraboards table exists and has data
        try {
            $jiraboards = R::findAll('jiraboards');
        } catch (\Exception $e) {
            return; // No jiraboards table
        }

        if (empty($jiraboards)) {
            return; // No data to migrate
        }

        foreach ($jiraboards as $jb) {
            // Check if already migrated (by external_uid)
            $externalUid = ($jb->cloud_uid ?? '') . ':' . ($jb->board_uid ?? $jb->id);
            $existing = R::findOne('boards', 'external_uid = ? AND type = ?', [$externalUid, 'jira']);
            if ($existing) {
                continue; // Already migrated
            }

            // Create board record
            $board = R::dispense('boards');
            $board->member_id = $jb->member_id;
            $board->type = 'jira';
            $board->external_uid = $externalUid;
            $board->name = $jb->board_name;
            $board->project_key = $jb->project_key;
            $board->project_name = $jb->project_name;
            $board->is_active = $jb->is_active;
            $board->digest_enabled = $jb->digest_enabled;
            $board->digest_frequency = $jb->digest_frequency;
            $board->digest_time = $jb->digest_time;
            $board->digest_day = $jb->digest_day;
            $board->last_digest_at = $jb->last_digest_at;
            $board->created_at = $jb->created_at;
            $board->updated_at = $jb->updated_at;

            // Store Jira-specific fields in provider_config_json
            $board->provider_config_json = json_encode([
                'cloud_uid' => $jb->cloud_uid,
                'board_uid' => $jb->board_uid,
                'board_type' => $jb->board_type, // scrum, kanban
                'jiraboards_id' => $jb->id, // Reference back to original
            ]);

            R::store($board);
        }
    }

    /**
     * Migration: Rename board_id and jiraboards_id columns to boards_id
     * This unifies FK references to the generic boards table
     *
     * Note: Uses R::exec for DDL statements - table names are from hardcoded whitelist
     */
    private function renameToBoardsId(): void {
        // Tables that may have board_id or jiraboards_id columns
        $tables = [
            'aidevjobs',
            'analysisresults',
            'boardrepomapping',
            'digesthistory',
            'digestjobs',
            'ticketanalysiscache',
        ];

        foreach ($tables as $table) {
            // Try to rename jiraboards_id first (if previous migration was run)
            $this->doColumnRenameToBoards($table, 'jiraboards_id', 'boards_id', 'INT(11) UNSIGNED DEFAULT NULL');
            // Then try board_id (if previous migration was not run)
            $this->doColumnRenameToBoards($table, 'board_id', 'boards_id', 'INT(11) UNSIGNED DEFAULT NULL');
        }
    }

    /**
     * Helper: Rename column to boards_id with proper index handling
     */
    private function doColumnRenameToBoards(string $table, string $oldColumn, string $newColumn, string $columnDef): void {
        // Whitelist validation for table names
        $allowedTables = [
            'aidevjobs', 'analysisresults', 'boardrepomapping', 'digesthistory',
            'digestjobs', 'ticketanalysiscache', 'shopifyconnections',
            'discoveredplugins', 'pluginscans'
        ];
        if (!in_array($table, $allowedTables, true)) {
            return;
        }

        // Check if table exists and has the old column
        try {
            $columns = R::inspect($table);
        } catch (\Exception $e) {
            return; // Table doesn't exist, skip
        }
        if (!$columns || !isset($columns[$oldColumn])) {
            return;
        }

        // Skip if target column already exists
        if (isset($columns[$newColumn])) {
            return;
        }

        // Build and execute DDL (table/column names are whitelisted, not user input)
        $sql = sprintf(
            'ALTER TABLE `%s` CHANGE COLUMN `%s` `%s` %s',
            $table, $oldColumn, $newColumn, $columnDef
        );
        R::exec($sql);

        // Clean up old indexes
        $this->safeDropIndex($table, 'idx_board');
        $this->safeDropIndex($table, sprintf('index_foreignkey_%s_board', $table));
        $this->safeDropIndex($table, sprintf('index_foreignkey_%s_jiraboards', $table));

        // Create new index for boards_id
        $indexName = sprintf('index_foreignkey_%s_boards', $table);
        $this->safeCreateIndex($table, $indexName, $newColumn);
    }

    /**
     * Migration: Convert agent mcp_servers JSON to linked mcpservers records
     * Creates mcpservers records and links them via many-to-many
     */
    private function migrateAgentMcpServersToLibrary(): void {
        // First ensure mcpservers table exists
        try {
            R::inspect('mcpservers');
        } catch (\Exception $e) {
            return; // mcpservers table doesn't exist, skip
        }

        // Get all agents with mcp_servers JSON
        $agents = R::findAll('aiagents');
        if (empty($agents)) {
            return;
        }

        foreach ($agents as $agent) {
            $mcpJson = $agent->mcp_servers;
            if (empty($mcpJson) || $mcpJson === '[]') {
                continue;
            }

            $servers = json_decode($mcpJson, true);
            if (empty($servers) || !is_array($servers)) {
                continue;
            }

            foreach ($servers as $serverConfig) {
                $name = $serverConfig['name'] ?? null;
                if (!$name) {
                    continue;
                }

                // Check if server already exists in library (by name and member)
                $existing = R::findOne('mcpservers',
                    ' name = ? AND member_id = ?',
                    [$name, $agent->member_id]
                );

                if ($existing) {
                    // Link existing server to agent
                    $agent->sharedMcpserversList[] = $existing;
                    continue;
                }

                // Create new server record
                $server = R::dispense('mcpservers');
                $server->member_id = $agent->member_id;
                $server->name = $name;
                $server->description = 'Migrated from agent: ' . $agent->name;
                $server->server_type = $serverConfig['type'] ?? 'stdio';
                $server->command = $serverConfig['command'] ?? null;
                $server->args_json = json_encode($serverConfig['args'] ?? []);
                $server->url = $serverConfig['url'] ?? null;
                $server->headers_json = json_encode($serverConfig['headers'] ?? []);
                $server->env_json = json_encode($serverConfig['env'] ?? []);
                $server->is_shared = 0;
                $server->is_active = 1;
                $server->created_at = date('Y-m-d H:i:s');
                $server->updated_at = date('Y-m-d H:i:s');
                R::store($server);

                // Link to agent
                $agent->sharedMcpserversList[] = $server;
            }

            // Clear the legacy JSON and save
            $agent->mcp_servers = '[]';
            R::store($agent);
        }
    }
}
