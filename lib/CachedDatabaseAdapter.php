<?php
/**
 * CachedDatabaseAdapter - Transparent Query Cache for RedBeanPHP
 *
 * Drop-in replacement for RedBeanPHP's database adapter that automatically
 * caches SELECT queries and invalidates on data modifications.
 *
 * @author Claude
 * @version 1.0
 */

namespace app;

use \RedBeanPHP\Adapter\DBAdapter;
use \RedBeanPHP\Driver;
use \RedBeanPHP\R;
use \Flight;

class CachedDatabaseAdapter extends DBAdapter {

    // Cache configuration
    private $enabled = true;
    private $defaultTTL = 60;
    private $cachePrefix;

    // Statistics
    private $hits = 0;
    private $misses = 0;

    // Table version tracking
    private $tableVersions = [];

    /**
     * Constructor - wraps existing adapter
     */
    public function __construct($database) {
        parent::__construct($database);

        // Generate unique prefix for multi-workspace safety
        $siteId = md5(__DIR__ . '_' . ($_SERVER['HTTP_HOST'] ?? 'cli'));
        $this->cachePrefix = "rdb_{$siteId}_";

        // Get config if available
        if (class_exists('Flight') && Flight::has('config')) {
            $config = Flight::get('config');
            $this->enabled = $config['cache']['query_cache'] ?? true;
            $this->defaultTTL = $config['cache']['query_cache_ttl'] ?? 60;
        }

        $this->log('CachedDatabaseAdapter initialized');
    }

    /**
     * Override get() - intercepts SELECT queries
     */
    public function get($sql, $bindings = array()) {
        // Only cache SELECT queries
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            return parent::get($sql, $bindings);
        }

        // Check cache first
        $cacheKey = $this->getCacheKey($sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            $this->log("Cache HIT for query", ['sql' => $sql]);
            return $cached['result'];
        }

        // Execute query
        $result = parent::get($sql, $bindings);

        // Cache the result
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;
        $this->log("Cache MISS for query", ['sql' => $sql]);

        return $result;
    }

    /**
     * Override getCell() - intercepts single cell queries
     */
    public function getCell($sql, $bindings = array(), $noSignal = null) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            return parent::getCell($sql, $bindings, $noSignal);
        }

        $cacheKey = $this->getCacheKey('cell_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getCell($sql, $bindings, $noSignal);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override getCol() - intercepts column queries
     */
    public function getCol($sql, $bindings = array()) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            return parent::getCol($sql, $bindings);
        }

        $cacheKey = $this->getCacheKey('col_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getCol($sql, $bindings);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override getRow() - intercepts single row queries
     */
    public function getRow($sql, $bindings = array()) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            return parent::getRow($sql, $bindings);
        }

        $cacheKey = $this->getCacheKey('row_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getRow($sql, $bindings);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override getAssoc() - intercepts associative array queries
     */
    public function getAssoc($sql, $bindings = array()) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            return parent::getAssoc($sql, $bindings);
        }

        $cacheKey = $this->getCacheKey('assoc_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getAssoc($sql, $bindings);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override exec() - intercepts INSERT/UPDATE/DELETE
     */
    public function exec($sql, $bindings = array(), $noEvent = false) {
        $result = parent::exec($sql, $bindings, $noEvent);

        // Invalidate cache for affected tables
        if ($this->enabled && $result !== false) {
            $this->invalidateFromSQL($sql);
        }

        return $result;
    }

    /**
     * Generate cache key from query
     */
    private function getCacheKey($sql, $bindings = array()) {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
        return $this->cachePrefix . md5($normalized . serialize($bindings));
    }

    /**
     * Store result in cache with table tracking
     */
    private function storeInCache($key, $sql, $bindings, $result, $ttl = null) {
        if (!$this->hasAPCu()) {
            return false;
        }

        // Extract tables from SQL
        $tables = $this->extractTables($sql);

        // Get current version for each table
        $versions = [];
        foreach ($tables as $table) {
            $versions[$table] = $this->getTableVersion($table);
        }

        $data = [
            'result' => $result,
            'tables' => $tables,
            'versions' => $versions,
            'cached_at' => time(),
            'sql' => $sql
        ];

        $ttl = $ttl ?? $this->defaultTTL;
        return apcu_store($key, $data, $ttl);
    }

    /**
     * Get result from cache with validation
     */
    private function getFromCache($key) {
        if (!$this->hasAPCu()) {
            return false;
        }

        $data = apcu_fetch($key, $success);
        if (!$success) {
            return false;
        }

        // Validate table versions
        foreach ($data['versions'] as $table => $version) {
            if ($this->getTableVersion($table) !== $version) {
                // Table has been modified, invalidate cache
                apcu_delete($key);
                return false;
            }
        }

        return $data;
    }

    /**
     * Get or create table version
     */
    private function getTableVersion($table) {
        if (!$this->hasAPCu()) {
            return time();
        }

        $key = $this->cachePrefix . 'tv_' . $table;
        $version = apcu_fetch($key, $success);

        if (!$success) {
            $version = time() . '_' . mt_rand();
            apcu_store($key, $version, 86400); // 24 hours
        }

        return $version;
    }

    /**
     * Invalidate cache for a table
     */
    public function invalidateTable($table) {
        if (!$this->hasAPCu()) {
            return;
        }

        // Update table version
        $key = $this->cachePrefix . 'tv_' . $table;
        $newVersion = time() . '_' . mt_rand();
        apcu_store($key, $newVersion, 86400);

        $this->log("Cache invalidated for table: $table");
    }

    /**
     * Extract tables from SQL statement
     */
    private function extractTables($sql) {
        $tables = [];
        $sql = str_replace(['`', '"'], '', $sql);

        // Extract from SELECT/FROM
        if (preg_match_all('/\bFROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Extract from JOIN
        if (preg_match_all('/\bJOIN\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Extract from INSERT INTO
        if (preg_match('/\bINTO\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract from UPDATE
        if (preg_match('/\bUPDATE\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract from DELETE FROM
        if (preg_match('/\bDELETE\s+FROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        return array_unique($tables);
    }

    /**
     * Invalidate cache based on SQL statement
     */
    private function invalidateFromSQL($sql) {
        $tables = $this->extractTables($sql);

        foreach ($tables as $table) {
            $this->invalidateTable($table);
        }
    }

    /**
     * Check if query is SELECT
     */
    private function isSelectQuery($sql) {
        $sql = strtoupper(trim($sql));
        return strpos($sql, 'SELECT') === 0 || strpos($sql, 'SHOW') === 0;
    }

    /**
     * Check if APCu is available
     */
    private function hasAPCu() {
        return function_exists('apcu_fetch') && ini_get('apc.enabled');
    }

    /**
     * Clear all cache
     */
    public function clearAllCache() {
        if (!$this->hasAPCu()) {
            return;
        }

        $iterator = new \APCUIterator('/^' . preg_quote($this->cachePrefix, '/') . '/');
        foreach ($iterator as $item) {
            apcu_delete($item['key']);
        }

        $this->hits = 0;
        $this->misses = 0;

        $this->log('All cache cleared');
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        $stats = [
            'enabled' => $this->enabled,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => ($this->hits + $this->misses) > 0 ?
                round($this->hits / ($this->hits + $this->misses) * 100, 2) : 0
        ];

        if ($this->hasAPCu()) {
            try {
                $pattern = '/^' . preg_quote($this->cachePrefix, '/') . '/';
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
                $stats['cached_queries'] = 0;
                $stats['cache_size_kb'] = 0;
            }
        }

        return $stats;
    }

    /**
     * Enable/disable caching
     */
    public function enableCache() {
        $this->enabled = true;
    }

    public function disableCache() {
        $this->enabled = false;
    }

    /**
     * Simple logging
     */
    private function log($message, $context = []) {
        if (class_exists('Flight') && Flight::has('log')) {
            Flight::get('log')->debug("CachedDatabaseAdapter: $message", $context);
        }
    }
}