<?php
/**
 * Stripe Service
 * Handles Stripe payment integration for subscriptions
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Stripe\Stripe;
use \Stripe\Customer;
use \Stripe\Checkout\Session as CheckoutSession;
use \Stripe\BillingPortal\Session as PortalSession;
use \Stripe\Webhook;
use \Stripe\Subscription;

class StripeService {

    private static bool $initialized = false;

    /**
     * Initialize Stripe with API key
     */
    private static function init(): void {
        if (self::$initialized) {
            return;
        }

        $secretKey = Flight::get('stripe.secret_key') ?? '';
        if (empty($secretKey)) {
            throw new \Exception('Stripe secret key not configured');
        }

        Stripe::setApiKey($secretKey);
        self::$initialized = true;
    }

    /**
     * Check if Stripe is configured
     */
    public static function isConfigured(): bool {
        try {
            $secretKey = Flight::get('stripe.secret_key');
            $publishableKey = Flight::get('stripe.publishable_key');
            return !empty($secretKey) && !empty($publishableKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get publishable key for frontend
     */
    public static function getPublishableKey(): string {
        return Flight::get('stripe.publishable_key') ?? '';
    }

    /**
     * Get or create Stripe customer for a member
     */
    public static function getOrCreateCustomer(int $memberId): string {
        self::init();

        // Check if member already has a Stripe customer ID
        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);

        if ($subscription && !empty($subscription->stripe_customer_id) &&
            strpos($subscription->stripe_customer_id, 'stub_') !== 0) {
            return $subscription->stripe_customer_id;
        }

        // Get member details
        $member = R::load('member', $memberId);
        if (!$member->id) {
            throw new \Exception('Member not found');
        }

        // Create new Stripe customer
        $customer = Customer::create([
            'email' => $member->email,
            'name' => $member->display_name ?? $member->email,
            'metadata' => [
                'member_id' => $memberId
            ]
        ]);

        // Save customer ID
        if (!$subscription) {
            $subscription = R::dispense('subscription');
            $subscription->member_id = $memberId;
            $subscription->tier = 'free';
            $subscription->status = 'active';
            $subscription->created_at = date('Y-m-d H:i:s');
        }
        $subscription->stripe_customer_id = $customer->id;
        $subscription->updated_at = date('Y-m-d H:i:s');
        R::store($subscription);

        return $customer->id;
    }

    /**
     * Get trial period days from config
     */
    public static function getTrialDays(): int {
        try {
            return (int)(Flight::get('stripe.trial_days') ?? 14);
        } catch (\Exception $e) {
            return 14; // Default to 14 days (1 sprint)
        }
    }

    /**
     * Check if member has already used a trial
     */
    public static function hasUsedTrial(int $memberId): bool {
        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);
        return $subscription && !empty($subscription->trial_used);
    }

    /**
     * Create a Checkout Session for subscription
     */
    public static function createCheckoutSession(int $memberId, string $priceId, bool $withTrial = true): CheckoutSession {
        self::init();

        $customerId = self::getOrCreateCustomer($memberId);
        $successUrl = Flight::get('stripe.success_url');
        $cancelUrl = Flight::get('stripe.cancel_url');

        $subscriptionData = [
            'metadata' => [
                'member_id' => $memberId
            ]
        ];

        // Add trial period if eligible (hasn't used trial before)
        if ($withTrial && !self::hasUsedTrial($memberId)) {
            $trialDays = self::getTrialDays();
            if ($trialDays > 0) {
                $subscriptionData['trial_period_days'] = $trialDays;
            }
        }

        $session = CheckoutSession::create([
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'member_id' => $memberId
            ],
            'subscription_data' => $subscriptionData
        ]);

        return $session;
    }

    /**
     * Create a billing portal session for managing subscription
     */
    public static function createPortalSession(int $memberId, string $returnUrl): PortalSession {
        self::init();

        $customerId = self::getOrCreateCustomer($memberId);

        $session = PortalSession::create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session;
    }

    /**
     * Handle Stripe webhook event
     */
    public static function handleWebhook(string $payload, string $sigHeader): array {
        self::init();

        $webhookSecret = Flight::get('stripe.webhook_secret') ?? '';

        if (empty($webhookSecret)) {
            throw new \Exception('Webhook secret not configured');
        }

        // Verify webhook signature
        $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);

        $result = ['handled' => false, 'type' => $event->type];

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                self::handleCheckoutComplete($session);
                $result['handled'] = true;
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                self::handleSubscriptionUpdate($subscription);
                $result['handled'] = true;
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                self::handleSubscriptionCanceled($subscription);
                $result['handled'] = true;
                break;

            case 'invoice.payment_succeeded':
                $invoice = $event->data->object;
                self::handlePaymentSucceeded($invoice);
                $result['handled'] = true;
                break;

            case 'invoice.payment_failed':
                $invoice = $event->data->object;
                self::handlePaymentFailed($invoice);
                $result['handled'] = true;
                break;
        }

        return $result;
    }

    /**
     * Handle checkout.session.completed
     */
    private static function handleCheckoutComplete($session): void {
        $memberId = $session->metadata->member_id ?? null;
        if (!$memberId) {
            Flight::get('log')->warning('Checkout complete without member_id', ['session_id' => $session->id]);
            return;
        }

        Flight::get('log')->info('Checkout completed', [
            'member_id' => $memberId,
            'session_id' => $session->id,
            'subscription_id' => $session->subscription
        ]);
    }

    /**
     * Handle subscription created/updated
     */
    private static function handleSubscriptionUpdate($stripeSubscription): void {
        $customerId = $stripeSubscription->customer;
        $memberId = $stripeSubscription->metadata->member_id ?? null;

        // Find member by customer ID if not in metadata
        if (!$memberId) {
            $subscription = R::findOne('subscription', 'stripe_customer_id = ?', [$customerId]);
            if ($subscription) {
                $memberId = $subscription->member_id;
            }
        }

        if (!$memberId) {
            Flight::get('log')->warning('Subscription update without member_id', [
                'subscription_id' => $stripeSubscription->id,
                'customer_id' => $customerId
            ]);
            return;
        }

        // Determine tier from price ID
        $tier = self::getTierFromPriceId($stripeSubscription->items->data[0]->price->id ?? '');

        // Update local subscription
        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);
        if (!$subscription) {
            $subscription = R::dispense('subscription');
            $subscription->member_id = $memberId;
            $subscription->created_at = date('Y-m-d H:i:s');
        }

        $subscription->tier = $tier;
        $subscription->stripe_customer_id = $customerId;
        $subscription->stripe_subscription_id = $stripeSubscription->id;
        $subscription->current_period_start = date('Y-m-d H:i:s', $stripeSubscription->current_period_start);
        $subscription->current_period_end = date('Y-m-d H:i:s', $stripeSubscription->current_period_end);
        $subscription->updated_at = date('Y-m-d H:i:s');

        // Handle trial period
        if ($stripeSubscription->trial_end) {
            $subscription->trial_ends_at = date('Y-m-d H:i:s', $stripeSubscription->trial_end);
            $subscription->trial_used = 1;
            // During trial, status is 'trialing' in Stripe
            $subscription->status = ($stripeSubscription->status === 'trialing') ? 'active' :
                                   (($stripeSubscription->status === 'active') ? 'active' : 'inactive');
        } else {
            $subscription->status = $stripeSubscription->status === 'active' ? 'active' : 'inactive';
        }

        if ($stripeSubscription->cancel_at_period_end) {
            $subscription->cancelled_at = date('Y-m-d H:i:s');
        } else {
            $subscription->cancelled_at = null;
        }

        R::store($subscription);

        Flight::get('log')->info('Subscription updated', [
            'member_id' => $memberId,
            'tier' => $tier,
            'status' => $subscription->status
        ]);
    }

    /**
     * Handle subscription canceled
     */
    private static function handleSubscriptionCanceled($stripeSubscription): void {
        $customerId = $stripeSubscription->customer;

        $subscription = R::findOne('subscription', 'stripe_customer_id = ?', [$customerId]);
        if (!$subscription) {
            return;
        }

        $subscription->tier = 'free';
        $subscription->status = 'active';
        $subscription->stripe_subscription_id = null;
        $subscription->cancelled_at = date('Y-m-d H:i:s');
        $subscription->updated_at = date('Y-m-d H:i:s');

        R::store($subscription);

        Flight::get('log')->info('Subscription canceled', [
            'member_id' => $subscription->member_id
        ]);
    }

    /**
     * Handle successful payment
     */
    private static function handlePaymentSucceeded($invoice): void {
        Flight::get('log')->info('Payment succeeded', [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer,
            'amount' => $invoice->amount_paid / 100
        ]);
    }

    /**
     * Handle failed payment
     */
    private static function handlePaymentFailed($invoice): void {
        Flight::get('log')->warning('Payment failed', [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer
        ]);

        // Optionally downgrade or notify user
        $subscription = R::findOne('subscription', 'stripe_customer_id = ?', [$invoice->customer]);
        if ($subscription) {
            $subscription->status = 'past_due';
            $subscription->updated_at = date('Y-m-d H:i:s');
            R::store($subscription);
        }
    }

    /**
     * Determine tier from Stripe price ID
     */
    private static function getTierFromPriceId(string $priceId): string {
        $proMonthly = Flight::get('stripe.pro_monthly_price_id') ?? '';
        $proYearly = Flight::get('stripe.pro_yearly_price_id') ?? '';
        $businessMonthly = Flight::get('stripe.business_monthly_price_id') ?? '';
        $businessYearly = Flight::get('stripe.business_yearly_price_id') ?? '';

        if ($priceId === $proMonthly || $priceId === $proYearly) {
            return 'pro';
        }

        if ($priceId === $businessMonthly || $priceId === $businessYearly) {
            return 'enterprise';
        }

        return 'pro'; // Default to pro for unknown prices
    }

    /**
     * Get price ID for a tier and interval
     */
    public static function getPriceId(string $tier, string $interval = 'monthly'): ?string {
        try {
            $key = "stripe.{$tier}_{$interval}_price_id";
            return Flight::get($key) ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Cancel subscription at period end
     */
    public static function cancelSubscription(int $memberId): bool {
        self::init();

        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);
        if (!$subscription || empty($subscription->stripe_subscription_id)) {
            return false;
        }

        try {
            $stripeSubscription = Subscription::update(
                $subscription->stripe_subscription_id,
                ['cancel_at_period_end' => true]
            );

            $subscription->cancelled_at = date('Y-m-d H:i:s');
            $subscription->updated_at = date('Y-m-d H:i:s');
            R::store($subscription);

            return true;
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to cancel subscription', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Reactivate canceled subscription
     */
    public static function reactivateSubscription(int $memberId): bool {
        self::init();

        $subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);
        if (!$subscription || empty($subscription->stripe_subscription_id)) {
            return false;
        }

        try {
            $stripeSubscription = Subscription::update(
                $subscription->stripe_subscription_id,
                ['cancel_at_period_end' => false]
            );

            $subscription->cancelled_at = null;
            $subscription->updated_at = date('Y-m-d H:i:s');
            R::store($subscription);

            return true;
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to reactivate subscription', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
