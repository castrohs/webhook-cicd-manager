<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->get('admin', 'Admin::index');
$routes->get('admin/stats', 'Admin::stats');
$routes->post('admin/action', 'Admin::runAction');
$routes->match(['get', 'post'], 'webhook', 'Webhook::index');

$routes->get('login', 'Auth::login');
$routes->post('login/process', 'Auth::process');
$routes->get('logout', 'Auth::logout');
