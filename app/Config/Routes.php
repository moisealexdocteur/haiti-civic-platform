<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'CitizenPortal::home');
$routes->get('structures-politiques', 'PoliticalStructures::index');
$routes->get('inscription', 'CitizenPortal::locate');
$routes->get(
    'inscription/(:segment)/konfimasyon',
    'CitizenPortal::confirmation/$1'
);
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
    'inscription/(:segment)/otp/continuer-sans-code',
    'CitizenPortal::continueWithoutOtp/$1',
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
$routes->get('admin/password/forgot', 'AdminAuth::forgot');
$routes->post(
    'admin/password/forgot',
    'AdminAuth::requestReset',
    ['filter' => 'csrf']
);
$routes->get('admin/password/reset', 'AdminAuth::reset');
$routes->post(
    'admin/password/reset',
    'AdminAuth::completeReset',
    ['filter' => 'csrf']
);
$routes->post(
    'admin/logout',
    'AdminAuth::logout',
    ['filter' => ['adminauth', 'csrf']]
);

$routes->get('admin', 'AdminDashboard::index', ['filter' => 'adminauth']);
$routes->get(
    'admin/communications',
    'AdminSettings::communications',
    ['filter' => ['adminauth', 'adminperm:settings.view']]
);
$routes->post(
    'admin/communications',
    'AdminSettings::saveCommunications',
    ['filter' => ['adminauth', 'adminperm:settings.manage', 'csrf']]
);
$routes->get(
    'admin/utilisateurs',
    'AdminUsers::index',
    ['filter' => ['adminauth', 'adminperm:users.view']]
);
$routes->post(
    'admin/utilisateurs',
    'AdminUsers::create',
    ['filter' => ['adminauth', 'adminperm:users.manage,roles.manage', 'csrf']]
);
$routes->post(
    'admin/utilisateurs/(:segment)/statut',
    'AdminUsers::status/$1',
    ['filter' => ['adminauth', 'adminperm:users.manage', 'csrf']]
);
$routes->get(
    'admin/audit',
    'AdminAudit::index',
    ['filter' => ['adminauth', 'adminperm:audit.view']]
);
$routes->get(
    'admin/roles',
    'AdminRoles::index',
    ['filter' => ['adminauth', 'adminperm:roles.view']]
);
$routes->post(
    'admin/roles',
    'AdminRoles::create',
    ['filter' => ['adminauth', 'adminperm:roles.manage', 'csrf']]
);
$routes->post(
    'admin/roles/(:segment)',
    'AdminRoles::update/$1',
    ['filter' => ['adminauth', 'adminperm:roles.manage', 'csrf']]
);
$routes->get('admin/securite', 'AdminSecurity::index', ['filter' => 'adminauth']);
$routes->post(
    'admin/securite/mot-de-passe',
    'AdminSecurity::changePassword',
    ['filter' => ['adminauth', 'csrf']]
);

$routes->get(
    'admin/identites',
    'AdminIdentities::index',
    ['filter' => ['adminauth', 'adminperm:identity.view']]
);
$routes->get(
    'admin/carte',
    'AdminMap::index',
    ['filter' => ['adminauth', 'adminperm:identity.view']]
);
$routes->get(
    'admin/identites/(:segment)/documents/(:segment)',
    'AdminIdentities::document/$1/$2',
    ['filter' => ['adminauth', 'adminperm:identity.view']]
);
$routes->post(
    'admin/identites/(:segment)/statut',
    'AdminIdentities::transition/$1',
    ['filter' => ['adminauth', 'adminperm:identity.manage', 'csrf']]
);
$routes->get(
    'admin/identites/(:segment)',
    'AdminIdentities::show/$1',
    ['filter' => ['adminauth', 'adminperm:identity.view']]
);

$routes->get('health', 'Health::index');
