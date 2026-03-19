<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// ─── API Routes ──────────────────────────────────────────────────────────────
// Documentation (no auth required)
$routes->get('api/docs', 'Api\AgentApiController::docs');

// Authenticated API endpoints
$routes->group('api', ['filter' => 'apiauth'], static function ($routes) {
    $routes->post('chat',                    'Api\AgentApiController::chat');
    $routes->get('sessions',                 'Api\AgentApiController::sessions');
    $routes->get('sessions/(:segment)',      'Api\AgentApiController::session/$1');
    $routes->post('sessions/(:segment)/archive', 'Api\AgentApiController::archiveSession/$1');
    $routes->get('status',                   'Api\AgentApiController::status');
});
