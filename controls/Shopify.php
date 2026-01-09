<?php
/**
 * Shopify Controller
 * Handles Shopify integration using Admin API access tokens (shpat_*)
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\TierFeatures;
use \app\services\ShopifyClient;

require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/ShopifyClient.php';

class Shopify extends BaseControls\Control {

    /**
     * Check access - all features now available to logged-in users
     */
    private function requireEnterprise(): bool {
        return $this->requireLogin();
    }

    /**
     * Shopify settings/configuration page
     */
    public function index() {
        if (!$this->requireEnterprise()) return;

        $shopify = new ShopifyClient($this->member->id);

        // Handle form submission
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;

            $shop = trim(Flight::request()->data->shopify_shop ?? '');
            $accessToken = trim(Flight::request()->data->shopify_access_token ?? '');

            if (empty($shop) || empty($accessToken)) {
                $this->flash('error', 'Shop domain and access token are required.');
                Flight::redirect('/shopify');
                return;
            }

            try {
                $shopify->saveCredentials($shop, $accessToken);

                // Test the connection
                $testResult = $shopify->testConnection();
                if ($testResult['success']) {
                    $this->flash('success', 'Shopify connected successfully! ' . $testResult['message']);
                } else {
                    $this->flash('warning', 'Credentials saved but connection test failed: ' . $testResult['message']);
                }

                $this->logger->info('Shopify credentials saved', ['member_id' => $this->member->id]);
            } catch (Exception $e) {
                $this->flash('error', 'Failed to save credentials: ' . $e->getMessage());
            }

            Flight::redirect('/shopify');
            return;
        }

        // Get current status
        $isConnected = $shopify->isConnected();
        $shop = $shopify->getShop();
        $connectionDetails = $shopify->getConnectionDetails();
        $themes = [];

        if ($isConnected) {
            try {
                $themes = $shopify->getThemes();
            } catch (Exception $e) {
                $this->logger->warning('Failed to fetch Shopify themes', ['error' => $e->getMessage()]);
            }
        }

        $this->render('shopify/index', [
            'title' => 'Shopify Integration',
            'isConnected' => $isConnected,
            'shop' => $shop,
            'connectionDetails' => $connectionDetails,
            'themes' => $themes
        ]);
    }

    /**
     * Test Shopify connection (AJAX)
     */
    public function test() {
        if (!$this->requireEnterprise()) return;

        $shopify = new ShopifyClient($this->member->id);
        $result = $shopify->testConnection();

        $this->json($result);
    }

    /**
     * Disconnect Shopify (removes all credentials)
     */
    public function disconnect() {
        if (!$this->requireEnterprise()) return;

        try {
            $shopify = new ShopifyClient($this->member->id);
            $shopify->disconnect();

            $this->flash('success', 'Shopify disconnected.');
            $this->logger->info('Shopify disconnected', ['member_id' => $this->member->id]);

        } catch (Exception $e) {
            $this->logger->error('Failed to disconnect Shopify', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to disconnect Shopify.');
        }

        Flight::redirect('/shopify');
    }
}
