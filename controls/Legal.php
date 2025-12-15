<?php
/**
 * Legal Controller
 * Handles Terms of Service and Privacy Policy pages
 */

namespace app;

use \Flight as Flight;

class Legal extends BaseControls\Control {

    /**
     * Terms of Service
     */
    public function terms() {
        $this->render('legal/terms', [
            'title' => 'Terms of Service - MyCTOBot'
        ]);
    }

    /**
     * Privacy Policy
     */
    public function privacy() {
        $this->render('legal/privacy', [
            'title' => 'Privacy Policy - MyCTOBot'
        ]);
    }
}
