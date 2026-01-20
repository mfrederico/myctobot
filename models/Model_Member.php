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
}
