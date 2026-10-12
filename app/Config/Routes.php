<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');


$routes->group('api', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    // Endpoint publik (login) - no filter for testing
    $routes->post('login', 'AuthController::login');

    // Endpoint yang memerlukan autentikasi - perlu decrypt, signature, dan auth
    $routes->group('', ['filter' => ['api-decrypt', 'api-signature', 'api-auth']], static function ($routes) {
        $routes->post('logout', 'AuthController::logout');
        $routes->get('me', 'AuthController::me');
        $routes->get('checkpoints', 'CheckpointController::index');
        $routes->post('ticket/check', 'TicketController::check');
        $routes->post('ticket/validate-and-checkin', 'TicketController::validateAndCheckin');
        $routes->get('dashboard/summary', 'DashboardController::summary');
        $routes->get('ticket/history', 'TicketController::history');
    });
});
