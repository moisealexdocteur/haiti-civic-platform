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

/*
 * --------------------------------------------------------------------
 * Platform Health Check
 * --------------------------------------------------------------------
 */
$routes->get('health', 'Health::index');
