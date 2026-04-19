<?php
/**
 * Google Stitch Connector
 *
 * OAuth-based connector for Google Stitch AI design tool.
 * Delegates API calls to StitchClient service.
 */

namespace app\services\connectors;

use \GuzzleHttp\Client;
use \app\services\EncryptionService;
use \app\Bean;

class StitchConnector extends AbstractConnector {

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private static $defaultScopes = 'https://www.googleapis.com/auth/cloud-platform';

    public static function getKey(): string {
        return 'stitch';
    }

    public static function getMeta(): array {
        return [
            'name' => 'Google Stitch',
            'description' => 'AI design tool for generating UI screens and production-ready code',
            'icon' => 'bi-palette',
            'color' => 'info',
            'auth_type' => 'oauth',
            'tier_required' => 'free',
            'features' => ['AI UI Design', 'Screen Generation', 'Code Export'],
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
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopes,
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public static function exchangeCode(string $code, array $config, array $extra = []): ?array {
        $clientId = $config['client_id'] ?? '';
        $clientSecret = $config['client_secret'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        $client = new Client(['timeout' => 30]);

        $response = $client->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['access_token'])) {
            return null;
        }

        // Fetch user info (email)
        $userInfo = self::fetchUserInfo($body['access_token']);

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'token_type' => $body['token_type'] ?? 'Bearer',
            'expires_at' => date('Y-m-d H:i:s', time() + ($body['expires_in'] ?? 3600)),
            'scopes' => $body['scope'] ?? ($config['scopes'] ?? self::$defaultScopes),
            'external_eid' => $userInfo['email'] ?? '',
            'external_name' => $userInfo['name'] ?? ($userInfo['email'] ?? 'Google User'),
            'external_url' => 'https://stitch.googleapis.com',
            'metadata' => [
                'google_email' => $userInfo['email'] ?? '',
                'gcp_project_id' => $extra['gcp_project_id'] ?? '',
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
            $response = $client->post(self::TOKEN_URL, [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
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
            $userInfo = self::fetchUserInfo($token);

            if (!empty($userInfo['email'])) {
                return ['success' => true, 'message' => "Connected as {$userInfo['email']}"];
            }
            return ['success' => false, 'message' => 'Could not verify token'];
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
                    ['label' => 'Connect Google', 'url' => '/connections/connect/stitch', 'class' => 'btn-info']
                ],
            ];
        }

        $connList = [];
        foreach ($connections as $conn) {
            $isOwner = ($conn->member_id == $memberId);
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $connList[] = [
                'id' => $conn->id,
                'google_email' => $metadata['google_email'] ?? $conn->external_eid ?? 'Unknown',
                'gcp_project_id' => $metadata['gcp_project_id'] ?? '',
                'enabled' => (bool)$conn->enabled,
                'shared' => (bool)$conn->shared,
                'is_owner' => $isOwner,
            ];
        }

        return [
            'connected' => true,
            'status' => count($connList) . ' account(s) connected',
            'details' => [
                'connections' => $connList,
                'connection_count' => count($connList),
            ],
            'actions' => [
                ['label' => 'Manage', 'url' => '/stitch', 'class' => 'btn-outline-info'],
                ['label' => 'Add Account', 'url' => '/connections/connect/stitch', 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    public static function loadOAuthConfig(): array {
        $config = self::loadConfig('stitch', self::getConfigKeys(), 'oauth');

        if (empty($config['scopes'])) {
            $config['scopes'] = self::$defaultScopes;
        }

        return $config;
    }

    private static function fetchUserInfo(string $accessToken): array {
        try {
            $client = new Client(['timeout' => 10, 'http_errors' => false]);
            $response = $client->get(self::USERINFO_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
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
