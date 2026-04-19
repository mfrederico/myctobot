<?php
/**
 * Anthropic Key Service
 * Centralized service for retrieving Anthropic API keys from the unified connections table
 * (connector_type = 'anthropic')
 */

namespace app\services;

use \app\Bean;

class AnthropicKeyService {

    /**
     * Get an available Anthropic API key for a member
     * Checks keys owned by member or shared with workspace
     *
     * @param int $memberId Member ID
     * @param string|null $preferredModel Optional model preference (e.g., 'claude-sonnet-4-20250514')
     * @return string|null Decrypted API key or null if none found
     */
    public static function getApiKey(int $memberId, ?string $preferredModel = null): ?string {
        $key = self::getKeyBean($memberId, $preferredModel);

        if (!$key || empty($key->access_token)) {
            return null;
        }

        return EncryptionService::decrypt($key->access_token);
    }

    /**
     * Get the full key bean (for when you need metadata like model)
     *
     * @param int $memberId Member ID
     * @param string|null $preferredModel Optional model preference
     * @return object|null Key bean or null if none found
     */
    public static function getKeyBean(int $memberId, ?string $preferredModel = null): ?object {
        // Get all anthropic connections available to this member (owned or shared)
        $keys = Bean::find('connections',
            ' connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY created_at DESC ',
            ['anthropic', $memberId]
        );

        if (empty($keys)) {
            return null;
        }

        // If a preferred model is specified, try to find a matching key first
        if ($preferredModel) {
            foreach ($keys as $key) {
                $metadata = json_decode($key->metadata_json ?? '{}', true) ?: [];
                if (isset($metadata['model']) && $metadata['model'] === $preferredModel) {
                    return $key;
                }
            }
        }

        // Fall back to first available key
        return reset($keys) ?: null;
    }

    /**
     * Check if a member has any Anthropic API keys available
     *
     * @param int $memberId Member ID
     * @return bool True if at least one key is available
     */
    public static function hasApiKey(int $memberId): bool {
        $count = Bean::count('connections',
            ' connector_type = ? AND (member_id = ? OR shared = 1) ',
            ['anthropic', $memberId]
        );
        return $count > 0;
    }

    /**
     * Get all available keys for a member (owned + shared)
     *
     * @param int $memberId Member ID
     * @return array Array of key beans
     */
    public static function getAllKeys(int $memberId): array {
        return Bean::find('connections',
            ' connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY created_at DESC ',
            ['anthropic', $memberId]
        );
    }

    /**
     * Get the default model from the first available key
     *
     * @param int $memberId Member ID
     * @return string|null Model string or null
     */
    public static function getDefaultModel(int $memberId): ?string {
        $key = self::getKeyBean($memberId);
        if (!$key) {
            return null;
        }
        $metadata = json_decode($key->metadata_json ?? '{}', true) ?: [];
        return $metadata['model'] ?? null;
    }

    /**
     * Get API key for a specific agent
     * Uses the agent's assigned key, or falls back to member's available keys
     *
     * @param object $agent Agent bean with connections_id
     * @param int $memberId Member ID for fallback
     * @return string|null Decrypted API key or null
     */
    public static function getApiKeyForAgent(object $agent, int $memberId): ?string {
        // First check if agent has an assigned key
        if (!empty($agent->connections_id)) {
            $key = Bean::load('connections', (int)$agent->connections_id);
            if ($key && $key->id && !empty($key->access_token)) {
                return EncryptionService::decrypt($key->access_token);
            }
        }

        // Fall back to member's available keys
        return self::getApiKey($memberId);
    }

    /**
     * Get key bean for a specific agent
     *
     * @param object $agent Agent bean
     * @param int $memberId Member ID for fallback
     * @return object|null Key bean or null
     */
    public static function getKeyBeanForAgent(object $agent, int $memberId): ?object {
        if (!empty($agent->connections_id)) {
            $key = Bean::load('connections', (int)$agent->connections_id);
            if ($key && $key->id) {
                return $key;
            }
        }

        return self::getKeyBean($memberId);
    }

    /**
     * Test an Anthropic API key by making a minimal API call
     *
     * @param int $keyId Key ID to test
     * @return array ['success' => bool, 'message' => string, 'error' => string|null]
     */
    public static function testKey(int $keyId): array {
        $key = Bean::load('connections', $keyId);

        if (!$key || !$key->id) {
            return ['success' => false, 'error' => 'Key not found'];
        }

        $apiKey = EncryptionService::decrypt($key->access_token);
        $metadata = json_decode($key->metadata_json ?? '{}', true) ?: [];
        $model = $metadata['model'] ?? 'claude-sonnet-4-20250514';

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.anthropic.com',
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
            ]);

            $client->post('/v1/messages', [
                'json' => [
                    'model' => $model,
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                ],
            ]);

            return ['success' => true, 'message' => 'API key is valid!'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
