<?php
/**
 * Permission Cache with APCu Support
 * Provides high-performance permission checking with shared memory caching
 * Falls back to per-request caching if APCu is not available
 */

namespace app;

use \RedBeanPHP\R as R;
use \Flight as Flight;

class PermissionCache {

    // Process-local cache (fastest access)
    private static $localCache = null;

    // Cache configuration - Use unique keys per installation
    private static $CACHE_KEY = null;
    private const CACHE_TTL = 3600; // 1 hour
    private static $STATS_KEY = null;
    private static $CACHE_VERSION_FILE = null;

    /**
     * Get cache version file path
     */
    private static function getCacheVersionFile() {
        if (self::$CACHE_VERSION_FILE === null) {
            $cacheDir = dirname(__DIR__) . '/cache';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            self::$CACHE_VERSION_FILE = $cacheDir . '/.permission_cache_version';
        }
        return self::$CACHE_VERSION_FILE;
    }

    /**
     * Get current cache version (timestamp from version file)
     */
    private static function getCacheVersion() {
        $versionFile = self::getCacheVersionFile();
        if (file_exists($versionFile)) {
            return (int)file_get_contents($versionFile);
        }
        return 0;
    }

    /**
     * Get unique cache key for this installation (includes version for cache busting)
     */
    private static function getCacheKey() {
        if (self::$CACHE_KEY === null) {
            // Create unique key based on installation path and version
            $siteId = md5(__DIR__ . '_' . ($_SERVER['HTTP_HOST'] ?? 'cli'));
            $version = self::getCacheVersion();
            self::$CACHE_KEY = "tiknix_{$siteId}_permissions_v{$version}";
            self::$STATS_KEY = "tiknix_{$siteId}_stats";
        }
        return self::$CACHE_KEY;
    }

    /**
     * Get stats cache key
     */
    private static function getStatsKey() {
        if (self::$STATS_KEY === null) {
            self::getCacheKey(); // Initialize both keys
        }
        return self::$STATS_KEY;
    }

    /**
     * Check if user has permission for controller/method
     *
     * @param string $control Controller name
     * @param string $method Method name
     * @param int $userLevel User's permission level
     * @return bool
     */
    public static function check($control, $method, $userLevel) {
        // Normalize names
        $control = strtolower($control);
        $method = strtolower($method);

        // Routes that are always public (no database lookup needed)
        // These include custom routes that don't map to controllers
        static $publicRoutes = [
            'index::index',
            'login::index',         // Custom route /login
            'login::*',             // Custom route /login/{workspace}
            'auth::login',
            'auth::dologin',
            'auth::logout',
            'auth::register',
            'auth::doregister',
            'auth::forgot',
            'auth::doforgot',
            'auth::reset',
            'auth::doreset',
            'auth::google',
            'auth::googlecallback',
            'error::notfound',
            'error::forbidden',
            'error::servererror',
            'health::index',
            'webhook::jira',
            'webhook::github',
            'signup::index',
            'signup::pending',
            'signup::verify',
            'signup::resend',
        ];

        $key = "{$control}::{$method}";
        if (in_array($key, $publicRoutes) || in_array("{$control}::*", $publicRoutes)) {
            return true;
        }

        // Ensure cache is loaded
        self::ensureLoaded();

        // Check specific method permission
        if (isset(self::$localCache[$key])) {
            $requiredLevel = self::$localCache[$key];
            self::logAccess('hit', $key);
            $hasPermission = $userLevel <= $requiredLevel;

            // Increment validcount when permission is granted
            if ($hasPermission) {
                self::incrementValidCount($control, $method);
            }

            return $hasPermission;
        }

        // Check wildcard permission for entire controller
        $wildcardKey = strtolower("{$control}::*");
        if (isset(self::$localCache[$wildcardKey])) {
            $requiredLevel = self::$localCache[$wildcardKey];
            self::logAccess('wildcard', $wildcardKey);
            $hasPermission = $userLevel <= $requiredLevel;

            // Increment validcount for wildcard when permission is granted
            if ($hasPermission) {
                self::incrementValidCount($control, '*');
            }

            return $hasPermission;
        }

        // No permission found - check if we're in build mode
        if (Flight::get('build')) {
            self::logAccess('build', $key);
            // Auto-create permission in build mode
            self::createPermission($control, $method);
            return true; // Allow access in build mode
        }

        // Default to public access if no permission defined
        self::logAccess('default', $key);
        return $userLevel <= LEVELS['PUBLIC'];
    }

    /**
     * Ensure cache is loaded into memory
     */
    private static function ensureLoaded() {
        // If already in process memory, return immediately (fastest)
        if (self::$localCache !== null) {
            return;
        }

        // Try to load from APCu shared memory
        if (self::hasAPCu()) {
            self::$localCache = apcu_fetch(self::getCacheKey(), $success);
            if ($success) {
                Flight::get('log')->debug('PermissionCache: Loaded from APCu');
                self::incrementStat('apcu_hits');
                return;
            }
        }

        // Load from database
        self::loadFromDatabase();

        // Store in APCu if available
        if (self::hasAPCu() && self::$localCache !== null) {
            apcu_store(self::getCacheKey(), self::$localCache, self::CACHE_TTL);
            Flight::get('log')->info('PermissionCache: Stored in APCu');
        }
    }

    /**
     * Load permissions from database
     */
    private static function loadFromDatabase() {
        try {
            $startTime = microtime(true);

            // Load all permissions from database
            $permissions = R::findAll('authcontrol');

            self::$localCache = [];

            foreach ($permissions as $perm) {
                // Store with lowercase key for case-insensitive lookups
                $key = strtolower("{$perm->control}::{$perm->method}");
                self::$localCache[$key] = (int)$perm->level;
            }

            $loadTime = round((microtime(true) - $startTime) * 1000, 2);

            Flight::get('log')->info('PermissionCache: Loaded from database', [
                'count' => count(self::$localCache),
                'time_ms' => $loadTime
            ]);

            self::incrementStat('db_loads');

        } catch (\Exception $e) {
            // Warning instead of error - this is expected on shards without DB access
            // Shards use API key authentication, not session-based permissions
            // TODO: If DB replication is added to shards, this can be removed
            Flight::get('log')->warning('PermissionCache: No database connection (expected on shards)', [
                'error' => $e->getMessage(),
                'note' => 'Shard endpoints use API key auth instead'
            ]);
            // Initialize empty cache to prevent repeated failures
            self::$localCache = [];
        }
    }

    /**
     * Clear the cache (call after permission changes)
     * Works from both CLI and web by incrementing version file
     */
    public static function clear() {
        // Clear local cache
        self::$localCache = null;

        // Clear APCu stats counters before changing version
        if (self::hasAPCu()) {
            $statsKey = self::getStatsKey();
            apcu_delete($statsKey . '_apcu_hits');
            apcu_delete($statsKey . '_db_loads');
        }

        // Increment version file to invalidate all APCu caches across all processes
        $versionFile = self::getCacheVersionFile();
        $newVersion = time();
        file_put_contents($versionFile, $newVersion);

        // Reset cache keys so they use new version
        self::$CACHE_KEY = null;
        self::$STATS_KEY = null;

        Flight::get('log')->info('PermissionCache: Cache cleared (version: ' . $newVersion . ')');
    }

    /**
     * Reload cache from database
     */
    public static function reload() {
        self::clear();
        self::ensureLoaded();
        return self::$localCache;
    }

    /**
     * Get cache statistics
     * Returns consistent field names across all views
     */
    public static function getStats() {
        $apcu_hits = 0;
        $db_loads = 0;

        // Get APCu counters if available
        if (self::hasAPCu()) {
            $apcu_hits = apcu_fetch(self::getStatsKey() . '_apcu_hits') ?: 0;
            $db_loads = apcu_fetch(self::getStatsKey() . '_db_loads') ?: 0;
        }

        // Calculate hit rate
        $total_requests = $apcu_hits + $db_loads;
        $hit_rate = $total_requests > 0 ? ($apcu_hits / $total_requests) * 100 : 0;

        $stats = [
            // Basic cache status
            'apcu_available' => self::hasAPCu(),
            'cache_loaded' => self::$localCache !== null,
            'in_apcu' => false,

            // Permission counts and memory
            'count' => self::$localCache ? count(self::$localCache) : 0,
            'memory' => self::$localCache ? strlen(serialize(self::$localCache)) : 0,

            // Cache performance metrics
            'hits' => $apcu_hits,
            'misses' => $db_loads,
            'hit_rate' => $hit_rate,

            // Cache version
            'cache_version' => self::getCacheVersion()
        ];

        // Get additional APCu-specific stats
        if (self::hasAPCu()) {
            $stats['in_apcu'] = apcu_exists(self::getCacheKey());

            if ($stats['in_apcu']) {
                $info = apcu_key_info(self::getCacheKey());
                if ($info) {
                    $stats['apcu_ttl'] = $info['ttl'] ?? null;
                    $stats['apcu_hits_on_key'] = $info['num_hits'] ?? null;
                }
            }
        }

        return $stats;
    }

    /**
     * Warm up the cache (useful for deployment/startup)
     */
    public static function warmup() {
        self::clear();
        self::ensureLoaded();

        $stats = self::getStats();
        Flight::get('log')->info('PermissionCache: Warmed up', $stats);

        return $stats;
    }

    /**
     * Check if APCu is available and enabled
     */
    private static function hasAPCu() {
        return function_exists('apcu_fetch') &&
               function_exists('apcu_store') &&
               ini_get('apc.enabled') &&
               (php_sapi_name() !== 'cli' || ini_get('apc.enable_cli'));
    }

    /**
     * Create a new permission entry (build mode only)
     * Only creates if the controller and method actually exist
     */
    private static function createPermission($control, $method) {
        if (!Flight::get('build')) {
            return false;
        }

        // Verify controller class exists (use ucfirst to match routing convention)
        $className = "app\\" . ucfirst($control);
        if (!class_exists($className)) {
            Flight::get('log')->debug("PermissionCache: Controller class not found: {$className}");
            return false;
        }

        // Verify method exists in the controller
        if (!method_exists($className, $method)) {
            Flight::get('log')->debug("PermissionCache: Method not found: {$className}::{$method}");
            return false;
        }

        // Check if method is public (required for routing)
        $reflection = new \ReflectionMethod($className, $method);
        if (!$reflection->isPublic()) {
            Flight::get('log')->debug("PermissionCache: Method is not public: {$className}::{$method}");
            return false;
        }

        try {
            // Use logged-in user's level if available, otherwise default to MEMBER level
            // This ensures auto-generated routes require at least the access level of the first user to access them
            $userLevel = LEVELS['MEMBER']; // Default to member level (100)
            if (isset($_SESSION['member']['level'])) {
                $userLevel = (int)$_SESSION['member']['level'];
            }

            Flight::get('log')->info("PermissionCache: Auto-creating permission for {$control}->{$method} with level {$userLevel}");

            $auth = R::dispense('authcontrol');
            $auth->control = $control;
            $auth->method = $method;
            $auth->level = $userLevel;
            $auth->description = "Auto-generated permission for {$control}::{$method}";
            $auth->validcount = 0;
            $auth->created_at = date('Y-m-d H:i:s');
            R::store($auth);

            // Add to local cache immediately
            $key = strtolower("{$control}::{$method}");
            if (self::$localCache !== null) {
                self::$localCache[$key] = $userLevel;
            }

            // Clear APCu to force reload on next request
            if (self::hasAPCu()) {
                apcu_delete(self::getCacheKey());
            }

            return true;
        } catch (\Exception $e) {
            Flight::get('log')->error('PermissionCache: Failed to create permission', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Log cache access for debugging
     */
    private static function logAccess($type, $key) {
        if (Flight::get('debug')) {
            Flight::get('log')->debug("PermissionCache: {$type} for {$key}");
        }
    }

    /**
     * Increment statistics counter
     */
    private static function incrementStat($stat) {
        if (self::hasAPCu()) {
            $key = self::getStatsKey() . '_' . $stat;
            apcu_inc($key, 1, $success, self::CACHE_TTL);
        }
    }

    /**
     * Get all cached permissions (for debugging/admin panel)
     */
    public static function getAll() {
        self::ensureLoaded();
        return self::$localCache;
    }

    /**
     * Add or update a permission in cache
     */
    public static function set($control, $method, $level) {
        self::ensureLoaded();

        $key = strtolower("{$control}::{$method}");
        self::$localCache[$key] = (int)$level;

        // Update APCu if available
        if (self::hasAPCu()) {
            apcu_store(self::getCacheKey(), self::$localCache, self::CACHE_TTL);
        }

        Flight::get('log')->debug("PermissionCache: Set {$key} = {$level}");
    }

    /**
     * Remove a permission from cache
     */
    public static function remove($control, $method) {
        self::ensureLoaded();

        $key = strtolower("{$control}::{$method}");
        unset(self::$localCache[$key]);

        // Update APCu if available
        if (self::hasAPCu()) {
            apcu_store(self::getCacheKey(), self::$localCache, self::CACHE_TTL);
        }

        Flight::get('log')->debug("PermissionCache: Removed {$key}");
    }

    /**
     * Increment validcount for a permission (async to avoid performance impact)
     *
     * @param string $control Controller name
     * @param string $method Method name
     */
    private static function incrementValidCount($control, $method) {
        // Use a deferred write to avoid blocking the request
        // Only increment once per request to avoid multiple increments
        static $incrementedThisRequest = [];

        $key = strtolower("{$control}::{$method}");

        if (isset($incrementedThisRequest[$key])) {
            return; // Already incremented this request
        }

        $incrementedThisRequest[$key] = true;

        try {
            // Use a simple SQL UPDATE for better performance
            R::exec(
                'UPDATE authcontrol SET validcount = validcount + 1 WHERE LOWER(control) = ? AND LOWER(method) = ?',
                [strtolower($control), strtolower($method)]
            );
        } catch (\Exception $e) {
            // Silently fail - don't interrupt request for counter update
            Flight::get('log')->debug("PermissionCache: Failed to increment validcount for {$key}", [
                'error' => $e->getMessage()
            ]);
        }
    }
}