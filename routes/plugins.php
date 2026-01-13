<?php
/**
 * Plugin Routes
 *
 * Routes for plugin marketplace features.
 */

use \Flight as Flight;

// Plugin browsing
Flight::route('GET /plugins', ['\\app\\Plugins', 'index']);
Flight::route('GET /plugins/search', ['\\app\\Plugins', 'search']);
Flight::route('GET /plugins/categories', ['\\app\\Plugins', 'categories']);
Flight::route('GET /plugins/view/@id', ['\\app\\Plugins', 'view']);
Flight::route('POST /plugins/autocomplete', ['\\app\\Plugins', 'autocomplete']);

// Plugin sources management
Flight::route('GET /pluginsources', ['\\app\\PluginSources', 'index']);
Flight::route('GET /pluginsources/add', ['\\app\\PluginSources', 'add']);
Flight::route('POST /pluginsources/store', ['\\app\\PluginSources', 'store']);
Flight::route('POST /pluginsources/validate', ['\\app\\PluginSources', 'validate']);
Flight::route('POST /pluginsources/delete/@id', ['\\app\\PluginSources', 'delete']);
Flight::route('POST /pluginsources/toggle/@id', ['\\app\\PluginSources', 'toggle']);

// Plugin registry (admin)
Flight::route('GET /pluginregistry', ['\\app\\PluginRegistry', 'index']);
