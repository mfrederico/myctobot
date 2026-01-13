<?php
/**
 * Plugin Manager
 * Handles plugin discovery, manifest loading, and plugin lifecycle management.
 *
 * Scans the plugins directory for installed plugins, reads their manifests,
 * and coordinates with PluginVersionService for version tracking.
 *
 * @see GitHub Issue #104
 */

namespace app\lib;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\services\PluginVersionService;

class PluginManager {

    /**
     * Default plugin directory relative to application root
     */
    private const DEFAULT_PLUGIN_DIR = 'lib/plugins';

    /**
     * Plugin manifest filename
     */
    private const MANIFEST_FILE = 'plugin.json';

    /**
     * Base path for the application
     */
    private static ?string $basePath = null;

    /**
     * Cached plugin manifests
     */
    private static array $manifestCache = [];

    /**
     * Set the base path for plugin discovery
     *
     * @param string $path Base path
     */
    public static function setBasePath(string $path): void {
        self::$basePath = rtrim($path, '/');
        self::$manifestCache = [];
    }

    /**
     * Get the base path
     *
     * @return string Base path
     */
    public static function getBasePath(): string {
        if (self::$basePath === null) {
            // Try to determine from Flight config or use dirname of lib
            try {
                self::$basePath = Flight::get('app.base_path') ?? dirname(__DIR__);
            } catch (\Exception $e) {
                self::$basePath = dirname(__DIR__);
            }
        }

        return self::$basePath;
    }

    /**
     * Get the plugins directory path
     *
     * @return string Full path to plugins directory
     */
    public static function getPluginDirectory(): string {
        return self::getBasePath() . '/' . self::DEFAULT_PLUGIN_DIR;
    }

    /**
     * Scan the plugin directory for all plugins
     *
     * Looks for:
     * 1. Subdirectories with plugin.json manifest files
     * 2. Standalone PHP files with plugin metadata in docblock (legacy format)
     *
     * @return array Array of discovered plugin paths
     */
    public static function scanPluginDirectory(): array {
        $pluginDir = self::getPluginDirectory();
        $discoveredPlugins = [];

        if (!is_dir($pluginDir)) {
            self::log('Plugin directory not found', ['path' => $pluginDir], 'warning');
            return [];
        }

        $entries = scandir($pluginDir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $pluginDir . '/' . $entry;

            // Check for directory with plugin.json
            if (is_dir($entryPath)) {
                $manifestPath = $entryPath . '/' . self::MANIFEST_FILE;
                if (file_exists($manifestPath)) {
                    $discoveredPlugins[] = [
                        'type' => 'directory',
                        'path' => $entryPath,
                        'manifest_path' => $manifestPath,
                        'name' => $entry,
                    ];
                }
            }
            // Check for standalone PHP file (legacy single-file plugins)
            elseif (is_file($entryPath) && pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
                // Check for companion plugin.json
                $legacyManifest = $pluginDir . '/' . pathinfo($entry, PATHINFO_FILENAME) . '.json';
                if (file_exists($legacyManifest)) {
                    $discoveredPlugins[] = [
                        'type' => 'file',
                        'path' => $entryPath,
                        'manifest_path' => $legacyManifest,
                        'name' => pathinfo($entry, PATHINFO_FILENAME),
                    ];
                } else {
                    // No manifest, treat as legacy plugin
                    $discoveredPlugins[] = [
                        'type' => 'legacy',
                        'path' => $entryPath,
                        'manifest_path' => null,
                        'name' => pathinfo($entry, PATHINFO_FILENAME),
                    ];
                }
            }
        }

        self::log('Plugin directory scanned', [
            'path' => $pluginDir,
            'found' => count($discoveredPlugins),
        ]);

        return $discoveredPlugins;
    }

    /**
     * Load and parse a plugin manifest file
     *
     * @param string $pluginPath Path to plugin directory or manifest file
     * @return array|null Manifest data or null if invalid
     */
    public static function loadPluginManifest(string $pluginPath): ?array {
        // Determine manifest path
        if (is_dir($pluginPath)) {
            $manifestPath = $pluginPath . '/' . self::MANIFEST_FILE;
        } elseif (pathinfo($pluginPath, PATHINFO_EXTENSION) === 'json') {
            $manifestPath = $pluginPath;
        } elseif (pathinfo($pluginPath, PATHINFO_EXTENSION) === 'php') {
            // Check for companion .json file
            $manifestPath = dirname($pluginPath) . '/' . pathinfo($pluginPath, PATHINFO_FILENAME) . '.json';
        } else {
            return null;
        }

        // Check cache
        if (isset(self::$manifestCache[$manifestPath])) {
            return self::$manifestCache[$manifestPath];
        }

        if (!file_exists($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        $manifest = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log('Invalid plugin manifest JSON', [
                'path' => $manifestPath,
                'error' => json_last_error_msg(),
            ], 'error');
            return null;
        }

        // Validate required fields
        $required = ['name', 'version'];
        foreach ($required as $field) {
            if (empty($manifest[$field])) {
                self::log('Plugin manifest missing required field', [
                    'path' => $manifestPath,
                    'field' => $field,
                ], 'warning');
                return null;
            }
        }

        // Add computed fields
        $manifest['manifest_path'] = $manifestPath;
        $manifest['plugin_path'] = is_dir($pluginPath) ? $pluginPath : dirname($pluginPath);

        // Generate slug if not provided
        if (empty($manifest['slug'])) {
            $manifest['slug'] = self::generateSlug($manifest['name']);
        }

        // Cache and return
        self::$manifestCache[$manifestPath] = $manifest;

        return $manifest;
    }

    /**
     * Register all discovered plugins with the version service
     *
     * @param bool $skipExisting Skip plugins that are already registered
     * @return array Results of registration for each plugin
     */
    public static function registerAllPlugins(bool $skipExisting = true): array {
        $discovered = self::scanPluginDirectory();
        $results = [];

        foreach ($discovered as $pluginInfo) {
            $slug = self::generateSlug($pluginInfo['name']);

            // Skip if already registered
            if ($skipExisting) {
                $existing = PluginVersionService::getPlugin($slug);
                if ($existing) {
                    $results[$slug] = [
                        'status' => 'skipped',
                        'reason' => 'Already registered',
                        'current_version' => $existing->installed_version,
                    ];
                    continue;
                }
            }

            // Load manifest if available
            $manifest = null;
            if ($pluginInfo['manifest_path']) {
                $manifest = self::loadPluginManifest($pluginInfo['manifest_path']);
            }

            if ($manifest) {
                // Register with manifest data
                try {
                    $plugin = PluginVersionService::registerPlugin(
                        $manifest['name'],
                        $manifest['version'],
                        $manifest['repository'] ?? null,
                        [
                            'slug' => $manifest['slug'] ?? null,
                            'description' => $manifest['description'] ?? null,
                            'author' => $manifest['author'] ?? null,
                            'homepage' => $manifest['homepage'] ?? null,
                            'plugin_path' => $pluginInfo['path'],
                            'requires_php' => $manifest['requires']['php'] ?? null,
                        ]
                    );

                    $results[$plugin->slug] = [
                        'status' => 'registered',
                        'version' => $manifest['version'],
                        'has_manifest' => true,
                    ];
                } catch (\Exception $e) {
                    $results[$slug] = [
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            } elseif ($pluginInfo['type'] === 'legacy') {
                // Register legacy plugin with metadata from docblock
                $metadata = self::extractLegacyMetadata($pluginInfo['path']);

                try {
                    $plugin = PluginVersionService::registerPlugin(
                        $metadata['name'] ?? $pluginInfo['name'],
                        $metadata['version'] ?? '0.0.0',
                        $metadata['repository'] ?? null,
                        [
                            'slug' => $slug,
                            'description' => $metadata['description'] ?? null,
                            'author' => $metadata['author'] ?? null,
                            'plugin_path' => $pluginInfo['path'],
                        ]
                    );

                    $results[$plugin->slug] = [
                        'status' => 'registered',
                        'version' => $plugin->installed_version,
                        'has_manifest' => false,
                        'legacy' => true,
                    ];
                } catch (\Exception $e) {
                    $results[$slug] = [
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        self::log('Plugins registered', [
            'total' => count($results),
            'registered' => count(array_filter($results, fn($r) => $r['status'] === 'registered')),
            'skipped' => count(array_filter($results, fn($r) => $r['status'] === 'skipped')),
        ]);

        return $results;
    }

    /**
     * Check all registered plugins for updates
     *
     * @param bool $force Force check even if recently checked
     * @return array Update check results
     */
    public static function checkAllForUpdates(bool $force = false): array {
        return PluginVersionService::scanForUpdates(null, $force);
    }

    /**
     * Check a specific plugin for updates
     *
     * @param string $slug Plugin slug
     * @param bool $force Force check even if recently checked
     * @return array Update check result
     */
    public static function checkForUpdates(string $slug, bool $force = false): array {
        $results = PluginVersionService::scanForUpdates($slug, $force);
        return $results[$slug] ?? ['error' => true, 'message' => 'Plugin not found'];
    }

    /**
     * Get all plugins with their current status
     *
     * @return array Array of plugin info arrays
     */
    public static function getAllPlugins(): array {
        $plugins = PluginVersionService::getInstalledPlugins();
        $result = [];

        foreach ($plugins as $plugin) {
            $result[] = $plugin->toArray();
        }

        return $result;
    }

    /**
     * Get plugins that need updates
     *
     * @return array Array of plugin info arrays with updates
     */
    public static function getPluginsNeedingUpdates(): array {
        $plugins = PluginVersionService::getPluginsWithUpdates();
        $result = [];

        foreach ($plugins as $plugin) {
            $result[] = $plugin->toArray();
        }

        return $result;
    }

    /**
     * Create a plugin manifest template for a legacy plugin
     *
     * @param string $pluginPath Path to legacy plugin file
     * @return array Manifest template data
     */
    public static function createManifestTemplate(string $pluginPath): array {
        $metadata = self::extractLegacyMetadata($pluginPath);
        $name = pathinfo($pluginPath, PATHINFO_FILENAME);

        return [
            'name' => $metadata['name'] ?? $name,
            'slug' => self::generateSlug($metadata['name'] ?? $name),
            'version' => $metadata['version'] ?? '1.0.0',
            'description' => $metadata['description'] ?? 'Plugin description',
            'author' => $metadata['author'] ?? 'MyCTOBot',
            'repository' => $metadata['repository'] ?? '',
            'homepage' => '',
            'requires' => [
                'php' => '>=8.1',
            ],
        ];
    }

    /**
     * Write a manifest file for a plugin
     *
     * @param string $pluginPath Path to plugin (directory or file)
     * @param array $manifest Manifest data
     * @return bool True if successfully written
     */
    public static function writeManifest(string $pluginPath, array $manifest): bool {
        if (is_dir($pluginPath)) {
            $manifestPath = $pluginPath . '/' . self::MANIFEST_FILE;
        } else {
            // Create companion .json file for single-file plugins
            $manifestPath = dirname($pluginPath) . '/' . pathinfo($pluginPath, PATHINFO_FILENAME) . '.json';
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($manifestPath, $json) === false) {
            self::log('Failed to write manifest', ['path' => $manifestPath], 'error');
            return false;
        }

        // Clear cache
        unset(self::$manifestCache[$manifestPath]);

        self::log('Manifest written', ['path' => $manifestPath]);

        return true;
    }

    /**
     * Extract metadata from a legacy PHP plugin file docblock
     *
     * @param string $filePath Path to PHP file
     * @return array Extracted metadata
     */
    private static function extractLegacyMetadata(string $filePath): array {
        $metadata = [];

        if (!file_exists($filePath)) {
            return $metadata;
        }

        $content = file_get_contents($filePath);

        // Extract docblock
        if (preg_match('/\/\*\*(.+?)\*\//s', $content, $docblock)) {
            $docContent = $docblock[1];

            // Extract @tags
            $patterns = [
                'name' => '/@(?:package|plugin)\s+(.+?)$/m',
                'version' => '/@version\s+(.+?)$/m',
                'author' => '/@author\s+(.+?)$/m',
                'description' => '/^\s*\*\s+(?!@)(.+?)$/m',
            ];

            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $docContent, $match)) {
                    $metadata[$key] = trim($match[1]);
                }
            }
        }

        // Try to extract class name as fallback for name
        if (empty($metadata['name'])) {
            if (preg_match('/class\s+(\w+)/m', $content, $match)) {
                $metadata['name'] = $match[1];
            }
        }

        return $metadata;
    }

    /**
     * Generate a URL-friendly slug from a name
     *
     * @param string $name Plugin name
     * @return string Slug
     */
    private static function generateSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param array $context Additional context
     * @param string $level Log level
     */
    private static function log(string $message, array $context = [], string $level = 'info'): void {
        try {
            $logger = Flight::get('log');
            if ($logger) {
                $logger->$level('[PluginManager] ' . $message, $context);
            }
        } catch (\Exception $e) {
            // Logger not available, silently ignore
        }
    }

    /**
     * Clear the manifest cache
     */
    public static function clearCache(): void {
        self::$manifestCache = [];
    }
}
