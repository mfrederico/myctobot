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

        $this->member = R::load('member', $memberId);
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
            // Check for GitHub token (user database via Bean::)
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);

            if (!$tokenSetting || empty($tokenSetting->setting_value)) {
                return $result;
            }

            // Get user info
            $userSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_user']);
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
     * Get Anthropic API connection status
     */
    private function getAnthropicStatus(): array {
        $result = [
            'connected' => false,
            'status' => 'Not configured',
            'details' => null,
            'actions' => [
                ['label' => 'Configure API Key', 'url' => '/anthropic', 'class' => 'btn-warning']
            ]
        ];

        try {
            $keySetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['anthropic_api_key', $this->memberId]);

            if (!$keySetting || empty($keySetting->setting_value)) {
                return $result;
            }

            // Decrypt the key and mask it like Anthropic console: sk-ant-api03-XXX...YYYY
            $decryptedKey = EncryptionService::decrypt($keySetting->setting_value);
            $maskedKey = $this->maskAnthropicKey($decryptedKey);

            // Check for credit balance errors
            $creditSetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['credit_balance_error', $this->memberId]);

            $status = 'Configured';
            $statusClass = 'success';
            if ($creditSetting && !empty($creditSetting->setting_value)) {
                $status = 'Low Credits Warning';
                $statusClass = 'warning';
            }

            $result = [
                'connected' => true,
                'status' => $status,
                'status_class' => $statusClass,
                'details' => [
                    'masked_key' => $maskedKey,
                    'has_credit_warning' => $creditSetting && !empty($creditSetting->setting_value)
                ],
                'actions' => [
                    ['label' => 'Update Key', 'url' => '/anthropic', 'class' => 'btn-outline-warning'],
                    ['label' => 'Test Key', 'url' => '/anthropic/test', 'class' => 'btn-outline-secondary', 'ajax' => true]
                ]
            ];
        } catch (\Exception $e) {
            // Database error
        }

        return $result;
    }

    /**
     * Get Shopify connection status
     */
    private function getShopifyStatus(): array {
        $result = [
            'connected' => false,
            'status' => 'Not configured',
            'details' => null,
            'actions' => [
                ['label' => 'Configure Shopify', 'url' => '/shopify', 'class' => 'btn-success']
            ]
        ];

        try {
            $shopify = new ShopifyClient($this->memberId);

            if (!$shopify->isConfigured()) {
                return $result;
            }

            if (!$shopify->isConnected()) {
                // Configured but not connected (needs OAuth)
                return [
                    'connected' => false,
                    'status' => 'Configured - needs authorization',
                    'details' => [
                        'shop' => $shopify->getShop()
                    ],
                    'actions' => [
                        ['label' => 'Authorize', 'url' => '/shopify/connect', 'class' => 'btn-success'],
                        ['label' => 'Settings', 'url' => '/shopify', 'class' => 'btn-outline-secondary']
                    ]
                ];
            }

            // Fully connected
            $details = $shopify->getConnectionDetails();
            $shopName = $details['shop_info']['name'] ?? $details['shop'] ?? 'Connected';

            return [
                'connected' => true,
                'status' => $shopName,
                'details' => $details,
                'actions' => [
                    ['label' => 'Manage', 'url' => '/shopify', 'class' => 'btn-outline-success'],
                    ['label' => 'Disconnect', 'url' => '/shopify/disconnect', 'class' => 'btn-outline-danger', 'confirm' => 'Are you sure you want to disconnect Shopify?']
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
}
