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

        // If API key is empty but we have an existing one, keep it
        $existingSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_api_key']);
        if (empty($apiKey) && $existingSetting && !empty($existingSetting->setting_value)) {
            // Keep existing key - user didn't change it
        } elseif (!empty($apiKey)) {
            // Save new/updated API key (encrypted)
            $this->saveSetting('mailgun_api_key', EncryptionService::encrypt($apiKey));
        } else {
            $this->flash('error', 'API Key is required');
            Flight::redirect('/mailgun');
            return;
        }

        // Save other settings
        $this->saveSetting('mailgun_domain', $domain);
        $this->saveSetting('mailgun_from_email', $fromEmail ?: "noreply@{$domain}");
        $this->saveSetting('mailgun_from_name', $fromName ?: 'MyCTOBot');
        $this->saveSetting('mailgun_endpoint', $endpoint);

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

        $this->deleteSetting('mailgun_api_key');
        $this->deleteSetting('mailgun_domain');
        $this->deleteSetting('mailgun_from_email');
        $this->deleteSetting('mailgun_from_name');
        $this->deleteSetting('mailgun_endpoint');

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

        $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_api_key']);
        if ($apiKeySetting && !empty($apiKeySetting->setting_value)) {
            $decrypted = EncryptionService::decrypt($apiKeySetting->setting_value);
            $settings['api_key'] = $decrypted;
            $settings['api_key_masked'] = $this->maskKey($decrypted);
        }

        $domainSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_domain']);
        if ($domainSetting) {
            $settings['domain'] = $domainSetting->setting_value;
        }

        $fromEmailSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_from_email']);
        if ($fromEmailSetting) {
            $settings['from_email'] = $fromEmailSetting->setting_value;
        }

        $fromNameSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_from_name']);
        if ($fromNameSetting) {
            $settings['from_name'] = $fromNameSetting->setting_value;
        }

        $endpointSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['mailgun_endpoint']);
        if ($endpointSetting) {
            $settings['endpoint'] = $endpointSetting->setting_value;
        }

        return $settings;
    }

    /**
     * Save a setting
     */
    private function saveSetting(string $key, string $value): void {
        $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', [$key]);
        if (!$setting) {
            $setting = Bean::dispense('enterprisesettings');
            $setting->setting_key = $key;
        }
        $setting->setting_value = $value;
        $setting->updated_at = date('Y-m-d H:i:s');
        Bean::store($setting);
    }

    /**
     * Delete a setting
     */
    private function deleteSetting(string $key): void {
        $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', [$key]);
        if ($setting) {
            Bean::trash($setting);
        }
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
