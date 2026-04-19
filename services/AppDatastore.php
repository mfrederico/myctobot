<?php
/**
 * AppDatastore - Per-app SQLite database management
 *
 * Each tenant app has its own isolated SQLite database in storage/apps/{workspace}/{slug}/data.sqlite.
 * This service provides CRUD operations and schema management for app-specific data.
 *
 * Benefits:
 * - Portability: Move entire app directory (code + data) to container
 * - Isolation: No risk of affecting main MyCTOBot database
 * - Simplicity: SQLite = zero configuration
 * - Backup: Simple file copy
 */

namespace app\services;

class AppDatastore
{
    private \PDO $db;
    private string $dbPath;
    private string $workspace;
    private string $appSlug;

    /**
     * Base paths - dynamically detected
     */
    private static ?string $rootPath = null;

    private static function getRootPath(): string
    {
        if (self::$rootPath === null) {
            self::$rootPath = dirname(__DIR__);
        }
        return self::$rootPath;
    }

    public static function getStorageDir(): string
    {
        return self::getRootPath() . '/storage/apps';
    }

    /**
     * Create a new AppDatastore instance
     *
     * @param string $workspace The workspace slug
     * @param string $appSlug The app slug
     */
    public function __construct(string $workspace, string $appSlug)
    {
        $this->workspace = $workspace;
        $this->appSlug = $appSlug;

        $storageDir = self::getStorageDir() . "/{$workspace}/{$appSlug}";
        $this->dbPath = $storageDir . '/data.sqlite';

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $this->db = new \PDO("sqlite:{$this->dbPath}");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Enable foreign keys
        $this->db->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Get the database path
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Get the PDO instance for advanced operations
     */
    public function getPdo(): \PDO
    {
        return $this->db;
    }

    /**
     * Insert a row into a table
     *
     * @param string $table Table name
     * @param array $data Column => value pairs
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot insert empty data');
        }

        // Sanitize table name
        $table = $this->sanitizeIdentifier($table);

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        // Handle special values like NOW()
        $values = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && strtoupper($value) === 'NOW()') {
                $values[] = date('Y-m-d H:i:s');
            } else {
                $values[] = $value;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map([$this, 'sanitizeIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update rows in a table
     *
     * @param string $table Table name
     * @param array $data Column => value pairs to update
     * @param array $where Column => value pairs for WHERE clause
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot update with empty data');
        }

        if (empty($where)) {
            throw new \InvalidArgumentException('WHERE clause required for update');
        }

        // Sanitize table name
        $table = $this->sanitizeIdentifier($table);

        // Build SET clause
        $setClauses = [];
        $values = [];
        foreach ($data as $column => $value) {
            $setClauses[] = $this->sanitizeIdentifier($column) . ' = ?';
            if (is_string($value) && strtoupper($value) === 'NOW()') {
                $values[] = date('Y-m-d H:i:s');
            } else {
                $values[] = $value;
            }
        }

        // Build WHERE clause
        $whereClauses = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = $this->sanitizeIdentifier($column) . ' = ?';
            $values[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $stmt->rowCount();
    }

    /**
     * Select rows from a table
     *
     * @param string $table Table name
     * @param array $where Column => value pairs for WHERE clause (optional)
     * @param array $options Query options: order_by, limit, offset, columns
     * @return array Array of rows
     */
    public function select(string $table, array $where = [], array $options = []): array
    {
        // Sanitize table name
        $table = $this->sanitizeIdentifier($table);

        // Build SELECT clause
        $columns = $options['columns'] ?? ['*'];
        if (is_array($columns)) {
            $columns = array_map(function ($col) {
                return $col === '*' ? '*' : $this->sanitizeIdentifier($col);
            }, $columns);
            $columnsSql = implode(', ', $columns);
        } else {
            $columnsSql = '*';
        }

        $sql = "SELECT {$columnsSql} FROM {$table}";
        $values = [];

        // Build WHERE clause
        if (!empty($where)) {
            $whereClauses = [];
            foreach ($where as $column => $value) {
                if ($value === null) {
                    $whereClauses[] = $this->sanitizeIdentifier($column) . ' IS NULL';
                } else {
                    $whereClauses[] = $this->sanitizeIdentifier($column) . ' = ?';
                    $values[] = $value;
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        // Add ORDER BY
        if (!empty($options['order_by'])) {
            $orderBy = $options['order_by'];
            if (is_string($orderBy)) {
                // Simple string like "created_at DESC"
                $sql .= ' ORDER BY ' . $orderBy;
            } elseif (is_array($orderBy)) {
                $orderClauses = [];
                foreach ($orderBy as $column => $direction) {
                    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                    $orderClauses[] = $this->sanitizeIdentifier($column) . ' ' . $direction;
                }
                $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
            }
        }

        // Add LIMIT
        if (!empty($options['limit'])) {
            $sql .= ' LIMIT ' . (int)$options['limit'];
        }

        // Add OFFSET
        if (!empty($options['offset'])) {
            $sql .= ' OFFSET ' . (int)$options['offset'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $stmt->fetchAll();
    }

    /**
     * Select a single row from a table
     *
     * @param string $table Table name
     * @param array $where Column => value pairs for WHERE clause
     * @return array|null Single row or null
     */
    public function selectOne(string $table, array $where): ?array
    {
        $results = $this->select($table, $where, ['limit' => 1]);
        return $results[0] ?? null;
    }

    /**
     * Delete rows from a table
     *
     * @param string $table Table name
     * @param array $where Column => value pairs for WHERE clause
     * @return int Number of deleted rows
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new \InvalidArgumentException('WHERE clause required for delete');
        }

        // Sanitize table name
        $table = $this->sanitizeIdentifier($table);

        // Build WHERE clause
        $whereClauses = [];
        $values = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = $this->sanitizeIdentifier($column) . ' = ?';
            $values[] = $value;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode(' AND ', $whereClauses)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $stmt->rowCount();
    }

    /**
     * Execute raw SQL
     *
     * @param string $sql SQL statement
     * @param array $params Parameters for prepared statement
     * @return mixed PDOStatement for SELECT, rowCount for INSERT/UPDATE/DELETE
     */
    public function raw(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Determine if this is a SELECT query
        $trimmedSql = ltrim(strtoupper($sql));
        if (str_starts_with($trimmedSql, 'SELECT')) {
            return $stmt->fetchAll();
        }

        return $stmt->rowCount();
    }

    /**
     * Count rows in a table
     *
     * @param string $table Table name
     * @param array $where Column => value pairs for WHERE clause (optional)
     * @return int Row count
     */
    public function count(string $table, array $where = []): int
    {
        $table = $this->sanitizeIdentifier($table);
        $sql = "SELECT COUNT(*) FROM {$table}";
        $values = [];

        if (!empty($where)) {
            $whereClauses = [];
            foreach ($where as $column => $value) {
                $whereClauses[] = $this->sanitizeIdentifier($column) . ' = ?';
                $values[] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Ensure schema tables exist
     *
     * @param array $tables Table definitions: ['table_name' => ['column' => 'definition', ...]]
     */
    public function ensureSchema(array $tables): void
    {
        foreach ($tables as $tableName => $columns) {
            $this->createTableIfNotExists($tableName, $columns);
        }
    }

    /**
     * Create a table if it doesn't exist
     *
     * @param string $tableName Table name
     * @param array $columns Column definitions: ['column' => 'definition']
     */
    public function createTableIfNotExists(string $tableName, array $columns): void
    {
        $tableName = $this->sanitizeIdentifier($tableName);

        $columnDefs = [];
        foreach ($columns as $columnName => $definition) {
            $columnDefs[] = $this->sanitizeIdentifier($columnName) . ' ' . $definition;
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $tableName,
            implode(', ', $columnDefs)
        );

        $this->db->exec($sql);
    }

    /**
     * Check if a table exists
     *
     * @param string $table Table name
     * @return bool
     */
    public function tableExists(string $table): bool
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$table]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get list of tables
     *
     * @return array Table names
     */
    public function listTables(): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        $stmt = $this->db->query($sql);
        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): void
    {
        $this->db->rollBack();
    }

    /**
     * Sanitize an identifier (table/column name)
     * SQLite uses double quotes for identifiers
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        // Remove any existing quotes and dangerous characters
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
        return '"' . $identifier . '"';
    }

    /**
     * Static factory method to get datastore for an app
     *
     * @param string $workspace Workspace slug
     * @param string $appSlug App slug
     * @return self
     */
    public static function forApp(string $workspace, string $appSlug): self
    {
        return new self($workspace, $appSlug);
    }

    /**
     * Initialize datastore from app config
     *
     * @param array $config App config with datastore schema definition
     * @return void
     */
    public function initFromConfig(array $config): void
    {
        $datastoreConfig = $config['datastore'] ?? null;
        if (!$datastoreConfig || !isset($datastoreConfig['schema'])) {
            return;
        }

        $this->ensureSchema($datastoreConfig['schema']);
    }
}
