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

class Anthropic extends BaseControls\Control {

    /**
     * Check Enterprise tier access
     */
    private function requireEnterprise(): bool {
        if (!$this->requireLogin()) return false;

        $tier = $this->member->getTier();
        if (!TierFeatures::hasFeature($tier, TierFeatures::FEATURE_AI_DEVELOPER)) {
            $this->flash('error', 'Anthropic API configuration requires an Enterprise subscription.');
            Flight::redirect('/settings/subscription');
            return false;
        }

        return true;
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
}
