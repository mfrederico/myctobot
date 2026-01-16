<?php
/**
 * Bean - RedBeanPHP Wrapper with Query Caching
 *
 * Normalizes bean type names to work around RedBeanPHP's strict naming requirements.
 * R::dispense() requires all lowercase, no underscores. This wrapper accepts:
 * - camelCase: 'enterpriseSettings' -> 'enterprisesettings'
 * - snake_case: 'enterprise_settings' -> 'enterprisesettings'
 * - Already lowercase: 'enterprisesettings' -> 'enterprisesettings'
 *
 * Also provides optional APCu-based query caching for high-performance reads.
 *
 * Usage:
 *   use \app\Bean;
 *
 *   // Standard operations
 *   $setting = Bean::findOne('enterpriseSettings', 'setting_key = ?', ['api_key']);
 *   if (!$setting) {
 *       $setting = Bean::dispense('enterpriseSettings');
 *       $setting->setting_key = 'api_key';
 *   }
 *   Bean::store($setting);
 *
 *   // Cached operations (when APCu is available)
 *   $boards = Bean::findCached('jiraboards', 'is_active = 1', [], 300); // 5 min TTL
 *   $count = Bean::countCached('aidevjobs', 'status = ?', ['pending'], 60);
 *
 *   // Cache management
 *   Bean::invalidateTable('jiraboards'); // After modifications
 *   Bean::clearCache(); // Clear all cached queries
 */

namespace app;

use \RedBeanPHP\R as R;

class Bean {

    // ========================================
    // Cache Configuration
    // ========================================

    /** @var bool Cache enabled flag */
    private static bool $cacheEnabled = true;

    /** @var int Default cache TTL in seconds */
    private static int $defaultTTL = 60;

    /** @var string|null Cache key prefix (unique per installation) */
    private static ?string $cachePrefix = null;

    /** @var int Cache hits counter */
    private static int $cacheHits = 0;

    /** @var int Cache misses counter */
    private static int $cacheMisses = 0;

    /** @var array Track registered database keys */
    private static array $registeredDatabases = [];

    // ========================================
    // Bean Type Normalization
    // ========================================

    /**
     * Normalize bean type to lowercase, no underscores
     * This is required for R::dispense() to work
     *
     * @param string $type Bean type (camelCase, snake_case, or lowercase)
     * @return string Normalized lowercase bean type
     */
    public static function normalize(string $type): string {
        return strtolower(str_replace('_', '', $type));
    }

    // ========================================
    // Standard Bean Operations
    // ========================================

    /**
     * Create a new bean
     *
     * @param string $type Bean type
     * @return \RedBeanPHP\OODBBean
     */
    public static function dispense(string $type) {
        return R::dispense(self::normalize($type));
    }

    /**
     * Load a bean by ID
     *
     * @param string $type Bean type
     * @param int $id Bean ID
     * @return \RedBeanPHP\OODBBean
     */
    public static function load(string $type, int $id) {
        return R::load(self::normalize($type), $id);
    }

    /**
     * Find a single bean matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function findOne(string $type, ?string $sql = null, array $params = []) {
        return R::findOne(self::normalize($type), $sql, $params);
    }

    /**
     * Find a single bean or throw an exception if not found
     * Reduces duplicate pattern: $bean = Bean::findOne(...); if (!$bean) { throw/error; }
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @param string|null $errorMessage Custom error message (default: "{Type} not found")
     * @return \RedBeanPHP\OODBBean
     * @throws \Exception If bean not found
     */
    public static function findOneOrFail(string $type, ?string $sql = null, array $params = [], ?string $errorMessage = null) {
        $bean = R::findOne(self::normalize($type), $sql, $params);
        if (!$bean) {
            $message = $errorMessage ?? ucfirst(self::normalize($type)) . ' not found';
            throw new \Exception($message);
        }
        return $bean;
    }

    /**
     * Load a bean by ID or throw an exception if not found
     * Reduces duplicate pattern: $bean = Bean::load(...); if (!$bean->id) { throw/error; }
     *
     * @param string $type Bean type
     * @param int $id Bean ID
     * @param string|null $errorMessage Custom error message
     * @return \RedBeanPHP\OODBBean
     * @throws \Exception If bean not found
     */
    public static function loadOrFail(string $type, int $id, ?string $errorMessage = null) {
        $bean = R::load(self::normalize($type), $id);
        if (!$bean || !$bean->id) {
            $message = $errorMessage ?? ucfirst(self::normalize($type)) . ' not found';
            throw new \Exception($message);
        }
        return $bean;
    }

    /**
     * Find all beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function find(string $type, ?string $sql = null, array $params = []) {
        return R::find(self::normalize($type), $sql, $params);
    }

    /**
     * Find all beans matching criteria (alias for find)
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function findAll(string $type, ?string $sql = null, array $params = []) {
        return R::findAll(self::normalize($type), $sql, $params);
    }

    /**
     * Count beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return int Count
     */
    public static function count(string $type, ?string $sql = null, array $params = []) {
        // R::count calls trim() on $sql, so ensure it's never null
        return R::count(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Store a bean
     *
     * @param \RedBeanPHP\OODBBean $bean Bean to store
     * @return int|string Bean ID
     */
    public static function store($bean) {
        // Invalidate cache for this table after store
        if ($bean && $bean->getMeta('type')) {
            self::invalidateTable($bean->getMeta('type'));
        }
        return R::store($bean);
    }

    /**
     * Delete a bean
     *
     * @param \RedBeanPHP\OODBBean|string $beanOrType Bean or type name
     * @param int|null $id Optional ID if first param is type
     * @return void
     */
    public static function trash($beanOrType, ?int $id = null) {
        // Invalidate cache for this table after trash
        if (is_string($beanOrType)) {
            self::invalidateTable(self::normalize($beanOrType));
            if ($id !== null) {
                return R::trash(self::normalize($beanOrType), $id);
            }
        } elseif ($beanOrType && $beanOrType->getMeta('type')) {
            self::invalidateTable($beanOrType->getMeta('type'));
        }
        return R::trash($beanOrType);
    }

    /**
     * Delete multiple beans
     *
     * @param array $beans Array of beans to delete
     * @return void
     */
    public static function trashAll(array $beans) {
        // Invalidate cache for affected tables
        $tables = [];
        foreach ($beans as $bean) {
            if ($bean && $bean->getMeta('type')) {
                $tables[$bean->getMeta('type')] = true;
            }
        }
        foreach (array_keys($tables) as $table) {
            self::invalidateTable($table);
        }
        return R::trashAll($beans);
    }

    /**
     * Execute raw SQL (use sparingly - only for DDL or complex operations)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return int Affected rows
     */
    public static function exec(string $sql, array $params = []) {
        // Invalidate relevant tables for modifying queries
        if (!self::isSelectQuery($sql)) {
            $tables = self::extractTablesFromSql($sql);
            foreach ($tables as $table) {
                self::invalidateTable($table);
            }
        }
        return R::exec($sql, $params);
    }

    /**
     * Get all rows as arrays (for complex SELECT with joins)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Array of arrays
     */
    public static function getAll(string $sql, array $params = []) {
        return R::getAll($sql, $params);
    }

    /**
     * Execute SQL and return first row
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array|null First row or null
     */
    public static function getRow(string $sql, array $params = []) {
        return R::getRow($sql, $params);
    }

    /**
     * Convert array of rows to beans
     *
     * @param string $type Bean type
     * @param array $rows Array of row arrays
     * @return array Array of beans
     */
    public static function convertToBeans(string $type, array $rows) {
        $type = self::normalize($type);
        return R::convertToBeans($type, $rows);
    }

    // ========================================
    // Database Management
    // ========================================

    /**
     * Check if a database key is already registered
     *
     * @param string $key Database key
     * @return bool
     */
    public static function hasDatabase(string $key): bool {
        return isset(self::$registeredDatabases[$key]);
    }

    /**
     * Add a database connection (safe - won't error if already registered)
     *
     * @param string $key Database key
     * @param string $dsn DSN string
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     * @return bool True if newly added, false if already existed
     */
    public static function addDatabase(string $key, string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false): bool {
        if (self::hasDatabase($key)) {
            return false; // Already registered
        }

        R::addDatabase($key, $dsn, $user, $pass, $frozen);
        self::$registeredDatabases[$key] = true;
        return true;
    }

    /**
     * Select a database connection
     *
     * @param string $key Database key
     */
    public static function selectDatabase(string $key) {
        return R::selectDatabase($key);
    }

    /**
     * Add and select a database in one call (convenience method)
     *
     * @param string $key Database key
     * @param string $dsn DSN string
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     */
    public static function useDatabase(string $key, string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false): void {
        self::addDatabase($key, $dsn, $user, $pass, $frozen);
        self::selectDatabase($key);
    }

    /**
     * Set freeze mode
     *
     * @param bool $frozen Freeze mode
     */
    public static function freeze(bool $frozen = true) {
        return R::freeze($frozen);
    }

    /**
     * Close database connection
     */
    public static function close() {
        return R::close();
    }

    /**
     * Get a single cell value from a query
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return mixed Single cell value
     */
    public static function getCell(string $sql, array $params = []) {
        return R::getCell($sql, $params);
    }

    /**
     * Get a single column as array
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Column values
     */
    public static function getCol(string $sql, array $params = []) {
        return R::getCol($sql, $params);
    }

    /**
     * Begin a transaction
     */
    public static function begin() {
        return R::begin();
    }

    /**
     * Commit a transaction
     */
    public static function commit() {
        return R::commit();
    }

    /**
     * Rollback a transaction
     */
    public static function rollback() {
        return R::rollback();
    }

    /**
     * Export all beans to arrays
     *
     * @param array $beans Array of beans
     * @return array Array of arrays
     */
    public static function exportAll(array $beans) {
        return R::exportAll($beans);
    }

    /**
     * Setup database connection (for standalone scripts)
     *
     * @param string $dsn Database DSN
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     */
    public static function setup(string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false) {
        return R::setup($dsn, $user, $pass, $frozen);
    }

    /**
     * Get the database adapter
     *
     * @return \RedBeanPHP\Adapter\DBAdapter
     */
    public static function getDatabaseAdapter() {
        return R::getDatabaseAdapter();
    }

    /**
     * Nuke (destroy) the database - use with caution!
     */
    public static function nuke() {
        return R::nuke();
    }

    // ========================================
    // Cached Query Operations
    // ========================================

    /**
     * Find beans with APCu caching
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @param int|null $ttl Cache TTL in seconds (default: 60)
     * @return array Array of beans
     */
    public static function findCached(string $type, ?string $sql = null, array $params = [], ?int $ttl = null) {
        $type = self::normalize($type);

        if (!self::$cacheEnabled || !self::hasAPCu()) {
            return R::find($type, $sql, $params);
        }

        // Build cache key
        $fullSql = "SELECT * FROM `{$type}`" . ($sql ? " WHERE {$sql}" : '');
        $cacheKey = self::getCacheKey($fullSql, $params);

        // Check if table version invalidated the cache
        if (!self::isTableVersionValid($type, $cacheKey)) {
            self::removeFromCache($cacheKey);
        }

        // Try cache
        $cached = self::getFromCache($cacheKey);
        if ($cached !== false) {
            self::$cacheHits++;
            return R::convertToBeans($type, $cached['result']);
        }

        // Execute query
        $beans = R::find($type, $sql, $params);

        // Cache the exported result
        $result = R::exportAll($beans);
        self::storeInCache($cacheKey, $result, [$type], $ttl);
        self::$cacheMisses++;

        return $beans;
    }

    /**
     * Find single bean with APCu caching
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @param int|null $ttl Cache TTL in seconds
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function findOneCached(string $type, ?string $sql = null, array $params = [], ?int $ttl = null) {
        $beans = self::findCached($type, $sql . ' LIMIT 1', $params, $ttl);
        return reset($beans) ?: null;
    }

    /**
     * Count beans with APCu caching
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @param int|null $ttl Cache TTL in seconds
     * @return int Count
     */
    public static function countCached(string $type, ?string $sql = null, array $params = [], ?int $ttl = null) {
        $type = self::normalize($type);

        if (!self::$cacheEnabled || !self::hasAPCu()) {
            return R::count($type, $sql ?? '', $params);
        }

        // Build cache key
        $fullSql = "COUNT(*) FROM `{$type}`" . ($sql ? " WHERE {$sql}" : '');
        $cacheKey = self::getCacheKey($fullSql, $params);

        // Check if table version invalidated the cache
        if (!self::isTableVersionValid($type, $cacheKey)) {
            self::removeFromCache($cacheKey);
        }

        // Try cache
        $cached = self::getFromCache($cacheKey);
        if ($cached !== false) {
            self::$cacheHits++;
            return $cached['result'];
        }

        // Execute query
        $result = R::count($type, $sql ?? '', $params);

        // Cache the result
        self::storeInCache($cacheKey, $result, [$type], $ttl);
        self::$cacheMisses++;

        return $result;
    }

    /**
     * Execute a cached raw query (SELECT only)
     *
     * @param string $sql SQL SELECT statement
     * @param array $params Bound parameters
     * @param int|null $ttl Cache TTL in seconds
     * @return array Array of arrays
     */
    public static function getAllCached(string $sql, array $params = [], ?int $ttl = null) {
        if (!self::$cacheEnabled || !self::hasAPCu() || !self::isSelectQuery($sql)) {
            return R::getAll($sql, $params);
        }

        $cacheKey = self::getCacheKey($sql, $params);

        // Try cache
        $cached = self::getFromCache($cacheKey);
        if ($cached !== false) {
            self::$cacheHits++;
            return $cached['result'];
        }

        // Execute query
        $result = R::getAll($sql, $params);

        // Cache the result with extracted table names
        $tables = self::extractTablesFromSql($sql);
        self::storeInCache($cacheKey, $result, $tables, $ttl);
        self::$cacheMisses++;

        return $result;
    }

    // ========================================
    // Cache Management
    // ========================================

    /**
     * Invalidate all cached queries for a table
     * Call this after modifying data in a table
     *
     * @param string $table Table name
     */
    public static function invalidateTable(string $table): void {
        if (!self::hasAPCu()) {
            return;
        }

        $table = self::normalize($table);
        $key = self::getCachePrefix() . 'tv_' . $table;
        $newVersion = time() . '_' . mt_rand();
        apcu_store($key, $newVersion, 86400); // 24 hours
    }

    /**
     * Clear all cached queries
     */
    public static function clearCache(): void {
        if (!self::hasAPCu()) {
            return;
        }

        $prefix = self::getCachePrefix();
        $iterator = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');
        foreach ($iterator as $item) {
            apcu_delete($item['key']);
        }

        self::$cacheHits = 0;
        self::$cacheMisses = 0;
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public static function getCacheStats(): array {
        $stats = [
            'enabled' => self::$cacheEnabled,
            'apcu_available' => self::hasAPCu(),
            'hits' => self::$cacheHits,
            'misses' => self::$cacheMisses,
            'hit_rate' => (self::$cacheHits + self::$cacheMisses) > 0
                ? round(self::$cacheHits / (self::$cacheHits + self::$cacheMisses) * 100, 2)
                : 0,
            'cached_queries' => 0,
            'cache_size_kb' => 0
        ];

        if (self::hasAPCu()) {
            try {
                $prefix = self::getCachePrefix();
                $pattern = '/^' . preg_quote($prefix, '/') . '/';
                $iterator = new \APCUIterator($pattern);
                $count = 0;
                $size = 0;

                foreach ($iterator as $item) {
                    if (strpos($item['key'], '_tv_') === false) {
                        $count++;
                        $size += $item['mem_size'];
                    }
                }

                $stats['cached_queries'] = $count;
                $stats['cache_size_kb'] = round($size / 1024, 2);
            } catch (\Exception $e) {
                // Ignore iteration errors
            }
        }

        return $stats;
    }

    /**
     * Enable query caching
     */
    public static function enableCache(): void {
        self::$cacheEnabled = true;
    }

    /**
     * Disable query caching
     */
    public static function disableCache(): void {
        self::$cacheEnabled = false;
    }

    /**
     * Set default cache TTL
     *
     * @param int $ttl TTL in seconds
     */
    public static function setDefaultTTL(int $ttl): void {
        self::$defaultTTL = $ttl;
    }

    // ========================================
    // Cache Internal Methods
    // ========================================

    /**
     * Check if APCu is available
     */
    private static function hasAPCu(): bool {
        return function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && ini_get('apc.enabled')
            && (php_sapi_name() !== 'cli' || ini_get('apc.enable_cli'));
    }

    /**
     * Get cache key prefix (unique per installation)
     */
    private static function getCachePrefix(): string {
        if (self::$cachePrefix === null) {
            $siteId = md5(__DIR__ . '_' . ($_SERVER['HTTP_HOST'] ?? 'cli'));
            self::$cachePrefix = "bean_{$siteId}_";
        }
        return self::$cachePrefix;
    }

    /**
     * Generate cache key from SQL and bindings
     */
    private static function getCacheKey(string $sql, array $bindings = []): string {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
        return self::getCachePrefix() . md5($normalized . serialize($bindings));
    }

    /**
     * Store result in cache
     */
    private static function storeInCache(string $key, $result, array $tables = [], ?int $ttl = null): bool {
        if (!self::hasAPCu()) {
            return false;
        }

        // Get current table versions
        $versions = [];
        foreach ($tables as $table) {
            $versions[$table] = self::getTableVersion($table);
        }

        $data = [
            'result' => $result,
            'tables' => $tables,
            'versions' => $versions,
            'cached_at' => time()
        ];

        return apcu_store($key, $data, $ttl ?? self::$defaultTTL);
    }

    /**
     * Get result from cache
     */
    private static function getFromCache(string $key) {
        if (!self::hasAPCu()) {
            return false;
        }

        $data = apcu_fetch($key, $success);
        if (!$success) {
            return false;
        }

        // Validate table versions haven't changed
        foreach ($data['versions'] as $table => $version) {
            if (self::getTableVersion($table) !== $version) {
                apcu_delete($key);
                return false;
            }
        }

        return $data;
    }

    /**
     * Remove from cache
     */
    private static function removeFromCache(string $key): void {
        if (self::hasAPCu()) {
            apcu_delete($key);
        }
    }

    /**
     * Get current version of a table
     */
    private static function getTableVersion(string $table): string {
        if (!self::hasAPCu()) {
            return (string) time();
        }

        $key = self::getCachePrefix() . 'tv_' . $table;
        $version = apcu_fetch($key, $success);

        if (!$success) {
            $version = time() . '_' . mt_rand();
            apcu_store($key, $version, 86400);
        }

        return $version;
    }

    /**
     * Check if table version is still valid for cached entry
     */
    private static function isTableVersionValid(string $table, string $cacheKey): bool {
        $cached = self::getFromCache($cacheKey);
        if ($cached === false) {
            return true; // Not cached yet
        }

        if (isset($cached['versions'][$table])) {
            return $cached['versions'][$table] === self::getTableVersion($table);
        }

        return true;
    }

    /**
     * Check if SQL is a SELECT query
     */
    private static function isSelectQuery(string $sql): bool {
        return stripos(trim($sql), 'SELECT') === 0;
    }

    /**
     * Extract table names from SQL
     */
    private static function extractTablesFromSql(string $sql): array {
        $tables = [];
        $sql = str_replace('`', '', $sql);

        if (preg_match_all('/\bFROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (preg_match_all('/\bJOIN\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (preg_match_all('/\bUPDATE\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (preg_match_all('/\bINSERT\s+INTO\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (preg_match_all('/\bDELETE\s+FROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique(array_map([self::class, 'normalize'], $tables));
    }
}
