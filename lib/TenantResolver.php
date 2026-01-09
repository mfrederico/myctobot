<?php
/**
 * TenantResolver - Helper for multi-tenant routing
 *
 * Supports two modes:
 * 1. Session-based tenancy (preferred): User logs in with workspace code, stored in session
 * 2. Subdomain-based tenancy (legacy): gwt.myctobot.ai → conf/config.gwt.ini
 *
 * Session-based flow:
 *   myctobot.ai/login/gwt → login with workspace "gwt" → session stores tenant
 *   All subsequent requests check session for tenant
 *
 * Config resolution:
 *   Session tenant "gwt" → conf/config.gwt.ini
 *   No session tenant   → conf/config.ini (default)
 */

namespace app;

use \Flight as Flight;

class TenantResolver {

    private static $sessionTenant = null;
    private static $initialized = false;

    /**
     * Get tenant slug from session (primary) or subdomain (fallback)
     *
     * @return string Tenant slug (e.g., 'gwt', 'acme', 'default')
     */
    public static function getSlug(): string {
        // Check session first (session-based tenancy)
        if (isset($_SESSION['tenant_slug']) && !empty($_SESSION['tenant_slug'])) {
            return $_SESSION['tenant_slug'];
        }

        // Fallback to subdomain (legacy support)
        return self::getSubdomain() ?? 'default';
    }

    /**
     * Set tenant in session
     *
     * @param string $slug Tenant slug (e.g., 'gwt')
     * @return bool True if tenant config exists and was set
     */
    public static function setTenant(string $slug): bool {
        $slug = strtolower(trim($slug));

        if (empty($slug) || $slug === 'default') {
            self::clearTenant();
            return true;
        }

        // Validate tenant config exists
        $configFile = "conf/config.{$slug}.ini";
        if (!file_exists($configFile)) {
            return false;
        }

        $_SESSION['tenant_slug'] = $slug;
        self::$sessionTenant = $slug;
        return true;
    }

    /**
     * Clear tenant from session (logout or switch to default)
     */
    public static function clearTenant(): void {
        unset($_SESSION['tenant_slug']);
        self::$sessionTenant = null;
    }

    /**
     * Get tenant slug from session only (not subdomain)
     *
     * @return string|null Tenant slug or null if not in session
     */
    public static function getSessionTenant(): ?string {
        return $_SESSION['tenant_slug'] ?? null;
    }

    /**
     * Check if a tenant config exists
     *
     * @param string $slug Tenant slug to check
     * @return bool True if config file exists
     */
    public static function tenantExists(string $slug): bool {
        if (empty($slug) || $slug === 'default') {
            return true;
        }
        return file_exists("conf/config.{$slug}.ini");
    }

    /**
     * Get the config file path for a tenant
     *
     * @param string|null $slug Tenant slug (null for current tenant)
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
     * Extract subdomain from current HTTP host (legacy support)
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
     * Check if current request is for a specific tenant
     *
     * @param string $slug Tenant slug to check
     * @return bool True if current tenant matches
     */
    public static function isTenant(string $slug): bool {
        return self::getSlug() === strtolower($slug);
    }

    /**
     * Check if current request is for the default (public) tenant
     *
     * @return bool True if default/public tenant (no session tenant, no subdomain)
     */
    public static function isDefault(): bool {
        // Check session tenant first
        if (isset($_SESSION['tenant_slug']) && !empty($_SESSION['tenant_slug'])) {
            return false;
        }

        // Check subdomain
        $subdomain = self::getSubdomain();
        return $subdomain === null;
    }

    /**
     * Get the current tenant's base URL
     *
     * @return string The base URL from config
     */
    public static function getBaseUrl(): string {
        return Flight::get('app.baseurl') ?? 'http://localhost';
    }

    /**
     * Get tenant info array
     *
     * @return array Tenant info
     */
    public static function getTenant(): array {
        return [
            'slug' => self::getSlug(),
            'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'baseurl' => self::getBaseUrl(),
            'is_default' => self::isDefault(),
            'from_session' => isset($_SESSION['tenant_slug'])
        ];
    }
}
