<?php
/**
 * Shopify Client Service
 *
 * Handles API calls to Shopify stores using per-customer credentials.
 * Supports direct Admin API access tokens (shpat_*) for simpler setup.
 *
 * Connection Modes:
 * 1. Multi-store (new): Load from shopifyconnections table via connection ID
 * 2. Legacy (deprecated): Load from enterprisesettings via member ID
 *
 * Connection Flow:
 * 1. User generates an Admin API access token in Shopify Admin
 * 2. User enters their shop domain and access token in /shopify
 * 3. Token is stored encrypted in the database
 * 4. API calls use the token directly
 */

namespace app\services;

use \Flight as Flight;
use \GuzzleHttp\Client;
use \RedBeanPHP\R as R;
use \app\Bean;
use \Exception;

require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/../lib/Bean.php';

class ShopifyClient {

    private $httpClient;
    private $memberId;
    private $connectionId;
    private $shop;
    private $accessToken;

    // Shopify API version
    const API_VERSION = '2024-10';

    /**
     * Create ShopifyClient
     *
     * @param int|null $connectionId Connection ID from shopifyconnections table (preferred)
     * @param int|null $memberId Member ID for legacy enterprisesettings lookup
     */
    public function __construct(?int $connectionId = null, ?int $memberId = null) {
        $this->connectionId = $connectionId;
        $this->memberId = $memberId;
        $this->httpClient = new Client([
            'timeout' => 30,
            'http_errors' => false
        ]);

        // Load credentials based on mode
        if ($connectionId) {
            $this->loadCredentialsFromConnection($connectionId);
        } elseif ($memberId) {
            // Legacy fallback
            $this->loadCredentialsFromSettings($memberId);
        }
    }

    /**
     * Load credentials from shopifyconnections table (multi-store mode)
     *
     * @param int $connectionId Connection ID
     */
    private function loadCredentialsFromConnection(int $connectionId): void {
        try {
            $conn = Bean::load('shopifyconnections', $connectionId);
            if (!$conn->id) {
                throw new Exception('Shopify connection not found');
            }
            $this->shop = $conn->shop_domain;
            $this->accessToken = EncryptionService::decrypt($conn->access_token);
        } catch (Exception $e) {
            // Credentials not available
        }
    }

    /**
     * Load Shopify credentials from enterprisesettings (legacy mode)
     *
     * @param int $memberId Member ID
     */
    private function loadCredentialsFromSettings(int $memberId): void {
        try {
            $shop = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_shop', $memberId]);
            $token = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_access_token', $memberId]);

            if ($shop) {
                $this->shop = $shop->setting_value;
            }
            if ($token) {
                $this->accessToken = EncryptionService::decrypt($token->setting_value);
            }
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
     * Save Shopify credentials (shop domain and access token)
     *
     * @param string $shop Shop domain
     * @param string $accessToken Admin API access token (shpat_*)
     */
    public function saveCredentials(string $shop, string $accessToken): void {
        $shop = $this->normalizeShopDomain($shop);

        // Validate token format
        if (!preg_match('/^shpat_/', $accessToken)) {
            throw new Exception('Invalid token format. Admin API access tokens start with shpat_');
        }

        // Store shop domain (not encrypted)
        $shopBean = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_shop', $this->memberId]);
        if (!$shopBean) {
            $shopBean = R::dispense('enterprisesettings');
            $shopBean->setting_key = 'shopify_shop';
            $shopBean->member_id = $this->memberId;
            $shopBean->created_at = date('Y-m-d H:i:s');
        }
        $shopBean->setting_value = $shop;
        $shopBean->is_encrypted = 0;
        $shopBean->updated_at = date('Y-m-d H:i:s');
        R::store($shopBean);

        // Store encrypted access token
        $tokenBean = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_access_token', $this->memberId]);
        if (!$tokenBean) {
            $tokenBean = R::dispense('enterprisesettings');
            $tokenBean->setting_key = 'shopify_access_token';
            $tokenBean->member_id = $this->memberId;
            $tokenBean->created_at = date('Y-m-d H:i:s');
        }
        $tokenBean->setting_value = EncryptionService::encrypt($accessToken);
        $tokenBean->is_encrypted = 1;
        $tokenBean->updated_at = date('Y-m-d H:i:s');
        R::store($tokenBean);

        // Update local state
        $this->shop = $shop;
        $this->accessToken = $accessToken;

        // Fetch and store shop info
        $this->refreshShopInfo();
    }

    /**
     * Refresh and store shop info from API
     */
    public function refreshShopInfo(): void {
        if (!$this->isConnected()) {
            return;
        }

        $shopInfo = $this->getShopInfo();

        if (!empty($shopInfo)) {
            $infoBean = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_shop_info', $this->memberId]);
            if (!$infoBean) {
                $infoBean = R::dispense('enterprisesettings');
                $infoBean->setting_key = 'shopify_shop_info';
                $infoBean->member_id = $this->memberId;
                $infoBean->created_at = date('Y-m-d H:i:s');
            }
            $infoBean->setting_value = json_encode($shopInfo);
            $infoBean->is_encrypted = 0;
            $infoBean->updated_at = date('Y-m-d H:i:s');
            R::store($infoBean);
        }
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
            $shopInfo = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_shop_info', $this->memberId]);

            if ($shopInfo) {
                $details['shop_info'] = json_decode($shopInfo->setting_value, true);
            }
        } catch (Exception $e) {
            // Ignore
        }

        return $details;
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
        $beans = R::find('enterprisesettings', 'setting_key LIKE ? AND member_id = ?', ['shopify_%', $this->memberId]);
        foreach ($beans as $bean) {
            R::trash($bean);
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
     * Get all Shopify connections for the workspace
     *
     * @return array Array of connection beans
     */
    public static function getAllConnections(): array {
        return Bean::findAll('shopifyconnections', ' ORDER BY created_at DESC ');
    }

    /**
     * Get enabled Shopify connections for the workspace
     *
     * @return array Array of enabled connection beans
     */
    public static function getEnabledConnections(): array {
        return Bean::find('shopifyconnections', ' enabled = 1 ORDER BY created_at DESC ');
    }

    /**
     * Get a connection by ID
     *
     * @param int $connectionId Connection ID
     * @return object|null Connection bean or null
     */
    public static function getConnection(int $connectionId): ?object {
        $conn = Bean::load('shopifyconnections', $connectionId);
        return $conn->id ? $conn : null;
    }

    /**
     * Get a connection by shop domain
     *
     * @param string $shopDomain Shop domain
     * @return object|null Connection bean or null
     */
    public static function getConnectionByShop(string $shopDomain): ?object {
        $shopDomain = self::normalizeShopDomainStatic($shopDomain);
        return Bean::findOne('shopifyconnections', ' shop_domain = ? ', [$shopDomain]);
    }

    /**
     * Get connection linked to a repo
     *
     * @param int $repoId Repo connection ID
     * @return object|null Connection bean or null
     */
    public static function getConnectionByRepo(int $repoId): ?object {
        return Bean::findOne('shopifyconnections', ' repo_connection_id = ? AND enabled = 1 ', [$repoId]);
    }

    /**
     * Create a new Shopify connection
     *
     * @param int $memberId Member ID creating the connection
     * @param string $memberName Member display name
     * @param string $shopDomain Shop domain
     * @param string $accessToken Admin API access token (shpat_*)
     * @param string|null $connectionName Optional friendly name
     * @return object Created connection bean
     */
    public static function createConnection(
        int $memberId,
        string $memberName,
        string $shopDomain,
        string $accessToken,
        ?string $connectionName = null
    ): object {
        $shopDomain = self::normalizeShopDomainStatic($shopDomain);

        // Check if shop already exists
        $existing = self::getConnectionByShop($shopDomain);
        if ($existing) {
            throw new Exception("Shop {$shopDomain} is already connected");
        }

        // Validate token format
        if (!preg_match('/^shpat_/', $accessToken)) {
            throw new Exception('Invalid token format. Admin API access tokens start with shpat_');
        }

        // Create connection
        $conn = Bean::dispense('shopifyconnections');
        $conn->created_by_member_id = $memberId;
        $conn->created_by_name = $memberName;
        $conn->connection_name = $connectionName;
        $conn->shop_domain = $shopDomain;
        $conn->access_token = EncryptionService::encrypt($accessToken);
        $conn->enabled = 1;
        $conn->created_at = date('Y-m-d H:i:s');
        Bean::store($conn);

        // Fetch and update shop info
        try {
            $client = new self($conn->id);
            $shopInfo = $client->getShopInfo();
            if (!empty($shopInfo)) {
                $conn->shop_name = $shopInfo['name'] ?? null;
                $conn->shop_email = $shopInfo['email'] ?? null;
                Bean::store($conn);
            }
        } catch (Exception $e) {
            // Ignore errors fetching shop info
        }

        return $conn;
    }

    /**
     * Update a Shopify connection
     *
     * @param int $connectionId Connection ID
     * @param array $data Fields to update
     * @return object Updated connection bean
     */
    public static function updateConnection(int $connectionId, array $data): object {
        $conn = Bean::load('shopifyconnections', $connectionId);
        if (!$conn->id) {
            throw new Exception('Connection not found');
        }

        // Update allowed fields
        $allowedFields = ['connection_name', 'storefront_password', 'verify_with_playwright', 'enabled', 'repo_connection_id'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'storefront_password' && !empty($data[$field])) {
                    $conn->$field = EncryptionService::encrypt($data[$field]);
                } else {
                    $conn->$field = $data[$field];
                }
            }
        }

        // Handle access token update separately (needs encryption)
        if (!empty($data['access_token'])) {
            if (!preg_match('/^shpat_/', $data['access_token'])) {
                throw new Exception('Invalid token format. Admin API access tokens start with shpat_');
            }
            $conn->access_token = EncryptionService::encrypt($data['access_token']);
        }

        $conn->updated_at = date('Y-m-d H:i:s');
        Bean::store($conn);

        return $conn;
    }

    /**
     * Delete a Shopify connection
     *
     * @param int $connectionId Connection ID
     * @return bool True if deleted
     */
    public static function deleteConnection(int $connectionId): bool {
        $conn = Bean::load('shopifyconnections', $connectionId);
        if (!$conn->id) {
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
        return self::updateConnection($connectionId, ['repo_connection_id' => $repoId]);
    }

    /**
     * Unlink a connection from its repo
     *
     * @param int $connectionId Shopify connection ID
     * @return object Updated connection bean
     */
    public static function unlinkRepo(int $connectionId): object {
        return self::updateConnection($connectionId, ['repo_connection_id' => null]);
    }

    /**
     * Test a connection by ID (static wrapper)
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
}
