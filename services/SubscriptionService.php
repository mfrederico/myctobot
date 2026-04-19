<?php
/**
 * Subscription Service
 * Manages WORKSPACE-level subscription tiers and feature access
 *
 * In multi-workspace mode, each workspace database has ONE subscription
 * that applies to ALL members in that workspace.
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

class SubscriptionService {

    /**
     * Get the workspace subscription tier
     *
     * Each workspace database has a single subscription record.
     * All members in the workspace share this tier.
     *
     * @return string Tier name ('free', 'pro', 'enterprise')
     */
    public static function getTier(): string {
        $subscription = self::getWorkspaceSubscription();

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
     * Get subscription tier for a member (legacy compatibility)
     *
     * @param int $memberId Member ID (ignored - uses workspace subscription)
     * @return string Tier name
     * @deprecated Use getTier() instead - subscriptions are workspace-level
     */
    public static function getTierForMember(int $memberId): string {
        return self::getTier();
    }

    /**
     * Check if workspace has Pro tier or higher
     *
     * @return bool
     */
    public static function isPro(): bool {
        $tier = self::getTier();
        return in_array($tier, ['pro', 'enterprise']);
    }

    /**
     * Check if workspace has Enterprise tier
     *
     * @return bool
     */
    public static function isEnterprise(): bool {
        return self::getTier() === 'enterprise';
    }

    /**
     * Check if workspace can access a specific feature
     *
     * @param string $feature Feature name
     * @return bool
     */
    public static function canAccessFeature(string $feature): bool {
        $tier = self::getTier();
        $features = TierFeatures::getFeatures($tier);

        return $features[$feature] ?? false;
    }

    /**
     * Get remaining quota for a rate-limited feature
     *
     * @param string $feature Feature name
     * @return int Remaining uses (-1 for unlimited)
     */
    public static function getRemainingQuota(string $feature): int {
        $tier = self::getTier();
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
     * Get the workspace subscription record
     *
     * @return object|null RedBeanPHP bean or null
     */
    public static function getWorkspaceSubscription(): ?object {
        return Bean::findOne('subscription', 'status = ? ORDER BY id DESC', ['active']);
    }

    /**
     * Get subscription details for the workspace
     *
     * @return array|null
     */
    public static function getSubscription(): ?array {
        $subscription = self::getWorkspaceSubscription();

        if ($subscription) {
            return $subscription->export();
        }

        return null;
    }

    /**
     * Create or get subscription record for the workspace
     *
     * @param string|null $billingEmail Optional billing email
     * @param string|null $billingName Optional billing name
     * @return object RedBeanPHP bean
     */
    public static function ensureSubscription(?string $billingEmail = null, ?string $billingName = null): object {
        // Check for existing active subscription
        $existing = self::getWorkspaceSubscription();

        if ($existing) {
            // Update billing info if provided
            if ($billingEmail) {
                $existing->billing_email = $billingEmail;
            }
            if ($billingName) {
                $existing->billing_name = $billingName;
            }
            if ($billingEmail || $billingName) {
                $existing->updated_at = date('Y-m-d H:i:s');
                Bean::store($existing);
            }
            return $existing;
        }

        // Create new workspace subscription
        $subscription = Bean::dispense('subscription');
        $subscription->tier = 'free';
        $subscription->status = 'active';
        $subscription->billing_email = $billingEmail;
        $subscription->billing_name = $billingName;
        $subscription->created_at = date('Y-m-d H:i:s');
        Bean::store($subscription);

        return $subscription;
    }

    /**
     * Update billing contact for the workspace
     *
     * @param string $email Billing email
     * @param string|null $name Billing name
     * @return bool Success
     */
    public static function updateBillingContact(string $email, ?string $name = null): bool {
        $subscription = self::ensureSubscription($email, $name);
        return $subscription->id > 0;
    }

    /**
     * Stub upgrade to Pro tier (for testing without Stripe)
     *
     * @return bool Success
     */
    public static function stubUpgrade(): bool {
        $subscription = self::ensureSubscription();

        $subscription->tier = 'pro';
        $subscription->status = 'active';
        $subscription->stripe_customer_eid = 'stub_workspace';
        $subscription->stripe_subscription_eid = 'stub_sub_' . time();
        $subscription->current_period_start = date('Y-m-d H:i:s');
        $subscription->current_period_end = date('Y-m-d H:i:s', strtotime('+1 month'));
        $subscription->updated_at = date('Y-m-d H:i:s');

        Bean::store($subscription);

        Flight::get('log')->info('Stub upgrade workspace to Pro');

        return true;
    }

    /**
     * Stub downgrade to Free tier (for testing)
     *
     * @return bool Success
     */
    public static function stubDowngrade(): bool {
        $subscription = self::ensureSubscription();

        $subscription->tier = 'free';
        $subscription->status = 'active';
        $subscription->cancelled_at = date('Y-m-d H:i:s');
        $subscription->updated_at = date('Y-m-d H:i:s');

        Bean::store($subscription);

        Flight::get('log')->info('Stub downgrade workspace to Free');

        return true;
    }

    /**
     * Manually set workspace subscription tier (for Enterprise customers)
     *
     * @param string $tier Tier name ('free', 'pro', 'enterprise')
     * @param string|null $expiresAt Optional expiration date (null = never expires)
     * @return bool Success
     */
    public static function setTier(string $tier, ?string $expiresAt = null): bool {
        if (!in_array($tier, ['free', 'pro', 'enterprise'])) {
            return false;
        }

        $subscription = self::ensureSubscription();

        $subscription->tier = $tier;
        $subscription->status = 'active';
        $subscription->stripe_customer_eid = $subscription->stripe_customer_eid ?? 'manual_workspace';
        $subscription->stripe_subscription_eid = 'manual_' . $tier . '_' . time();
        $subscription->current_period_start = date('Y-m-d H:i:s');
        $subscription->current_period_end = $expiresAt ?? date('Y-m-d H:i:s', strtotime('+100 years'));
        $subscription->updated_at = date('Y-m-d H:i:s');

        Bean::store($subscription);

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        Flight::get('log')->info('Manual workspace tier assignment', [
            'workspace' => $workspace,
            'tier' => $tier,
            'expires' => $expiresAt ?? 'never'
        ]);

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

    // =========================================================================
    // Legacy compatibility methods (member-based calls redirect to workspace)
    // =========================================================================

    /**
     * @deprecated Use isPro() instead
     */
    public static function isProForMember(int $memberId): bool {
        return self::isPro();
    }

    /**
     * @deprecated Use isEnterprise() instead
     */
    public static function isEnterpriseForMember(int $memberId): bool {
        return self::isEnterprise();
    }

    /**
     * @deprecated Use canAccessFeature($feature) instead
     */
    public static function canAccessFeatureForMember(int $memberId, string $feature): bool {
        return self::canAccessFeature($feature);
    }
}
