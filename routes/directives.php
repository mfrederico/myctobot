<?php
/**
 * Directives Routes
 *
 * Routes for CEO directive endpoints.
 */

use \Flight as Flight;

// CEO Directive endpoints
Flight::route('POST /directives/receive', ['\app\Directives', 'receive']);
Flight::route('GET /directives/schema', ['\app\Directives', 'schema']);
Flight::route('POST /directives/validate', ['\app\Directives', 'validateOnly']);

// Fall through to default routes for other /directives/* paths
Flight::defaultRoute();
