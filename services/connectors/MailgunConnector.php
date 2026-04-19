<?php
/**
 * Mailgun Connector
 *
 * API key-based connector for Mailgun email service.
 * No OAuth flow - uses API key + domain configuration.
 */

namespace app\services\connectors;

use \GuzzleHttp\Client;
use \app\services\EncryptionService;
use \app\Bean;

class MailgunConnector extends AbstractConnector {

    public static function getKey(): string {
        return 'mailgun';
    }

    public static function getMeta(): array {
        return [
            'name' => 'Mailgun',
            'description' => 'Email service for pipeline notifications and alerts',
            'icon' => 'bi-envelope',
            'color' => 'danger',
            'auth_type' => 'api_key',
            'tier_required' => 'free',
            'features' => ['Pipeline Email Steps', 'Notifications', 'Digest Emails'],
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
            $metadata = json_decode($connectionBean->metadata_json ?: '{}', true);
            $domain = $metadata['domain'] ?? $connectionBean->external_eid ?? '';

            if (empty($domain)) {
                return ['success' => false, 'message' => 'No domain configured'];
            }

            $client = new Client(['timeout' => 15, 'http_errors' => false]);
            $response = $client->get("https://api.mailgun.net/v3/{$domain}", [
                'auth' => ['api', $apiKey],
            ]);

            if ($response->getStatusCode() === 200) {
                return ['success' => true, 'message' => "Domain {$domain} verified"];
            }

            if ($response->getStatusCode() === 401) {
                return ['success' => false, 'message' => 'Invalid API key'];
            }

            return ['success' => false, 'message' => 'HTTP ' . $response->getStatusCode()];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function validate(array $data): array {
        $errors = [];

        if (empty($data['api_key'])) {
            $errors[] = 'API key is required';
        }

        if (empty($data['domain'])) {
            $errors[] = 'Domain is required';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function getStatus(int $memberId, array $connections): array {
        if (empty($connections)) {
            return [
                'connected' => false,
                'status' => 'Not configured',
                'details' => null,
                'actions' => [
                    ['label' => 'Configure', 'url' => '/connections/add/mailgun', 'class' => 'btn-danger']
                ],
            ];
        }

        $conn = reset($connections);
        $metadata = json_decode($conn->metadata_json ?: '{}', true);
        $domain = $metadata['domain'] ?? $conn->external_eid ?? '';
        $fromEmail = $metadata['from_email'] ?? "noreply@{$domain}";

        $maskedKey = '****';
        try {
            $decrypted = EncryptionService::decrypt($conn->access_token);
            $maskedKey = self::maskApiKey($decrypted);
        } catch (\Exception $e) {
            // Key might be corrupted
        }

        return [
            'connected' => true,
            'status' => "Domain: {$domain}",
            'details' => [
                'domain' => $domain,
                'from_email' => $fromEmail,
                'masked_key' => $maskedKey,
            ],
            'actions' => [
                ['label' => 'Manage', 'url' => '/mailgun', 'class' => 'btn-outline-danger'],
                ['label' => 'Test', 'url' => '/mailgun/test', 'class' => 'btn-outline-secondary', 'ajax' => true],
            ],
        ];
    }

    private static function maskApiKey(string $key): string {
        if (empty($key)) {
            return '(not configured)';
        }

        $len = strlen($key);
        if ($len < 8) {
            return '****';
        }

        return substr($key, 0, 4) . '...' . substr($key, -4);
    }
}
