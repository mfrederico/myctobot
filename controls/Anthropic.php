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
require_once __DIR__ . '/../lib/Bean.php';

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
                    $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);
                    if (!$setting) {
                        $setting = R::dispense('enterprisesettings');
                        $setting->setting_key = 'anthropic_api_key';
                        $setting->member_id = $memberId;
                    }
                    $setting->setting_value = $encrypted;
                    $setting->is_encrypted = 1;
                    $setting->updated_at = date('Y-m-d H:i:s');
                    R::store($setting);

                    // Clear any credit balance error
                    $creditError = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
                    if ($creditError) {
                        R::trash($creditError);
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

        // Get current status
        $apiKeySetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);
        $apiKeySet = $apiKeySetting && !empty($apiKeySetting->setting_value);

        // Check for credit balance warnings
        $creditSetting = R::findOne('enterprisesettings',
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
            $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);

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
            $creditError = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
            if ($creditError) {
                R::trash($creditError);
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
            $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $memberId]);
            if ($setting) {
                R::trash($setting);
            }

            // Also remove credit balance error
            $creditError = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
            if ($creditError) {
                R::trash($creditError);
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
    public function clearWarning() {
        if (!$this->requireLogin()) return;

        $memberId = $this->member->id;

        try {
            $creditError = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $memberId]);
            if ($creditError) {
                R::trash($creditError);
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
     * List all API keys
     */
    public function keys() {
        if (!$this->requireEnterprise()) return;

        $keys = [];
        $keyBeans = Bean::findAll('anthropickeys', ' ORDER BY created_at DESC ');
        foreach ($keyBeans as $key) {
            $decrypted = EncryptionService::decrypt($key->api_key);
            $keys[] = [
                'id' => $key->id,
                'name' => $key->name,
                'model' => $key->model,
                'masked_key' => $this->maskAnthropicKey($decrypted),
                'created_at' => $key->created_at
            ];
        }

        $this->render('anthropic/keys', [
            'title' => 'API Keys',
            'keys' => $keys
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
            $key->name = $name;
            $key->api_key = $encrypted;
            $key->model = $model;
            $key->created_at = date('Y-m-d H:i:s');
            Bean::store($key);

            $this->flash('success', 'API key added successfully.');
            $this->logger->info('Anthropic API key added', ['member_id' => $this->member->id, 'name' => $name]);

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to save API key: ' . $e->getMessage());
        }

        Flight::redirect('/anthropic/keys');
    }

    /**
     * Delete an API key
     */
    public function deletekey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $params['operation']->name ?? null;
        if (!$keyId) {
            $this->flash('error', 'No key specified.');
            Flight::redirect('/anthropic/keys');
            return;
        }

        try {
            $key = Bean::load('anthropickeys', $keyId);
            if ($key && $key->id) {
                $keyName = $key->name;
                Bean::trash($key);

                // Reset any boards using this key to NULL (local runner)
                Bean::exec('UPDATE jiraboards SET aidev_anthropic_key_id = NULL WHERE aidev_anthropic_key_id = ?', [$keyId]);

                $this->flash('success', "API key '{$keyName}' deleted. Affected boards switched to local runner.");
                $this->logger->info('Anthropic API key deleted', ['member_id' => $this->member->id, 'key_id' => $keyId]);
            } else {
                $this->flash('error', 'Key not found.');
            }
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to delete key: ' . $e->getMessage());
        }

        Flight::redirect('/anthropic/keys');
    }

    /**
     * Test an API key
     */
    public function testkey($params = []) {
        if (!$this->requireEnterprise()) return;

        $keyId = $params['operation']->name ?? null;
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

    /**
     * Mask an Anthropic API key for display
     */
    private function maskAnthropicKey(string $key): string {
        if (empty($key)) return '(empty)';

        if (preg_match('/^(sk-ant-api\d+-)(.+)$/', $key, $matches)) {
            $prefix = $matches[1];
            $secret = $matches[2];
            $secretLen = strlen($secret);
            if ($secretLen > 7) {
                return $prefix . substr($secret, 0, 3) . '...' . substr($secret, -4);
            }
            return $prefix . '***';
        }

        return substr($key, 0, 10) . '...' . substr($key, -4);
    }
}
