<?php
/**
 * Anthropic Connector
 *
 * API key-based connector for Anthropic Claude API.
 * No OAuth flow - users enter their API key directly.
 */

namespace app\services\connectors;

use \GuzzleHttp\Client;
use \app\services\EncryptionService;
use \app\Bean;

class AnthropicConnector extends AbstractConnector {

    public static function getKey(): string {
        return 'anthropic';
    }

    public static function getMeta(): array {
        return [
            'name' => 'Anthropic API',
            'description' => 'Your Claude API key for AI-powered features',
            'icon' => 'bi-robot',
            'color' => 'warning',
            'auth_type' => 'api_key',
            'tier_required' => 'free',
            'features' => ['AI Developer', 'Advanced Analysis'],
        ];
    }

    public static function getConfigKeys(): array {
        return [];
    }

    public static function testConnection(object $connectionBean): array {
        if (empty($connectionBean->access_token)) {
            return ['success' => false, 'message' => 'No API key configured'];
        }

        try {
            $apiKey = EncryptionService::decrypt($connectionBean->access_token);

            $client = new Client(['timeout' => 15, 'http_errors' => false]);
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'claude-sonnet-4-5-20250929',
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                ],
            ]);

            $status = $response->getStatusCode();

            if ($status === 200) {
                return ['success' => true, 'message' => 'API key is valid'];
            }

            if ($status === 401) {
                return ['success' => false, 'message' => 'Invalid API key'];
            }

            if ($status === 429) {
                return ['success' => true, 'message' => 'API key valid (rate limited)'];
            }

            return ['success' => false, 'message' => 'HTTP ' . $status];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function validate(array $data): array {
        $errors = [];

        if (empty($data['api_key'])) {
            $errors[] = 'API key is required';
        } elseif (!preg_match('/^sk-ant-/', $data['api_key'])) {
            $errors[] = 'API key should start with sk-ant-';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function getStatus(int $memberId, array $connections): array {
        if (empty($connections)) {
            return [
                'connected' => false,
                'status' => 'No API keys configured',
                'details' => null,
                'actions' => [
                    ['label' => 'Add API Key', 'url' => '/connections/add/anthropic', 'class' => 'btn-warning']
                ],
            ];
        }

        $keyList = [];
        $ownedCount = 0;
        $sharedCount = 0;

        foreach ($connections as $conn) {
            $isOwner = ($conn->member_id == $memberId);
            $metadata = json_decode($conn->metadata_json ?: '{}', true);

            $maskedKey = '****';
            try {
                $decrypted = EncryptionService::decrypt($conn->access_token);
                $maskedKey = self::maskAnthropicKey($decrypted);
            } catch (\Exception $e) {
                // Key might be corrupted
            }

            $ownerName = 'Unknown';
            if ($conn->member_id) {
                $owner = Bean::load('member', (int)$conn->member_id);
                if ($owner->id) {
                    $ownerName = $owner->display_name ?: $owner->email;
                }
            }

            $keyList[] = [
                'id' => $conn->id,
                'name' => $conn->connection_name ?: 'API Key',
                'model' => $metadata['model'] ?? '',
                'masked_key' => $maskedKey,
                'shared' => (bool)$conn->shared,
                'is_owner' => $isOwner,
                'owner_name' => $ownerName,
            ];

            if ($isOwner) $ownedCount++;
            else $sharedCount++;
        }

        $statusParts = [];
        if ($ownedCount > 0) $statusParts[] = $ownedCount . ' owned';
        if ($sharedCount > 0) $statusParts[] = $sharedCount . ' shared';

        return [
            'connected' => true,
            'status' => count($keyList) . ' key(s) - ' . implode(', ', $statusParts),
            'details' => [
                'keys' => $keyList,
                'key_count' => count($keyList),
                'owned_count' => $ownedCount,
                'shared_count' => $sharedCount,
            ],
            'actions' => [
                ['label' => 'Manage Keys', 'url' => '/anthropic/keys', 'class' => 'btn-outline-warning'],
                ['label' => 'Add Key', 'url' => '/connections/add/anthropic', 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    private static function maskAnthropicKey(string $key): string {
        if (empty($key)) {
            return '(not configured)';
        }

        if (strlen($key) < 20) {
            return 'sk-ant-****...****';
        }

        if (preg_match('/^(sk-ant-api\d+-)(.+)$/', $key, $matches)) {
            $prefix = $matches[1];
            $secret = $matches[2];

            if (strlen($secret) > 7) {
                return $prefix . substr($secret, 0, 3) . '...' . substr($secret, -4);
            }
            return $prefix . '***';
        }

        return substr($key, 0, 10) . '...' . substr($key, -4);
    }
}
