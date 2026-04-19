<?php
declare(strict_types=1);
/**
 * {{APP_NAME}} - Standalone Tenant App
 *
 * Self-contained OpenSwoole app with mini FlightPHP-style framework.
 * Workspace: {{WORKSPACE}}
 * Generated: {{GENERATED_AT}}
 */

// Load mini-framework
require_once __DIR__ . '/lib/Bean.php';
require_once __DIR__ . '/lib/Router.php';
require_once __DIR__ . '/lib/Control.php';
require_once __DIR__ . '/lib/App.php';

// Autoload controllers
spl_autoload_register(function($class) {
    if (str_starts_with($class, 'TenantApp\\Controls\\')) {
        $file = __DIR__ . '/controls/' . substr($class, 19) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Create and configure app
$app = new \TenantApp\App(__DIR__);
$app->loadConfig();
$app->initDatabase();
$app->initRouter();
$app->addHealthCheck('{{HEALTH_PATH}}');

// Run
$app->run('{{HOST}}', {{PORT}});
