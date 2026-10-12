<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');


$routes->group('api', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    // Endpoint publik
    $routes->post('login', 'AuthController::login');

    // Endpoint yang memerlukan autentikasi
    $routes->group('', ['filter' => 'api-auth'], static function ($routes) {
        $routes->post('logout', 'AuthController::logout');
    });
});
