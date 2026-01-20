<?php
/**
 * ApiAuthService - Unified API authentication service
 *
 * Validates API keys from multiple sources (header, query param) and
 * returns the member context for the authenticated request.
 *
 * Usage:
 *   $result = ApiAuthService::authenticate($controller, $method);
 *   if (!$result['success']) {
 *       Flight::jsonError($result['error'], $result['code']);
 *       return;
 *   }
 *   $member = $result['member'];
 *   $apiKey = $result['apikey'];
 */

namespace app\services;

use \app\Bean;
use \Flight as Flight;

class ApiAuthService {

    /**
     * Authenticate an API request and return the member context
     *
     * @param string|null $controller The controller being accessed (for scope check)
     * @param string|null $method The method being accessed (for scope check)
     * @return array ['success' => bool, 'member' => ?object, 'apikey' => ?object, 'error' => ?string, 'code' => ?int]
     */
    public static function authenticate(?string $controller = null, ?string $method = null): array {
        // Extract token from request
        $token = self::extractToken();

        if (empty($token)) {
            return [
                'success' => false,
                'member' => null,
                'apikey' => null,
                'error' => 'API key required. Use X-API-TOKEN header, Authorization: Bearer header, or ?key= parameter.',
                'code' => 401
            ];
        }

        // Validate token format
        if (!str_starts_with($token, 'tk_')) {
            return [
                'success' => false,
                'member' => null,
                'apikey' => null,
                'error' => 'Invalid API key format.',
                'code' => 401
            ];
        }

        // Find the API key
        $apiKey = Bean::findOne('apikeys', 'token = ?', [$token]);

        if (!$apiKey) {
            return [
                'success' => false,
                'member' => null,
                'apikey' => null,
                'error' => 'Invalid API key.',
                'code' => 401
            ];
        }

        // Check if key is active
        if (!$apiKey->is_active) {
            return [
                'success' => false,
                'member' => null,
                'apikey' => $apiKey,
                'error' => 'API key is disabled.',
                'code' => 401
            ];
        }

        // Check expiration
        if ($apiKey->expires_at && strtotime($apiKey->expires_at) < time()) {
            return [
                'success' => false,
                'member' => null,
                'apikey' => $apiKey,
                'error' => 'API key has expired.',
                'code' => 401
            ];
        }

        // Load the member this key runs as
        $member = Bean::load('member', $apiKey->member_id);
        if (!$member || !$member->id) {
            return [
                'success' => false,
                'member' => null,
                'apikey' => $apiKey,
                'error' => 'API key member not found.',
                'code' => 401
            ];
        }

        // Check member is active
        if (isset($member->is_active) && !$member->is_active) {
            return [
                'success' => false,
                'member' => $member,
                'apikey' => $apiKey,
                'error' => 'Member account is disabled.',
                'code' => 403
            ];
        }

        // Check scope if controller/method provided
        if ($controller && $method) {
            $scopeCheck = self::checkScope($apiKey, $controller, $method);
            if (!$scopeCheck['allowed']) {
                return [
                    'success' => false,
                    'member' => $member,
                    'apikey' => $apiKey,
                    'error' => "API key does not have permission for {$controller}:{$method}.",
                    'code' => 403
                ];
            }

            // Also check member's permission level for this endpoint
            $memberLevel = $member->level ?? LEVELS['MEMBER'];
            $permissions = new \app\Permissions();
            if (!$permissions->permfor($controller, $method, $memberLevel)) {
                return [
                    'success' => false,
                    'member' => $member,
                    'apikey' => $apiKey,
                    'error' => "Member does not have permission for {$controller}:{$method}.",
                    'code' => 403
                ];
            }
        }

        // Update usage stats
        self::updateUsageStats($apiKey);

        return [
            'success' => true,
            'member' => $member,
            'apikey' => $apiKey,
            'error' => null,
            'code' => null
        ];
    }

    /**
     * Extract API token from request (header or query param)
     *
     * @return string|null
     */
    public static function extractToken(): ?string {
        // Check X-API-TOKEN header
        $token = $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if (!empty($token)) {
            return $token;
        }

        // Check Authorization: Bearer header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check query parameter ?key=xxx
        $token = $_GET['key'] ?? null;
        if (!empty($token)) {
            return $token;
        }

        return null;
    }

    /**
     * Check if API key has the required scope
     *
     * @param object $apiKey The API key bean
     * @param string $controller The controller name
     * @param string $method The method name
     * @return array ['allowed' => bool, 'matched_scope' => ?string]
     */
    public static function checkScope(object $apiKey, string $controller, string $method): array {
        $scopes = json_decode($apiKey->scopes_json ?: '[]', true);
        if (!is_array($scopes)) {
            $scopes = [];
        }

        $controller = strtolower($controller);
        $method = strtolower($method);
        $required = "{$controller}:{$method}";

        foreach ($scopes as $scope) {
            // Full wildcard
            if ($scope === '*:*' || $scope === '*') {
                return ['allowed' => true, 'matched_scope' => $scope];
            }

            // Controller wildcard (e.g., "pipelines:*")
            if (preg_match('/^([^:]+):\*$/', $scope, $matches)) {
                if (strtolower($matches[1]) === $controller) {
                    return ['allowed' => true, 'matched_scope' => $scope];
                }
            }

            // Exact match
            if (strtolower($scope) === $required) {
                return ['allowed' => true, 'matched_scope' => $scope];
            }
        }

        return ['allowed' => false, 'matched_scope' => null];
    }

    /**
     * Update API key usage statistics
     *
     * @param object $apiKey
     */
    private static function updateUsageStats(object $apiKey): void {
        $apiKey->last_used_at = date('Y-m-d H:i:s');
        $apiKey->last_used_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $apiKey->usage_count = ($apiKey->usage_count ?? 0) + 1;
        Bean::store($apiKey);
    }

    /**
     * Generate a new secure API token
     *
     * @return string
     */
    public static function generateToken(): string {
        return 'tk_' . bin2hex(random_bytes(32));
    }

    /**
     * Get all available scopes from authcontrol table
     *
     * @return array ['scope' => 'description', ...]
     */
    public static function getAvailableScopes(): array {
        $scopes = [
            '*:*' => 'Full access (all controllers and methods)'
        ];

        // Get all permissions from authcontrol
        $permissions = Bean::findAll('authcontrol', 'ORDER BY control, method');

        // Group by controller for wildcard options
        $controllers = [];
        foreach ($permissions as $perm) {
            $scope = "{$perm->control}:{$perm->method}";
            $description = $perm->description ?: ucfirst($perm->control) . ' ' . $perm->method;
            $scopes[$scope] = $description;

            if (!isset($controllers[$perm->control])) {
                $controllers[$perm->control] = true;
            }
        }

        // Add controller wildcards
        foreach (array_keys($controllers) as $controller) {
            $wildcardScope = "{$controller}:*";
            if (!isset($scopes[$wildcardScope])) {
                $scopes[$wildcardScope] = ucfirst($controller) . ' - all methods';
            }
        }

        return $scopes;
    }

    /**
     * Validate scopes array against available scopes
     *
     * @param array $scopes
     * @return array ['valid' => bool, 'invalid' => array]
     */
    public static function validateScopes(array $scopes): array {
        $available = self::getAvailableScopes();
        $invalid = [];

        foreach ($scopes as $scope) {
            // Allow wildcards
            if ($scope === '*:*' || $scope === '*') {
                continue;
            }

            // Allow controller wildcards
            if (preg_match('/^[a-z]+:\*$/', $scope)) {
                continue;
            }

            // Check exact scope exists
            if (!isset($available[$scope])) {
                $invalid[] = $scope;
            }
        }

        return [
            'valid' => empty($invalid),
            'invalid' => $invalid
        ];
    }
}
