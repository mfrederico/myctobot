<?php
/**
 * Index Controller
 * Handles the home page and public content
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class Index extends BaseControls\Control {
    
    /**
     * Home page
     */
    public function index() {
        // On public site, redirect to signup
        if (TenantResolver::isDefault()) {
            Flight::redirect('/signup');
            return;
        }

        // On tenant sites, show normal homepage
        $googleEnabled = !empty(Flight::get('social.google_client_id'));

        $this->render('index/index', [
            'title' => 'MyCTOBot - AI Sprint Digests',
            'googleEnabled' => $googleEnabled
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
