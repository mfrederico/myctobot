<?php
/**
 * Plugin Sources Routes
 */

use \Flight as Flight;

Flight::route('GET /pluginsources', ['\\app\\PluginSources', 'index']);
Flight::route('GET /pluginsources/add', ['\\app\\PluginSources', 'add']);
Flight::route('POST /pluginsources/store', ['\\app\\PluginSources', 'store']);
Flight::route('POST /pluginsources/validate', ['\\app\\PluginSources', 'validate']);
Flight::route('POST /pluginsources/delete/@id', ['\\app\\PluginSources', 'delete']);
Flight::route('POST /pluginsources/toggle/@id', ['\\app\\PluginSources', 'toggle']);
