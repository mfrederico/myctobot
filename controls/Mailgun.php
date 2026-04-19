<?php
/**
 * Mailgun Controller
 * Manage Mailgun email service configuration
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;
use app\services\EncryptionService;
use app\services\MailgunService;

require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/MailgunService.php';

class Mailgun extends Control {

    /**
     * Main configuration page
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Get current settings
        $settings = $this->getSettings();

        $this->render('mailgun/index', [
            'title' => 'Mailgun Configuration',
            'settings' => $settings
        ]);
    }

    /**
     * Save Mailgun configuration
     */
    public function save() {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $apiKey = $this->getParam('api_key');
        $domain = $this->sanitize($this->getParam('domain'));
        $fromEmail = $this->sanitize($this->getParam('from_email'));
        $fromName = $this->sanitize($this->getParam('from_name'));
        $endpoint = $this->sanitize($this->getParam('endpoint')) ?: 'https://api.mailgun.net';

        // Validate required fields
        if (empty($domain)) {
            $this->flash('error', 'Domain is required');
            Flight::redirect('/mailgun');
            return;
        }

        $memberId = (int) $this->member->id;

        // Find existing connection or create new one
        $conn = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1) AND enabled = 1 ORDER BY id ASC', ['mailgun', $memberId]);

        // If API key is empty but we have an existing one, keep it
        if (empty($apiKey) && $conn && !empty($conn->access_token)) {
            // Keep existing key - user didn't change it
        } elseif (!empty($apiKey)) {
            // Will save new/updated API key below
        } else {
            $this->flash('error', 'API Key is required');
            Flight::redirect('/mailgun');
            return;
        }

        if (!$conn) {
            $conn = Bean::dispense('connections');
            $conn->connector_type = 'mailgun';
            $conn->auth_type = 'api_key';
            $conn->member_id = $memberId;
            $conn->connection_name = 'Mailgun';
            $conn->enabled = 1;
            $conn->shared = 1;
            $conn->created_at = date('Y-m-d H:i:s');
        }

        if (!empty($apiKey)) {
            $conn->access_token = EncryptionService::encrypt($apiKey);
        }

        $conn->external_eid = $domain;
        $conn->metadata_json = json_encode([
            'domain' => $domain,
            'from_email' => $fromEmail ?: "noreply@{$domain}",
            'from_name' => $fromName ?: 'MyCTOBot',
            'endpoint' => $endpoint,
        ]);
        $conn->updated_at = date('Y-m-d H:i:s');
        Bean::store($conn);

        $this->flash('success', 'Mailgun configuration saved');
        Flight::redirect('/mailgun');
    }

    /**
     * Test Mailgun connection (AJAX)
     */
    public function test() {
        if (!$this->requireLogin()) return;

        try {
            // Create a test instance using saved settings
            $settings = $this->getSettings();

            if (empty($settings['api_key']) || empty($settings['domain'])) {
                $this->jsonError('Mailgun not configured. Please save your settings first.');
                return;
            }

            // Try to validate by making a simple API call
            $client = new \GuzzleHttp\Client([
                'base_uri' => ($settings['endpoint'] ?: 'https://api.mailgun.net') . '/v3/',
                'auth' => ['api', $settings['api_key']],
            ]);

            $response = $client->get("domains/{$settings['domain']}");
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['domain'])) {
                $this->jsonSuccess([
                    'domain' => $data['domain']['name'] ?? $settings['domain'],
                    'state' => $data['domain']['state'] ?? 'unknown'
                ], 'Connection successful! Domain is ' . ($data['domain']['state'] ?? 'active'));
            } else {
                $this->jsonSuccess([], 'Connection successful');
            }

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->jsonError('Authentication failed. Check your API key.');
        } catch (\Exception $e) {
            $this->jsonError('Connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a test email (AJAX)
     */
    public function sendtest() {
        if (!$this->requireLogin()) return;

        $to = $this->getParam('to') ?: $this->member->email;

        try {
            $mailgun = new MailgunService();

            if (!$mailgun->isEnabled()) {
                $this->jsonError('Mailgun is not enabled. Please configure it first.');
                return;
            }

            $subject = 'MyCTOBot - Test Email';
            $content = "# Test Email\n\nThis is a test email from MyCTOBot.\n\nIf you received this, your Mailgun configuration is working correctly!\n\n---\n*Sent at: " . date('Y-m-d H:i:s') . "*";

            $result = $mailgun->sendMarkdownEmail($subject, $content, $to);

            if ($result) {
                $this->jsonSuccess([], "Test email sent to {$to}");
            } else {
                $this->jsonError('Failed to send test email');
            }

        } catch (\Exception $e) {
            $this->jsonError('Failed to send test email: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect/remove Mailgun configuration
     */
    public function disconnect() {
        if (!$this->requireLogin()) return;

        $memberId = (int) $this->member->id;
        $conn = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1) AND enabled = 1 ORDER BY id ASC', ['mailgun', $memberId]);
        if ($conn) {
            Bean::trash($conn);
        }

        $this->flash('success', 'Mailgun configuration removed');
        Flight::redirect('/settings/connections');
    }

    /**
     * Get current Mailgun settings
     */
    private function getSettings(): array {
        $settings = [
            'api_key' => '',
            'api_key_masked' => '',
            'domain' => '',
            'from_email' => '',
            'from_name' => '',
            'endpoint' => 'https://api.mailgun.net'
        ];

        $memberId = (int) $this->member->id;
        $conn = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1) AND enabled = 1 ORDER BY id ASC', ['mailgun', $memberId]);

        if ($conn && !empty($conn->access_token)) {
            $decrypted = EncryptionService::decrypt($conn->access_token);
            $settings['api_key'] = $decrypted;
            $settings['api_key_masked'] = $this->maskKey($decrypted);

            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $settings['domain'] = $conn->external_eid ?: ($metadata['domain'] ?? '');
            $settings['from_email'] = $metadata['from_email'] ?? '';
            $settings['from_name'] = $metadata['from_name'] ?? '';
            $settings['endpoint'] = $metadata['endpoint'] ?? 'https://api.mailgun.net';
        }

        return $settings;
    }

    /**
     * Mask an API key for display
     */
    private function maskKey(string $key): string {
        if (empty($key)) {
            return '';
        }
        $len = strlen($key);
        if ($len < 8) {
            return '****';
        }
        return substr($key, 0, 4) . str_repeat('*', $len - 8) . substr($key, -4);
    }
}
