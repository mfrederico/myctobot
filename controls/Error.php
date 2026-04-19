<?php
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Error extends Control {
    
    /**
     * 404 Not Found
     */
    public function notfound($params = []) {
        Flight::response()->status(404);
        $this->viewData['title'] = '404 - Page Not Found';
        $this->render('error/404', $this->viewData, false);
    }
    
    /**
     * 403 Forbidden
     */
    public function forbidden($params = []) {
        Flight::response()->status(403);
        $this->viewData['title'] = '403 - Forbidden';
        $this->viewData['message'] = 'You do not have permission to access this resource.';
        
        // Check if it's a CSRF error
        if (isset($_SESSION['csrf_error'])) {
            $this->viewData['message'] = $_SESSION['csrf_error'];
            unset($_SESSION['csrf_error']);
        }
        
        $this->render('error/403', $this->viewData, false);
    }
    
    /**
     * 500 Server Error
     */
    public function servererror($params = []) {
        Flight::response()->status(500);
        $this->viewData['title'] = '500 - Server Error';
        $this->render('error/500', $this->viewData, false);
    }
    
    /**
     * Maintenance mode
     */
    public function maintenance($params = []) {
        Flight::response()->status(503);
        $this->viewData['title'] = 'Maintenance Mode';
        $this->viewData['message'] = 'The site is currently undergoing maintenance. Please check back soon.';
        $this->render('error/maintenance', $this->viewData, false);
    }
}