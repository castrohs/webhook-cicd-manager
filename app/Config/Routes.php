<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->get('admin', 'Admin::index');
$routes->get('admin/stats', 'Admin::stats');
$routes->get('admin/security-audit', 'Admin::securityAudit');
$routes->post('admin/2fa/prepare', 'Admin::prepare2fa');
$routes->post('admin/2fa/enable', 'Admin::enable2fa');
$routes->post('admin/2fa/disable', 'Admin::disable2fa');
$routes->post('admin/action', 'Admin::runAction');
$routes->match(['get', 'post'], 'webhook', 'Webhook::index');

$routes->get('login', 'Auth::login');
$routes->post('login/process', 'Auth::process');
$routes->get('logout', 'Auth::logout');
