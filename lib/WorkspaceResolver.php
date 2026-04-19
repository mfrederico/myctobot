<?php
/**
 * WorkspaceResolver - Helper for multi-workspace routing
 *
 * Supports two modes:
 * 1. Session-based tenancy (primary): User logs in with workspace code, stored in session
 * 2. Subdomain-based tenancy: gwt.myctobot.ai → conf/config.gwt.ini
 *
 * Session-based flow:
 *   myctobot.ai/login/gwt → login with workspace "gwt" → session stores workspace
 *   All subsequent requests check session for workspace
 *
 * Config resolution:
 *   Session workspace "gwt" → conf/config.gwt.ini
 *   No session workspace   → conf/config.ini (default)
 */

namespace app;

use \Flight as Flight;

class WorkspaceResolver {

    private static $sessionWorkspace = null;
    private static $initialized = false;

    /**
     * Get workspace slug from session or subdomain
     *
     * @return string Workspace slug (e.g., 'gwt', 'acme', 'default')
     */
    public static function getSlug(): string {
        // Check session first (session-based tenancy)
        if (isset($_SESSION['workspace_slug']) && !empty($_SESSION['workspace_slug'])) {
            return $_SESSION['workspace_slug'];
        }

        // Check subdomain
        return self::getSubdomain() ?? 'default';
    }

    /**
     * Set workspace in session
     *
     * @param string $slug Workspace slug (e.g., 'gwt')
     * @return bool True if workspace config exists and was set
     */
    public static function setWorkspace(string $slug): bool {
        $slug = strtolower(trim($slug));

        if (empty($slug) || $slug === 'default') {
            self::clearworkspace();
            return true;
        }

        // Validate workspace config exists
        $configFile = "conf/config.{$slug}.ini";
        if (!file_exists($configFile)) {
            return false;
        }

        $_SESSION['workspace_slug'] = $slug;
        self::$sessionWorkspace = $slug;
        return true;
    }

    /**
     * Clear workspace from session (logout or switch to default)
     */
    public static function clearworkspace(): void {
        unset($_SESSION['workspace_slug']);
        self::$sessionWorkspace = null;
    }

    /**
     * Get workspace slug from session only (not subdomain)
     *
     * @return string|null Workspace slug or null if not in session
     */
    public static function getSessionworkspace(): ?string {
        return $_SESSION['workspace_slug'] ?? null;
    }

    /**
     * Check if a workspace config exists
     *
     * @param string $slug Workspace slug to check
     * @return bool True if config file exists
     */
    public static function workspaceExists(string $slug): bool {
        if (empty($slug) || $slug === 'default') {
            return true;
        }
        return file_exists("conf/config.{$slug}.ini");
    }

    /**
     * Get the config file path for a workspace
     *
     * @param string|null $slug Workspace slug (null for current workspace)
     * @return string Config file path
     */
    public static function getConfigFile(?string $slug = null): string {
        $slug = $slug ?? self::getSlug();

        if (empty($slug) || $slug === 'default') {
            return 'conf/config.ini';
        }

        $configFile = "conf/config.{$slug}.ini";
        return file_exists($configFile) ? $configFile : 'conf/config.ini';
    }

    /**
     * Extract subdomain from current HTTP host
     *
     * Examples:
     *   gwt.myctobot.ai → gwt
     *   acme.myctobot.ai → acme
     *   myctobot.ai → null
     *   localhost → null
     *
     * @return string|null Subdomain or null
     */
    public static function getSubdomain(): ?string {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Remove port if present
        $host = explode(':', $host)[0];
        $host = strtolower($host);

        // Skip localhost
        if ($host === 'localhost' || strpos($host, 'localhost') === 0) {
            return null;
        }

        // Skip IP addresses
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $host);

        // Need at least 3 parts for subdomain (sub.domain.tld)
        if (count($parts) >= 3) {
            return $parts[0];
        }

        return null;
    }

    /**
     * Check if current request is for a specific workspace
     *
     * @param string $slug Workspace slug to check
     * @return bool True if current workspace matches
     */
    public static function isworkspace(string $slug): bool {
        return self::getSlug() === strtolower($slug);
    }

    /**
     * Check if current request is for the default (public) workspace
     *
     * @return bool True if default/public workspace (no session workspace, no subdomain)
     */
    public static function isDefault(): bool {
        // Check session workspace first
        if (isset($_SESSION['workspace_slug']) && !empty($_SESSION['workspace_slug'])) {
            return false;
        }

        // Check subdomain
        $subdomain = self::getSubdomain();
        return $subdomain === null;
    }

    /**
     * Get the current workspace's base URL
     *
     * @return string The base URL from config
     */
    public static function getBaseUrl(): string {
        return Flight::get('app.baseurl') ?? 'http://localhost';
    }

    /**
     * Get workspace info array
     *
     * @return array Workspace info
     */
    public static function getWorkspace(): array {
        return [
            'slug' => self::getSlug(),
            'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'baseurl' => self::getBaseUrl(),
            'is_default' => self::isDefault(),
            'from_session' => isset($_SESSION['workspace_slug'])
        ];
    }

    /**
     * Switch to workspace database and load config
     *
     * Loads workspace config file, sets Flight config values, and switches
     * Bean database connection. Use this for webhooks, API calls, and
     * background scripts that need to operate in a workspace context.
     *
     * @param string $slug Workspace slug (e.g., 'gwt')
     * @return bool True if switch successful
     */
    public static function switchDatabase(string $slug): bool {
        $logger = Flight::get('log');
        $slug = strtolower(trim($slug));

        // Load workspace config
        $configFile = __DIR__ . "/../conf/config.{$slug}.ini";
        if (!file_exists($configFile)) {
            if ($logger) {
                $logger->warning("WorkspaceResolver: Config not found", ['workspace' => $slug, 'file' => $configFile]);
            }
            return false;
        }

        $workspaceConfig = parse_ini_file($configFile, true);
        if (!$workspaceConfig || empty($workspaceConfig['database'])) {
            if ($logger) {
                $logger->warning("WorkspaceResolver: Invalid config", ['workspace' => $slug]);
            }
            return false;
        }

        // Override Flight config values
        foreach ($workspaceConfig as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    Flight::set("{$section}.{$key}", $value);
                }
            }
        }

        // Set PHP timezone from workspace config (important for consistent time handling)
        $timezone = $workspaceConfig['app']['timezone'] ?? $workspaceConfig['timezone'] ?? null;
        if ($timezone) {
            date_default_timezone_set($timezone);
        }

        // Switch Bean database connection
        try {
            $dbConfig = $workspaceConfig['database'];
            $type = $dbConfig['type'] ?? 'mysql';

            if ($type === 'sqlite') {
                $dbPath = $dbConfig['path'] ?? "database/{$slug}.sqlite";
                $dsn = "sqlite:{$dbPath}";
                \app\Bean::useDatabase($slug, $dsn);
            } else {
                $host = $dbConfig['host'] ?? 'localhost';
                $port = $dbConfig['port'] ?? 3306;
                $name = $dbConfig['name'] ?? $slug;
                $user = $dbConfig['user'] ?? 'root';
                $pass = $dbConfig['pass'] ?? '';
                $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
                \app\Bean::useDatabase($slug, $dsn, $user, $pass);
            }

            Flight::set('workspace.slug', $slug);
            Flight::set('workspace.active', true);
            $_SESSION['workspace_slug'] = $slug;

            if ($logger) {
                $logger->debug("WorkspaceResolver: Switched to workspace", ['workspace' => $slug]);
            }

            return true;
        } catch (\Exception $e) {
            if ($logger) {
                $logger->error("WorkspaceResolver: Database switch failed", [
                    'workspace' => $slug,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
}
