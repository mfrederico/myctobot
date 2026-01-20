<?php
/**
 * Pipein Routes
 *
 * Webhook endpoint for triggering pipelines externally.
 * Catches all /pipein/* URLs and routes to the controller.
 */

use \Flight as Flight;
use \app\Pipein;

// Match /pipein/{tenant}/{slug} and any subpaths
Flight::route('GET|POST /pipein/*', function() {
    $controller = new Pipein();
    $controller->index();
});
