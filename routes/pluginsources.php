<?php
/**
 * Plugin Sources Routes
 */

use \Flight as Flight;

Flight::route('GET /pluginsources', ['\\app\\Pluginsources', 'index']);
Flight::route('GET /pluginsources/add', ['\\app\\Pluginsources', 'add']);
Flight::route('POST /pluginsources/store', ['\\app\\Pluginsources', 'store']);
Flight::route('POST /pluginsources/validate', ['\\app\\Pluginsources', 'validate']);
Flight::route('POST /pluginsources/delete/@id', ['\\app\\Pluginsources', 'delete']);
Flight::route('POST /pluginsources/toggle/@id', ['\\app\\Pluginsources', 'toggle']);
