<?php
/**
 * Generic Connections Controller
 *
 * Handles OAuth initiation, callback, testing, and disconnection
 * for ANY registered connector type via ConnectorRegistry.
 *
 * Routes (auto-routed by DefaultRoute):
 *   /connections              → index()      → List/redirect
 *   /connections/connect/{type}  → connect()   → OAuth initiation
 *   /connections/callback/{type} → callback()  → OAuth callback
 *   /connections/disconnect/{id} → disconnect() → Remove connection
 *   /connections/test/{id}       → test()      → Test connection (AJAX)
 *   /connections/add/{type}      → add()       → Manual key/token entry
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use \app\services\ConnectionsService;
use \app\services\ConnectorRegistry;
use \app\services\OAuthStateService;
use \app\services\EncryptionService;
use \app\Bean;

require_once __DIR__ . '/../services/ConnectorRegistry.php';
require_once __DIR__ . '/../services/OAuthStateService.php';
require_once __DIR__ . '/../services/EncryptionService.php';

class Connections extends BaseControls\Control {

    /**
     * Connections index - redirect to settings page
     */
    public function index() {
        if (!$this->requireLogin()) return;
        Flight::redirect('/settings/connections');
    }

    /**
     * Generic OAuth initiation for any connector type
     *
     * URL: /connections/connect/{type}
     * Supports extra params via query string (e.g., ?shop=mystore for Shopify)
     */
    public function connect($params = []) {
        if (!$this->requireLogin()) return;

        $type = $params[0] ?? '';

        if (empty($type) || !ConnectorRegistry::has($type)) {
            $this->flash('error', 'Unknown connector type.');
            Flight::redirect('/settings/connections');
            return;
        }

        $connectorClass = ConnectorRegistry::get($type);
        $meta = $connectorClass::getMeta();

        // For API key connectors, redirect to add form
        if ($meta['auth_type'] === 'api_key') {
            Flight::redirect("/connections/add/{$type}");
            return;
        }

        // Load OAuth config using connector's own method
        $config = method_exists($connectorClass, 'loadOAuthConfig')
            ? $connectorClass::loadOAuthConfig()
            : [];

        if (!ConnectionsService::isOAuthConfigured($connectorClass)) {
            $this->flash('error', "{$meta['name']} OAuth is not configured. Please contact your administrator.");
            Flight::redirect('/settings/connections');
            return;
        }

        // Collect extra params from query string (e.g., shop domain for Shopify)
        $extra = [];
        $request = Flight::request();
        foreach ($request->query as $key => $value) {
            $extra[$key] = $value;
        }

        // Shopify requires shop domain before OAuth - show form if missing
        if ($type === 'shopify' && empty($extra['shop'])) {
            $this->render('connections/connect_shopify', [
                'title' => 'Connect Shopify Store',
                'meta' => $meta,
            ]);
            return;
        }

        // Build OAuth state with workspace + CSRF
        $returnUrl = $request->query->return_url ?? '/settings/connections';
        $state = OAuthStateService::encode([
            'provider' => $type,
            'return_url' => $returnUrl,
        ]);

        $sessionKey = "{$type}_oauth_state_hash";
        OAuthStateService::storeInSession($state, $sessionKey);

        $oauthUrl = $connectorClass::getOAuthUrl($state, $config, $extra);

        if (empty($oauthUrl)) {
            $this->flash('error', "Could not build {$meta['name']} authorization URL. Check configuration.");
            Flight::redirect('/settings/connections');
            return;
        }

        $this->logger->info("Starting {$type} OAuth", [
            'member_id' => $this->member->id,
            'connector' => $type,
        ]);

        Flight::redirect($oauthUrl);
    }

    /**
     * Generic OAuth callback for any connector type
     *
     * URL: /connections/callback/{type}
     * This is PUBLIC (level 101) since user redirects back from external provider
     */
    public function callback($params = []) {
        $type = $params[0] ?? '';

        if (empty($type) || !ConnectorRegistry::has($type)) {
            $this->flash('error', 'Unknown connector type.');
            Flight::redirect('/settings/connections');
            return;
        }

        $connectorClass = ConnectorRegistry::get($type);
        $meta = $connectorClass::getMeta();

        $request = Flight::request();
        $code = $request->query->code ?? '';
        $state = $request->query->state ?? '';
        $error = $request->query->error ?? '';
        $errorDescription = $request->query->error_description ?? '';

        // Check for OAuth errors from provider
        if ($error) {
            $this->logger->warning("{$type} OAuth error", [
                'error' => $error,
                'description' => $errorDescription,
            ]);

            $stateData = OAuthStateService::decode($state);
            if ($stateData && OAuthStateService::needsWorkspaceRedirect($stateData)) {
                OAuthStateService::redirectToWorkspace($stateData, "/connections/callback/{$type}", [
                    'error' => $error,
                    'error_description' => $errorDescription,
                    'state' => $state,
                ]);
                return;
            }

            $this->flash('error', "{$meta['name']} authorization failed: " . ($errorDescription ?: $error));
            Flight::redirect('/settings/connections');
            return;
        }

        // Decode and verify state
        $stateData = OAuthStateService::decode($state);
        if (!$stateData) {
            $this->logger->warning("{$type} OAuth: Invalid state (decode failed)");
            $this->flash('error', 'Invalid OAuth state. Please try again.');
            Flight::redirect('/settings/connections');
            return;
        }

        // Check if workspace redirect needed (OAuth callback came to wrong subdomain)
        if (OAuthStateService::needsWorkspaceRedirect($stateData)) {
            // Forward all query params to the workspace redirect
            $queryParams = [];
            foreach ($request->query as $key => $value) {
                $queryParams[$key] = $value;
            }
            OAuthStateService::redirectToWorkspace($stateData, "/connections/callback/{$type}", $queryParams);
            return;
        }

        // Require login on correct workspace
        if (!$this->requireLogin()) return;

        // Validate state against session (CSRF protection)
        $sessionKey = "{$type}_oauth_state_hash";
        if (!OAuthStateService::validateFromSession($state, $sessionKey)) {
            $this->logger->warning("{$type} OAuth state mismatch");
            $this->flash('error', 'Invalid OAuth state. Please try again.');
            Flight::redirect('/settings/connections');
            return;
        }

        OAuthStateService::clearFromSession($sessionKey);

        try {
            // Load connector config for token exchange
            $config = method_exists($connectorClass, 'loadOAuthConfig')
                ? $connectorClass::loadOAuthConfig()
                : [];

            // Collect extra params (e.g., shop from query or session)
            $extra = [];
            foreach ($request->query as $key => $value) {
                $extra[$key] = $value;
            }
            // Also check session for stored extras (e.g., Shopify shop domain)
            if (!empty($_SESSION["{$type}_oauth_extra"])) {
                $extra = array_merge(json_decode($_SESSION["{$type}_oauth_extra"], true) ?: [], $extra);
                unset($_SESSION["{$type}_oauth_extra"]);
            }

            // Exchange authorization code for tokens
            $result = $connectorClass::exchangeCode($code, $config, $extra);

            if (empty($result) || empty($result['access_token'])) {
                throw new Exception('Failed to obtain access token');
            }

            // Check for existing connection (reconnect scenario)
            $existingConn = null;
            if (!empty($result['external_eid'])) {
                $existingConn = Bean::findOne('connections',
                    'connector_type = ? AND member_id = ? AND external_eid = ?',
                    [$type, $this->member->id, $result['external_eid']]
                );
            }

            // Update existing or create new
            $isReconnect = false;
            if ($existingConn && $existingConn->id) {
                $conn = $existingConn;
                $isReconnect = true;
            } else {
                $conn = Bean::dispense('connections');
                $conn->connector_type = $type;
                $conn->member_id = $this->member->id;
                $conn->created_at = date('Y-m-d H:i:s');
            }

            // Update connection fields
            $conn->connection_name = $result['external_name'] ?? $meta['name'];
            $conn->auth_type = 'oauth';
            $conn->access_token = EncryptionService::encrypt($result['access_token']);
            $conn->refresh_token = !empty($result['refresh_token'])
                ? EncryptionService::encrypt($result['refresh_token'])
                : null;
            $conn->token_type = $result['token_type'] ?? 'Bearer';
            $conn->expires_at = $result['expires_at'] ?? null;
            $conn->scopes = $result['scopes'] ?? '';
            $conn->external_eid = $result['external_eid'] ?? '';
            $conn->external_name = $result['external_name'] ?? '';
            $conn->external_url = $result['external_url'] ?? '';
            $conn->enabled = 1;
            $conn->shared = 1;
            $conn->metadata_json = !empty($result['metadata']) ? json_encode($result['metadata']) : '{}';
            $conn->updated_at = date('Y-m-d H:i:s');

            $connId = Bean::store($conn);

            $action = $isReconnect ? 'updated' : 'created';
            $this->logger->info("{$type} OAuth: Connection {$action}", [
                'member_id' => $this->member->id,
                'connection_id' => $connId,
                'external_eid' => $result['external_eid'] ?? '',
                'is_reconnect' => $isReconnect,
            ]);

            $message = $isReconnect
                ? "{$meta['name']} reconnected successfully with updated permissions!"
                : "{$meta['name']} connected successfully!";
            $this->flash('success', $message);

            $returnUrl = $stateData['return_url'] ?? '/settings/connections';
            Flight::redirect($returnUrl);

        } catch (Exception $e) {
            $this->logger->error("{$type} OAuth failed", [
                'error' => $e->getMessage(),
            ]);
            $this->flash('error', "Failed to connect {$meta['name']}: " . $e->getMessage());
            Flight::redirect('/settings/connections');
        }
    }

    /**
     * Disconnect (delete) a connection
     *
     * URL: /connections/disconnect/{id}
     */
    public function disconnect($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);

        if (empty($connectionId)) {
            $this->flash('error', 'No connection specified.');
            Flight::redirect('/settings/connections');
            return;
        }

        try {
            $conn = Bean::load('connections', $connectionId);
            if (!$conn->id) {
                $this->flash('error', 'Connection not found.');
                Flight::redirect('/settings/connections');
                return;
            }

            // Only owner can disconnect
            if ($conn->member_id != $this->member->id) {
                $this->flash('error', 'You can only disconnect your own connections.');
                Flight::redirect('/settings/connections');
                return;
            }

            $type = $conn->connector_type;
            $name = $conn->connection_name ?: $conn->external_name ?: $type;

            Bean::trash($conn);

            $this->logger->info('Connection disconnected', [
                'member_id' => $this->member->id,
                'connection_id' => $connectionId,
                'connector_type' => $type,
            ]);

            $this->flash('success', "Disconnected {$name}");

        } catch (Exception $e) {
            $this->logger->error('Failed to disconnect', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to disconnect: ' . $e->getMessage());
        }

        Flight::redirect('/settings/connections');
    }

    /**
     * Test a connection (AJAX)
     *
     * URL: /connections/test/{id}
     */
    public function test($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = Bean::load('connections', $connectionId);
            if (!$conn->id) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Check access (owner or shared)
            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $type = $conn->connector_type;
            $connectorClass = ConnectorRegistry::get($type);

            if (!$connectorClass) {
                $this->json(['success' => false, 'message' => "Unknown connector type: {$type}"]);
                return;
            }

            $result = $connectorClass::testConnection($conn);

            // Update last_used_at on success
            if ($result['success'] ?? false) {
                $conn->last_used_at = date('Y-m-d H:i:s');
                $conn->last_error = null;
                Bean::store($conn);
            } else {
                $conn->last_error = $result['message'] ?? 'Test failed';
                Bean::store($conn);
            }

            $this->json($result);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Manual connection form (for API key connectors)
     *
     * URL: /connections/add/{type}
     */
    public function add($params = []) {
        if (!$this->requireLogin()) return;

        $type = $params[0] ?? '';

        if (empty($type) || !ConnectorRegistry::has($type)) {
            $this->flash('error', 'Unknown connector type.');
            Flight::redirect('/settings/connections');
            return;
        }

        $connectorClass = ConnectorRegistry::get($type);
        $meta = $connectorClass::getMeta();

        // Handle POST (save the API key / token)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $data = [];
            foreach ($_POST as $key => $value) {
                if ($key !== 'csrf_token') {
                    $data[$key] = trim($value);
                }
            }

            // Validate via connector
            $validation = $connectorClass::validate($data);
            if (!$validation['valid']) {
                $this->flash('error', implode(', ', $validation['errors']));
                Flight::redirect("/connections/add/{$type}");
                return;
            }

            // Create connection bean
            $conn = Bean::dispense('connections');
            $conn->connector_type = $type;
            $conn->member_id = $this->member->id;
            $conn->connection_name = $data['name'] ?? $meta['name'];
            $conn->auth_type = $meta['auth_type'];
            $conn->access_token = EncryptionService::encrypt($data['api_key'] ?? $data['token'] ?? '');
            $conn->enabled = 1;
            $conn->shared = !empty($data['shared']) ? 1 : 0;
            $conn->external_eid = $data['domain'] ?? $data['external_eid'] ?? '';
            $conn->external_name = $data['name'] ?? $meta['name'];

            // Build metadata from extra fields
            $metadata = [];
            $skipKeys = ['api_key', 'token', 'name', 'shared', 'csrf_token', 'external_eid'];
            foreach ($data as $key => $value) {
                if (!in_array($key, $skipKeys) && !empty($value)) {
                    $metadata[$key] = $value;
                }
            }
            $conn->metadata_json = !empty($metadata) ? json_encode($metadata) : '{}';
            $conn->created_at = date('Y-m-d H:i:s');
            $conn->updated_at = date('Y-m-d H:i:s');

            $connId = Bean::store($conn);

            $this->logger->info("{$type} connection added manually", [
                'member_id' => $this->member->id,
                'connection_id' => $connId,
            ]);

            $this->flash('success', "{$meta['name']} connection added!");
            Flight::redirect('/settings/connections');
            return;
        }

        // GET - show form
        $this->render('connections/add', [
            'title' => "Add {$meta['name']} Connection",
            'type' => $type,
            'meta' => $meta,
        ]);
    }
}
