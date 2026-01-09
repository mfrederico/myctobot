<?php
/**
 * Login routes - handles /login and /login/{workspace} patterns
 *
 * URL patterns:
 *   /login          → Auth->login() (main site login, no workspace)
 *   /login/gwt      → Auth->login(['workspace' => 'gwt'])
 *   /login/acme     → Auth->login(['workspace' => 'acme'])
 */

use \Flight as Flight;

// Route /login/{workspace} to Auth->login with workspace parameter
Flight::route('/login(/@workspace)', function($workspace = null) {
    Flight::view()->set('LEVELS', LEVELS);

    $controllerClass = '\\app\\Auth';

    // Check permissions for auth::login
    if (!Flight::permissionFor('auth', 'login', Flight::getMember()->level)) {
        Flight::redirect('/');
        return;
    }

    try {
        $controller = new $controllerClass;

        // Build params similar to defaultRoute
        $params = [];
        if ($workspace) {
            $params['workspace'] = $workspace;
        }

        $controller->login($params);
    } catch (\Throwable $e) {
        Flight::get('log')->error("Login route error: " . $e->getMessage());
        Flight::notFound();
    }
});
