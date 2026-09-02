<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'CitizenPortal::home');
$routes->get('inscription', 'CitizenPortal::locate');
$routes->get(
    'inscription/(:segment)',
    'CitizenPortal::register/$1'
);
$routes->post(
    'inscription/(:segment)',
    'CitizenPortal::submit/$1',
    ['filter' => 'csrf']
);

$routes->get('admin/login', 'AdminAuth::login');
$routes->post(
    'admin/login',
    'AdminAuth::authenticate',
    ['filter' => 'csrf']
);
$routes->post(
    'admin/logout',
    'AdminAuth::logout',
    ['filter' => ['adminauth', 'csrf']]
);

$routes->get('health', 'Health::index');
