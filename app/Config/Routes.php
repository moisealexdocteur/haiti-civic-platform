<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'CitizenPortal::home');
$routes->get('structures-politiques', 'PoliticalStructures::index');
$routes->get('inscription', 'CitizenPortal::locate');
$routes->get(
    'inscription/(:segment)',
    'CitizenPortal::register/$1'
);
$routes->post(
    'inscription/(:segment)/otp/demander',
    'CitizenPortal::requestOtp/$1',
    ['filter' => 'csrf']
);
$routes->post(
    'inscription/(:segment)/otp/verifier',
    'CitizenPortal::verifyOtp/$1',
    ['filter' => 'csrf']
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

$routes->get(
    'admin/identites',
    'AdminIdentities::index',
    ['filter' => 'adminauth']
);
$routes->get(
    'admin/identites/(:segment)/documents/(:segment)',
    'AdminIdentities::document/$1/$2',
    ['filter' => 'adminauth']
);
$routes->post(
    'admin/identites/(:segment)/statut',
    'AdminIdentities::transition/$1',
    ['filter' => ['adminauth', 'csrf']]
);
$routes->get(
    'admin/identites/(:segment)',
    'AdminIdentities::show/$1',
    ['filter' => 'adminauth']
);

$routes->get('health', 'Health::index');
