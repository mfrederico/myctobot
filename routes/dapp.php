<?php
/**
 * Hosted Detachable App Routes
 *
 * Serves detachable apps directly from MyCTOBot.
 * Catches all /dapp/* URLs and routes to the Dapp controller.
 *
 * Examples:
 *   /dapp/my-widget           → serve default screen
 *   /dapp/my-widget/about     → serve screen by route
 *   /dapp/my-widget/api/pipeline/support → proxy pipeline call
 */

use \Flight as Flight;
use \app\Dapp;

Flight::route('GET|POST|OPTIONS /dapp', function() {
    $controller = new Dapp();
    $controller->index();
});

Flight::route('GET|POST|OPTIONS /dapp/*', function() {
    $controller = new Dapp();
    $controller->index();
});
