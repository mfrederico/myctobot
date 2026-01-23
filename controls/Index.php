<?php
/**
 * Index Controller
 * Handles the home page and public content
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use app\services\PricingService;

class Index extends BaseControls\Control {
    
    /**
     * Home page
     */
    public function index() {
        // On public site, redirect to signup
/*
        if (WorkspaceResolver::isDefault()) {
            Flight::redirect('/signup');
            return;
        }
*/

        // On workspace sites, show normal homepage
        $googleEnabled = !empty(Flight::get('social.google_client_id'));

        // Load pricing data for product cards
        try {
            $allProducts = PricingService::getAllProducts();
            $trialDays = PricingService::getTrialDays();
        } catch (\Exception $e) {
            Flight::get('log')->error("Failed to load pricing: " . $e->getMessage());
            $allProducts = [];
            $trialDays = 14;
        }

        $this->render('index/index', [
            'title' => 'MyCTOBot - AI Sprint Digests',
            'googleEnabled' => $googleEnabled,
            'allProducts' => $allProducts,
            'trialDays' => $trialDays
        ]);
    }
    
    /**
     * About page
     */
    public function about() {
        $this->render('index/about', [
            'title' => 'About Us'
        ]);
    }
    
    /**
     * Contact page
     */
    public function contact() {
        $this->render('index/contact', [
            'title' => 'Contact Us'
        ]);
    }
    
    /**
     * Process contact form
     */
    public function docontact() {
        // Validate CSRF
        if (!$this->validateCSRF()) {
            return;
        }
        
        $name = $this->sanitize($this->getParam('name'));
        $email = $this->sanitize($this->getParam('email'), 'email');
        $subject = $this->sanitize($this->getParam('subject'));
        $message = $this->sanitize($this->getParam('message'));
        
        // Validate input
        if (empty($name) || empty($email) || empty($message)) {
            $this->flash('error', 'Please fill in all required fields');
            Flight::redirect('/contact');
            return;
        }
        
        // TODO: Send email or save to database
        
        $this->flash('success', 'Thank you for your message. We will get back to you soon!');
        Flight::redirect('/contact');
    }
    
    /**
     * Privacy policy
     */
    public function privacy() {
        $this->render('index/privacy', [
            'title' => 'Privacy Policy'
        ]);
    }
    
    /**
     * Terms of service
     */
    public function terms() {
        $this->render('index/terms', [
            'title' => 'Terms of Service'
        ]);
    }
}
