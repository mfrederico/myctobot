<?php
/**
 * Plugin Registry Routes
 */

use \Flight as Flight;

Flight::route('GET /pluginregistry', ['\\app\\Pluginregistry', 'index']);
