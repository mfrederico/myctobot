<?php
/**
 * Shopify Client Service
 *
 * Handles API calls to Shopify stores using per-customer credentials.
 * Loads credentials from unified `connections` table (connector_type='shopify').
 */

namespace app\services;

use \Flight as Flight;
use \GuzzleHttp\Client;
use \app\Bean;
use \Exception;

require_once __DIR__ . '/EncryptionService.php';

class ShopifyClient {

    private $httpClient;
    private $memberId;
    private $connectionId;
    private $shop;
    private $accessToken;

    // Shopify API version
    const API_VERSION = '2024-10';

    /**
     * Parse a Shopify GID (Global ID) into its components
     *
     * Shopify uses GIDs like: gid://shopify/Order/6098827706553
     * Uses PHP's parse_url since GIDs are URI format
     *
     * @param string $gid The Shopify GID
     * @return array ['type' => 'Order', 'id' => '6098827706553', 'full' => 'gid://...'] or empty array if invalid
     */
    public static function parseGid(string $gid): array {
        $parts = parse_url($gid);
        if (!$parts || ($parts['scheme'] ?? '') !== 'gid' || ($parts['host'] ?? '') !== 'shopify') {
            return [];
        }

        $path = trim($parts['path'] ?? '', '/');
        $segments = explode('/', $path);

        if (count($segments) !== 2) {
            return [];
        }

        return [
            'type' => $segments[0],
            'id' => $segments[1],
            'full' => $gid
        ];
    }

    /**
     * Extract just the numeric ID from a Shopify GID
     *
     * @param string $gid The Shopify GID (e.g., gid://shopify/Order/6098827706553)
     * @return string|null The numeric ID or null if invalid
     */
    public static function getIdFromGid(string $gid): ?string {
        $parsed = self::parseGid($gid);
        return $parsed['id'] ?? null;
    }

    /**
     * Extract the type from a Shopify GID
     *
     * @param string $gid The Shopify GID (e.g., gid://shopify/Order/6098827706553)
     * @return string|null The type (Order, Product, Customer, etc.) or null if invalid
     */
    public static function getTypeFromGid(string $gid): ?string {
        $parsed = self::parseGid($gid);
        return $parsed['type'] ?? null;
    }

    /**
     * Build a Shopify GID from type and ID
     *
     * @param string $type The object type (Order, Product, Customer, etc.)
     * @param string|int $id The numeric ID
     * @return string The full GID
     */
    public static function buildGid(string $type, $id): string {
        return "gid://shopify/{$type}/{$id}";
    }

    /**
     * Create ShopifyClient
     *
     * @param int|null $connectionId Connection ID from unified connections table
     * @param int|null $memberId Member ID (stored for reference, not used for credential lookup)
     */
    public function __construct(?int $connectionId = null, ?int $memberId = null) {
        $this->connectionId = $connectionId;
        $this->memberId = $memberId;
        $this->httpClient = new Client([
            'timeout' => 30,
            'http_errors' => false
        ]);

        if ($connectionId) {
            $this->loadCredentialsFromConnection($connectionId);
        }
    }

    /**
     * Load credentials from unified connections table
     *
     * @param int $connectionId Connection ID
     */
    private function loadCredentialsFromConnection(int $connectionId): void {
        try {
            $conn = Bean::load('connections', $connectionId);
            if (!$conn->id || $conn->connector_type !== 'shopify') {
                throw new Exception('Shopify connection not found: ' . $connectionId);
            }
            $this->shop = $conn->external_eid;
            $this->accessToken = EncryptionService::decrypt($conn->access_token);
        } catch (Exception $e) {
            // Credentials not available
        }
    }

    /**
     * Get the connection ID (for multi-store mode)
     */
    public function getConnectionId(): ?int {
        return $this->connectionId;
    }

    /**
     * Check if Shopify is configured (has shop and token)
     */
    public function isConfigured(): bool {
        return !empty($this->shop) && !empty($this->accessToken);
    }

    /**
     * Check if user is connected (same as configured for direct token auth)
     */
    public function isConnected(): bool {
        return $this->isConfigured();
    }

    /**
     * Get the configured shop domain
     */
    public function getShop(): ?string {
        return $this->shop;
    }

    /**
     * Get masked token for display (shpat_...xxxx)
     *
     * @return string|null Masked token or null if not set
     */
    public function getMaskedToken(): ?string {
        if (empty($this->accessToken)) {
            return null;
        }

        $token = $this->accessToken;
        $length = strlen($token);

        if ($length <= 8) {
            return '****';
        }

        // Show first 5 chars (shpat) and last 4 chars
        $prefix = substr($token, 0, 5);
        $suffix = substr($token, -4);

        return $prefix . '_...' . $suffix;
    }

    /**
     * Get connection details for display
     *
     * @return array|null Connection details or null if not connected
     */
    public function getConnectionDetails(): ?array {
        if (!$this->isConnected()) {
            return null;
        }

        $details = [
            'shop' => $this->shop,
            'shop_info' => null,
            'token_hint' => $this->getMaskedToken()
        ];

        try {
            $shopInfo = Bean::findOne('enterprisesettings', 'setting_key = ? AND (member_id = ? OR is_shared = 1)', ['shopify_shop_info', $this->memberId]);

            if ($shopInfo) {
                $details['shop_info'] = json_decode($shopInfo->setting_value, true);
            }
        } catch (Exception $e) {
            // Ignore
        }

        return $details;
    }

    /**
     * Execute a GraphQL query against the Shopify Admin API
     *
     * @param string $query GraphQL query string
     * @param array $variables Optional variables for the query
     * @return array Response with 'data', 'errors', 'extensions'
     * @throws Exception If not connected or request fails
     */
    public function graphql(string $query, array $variables = []): array {
        if (!$this->isConnected()) {
            throw new Exception('Not connected to Shopify');
        }

        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = $this->httpClient->post(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/graphql.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]
        );

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if ($statusCode !== 200) {
            $errorMessage = $data['errors'][0]['message'] ?? "HTTP {$statusCode}";
            throw new Exception("GraphQL request failed: {$errorMessage}");
        }

        // Check for GraphQL-level errors
        if (!empty($data['errors'])) {
            // Return the full response so caller can handle errors
            return [
                'success' => false,
                'data' => $data['data'] ?? null,
                'errors' => $data['errors'],
                'extensions' => $data['extensions'] ?? null
            ];
        }

        return [
            'success' => true,
            'data' => $data['data'] ?? null,
            'errors' => null,
            'extensions' => $data['extensions'] ?? null,
            'shop' => $this->shop
        ];
    }

    /**
     * Get shop information from Shopify API
     *
     * @return array Shop info
     */
    public function getShopInfo(): array {
        if (!$this->accessToken || !$this->shop) {
            return [];
        }

        $response = $this->httpClient->get(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/shop.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ]
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['shop'] ?? [];
    }

    // Required scopes for AI Developer theme operations
    const REQUIRED_SCOPES = ['read_themes', 'write_themes'];

    /**
     * Test the connection by fetching shop info and validating scopes
     *
     * @return array ['success' => bool, 'message' => string, 'shop_info' => array|null, 'scopes' => array, 'missing_scopes' => array]
     */
    public function testConnection(): array {
        if (!$this->isConnected()) {
            return ['success' => false, 'message' => 'Not configured', 'shop_info' => null, 'scopes' => [], 'missing_scopes' => []];
        }

        try {
            // Fetch shop info
            $shopResponse = $this->httpClient->get(
                "https://{$this->shop}/admin/api/" . self::API_VERSION . "/shop.json",
                [
                    'headers' => [
                        'X-Shopify-Access-Token' => $this->accessToken
                    ]
                ]
            );

            if ($shopResponse->getStatusCode() !== 200) {
                return [
                    'success' => false,
                    'message' => 'Could not fetch shop info. Check your token.',
                    'shop_info' => null,
                    'scopes' => [],
                    'missing_scopes' => []
                ];
            }

            $shopData = json_decode($shopResponse->getBody()->getContents(), true);
            $shopInfo = $shopData['shop'] ?? [];

            // Fetch access scopes via dedicated OAuth endpoint
            $scopeResponse = $this->httpClient->get(
                "https://{$this->shop}/admin/oauth/access_scopes.json",
                [
                    'headers' => [
                        'X-Shopify-Access-Token' => $this->accessToken
                    ]
                ]
            );

            $scopes = [];
            if ($scopeResponse->getStatusCode() === 200) {
                $scopeData = json_decode($scopeResponse->getBody()->getContents(), true);
                if (isset($scopeData['access_scopes'])) {
                    $scopes = array_column($scopeData['access_scopes'], 'handle');
                }
            }

            // Check for missing required scopes
            $missingScopes = array_diff(self::REQUIRED_SCOPES, $scopes);

            // Build result message
            $message = 'Connected to ' . ($shopInfo['name'] ?? $this->shop);

            if (!empty($missingScopes)) {
                $message .= '. WARNING: Missing required scopes: ' . implode(', ', $missingScopes);
                return [
                    'success' => false,
                    'message' => $message,
                    'shop_info' => $shopInfo,
                    'scopes' => $scopes,
                    'missing_scopes' => array_values($missingScopes)
                ];
            }

            return [
                'success' => true,
                'message' => $message,
                'shop_info' => $shopInfo,
                'scopes' => $scopes,
                'missing_scopes' => []
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'shop_info' => null,
                'scopes' => [],
                'missing_scopes' => []
            ];
        }
    }

    /**
     * Get list of themes for the shop
     *
     * @return array List of themes
     */
    public function getThemes(): array {
        if (!$this->isConnected()) {
            return [];
        }

        $response = $this->httpClient->get(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ]
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['themes'] ?? [];
    }

    /**
     * Get theme files/assets
     *
     * @param int $themeId Theme ID
     * @return array List of assets
     */
    public function getThemeAssets(int $themeId): array {
        if (!$this->isConnected()) {
            return [];
        }

        $response = $this->httpClient->get(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes/{$themeId}/assets.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ]
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['assets'] ?? [];
    }

    /**
     * Get a specific theme asset
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key (e.g., "templates/product.liquid")
     * @return array|null Asset data or null
     */
    public function getThemeAsset(int $themeId, string $key): ?array {
        if (!$this->isConnected()) {
            return null;
        }

        $response = $this->httpClient->get(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes/{$themeId}/assets.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ],
                'query' => [
                    'asset[key]' => $key
                ]
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['asset'] ?? null;
    }

    /**
     * Upload or update a theme asset
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key (e.g., "templates/product.liquid")
     * @param string $value Asset content
     * @return array Updated asset info
     */
    public function updateThemeAsset(int $themeId, string $key, string $value): array {
        if (!$this->isConnected()) {
            throw new Exception('Not connected to Shopify');
        }

        $response = $this->httpClient->put(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes/{$themeId}/assets.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'asset' => [
                        'key' => $key,
                        'value' => $value
                    ]
                ]
            ]
        );

        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
            throw new Exception('Failed to update asset: ' . $response->getBody());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['asset'] ?? [];
    }

    /**
     * Create a development theme (unpublished) for AI dev preview
     *
     * @param string $name Theme name (e.g., "[DEV] SSI-1844-header-gradient")
     * @param int|null $sourceThemeId Optional source theme to copy from (uses live theme if null)
     * @return array Created theme data with 'id', 'name', 'role'
     */
    public function createDevelopmentTheme(string $name, ?int $sourceThemeId = null): array {
        if (!$this->isConnected()) {
            throw new Exception('Not connected to Shopify');
        }

        // If no source theme specified, find the live theme
        if ($sourceThemeId === null) {
            $themes = $this->getThemes();
            foreach ($themes as $theme) {
                if ($theme['role'] === 'main') {
                    $sourceThemeId = $theme['id'];
                    break;
                }
            }
            if ($sourceThemeId === null) {
                throw new Exception('No live theme found to copy from');
            }
        }

        $response = $this->httpClient->post(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'theme' => [
                        'name' => $name,
                        'role' => 'unpublished',
                        'src' => "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes/{$sourceThemeId}.json"
                    ]
                ]
            ]
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200 && $statusCode !== 201 && $statusCode !== 202) {
            throw new Exception('Failed to create theme: ' . $response->getBody());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['theme'] ?? [];
    }

    /**
     * Find an existing development theme by name prefix
     *
     * @param string $prefix Theme name prefix to search for (e.g., "[DEV] SSI-1844")
     * @return array|null Theme data if found, null otherwise
     */
    public function findDevelopmentTheme(string $prefix): ?array {
        $themes = $this->getThemes();
        foreach ($themes as $theme) {
            if ($theme['role'] === 'unpublished' && str_starts_with($theme['name'], $prefix)) {
                return $theme;
            }
        }
        return null;
    }

    /**
     * Get or create a development theme for a ticket
     *
     * @param string $ticketKey Jira ticket key (e.g., "SSI-1844")
     * @param string $ticketTitle Optional ticket title for the theme name
     * @return array Theme data with 'id', 'name', 'preview_url'
     */
    public function getOrCreateDevTheme(string $ticketKey, string $ticketTitle = ''): array {
        $prefix = "[DEV] {$ticketKey}";

        // Check for existing theme
        $existingTheme = $this->findDevelopmentTheme($prefix);
        if ($existingTheme) {
            $existingTheme['preview_url'] = $this->getPreviewUrl($existingTheme['id']);
            return $existingTheme;
        }

        // Create new development theme
        $themeName = $prefix;
        if ($ticketTitle) {
            // Sanitize title for theme name
            $sanitizedTitle = preg_replace('/[^a-zA-Z0-9\-]/', '-', $ticketTitle);
            $sanitizedTitle = preg_replace('/-+/', '-', $sanitizedTitle);
            $sanitizedTitle = trim($sanitizedTitle, '-');
            if (strlen($sanitizedTitle) > 30) {
                $sanitizedTitle = substr($sanitizedTitle, 0, 30);
            }
            $themeName .= "-{$sanitizedTitle}";
        }

        $theme = $this->createDevelopmentTheme($themeName);
        $theme['preview_url'] = $this->getPreviewUrl($theme['id']);
        return $theme;
    }

    /**
     * Upload multiple theme files in bulk
     *
     * @param int $themeId Theme ID
     * @param array $files Array of ['key' => 'asset/path', 'value' => 'content'] or ['key' => 'asset/path', 'attachment' => 'base64']
     * @return array Results with 'success', 'failed', 'errors'
     */
    public function uploadThemeFiles(int $themeId, array $files): array {
        $results = [
            'success' => [],
            'failed' => [],
            'errors' => []
        ];

        foreach ($files as $file) {
            try {
                if (isset($file['attachment'])) {
                    // Binary file (base64 encoded)
                    $this->updateThemeAssetBinary($themeId, $file['key'], $file['attachment']);
                } else {
                    // Text file
                    $this->updateThemeAsset($themeId, $file['key'], $file['value']);
                }
                $results['success'][] = $file['key'];
            } catch (Exception $e) {
                $results['failed'][] = $file['key'];
                $results['errors'][$file['key']] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Upload a binary asset (base64 encoded)
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key
     * @param string $base64Content Base64 encoded content
     * @return array Updated asset info
     */
    public function updateThemeAssetBinary(int $themeId, string $key, string $base64Content): array {
        if (!$this->isConnected()) {
            throw new Exception('Not connected to Shopify');
        }

        $response = $this->httpClient->put(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes/{$themeId}/assets.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'asset' => [
                        'key' => $key,
                        'attachment' => $base64Content
                    ]
                ]
            ]
        );

        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
            throw new Exception('Failed to update binary asset: ' . $response->getBody());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['asset'] ?? [];
    }

    /**
     * Get the preview URL for a theme
     *
     * @param int $themeId Theme ID
     * @return string Preview URL (never publishes, just previews)
     */
    public function getPreviewUrl(int $themeId): string {
        if (!$this->shop) {
            throw new Exception('Shop not configured');
        }
        return "https://{$this->shop}/?preview_theme_id={$themeId}";
    }

    /**
     * Delete a theme
     *
     * @param int $themeId Theme ID to delete
     * @return bool True if deleted successfully
     */
    public function deleteTheme(int $themeId): bool {
        if (!$this->isConnected()) {
            throw new Exception('Not connected to Shopify');
        }

        $response = $this->httpClient->delete(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/themes/{$themeId}.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ]
            ]
        );

        // 200 = deleted, 404 = already deleted (both are success)
        return in_array($response->getStatusCode(), [200, 404]);
    }

    /**
     * Delete all development themes for a ticket (cleanup)
     *
     * @param string $ticketKey Jira ticket key to cleanup themes for
     * @return int Number of themes deleted
     */
    public function cleanupDevThemes(string $ticketKey): int {
        $prefix = "[DEV] {$ticketKey}";
        $deleted = 0;

        $themes = $this->getThemes();
        foreach ($themes as $theme) {
            if ($theme['role'] === 'unpublished' && str_starts_with($theme['name'], $prefix)) {
                if ($this->deleteTheme($theme['id'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Disconnect Shopify (remove all credentials)
     */
    public function disconnect(): void {
        $this->removeAllConfig();
    }

    /**
     * Remove all Shopify configuration (full reset)
     */
    public function removeAllConfig(): void {
        $beans = Bean::find('enterprisesettings', 'setting_key LIKE ? AND member_id = ?', ['shopify_%', $this->memberId]);
        foreach ($beans as $bean) {
            Bean::trash($bean);
        }

        $this->shop = null;
        $this->accessToken = null;
    }

    /**
     * Normalize shop domain to standard format
     *
     * @param string $shop Input shop (can be "mystore", "mystore.myshopify.com", or full URL)
     * @return string Normalized domain (e.g., "mystore.myshopify.com")
     */
    private function normalizeShopDomain(string $shop): string {
        // Remove protocol if present
        $shop = preg_replace('#^https?://#', '', $shop);

        // Remove trailing slashes
        $shop = rtrim($shop, '/');

        // Add .myshopify.com if not present
        if (!str_contains($shop, '.myshopify.com')) {
            $shop .= '.myshopify.com';
        }

        return strtolower($shop);
    }

    /**
     * Get the access token (for MCP server use)
     *
     * @return string|null Access token or null
     */
    public function getAccessToken(): ?string {
        return $this->accessToken;
    }

    // =========================================================================
    // STATIC METHODS FOR CONNECTION MANAGEMENT (multi-store mode)
    // =========================================================================

    /**
     * Get all Shopify connections visible to a member
     * Returns connections owned by the member OR shared with workspace
     * Queries unified connections table with connector_type='shopify'
     *
     * @param int|null $memberId Member ID (null = all connections for admin)
     * @return array Array of connection beans
     */
    public static function getAllConnections(?int $memberId = null): array {
        if ($memberId === null) {
            return Bean::find('connections', ' connector_type = ? ORDER BY created_at DESC ', ['shopify']);
        }
        return Bean::find('connections',
            ' connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY created_at DESC ',
            ['shopify', $memberId]
        );
    }

    /**
     * Get enabled Shopify connections visible to a member
     * Returns enabled connections owned by the member OR shared with workspace
     *
     * @param int|null $memberId Member ID (null = all enabled connections)
     * @return array Array of enabled connection beans
     */
    public static function getEnabledConnections(?int $memberId = null): array {
        if ($memberId === null) {
            return Bean::find('connections', ' connector_type = ? AND enabled = 1 ORDER BY created_at DESC ', ['shopify']);
        }
        return Bean::find('connections',
            ' connector_type = ? AND enabled = 1 AND (member_id = ? OR shared = 1) ORDER BY created_at DESC ',
            ['shopify', $memberId]
        );
    }

    /**
     * Get a connection by ID
     * Queries unified connections table with connector_type='shopify'
     *
     * @param int $connectionId Connection ID
     * @return object|null Connection bean or null
     */
    public static function getConnection(int $connectionId): ?object {
        $conn = Bean::load('connections', $connectionId);
        return ($conn->id && $conn->connector_type === 'shopify') ? $conn : null;
    }

    /**
     * Get a connection by shop domain
     * Queries unified connections table with connector_type='shopify'
     *
     * @param string $shopDomain Shop domain
     * @return object|null Connection bean or null
     */
    public static function getConnectionByShop(string $shopDomain): ?object {
        $shopDomain = self::normalizeShopDomainStatic($shopDomain);
        return Bean::findOne('connections', ' connector_type = ? AND external_eid = ? ', ['shopify', $shopDomain]);
    }

    /**
     * Get connection linked to a repo
     * Queries unified connections table metadata_json with connector_type='shopify'
     *
     * @param int $repoId Repo connection ID
     * @return object|null Connection bean or null
     */
    public static function getConnectionByRepo(int $repoId): ?object {
        $conns = Bean::find('connections', ' connector_type = ? AND enabled = 1 ', ['shopify']);
        foreach ($conns as $conn) {
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            if (($metadata['repoconnections_id'] ?? null) == $repoId) {
                return $conn;
            }
        }
        return null;
    }

    /**
     * Create a new Shopify connection (writes to unified connections table)
     *
     * @param int $memberId Member ID creating the connection
     * @param string $memberName Member display name
     * @param string $shopDomain Shop domain
     * @param string $accessToken Admin API access token (shpat_*)
     * @param string|null $connectionName Optional friendly name
     * @param bool $shared Share with workspace members
     * @return object Created connection bean
     */
    public static function createConnection(
        int $memberId,
        string $memberName,
        string $shopDomain,
        string $accessToken,
        ?string $connectionName = null,
        bool $shared = false
    ): object {
        $shopDomain = self::normalizeShopDomainStatic($shopDomain);

        // Check if shop already exists
        $existing = self::getConnectionByShop($shopDomain);
        if ($existing) {
            throw new Exception("Shop {$shopDomain} is already connected");
        }

        // Validate token format
        // Accept both manual tokens (shpat_*) and OAuth tokens (alphanumeric, 32+ chars)
        if (!preg_match('/^shpat_/', $accessToken) && !preg_match('/^[a-zA-Z0-9_-]{32,}$/', $accessToken)) {
            throw new Exception('Invalid token format. Expected Admin API token (shpat_*) or OAuth token.');
        }

        // Create in unified connections table
        $conn = Bean::dispense('connections');
        $conn->connector_type = 'shopify';
        $conn->member_id = $memberId;
        $conn->connection_name = $connectionName ?: $shopDomain;
        $conn->auth_type = 'oauth';
        $conn->access_token = EncryptionService::encrypt($accessToken);
        $conn->external_eid = $shopDomain;
        $conn->external_name = $shopDomain;
        $conn->external_url = "https://{$shopDomain}";
        $conn->enabled = 1;
        $conn->shared = $shared ? 1 : 0;
        $conn->metadata_json = json_encode(['created_by_name' => $memberName]);
        $conn->created_at = date('Y-m-d H:i:s');
        $conn->updated_at = date('Y-m-d H:i:s');
        Bean::store($conn);

        // Fetch and update shop info
        try {
            $client = new self($conn->id);
            $shopInfo = $client->getShopInfo();
            if (!empty($shopInfo)) {
                $conn->external_name = $shopInfo['name'] ?? $shopDomain;
                $metadata = json_decode($conn->metadata_json ?: '{}', true);
                $metadata['shop_email'] = $shopInfo['email'] ?? null;
                $conn->metadata_json = json_encode($metadata);
                Bean::store($conn);
            }
        } catch (Exception $e) {
            // Ignore errors fetching shop info
        }

        return $conn;
    }

    /**
     * Update a Shopify connection
     * Uses unified connections table with connector_type='shopify'
     *
     * @param int $connectionId Connection ID
     * @param array $data Fields to update
     * @return object Updated connection bean
     */
    public static function updateConnection(int $connectionId, array $data): object {
        $conn = Bean::load('connections', $connectionId);
        if (!$conn->id || $conn->connector_type !== 'shopify') {
            throw new Exception('Connection not found');
        }

        $metadata = json_decode($conn->metadata_json ?: '{}', true);

        if (array_key_exists('connection_name', $data)) {
            $conn->connection_name = $data['connection_name'];
        }
        if (array_key_exists('enabled', $data)) {
            $conn->enabled = $data['enabled'];
        }
        if (array_key_exists('shared', $data)) {
            $conn->shared = $data['shared'];
        }

        // Store connector-specific fields in metadata
        $metadataFields = ['storefront_password', 'verify_with_playwright', 'repoconnections_id'];
        foreach ($metadataFields as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'storefront_password' && !empty($data[$field])) {
                    $metadata[$field] = EncryptionService::encrypt($data[$field]);
                } else {
                    $metadata[$field] = $data[$field];
                }
            }
        }

        // Handle access token update
        if (!empty($data['access_token'])) {
            if (!preg_match('/^shpat_/', $data['access_token']) && !preg_match('/^[a-zA-Z0-9_-]{32,}$/', $data['access_token'])) {
                throw new Exception('Invalid token format. Expected Admin API token (shpat_*) or OAuth token.');
            }
            $conn->access_token = EncryptionService::encrypt($data['access_token']);
        }

        // Update shop info fields
        if (!empty($data['shop_name'])) {
            $conn->external_name = $data['shop_name'];
        }
        if (!empty($data['shop_email'])) {
            $metadata['shop_email'] = $data['shop_email'];
        }
        if (!empty($data['scope'])) {
            $conn->scopes = $data['scope'];
        }

        $conn->metadata_json = json_encode($metadata);
        $conn->updated_at = date('Y-m-d H:i:s');
        Bean::store($conn);
        return $conn;
    }

    /**
     * Delete a Shopify connection
     * Queries unified connections table with connector_type='shopify'
     *
     * @param int $connectionId Connection ID
     * @return bool True if deleted
     */
    public static function deleteConnection(int $connectionId): bool {
        $conn = Bean::load('connections', $connectionId);
        if (!$conn->id || $conn->connector_type !== 'shopify') {
            return false;
        }
        Bean::trash($conn);
        return true;
    }

    /**
     * Link a connection to a repo
     *
     * @param int $connectionId Shopify connection ID
     * @param int $repoId Repo connection ID
     * @return object Updated connection bean
     */
    public static function linkRepo(int $connectionId, int $repoId): object {
        return self::updateConnection($connectionId, ['repoconnections_id' => $repoId]);
    }

    /**
     * Unlink a connection from its repo
     *
     * @param int $connectionId Shopify connection ID
     * @return object Updated connection bean
     */
    public static function unlinkRepo(int $connectionId): object {
        return self::updateConnection($connectionId, ['repoconnections_id' => null]);
    }

    /**
     * Test a connection by ID (static wrapper)
     * Works with both unified and legacy connection IDs
     *
     * @param int $connectionId Connection ID
     * @return array Test result
     */
    public static function testConnectionById(int $connectionId): array {
        $client = new self($connectionId);
        return $client->testConnection();
    }

    /**
     * Normalize shop domain (static version)
     *
     * @param string $shop Shop domain
     * @return string Normalized domain
     */
    private static function normalizeShopDomainStatic(string $shop): string {
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = rtrim($shop, '/');
        if (!str_contains($shop, '.myshopify.com')) {
            $shop .= '.myshopify.com';
        }
        return strtolower($shop);
    }

    // ========================================
    // Script Tag Management (for Customer Support Widget)
    // ========================================

    /**
     * Get all script tags for the shop
     *
     * @return array List of script tags
     */
    public function getScriptTags(): array {
        if (!$this->isConnected()) {
            return [];
        }

        $response = $this->httpClient->get(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/script_tags.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ]
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['script_tags'] ?? [];
    }

    /**
     * Create a script tag
     *
     * @param string $src Script URL
     * @param string $event Event trigger (default: 'onload')
     * @param string $displayScope Display scope: 'online_store', 'order_status', 'all'
     * @return array Created script tag data
     */
    public function createScriptTag(string $src, string $event = 'onload', string $displayScope = 'all'): array {
        if (!$this->isConnected()) {
            throw new Exception('Not connected to Shopify');
        }

        $response = $this->httpClient->post(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/script_tags.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'script_tag' => [
                        'event' => $event,
                        'src' => $src,
                        'display_scope' => $displayScope
                    ]
                ]
            ]
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200 && $statusCode !== 201) {
            throw new Exception('Failed to create script tag: ' . $response->getBody());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['script_tag'] ?? [];
    }

    /**
     * Delete a script tag by ID
     *
     * @param int $scriptTagId Script tag ID
     * @return bool True if deleted
     */
    public function deleteScriptTag(int $scriptTagId): bool {
        if (!$this->isConnected()) {
            return false;
        }

        $response = $this->httpClient->delete(
            "https://{$this->shop}/admin/api/" . self::API_VERSION . "/script_tags/{$scriptTagId}.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $this->accessToken
                ]
            ]
        );

        // 200 = deleted, 404 = already deleted
        return in_array($response->getStatusCode(), [200, 404]);
    }

    /**
     * Find the MyCTOBot widget script tag
     *
     * @return array|null Script tag data or null if not found
     */
    public function findWidgetScriptTag(): ?array {
        $scriptTags = $this->getScriptTags();
        foreach ($scriptTags as $tag) {
            if (str_contains($tag['src'] ?? '', 'myctobot') && str_contains($tag['src'] ?? '', '/widget/js/')) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * Sync widget script tag with connection's public_agent_token
     *
     * If public_agent_token is set, ensures script tag exists with correct URL.
     * If public_agent_token is empty, removes any existing widget script tag.
     *
     * @param string|null $publicAgentToken The widget token (null to remove)
     * @param string $baseUrl Base URL for widget script (e.g., 'https://dev.myctobot.ai')
     * @return array Result with 'action' => 'created'|'updated'|'removed'|'unchanged', 'script_tag' => data
     */
    public function syncWidgetScriptTag(?string $publicAgentToken, string $baseUrl): array {
        $existingTag = $this->findWidgetScriptTag();
        $expectedSrc = $publicAgentToken ? "{$baseUrl}/widget/js/{$publicAgentToken}" : null;

        // Case 1: No token set, remove existing tag if present
        if (empty($publicAgentToken)) {
            if ($existingTag) {
                $this->deleteScriptTag($existingTag['id']);
                return ['action' => 'removed', 'script_tag' => null];
            }
            return ['action' => 'unchanged', 'script_tag' => null];
        }

        // Case 2: Token set, check if tag exists with correct URL
        if ($existingTag) {
            if ($existingTag['src'] === $expectedSrc) {
                // Already correct
                return ['action' => 'unchanged', 'script_tag' => $existingTag];
            }
            // Wrong URL, remove old and create new
            $this->deleteScriptTag($existingTag['id']);
        }

        // Case 3: Create new script tag
        $newTag = $this->createScriptTag($expectedSrc, 'onload', 'online_store');
        return [
            'action' => $existingTag ? 'updated' : 'created',
            'script_tag' => $newTag
        ];
    }

    /**
     * Sync widget script tag for a connection (static helper)
     *
     * @param int $connectionId Shopify connection ID
     * @param string $baseUrl Base URL for widget (e.g., 'https://dev.myctobot.ai')
     * @return array Sync result
     */
    public static function syncWidgetForConnection(int $connectionId, string $baseUrl): array {
        $conn = Bean::load('connections', $connectionId);
        if (!$conn->id || $conn->connector_type !== 'shopify') {
            throw new Exception('Connection not found');
        }

        $metadata = json_decode($conn->metadata_json ?: '{}', true);
        $client = new self($connectionId);
        return $client->syncWidgetScriptTag($metadata['public_agent_token'] ?? null, $baseUrl);
    }
}
