<?php
/**
 * Connector Interface
 *
 * Defines the contract for all service connectors (Shopify, GitHub, Atlassian, etc.)
 * Each connector registers itself via auto-discovery in ConnectorRegistry.
 */

namespace app\services\connectors;

interface ConnectorInterface {

    /**
     * Get the unique key for this connector (e.g., 'shopify', 'github')
     */
    public static function getKey(): string;

    /**
     * Get connector metadata for display on connections page
     *
     * @return array [
     *   'name' => string,        // e.g., 'Shopify'
     *   'description' => string,  // e.g., 'Connect your Shopify store...'
     *   'icon' => string,         // Bootstrap icon class e.g., 'bi-shop'
     *   'color' => string,        // Bootstrap color e.g., 'success'
     *   'auth_type' => string,    // 'oauth', 'api_key', 'token'
     *   'tier_required' => string, // 'free', 'pro', 'enterprise'
     *   'features' => array,      // ['Theme Development', 'Store Analysis']
     * ]
     */
    public static function getMeta(): array;

    /**
     * Get the config keys needed for this connector's OAuth/API setup
     * e.g., ['client_id', 'client_secret', 'redirect_uri', 'scopes']
     *
     * @return array
     */
    public static function getConfigKeys(): array;

    /**
     * Build the OAuth authorization URL
     * Return null for non-OAuth connectors (API key, manual token)
     *
     * @param string $state  Encoded OAuth state (from OAuthStateService)
     * @param array  $config OAuth config (client_id, client_secret, etc.)
     * @param array  $extra  Extra params (e.g., shop domain for Shopify)
     * @return string|null   Authorization URL or null
     */
    public static function getOAuthUrl(string $state, array $config, array $extra = []): ?string;

    /**
     * Exchange an authorization code for tokens
     * Return null for non-OAuth connectors
     *
     * @param string $code   Authorization code from provider
     * @param array  $config OAuth config
     * @param array  $extra  Extra params
     * @return array|null    Normalized token data: [
     *   'access_token' => string,
     *   'refresh_token' => ?string,
     *   'token_type' => string,
     *   'expires_at' => ?string,    // Y-m-d H:i:s
     *   'scopes' => ?string,
     *   'external_eid' => ?string,   // Provider-side ID
     *   'external_name' => ?string, // Human-readable name
     *   'external_url' => ?string,  // API base URL or site URL
     *   'metadata' => array,        // Connector-specific extras
     * ]
     */
    public static function exchangeCode(string $code, array $config, array $extra = []): ?array;

    /**
     * Refresh an expired OAuth token
     * Return null if connector doesn't support refresh (permanent tokens)
     *
     * @param string $refreshToken The refresh token
     * @param array  $config       OAuth config
     * @return array|null          ['access_token' => string, 'expires_at' => ?string] or null
     */
    public static function refreshToken(string $refreshToken, array $config): ?array;

    /**
     * Test a connection to verify it's working
     *
     * @param object $connectionBean The connections table bean (decrypted tokens available)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function testConnection(object $connectionBean): array;

    /**
     * Get status information for the connections dashboard
     *
     * @param int   $memberId    Current member ID
     * @param array $connections Array of connection beans for this connector type
     * @return array [
     *   'connected' => bool,
     *   'status' => string,
     *   'details' => ?array,
     *   'actions' => array,
     * ]
     */
    public static function getStatus(int $memberId, array $connections): array;

    /**
     * Validate connector-specific data before creating/updating a connection
     *
     * @param array $data The connection data to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $data): array;
}
