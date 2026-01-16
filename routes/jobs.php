<?php
/**
 * Jobs routes - special API endpoints + default routing
 */

use \Flight as Flight;

// Cleanup endpoint - uses api.api_key for auth, bypasses session auth
Flight::route('POST /jobs/cleanup', function() {
    $controller = new \app\Jobs();
    $controller->cleanup();
});

// Include default routes for standard Jobs controller methods
require_once __DIR__ . '/default.php';
