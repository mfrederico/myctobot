<?php
/**
 * App Proxy Routes
 *
 * Proxies requests to tenant apps running on designated ports.
 * Catches all /app/* URLs and routes to the App controller.
 *
 * Examples:
 *   /app/contact-classifier/admin → proxy to port 9605/admin
 *   /app/lead-manager/api/leads → proxy to port 9601/api/leads
 */

use \Flight as Flight;
use \app\App;

// Match /app and /app/* for all HTTP methods
Flight::route('GET|POST|PUT|PATCH|DELETE|OPTIONS /app', function() {
    $controller = new App();
    $controller->index();
});

Flight::route('GET|POST|PUT|PATCH|DELETE|OPTIONS /app/*', function() {
    $controller = new App();
    $controller->index();
});
