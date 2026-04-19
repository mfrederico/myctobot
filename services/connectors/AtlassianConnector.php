<?php
/**
 * Atlassian Connector
 *
 * OAuth-based connector for Atlassian/Jira.
 * Delegates API calls to AtlassianAuth plugin.
 */

namespace app\services\connectors;

use \GuzzleHttp\Client;
use \app\services\EncryptionService;
use \app\Bean;

class AtlassianConnector extends AbstractConnector {

    private static $authUrl = 'https://auth.atlassian.com/authorize';
    private static $tokenUrl = 'https://auth.atlassian.com/oauth/token';
    private static $resourcesUrl = 'https://api.atlassian.com/oauth/token/accessible-resources';

    private static $defaultScopes = 'read:jira-work read:jira-user read:board-scope:jira-software read:project:jira read:sprint:jira-software offline_access';
    private static $writeScopes = 'read:jira-work read:jira-user read:board-scope:jira-software read:project:jira read:sprint:jira-software write:jira-work write:webhook:jira read:webhook:jira delete:webhook:jira read:source-code:jira-software offline_access';

    public static function getKey(): string {
        return 'atlassian';
    }

    public static function getMeta(): array {
        return [
            'name' => 'Atlassian / Jira',
            'description' => 'Connect your Jira boards for sprint analysis and AI developer features',
            'icon' => 'bi-kanban',
            'color' => 'primary',
            'auth_type' => 'oauth',
            'tier_required' => 'free',
            'features' => ['Sprint Analysis', 'Daily Digests', 'AI Developer (Enterprise)'],
        ];
    }

    public static function getConfigKeys(): array {
        return ['client_id', 'client_secret', 'redirect_uri', 'scopes'];
    }

    public static function getOAuthUrl(string $state, array $config, array $extra = []): ?string {
        $clientId = $config['client_id'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';
        $scopes = $config['scopes'] ?? self::$defaultScopes;

        if (empty($clientId) || empty($redirectUri)) {
            return null;
        }

        $params = [
            'audience' => 'api.atlassian.com',
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'prompt' => 'consent',
        ];

        return self::$authUrl . '?' . http_build_query($params);
    }

    public static function exchangeCode(string $code, array $config, array $extra = []): ?array {
        $clientId = $config['client_id'] ?? '';
        $clientSecret = $config['client_secret'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        $client = new Client(['timeout' => 30]);

        // Exchange code for tokens
        $response = $client->post(self::$tokenUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['access_token'])) {
            return null;
        }

        // Fetch accessible resources (Jira sites)
        $resources = self::fetchResources($body['access_token']);
        $firstSite = $resources[0] ?? null;

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'token_type' => $body['token_type'] ?? 'Bearer',
            'expires_at' => date('Y-m-d H:i:s', time() + ($body['expires_in'] ?? 3600)),
            'scopes' => $body['scope'] ?? ($config['scopes'] ?? self::$defaultScopes),
            'external_eid' => $firstSite['id'] ?? '',
            'external_name' => $firstSite['name'] ?? 'Atlassian',
            'external_url' => $firstSite['url'] ?? '',
            'metadata' => [
                'cloud_uid' => $firstSite['id'] ?? '',
                'resources' => $resources,
                'has_write_scopes' => str_contains($body['scope'] ?? '', 'write:jira-work'),
            ],
        ];
    }

    public static function refreshToken(string $refreshToken, array $config): ?array {
        $clientId = $config['client_id'] ?? '';
        $clientSecret = $config['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        try {
            $client = new Client(['timeout' => 30]);
            $response = $client->post(self::$tokenUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (empty($body['access_token'])) {
                return null;
            }

            return [
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? $refreshToken,
                'expires_at' => date('Y-m-d H:i:s', time() + ($body['expires_in'] ?? 3600)),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function testConnection(object $connectionBean): array {
        if (empty($connectionBean->access_token)) {
            return ['success' => false, 'message' => 'No access token'];
        }

        try {
            $token = EncryptionService::decrypt($connectionBean->access_token);
            $resources = self::fetchResources($token);

            if (!empty($resources)) {
                return ['success' => true, 'message' => count($resources) . ' site(s) accessible'];
            }
            return ['success' => false, 'message' => 'No accessible resources'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function getStatus(int $memberId, array $connections): array {
        if (empty($connections)) {
            return [
                'connected' => false,
                'status' => 'Not connected',
                'details' => null,
                'actions' => [
                    ['label' => 'Connect', 'url' => '/connections/connect/atlassian', 'class' => 'btn-primary']
                ],
            ];
        }

        $sites = [];
        $hasWriteScopes = false;
        foreach ($connections as $conn) {
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $sites[] = [
                'cloud_uid' => $conn->external_eid ?: ($metadata['cloud_uid'] ?? ''),
                'site_name' => $conn->external_name,
                'site_url' => $conn->external_url,
            ];
            if (!empty($metadata['has_write_scopes'])) {
                $hasWriteScopes = true;
            }
        }

        $scopeInfo = $hasWriteScopes ? 'Read/Write' : 'Read Only';

        return [
            'connected' => true,
            'status' => count($sites) . ' site(s) connected',
            'details' => [
                'sites' => $sites,
                'scopes' => $scopeInfo,
                'has_write_scopes' => $hasWriteScopes,
            ],
            'actions' => [
                ['label' => 'Manage Sites', 'url' => '/atlassian', 'class' => 'btn-outline-primary'],
                ['label' => 'Add Site', 'url' => '/connections/connect/atlassian', 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    public static function loadOAuthConfig(): array {
        return self::loadConfig('atlassian', self::getConfigKeys());
    }

    private static function fetchResources(string $accessToken): array {
        try {
            $client = new Client(['timeout' => 15, 'http_errors' => false]);
            $response = $client->get(self::$resourcesUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true) ?: [];
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return [];
    }
}
