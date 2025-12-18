<?php
/**
 * Atlassian OAuth 2.0 Plugin for MyCTOBot
 * Handles Atlassian/Jira OAuth 2.0 (3LO) authentication
 *
 * Usage:
 * 1. Set atlassian.client_id, atlassian.client_secret, atlassian.redirect_uri in config.ini
 * 2. Call AtlassianAuth::getLoginUrl() to get the authorization URL
 * 3. Handle callback using AtlassianAuth::handleCallback()
 * 4. Use AtlassianAuth::getValidToken() to get a valid access token for API calls
 */

namespace app\plugins;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class AtlassianAuth {

    private static $authUrl = 'https://auth.atlassian.com/authorize';
    private static $tokenUrl = 'https://auth.atlassian.com/oauth/token';
    private static $resourcesUrl = 'https://api.atlassian.com/oauth/token/accessible-resources';

    // Required scopes for Jira access (including Agile/Software for boards)
    private static $defaultScopes = 'read:jira-work read:jira-user read:board-scope:jira-software read:project:jira read:sprint:jira-software offline_access';

    // Write scopes for Enterprise tier (AI Developer feature) - includes webhook management
    private static $writeScopes = 'read:jira-work read:jira-user read:board-scope:jira-software read:project:jira read:sprint:jira-software write:jira-work manage:jira-webhook offline_access';

    /**
     * Get Atlassian OAuth authorization URL
     *
     * @param string $state Optional state parameter for CSRF protection
     * @return string The authorization URL
     */
    public static function getLoginUrl($state = null) {
        $clientId = Flight::get('atlassian.client_id');
        $redirectUri = Flight::get('atlassian.redirect_uri');
        $scopes = Flight::get('atlassian.scopes') ?? self::$defaultScopes;

        if (empty($clientId) || empty($redirectUri)) {
            throw new \Exception('Atlassian OAuth not configured. Set atlassian.client_id and atlassian.redirect_uri in config.ini');
        }

        // Generate state for CSRF protection if not provided
        if ($state === null) {
            $state = bin2hex(random_bytes(16));
            $_SESSION['atlassian_oauth_state'] = $state;
        }

        $params = [
            'audience' => 'api.atlassian.com',
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'prompt' => 'consent' // Force consent to get refresh token
        ];

        return self::$authUrl . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback and store tokens
     *
     * @param string $code Authorization code from Atlassian
     * @param string $state State parameter for CSRF verification
     * @param int $memberId Member ID to associate tokens with
     * @return array|false Array of accessible resources on success, false on failure
     */
    public static function handleCallback($code, $state, $memberId) {
        $logger = Flight::get('log');

        // Verify state parameter (CSRF protection)
        if ($state !== null && isset($_SESSION['atlassian_oauth_state'])) {
            if ($state !== $_SESSION['atlassian_oauth_state']) {
                $logger->warning('Atlassian OAuth state mismatch');
                return false;
            }
            unset($_SESSION['atlassian_oauth_state']);
        }

        // Exchange code for access token
        $tokens = self::exchangeCode($code);
        if (!$tokens || !isset($tokens['access_token'])) {
            $logger->error('Failed to get Atlassian access token');
            return false;
        }

        // Get accessible resources (Jira sites)
        $resources = self::getAccessibleResources($tokens['access_token']);
        if (!$resources || empty($resources)) {
            $logger->error('Failed to get Atlassian accessible resources');
            return false;
        }

        // Store tokens for each cloud resource
        foreach ($resources as $resource) {
            self::storeTokens($memberId, $tokens, $resource);
        }

        // Register AI Developer webhooks for each site (Enterprise feature)
        foreach ($resources as $resource) {
            self::registerAIDevWebhook($memberId, $resource['id'], $tokens['access_token']);
        }

        $logger->info('Atlassian OAuth completed', [
            'member_id' => $memberId,
            'sites_count' => count($resources)
        ]);

        return $resources;
    }

    /**
     * Exchange authorization code for tokens
     *
     * @param string $code Authorization code
     * @return array|false Token data or false on failure
     */
    private static function exchangeCode($code) {
        $clientId = Flight::get('atlassian.client_id');
        $clientSecret = Flight::get('atlassian.client_secret');
        $redirectUri = Flight::get('atlassian.redirect_uri');

        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri
        ];

        $ch = curl_init(self::$tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Flight::get('log')->error('Atlassian token request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        return json_decode($response, true);
    }

    /**
     * Get accessible Jira resources/sites
     *
     * @param string $accessToken Access token
     * @return array|false Array of resources or false on failure
     */
    private static function getAccessibleResources($accessToken) {
        $ch = curl_init(self::$resourcesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Flight::get('log')->error('Atlassian resources request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        return json_decode($response, true);
    }

    /**
     * Store tokens for a member and cloud resource
     *
     * @param int $memberId Member ID
     * @param array $tokens Token data from OAuth
     * @param array $resource Resource data (cloud ID, URL, name)
     */
    public static function storeTokens($memberId, $tokens, $resource) {
        $expiresAt = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600));

        // Check for existing token for this member/cloud
        $existing = R::findOne('atlassiantoken',
            'member_id = ? AND cloud_id = ?',
            [$memberId, $resource['id']]
        );

        if (!$existing) {
            $existing = R::dispense('atlassiantoken');
            $existing->member_id = $memberId;
            $existing->cloud_id = $resource['id'];
            $existing->created_at = date('Y-m-d H:i:s');
        }

        $existing->access_token = $tokens['access_token'];
        $existing->refresh_token = $tokens['refresh_token'] ?? $existing->refresh_token;
        $existing->token_type = $tokens['token_type'] ?? 'Bearer';
        $existing->expires_at = $expiresAt;
        $existing->site_url = $resource['url'];
        $existing->site_name = $resource['name'];
        $existing->scopes = $tokens['scope'] ?? self::$defaultScopes;
        $existing->updated_at = date('Y-m-d H:i:s');

        R::store($existing);

        Flight::get('log')->info('Stored Atlassian tokens', [
            'member_id' => $memberId,
            'cloud_id' => $resource['id'],
            'site_name' => $resource['name']
        ]);
    }

    /**
     * Refresh an expired access token
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @return bool Success status
     */
    public static function refreshToken($memberId, $cloudId) {
        $logger = Flight::get('log');

        // Get existing token record
        $token = R::findOne('atlassiantoken',
            'member_id = ? AND cloud_id = ?',
            [$memberId, $cloudId]
        );

        if (!$token || empty($token->refresh_token)) {
            $logger->error('No refresh token found', [
                'member_id' => $memberId,
                'cloud_id' => $cloudId
            ]);
            return false;
        }

        $clientId = Flight::get('atlassian.client_id');
        $clientSecret = Flight::get('atlassian.client_secret');

        $postData = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $token->refresh_token
        ];

        $ch = curl_init(self::$tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $logger->error('Atlassian token refresh failed', [
                'http_code' => $httpCode,
                'response' => $response,
                'member_id' => $memberId,
                'cloud_id' => $cloudId
            ]);
            return false;
        }

        $newTokens = json_decode($response, true);

        // Update stored tokens
        $expiresAt = date('Y-m-d H:i:s', time() + ($newTokens['expires_in'] ?? 3600));
        $token->access_token = $newTokens['access_token'];
        // Atlassian uses rotating refresh tokens
        if (isset($newTokens['refresh_token'])) {
            $token->refresh_token = $newTokens['refresh_token'];
        }
        $token->expires_at = $expiresAt;
        $token->updated_at = date('Y-m-d H:i:s');
        R::store($token);

        $logger->info('Refreshed Atlassian token', [
            'member_id' => $memberId,
            'cloud_id' => $cloudId
        ]);

        return true;
    }

    /**
     * Get a valid access token, refreshing if needed
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @return string|null Access token or null if unavailable
     */
    public static function getValidToken($memberId, $cloudId) {
        $token = R::findOne('atlassiantoken',
            'member_id = ? AND cloud_id = ?',
            [$memberId, $cloudId]
        );

        if (!$token) {
            return null;
        }

        // Check if token is expired (with 5 minute buffer)
        $expiresAt = strtotime($token->expires_at);
        if ($expiresAt < time() + 300) {
            // Token expired or expiring soon, refresh it
            if (!self::refreshToken($memberId, $cloudId)) {
                return null;
            }
            // Reload token after refresh
            $token = R::load('atlassiantoken', $token->id);
        }

        return $token->access_token;
    }

    /**
     * Get all connected Atlassian sites for a member
     *
     * @param int $memberId Member ID
     * @return array Array of token records
     */
    public static function getConnectedSites($memberId) {
        return R::findAll('atlassiantoken', 'member_id = ?', [$memberId]);
    }

    /**
     * Disconnect an Atlassian site
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @return bool Success status
     */
    public static function disconnect($memberId, $cloudId) {
        $token = R::findOne('atlassiantoken',
            'member_id = ? AND cloud_id = ?',
            [$memberId, $cloudId]
        );

        if ($token) {
            // Remove AI Developer webhook before disconnecting
            self::removeAIDevWebhook($memberId, $cloudId);

            R::trash($token);
            Flight::get('log')->info('Disconnected Atlassian site', [
                'member_id' => $memberId,
                'cloud_id' => $cloudId
            ]);
            return true;
        }

        return false;
    }

    /**
     * Disconnect all Atlassian sites for a member
     *
     * @param int $memberId Member ID
     * @return int Number of sites disconnected
     */
    public static function disconnectAll($memberId) {
        $tokens = R::findAll('atlassiantoken', 'member_id = ?', [$memberId]);
        $count = count($tokens);

        foreach ($tokens as $token) {
            // Remove AI Developer webhook before disconnecting
            self::removeAIDevWebhook($memberId, $token->cloud_id);
            R::trash($token);
        }

        if ($count > 0) {
            Flight::get('log')->info('Disconnected all Atlassian sites', [
                'member_id' => $memberId,
                'count' => $count
            ]);
        }

        return $count;
    }

    /**
     * Check if Atlassian OAuth is configured
     *
     * @return bool
     */
    public static function isConfigured() {
        $clientId = Flight::get('atlassian.client_id');
        $clientSecret = Flight::get('atlassian.client_secret');
        $redirectUri = Flight::get('atlassian.redirect_uri');

        return !empty($clientId) && !empty($clientSecret) && !empty($redirectUri);
    }

    /**
     * Get Jira site URL for a member and cloud ID
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @return string|null Site URL (e.g., https://yoursite.atlassian.net) or null if not found
     */
    public static function getSiteUrl($memberId, $cloudId) {
        $token = R::findOne('atlassiantoken',
            'member_id = ? AND cloud_id = ?',
            [$memberId, $cloudId]
        );

        return $token ? $token->site_url : null;
    }

    /**
     * Get Jira API base URL for a cloud ID
     *
     * @param string $cloudId Cloud ID
     * @return string API base URL
     */
    public static function getApiBaseUrl($cloudId) {
        return "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3";
    }

    /**
     * Get Jira Agile API base URL for a cloud ID
     *
     * @param string $cloudId Cloud ID
     * @return string Agile API base URL
     */
    public static function getAgileApiBaseUrl($cloudId) {
        return "https://api.atlassian.com/ex/jira/{$cloudId}/rest/agile/1.0";
    }

    /**
     * Get Atlassian OAuth authorization URL with write scopes
     * Used for Enterprise tier AI Developer feature
     *
     * @param string $state Optional state parameter for CSRF protection
     * @return string The authorization URL with write scopes
     */
    public static function getLoginUrlWithWriteScopes($state = null) {
        $clientId = Flight::get('atlassian.client_id');
        $redirectUri = Flight::get('atlassian.redirect_uri');

        if (empty($clientId) || empty($redirectUri)) {
            throw new \Exception('Atlassian OAuth not configured. Set atlassian.client_id and atlassian.redirect_uri in config.ini');
        }

        // Generate state for CSRF protection if not provided
        if ($state === null) {
            $state = bin2hex(random_bytes(16));
            $_SESSION['atlassian_oauth_state'] = $state;
        }

        // Store that we're requesting write scopes
        $_SESSION['atlassian_write_scope_upgrade'] = true;

        $params = [
            'audience' => 'api.atlassian.com',
            'client_id' => $clientId,
            'scope' => self::$writeScopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'prompt' => 'consent'
        ];

        return self::$authUrl . '?' . http_build_query($params);
    }

    /**
     * Check if a member has write scopes for a specific cloud
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @return bool True if write scopes are available
     */
    public static function hasWriteScopes($memberId, $cloudId) {
        $token = R::findOne('atlassiantoken',
            'member_id = ? AND cloud_id = ?',
            [$memberId, $cloudId]
        );

        if (!$token || empty($token->scopes)) {
            return false;
        }

        return strpos($token->scopes, 'write:jira-work') !== false;
    }

    /**
     * Get the write scopes string
     *
     * @return string Write scopes
     */
    public static function getWriteScopes() {
        return self::$writeScopes;
    }

    /**
     * Register AI Developer webhook for a Jira site
     * This webhook listens for issue updates and comment events
     *
     * @param int $memberId Member ID
     * @param string $cloudId Atlassian Cloud ID
     * @param string $accessToken Access token
     * @return bool Success
     */
    public static function registerAIDevWebhook(int $memberId, string $cloudId, string $accessToken): bool {
        $logger = Flight::get('log');

        // Check if member has Enterprise tier
        $member = R::load('member', $memberId);
        if (!$member || $member->getTier() !== 'enterprise') {
            $logger->debug('Skipping webhook registration - not Enterprise tier', [
                'member_id' => $memberId
            ]);
            return false;
        }

        $baseUrl = Flight::get('baseurl');
        $webhookUrl = $baseUrl . '/webhook/jira';

        // Check if webhook already exists
        $existingWebhooks = self::getRegisteredWebhooks($cloudId, $accessToken);
        foreach ($existingWebhooks as $webhook) {
            if (isset($webhook['url']) && strpos($webhook['url'], $webhookUrl) !== false) {
                $logger->debug('AI Developer webhook already registered', [
                    'cloud_id' => $cloudId,
                    'webhook_id' => $webhook['id'] ?? 'unknown'
                ]);
                return true;
            }
        }

        // Register new webhook
        // Format per Atlassian docs: url at top level, webhooks array with jqlFilter and events
        $requestBody = [
            'url' => $webhookUrl,
            'webhooks' => [
                [
                    'jqlFilter' => 'labels = ai-dev',
                    'events' => [
                        'jira:issue_updated',
                        'comment_created'
                    ]
                ]
            ]
        ];

        $apiUrl = "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/webhook";

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $result = json_decode($response, true);
            $webhookId = $result['webhookRegistrationResult'][0]['createdWebhookId'] ?? null;

            $logger->info('AI Developer webhook registered', [
                'member_id' => $memberId,
                'cloud_id' => $cloudId,
                'webhook_id' => $webhookId
            ]);

            // Store webhook ID for later management
            self::storeWebhookId($memberId, $cloudId, $webhookId);

            return true;
        } else {
            $logger->warning('Failed to register AI Developer webhook', [
                'member_id' => $memberId,
                'cloud_id' => $cloudId,
                'http_code' => $httpCode,
                'response' => $response,
                'request_url' => $apiUrl,
                'request_body' => json_encode($requestBody)
            ]);
            return false;
        }
    }

    /**
     * Get registered webhooks for a Jira site
     *
     * @param string $cloudId Atlassian Cloud ID
     * @param string $accessToken Access token
     * @return array List of webhooks
     */
    public static function getRegisteredWebhooks(string $cloudId, string $accessToken): array {
        $apiUrl = "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/webhook";

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['values'] ?? [];
        }

        return [];
    }

    /**
     * Store webhook ID in member's database for management
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string|null $webhookId Webhook ID
     */
    private static function storeWebhookId(int $memberId, string $cloudId, ?string $webhookId): void {
        if (!$webhookId) return;

        $member = R::load('member', $memberId);
        if (!$member || empty($member->ceobot_db)) return;

        $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbFile = $dbPath . $member->ceobot_db . '.sqlite';

        if (!file_exists($dbFile)) return;

        try {
            $db = new \SQLite3($dbFile);

            // Ensure table exists
            $db->exec("CREATE TABLE IF NOT EXISTS jira_webhooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cloud_id TEXT NOT NULL,
                webhook_id TEXT NOT NULL,
                webhook_type TEXT DEFAULT 'aidev',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(cloud_id, webhook_type)
            )");

            // Insert or replace webhook ID
            $stmt = $db->prepare("INSERT OR REPLACE INTO jira_webhooks (cloud_id, webhook_id, webhook_type) VALUES (?, ?, 'aidev')");
            $stmt->bindValue(1, $cloudId, SQLITE3_TEXT);
            $stmt->bindValue(2, $webhookId, SQLITE3_TEXT);
            $stmt->execute();

            $db->close();
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to store webhook ID', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Remove AI Developer webhook from a Jira site
     *
     * @param int $memberId Member ID
     * @param string $cloudId Atlassian Cloud ID
     * @return bool Success
     */
    public static function removeAIDevWebhook(int $memberId, string $cloudId): bool {
        $logger = Flight::get('log');

        $accessToken = self::getValidToken($memberId, $cloudId);
        if (!$accessToken) {
            return false;
        }

        // Get stored webhook ID
        $member = R::load('member', $memberId);
        if (!$member || empty($member->ceobot_db)) return false;

        $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbFile = $dbPath . $member->ceobot_db . '.sqlite';

        if (!file_exists($dbFile)) return false;

        try {
            $db = new \SQLite3($dbFile);
            $webhookId = $db->querySingle("SELECT webhook_id FROM jira_webhooks WHERE cloud_id = '{$db->escapeString($cloudId)}' AND webhook_type = 'aidev'");
            $db->close();

            if (!$webhookId) {
                return true; // No webhook to remove
            }

            // Delete webhook via API
            $apiUrl = "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/webhook";

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['webhookIds' => [(int)$webhookId]]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $logger->info('AI Developer webhook removed', [
                    'member_id' => $memberId,
                    'cloud_id' => $cloudId
                ]);
                return true;
            }

        } catch (\Exception $e) {
            $logger->error('Failed to remove webhook', ['error' => $e->getMessage()]);
        }

        return false;
    }
}
