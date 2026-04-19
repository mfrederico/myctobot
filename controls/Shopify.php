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
use \app\services\ShopifyCrawlerService;
use \app\services\EncryptionService;
use \app\Bean;

require_once __DIR__ . '/../services/TierFeatures.php';
require_once __DIR__ . '/../services/ShopifyClient.php';
require_once __DIR__ . '/../services/ShopifyCrawlerService.php';
require_once __DIR__ . '/../services/EncryptionService.php';

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
                'shop' => $conn->external_eid,
                'connection_id' => $conn->id
            ]);

        } catch (Exception $e) {
            $this->flash('error', 'Failed to connect store: ' . $e->getMessage());
            $this->logger->error('Shopify connection failed', ['error' => $e->getMessage()]);
        }

        Flight::redirect('/shopify');
    }

    // ========================================
    // OAuth Flow Methods (delegates to generic Connections controller)
    // ========================================

    /**
     * Start Shopify OAuth flow - delegates to generic Connections controller
     */
    public function connect() {
        $shop = Flight::request()->query->shop ?? Flight::request()->data->shop ?? '';
        $query = trim($shop) ? '?shop=' . urlencode(trim($shop)) : '';
        Flight::redirect('/connections/connect/shopify' . $query);
    }

    /**
     * Handle Shopify OAuth callback - forwards to generic Connections controller
     */
    public function callback() {
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        Flight::redirect('/connections/callback/shopify' . ($queryString ? '?' . $queryString : ''));
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
            if ($conn->member_id != $this->member->id) {
                $this->flash('error', 'You can only disconnect your own stores.');
                Flight::redirect('/shopify');
                return;
            }

            $shopDomain = $conn->external_eid;
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
                'shop' => $conn->external_eid,
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
                'shop' => $conn->external_eid
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
            if (!$conn || $conn->member_id != $this->member->id) {
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

    /**
     * Test a GraphQL query against a Shopify store (AJAX)
     * Used by pipeline editor to validate queries before saving
     */
    public function testquery($params = []) {
        if (!$this->requireEnterprise()) return;

        // Accept JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            // Fall back to form data
            $input = [
                'connection_id' => $this->getParam('connection_id'),
                'query' => $this->getParam('query'),
                'variables' => $this->getParam('variables')
            ];
        }

        $connectionId = $input['connection_id'] ?? null;
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? [];

        if (empty($connectionId)) {
            $this->json(['success' => false, 'error' => 'No connection specified']);
            return;
        }

        if (empty(trim($query))) {
            $this->json(['success' => false, 'error' => 'No query provided']);
            return;
        }

        // Ensure variables is an array
        if (is_string($variables)) {
            $variables = json_decode($variables, true) ?? [];
        }

        try {
            // Verify user has access to this connection
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'error' => 'Connection not found']);
                return;
            }

            // Check access: owner or shared connection
            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->json(['success' => false, 'error' => 'Access denied to this connection']);
                return;
            }

            $client = new ShopifyClient((int)$connectionId);
            $result = $client->graphql($query, $variables);

            // Check for GraphQL errors in the response
            if (isset($result['errors']) && !empty($result['errors'])) {
                $this->json([
                    'success' => false,
                    'error' => 'GraphQL query returned errors',
                    'errors' => $result['errors'],
                    'data' => $result['data'] ?? null
                ]);
                return;
            }

            $this->json([
                'success' => true,
                'data' => $result['data'] ?? $result
            ]);

        } catch (Exception $e) {
            $this->logger->error('Shopify GraphQL test query failed', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // CRAWLER METHODS - For RAG indexing of Shopify stores
    // =========================================================================

    /**
     * Get crawler configuration and status (AJAX)
     */
    public function crawlerconfig($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Check access
            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            // Get crawler config from metadata_json
            $metadata = json_decode($conn->metadata_json ?: '{}', true);

            // Get crawler status if exists
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $status = null;
            try {
                $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);
                $status = $crawler->getStatus();
            } catch (Exception $e) {
                // Crawler not initialized yet
            }

            $this->json([
                'success' => true,
                'config' => [
                    'has_crawler_auth' => !empty($metadata['crawler_signature']),
                    'has_signature' => !empty($metadata['crawler_signature']),
                    'has_signature_input' => !empty($metadata['crawler_signature_input']),
                    'signature_agent' => $metadata['crawler_signature_agent'] ?? '"https://shopify.com"',
                    'primary_domain' => $metadata['primary_domain'] ?? ''
                ],
                'status' => $status
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Save crawler authentication config (AJAX)
     */
    public function savecrawlerconfig($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Only owner can update
            if ($conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Only the connection owner can update crawler config']);
                return;
            }

            // Get crawler auth from request
            $signature = trim($this->getParam('crawler_signature') ?? '');
            $signatureInput = trim($this->getParam('crawler_signature_input') ?? '');
            $signatureAgent = trim($this->getParam('crawler_signature_agent') ?? '"https://shopify.com"');
            $primaryDomain = trim($this->getParam('primary_domain') ?? '');

            // Store crawler config in metadata_json
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            if (!empty($signature)) {
                $metadata['crawler_signature'] = EncryptionService::encrypt($signature);
            }
            if (!empty($signatureInput)) {
                $metadata['crawler_signature_input'] = EncryptionService::encrypt($signatureInput);
            }
            $metadata['crawler_signature_agent'] = $signatureAgent;
            $metadata['primary_domain'] = $primaryDomain;
            $conn->metadata_json = json_encode($metadata);
            $conn->updated_at = date('Y-m-d H:i:s');

            Bean::store($conn);

            $this->logger->info('Shopify crawler config saved', [
                'connection_id' => $connectionId,
                'shop' => $conn->external_eid,
                'has_signature' => !empty($signature)
            ]);

            $this->json([
                'success' => true,
                'message' => 'Crawler configuration saved'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Save Shopify Partners deployment credentials (AJAX)
     */
    public function savepartnersconfig($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Only owner can update
            if ($conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Only the connection owner can update Partners config']);
                return;
            }

            // Get Partners credentials from request
            $cliToken = trim($this->getParam('cli_token') ?? '');
            $clientId = trim($this->getParam('client_id') ?? '');
            $orgId = trim($this->getParam('org_id') ?? '');

            if (empty($cliToken)) {
                $this->json(['success' => false, 'message' => 'CLI token is required']);
                return;
            }

            // Store Partners config in metadata_json
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $metadata['shopify_cli_token'] = EncryptionService::encrypt($cliToken);
            if (!empty($clientId)) {
                $metadata['shopify_client_id'] = $clientId;
            } else {
                unset($metadata['shopify_client_id']);
            }
            if (!empty($orgId)) {
                $metadata['shopify_org_id'] = $orgId;
            } else {
                unset($metadata['shopify_org_id']);
            }
            $conn->metadata_json = json_encode($metadata);
            $conn->updated_at = date('Y-m-d H:i:s');

            Bean::store($conn);

            $this->logger->info('Shopify Partners config saved', [
                'connection_id' => $connectionId,
                'shop' => $conn->external_eid,
                'has_client_id' => !empty($clientId),
                'has_org_id' => !empty($orgId)
            ]);

            $this->json([
                'success' => true,
                'message' => 'Partners configuration saved'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Start URL discovery from sitemap (AJAX)
     */
    public function crawldiscover($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);

            $result = $crawler->discoverUrls();
            $this->json($result);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Crawl pending pages (AJAX)
     */
    public function crawlpages($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');
        $maxPages = (int)($this->getParam('max_pages') ?? 10);
        $pageTypes = $this->getParam('page_types');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        // Parse page types if string
        if (is_string($pageTypes) && !empty($pageTypes)) {
            $pageTypes = array_map('trim', explode(',', $pageTypes));
        } elseif (!is_array($pageTypes)) {
            $pageTypes = [];
        }

        try {
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);

            $result = $crawler->crawlPages($maxPages, $pageTypes);
            $this->json($result);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get crawl status (AJAX)
     */
    public function crawlstatus($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);

            $this->json([
                'success' => true,
                'status' => $crawler->getStatus(),
                'has_auth' => $crawler->hasAuthConfig()
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get crawled pages list (AJAX)
     */
    public function crawledpages($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');
        $status = $this->getParam('status') ?? null;
        $pageType = $this->getParam('page_type') ?? null;
        $limit = (int)($this->getParam('limit') ?? 50);
        $offset = (int)($this->getParam('offset') ?? 0);

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);

            $filters = [];
            if ($status) $filters['status'] = $status;
            if ($pageType) $filters['page_type'] = $pageType;

            $pages = $crawler->getPages($filters, $limit, $offset);

            $this->json([
                'success' => true,
                'pages' => $pages,
                'count' => count($pages)
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Search crawled content (AJAX)
     */
    public function crawlsearch($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');
        $query = trim($this->getParam('q') ?? '');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        if (empty($query)) {
            $this->json(['success' => false, 'message' => 'Search query required']);
            return;
        }

        try {
            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);

            $results = $crawler->search($query);

            $this->json([
                'success' => true,
                'results' => $results,
                'query' => $query,
                'count' => count($results)
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reset crawler data (AJAX)
     */
    public function crawlreset($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            // Check ownership
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn || $conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Only the connection owner can reset crawler data']);
                return;
            }

            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            $crawler = ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);

            $crawler->reset();

            $this->logger->info('Shopify crawler reset', [
                'connection_id' => $connectionId,
                'shop' => $conn->external_eid
            ]);

            $this->json([
                'success' => true,
                'message' => 'Crawler data reset successfully'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Index products/collections/pages from Shopify Admin API into knowledge store.
     * Bypasses HTML crawling — works even for password-protected stores.
     */
    public function crawlapi($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');
        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $client = new ShopifyClient((int)$connectionId);
            if (!$client->isConnected()) {
                $this->json(['success' => false, 'message' => 'Shopify connection not active']);
                return;
            }

            $workspace = $_SESSION['workspace_slug'] ?? 'default';
            require_once __DIR__ . '/../services/AssistKnowledgeStore.php';
            $store = \app\services\AssistKnowledgeStore::forWorkspace($workspace);
            $storeDomain = $client->getShop();
            $baseUrl = "https://{$storeDomain}";
            $indexed = 0;

            // --- Products ---
            $productsQuery = <<<'GQL'
            query($first: Int!, $after: String) {
                products(first: $first, after: $after, query: "status:active") {
                    edges {
                        node {
                            handle title description productType status totalInventory tags
                            priceRange { minVariantPrice { amount currencyCode } maxVariantPrice { amount currencyCode } }
                            variants(first: 10) { edges { node { title price sku availableForSale } } }
                        }
                        cursor
                    }
                    pageInfo { hasNextPage }
                }
            }
            GQL;

            $after = null;
            do {
                $vars = ['first' => 50, 'after' => $after];
                $result = $client->graphql($productsQuery, $vars);
                if (!$result['success']) break;

                $edges = $result['data']['products']['edges'] ?? [];
                foreach ($edges as $edge) {
                    $p = $edge['node'];
                    $after = $edge['cursor'];
                    $url = "{$baseUrl}/products/{$p['handle']}";
                    $minPrice = $p['priceRange']['minVariantPrice']['amount'] ?? '';
                    $maxPrice = $p['priceRange']['maxVariantPrice']['amount'] ?? '';
                    $currency = $p['priceRange']['minVariantPrice']['currencyCode'] ?? 'USD';
                    $priceStr = ($minPrice === $maxPrice) ? "{$currency} {$minPrice}" : "{$currency} {$minPrice} - {$maxPrice}";

                    $variants = [];
                    foreach (($p['variants']['edges'] ?? []) as $ve) {
                        $v = $ve['node'];
                        $variants[] = $v['title'] . " ({$currency} {$v['price']}" . ($v['availableForSale'] ? '' : ', out of stock') . ")";
                    }

                    $content = "Product: {$p['title']}\n";
                    $content .= "URL: {$url}\n";
                    $content .= "Price: {$priceStr}\n";
                    if ($p['productType']) $content .= "Type: {$p['productType']}\n";
                    if ($p['totalInventory'] !== null) $content .= "Inventory: {$p['totalInventory']} units\n";
                    if (!empty($p['tags'])) $content .= "Tags: " . implode(', ', $p['tags']) . "\n";
                    if (!empty($variants)) $content .= "Variants: " . implode('; ', $variants) . "\n";
                    if ($p['description']) $content .= "\n{$p['description']}";

                    $store->upsertEntry('page_definition', $p['title'], trim($content), [
                        'page' => $url,
                        'metadata' => [
                            'url' => $url,
                            'page_type' => 'product',
                            'source' => 'shopify_crawler',
                            'shop_domain' => $storeDomain,
                            'price' => $priceStr,
                            'product_type' => $p['productType'],
                            'indexed_at' => date('Y-m-d H:i:s'),
                        ]
                    ]);
                    $indexed++;
                }
                $hasNext = $result['data']['products']['pageInfo']['hasNextPage'] ?? false;
            } while ($hasNext);

            // --- Collections ---
            $collectionsQuery = <<<'GQL'
            query($first: Int!) {
                collections(first: $first) {
                    edges {
                        node {
                            handle title description
                            productsCount { count }
                        }
                    }
                }
            }
            GQL;

            $result = $client->graphql($collectionsQuery, ['first' => 50]);
            if ($result['success']) {
                foreach (($result['data']['collections']['edges'] ?? []) as $edge) {
                    $c = $edge['node'];
                    $url = "{$baseUrl}/collections/{$c['handle']}";
                    $count = $c['productsCount']['count'] ?? 0;

                    $content = "Collection: {$c['title']}\n";
                    $content .= "URL: {$url}\n";
                    $content .= "Products: {$count} items\n";
                    if ($c['description']) $content .= "\n{$c['description']}";

                    $store->upsertEntry('page_definition', $c['title'], trim($content), [
                        'page' => $url,
                        'metadata' => [
                            'url' => $url,
                            'page_type' => 'collection',
                            'source' => 'shopify_crawler',
                            'shop_domain' => $storeDomain,
                            'product_count' => $count,
                            'indexed_at' => date('Y-m-d H:i:s'),
                        ]
                    ]);
                    $indexed++;
                }
            }

            // --- Pages ---
            $pagesQuery = <<<'GQL'
            query($first: Int!) {
                pages(first: $first) {
                    edges {
                        node {
                            handle title bodySummary
                        }
                    }
                }
            }
            GQL;

            $result = $client->graphql($pagesQuery, ['first' => 50]);
            if ($result['success']) {
                foreach (($result['data']['pages']['edges'] ?? []) as $edge) {
                    $pg = $edge['node'];
                    $url = "{$baseUrl}/pages/{$pg['handle']}";

                    $content = "Page: {$pg['title']}\n";
                    $content .= "URL: {$url}\n";
                    if ($pg['bodySummary']) $content .= "\n{$pg['bodySummary']}";

                    $store->upsertEntry('page_definition', $pg['title'], trim($content), [
                        'page' => $url,
                        'metadata' => [
                            'url' => $url,
                            'page_type' => 'page',
                            'source' => 'shopify_crawler',
                            'shop_domain' => $storeDomain,
                            'indexed_at' => date('Y-m-d H:i:s'),
                        ]
                    ]);
                    $indexed++;
                }
            }

            $this->json([
                'success' => true,
                'indexed' => $indexed,
                'message' => "Indexed {$indexed} items from Shopify Admin API"
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // CUSTOMER SUPPORT WIDGET METHODS
    // =========================================================================

    /**
     * Link a support pipeline to the Shopify connection (AJAX)
     */
    public function linksupportpipeline($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');
        $pipelineId = $this->getParam('pipeline_id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Only owner can update
            if ($conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Only the connection owner can configure support']);
                return;
            }

            // Validate pipeline exists if provided
            if (!empty($pipelineId)) {
                $pipeline = Bean::load('pipelines', (int)$pipelineId);
                if (!$pipeline->id) {
                    $this->json(['success' => false, 'message' => 'Pipeline not found']);
                    return;
                }
            }

            // Store pipeline ID in metadata_json
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $metadata['pipelines_id'] = !empty($pipelineId) ? (int)$pipelineId : null;
            $conn->metadata_json = json_encode($metadata);
            $conn->updated_at = date('Y-m-d H:i:s');
            Bean::store($conn);

            $this->logger->info('Support pipeline linked', [
                'connection_id' => $connectionId,
                'shop' => $conn->external_eid,
                'pipeline_id' => $pipelineId
            ]);

            $this->json([
                'success' => true,
                'message' => $pipelineId ? 'Support pipeline linked' : 'Support pipeline unlinked'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Generate a public widget token for the Shopify connection (AJAX)
     */
    public function generatewidgettoken($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = $this->opId() ?? $this->getParam('id');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection((int)$connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Only owner can update
            if ($conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Only the connection owner can generate tokens']);
                return;
            }

            // Generate a new token and store in metadata_json
            $token = 'wgt_' . bin2hex(random_bytes(16));

            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $metadata['public_agent_token'] = $token;
            $conn->metadata_json = json_encode($metadata);
            $conn->updated_at = date('Y-m-d H:i:s');
            Bean::store($conn);

            $this->logger->info('Widget token generated', [
                'connection_id' => $connectionId,
                'shop' => $conn->external_eid
            ]);

            $this->json([
                'success' => true,
                'token' => $token,
                'message' => 'Widget token generated'
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * POST /shopify/publishwidget/{id}?action=install|uninstall
     * Install or uninstall the widget script tag on Shopify
     */
    public function publishwidget($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);
        $action = $this->getParam('action', 'install');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection($connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Only owner can publish
            if ($conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Only the connection owner can publish widgets']);
                return;
            }

            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $token = $metadata['public_agent_token'] ?? '';
            $pipelineId = $metadata['pipelines_id'] ?? null;

            if ($action === 'install') {
                if (empty($token)) {
                    $this->json(['success' => false, 'message' => 'Generate a widget token first']);
                    return;
                }
                if (empty($pipelineId)) {
                    $this->json(['success' => false, 'message' => 'Select a support pipeline first']);
                    return;
                }

                $baseUrl = 'https://' . $_SERVER['HTTP_HOST'];
                $client = new ShopifyClient($connectionId);
                $result = $client->syncWidgetScriptTag($token, $baseUrl);

                // Store installed flag in metadata
                $metadata['widget_script_tag_installed'] = true;
                $conn->metadata_json = json_encode($metadata);
                $conn->updated_at = date('Y-m-d H:i:s');
                Bean::store($conn);

                $this->logger->info('Widget script tag installed', [
                    'connection_id' => $connectionId,
                    'shop' => $conn->external_eid,
                    'action' => $result['action']
                ]);

                $this->json([
                    'success' => true,
                    'message' => 'Widget installed on Shopify',
                    'action' => $result['action'],
                    'script_tag' => $result['script_tag']
                ]);

            } elseif ($action === 'uninstall') {
                $client = new ShopifyClient($connectionId);
                $existingTag = $client->findWidgetScriptTag();

                if ($existingTag) {
                    $client->deleteScriptTag($existingTag['id']);
                }

                // Update metadata
                $metadata['widget_script_tag_installed'] = false;
                $conn->metadata_json = json_encode($metadata);
                $conn->updated_at = date('Y-m-d H:i:s');
                Bean::store($conn);

                $this->logger->info('Widget script tag uninstalled', [
                    'connection_id' => $connectionId,
                    'shop' => $conn->external_eid
                ]);

                $this->json([
                    'success' => true,
                    'message' => 'Widget removed from Shopify'
                ]);

            } else {
                $this->json(['success' => false, 'message' => 'Invalid action. Use install or uninstall.']);
            }

        } catch (Exception $e) {
            $this->logger->error('Widget publish failed', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /shopify/widgetstatus/{id}
     * Check whether the widget script tag is installed on Shopify
     */
    public function widgetstatus($params = []) {
        if (!$this->requireEnterprise()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = ShopifyClient::getConnection($connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Check access: owner or shared
            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $client = new ShopifyClient($connectionId);
            $scriptTag = $client->findWidgetScriptTag();

            $this->json([
                'success' => true,
                'installed' => $scriptTag !== null,
                'script_tag' => $scriptTag
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * App Proxy endpoint - receives requests from Shopify storefront
     * URL pattern: {shop}.myshopify.com/apps/myctobot/{path}
     * Proxied to: /shopify/proxy with HMAC signature
     *
     * This is a PUBLIC endpoint (no session) - authentication via Shopify HMAC
     */
    public function proxy($params = []) {
        // This is a stateless endpoint - don't require session
        header('Content-Type: application/json');

        try {
            // Get all query parameters
            $queryParams = $_GET;

            // Extract and validate HMAC
            $hmac = $queryParams['hmac'] ?? '';
            unset($queryParams['hmac']); // Remove hmac before validation

            // Validate HMAC signature
            if (!$this->validateShopifyHmac($queryParams, $hmac)) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid signature']);
                return;
            }

            // Extract shop domain
            $shop = $queryParams['shop'] ?? '';
            if (empty($shop)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing shop parameter']);
                return;
            }

            // Look up Shopify connection by shop domain
            $connection = Bean::findOne('shopifyconnections', 'shop = ?', [$shop]);
            if (!$connection || !$connection->id) {
                http_response_code(404);
                echo json_encode(['error' => 'Shop not found']);
                return;
            }

            // Parse the proxy path to determine which pipeline to call
            // Path comes from URL: /apps/myctobot/{path}
            $pathInfo = $queryParams['path_prefix'] ?? '';
            $path = trim($pathInfo, '/');

            // Map paths to pipeline slugs
            $pipelineMap = [
                'badges' => 'product-badges',
                'support' => 'customer-support',
                // Add more mappings as needed
            ];

            $pipelineSlug = $pipelineMap[$path] ?? null;
            if (!$pipelineSlug) {
                http_response_code(404);
                echo json_encode(['error' => 'Invalid proxy path']);
                return;
            }

            // Get request body (for POST requests)
            $requestBody = file_get_contents('php://input');
            $context = json_decode($requestBody, true) ?: [];

            // Add shop context
            $context['shop'] = $shop;
            $context['connection_id'] = $connection->id;

            // Add customer ID if user is logged in (critical for personalization!)
            if (!empty($queryParams['logged_in_customer_id'])) {
                $context['customer_id'] = $queryParams['logged_in_customer_id'];
            }

            // Add other useful Shopify context
            $context['timestamp'] = $queryParams['timestamp'] ?? null;
            $context['locale'] = $queryParams['locale'] ?? null;

            // Execute the pipeline
            require_once __DIR__ . '/../services/PipelineDispatcher.php';
            $result = \app\services\PipelineDispatcher::dispatch($pipelineSlug, $context);

            // Return the result
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'data' => $result['output'] ?? []
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error'] ?? 'Pipeline execution failed'
                ]);
            }

        } catch (Exception $e) {
            $this->logger->error('App proxy error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    /**
     * Validate Shopify HMAC signature for app proxy requests
     */
    private function validateShopifyHmac(array $params, string $hmac): bool {
        // Sort parameters
        ksort($params);

        // Build query string
        $queryString = http_build_query($params);

        // Get app secret from connection or enterprise settings
        // For app proxy, we use the app's client secret
        $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['shopify_client_secret']);
        if (!$setting || !$setting->id) {
            $this->logger->warning('Shopify client secret not configured');
            return false;
        }

        $clientSecret = \app\services\EncryptionService::decrypt($setting->setting_value);

        // Calculate expected HMAC
        $expectedHmac = hash_hmac('sha256', $queryString, $clientSecret);

        // Compare (timing-safe)
        return hash_equals($expectedHmac, $hmac);
    }
}
