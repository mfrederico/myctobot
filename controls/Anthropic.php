<?php
/**
 * Anthropic Controller
 * Handles Anthropic API key management for Enterprise tier
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\TierFeatures;
use \app\services\EncryptionService;

require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/EncryptionService.php';
use \app\Bean;

class Anthropic extends BaseControls\Control {

    /**
     * Check access - all features now available to logged-in users
     */
    private function requireEnterprise(): bool {
        return $this->requireLogin();
    }

    /**
     * Anthropic API key settings page
     */
    public function index() {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;
        $apiKeySet = false;
        $creditBalanceError = null;

        // Handle form submission
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;

            $apiKey = trim(Flight::request()->data->anthropic_api_key ?? '');

            if (!empty($apiKey)) {
                // Validate API key format
                if (!preg_match('/^sk-ant-/', $apiKey)) {
                    $this->flash('error', 'Invalid API key format. Should start with sk-ant-');
                    Flight::redirect('/anthropic');
                    return;
                }

                try {
                    // Encrypt and store
                    $encrypted = EncryptionService::encrypt($apiKey);

                    // Find or create setting bean
                    $setting = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);
                    if (!$setting) {
                        $setting = Bean::dispense('enterprisesettings');
                        $setting->setting_key = 'anthropic_api_key';
                        $setting->member_id = $memberId;
                        $setting->is_shared = 0;
                    }
                    $setting->setting_value = $encrypted;
                    $setting->is_encrypted = 1;
                    $setting->updated_at = date('Y-m-d H:i:s');
                    Bean::store($setting);

                    // Clear any credit balance error
                    $creditError = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
                    if ($creditError) {
                        Bean::trash($creditError);
                    }

                    $this->flash('success', 'API key saved successfully.');
                    $this->logger->info('Anthropic API key updated', ['member_id' => $memberId]);

                } catch (Exception $e) {
                    $this->flash('error', 'Failed to save API key: ' . $e->getMessage());
                }
            }

            Flight::redirect('/anthropic');
            return;
        }

        // Get current status (include shared settings)
        $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ? AND (member_id = ? OR is_shared = 1)', ['anthropic_api_key', $memberId]);
        $apiKeySet = $apiKeySetting && !empty($apiKeySetting->setting_value);

        // Check for credit balance warnings
        $creditSetting = Bean::findOne('enterprisesettings',
            'setting_key = ? AND member_id = ? AND updated_at > ?',
            ['credit_balance_error', $memberId, date('Y-m-d H:i:s', strtotime('-24 hours'))]
        );
        if ($creditSetting) {
            $creditBalanceError = $creditSetting->setting_value;
        }

        $this->render('anthropic/index', [
            'title' => 'Anthropic API Configuration',
            'apiKeySet' => $apiKeySet,
            'creditBalanceError' => $creditBalanceError
        ]);
    }

    /**
     * Test API key (AJAX)
     */
    public function test() {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;
        $apiKey = null;

        try {
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ? AND (member_id = ? OR is_shared = 1)', ['anthropic_api_key', $memberId]);

            if (!$setting || empty($setting->setting_value)) {
                $this->json(['success' => false, 'error' => 'No API key configured']);
                return;
            }

            $apiKey = EncryptionService::decrypt($setting->setting_value);

            if (!$apiKey) {
                $this->json(['success' => false, 'error' => 'No API key configured']);
                return;
            }

            // Test the key with a simple request
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.anthropic.com',
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->post('/v1/messages', [
                'json' => [
                    'model' => 'claude-sonnet-4-20250514',
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hello']]
                ]
            ]);

            // Clear any credit balance warning since the key works
            $creditError = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
            if ($creditError) {
                Bean::trash($creditError);
            }

            $this->json(['success' => true, 'message' => 'API key is valid and working!']);

        } catch (Exception $e) {
            $this->logger->warning('Anthropic API key test failed', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => 'API key validation failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove API key
     */
    public function remove() {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;

        try {
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);
            if ($setting) {
                Bean::trash($setting);
            }

            // Also remove credit balance error
            $creditError = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
            if ($creditError) {
                Bean::trash($creditError);
            }

            $this->flash('success', 'Anthropic API key removed.');
            $this->logger->info('Anthropic API key removed', ['member_id' => $memberId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to remove Anthropic API key', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to remove API key.');
        }

        Flight::redirect('/anthropic');
    }

    /**
     * Clear credit balance warning (AJAX)
     */
    public function clearwarning() {
        if (!$this->requireLogin()) return;

        $memberId = $this->member->id;

        try {
            $creditError = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
            if ($creditError) {
                Bean::trash($creditError);
            }

            Flight::json(['success' => true]);
        } catch (\Exception $e) {
            Flight::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // Multi-Key Management (anthropickeys table)
    // ========================================

    /**
     * List all API keys visible to this member (owned + shared)
     */
    public function keys() {
        if (!$this->requireEnterprise()) return;

        $memberId = $this->member->id;
        $keys = [];

        // Get keys owned by member OR shared with workspace
        $keyBeans = Bean::find('anthropickeys',
            ' created_by_member_id = ? OR shared = 1 ORDER BY created_at DESC ',
            [$memberId]
        );

        foreach ($keyBeans as $key) {
            $decrypted = EncryptionService::decrypt($key->api_key);
            $isOwner = ($key->created_by_member_id == $memberId);
            $keys[] = [
                'id' => $key->id,
                'name' => $key->name,
                'model' => $key->model,
                'masked_key' => EncryptionService::maskAnthropicKey($decrypted),
                'shared' => (bool)$key->shared,
                'is_owner' => $isOwner,
                'owner_name' => $key->created_by_name,
                'created_at' => $key->created_at
            ];
        }

        $this->render('anthropic/keys', [
            'title' => 'API Keys',
            'keys' => $keys,
            'member_id' => $memberId
        ]);
    }

    /**
     * Add a new API key
     */
    public function addkey() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/anthropic/keys');
            return;
        }

        if (!$this->validateCSRF()) return;

        $name = trim(Flight::request()->data->key_name ?? '');
        $apiKey = trim(Flight::request()->data->api_key ?? '');
        $model = Flight::request()->data->key_model ?? 'claude-sonnet-4-20250514';
        $shared = !empty(Flight::request()->data->shared);

        if (empty($name) || empty($apiKey)) {
            $this->flash('error', 'Name and API key are required.');
            Flight::redirect('/anthropic/keys');
            return;
        }

        if (!preg_match('/^sk-ant-/', $apiKey)) {
            $this->flash('error', 'Invalid API key format. Should start with sk-ant-');
            Flight::redirect('/anthropic/keys');
            return;
        }

        try {
            $encrypted = EncryptionService::encrypt($apiKey);

            $key = Bean::dispense('anthropickeys');
            $key->created_by_member_id = $this->member->id;
            $key->created_by_name = $this->member->display_name ?? $this->member->email;
            $key->name = $name;
            $key->api_key = $encrypted;
            $key->model = $model;
            $key->shared = $shared ? 1 : 0;
            $key->created_at = date('Y-m-d H:i:s');
            Bean::store($key);

            $this->flash('success', 'API key added successfully.');
            $this->logger->info('Anthropic API key added', ['member_id' => $this->member->id, 'name' => $name, 'shared' => $shared]);

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to save API key: ' . $e->getMessage());
        }

        Flight::redirect('/anthropic/keys');
    }

    /**
     * Delete an API key (owner only)
     */
    public function deletekey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $this->opId() ?? null;
        if (!$keyId) {
            $this->flash('error', 'No key specified.');
            Flight::redirect('/anthropic/keys');
            return;
        }

        try {
            $key = Bean::load('anthropickeys', $keyId);
            if (!$key || !$key->id) {
                $this->flash('error', 'Key not found.');
                Flight::redirect('/anthropic/keys');
                return;
            }

            // Only owner can delete
            if ($key->created_by_member_id != $this->member->id) {
                $this->flash('error', 'You can only delete your own API keys.');
                Flight::redirect('/anthropic/keys');
                return;
            }

            $keyName = $key->name;
            Bean::trash($key);

            // Reset any boards using this key to NULL (local runner)
            Bean::exec('UPDATE jiraboards SET aidev_anthropic_key_id = NULL WHERE aidev_anthropic_key_id = ?', [$keyId]);

            $this->flash('success', "API key '{$keyName}' deleted. Affected boards switched to local runner.");
            $this->logger->info('Anthropic API key deleted', ['member_id' => $this->member->id, 'key_id' => $keyId]);

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to delete key: ' . $e->getMessage());
        }

        Flight::redirect('/anthropic/keys');
    }

    /**
     * Toggle sharing for an API key (AJAX, owner only)
     */
    public function toggleshare($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $this->opId() ?? $this->getParam('id');
        if (!$keyId) {
            $this->json(['success' => false, 'message' => 'No key specified']);
            return;
        }

        try {
            $key = Bean::load('anthropickeys', $keyId);
            if (!$key || !$key->id) {
                $this->json(['success' => false, 'message' => 'Key not found']);
                return;
            }

            // Only owner can change sharing
            if ($key->created_by_member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'You can only modify your own API keys']);
                return;
            }

            // Toggle shared status
            $key->shared = $key->shared ? 0 : 1;
            $key->updated_at = date('Y-m-d H:i:s');
            Bean::store($key);

            $status = $key->shared ? 'shared with workspace' : 'private';
            $this->json(['success' => true, 'message' => "Key is now {$status}", 'shared' => (bool)$key->shared]);

        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Test an API key
     */
    public function testkey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $this->opId() ?? null;
        if (!$keyId) {
            Flight::json(['success' => false, 'error' => 'No key specified']);
            return;
        }

        try {
            $key = Bean::load('anthropickeys', $keyId);
            if (!$key || !$key->id) {
                Flight::json(['success' => false, 'error' => 'Key not found']);
                return;
            }

            $apiKey = EncryptionService::decrypt($key->api_key);
            $model = $key->model ?? 'claude-sonnet-4-20250514';

            // Test the key
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.anthropic.com',
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->post('/v1/messages', [
                'json' => [
                    'model' => $model,
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hi']]
                ]
            ]);

            Flight::json(['success' => true, 'message' => 'API key is valid!']);

        } catch (\Exception $e) {
            $this->logger->warning('Anthropic API key test failed', ['error' => $e->getMessage()]);
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
