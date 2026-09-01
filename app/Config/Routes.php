<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

/*
 * --------------------------------------------------------------------
 * Platform Health Check
 * --------------------------------------------------------------------
 */
$routes->get('health', 'Health::index');
