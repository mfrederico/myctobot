<?php
/**
 * Connections Service
 *
 * Aggregates and manages all external service connections for a user.
 * Uses ConnectorRegistry for dynamic connector discovery and delegation.
 * All data lives in the unified `connections` table.
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/ConnectorRegistry.php';

class ConnectionsService {

    private $memberId;
    private $member;
    private $tier;

    public function __construct(int $memberId) {
        $this->memberId = $memberId;

        $this->member = Bean::load('member', $memberId);
        $this->tier = SubscriptionService::getTier($memberId);
    }

    /**
     * Get all connections with their current status
     *
     * Uses ConnectorRegistry to dynamically discover all connector types,
     * then queries the unified `connections` table for each type.
     * Falls back to legacy per-connector status methods if no unified data exists.
     *
     * @return array
     */
    public function getAllConnections(): array {
        $connections = [];

        foreach (ConnectorRegistry::getAll() as $key => $connectorClass) {
            $meta = $connectorClass::getMeta();

            $connection = [
                'key' => $key,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'tier_required' => $meta['tier_required'],
                'auth_type' => $meta['auth_type'],
                'features' => $meta['features'],
                'coming_soon' => $meta['coming_soon'] ?? false,
                'available' => $this->isConnectionAvailable($key, $meta),
                'connected' => false,
                'status' => null,
                'details' => null,
                'actions' => []
            ];

            // OAuth connectors need their redirect URL registered in the provider's
            // app settings - surface it (and any mismatch with the configured value)
            // so an admin can set it up without digging through ini files.
            if ($connection['auth_type'] === 'oauth' && method_exists($connectorClass, 'getCallbackUrl')) {
                $connection['callback_url'] = $connectorClass::getCallbackUrl();
                $connection['configured'] = self::isOAuthConfigured($connectorClass);

                $oauthConfig = method_exists($connectorClass, 'loadOAuthConfig')
                    ? $connectorClass::loadOAuthConfig()
                    : [];
                $connection['redirect_uri'] = $oauthConfig['redirect_uri'] ?? null;
            }

            if (!$connection['coming_soon'] && $connection['available']) {
                $status = $this->getConnectorStatus($key, $connectorClass);
                if ($status !== null) {
                    $connection = array_merge($connection, $status);
                }
            }

            $connections[$key] = $connection;
        }

        return $connections;
    }

    /**
     * Check whether a connector has the credentials it needs to start OAuth.
     *
     * loadConfig() pre-fills every key with null, so an unconfigured connector
     * returns a non-empty array of empty values - the values must be checked.
     *
     * @param string $connectorClass FQCN of the connector
     * @return bool
     */
    public static function isOAuthConfigured(string $connectorClass): bool {
        if (method_exists($connectorClass, 'isOAuthReady')) {
            return $connectorClass::isOAuthReady();
        }

        if (!method_exists($connectorClass, 'loadOAuthConfig')) {
            return false;
        }

        // Check the credential pair specifically - loadOAuthConfig() injects
        // default scopes, so "any non-empty value" would be a false positive.
        // Connectors use either client_id/secret or api_key/secret naming.
        $config = $connectorClass::loadOAuthConfig();

        $hasId = !empty($config['client_id']) || !empty($config['api_key']);
        $hasSecret = !empty($config['client_secret']) || !empty($config['api_secret']);

        return $hasId && $hasSecret;
    }

    /**
     * Get status from unified connections table via ConnectorRegistry
     */
    private function getConnectorStatus(string $key, string $connectorClass): ?array {
        $connBeans = Bean::find('connections',
            ' connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY created_at DESC ',
            [$key, $this->memberId]
        );

        if (empty($connBeans)) {
            return null;
        }

        return $connectorClass::getStatus($this->memberId, $connBeans);
    }

    /**
     * Check if a connection type is available for this user's tier
     */
    private function isConnectionAvailable(string $key, array $meta): bool {
        $tierRequired = $meta['tier_required'];

        if ($tierRequired === 'free') {
            return true;
        }

        if ($tierRequired === 'pro' && in_array($this->tier, ['pro', 'enterprise'])) {
            return true;
        }

        if ($tierRequired === 'enterprise' && $this->tier === 'enterprise') {
            return true;
        }

        return false;
    }

    /**
     * Get summary counts for dashboard widgets
     */
    public function getConnectionSummary(): array {
        $connections = $this->getAllConnections();

        $connected = 0;
        $available = 0;
        $total = count($connections);

        foreach ($connections as $conn) {
            if ($conn['connected']) {
                $connected++;
            }
            if ($conn['available'] && !$conn['coming_soon']) {
                $available++;
            }
        }

        return [
            'connected' => $connected,
            'available' => $available,
            'total' => $total,
            'tier' => $this->tier
        ];
    }

    /**
     * Check if all required connections are configured for a feature
     */
    public function checkRequirements(string $feature): array {
        $requirements = [
            'ai_developer' => ['atlassian', 'github', 'anthropic'],
            'sprint_analysis' => ['atlassian'],
            'digests' => ['atlassian'],
            'shopify_dev' => ['shopify', 'github', 'anthropic']
        ];

        if (!isset($requirements[$feature])) {
            return ['ready' => false, 'error' => 'Unknown feature'];
        }

        $connections = $this->getAllConnections();
        $missing = [];

        foreach ($requirements[$feature] as $required) {
            if (!isset($connections[$required]) || !$connections[$required]['connected']) {
                $missing[] = $connections[$required]['name'] ?? $required;
            }
        }

        return [
            'ready' => empty($missing),
            'missing' => $missing
        ];
    }

    // ========================================
    // Unified CRUD Methods (connections table)
    // ========================================

    /**
     * Create a connection in the unified table
     *
     * @param string $type Connector type key
     * @param array  $data Connection data
     * @return int New connection ID
     */
    public static function createConnection(string $type, array $data): int {
        $conn = Bean::dispense('connections');
        $conn->connector_type = $type;
        $conn->member_id = $data['member_id'];
        $conn->connection_name = $data['connection_name'] ?? $type;
        $conn->auth_type = $data['auth_type'] ?? 'oauth';
        $conn->access_token = !empty($data['access_token'])
            ? EncryptionService::encrypt($data['access_token'])
            : null;
        $conn->refresh_token = !empty($data['refresh_token'])
            ? EncryptionService::encrypt($data['refresh_token'])
            : null;
        $conn->token_type = $data['token_type'] ?? 'Bearer';
        $conn->expires_at = $data['expires_at'] ?? null;
        $conn->scopes = $data['scopes'] ?? '';
        $conn->external_eid = $data['external_eid'] ?? '';
        $conn->external_name = $data['external_name'] ?? '';
        $conn->external_url = $data['external_url'] ?? '';
        $conn->enabled = $data['enabled'] ?? 1;
        $conn->shared = $data['shared'] ?? 1;
        $conn->metadata_json = !empty($data['metadata'])
            ? json_encode($data['metadata'])
            : '{}';
        $conn->created_at = date('Y-m-d H:i:s');
        $conn->updated_at = date('Y-m-d H:i:s');

        return Bean::store($conn);
    }

    /**
     * Get a connection by ID
     */
    public static function getConnection(int $id): ?object {
        $conn = Bean::load('connections', $id);
        return $conn->id ? $conn : null;
    }

    /**
     * Delete a connection
     */
    public static function deleteConnection(int $id): bool {
        $conn = Bean::load('connections', $id);
        if (!$conn->id) {
            return false;
        }
        Bean::trash($conn);
        return true;
    }

    /**
     * Get a valid (non-expired) token for a connection, auto-refreshing if needed
     *
     * @param int $connectionId Connection ID
     * @return string|null Decrypted access token or null
     */
    public static function getValidToken(int $connectionId): ?string {
        $conn = Bean::load('connections', $connectionId);
        if (!$conn->id || empty($conn->access_token)) {
            return null;
        }

        $token = EncryptionService::decrypt($conn->access_token);

        // Check if token needs refresh (5 minute buffer)
        if (!empty($conn->expires_at) && strtotime($conn->expires_at) < time() + 300) {
            if (self::refreshConnectionToken($connectionId)) {
                // Reload after refresh
                $conn = Bean::load('connections', $connectionId);
                $token = EncryptionService::decrypt($conn->access_token);
            } else {
                return null;
            }
        }

        // Update last_used_at
        $conn->last_used_at = date('Y-m-d H:i:s');
        Bean::store($conn);

        return $token;
    }

    /**
     * Refresh an expired token using the connector's refreshToken method
     *
     * @param int $connectionId Connection ID
     * @return bool Success
     */
    public static function refreshConnectionToken(int $connectionId): bool {
        $conn = Bean::load('connections', $connectionId);
        if (!$conn->id || empty($conn->refresh_token)) {
            return false;
        }

        $type = $conn->connector_type;
        $connectorClass = ConnectorRegistry::get($type);
        if (!$connectorClass) {
            return false;
        }

        try {
            $refreshToken = EncryptionService::decrypt($conn->refresh_token);

            $config = method_exists($connectorClass, 'loadOAuthConfig')
                ? $connectorClass::loadOAuthConfig()
                : [];

            $result = $connectorClass::refreshToken($refreshToken, $config);

            if (empty($result) || empty($result['access_token'])) {
                $conn->last_error = 'Token refresh failed';
                $conn->updated_at = date('Y-m-d H:i:s');
                Bean::store($conn);
                return false;
            }

            $conn->access_token = EncryptionService::encrypt($result['access_token']);
            if (!empty($result['refresh_token'])) {
                $conn->refresh_token = EncryptionService::encrypt($result['refresh_token']);
            }
            if (!empty($result['expires_at'])) {
                $conn->expires_at = $result['expires_at'];
            }
            $conn->last_error = null;
            $conn->updated_at = date('Y-m-d H:i:s');
            Bean::store($conn);

            return true;

        } catch (\Exception $e) {
            $conn->last_error = 'Refresh error: ' . $e->getMessage();
            $conn->updated_at = date('Y-m-d H:i:s');
            Bean::store($conn);
            return false;
        }
    }

    // ========================================
    // Static Repository Connection Methods
    // ========================================

    /**
     * Get all repository connections with agent names
     */
    public static function getRepositoryConnections(): array
    {
        $repos = [];

        $repoBeans = Bean::findAll('repoconnections', ' ORDER BY created_at DESC ');

        foreach ($repoBeans as $bean) {
            $agentName = null;
            $agent = $bean->aiagents;
            if ($agent && $agent->id) {
                $agentName = $agent->name;
            }

            $repos[] = [
                'id' => $bean->id,
                'provider' => $bean->provider,
                'repo_owner' => $bean->repo_owner,
                'repo_name' => $bean->repo_name,
                'slug' => $bean->slug,
                'default_branch' => $bean->default_branch,
                'clone_url' => $bean->clone_url,
                'access_token' => $bean->access_token,
                'enabled' => $bean->enabled,
                'issues_enabled' => $bean->issues_enabled ?? 0,
                'webhook_uid' => $bean->webhook_uid,
                'aiagents_id' => ($agent && $agent->id) ? $agent->id : null,
                'agent_name' => $agentName,
                'created_at' => $bean->created_at,
                'updated_at' => $bean->updated_at
            ];
        }

        return $repos;
    }

    // ========================================
    // Static Utility Methods
    // ========================================

    private static function maskApiKeyStatic(string $key): string {
        if (empty($key)) return '(not configured)';
        $len = strlen($key);
        if ($len < 8) return '****';
        return substr($key, 0, 4) . '...' . substr($key, -4);
    }

    private static function maskAnthropicKeyStatic(string $key): string {
        if (empty($key)) return '(not configured)';
        if (strlen($key) < 20) return 'sk-ant-****...****';

        if (preg_match('/^(sk-ant-api\d+-)(.+)$/', $key, $matches)) {
            $prefix = $matches[1];
            $secret = $matches[2];
            if (strlen($secret) > 7) {
                return $prefix . substr($secret, 0, 3) . '...' . substr($secret, -4);
            }
            return $prefix . '***';
        }

        return substr($key, 0, 10) . '...' . substr($key, -4);
    }
}
