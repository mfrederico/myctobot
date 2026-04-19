<?php
/**
 * Default routing pattern
 * This handles the standard /class/method/operation/id pattern
 * Include this file in your route files to get automatic routing
 */

use \Flight as Flight;

// Register the default routing pattern
Flight::defaultRoute();

// This single line handles:
// - /controller -> Controller->index()
// - /controller/method -> Controller->method()
// - /controller/method/param -> Controller->method(['operation' => (object)['name' => 'param']])
// - /controller/method/param/id -> Controller->method(['operation' => (object)['name' => 'param', 'type' => 'id']])

// All permission checking is handled automatically via the authcontrol table