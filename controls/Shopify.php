<?php
/**
 * Shopify Controller
 * Handles multi-store Shopify integration using Admin API access tokens (shpat_*)
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\TierFeatures;
use \app\services\ShopifyClient;
use \app\Bean;

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
     * Shopify stores list and management page
     */
    public function index() {
        if (!$this->requireEnterprise()) return;

        // Get Shopify connections visible to this member (owned + shared)
        $connections = ShopifyClient::getAllConnections($this->member->id);

        // Get available repos for linking
        $repos = Bean::findAll('repoconnections', ' enabled = 1 ORDER BY repo_name ASC ');

        $this->render('shopify/index', [
            'title' => 'Shopify Stores',
            'connections' => $connections,
            'repos' => $repos,
            'member_id' => $this->member->id
        ]);
    }

    /**
     * Add a new Shopify store (POST)
     */
    public function add() {
        if (!$this->requireEnterprise()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/shopify');
            return;
        }

        if (!$this->validateCSRF()) return;

        $shop = trim(Flight::request()->data->shop_domain ?? '');
        $accessToken = trim(Flight::request()->data->access_token ?? '');
        $connectionName = trim(Flight::request()->data->connection_name ?? '');
        $shared = !empty(Flight::request()->data->shared);

        if (empty($shop) || empty($accessToken)) {
            $this->flash('error', 'Shop domain and access token are required.');
            Flight::redirect('/shopify');
            return;
        }

        try {
            $conn = ShopifyClient::createConnection(
                $this->member->id,
                $this->member->display_name ?? $this->member->email,
                $shop,
                $accessToken,
                $connectionName ?: null,
                $shared
            );

            // Test the connection
            $testResult = ShopifyClient::testConnectionById($conn->id);
            if ($testResult['success']) {
                $this->flash('success', 'Shopify store connected successfully! ' . $testResult['message']);
            } else {
                $this->flash('warning', 'Store saved but connection test failed: ' . $testResult['message']);
            }

            $this->logger->info('Shopify store connected', [
                'member_id' => $this->member->id,
                'shop' => $conn->shop_domain,
                'connection_id' => $conn->id
            ]);

        } catch (Exception $e) {
            $this->flash('error', 'Failed to connect store: ' . $e->getMessage());
            $this->logger->error('Shopify connection failed', ['error' => $e->getMessage()]);
        }

        Flight::redirect('/shopify');
    }

    /**
     * Test a Shopify connection (AJAX)
     */
    public function test($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $result = ShopifyClient::testConnectionById((int)$connectionId);
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Disconnect a Shopify store
     */
    public function disconnect($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->flash('error', 'No connection specified.');
            Flight::redirect('/shopify');
            return;
        }

        try {
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->flash('error', 'Connection not found.');
                Flight::redirect('/shopify');
                return;
            }

            // Only owner can disconnect
            if ($conn->created_by_member_id != $this->member->id) {
                $this->flash('error', 'You can only disconnect your own stores.');
                Flight::redirect('/shopify');
                return;
            }

            $shopDomain = $conn->shop_domain;
            $success = ShopifyClient::deleteConnection((int)$connectionId);

            if ($success) {
                $this->flash('success', "Disconnected {$shopDomain}");
                $this->logger->info('Shopify store disconnected', [
                    'member_id' => $this->member->id,
                    'shop' => $shopDomain,
                    'connection_id' => $connectionId
                ]);
            } else {
                $this->flash('error', 'Failed to disconnect store.');
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to disconnect Shopify', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to disconnect: ' . $e->getMessage());
        }

        Flight::redirect('/shopify');
    }

    /**
     * Link a Shopify store to a repo (AJAX)
     */
    public function linkrepo($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');
        $repoId = $this->getParam('repo_id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        if (empty($repoId)) {
            $this->json(['success' => false, 'message' => 'No repo specified']);
            return;
        }

        try {
            $conn = ShopifyClient::linkRepo((int)$connectionId, (int)$repoId);

            // Get repo name for message
            $repo = Bean::load('repoconnections', (int)$repoId);
            $repoName = $repo->id ? "{$repo->repo_owner}/{$repo->repo_name}" : 'unknown';

            $this->logger->info('Shopify store linked to repo', [
                'connection_id' => $connectionId,
                'shop' => $conn->shop_domain,
                'repo_id' => $repoId,
                'repo' => $repoName
            ]);

            $this->json([
                'success' => true,
                'message' => "Linked to {$repoName}",
                'repo_name' => $repoName
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Unlink a Shopify store from its repo (AJAX)
     */
    public function unlinkrepo($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::unlinkRepo((int)$connectionId);

            $this->logger->info('Shopify store unlinked from repo', [
                'connection_id' => $connectionId,
                'shop' => $conn->shop_domain
            ]);

            $this->json([
                'success' => true,
                'message' => 'Unlinked from repo'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Update a Shopify connection (AJAX)
     */
    public function update($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            // Only owner can update connection
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn || $conn->created_by_member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'You can only update your own connections']);
                return;
            }

            $data = [];

            // Collect updateable fields from request
            $connectionName = $this->getParam('connection_name');
            if ($connectionName !== null) {
                $data['connection_name'] = $connectionName;
            }

            $storefrontPassword = $this->getParam('storefront_password');
            if ($storefrontPassword !== null) {
                $data['storefront_password'] = $storefrontPassword;
            }

            $verifyPlaywright = $this->getParam('verify_with_playwright');
            if ($verifyPlaywright !== null) {
                $data['verify_with_playwright'] = (int)$verifyPlaywright;
            }

            $enabled = $this->getParam('enabled');
            if ($enabled !== null) {
                $data['enabled'] = (int)$enabled;
            }

            $shared = $this->getParam('shared');
            if ($shared !== null) {
                $data['shared'] = (int)$shared;
            }

            $accessToken = $this->getParam('access_token');
            if (!empty($accessToken)) {
                $data['access_token'] = $accessToken;
            }

            if (empty($data)) {
                $this->json(['success' => false, 'message' => 'No fields to update']);
                return;
            }

            $conn = ShopifyClient::updateConnection((int)$connectionId, $data);

            $this->json([
                'success' => true,
                'message' => 'Connection updated'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Get themes for a store (AJAX)
     */
    public function themes($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified', 'themes' => []]);
            return;
        }

        try {
            $client = new ShopifyClient((int)$connectionId);
            $themes = $client->getThemes();

            $this->json([
                'success' => true,
                'themes' => $themes
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage(), 'themes' => []]);
        }
    }
}
