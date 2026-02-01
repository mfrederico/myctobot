<?php
/**
 * Anthropic Key Service
 * Centralized service for retrieving Anthropic API keys from the anthropickeys table
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

        if (!$key || empty($key->api_key)) {
            return null;
        }

        return EncryptionService::decrypt($key->api_key);
    }

    /**
     * Get the full key bean (for when you need metadata like model)
     *
     * @param int $memberId Member ID
     * @param string|null $preferredModel Optional model preference
     * @return object|null Key bean or null if none found
     */
    public static function getKeyBean(int $memberId, ?string $preferredModel = null): ?object {
        // Build query - prefer matching model if specified
        if ($preferredModel) {
            // First try to find key with matching model
            $key = Bean::findOne('anthropickeys',
                ' (created_by_member_id = ? OR shared = 1) AND model = ? ORDER BY created_at DESC ',
                [$memberId, $preferredModel]
            );
            if ($key) {
                return $key;
            }
        }

        // Fall back to any available key (owned or shared)
        return Bean::findOne('anthropickeys',
            ' created_by_member_id = ? OR shared = 1 ORDER BY created_at DESC ',
            [$memberId]
        );
    }

    /**
     * Check if a member has any Anthropic API keys available
     *
     * @param int $memberId Member ID
     * @return bool True if at least one key is available
     */
    public static function hasApiKey(int $memberId): bool {
        $count = Bean::count('anthropickeys',
            ' created_by_member_id = ? OR shared = 1 ',
            [$memberId]
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
        return Bean::find('anthropickeys',
            ' created_by_member_id = ? OR shared = 1 ORDER BY created_at DESC ',
            [$memberId]
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
        return $key ? $key->model : null;
    }

    /**
     * Get API key for a specific agent
     * Uses the agent's assigned key, or falls back to member's available keys
     *
     * @param object $agent Agent bean with anthropickeys_id
     * @param int $memberId Member ID for fallback
     * @return string|null Decrypted API key or null
     */
    public static function getApiKeyForAgent(object $agent, int $memberId): ?string {
        // First check if agent has an assigned key
        if (!empty($agent->anthropickeys_id)) {
            $key = Bean::load('anthropickeys', (int)$agent->anthropickeys_id);
            if ($key && $key->id && !empty($key->api_key)) {
                return EncryptionService::decrypt($key->api_key);
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
        if (!empty($agent->anthropickeys_id)) {
            $key = Bean::load('anthropickeys', (int)$agent->anthropickeys_id);
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
        $key = Bean::load('anthropickeys', $keyId);

        if (!$key || !$key->id) {
            return ['success' => false, 'error' => 'Key not found'];
        }

        $apiKey = EncryptionService::decrypt($key->api_key);
        $model = $key->model ?? 'claude-sonnet-4-20250514';

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
