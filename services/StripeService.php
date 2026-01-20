<?php
/**
 * Stripe Service
 * Handles Stripe payment integration for WORKSPACE-level subscriptions
 *
 * In multi-workspace mode, each workspace has ONE subscription.
 * Any admin can manage billing on behalf of the workspace.
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;
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
     * Get or create Stripe customer for the workspace
     *
     * @param string $billingEmail Email for the billing contact
     * @param string|null $billingName Name for the billing contact
     * @return string Stripe customer ID
     */
    public static function getOrCreateCustomer(string $billingEmail, ?string $billingName = null): string {
        self::init();

        // Get workspace subscription
        $subscription = SubscriptionService::getWorkspaceSubscription();

        // Check for existing Stripe customer
        if ($subscription && !empty($subscription->stripe_customer_id) &&
            strpos($subscription->stripe_customer_id, 'stub_') !== 0 &&
            strpos($subscription->stripe_customer_id, 'manual_') !== 0) {

            // Update customer email if different
            try {
                $customer = Customer::retrieve($subscription->stripe_customer_id);
                if ($customer->email !== $billingEmail) {
                    Customer::update($subscription->stripe_customer_id, [
                        'email' => $billingEmail,
                        'name' => $billingName ?? $billingEmail
                    ]);
                }
            } catch (\Exception $e) {
                // Customer may not exist, create new one
                Flight::get('log')->warning('Stripe customer not found, creating new', [
                    'old_customer_id' => $subscription->stripe_customer_id
                ]);
            }

            if (isset($customer) && $customer->id) {
                return $customer->id;
            }
        }

        // Create new Stripe customer for workspace
        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $customer = Customer::create([
            'email' => $billingEmail,
            'name' => $billingName ?? $billingEmail,
            'metadata' => [
                'workspace' => $workspace
            ]
        ]);

        // Save customer ID to workspace subscription
        $subscription = SubscriptionService::ensureSubscription($billingEmail, $billingName);
        $subscription->stripe_customer_id = $customer->id;
        $subscription->updated_at = date('Y-m-d H:i:s');
        Bean::store($subscription);

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
     * Check if workspace has already used a trial
     */
    public static function hasUsedTrial(): bool {
        $subscription = SubscriptionService::getWorkspaceSubscription();
        return $subscription && !empty($subscription->trial_used);
    }

    /**
     * Create a Checkout Session for workspace subscription
     *
     * @param string $billingEmail Billing contact email
     * @param string $priceId Stripe price ID
     * @param bool $withTrial Whether to include trial period
     * @return CheckoutSession
     */
    public static function createCheckoutSession(string $billingEmail, string $priceId, bool $withTrial = true): CheckoutSession {
        self::init();

        $customerId = self::getOrCreateCustomer($billingEmail);
        $successUrl = Flight::get('stripe.success_url');
        $cancelUrl = Flight::get('stripe.cancel_url');
        $workspace = $_SESSION['workspace_slug'] ?? 'default';

        $subscriptionData = [
            'metadata' => [
                'workspace' => $workspace
            ]
        ];

        // Add trial period if eligible (workspace hasn't used trial before)
        if ($withTrial && !self::hasUsedTrial()) {
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
                'workspace' => $workspace
            ],
            'subscription_data' => $subscriptionData
        ]);

        return $session;
    }

    /**
     * Create a billing portal session for managing subscription
     *
     * @param string $returnUrl URL to return to after portal
     * @return PortalSession
     */
    public static function createPortalSession(string $returnUrl): PortalSession {
        self::init();

        $subscription = SubscriptionService::getWorkspaceSubscription();
        if (!$subscription || empty($subscription->stripe_customer_id)) {
            throw new \Exception('No billing account found for this workspace');
        }

        $session = PortalSession::create([
            'customer' => $subscription->stripe_customer_id,
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
        $workspace = $session->metadata->workspace ?? 'unknown';

        Flight::get('log')->info('Checkout completed', [
            'workspace' => $workspace,
            'session_id' => $session->id,
            'subscription_id' => $session->subscription
        ]);
    }

    /**
     * Handle subscription created/updated
     */
    private static function handleSubscriptionUpdate($stripeSubscription): void {
        $customerId = $stripeSubscription->customer;

        // Find workspace subscription by Stripe customer ID
        $subscription = Bean::findOne('subscription', 'stripe_customer_id = ?', [$customerId]);

        if (!$subscription) {
            Flight::get('log')->warning('Subscription update for unknown customer', [
                'subscription_id' => $stripeSubscription->id,
                'customer_id' => $customerId
            ]);
            return;
        }

        // Determine tier from price ID
        $tier = self::getTierFromPriceId($stripeSubscription->items->data[0]->price->id ?? '');

        $subscription->tier = $tier;
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

        Bean::store($subscription);

        Flight::get('log')->info('Workspace subscription updated', [
            'tier' => $tier,
            'status' => $subscription->status
        ]);
    }

    /**
     * Handle subscription canceled
     */
    private static function handleSubscriptionCanceled($stripeSubscription): void {
        $customerId = $stripeSubscription->customer;

        $subscription = Bean::findOne('subscription', 'stripe_customer_id = ?', [$customerId]);
        if (!$subscription) {
            return;
        }

        $subscription->tier = 'free';
        $subscription->status = 'active';
        $subscription->stripe_subscription_id = null;
        $subscription->cancelled_at = date('Y-m-d H:i:s');
        $subscription->updated_at = date('Y-m-d H:i:s');

        Bean::store($subscription);

        Flight::get('log')->info('Workspace subscription canceled');
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

        // Mark workspace subscription as past_due
        $subscription = Bean::findOne('subscription', 'stripe_customer_id = ?', [$invoice->customer]);
        if ($subscription) {
            $subscription->status = 'past_due';
            $subscription->updated_at = date('Y-m-d H:i:s');
            Bean::store($subscription);
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
     * Cancel workspace subscription at period end
     */
    public static function cancelSubscription(): bool {
        self::init();

        $subscription = SubscriptionService::getWorkspaceSubscription();

        if (!$subscription || empty($subscription->stripe_subscription_id)) {
            return false;
        }

        try {
            Subscription::update(
                $subscription->stripe_subscription_id,
                ['cancel_at_period_end' => true]
            );

            $subscription->cancelled_at = date('Y-m-d H:i:s');
            $subscription->updated_at = date('Y-m-d H:i:s');
            Bean::store($subscription);

            return true;
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to cancel subscription', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Reactivate canceled workspace subscription
     */
    public static function reactivateSubscription(): bool {
        self::init();

        $subscription = SubscriptionService::getWorkspaceSubscription();

        if (!$subscription || empty($subscription->stripe_subscription_id)) {
            return false;
        }

        try {
            Subscription::update(
                $subscription->stripe_subscription_id,
                ['cancel_at_period_end' => false]
            );

            $subscription->cancelled_at = null;
            $subscription->updated_at = date('Y-m-d H:i:s');
            Bean::store($subscription);

            return true;
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to reactivate subscription', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
