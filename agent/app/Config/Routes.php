<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// ─── API Routes ──────────────────────────────────────────────────────────────
// Documentation (no auth required)
$routes->get('api/docs', 'Api\AgentApiController::docs');

// CORS preflight for browser extension endpoints
$routes->options('api/browser/pending', static function () {
    return service('response')
        ->setHeader('Access-Control-Allow-Origin', '*')
        ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
        ->setHeader('Access-Control-Max-Age', '86400')
        ->setStatusCode(204);
});
$routes->options('api/browser/result', static function () {
    return service('response')
        ->setHeader('Access-Control-Allow-Origin', '*')
        ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
        ->setHeader('Access-Control-Max-Age', '86400')
        ->setStatusCode(204);
});
$routes->options('api/browser/status', static function () {
    return service('response')
        ->setHeader('Access-Control-Allow-Origin', '*')
        ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
        ->setHeader('Access-Control-Max-Age', '86400')
        ->setStatusCode(204);
});

// Authenticated API endpoints
$routes->group('api', ['filter' => 'apiauth'], static function ($routes) {
    $routes->post('chat',                    'Api\AgentApiController::chat');
    $routes->get('sessions',                 'Api\AgentApiController::sessions');
    $routes->get('sessions/(:segment)',      'Api\AgentApiController::session/$1');
    $routes->post('sessions/(:segment)/archive', 'Api\AgentApiController::archiveSession/$1');
    $routes->get('status',                   'Api\AgentApiController::status');

    // Browser control (Chrome extension endpoints)
    $routes->get('browser/pending',          'Api\AgentApiController::browserPending');
    $routes->post('browser/result',          'Api\AgentApiController::browserResult');
    $routes->get('browser/status',           'Api\AgentApiController::browserStatus');
});
