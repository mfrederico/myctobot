<?php
/**
 * Member Model
 * FUSE model for member table
 *
 * All data is now in a single MySQL database per workspace.
 * Subscription is WORKSPACE-level, not per-member.
 */

use \RedBeanPHP\R as R;
use \app\services\SubscriptionService;

class Model_Member extends \RedBeanPHP\SimpleModel {

    /**
     * Get display name for this member
     */
    public function displayName(): string {
        $bean = $this->bean;
        if (!empty($bean->firstName) || !empty($bean->lastName)) {
            return trim(($bean->firstName ?? '') . ' ' . ($bean->lastName ?? ''));
        }
        if (!empty($bean->username)) {
            return $bean->username;
        }
        if (!empty($bean->email)) {
            return explode('@', $bean->email)[0];
        }
        return 'Unknown';
    }

    /**
     * Get initials for avatar placeholder
     */
    public function initials(): string {
        $name = $this->displayName();
        $parts = explode(' ', $name);
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }
        return strtoupper(substr($name, 0, 1));
    }

    /**
     * Tier hierarchy for comparison (higher = more access)
     */
    private const TIER_HIERARCHY = [
        'free' => 0,
        'pro' => 1,
        'enterprise' => 2
    ];

    /**
     * Check if member has at least the specified tier level
     *
     * Note: Subscription is workspace-level, so all members in the
     * same workspace share the same tier.
     *
     * @param string $requiredTier The minimum tier required ('free', 'pro', 'enterprise')
     * @return bool True if workspace tier is >= required tier
     */
    public function hasTier(string $requiredTier): bool {
        $workspaceTier = $this->getTier();
        $workspaceLevel = self::TIER_HIERARCHY[$workspaceTier] ?? 0;
        $requiredLevel = self::TIER_HIERARCHY[$requiredTier] ?? 0;

        return $workspaceLevel >= $requiredLevel;
    }

    /**
     * Check if workspace has Pro tier or higher
     *
     * @return bool
     */
    public function isPro(): bool {
        return $this->hasTier('pro');
    }

    /**
     * Check if workspace has Enterprise tier
     *
     * @return bool
     */
    public function isEnterprise(): bool {
        return $this->hasTier('enterprise');
    }

    /**
     * Get workspace subscription tier
     *
     * Subscription is workspace-level, not per-member.
     * All members in the workspace share the same tier.
     *
     * @return string
     */
    public function getTier(): string {
        return SubscriptionService::getTier();
    }

    /**
     * Get workspace subscription details
     *
     * @return array|null
     */
    public function getSubscription(): ?array {
        return SubscriptionService::getSubscription();
    }

    /**
     * Get member's service connections (Jira, GitHub, Shopify, etc.)
     * Includes both member-owned and shared workspace connections.
     * Extensible - add new services as integrations are added.
     *
     * Note: Some tables use `is_shared`, others use `shared` - handle both.
     * TODO: Consider refactoring tables to use consistent `is_shared` column.
     *
     * @return array Service connection status and metadata
     */
    public function getConnections(): array {
        $memberId = $this->bean->id;
        $connections = [];

        // Jira/Atlassian - uses shared
        $atlassian = \app\Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1)', ['atlassian', $memberId]);
        if ($atlassian && $atlassian->external_eid) {
            $connections['jira'] = [
                'connected' => true,
                'cloud_uid' => $atlassian->external_eid,
                'is_shared' => (bool) ($atlassian->shared ?? false),
            ];
        }

        // GitHub - check legacy table first, then unified connections
        $github = \app\Bean::findOne('githubtoken', '(member_id = ? OR is_shared = 1)', [$memberId]);
        if ($github && $github->access_token) {
            $connections['github'] = [
                'connected' => true,
                'username' => $github->username ?? null,
                'is_shared' => (bool) ($github->is_shared ?? false),
            ];
        } else {
            $githubConn = \app\Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1)', ['github', $memberId]);
            if ($githubConn && !empty($githubConn->access_token)) {
                $connections['github'] = [
                    'connected' => true,
                    'username' => $githubConn->external_eid ?? $githubConn->external_name ?? null,
                    'is_shared' => (bool) ($githubConn->shared ?? false),
                ];
            }
        }

        // Shopify - uses unified connections table
        $shopify = \app\Bean::find('connections', 'connector_type = ? AND (member_id = ? OR shared = 1) AND enabled = 1', ['shopify', $memberId]);
        if (!empty($shopify)) {
            $stores = [];
            foreach ($shopify as $conn) {
                $stores[] = [
                    'id' => (int) $conn->id,
                    'shop' => $conn->external_eid,
                    'is_shared' => (bool) ($conn->shared ?? false),
                ];
            }
            $connections['shopify'] = ['connected' => true, 'stores' => $stores];
        }

        // Anthropic API Keys - from unified connections table
        $anthropic = \app\Bean::find('connections', 'connector_type = ? AND (member_id = ? OR shared = 1)', ['anthropic', $memberId]);
        if (!empty($anthropic)) {
            $keys = [];
            foreach ($anthropic as $key) {
                $keys[] = [
                    'id' => (int) $key->id,
                    'name' => $key->connection_name ?? 'Unnamed',
                    'is_shared' => (bool) ($key->shared ?? false),
                ];
            }
            $connections['anthropic'] = ['connected' => true, 'keys' => $keys];
        }

        // Repo Connections - no sharing column currently (member-owned only)
        $repos = \app\Bean::find('repoconnections', 'member_id = ? AND enabled = 1', [$memberId]);
        if (!empty($repos)) {
            $repoList = [];
            foreach ($repos as $repo) {
                $repoList[] = [
                    'id' => (int) $repo->id,
                    'provider' => $repo->provider ?? 'github',
                    'repo' => ($repo->repo_owner ?? '') . '/' . ($repo->repo_name ?? ''),
                ];
            }
            $connections['repos'] = ['connected' => true, 'repos' => $repoList];
        }

        return $connections;
    }
}
