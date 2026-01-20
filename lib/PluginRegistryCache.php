<?php
/**
 * Plugin Registry Cache with APCu Support
 * Provides high-performance plugin metadata caching with shared memory
 * Falls back to per-request caching if APCu is not available
 *
 * Features:
 * - Multi-workspace support via workspace-specific cache keys
 * - Automatic TTL-based refresh
 * - Source error preservation (keeps cached data on source failure)
 * - Manual refresh capability for administrators
 */

namespace app;

use \Flight as Flight;

class PluginRegistryCache {

    // Process-local cache (fastest access)
    private static $localCache = null;

    // Cache configuration - Use unique keys per installation/workspace
    private static $CACHE_KEY = null;
    private static $STATS_KEY = null;
    private static $ERRORS_KEY = null;
    private static $CACHE_VERSION_FILE = null;

    // Default TTL: 1 hour (can be overridden by config)
    private const DEFAULT_TTL = 3600;

    /**
     * Get cache directory path
     */
    private static function getCacheDir() {
        $cacheDir = dirname(__DIR__) . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        return $cacheDir;
    }

    /**
     * Get cache version file path
     */
    private static function getCacheVersionFile() {
        if (self::$CACHE_VERSION_FILE === null) {
            $cacheDir = self::getCacheDir();
            $workspaceSlug = self::getWorkspaceSlug();
            self::$CACHE_VERSION_FILE = $cacheDir . "/.plugin_cache_version_{$workspaceSlug}";
        }
        return self::$CACHE_VERSION_FILE;
    }

    /**
     * Get current workspace slug for multi-workspace support
     */
    private static function getWorkspaceSlug() {
        return $_SESSION['workspace_slug'] ?? 'default';
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
     * Get configured TTL in seconds
     */
    private static function getTTL() {
        try {
            $configTtl = Flight::get('plugin_registry.refresh_interval');
            return $configTtl ? (int)$configTtl : self::DEFAULT_TTL;
        } catch (\Exception $e) {
            return self::DEFAULT_TTL;
        }
    }

    /**
     * Get unique cache key for this installation/workspace (includes version for cache busting)
     */
    private static function getCacheKey() {
        if (self::$CACHE_KEY === null) {
            // Create unique key based on installation path, workspace, and version
            $workspaceSlug = self::getWorkspaceSlug();
            $siteId = md5(__DIR__ . '_' . ($_SERVER['HTTP_HOST'] ?? 'cli') . '_' . $workspaceSlug);
            $version = self::getCacheVersion();
            self::$CACHE_KEY = "myctobot_{$siteId}_plugins_v{$version}";
            self::$STATS_KEY = "myctobot_{$siteId}_plugin_stats";
            self::$ERRORS_KEY = "myctobot_{$siteId}_plugin_errors";
        }
        return self::$CACHE_KEY;
    }

    /**
     * Get stats cache key
     */
    private static function getStatsKey() {
        if (self::$STATS_KEY === null) {
            self::getCacheKey(); // Initialize all keys
        }
        return self::$STATS_KEY;
    }

    /**
     * Get errors cache key
     */
    private static function getErrorsKey() {
        if (self::$ERRORS_KEY === null) {
            self::getCacheKey(); // Initialize all keys
        }
        return self::$ERRORS_KEY;
    }

    /**
     * Get cached plugin list
     *
     * @return array Array of plugin metadata
     */
    public static function getPlugins() {
        self::ensureLoaded();
        return self::$localCache['plugins'] ?? [];
    }

    /**
     * Get timestamp of last successful refresh
     *
     * @return int|null Unix timestamp or null if never refreshed
     */
    public static function getLastRefresh() {
        self::ensureLoaded();
        return self::$localCache['last_refresh'] ?? null;
    }

    /**
     * Get errors from last refresh attempt
     *
     * @return array Array of error messages with timestamps
     */
    public static function getRefreshErrors() {
        if (self::hasAPCu()) {
            $errors = apcu_fetch(self::getErrorsKey(), $success);
            if ($success) {
                return $errors;
            }
        }

        // Fallback to file-based error storage
        $errorFile = self::getCacheDir() . '/.plugin_errors_' . self::getWorkspaceSlug() . '.json';
        if (file_exists($errorFile)) {
            $content = file_get_contents($errorFile);
            return json_decode($content, true) ?: [];
        }

        return [];
    }

    /**
     * Refresh plugin cache from configured sources
     *
     * @param bool $force Force refresh even if TTL hasn't expired
     * @return array Result with 'success', 'count', 'errors' keys
     */
    public static function refresh($force = false) {
        $logger = self::getLogger();
        $startTime = microtime(true);

        // Check if refresh is needed based on TTL
        if (!$force) {
            self::ensureLoaded();
            $lastRefresh = self::$localCache['last_refresh'] ?? 0;
            $ttl = self::getTTL();

            if ($lastRefresh > 0 && (time() - $lastRefresh) < $ttl) {
                $logger->debug('PluginRegistryCache: Skipping refresh, TTL not expired', [
                    'last_refresh' => date('Y-m-d H:i:s', $lastRefresh),
                    'ttl' => $ttl,
                    'seconds_remaining' => $ttl - (time() - $lastRefresh)
                ]);
                return [
                    'success' => true,
                    'count' => count(self::$localCache['plugins'] ?? []),
                    'errors' => [],
                    'skipped' => true,
                    'reason' => 'TTL not expired'
                ];
            }
        }

        $logger->info('PluginRegistryCache: Starting refresh', ['force' => $force]);

        // Get configured sources
        $sources = self::getConfiguredSources();

        if (empty($sources)) {
            $logger->warning('PluginRegistryCache: No plugin sources configured');
            return [
                'success' => false,
                'count' => 0,
                'errors' => ['No plugin sources configured']
            ];
        }

        $plugins = [];
        $errors = [];
        $existingPlugins = self::$localCache['plugins'] ?? [];

        // Scan each source
        foreach ($sources as $source) {
            try {
                $sourcePlugins = self::scanSource($source);
                $plugins = array_merge($plugins, $sourcePlugins);
                $logger->debug('PluginRegistryCache: Scanned source', [
                    'source' => $source,
                    'plugins_found' => count($sourcePlugins)
                ]);
            } catch (\Exception $e) {
                // On source error: preserve existing cached data, log error with timestamp
                $errorEntry = [
                    'source' => $source,
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'unix_timestamp' => time()
                ];
                $errors[] = $errorEntry;

                $logger->error('PluginRegistryCache: Source scan failed', [
                    'source' => $source,
                    'error' => $e->getMessage()
                ]);

                // Preserve existing plugins from this source if we have them
                foreach ($existingPlugins as $existingPlugin) {
                    if (isset($existingPlugin['source']) && $existingPlugin['source'] === $source) {
                        $plugins[] = $existingPlugin;
                    }
                }
            }
        }

        // Store errors
        self::storeErrors($errors);

        // Update cache with new plugins
        self::$localCache = [
            'plugins' => $plugins,
            'last_refresh' => time(),
            'refresh_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];

        // Store in APCu if available
        if (self::hasAPCu()) {
            apcu_store(self::getCacheKey(), self::$localCache, self::getTTL());
            $logger->info('PluginRegistryCache: Stored in APCu');
        }

        // Also store in file cache for persistence across APCu restarts
        self::storeFileCache();

        // Update version to invalidate old caches
        $versionFile = self::getCacheVersionFile();
        file_put_contents($versionFile, time());

        // Reset cache keys so they use new version
        self::$CACHE_KEY = null;
        self::$STATS_KEY = null;
        self::$ERRORS_KEY = null;

        self::incrementStat('refreshes');

        $logger->info('PluginRegistryCache: Refresh complete', [
            'plugins_count' => count($plugins),
            'errors_count' => count($errors),
            'duration_ms' => self::$localCache['refresh_duration_ms']
        ]);

        return [
            'success' => count($errors) === 0,
            'count' => count($plugins),
            'errors' => $errors,
            'duration_ms' => self::$localCache['refresh_duration_ms']
        ];
    }

    /**
     * Invalidate (clear) the cache
     */
    public static function invalidate() {
        $logger = self::getLogger();

        // Clear local cache
        self::$localCache = null;

        // Clear APCu stats counters before changing version
        if (self::hasAPCu()) {
            $statsKey = self::getStatsKey();
            $errorsKey = self::getErrorsKey();
            apcu_delete(self::getCacheKey());
            apcu_delete($statsKey . '_refreshes');
            apcu_delete($statsKey . '_apcu_hits');
            apcu_delete($errorsKey);
        }

        // Increment version file to invalidate all APCu caches across all processes
        $versionFile = self::getCacheVersionFile();
        $newVersion = time();
        file_put_contents($versionFile, $newVersion);

        // Clear file cache
        $cacheFile = self::getFileCachePath();
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        // Clear errors file
        $errorFile = self::getCacheDir() . '/.plugin_errors_' . self::getWorkspaceSlug() . '.json';
        if (file_exists($errorFile)) {
            @unlink($errorFile);
        }

        // Reset cache keys so they use new version
        self::$CACHE_KEY = null;
        self::$STATS_KEY = null;
        self::$ERRORS_KEY = null;
        self::$CACHE_VERSION_FILE = null;

        $logger->info('PluginRegistryCache: Cache invalidated (version: ' . $newVersion . ')');
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics array
     */
    public static function getStats() {
        $apcu_hits = 0;
        $refreshes = 0;

        // Get APCu counters if available
        if (self::hasAPCu()) {
            $apcu_hits = apcu_fetch(self::getStatsKey() . '_apcu_hits') ?: 0;
            $refreshes = apcu_fetch(self::getStatsKey() . '_refreshes') ?: 0;
        }

        self::ensureLoaded();

        $stats = [
            // Basic cache status
            'apcu_available' => self::hasAPCu(),
            'cache_loaded' => self::$localCache !== null,
            'in_apcu' => false,

            // Plugin counts and memory
            'count' => count(self::$localCache['plugins'] ?? []),
            'memory' => self::$localCache ? strlen(serialize(self::$localCache)) : 0,

            // Cache performance metrics
            'apcu_hits' => $apcu_hits,
            'refreshes' => $refreshes,

            // Timing info
            'last_refresh' => self::$localCache['last_refresh'] ?? null,
            'last_refresh_formatted' => isset(self::$localCache['last_refresh'])
                ? date('Y-m-d H:i:s', self::$localCache['last_refresh'])
                : 'Never',
            'last_duration_ms' => self::$localCache['refresh_duration_ms'] ?? null,

            // TTL info
            'ttl' => self::getTTL(),
            'ttl_remaining' => null,

            // Cache version
            'cache_version' => self::getCacheVersion(),

            // Error count
            'error_count' => count(self::getRefreshErrors())
        ];

        // Calculate remaining TTL
        if (isset(self::$localCache['last_refresh'])) {
            $elapsed = time() - self::$localCache['last_refresh'];
            $remaining = self::getTTL() - $elapsed;
            $stats['ttl_remaining'] = max(0, $remaining);
        }

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
                self::getLogger()->debug('PluginRegistryCache: Loaded from APCu');
                self::incrementStat('apcu_hits');
                return;
            }
        }

        // Try to load from file cache
        self::loadFromFileCache();

        // If still empty, initialize with empty structure
        if (self::$localCache === null) {
            self::$localCache = [
                'plugins' => [],
                'last_refresh' => null,
                'refresh_duration_ms' => null
            ];
        }

        // Store in APCu if available and we have data
        if (self::hasAPCu() && !empty(self::$localCache['plugins'])) {
            apcu_store(self::getCacheKey(), self::$localCache, self::getTTL());
            self::getLogger()->info('PluginRegistryCache: Stored in APCu');
        }
    }

    /**
     * Get file cache path
     */
    private static function getFileCachePath() {
        return self::getCacheDir() . '/plugin_registry_' . self::getWorkspaceSlug() . '.cache';
    }

    /**
     * Load cache from file
     */
    private static function loadFromFileCache() {
        $cacheFile = self::getFileCachePath();
        if (file_exists($cacheFile)) {
            $content = file_get_contents($cacheFile);
            $data = @unserialize($content);
            if ($data !== false && is_array($data)) {
                self::$localCache = $data;
                self::getLogger()->debug('PluginRegistryCache: Loaded from file cache');
                return;
            }
        }
    }

    /**
     * Store cache to file
     */
    private static function storeFileCache() {
        $cacheFile = self::getFileCachePath();
        $content = serialize(self::$localCache);
        file_put_contents($cacheFile, $content);
    }

    /**
     * Store errors to persistent storage
     */
    private static function storeErrors($errors) {
        // Store in APCu if available
        if (self::hasAPCu()) {
            apcu_store(self::getErrorsKey(), $errors, self::getTTL());
        }

        // Also store in file for persistence
        $errorFile = self::getCacheDir() . '/.plugin_errors_' . self::getWorkspaceSlug() . '.json';
        file_put_contents($errorFile, json_encode($errors, JSON_PRETTY_PRINT));
    }

    /**
     * Get configured plugin sources
     *
     * @return array Array of source paths/URLs
     */
    private static function getConfiguredSources() {
        try {
            $sourcesConfig = Flight::get('plugin_registry.sources');
            if (empty($sourcesConfig)) {
                return [];
            }

            // Parse comma-separated list
            $sources = array_map('trim', explode(',', $sourcesConfig));
            return array_filter($sources); // Remove empty entries
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Scan a single source for plugins
     *
     * @param string $source Source path or URL
     * @return array Array of plugin metadata
     * @throws \Exception If source cannot be scanned
     */
    private static function scanSource($source) {
        $plugins = [];

        // Determine if source is a local directory or remote URL
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            // Remote source - fetch plugin manifest
            $plugins = self::scanRemoteSource($source);
        } else {
            // Local directory
            $plugins = self::scanLocalSource($source);
        }

        // Add source reference to each plugin
        foreach ($plugins as &$plugin) {
            $plugin['source'] = $source;
        }

        return $plugins;
    }

    /**
     * Scan a local directory for plugins
     *
     * @param string $path Directory path
     * @return array Array of plugin metadata
     */
    private static function scanLocalSource($path) {
        $plugins = [];

        // Handle relative paths
        if (!str_starts_with($path, '/')) {
            $path = dirname(__DIR__) . '/' . $path;
        }

        if (!is_dir($path)) {
            throw new \Exception("Plugin source directory not found: {$path}");
        }

        // Look for plugin manifest files (plugin.json or manifest.json)
        $iterator = new \DirectoryIterator($path);
        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $pluginDir = $item->getPathname();
            $manifestFile = null;

            // Check for manifest files
            foreach (['plugin.json', 'manifest.json'] as $manifestName) {
                $candidatePath = $pluginDir . '/' . $manifestName;
                if (file_exists($candidatePath)) {
                    $manifestFile = $candidatePath;
                    break;
                }
            }

            if ($manifestFile) {
                $content = file_get_contents($manifestFile);
                $manifest = json_decode($content, true);

                if ($manifest && isset($manifest['name'])) {
                    $plugins[] = [
                        'id' => $manifest['id'] ?? $item->getFilename(),
                        'name' => $manifest['name'],
                        'version' => $manifest['version'] ?? '1.0.0',
                        'description' => $manifest['description'] ?? '',
                        'author' => $manifest['author'] ?? 'Unknown',
                        'path' => $pluginDir,
                        'manifest' => $manifest,
                        'scanned_at' => time()
                    ];
                }
            }
        }

        return $plugins;
    }

    /**
     * Scan a remote source for plugins
     *
     * @param string $url Remote URL (e.g., GitHub API endpoint)
     * @return array Array of plugin metadata
     */
    private static function scanRemoteSource($url) {
        $plugins = [];

        // Set up HTTP context with timeout and user agent
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'MyCTOBot-PluginRegistry/1.0',
                'header' => "Accept: application/json\r\n"
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new \Exception("Failed to fetch remote source: " . ($error['message'] ?? 'Unknown error'));
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new \Exception("Invalid JSON response from remote source");
        }

        // Handle different response formats
        if (isset($data['plugins']) && is_array($data['plugins'])) {
            // Standard plugin registry format
            foreach ($data['plugins'] as $plugin) {
                if (isset($plugin['name'])) {
                    $plugins[] = [
                        'id' => $plugin['id'] ?? md5($plugin['name']),
                        'name' => $plugin['name'],
                        'version' => $plugin['version'] ?? '1.0.0',
                        'description' => $plugin['description'] ?? '',
                        'author' => $plugin['author'] ?? 'Unknown',
                        'url' => $plugin['url'] ?? null,
                        'manifest' => $plugin,
                        'scanned_at' => time()
                    ];
                }
            }
        } elseif (isset($data['name'])) {
            // Single plugin manifest
            $plugins[] = [
                'id' => $data['id'] ?? md5($data['name']),
                'name' => $data['name'],
                'version' => $data['version'] ?? '1.0.0',
                'description' => $data['description'] ?? '',
                'author' => $data['author'] ?? 'Unknown',
                'url' => $url,
                'manifest' => $data,
                'scanned_at' => time()
            ];
        }

        return $plugins;
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
     * Increment statistics counter
     */
    private static function incrementStat($stat) {
        if (self::hasAPCu()) {
            $key = self::getStatsKey() . '_' . $stat;
            apcu_inc($key, 1, $success, self::getTTL());
        }
    }

    /**
     * Get logger instance
     */
    private static function getLogger() {
        try {
            return Flight::get('log');
        } catch (\Exception $e) {
            // Return a null logger if Flight not initialized (CLI mode)
            return new class {
                public function debug($msg, $ctx = []) {}
                public function info($msg, $ctx = []) {}
                public function warning($msg, $ctx = []) {}
                public function error($msg, $ctx = []) {}
            };
        }
    }

    /**
     * Warm up the cache (useful for deployment/startup)
     *
     * @return array Cache statistics after warmup
     */
    public static function warmup() {
        self::invalidate();
        self::refresh(true);
        return self::getStats();
    }

    /**
     * Reload cache from sources
     *
     * @return array Cache data after reload
     */
    public static function reload() {
        self::invalidate();
        self::refresh(true);
        return self::$localCache;
    }
}
