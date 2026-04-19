<?php
/**
 * Site Configuration Service
 * Provides centralized access to site branding configuration
 *
 * All branding (domain, name, emails) is defined in config.ini [site] section
 * and accessed via this service for consistency across views and services.
 */

namespace app\services;

use \Flight as Flight;

class SiteConfig {

    /** @var array Cached config values */
    private static ?array $cache = null;

    /**
     * Load and cache site configuration from Flight config
     */
    private static function load(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [
            'domain' => Flight::get('site.domain') ?: 'myctobot.ai',
            'protocol' => Flight::get('site.protocol') ?: 'https',
            'name' => Flight::get('site.name') ?: 'MyCTOBot',
            'description' => Flight::get('site.description') ?: 'AI-Powered Development Platform',
            'support_email' => Flight::get('site.support_email') ?: 'support@myctobot.ai',
            'privacy_email' => Flight::get('site.privacy_email') ?: 'privacy@myctobot.ai',
            'legal_email' => Flight::get('site.legal_email') ?: 'legal@myctobot.ai',
            'ai_dev_email' => Flight::get('site.ai_dev_email') ?: 'ai-dev@myctobot.ai',
            'noreply_email' => Flight::get('site.noreply_email') ?: 'noreply@myctobot.ai',
        ];

        return self::$cache;
    }

    /**
     * Clear the cache (useful for testing or config reload)
     */
    public static function clearCache(): void {
        self::$cache = null;
    }

    /**
     * Get the primary domain (e.g., "myctobot.ai")
     */
    public static function getDomain(): string {
        return self::load()['domain'];
    }

    /**
     * Get the protocol (e.g., "https" or "http")
     */
    public static function getProtocol(): string {
        return self::load()['protocol'];
    }

    /**
     * Get the base URL without subdomain (e.g., "https://myctobot.ai")
     */
    public static function getBaseUrl(): string {
        $config = self::load();
        return $config['protocol'] . '://' . $config['domain'];
    }

    /**
     * Get the workspace URL (e.g., "https://gwt.myctobot.ai")
     *
     * @param string|null $workspace Workspace slug (null for current session workspace)
     * @return string Full workspace URL
     */
    public static function getWorkspaceUrl(?string $workspace = null): string {
        $config = self::load();

        // Use provided workspace or get from session
        if ($workspace === null) {
            $workspace = $_SESSION['workspace_slug'] ?? null;
        }

        // If no workspace or default workspace, return base URL
        if (empty($workspace) || $workspace === 'default') {
            return self::getBaseUrl();
        }

        return $config['protocol'] . '://' . $workspace . '.' . $config['domain'];
    }

    /**
     * Get the site name (e.g., "MyCTOBot")
     */
    public static function getName(): string {
        return self::load()['name'];
    }

    /**
     * Get the site description/tagline
     */
    public static function getDescription(): string {
        return self::load()['description'];
    }

    /**
     * Get a specific email address by type
     *
     * @param string $type One of: support, privacy, legal, ai_dev, noreply
     * @return string Email address
     */
    public static function getEmail(string $type): string {
        $config = self::load();
        $key = $type . '_email';

        if (isset($config[$key])) {
            return $config[$key];
        }

        // Fallback to support email for unknown types
        return $config['support_email'];
    }

    /**
     * Get all configuration as array for view injection
     *
     * Returns flattened keys prefixed with 'site_' for easy use in views:
     * - site_domain
     * - site_protocol
     * - site_base_url
     * - site_name
     * - site_description
     * - site_support_email
     * - site_privacy_email
     * - site_legal_email
     * - site_ai_dev_email
     * - site_noreply_email
     */
    public static function getViewData(): array {
        $config = self::load();

        return [
            'site_domain' => $config['domain'],
            'site_protocol' => $config['protocol'],
            'site_base_url' => self::getBaseUrl(),
            'site_name' => $config['name'],
            'site_description' => $config['description'],
            'site_support_email' => $config['support_email'],
            'site_privacy_email' => $config['privacy_email'],
            'site_legal_email' => $config['legal_email'],
            'site_ai_dev_email' => $config['ai_dev_email'],
            'site_noreply_email' => $config['noreply_email'],
        ];
    }

    /**
     * Build an authenticated URL for a workspace endpoint
     * Useful for invite links, webhooks, etc.
     *
     * @param string $workspace Workspace slug
     * @param string $path URL path (e.g., "/auth/invite/TOKEN")
     * @return string Full URL
     */
    public static function buildWorkspacePath(string $workspace, string $path): string {
        $base = self::getWorkspaceUrl($workspace);
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
