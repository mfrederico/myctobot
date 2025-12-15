<?php
/**
 * Stripe Controller
 * Handles Stripe checkout and webhooks
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\StripeService;

class Stripe extends BaseControls\Control {

    /**
     * Create checkout session and redirect to Stripe
     */
    public function checkout() {
        if (!$this->requireLogin()) return;

        if (!StripeService::isConfigured()) {
            $this->flash('error', 'Payment system not configured');
            Flight::redirect('/settings/subscription');
            return;
        }

        $tier = $this->getParam('tier') ?? 'pro';
        $interval = $this->getParam('interval') ?? 'monthly';

        $priceId = StripeService::getPriceId($tier, $interval);
        if (!$priceId) {
            $this->flash('error', 'Invalid subscription plan');
            Flight::redirect('/settings/subscription');
            return;
        }

        try {
            $session = StripeService::createCheckoutSession($this->member->id, $priceId);

            // Redirect to Stripe Checkout
            Flight::redirect($session->url);

        } catch (Exception $e) {
            $this->logger->error('Stripe checkout error: ' . $e->getMessage());
            $this->flash('error', 'Unable to start checkout: ' . $e->getMessage());
            Flight::redirect('/settings/subscription');
        }
    }

    /**
     * Create portal session for managing subscription
     */
    public function portal() {
        if (!$this->requireLogin()) return;

        if (!StripeService::isConfigured()) {
            $this->flash('error', 'Payment system not configured');
            Flight::redirect('/settings/subscription');
            return;
        }

        try {
            $returnUrl = Flight::get('config')['app']['baseurl'] . '/settings/subscription';
            $session = StripeService::createPortalSession($this->member->id, $returnUrl);

            // Redirect to Stripe Customer Portal
            Flight::redirect($session->url);

        } catch (Exception $e) {
            $this->logger->error('Stripe portal error: ' . $e->getMessage());
            $this->flash('error', 'Unable to access billing portal: ' . $e->getMessage());
            Flight::redirect('/settings/subscription');
        }
    }

    /**
     * Handle Stripe webhooks
     * This endpoint should be excluded from CSRF and session checks
     */
    public function webhook() {
        // Get raw POST body
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (empty($payload) || empty($sigHeader)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing payload or signature']);
            return;
        }

        try {
            $result = StripeService::handleWebhook($payload, $sigHeader);

            $this->logger->info('Stripe webhook processed', $result);

            http_response_code(200);
            echo json_encode(['received' => true, 'handled' => $result['handled']]);

        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            $this->logger->warning('Stripe webhook invalid payload: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            $this->logger->warning('Stripe webhook invalid signature: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);

        } catch (Exception $e) {
            $this->logger->error('Stripe webhook error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Webhook handler error']);
        }
    }

    /**
     * Cancel subscription (AJAX)
     */
    public function cancel() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            $this->jsonError('Invalid request method');
            return;
        }

        try {
            $success = StripeService::cancelSubscription($this->member->id);

            if ($success) {
                $this->jsonSuccess([], 'Subscription will be canceled at the end of the billing period');
            } else {
                $this->jsonError('Unable to cancel subscription');
            }

        } catch (Exception $e) {
            $this->logger->error('Cancel subscription error: ' . $e->getMessage());
            $this->jsonError('Error canceling subscription: ' . $e->getMessage());
        }
    }

    /**
     * Reactivate canceled subscription (AJAX)
     */
    public function reactivate() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            $this->jsonError('Invalid request method');
            return;
        }

        try {
            $success = StripeService::reactivateSubscription($this->member->id);

            if ($success) {
                $this->jsonSuccess([], 'Subscription reactivated');
            } else {
                $this->jsonError('Unable to reactivate subscription');
            }

        } catch (Exception $e) {
            $this->logger->error('Reactivate subscription error: ' . $e->getMessage());
            $this->jsonError('Error reactivating subscription: ' . $e->getMessage());
        }
    }
}
