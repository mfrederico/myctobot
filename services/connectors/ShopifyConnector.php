<?php
/**
 * Shopify Connector
 *
 * OAuth-based connector for Shopify stores.
 * Delegates API calls to ShopifyClient service.
 */

namespace app\services\connectors;

use \GuzzleHttp\Client;
use \app\services\EncryptionService;
use \app\services\ShopifyClient;
use \app\Bean;

class ShopifyConnector extends AbstractConnector {

    public static function getKey(): string {
        return 'shopify';
    }

    public static function getMeta(): array {
        return [
            'name' => 'Shopify',
            'description' => 'Connect your Shopify store for theme development',
            'icon' => 'bi-shop',
            'color' => 'success',
            'auth_type' => 'oauth',
            'tier_required' => 'free',
            'features' => ['Theme Development', 'Store Analysis'],
        ];
    }

    public static function getConfigKeys(): array {
        return ['api_key', 'api_secret', 'redirect_uri', 'scopes'];
    }

    public static function getOAuthUrl(string $state, array $config, array $extra = []): ?string {
        $apiKey = $config['api_key'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';
        $scopes = $config['scopes'] ?? 'read_themes,write_themes,read_products,read_content';
        $shop = $extra['shop'] ?? '';

        if (empty($apiKey) || empty($shop)) {
            return null;
        }

        // Normalize shop domain
        $shop = self::normalizeShopDomain($shop);

        $params = [
            'client_id' => $apiKey,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];

        return "https://{$shop}/admin/oauth/authorize?" . http_build_query($params);
    }

    public static function exchangeCode(string $code, array $config, array $extra = []): ?array {
        $apiKey = $config['api_key'] ?? '';
        $apiSecret = $config['api_secret'] ?? '';
        $shop = $extra['shop'] ?? '';

        if (empty($apiKey) || empty($apiSecret) || empty($shop)) {
            return null;
        }

        $shop = self::normalizeShopDomain($shop);
        $client = new Client(['timeout' => 30]);

        $response = $client->post("https://{$shop}/admin/oauth/access_token", [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'client_id' => $apiKey,
                'client_secret' => $apiSecret,
                'code' => $code,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['access_token'])) {
            return null;
        }

        // Fetch shop info
        $shopInfo = self::fetchShopInfo($shop, $body['access_token']);

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => null, // Shopify tokens are permanent
            'token_type' => 'Bearer',
            'expires_at' => null,
            'scopes' => $body['scope'] ?? '',
            'external_eid' => $shop,
            'external_name' => $shopInfo['name'] ?? $shop,
            'external_url' => "https://{$shop}",
            'metadata' => [
                'shop_email' => $shopInfo['email'] ?? '',
            ],
        ];
    }

    public static function testConnection(object $connectionBean): array {
        if (empty($connectionBean->access_token)) {
            return ['success' => false, 'message' => 'No access token configured'];
        }

        try {
            $token = EncryptionService::decrypt($connectionBean->access_token);
            $shopDomain = $connectionBean->external_eid ?: $connectionBean->external_url;
            if (empty($shopDomain) || empty($token)) {
                return ['success' => false, 'message' => 'Missing shop domain or token'];
            }

            // Strip protocol from external_url if needed
            $shopDomain = preg_replace('#^https?://#', '', $shopDomain);

            $client = new Client(['timeout' => 15, 'http_errors' => false]);
            $response = $client->get("https://{$shopDomain}/admin/api/" . ShopifyClient::API_VERSION . "/shop.json", [
                'headers' => ['X-Shopify-Access-Token' => $token],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $shopName = $data['shop']['name'] ?? $shopDomain;
                return ['success' => true, 'message' => "Connected to {$shopName}"];
            }

            return ['success' => false, 'message' => 'HTTP ' . $response->getStatusCode()];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function getStatus(int $memberId, array $connections): array {
        if (empty($connections)) {
            return [
                'connected' => false,
                'status' => 'No stores connected',
                'details' => null,
                'actions' => [
                    ['label' => 'Add Store', 'url' => '/connections/connect/shopify', 'class' => 'btn-success']
                ],
            ];
        }

        $storeList = [];
        $ownedCount = 0;
        $sharedCount = 0;

        foreach ($connections as $conn) {
            $isOwner = ($conn->member_id == $memberId);
            $metadata = json_decode($conn->metadata_json ?: '{}', true);

            $ownerName = 'Unknown';
            if ($conn->member_id) {
                $owner = Bean::load('member', (int)$conn->member_id);
                if ($owner->id) {
                    $ownerName = $owner->display_name ?: $owner->email;
                }
            }

            $storeList[] = [
                'id' => $conn->id,
                'shop_domain' => $conn->external_eid,
                'shop_name' => $conn->external_name ?: $conn->external_eid,
                'enabled' => (bool)$conn->enabled,
                'shared' => (bool)$conn->shared,
                'is_owner' => $isOwner,
                'owner_name' => $ownerName,
                'repo_linked' => !empty($metadata['repoconnections_id']),
            ];
            if ($isOwner) $ownedCount++;
            else $sharedCount++;
        }

        $statusParts = [];
        if ($ownedCount > 0) $statusParts[] = $ownedCount . ' owned';
        if ($sharedCount > 0) $statusParts[] = $sharedCount . ' shared';

        return [
            'connected' => true,
            'status' => count($storeList) . ' store(s) - ' . implode(', ', $statusParts),
            'details' => [
                'stores' => $storeList,
                'store_count' => count($storeList),
                'owned_count' => $ownedCount,
                'shared_count' => $sharedCount,
            ],
            'actions' => [
                ['label' => 'Manage Stores', 'url' => '/shopify', 'class' => 'btn-outline-success'],
                ['label' => 'Add Store', 'url' => '/connections/connect/shopify', 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    /**
     * Load Shopify OAuth config using centralized 3-step cascade
     */
    public static function loadOAuthConfig(): array {
        $config = self::loadConfig('shopify', self::getConfigKeys());

        // Map api_key/api_secret to client_id/client_secret for generic flow
        // (Shopify uses different names)
        if (empty($config['scopes'])) {
            $config['scopes'] = 'read_themes,write_themes,read_products,read_content';
        }

        return $config;
    }

    /**
     * Check if Shopify OAuth is configured
     */
    public static function isOAuthReady(): bool {
        $config = self::loadOAuthConfig();
        return !empty($config['api_key']) && !empty($config['api_secret']);
    }

    private static function normalizeShopDomain(string $shop): string {
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = rtrim($shop, '/');
        if (!str_contains($shop, '.myshopify.com')) {
            $shop .= '.myshopify.com';
        }
        return strtolower($shop);
    }

    private static function fetchShopInfo(string $shop, string $token): array {
        try {
            $client = new Client(['timeout' => 15, 'http_errors' => false]);
            $response = $client->get("https://{$shop}/admin/api/" . ShopifyClient::API_VERSION . "/shop.json", [
                'headers' => ['X-Shopify-Access-Token' => $token, 'Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                return $data['shop'] ?? [];
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return [];
    }
}
