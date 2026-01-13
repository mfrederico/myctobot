<?php
/**
 * Plugin Version Service
 * Manages plugin registration, version tracking, and update detection.
 *
 * Uses GitHub Releases API to check for new versions of plugins.
 *
 * @see GitHub Issue #104
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\lib\SemVer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PluginVersionService {

    /**
     * GitHub API base URL
     */
    private const GITHUB_API_BASE = 'https://api.github.com';

    /**
     * Cache duration for update checks (in seconds)
     */
    private const UPDATE_CHECK_CACHE_HOURS = 24;

    /**
     * Register a new plugin in the registry
     *
     * @param string $name Human-readable plugin name
     * @param string $version Current installed version
     * @param string|null $repositoryUrl GitHub repository URL for update checks
     * @param array $metadata Additional plugin metadata (slug, description, author, etc.)
     * @return \RedBeanPHP\OODBBean The created/updated plugin registry bean
     */
    public static function registerPlugin(
        string $name,
        string $version,
        ?string $repositoryUrl = null,
        array $metadata = []
    ): \RedBeanPHP\OODBBean {
        // Generate slug from name if not provided
        $slug = $metadata['slug'] ?? self::generateSlug($name);

        // Check if plugin already exists
        $plugin = R::findOne('pluginregistry', 'slug = ?', [$slug]);

        if (!$plugin) {
            $plugin = R::dispense('pluginregistry');
            $plugin->installed_at = date('Y-m-d H:i:s');
        }

        // Set/update fields
        $plugin->name = $name;
        $plugin->slug = $slug;
        $plugin->installed_version = SemVer::normalize($version) ?? $version;
        $plugin->repository_url = $repositoryUrl;
        $plugin->description = $metadata['description'] ?? $plugin->description;
        $plugin->author = $metadata['author'] ?? $plugin->author;
        $plugin->homepage_url = $metadata['homepage'] ?? $plugin->homepage_url;
        $plugin->plugin_path = $metadata['plugin_path'] ?? $plugin->plugin_path;
        $plugin->requires_php = $metadata['requires_php'] ?? $plugin->requires_php;
        $plugin->status = $metadata['status'] ?? $plugin->status ?? 'active';

        R::store($plugin);

        self::log('Plugin registered', [
            'name' => $name,
            'version' => $version,
            'slug' => $slug,
        ]);

        return $plugin;
    }

    /**
     * Scan for updates for one or all plugins
     *
     * @param string|null $pluginSlug Specific plugin slug, or null for all plugins
     * @param bool $force Force check even if recently checked
     * @return array Results with plugin slugs as keys
     */
    public static function scanForUpdates(?string $pluginSlug = null, bool $force = false): array {
        $results = [];

        if ($pluginSlug !== null) {
            $plugins = R::find('pluginregistry', 'slug = ? AND status != ?', [$pluginSlug, 'disabled']);
        } else {
            $plugins = R::find('pluginregistry', 'status != ?', ['disabled']);
        }

        foreach ($plugins as $plugin) {
            // Skip if recently checked (unless forced)
            if (!$force && !$plugin->needsUpdateCheck(self::UPDATE_CHECK_CACHE_HOURS)) {
                $results[$plugin->slug] = [
                    'skipped' => true,
                    'reason' => 'Recently checked',
                    'last_checked' => $plugin->last_checked_at,
                ];
                continue;
            }

            try {
                $updateInfo = self::checkPluginForUpdates($plugin);
                $results[$plugin->slug] = $updateInfo;
            } catch (\Exception $e) {
                $results[$plugin->slug] = [
                    'error' => true,
                    'message' => $e->getMessage(),
                ];
                self::log('Update check failed', [
                    'plugin' => $plugin->slug,
                    'error' => $e->getMessage(),
                ], 'error');
            }
        }

        return $results;
    }

    /**
     * Check a specific plugin for updates
     *
     * @param \RedBeanPHP\OODBBean $plugin Plugin registry bean
     * @return array Update information
     */
    private static function checkPluginForUpdates(\RedBeanPHP\OODBBean $plugin): array {
        if (empty($plugin->repository_url)) {
            $plugin->last_checked_at = date('Y-m-d H:i:s');
            R::store($plugin);

            return [
                'update_available' => false,
                'reason' => 'No repository URL configured',
            ];
        }

        // Parse GitHub repository URL
        $repoInfo = self::parseGitHubUrl($plugin->repository_url);
        if (!$repoInfo) {
            return [
                'update_available' => false,
                'reason' => 'Invalid or unsupported repository URL',
            ];
        }

        // Fetch releases from GitHub
        $releases = self::fetchGitHubReleases($repoInfo['owner'], $repoInfo['repo']);

        if (empty($releases)) {
            $plugin->markUpToDate();
            R::store($plugin);

            return [
                'update_available' => false,
                'reason' => 'No releases found',
            ];
        }

        // Find the latest stable release
        $latestRelease = null;
        $latestVersion = null;

        foreach ($releases as $release) {
            // Skip pre-releases unless no stable found
            if ($release['prerelease'] && $latestRelease !== null) {
                continue;
            }

            $version = ltrim($release['tag_name'], 'v');

            if ($latestVersion === null || SemVer::compare($version, $latestVersion) > 0) {
                $latestVersion = $version;
                $latestRelease = $release;
            }
        }

        if (!$latestVersion) {
            $plugin->markUpToDate();
            R::store($plugin);

            return [
                'update_available' => false,
                'reason' => 'No valid version found',
            ];
        }

        // Record all versions in history
        foreach ($releases as $release) {
            $version = ltrim($release['tag_name'], 'v');
            self::recordVersion(
                $plugin->slug,
                $version,
                $release['body'] ?? null,
                $release['published_at'] ?? null,
                $release['tarball_url'] ?? null,
                $release['prerelease'] ?? false
            );
        }

        // Compare with installed version
        $updateType = SemVer::getUpdateType($plugin->installed_version, $latestVersion);

        if ($updateType !== null) {
            $plugin->markUpdateAvailable($latestVersion, $updateType);
            R::store($plugin);

            self::log('Update available', [
                'plugin' => $plugin->slug,
                'current' => $plugin->installed_version,
                'latest' => $latestVersion,
                'type' => $updateType,
            ]);

            return [
                'update_available' => true,
                'current_version' => $plugin->installed_version,
                'latest_version' => $latestVersion,
                'update_type' => $updateType,
                'release_notes' => $latestRelease['body'] ?? null,
                'download_url' => $latestRelease['tarball_url'] ?? null,
            ];
        }

        $plugin->markUpToDate();
        R::store($plugin);

        return [
            'update_available' => false,
            'current_version' => $plugin->installed_version,
            'latest_version' => $latestVersion,
        ];
    }

    /**
     * Get all installed plugins
     *
     * @param string|null $status Filter by status (null for all)
     * @return array Array of plugin registry beans
     */
    public static function getInstalledPlugins(?string $status = null): array {
        if ($status !== null) {
            return R::find('pluginregistry', 'status = ? ORDER BY name ASC', [$status]);
        }

        return R::find('pluginregistry', ' ORDER BY name ASC');
    }

    /**
     * Get plugins that have available updates
     *
     * @param string|null $updateType Filter by update type ('major', 'minor', 'patch')
     * @return array Array of plugin registry beans with updates
     */
    public static function getPluginsWithUpdates(?string $updateType = null): array {
        if ($updateType !== null) {
            return R::find(
                'pluginregistry',
                'update_available = ? AND update_type = ? ORDER BY name ASC',
                [true, $updateType]
            );
        }

        return R::find('pluginregistry', 'update_available = ? ORDER BY name ASC', [true]);
    }

    /**
     * Record a version in the version history
     *
     * @param string $pluginSlug Plugin slug
     * @param string $version Version number
     * @param string|null $releaseNotes Release notes
     * @param string|null $releasedAt Release date (ISO 8601)
     * @param string|null $downloadUrl Download URL
     * @param bool $isPrerelease Is this a pre-release?
     * @return \RedBeanPHP\OODBBean|null The version bean or null if plugin not found
     */
    public static function recordVersion(
        string $pluginSlug,
        string $version,
        ?string $releaseNotes = null,
        ?string $releasedAt = null,
        ?string $downloadUrl = null,
        bool $isPrerelease = false
    ): ?\RedBeanPHP\OODBBean {
        $plugin = R::findOne('pluginregistry', 'slug = ?', [$pluginSlug]);

        if (!$plugin) {
            return null;
        }

        $normalizedVersion = SemVer::normalize($version) ?? $version;

        // Check if this version already exists
        $existingVersion = R::findOne(
            'pluginversion',
            'pluginregistry_id = ? AND version = ?',
            [$plugin->id, $normalizedVersion]
        );

        if ($existingVersion) {
            // Update existing record if release notes changed
            if ($releaseNotes !== null && $existingVersion->release_notes !== $releaseNotes) {
                $existingVersion->release_notes = $releaseNotes;
                R::store($existingVersion);
            }
            return $existingVersion;
        }

        // Create new version record
        $versionBean = R::dispense('pluginversion');
        $versionBean->pluginregistry_id = $plugin->id;
        $versionBean->version = $normalizedVersion;
        $versionBean->release_notes = $releaseNotes;
        $versionBean->download_url = $downloadUrl;
        $versionBean->is_prerelease = $isPrerelease ? 1 : 0;
        $versionBean->released_at = $releasedAt ? date('Y-m-d H:i:s', strtotime($releasedAt)) : null;

        R::store($versionBean);

        return $versionBean;
    }

    /**
     * Compare two version strings
     *
     * @param string $v1 First version
     * @param string $v2 Second version
     * @return int -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    public static function compareVersions(string $v1, string $v2): int {
        return SemVer::compare($v1, $v2);
    }

    /**
     * Get the type of update between two versions
     *
     * @param string $currentVersion Current version
     * @param string $newVersion New version
     * @return string|null 'major', 'minor', 'patch', or null
     */
    public static function getUpdateType(string $currentVersion, string $newVersion): ?string {
        return SemVer::getUpdateType($currentVersion, $newVersion);
    }

    /**
     * Get plugin by slug
     *
     * @param string $slug Plugin slug
     * @return \RedBeanPHP\OODBBean|null Plugin bean or null
     */
    public static function getPlugin(string $slug): ?\RedBeanPHP\OODBBean {
        return R::findOne('pluginregistry', 'slug = ?', [$slug]);
    }

    /**
     * Get version history for a plugin
     *
     * @param string $pluginSlug Plugin slug
     * @param int|null $limit Maximum versions to return
     * @return array Array of version beans
     */
    public static function getVersionHistory(string $pluginSlug, ?int $limit = null): array {
        $plugin = R::findOne('pluginregistry', 'slug = ?', [$pluginSlug]);

        if (!$plugin) {
            return [];
        }

        $sql = 'pluginregistry_id = ? ORDER BY released_at DESC';
        $params = [$plugin->id];

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        return R::find('pluginversion', $sql, $params);
    }

    /**
     * Unregister a plugin
     *
     * @param string $pluginSlug Plugin slug
     * @return bool True if successfully removed
     */
    public static function unregisterPlugin(string $pluginSlug): bool {
        $plugin = R::findOne('pluginregistry', 'slug = ?', [$pluginSlug]);

        if (!$plugin) {
            return false;
        }

        // This will cascade delete version history due to FK constraint
        R::trash($plugin);

        self::log('Plugin unregistered', ['slug' => $pluginSlug]);

        return true;
    }

    /**
     * Get update statistics
     *
     * @return array Statistics about plugin updates
     */
    public static function getUpdateStats(): array {
        $total = R::count('pluginregistry', 'status != ?', ['disabled']);
        $withUpdates = R::count('pluginregistry', 'update_available = ? AND status != ?', [true, 'disabled']);
        $majorUpdates = R::count('pluginregistry', 'update_type = ? AND status != ?', ['major', 'disabled']);
        $minorUpdates = R::count('pluginregistry', 'update_type = ? AND status != ?', ['minor', 'disabled']);
        $patchUpdates = R::count('pluginregistry', 'update_type = ? AND status != ?', ['patch', 'disabled']);

        return [
            'total_plugins' => $total,
            'plugins_with_updates' => $withUpdates,
            'major_updates' => $majorUpdates,
            'minor_updates' => $minorUpdates,
            'patch_updates' => $patchUpdates,
            'up_to_date' => $total - $withUpdates,
        ];
    }

    /**
     * Parse a GitHub repository URL
     *
     * @param string $url GitHub URL
     * @return array|null Array with 'owner' and 'repo' keys, or null if invalid
     */
    private static function parseGitHubUrl(string $url): ?array {
        // Match various GitHub URL formats
        $patterns = [
            '/github\.com\/([^\/]+)\/([^\/]+?)(?:\.git)?(?:\/.*)?$/i',
            '/^([^\/]+)\/([^\/]+)$/', // owner/repo format
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return [
                    'owner' => $matches[1],
                    'repo' => rtrim($matches[2], '.git'),
                ];
            }
        }

        return null;
    }

    /**
     * Fetch releases from GitHub API
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @return array Array of release data
     */
    private static function fetchGitHubReleases(string $owner, string $repo): array {
        $url = self::GITHUB_API_BASE . "/repos/{$owner}/{$repo}/releases";

        try {
            $client = new Client([
                'timeout' => 10,
                'headers' => self::getGitHubHeaders(),
            ]);

            $response = $client->get($url);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : [];
        } catch (GuzzleException $e) {
            self::log('GitHub API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ], 'error');

            return [];
        }
    }

    /**
     * Get GitHub API headers
     *
     * @return array Headers for GitHub API requests
     */
    private static function getGitHubHeaders(): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'MyCTOBot-PluginVersionService',
        ];

        // Add auth token if available
        try {
            $token = Flight::get('github.access_token');
            if (!empty($token)) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        } catch (\Exception $e) {
            // No token configured, continue without auth
        }

        return $headers;
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
                $logger->$level('[PluginVersionService] ' . $message, $context);
            }
        } catch (\Exception $e) {
            // Logger not available, silently ignore
        }
    }
}
