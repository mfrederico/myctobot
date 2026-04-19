<?php
/**
 * WorkspaceSchemaBuilder - Creates workspace database schema using RedBeanPHP
 *
 * Uses RedBeanPHP associations to create tables with proper foreign keys.
 * Schema is defined in modular seed files in Schema/Seeds/*.php
 *
 * @see https://redbeanphp.com/index.php?p=/association
 */

namespace app\services;

use RedBeanPHP\R as R;

class WorkspaceSchemaBuilder {

    private string $dbName;
    private string $dbUser;
    private string $dbPass;
    private string $dbHost;

    private static string $seedsDir = __DIR__ . '/Schema/Seeds';

    public function __construct(string $dbName, string $dbUser, string $dbPass, string $dbHost = 'localhost') {
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->dbHost = $dbHost;
    }

    /**
     * Build the complete workspace schema using RedBeanPHP.
     * Runs ALL seed files — use for fresh provisioning only.
     */
    public function build(): void {
        $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
        R::addDatabase('workspace_provision', $dsn, $this->dbUser, $this->dbPass);
        R::selectDatabase('workspace_provision');
        R::freeze(false);

        $seeders = glob(self::$seedsDir . '/*.php');
        sort($seeders);

        foreach ($seeders as $seederFile) {
            include $seederFile;
        }

        self::restoreDatabase();
    }

    /**
     * Safely restore the previous database selection, if one exists.
     */
    private static function restoreDatabase(): void {
        try {
            if (R::hasDatabase('default')) {
                self::restoreDatabase();
            }
        } catch (\Exception $e) {
            // CLI context — no default DB, that's fine
        }
    }

    // ── Migration framework ──────────────────────────────────────────

    /**
     * Get migration names from seed filenames (e.g. "01_Member", "02_AuthControl").
     */
    public static function getAvailableMigrations(): array {
        $files = glob(self::$seedsDir . '/*.php');
        sort($files);
        return array_map(fn($f) => basename($f, '.php'), $files);
    }

    /**
     * Discover workspaces by scanning conf/config.*.ini files.
     * Returns ['slug' => ['db_name' => …, 'config' => …], …]
     */
    public static function getAvailableworkspaces(): array {
        $confDir = dirname(__DIR__) . '/conf';
        $skip = ['example', 'sqlite.example'];
        $workspaces = [];

        foreach (glob($confDir . '/config.*.ini') as $file) {
            $slug = preg_replace('/^config\.(.+)\.ini$/', '$1', basename($file));
            if (in_array($slug, $skip, true)) {
                continue;
            }
            $config = parse_ini_file($file, true);
            $workspaces[$slug] = [
                'db_name' => $config['database']['name'] ?? null,
                'config'  => $file,
            ];
        }

        ksort($workspaces);
        return $workspaces;
    }

    /**
     * Get migration status (applied vs pending) for a workspace.
     */
    public static function getWorkspaceStatus(string $slug): array {
        $workspaces = self::getAvailableworkspaces();
        if (!isset($workspaces[$slug])) {
            return ['success' => false, 'error' => "Workspace '$slug' not found"];
        }

        $config = parse_ini_file($workspaces[$slug]['config'], true);
        $db = $config['database'] ?? null;
        if (!$db) {
            return ['success' => false, 'error' => 'No database config'];
        }

        $connKey = 'status_' . $slug;
        try {
            $dsn = "mysql:host=" . ($db['host'] ?? 'localhost') . ";dbname={$db['name']};charset=utf8mb4";
            if (!R::hasDatabase($connKey)) {
                R::addDatabase($connKey, $dsn, $db['user'], $db['pass'] ?? '');
            }
            R::selectDatabase($connKey);
            self::ensureMigrationsTable();

            $applied = R::getCol('SELECT migration_name FROM migrations ORDER BY migration_name');
            $all     = self::getAvailableMigrations();
            $pending = array_values(array_diff($all, $applied));

            self::restoreDatabase();
            return ['success' => true, 'applied' => $applied, 'pending' => $pending];
        } catch (\Exception $e) {
            self::restoreDatabase();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run a single named migration (seed file) on this builder's database.
     * In fluid mode so RedBeanPHP auto-creates tables/columns.
     */
    public function runMigration(string $migration, bool $force = false): array {
        $file = self::$seedsDir . '/' . $migration . '.php';
        if (!file_exists($file)) {
            return ['success' => false, 'error' => "Migration file not found: $migration"];
        }

        $connKey = 'migrate_' . md5($this->dbName . $migration);
        try {
            $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
            if (!R::hasDatabase($connKey)) {
                R::addDatabase($connKey, $dsn, $this->dbUser, $this->dbPass);
            }
            R::selectDatabase($connKey);
            R::freeze(false); // Fluid mode — auto-creates tables/columns

            self::ensureMigrationsTable();

            if (!$force) {
                $exists = R::getCell('SELECT COUNT(*) FROM migrations WHERE migration_name = ?', [$migration]);
                if ($exists) {
                    self::restoreDatabase();
                    return ['success' => true, 'skipped' => true];
                }
            }

            include $file;

            R::exec(
                'INSERT INTO migrations (migration_name, applied_at) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE applied_at = NOW()',
                [$migration]
            );

            self::restoreDatabase();
            return ['success' => true];
        } catch (\Exception $e) {
            self::restoreDatabase();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run all pending migrations for a workspace.
     */
    public static function syncworkspace(string $slug): array {
        $status = self::getWorkspaceStatus($slug);
        if (!$status['success']) {
            return $status;
        }

        if (empty($status['pending'])) {
            return ['success' => true, 'ran' => []];
        }

        $workspaces = self::getAvailableworkspaces();
        $config = parse_ini_file($workspaces[$slug]['config'], true);
        $db = $config['database'];
        $builder = new self($db['name'], $db['user'], $db['pass'] ?? '', $db['host'] ?? 'localhost');

        $ran    = [];
        $failed = [];

        foreach ($status['pending'] as $migration) {
            $result = $builder->runMigration($migration, false);
            if ($result['success'] && empty($result['skipped'])) {
                $ran[] = $migration;
            } elseif (!$result['success']) {
                $failed[] = ['migration' => $migration, 'error' => $result['error'] ?? 'Unknown'];
            }
        }

        return empty($failed)
            ? ['success' => true, 'ran' => $ran]
            : ['success' => false, 'ran' => $ran, 'failed' => $failed];
    }

    /**
     * Mark migrations as applied without running them (for existing databases).
     */
    public static function markForworkspace(array $migrations, string $slug): array {
        $workspaces = self::getAvailableworkspaces();
        if (!isset($workspaces[$slug])) {
            return ['success' => false, 'error' => "Workspace '$slug' not found"];
        }

        $config = parse_ini_file($workspaces[$slug]['config'], true);
        $db = $config['database'] ?? null;
        if (!$db) {
            return ['success' => false, 'error' => 'No database config'];
        }

        $connKey = 'mark_' . $slug;
        try {
            $dsn = "mysql:host=" . ($db['host'] ?? 'localhost') . ";dbname={$db['name']};charset=utf8mb4";
            if (!R::hasDatabase($connKey)) {
                R::addDatabase($connKey, $dsn, $db['user'], $db['pass'] ?? '');
            }
            R::selectDatabase($connKey);
            self::ensureMigrationsTable();

            $marked  = [];
            $skipped = [];

            foreach ($migrations as $migration) {
                $exists = R::getCell('SELECT COUNT(*) FROM migrations WHERE migration_name = ?', [$migration]);
                if ($exists) {
                    $skipped[] = $migration;
                } else {
                    R::exec('INSERT INTO migrations (migration_name, applied_at) VALUES (?, NOW())', [$migration]);
                    $marked[] = $migration;
                }
            }

            self::restoreDatabase();
            return ['success' => true, 'marked' => $marked, 'skipped' => $skipped];
        } catch (\Exception $e) {
            self::restoreDatabase();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create the migrations tracking table if it doesn't exist.
     */
    private static function ensureMigrationsTable(): void {
        R::exec('CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
