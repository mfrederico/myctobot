<?php
/**
 * Member Model
 * FUSE model for member table
 *
 * All data is now in a single MySQL database per tenant.
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
     * @param string $requiredTier The minimum tier required ('free', 'pro', 'enterprise')
     * @return bool True if member's tier is >= required tier
     */
    public function hasTier(string $requiredTier): bool {
        if (!$this->bean->id) {
            return $requiredTier === 'free';
        }

        $memberTier = $this->getTier();
        $memberLevel = self::TIER_HIERARCHY[$memberTier] ?? 0;
        $requiredLevel = self::TIER_HIERARCHY[$requiredTier] ?? 0;

        return $memberLevel >= $requiredLevel;
    }

    /**
     * Check if member has Pro tier or higher
     *
     * @return bool
     */
    public function isPro(): bool {
        return $this->hasTier('pro');
    }

    /**
     * Check if member has Enterprise tier
     *
     * @return bool
     */
    public function isEnterprise(): bool {
        return $this->hasTier('enterprise');
    }

    /**
     * Get member's subscription tier
     *
     * @return string
     */
    public function getTier(): string {
        if (!$this->bean->id) {
            return 'free';
        }
        return SubscriptionService::getTier($this->bean->id);
    }

    /**
     * Get member's subscription details
     *
     * @return array|null
     */
    public function getSubscription(): ?array {
        if (!$this->bean->id) {
            return null;
        }
        return SubscriptionService::getSubscription($this->bean->id);
    }
}
