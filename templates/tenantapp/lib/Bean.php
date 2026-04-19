<?php
/**
 * Bean - Lightweight SQLite ORM
 *
 * RedBeanPHP-inspired API for tenant app datastores.
 * Simple, single-file ORM for SQLite databases.
 */

namespace TenantApp;

class Bean implements \ArrayAccess, \JsonSerializable {

    private static ?\PDO $pdo = null;
    private static string $dbPath = '';

    private string $type;
    private array $data = [];
    private bool $isNew = true;

    /**
     * Setup database connection
     */
    public static function setup(string $dbPath): void {
        self::$dbPath = $dbPath;
        self::$pdo = new \PDO("sqlite:{$dbPath}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Get the PDO instance
     */
    public static function getPdo(): \PDO {
        if (!self::$pdo) {
            throw new \RuntimeException('Database not configured. Call Bean::setup() first.');
        }
        return self::$pdo;
    }

    /**
     * Create a new bean
     */
    public static function dispense(string $type): self {
        $bean = new self();
        $bean->type = strtolower($type);
        $bean->isNew = true;
        return $bean;
    }

    /**
     * Load a bean by ID
     */
    public static function load(string $type, int $id): self {
        $type = strtolower($type);
        $pdo = self::getPdo();

        $stmt = $pdo->prepare("SELECT * FROM {$type} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $bean = new self();
        $bean->type = $type;

        if ($row) {
            $bean->data = $row;
            $bean->isNew = false;
        }

        return $bean;
    }

    /**
     * Find beans by SQL condition
     */
    public static function find(string $type, string $sql = '', array $params = []): array {
        $type = strtolower($type);
        $pdo = self::getPdo();

        $query = "SELECT * FROM {$type}";
        if ($sql) {
            $query .= " WHERE {$sql}";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $beans = [];
        foreach ($rows as $row) {
            $bean = new self();
            $bean->type = $type;
            $bean->data = $row;
            $bean->isNew = false;
            $beans[] = $bean;
        }

        return $beans;
    }

    /**
     * Find all beans (with optional ORDER BY)
     */
    public static function findAll(string $type, string $sql = ''): array {
        $type = strtolower($type);
        $pdo = self::getPdo();

        $query = "SELECT * FROM {$type}";
        if ($sql) {
            // Allow ORDER BY, LIMIT etc without WHERE
            $query .= " {$sql}";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $beans = [];
        foreach ($rows as $row) {
            $bean = new self();
            $bean->type = $type;
            $bean->data = $row;
            $bean->isNew = false;
            $beans[] = $bean;
        }

        return $beans;
    }

    /**
     * Find one bean by SQL condition
     */
    public static function findOne(string $type, string $sql = '', array $params = []): ?self {
        $type = strtolower($type);
        $pdo = self::getPdo();

        $query = "SELECT * FROM {$type}";
        if ($sql) {
            $query .= " WHERE {$sql}";
        }
        $query .= " LIMIT 1";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $bean = new self();
        $bean->type = $type;
        $bean->data = $row;
        $bean->isNew = false;

        return $bean;
    }

    /**
     * Count beans
     */
    public static function count(string $type, string $sql = '', array $params = []): int {
        $type = strtolower($type);
        $pdo = self::getPdo();

        $query = "SELECT COUNT(*) FROM {$type}";
        if ($sql) {
            $query .= " WHERE {$sql}";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Store a bean (insert or update)
     */
    public static function store(self $bean): int {
        $pdo = self::getPdo();
        $type = $bean->type;
        $data = $bean->data;

        // Remove id for insert, keep for update
        $id = $data['id'] ?? null;
        unset($data['id']);

        // Handle timestamps
        $now = date('Y-m-d H:i:s');
        if ($bean->isNew) {
            $data['created_at'] = $data['created_at'] ?? $now;
        }
        $data['updated_at'] = $now;

        if ($bean->isNew) {
            // Insert
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));

            $stmt = $pdo->prepare("INSERT INTO {$type} ({$columns}) VALUES ({$placeholders})");
            $stmt->execute(array_values($data));

            $bean->data['id'] = (int) $pdo->lastInsertId();
            $bean->isNew = false;
        } else {
            // Update
            $sets = [];
            foreach (array_keys($data) as $col) {
                $sets[] = "{$col} = ?";
            }
            $setClause = implode(', ', $sets);

            $stmt = $pdo->prepare("UPDATE {$type} SET {$setClause} WHERE id = ?");
            $stmt->execute([...array_values($data), $id]);

            $bean->data['id'] = $id;
        }

        return $bean->data['id'];
    }

    /**
     * Delete a bean
     */
    public static function trash(self $bean): void {
        if ($bean->isNew || !isset($bean->data['id'])) {
            return;
        }

        $pdo = self::getPdo();
        $stmt = $pdo->prepare("DELETE FROM {$bean->type} WHERE id = ?");
        $stmt->execute([$bean->data['id']]);
    }

    /**
     * Execute raw SQL and return results
     */
    public static function getAll(string $sql, array $params = []): array {
        $pdo = self::getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute raw SQL and return single row
     */
    public static function getRow(string $sql, array $params = []): ?array {
        $pdo = self::getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Execute raw SQL and return single value
     */
    public static function getCell(string $sql, array $params = []) {
        $pdo = self::getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute raw SQL (for DDL, etc.)
     */
    public static function exec(string $sql, array $params = []): int {
        $pdo = self::getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get list of tables
     */
    public static function getTables(): array {
        $pdo = self::getPdo();
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Magic getter
     */
    public function __get(string $name) {
        return $this->data[$name] ?? null;
    }

    /**
     * Magic setter
     */
    public function __set(string $name, $value): void {
        $this->data[$name] = $value;
    }

    /**
     * Magic isset
     */
    public function __isset(string $name): bool {
        return isset($this->data[$name]);
    }

    /**
     * Get bean type
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * Check if bean is new (not yet saved)
     */
    public function isNew(): bool {
        return $this->isNew;
    }

    /**
     * Export bean data as array
     */
    public function export(): array {
        return $this->data;
    }

    /**
     * Import data into bean
     */
    public function import(array $data): self {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
        return $this;
    }

    // ArrayAccess implementation
    public function offsetExists($offset): bool {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void {
        unset($this->data[$offset]);
    }

    // JsonSerializable implementation
    public function jsonSerialize(): array {
        return $this->data;
    }
}
