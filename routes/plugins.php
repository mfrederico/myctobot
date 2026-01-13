<?php
/**
 * Plugin Routes
 *
 * Routes for plugin marketplace browsing.
 */

use \Flight as Flight;

// Plugin browsing
Flight::route('GET /plugins', ['\\app\\Plugins', 'index']);
Flight::route('GET /plugins/search', ['\\app\\Plugins', 'search']);
Flight::route('GET /plugins/categories', ['\\app\\Plugins', 'categories']);
Flight::route('GET /plugins/view/@id', ['\\app\\Plugins', 'view']);
Flight::route('POST /plugins/autocomplete', ['\\app\\Plugins', 'autocomplete']);
