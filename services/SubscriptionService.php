<?php
/**
 * Subscription Service
 * Manages user subscription tiers and feature access
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class SubscriptionService {

    /**
     * Get subscription tier for a member
     *
     * @param int $memberId Member ID
     * @return string Tier name ('free', 'pro', 'enterprise')
     */
    public static function getTier(int $memberId): string {
        $subscription = R::findOne('subscription', 'member_id = ? AND status = ?', [$memberId, 'active']);

        if ($subscription) {
            // Check if subscription is still valid
            if ($subscription->current_period_end) {
                $endDate = new \DateTime($subscription->current_period_end);
                if ($endDate < new \DateTime()) {
                    return 'free'; // Expired
                }
            }
            return $subscription->tier ?? 'free';
        }

        return 'free';
    }

    /**
     * Check if member has Pro tier or higher
     *
     * @param int $memberId Member ID
     * @return bool
     */
    public static function isPro(int $memberId): bool {
        $tier = self::getTier($memberId);
        return in_array($tier, ['pro', 'enterprise']);
    }

    /**
     * Check if member has Enterprise tier
     *
     * @param int $memberId Member ID
     * @return bool
     */
    public static function isEnterprise(int $memberId): bool {
        return self::getTier($memberId) === 'enterprise';
    }

    /**
     * Check if member can access a specific feature
     *
     * @param int $memberId Member ID
     * @param string $feature Feature name
     * @return bool
     */
    public static function canAccessFeature(int $memberId, string $feature): bool {
        $tier = self::getTier($memberId);
        $features = TierFeatures::getFeatures($tier);

        return $features[$feature] ?? false;
    }

    /**
     * Get remaining quota for a rate-limited feature
     *
     * @param int $memberId Member ID
     * @param string $feature Feature name
     * @return int Remaining uses (-1 for unlimited)
     */
    public static function getRemainingQuota(int $memberId, string $feature): int {
        $tier = self::getTier($memberId);
        $limits = TierFeatures::getLimits($tier);

        $limit = $limits[$feature] ?? 0;
        if ($limit === -1) {
            return -1; // Unlimited
        }

        // TODO: Track usage in database and calculate remaining
        // For now, return the limit
        return $limit;
    }

    /**
     * Get subscription details for a member
     *
     * @param int $memberId Member ID
     * @return array|null
     */
    public static function getSubscription(int $memberId): ?array {
        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);

        if ($subscription) {
            return $subscription->export();
        }

        return null;
    }

    /**
     * Create or get subscription record for a member
     *
     * @param int $memberId Member ID
     * @return object RedBeanPHP bean
     */
    public static function ensureSubscription(int $memberId): object {
        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);

        if (!$subscription) {
            $subscription = R::dispense('subscription');
            $subscription->member_id = $memberId;
            $subscription->tier = 'free';
            $subscription->status = 'active';
            $subscription->created_at = date('Y-m-d H:i:s');
            R::store($subscription);
        }

        return $subscription;
    }

    /**
     * Stub upgrade to Pro tier (for testing without Stripe)
     *
     * @param int $memberId Member ID
     * @return bool Success
     */
    public static function stubUpgrade(int $memberId): bool {
        $subscription = self::ensureSubscription($memberId);

        $subscription->tier = 'pro';
        $subscription->status = 'active';
        $subscription->stripe_customer_id = 'stub_' . $memberId;
        $subscription->stripe_subscription_id = 'stub_sub_' . time();
        $subscription->current_period_start = date('Y-m-d H:i:s');
        $subscription->current_period_end = date('Y-m-d H:i:s', strtotime('+1 month'));
        $subscription->updated_at = date('Y-m-d H:i:s');

        R::store($subscription);

        Flight::get('log')->info('Stub upgrade to Pro', ['member_id' => $memberId]);

        return true;
    }

    /**
     * Stub downgrade to Free tier (for testing)
     *
     * @param int $memberId Member ID
     * @return bool Success
     */
    public static function stubDowngrade(int $memberId): bool {
        $subscription = self::ensureSubscription($memberId);

        $subscription->tier = 'free';
        $subscription->status = 'active';
        $subscription->cancelled_at = date('Y-m-d H:i:s');
        $subscription->updated_at = date('Y-m-d H:i:s');

        R::store($subscription);

        Flight::get('log')->info('Stub downgrade to Free', ['member_id' => $memberId]);

        return true;
    }

    /**
     * Get Pro monthly price from config
     *
     * @return int Price in dollars
     */
    public static function getProMonthlyPrice(): int {
        try {
            return (int)(Flight::get('stripe.pro_monthly_price') ?? 150);
        } catch (\Exception $e) {
            return 150;
        }
    }

    /**
     * Get Pro yearly price from config
     *
     * @return int Price in dollars
     */
    public static function getProYearlyPrice(): int {
        try {
            return (int)(Flight::get('stripe.pro_yearly_price') ?? (self::getProMonthlyPrice() * 12));
        } catch (\Exception $e) {
            return self::getProMonthlyPrice() * 12;
        }
    }

    /**
     * Get tier display info
     *
     * @param string $tier Tier name
     * @return array
     */
    public static function getTierInfo(string $tier): array {
        $proMonthly = self::getProMonthlyPrice();

        $tiers = [
            'free' => [
                'name' => 'Free',
                'description' => 'Basic sprint analysis',
                'price' => '$0/month',
                'color' => 'secondary'
            ],
            'pro' => [
                'name' => 'Pro',
                'description' => 'Advanced analysis with priority weights, goals, and image analysis',
                'price' => '$' . number_format($proMonthly) . '/month',
                'color' => 'primary'
            ],
            'enterprise' => [
                'name' => 'Enterprise',
                'description' => 'Full access with repository integration',
                'price' => 'Contact us',
                'color' => 'warning'
            ]
        ];

        return $tiers[$tier] ?? $tiers['free'];
    }
}
