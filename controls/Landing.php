<?php
/**
 * Landing Controller
 * Serves product landing pages with dynamic pricing
 *
 * Routes:
 *   /landing/ai-developer     -> AI Developer landing page
 *   /landing/pipelines        -> Pipelines landing page
 *   /landing/code-review      -> Code Review landing page
 *   /landing/php-modernization -> PHP Modernization landing page
 *   /landing/shopify-themes   -> Shopify Themes landing page
 */

namespace app;

use \Flight as Flight;
use app\services\PricingService;

class Landing extends BaseControls\Control {

    /**
     * Product slug to view mapping
     */
    private array $products = [
        'ai-developer' => [
            'view' => 'landing/ai-developer',
            'title' => 'AI Developer - Your Development Team That Never Sleeps',
            'product_key' => 'ai-developer'
        ],
        'pipelines' => [
            'view' => 'landing/pipelines',
            'title' => 'AI Pipelines - Automation with AI Agents',
            'product_key' => 'pipelines'
        ],
        'code-review' => [
            'view' => 'landing/code-review',
            'title' => 'AI Code Review - Automated PR Analysis',
            'product_key' => 'code-review'
        ],
        'php-modernization' => [
            'view' => 'landing/php-modernization',
            'title' => 'PHP Modernization - AI-Powered PHP 8.x Upgrades',
            'product_key' => 'php-modernization'
        ],
        'shopify-themes' => [
            'view' => 'landing/shopify-themes',
            'title' => 'Shopify Theme Factory - Email to Theme',
            'product_key' => 'shopify-themes'
        ],
        'why-php' => [
            'view' => 'landing/why-php',
            'title' => 'Why PHP? The Case for the Web\'s Most Battle-Tested Language',
            'product_key' => 'platform' // Uses platform pricing
        ],
        'shopify-ai' => [
            'view' => 'landing/shopify-ai',
            'title' => 'Shopify Automations Your AI Can Control | MyCTOBot',
            'product_key' => 'platform'
        ]
    ];

    /**
     * Landing page index - redirects to main site
     */
    public function index() {
        Flight::redirect('/');
    }

    /**
     * AI Developer landing page
     */
    public function aideveloper() {
        $this->renderLanding('ai-developer');
    }

    /**
     * Pipelines landing page
     */
    public function pipelines() {
        $this->renderLanding('pipelines');
    }

    /**
     * Code Review landing page
     */
    public function codereview() {
        $this->renderLanding('code-review');
    }

    /**
     * PHP Modernization landing page
     */
    public function phpmodernization() {
        $this->renderLanding('php-modernization');
    }

    /**
     * Shopify Themes landing page
     */
    public function shopifythemes() {
        $this->renderLanding('shopify-themes');
    }

    /**
     * Why PHP landing page
     */
    public function whyphp() {
        $this->renderLanding('why-php');
    }

    /**
     * Shopify AI landing page
     */
    public function shopifyai() {
        $this->renderLanding('shopify-ai');
    }

    /**
     * Render a landing page with pricing data
     *
     * @param string $slug Product slug
     */
    private function renderLanding(string $slug): void {
        if (!isset($this->products[$slug])) {
            Flight::notFound();
            return;
        }

        $product = $this->products[$slug];

        try {
            $pricing = PricingService::getPricingData($product['product_key']);
            $allProducts = PricingService::getAllProducts();
            $trialDays = PricingService::getTrialDays();
        } catch (\Exception $e) {
            Flight::get('log')->error("Failed to load pricing: " . $e->getMessage());
            $pricing = [];
            $allProducts = [];
            $trialDays = 14;
        }

        // Landing pages are self-contained HTML - don't wrap in layout
        $this->render($product['view'], [
            'title' => $product['title'],
            'pricing' => $pricing,
            'allProducts' => $allProducts,
            'trialDays' => $trialDays,
            'currentProduct' => $slug
        ], false);
    }

    /**
     * API endpoint for pricing data (for JavaScript)
     */
    public function pricing() {
        $product = $this->getParam('product');

        try {
            if ($product) {
                $data = PricingService::getPricingData($product);
            } else {
                $data = PricingService::getAllProducts();
            }

            Flight::json([
                'success' => true,
                'data' => $data,
                'currency' => PricingService::getCurrencySymbol(),
                'trial_days' => PricingService::getTrialDays()
            ]);
        } catch (\Exception $e) {
            Flight::json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
