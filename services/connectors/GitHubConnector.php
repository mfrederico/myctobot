<?php
/**
 * GitHub Connector
 *
 * OAuth-based connector for GitHub.
 * Delegates API calls to GitHubClient service.
 */

namespace app\services\connectors;

use \GuzzleHttp\Client;
use \app\services\EncryptionService;
use \app\Bean;

class GitHubConnector extends AbstractConnector {

    private const AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    public static function getKey(): string {
        return 'github';
    }

    public static function getMeta(): array {
        return [
            'name' => 'GitHub',
            'description' => 'Connect repositories for AI-powered code implementation',
            'icon' => 'bi-github',
            'color' => 'dark',
            'auth_type' => 'oauth',
            'tier_required' => 'free',
            'features' => ['AI Developer', 'Automated PRs', 'Code Analysis'],
        ];
    }

    public static function getConfigKeys(): array {
        return ['client_id', 'client_secret', 'redirect_uri'];
    }

    public static function getOAuthUrl(string $state, array $config, array $extra = []): ?string {
        $clientId = $config['client_id'] ?? '';
        $redirectUri = $config['redirect_uri'] ?? '';

        if (empty($clientId)) {
            return null;
        }

        // Strip query params from redirect_uri (GitHub requires exact match)
        if (strpos($redirectUri, '?') !== false) {
            $redirectUri = explode('?', $redirectUri)[0];
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'repo read:user',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public static function exchangeCode(string $code, array $config, array $extra = []): ?array {
        $clientId = $config['client_id'] ?? '';
        $clientSecret = $config['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        $client = new Client(['timeout' => 30]);

        $response = $client->post(self::TOKEN_URL, [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (!empty($body['error']) || empty($body['access_token'])) {
            return null;
        }

        // Fetch user info
        $user = self::fetchUser($body['access_token']);

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => null, // GitHub tokens are permanent
            'token_type' => $body['token_type'] ?? 'bearer',
            'expires_at' => null,
            'scopes' => $body['scope'] ?? '',
            'external_eid' => $user['login'] ?? '',
            'external_name' => $user['name'] ?? ($user['login'] ?? 'GitHub User'),
            'external_url' => $user['html_url'] ?? 'https://github.com',
            'metadata' => [
                'user_login' => $user['login'] ?? '',
                'user_avatar' => $user['avatar_url'] ?? '',
                'user_id' => $user['id'] ?? null,
            ],
        ];
    }

    public static function testConnection(object $connectionBean): array {
        if (empty($connectionBean->access_token)) {
            return ['success' => false, 'message' => 'No access token configured'];
        }

        try {
            $token = EncryptionService::decrypt($connectionBean->access_token);
            $user = self::fetchUser($token);

            if (!empty($user['login'])) {
                return ['success' => true, 'message' => "Connected as @{$user['login']}"];
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
                    ['label' => 'Connect GitHub', 'url' => '/connections/connect/github', 'class' => 'btn-dark']
                ],
            ];
        }

        // Build list of all GitHub accounts
        $accounts = [];
        foreach ($connections as $conn) {
            $isOwner = ($conn->member_id == $memberId);
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $accounts[] = [
                'id' => $conn->id,
                'login' => $metadata['user_login'] ?? $conn->external_eid ?? 'Connected',
                'avatar_url' => $metadata['user_avatar'] ?? '',
                'name' => $conn->external_name,
                'shared' => (bool)$conn->shared,
                'is_owner' => $isOwner,
            ];
        }

        $firstAccount = $accounts[0] ?? null;

        // Count repos
        $repoCount = 0;
        try {
            $repos = Bean::find('repoconnections', 'enabled = ?', [1]);
            $repoCount = count($repos);
        } catch (\Exception $e) {
            // Table may not exist
        }

        return [
            'connected' => true,
            'status' => count($accounts) . " account(s) - {$repoCount} repo(s)",
            'details' => [
                'user' => $firstAccount ? [
                    'login' => $firstAccount['login'],
                    'avatar_url' => $firstAccount['avatar_url'],
                ] : null,
                'accounts' => $accounts,
                'repos' => [],
                'repo_count' => $repoCount,
            ],
            'actions' => [
                ['label' => 'Manage Repos', 'url' => '/github/repos', 'class' => 'btn-outline-dark'],
                ['label' => 'Add Account', 'url' => '/connections/connect/github', 'class' => 'btn-outline-secondary'],
                ['label' => 'Disconnect', 'url' => '/github/disconnect', 'class' => 'btn-outline-danger', 'confirm' => 'Are you sure you want to disconnect GitHub?'],
            ],
        ];
    }

    public static function loadOAuthConfig(): array {
        return self::loadConfig('github', self::getConfigKeys());
    }

    private static function fetchUser(string $token): array {
        try {
            $client = new Client(['timeout' => 15, 'http_errors' => false]);
            $response = $client->get('https://api.github.com/user', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
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
