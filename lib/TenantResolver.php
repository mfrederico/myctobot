<?php
/**
 * TenantResolver - Helper for multi-tenant subdomain routing
 *
 * Works with the bootstrap's config resolution to provide helper methods
 * for determining current tenant context.
 *
 * Routing logic (handled by bootstrap):
 *   gwt.myctobot.ai     → conf/config.gwt.ini
 *   acme.myctobot.ai    → conf/config.acme.ini
 *   myctobot.ai         → conf/config.ini (default)
 *   localhost           → conf/config.ini (default)
 */

namespace app;

use \Flight as Flight;

class TenantResolver {

    /**
     * Extract subdomain from current HTTP host
     *
     * Examples:
     *   gwt.myctobot.ai → gwt
     *   acme.myctobot.ai → acme
     *   myctobot.ai → null
     *   localhost → null
     *   192.168.1.1 → null
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
     * Get tenant slug
     *
     * @return string Tenant slug (e.g., 'gwt', 'acme', 'default')
     */
    public static function getSlug(): string {
        return self::getSubdomain() ?? 'default';
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
     * This checks if we're on the main site (no subdomain) by examining
     * the baseurl from the loaded config.
     *
     * @return bool True if default/public tenant
     */
    public static function isDefault(): bool {
        // Check if there's a subdomain in the current host
        $subdomain = self::getSubdomain();

        // No subdomain = default site
        if ($subdomain === null) {
            return true;
        }

        // Check the baseurl from config - if it matches the main domain, it's default
        $baseUrl = Flight::get('app.baseurl') ?? '';

        // Parse the baseurl to see if it has a subdomain
        $parsedUrl = parse_url($baseUrl);
        $configHost = $parsedUrl['host'] ?? '';
        $configParts = explode('.', $configHost);

        // Main site (myctobot.ai) has 2 parts, subdomains have 3+
        return count($configParts) < 3;
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
            'is_default' => self::isDefault()
        ];
    }
}
