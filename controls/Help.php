<?php
/**
 * Help Controller
 * Provides help and documentation pages
 */

namespace app;

use \Flight as Flight;

class Help extends BaseControls\Control {
    
    /**
     * Main help page
     */
    public function index() {
        $this->render('help/index', [
            'title' => 'Help Center'
        ]);
    }
}