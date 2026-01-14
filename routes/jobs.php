<?php
/**
 * Jobs routes - for internal API endpoints that use their own auth
 */

use \Flight as Flight;

// Cleanup endpoint - uses api.api_key for auth, bypasses session auth
Flight::route('POST /jobs/cleanup', function() {
    $controller = new \app\Jobs();
    $controller->cleanup();
});
