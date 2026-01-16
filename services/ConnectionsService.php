<?php
/**
 * Connections Service
 *
 * Aggregates and manages all external service connections for a user.
 * Provides a unified interface for checking connection status across:
 * - Atlassian/Jira (OAuth)
 * - GitHub (OAuth)
 * - Anthropic API (API Key)
 * - Shopify (OAuth) [Coming soon]
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/ShopifyClient.php';
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';

class ConnectionsService {

    private $memberId;
    private $member;
    private $tier;

    /**
     * Connection types and their requirements
     */
    const CONNECTORS = [
        'atlassian' => [
            'name' => 'Atlassian / Jira',
            'description' => 'Connect your Jira boards for sprint analysis and AI developer features',
            'icon' => 'bi-kanban',
            'color' => 'primary',
            'tier_required' => 'free', // Available to all tiers
            'auth_type' => 'oauth',
            'features' => ['Sprint Analysis', 'Daily Digests', 'AI Developer (Enterprise)']
        ],
        'github' => [
            'name' => 'GitHub',
            'description' => 'Connect repositories for AI-powered code implementation',
            'icon' => 'bi-github',
            'color' => 'dark',
            'tier_required' => 'free',  // Available to all tiers
            'auth_type' => 'oauth',
            'features' => ['AI Developer', 'Automated PRs', 'Code Analysis']
        ],
        'anthropic' => [
            'name' => 'Anthropic API',
            'description' => 'Your Claude API key for AI-powered features',
            'icon' => 'bi-robot',
            'color' => 'warning',
            'tier_required' => 'free',  // Available to all tiers
            'auth_type' => 'api_key',
            'features' => ['AI Developer', 'Advanced Analysis']
        ],
        'shopify' => [
            'name' => 'Shopify',
            'description' => 'Connect your Shopify store for theme development',
            'icon' => 'bi-shop',
            'color' => 'success',
            'tier_required' => 'free',  // Available to all tiers
            'auth_type' => 'oauth',
            'features' => ['Theme Development', 'Store Analysis']
        ]
    ];

    public function __construct(int $memberId) {
        $this->memberId = $memberId;

        $this->member = Bean::load('member', $memberId);
        // Use SubscriptionService directly instead of FUSE model
        // to avoid dependency on Model_Member being loaded
        $this->tier = SubscriptionService::getTier($memberId);
    }

    /**
     * Get all connections with their current status
     *
     * @return array
     */
    public function getAllConnections(): array {
        $connections = [];

        foreach (self::CONNECTORS as $key => $config) {
            $connection = [
                'key' => $key,
                'name' => $config['name'],
                'description' => $config['description'],
                'icon' => $config['icon'],
                'color' => $config['color'],
                'tier_required' => $config['tier_required'],
                'auth_type' => $config['auth_type'],
                'features' => $config['features'],
                'coming_soon' => $config['coming_soon'] ?? false,
                'available' => $this->isConnectionAvailable($key, $config),
                'connected' => false,
                'status' => null,
                'details' => null,
                'actions' => []
            ];

            // Get connection status based on type
            if (!$connection['coming_soon'] && $connection['available']) {
                switch ($key) {
                    case 'atlassian':
                        $connection = array_merge($connection, $this->getAtlassianStatus());
                        break;
                    case 'github':
                        $connection = array_merge($connection, $this->getGitHubStatus());
                        break;
                    case 'anthropic':
                        $connection = array_merge($connection, $this->getAnthropicStatus());
                        break;
                    case 'shopify':
                        $connection = array_merge($connection, $this->getShopifyStatus());
                        break;
                }
            }

            $connections[$key] = $connection;
        }

        return $connections;
    }

    /**
     * Check if a connection type is available for this user's tier
     */
    private function isConnectionAvailable(string $key, array $config): bool {
        $tierRequired = $config['tier_required'];

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
     * Get Atlassian/Jira connection status
     */
    private function getAtlassianStatus(): array {
        $sites = \app\plugins\AtlassianAuth::getConnectedSites($this->memberId);

        if (empty($sites)) {
            return [
                'connected' => false,
                'status' => 'Not connected',
                'details' => null,
                'actions' => [
                    ['label' => 'Connect', 'url' => '/atlassian/connect', 'class' => 'btn-primary']
                ]
            ];
        }

        // Check if any have write scopes (for Enterprise features)
        $hasWriteScopes = false;
        foreach ($sites as $site) {
            if (strpos($site['scopes'] ?? '', 'write:jira-work') !== false) {
                $hasWriteScopes = true;
                break;
            }
        }

        $scopeInfo = $hasWriteScopes ? 'Read/Write' : 'Read Only';

        return [
            'connected' => true,
            'status' => count($sites) . ' site(s) connected',
            'details' => [
                'sites' => $sites,
                'scopes' => $scopeInfo,
                'has_write_scopes' => $hasWriteScopes
            ],
            'actions' => [
                ['label' => 'Manage Sites', 'url' => '/atlassian', 'class' => 'btn-outline-primary'],
                ['label' => 'Add Site', 'url' => '/atlassian/connect', 'class' => 'btn-outline-secondary']
            ]
        ];
    }

    /**
     * Get GitHub connection status
     */
    private function getGitHubStatus(): array {
        $result = [
            'connected' => false,
            'status' => 'Not connected',
            'details' => null,
            'actions' => [
                ['label' => 'Connect GitHub', 'url' => '/github/connect', 'class' => 'btn-dark']
            ]
        ];

        try {
            // Check for GitHub token (workspace-level, shared)
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ? AND is_shared = 1', ['github_token']);

            if (!$tokenSetting || empty($tokenSetting->setting_value)) {
                return $result;
            }

            // Get user info
            $userSetting = Bean::findOne('enterprisesettings', 'setting_key = ? AND is_shared = 1', ['github_user']);
            $user = $userSetting ? json_decode($userSetting->setting_value, true) : null;

            // Get connected repositories
            $repos = Bean::find('repoconnections', 'enabled = ?', [1]);
            $repoList = [];
            foreach ($repos as $repo) {
                $repoList[] = $repo->export();
            }

            $result = [
                'connected' => true,
                'status' => ($user['login'] ?? 'Connected') . ' - ' . count($repoList) . ' repo(s)',
                'details' => [
                    'user' => $user,
                    'repos' => $repoList,
                    'repo_count' => count($repoList)
                ],
                'actions' => [
                    ['label' => 'Manage Repos', 'url' => '/github/repos', 'class' => 'btn-outline-dark'],
                    ['label' => 'Disconnect', 'url' => '/github/disconnect', 'class' => 'btn-outline-danger', 'confirm' => 'Are you sure you want to disconnect GitHub?']
                ]
            ];
        } catch (\Exception $e) {
            $logger = \Flight::get('log');
            $logger->error('GitHub status check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $result;
    }

    /**
     * Get Anthropic API connection status (multi-key)
     */
    private function getAnthropicStatus(): array {
        $result = [
            'connected' => false,
            'status' => 'No API keys configured',
            'details' => null,
            'actions' => [
                ['label' => 'Add API Key', 'url' => '/anthropic/keys', 'class' => 'btn-warning']
            ]
        ];

        try {
            // Get all keys visible to this member (owned + shared)
            $keys = Bean::find('anthropickeys',
                ' created_by_member_id = ? OR shared = 1 ORDER BY created_at DESC ',
                [$this->memberId]
            );

            if (empty($keys)) {
                return $result;
            }

            // Build key list for display
            $keyList = [];
            $ownedCount = 0;
            $sharedCount = 0;

            foreach ($keys as $key) {
                $decrypted = EncryptionService::decrypt($key->api_key);
                $isOwner = ($key->created_by_member_id == $this->memberId);

                $keyList[] = [
                    'id' => $key->id,
                    'name' => $key->name,
                    'model' => $key->model,
                    'masked_key' => $this->maskAnthropicKey($decrypted),
                    'shared' => (bool)$key->shared,
                    'is_owner' => $isOwner,
                    'owner_name' => $key->created_by_name
                ];

                if ($isOwner) {
                    $ownedCount++;
                } else {
                    $sharedCount++;
                }
            }

            $statusParts = [];
            if ($ownedCount > 0) {
                $statusParts[] = $ownedCount . ' owned';
            }
            if ($sharedCount > 0) {
                $statusParts[] = $sharedCount . ' shared';
            }

            return [
                'connected' => true,
                'status' => count($keyList) . ' key(s) - ' . implode(', ', $statusParts),
                'details' => [
                    'keys' => $keyList,
                    'key_count' => count($keyList),
                    'owned_count' => $ownedCount,
                    'shared_count' => $sharedCount
                ],
                'actions' => [
                    ['label' => 'Manage Keys', 'url' => '/anthropic/keys', 'class' => 'btn-outline-warning'],
                    ['label' => 'Add Key', 'url' => '/anthropic/keys', 'class' => 'btn-outline-secondary']
                ]
            ];

        } catch (\Exception $e) {
            // Database error - likely table doesn't exist yet
            return $result;
        }
    }

    /**
     * Get Shopify connection status (multi-store)
     */
    private function getShopifyStatus(): array {
        $result = [
            'connected' => false,
            'status' => 'No stores connected',
            'details' => null,
            'actions' => [
                ['label' => 'Add Store', 'url' => '/shopify', 'class' => 'btn-success']
            ]
        ];

        try {
            // Get all stores visible to this member (owned + shared)
            $stores = ShopifyClient::getAllConnections($this->memberId);

            if (empty($stores)) {
                return $result;
            }

            // Build store list for display
            $storeList = [];
            $ownedCount = 0;
            $sharedCount = 0;

            foreach ($stores as $store) {
                $isOwner = ($store->created_by_member_id == $this->memberId);
                $storeList[] = [
                    'id' => $store->id,
                    'shop_domain' => $store->shop_domain,
                    'shop_name' => $store->shop_name ?: $store->shop_domain,
                    'enabled' => (bool)$store->enabled,
                    'shared' => (bool)$store->shared,
                    'is_owner' => $isOwner,
                    'owner_name' => $store->created_by_name,
                    'repo_linked' => !empty($store->repoconnections_id)
                ];

                if ($isOwner) {
                    $ownedCount++;
                } else {
                    $sharedCount++;
                }
            }

            $statusParts = [];
            if ($ownedCount > 0) {
                $statusParts[] = $ownedCount . ' owned';
            }
            if ($sharedCount > 0) {
                $statusParts[] = $sharedCount . ' shared';
            }

            return [
                'connected' => true,
                'status' => count($storeList) . ' store(s) - ' . implode(', ', $statusParts),
                'details' => [
                    'stores' => $storeList,
                    'store_count' => count($storeList),
                    'owned_count' => $ownedCount,
                    'shared_count' => $sharedCount
                ],
                'actions' => [
                    ['label' => 'Manage Stores', 'url' => '/shopify', 'class' => 'btn-outline-success'],
                    ['label' => 'Add Store', 'url' => '/shopify', 'class' => 'btn-outline-secondary']
                ]
            ];

        } catch (\Exception $e) {
            return $result;
        }
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

    /**
     * Mask an Anthropic API key to match their console display format
     * Example: sk-ant-api03-3Wd...ZwAA
     *
     * @param string $key The decrypted API key
     * @return string Masked key
     */
    private function maskAnthropicKey(string $key): string {
        if (empty($key)) {
            return '(not configured)';
        }

        // Anthropic keys typically look like: sk-ant-api03-XXXXXXXXXXXXXXXXXXXXXXXXXX
        // Show prefix + first 3 chars of secret + ... + last 4 chars
        $len = strlen($key);

        if ($len < 20) {
            // Key seems malformed, just show generic mask
            return 'sk-ant-****...****';
        }

        // Find where the actual secret starts (after sk-ant-api0X-)
        if (preg_match('/^(sk-ant-api\d+-)(.+)$/', $key, $matches)) {
            $prefix = $matches[1];  // e.g., "sk-ant-api03-"
            $secret = $matches[2];  // the rest

            $secretLen = strlen($secret);
            if ($secretLen > 7) {
                return $prefix . substr($secret, 0, 3) . '...' . substr($secret, -4);
            } else {
                return $prefix . '***';
            }
        }

        // Fallback for unexpected format
        return substr($key, 0, 10) . '...' . substr($key, -4);
    }

    // ========================================
    // Static Repository Connection Methods
    // ========================================

    /**
     * Get all repository connections with agent names
     *
     * This is a static helper that can be called from any controller
     * once the user database is connected (via connectUserDb or similar).
     *
     * @return array List of repository connections with agent info
     */
    public static function getRepositoryConnections(): array
    {
        $repos = [];

        $repoBeans = Bean::findAll('repoconnections', ' ORDER BY created_at DESC ');

        foreach ($repoBeans as $bean) {
            // Get agent name if assigned (aiagents is in MySQL)
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
}
