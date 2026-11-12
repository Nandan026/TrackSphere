<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ---------------------------------------------------------------------
// Public + authentication
// ---------------------------------------------------------------------
$routes->get('/', 'Web\\DashboardController::index', ['filter' => 'auth']);
$routes->get('login', 'Web\\AuthController::login');
$routes->post('login', 'Web\\AuthController::attemptLogin');
$routes->get('logout', 'Web\\AuthController::logout');
$routes->post('logout', 'Web\\AuthController::logout'); // layout logout button submits a CSRF-protected POST
$routes->get('dashboard', 'Web\\DashboardController::index', ['filter' => 'auth']);

// Public tracking (no auth)
$routes->match(['GET', 'POST'], 'track', 'Web\\TrackingController::index');
$routes->get('track/(:segment)', 'Web\\TrackingController::show/$1');

// ---------------------------------------------------------------------
// Authenticated web modules (placeholders - enable as each module is built).
// Example guard: ['filter' => 'auth'] and ['filter' => 'permission:shipments.view']
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// Master data CRUD. Paths match the sidebar in layouts/main.php.
// Every group requires login ('auth') plus the permission shown; the
// permission code(s) are validated by the 'permission' filter
// (pipe = any of, comma = all of).
//   GET  {path}                 list (search ?q=, ?status=)
//   GET  {path}/create          form          POST {path}              store
//   GET  {path}/(id)/edit       form          POST {path}/(id)         update
//   POST {path}/(id)/delete     deactivate    POST {path}/(id)/activate reactivate
// ---------------------------------------------------------------------
$masterData = [
    // path               controller                 permission
    ['customers',        'CustomersController',       'customers.manage'],
    ['pickup-locations', 'PickupLocationsController', 'pickups.manage'],
    ['warehouses',       'WarehousesController',      'pickups.manage'],
    ['couriers',         'CouriersController',        'integrations.manage|billing.manage'],
    ['agents',           'AgentsController',          'delivery.assign'],
    ['packages',         'PackagesController',        'shipments.update'],
    ['rate-cards',       'RateCardsController',       'billing.manage'],
    ['users',            'UsersController',           'users.manage'],
    // Platform-wide catalog (status glossary + dropdown values), admin only.
    ['status-definitions', 'StatusDefinitionsController', 'users.manage'],
    ['lookup-values',      'LookupValuesController',      'users.manage'],
];

foreach ($masterData as [$path, $controller, $permission]) {
    $routes->group($path, ['filter' => ['auth', 'permission:' . $permission]], static function ($routes) use ($controller) {
        $class = 'Web\\' . $controller;

        $routes->get('/', $class . '::index');
        $routes->get('create', $class . '::create');
        $routes->post('/', $class . '::store');
        $routes->get('(:num)/edit', $class . '::edit/$1');
        $routes->post('(:num)', $class . '::update/$1');
        $routes->post('(:num)/delete', $class . '::delete/$1');
        $routes->post('(:num)/activate', $class . '::activate/$1');
    });
}
unset($masterData, $path, $controller, $permission);

// ---------------------------------------------------------------------
// Commercial modules: invoices, COD remittances, reports.
// ---------------------------------------------------------------------
$routes->group('invoices', ['filter' => ['auth', 'permission:billing.manage']], static function ($routes) {
    $routes->get('/', 'Web\\InvoicesController::index');
    $routes->get('create', 'Web\\InvoicesController::create');
    $routes->post('/', 'Web\\InvoicesController::store');
    $routes->get('(:num)', 'Web\\InvoicesController::show/$1');
    $routes->post('(:num)/sent', 'Web\\InvoicesController::markSent/$1');
    $routes->post('(:num)/paid', 'Web\\InvoicesController::markPaid/$1');
});

$routes->group('cod-remittances', ['filter' => ['auth', 'permission:billing.manage']], static function ($routes) {
    $routes->get('/', 'Web\\CodRemittancesController::index');
    $routes->get('create', 'Web\\CodRemittancesController::create');
    $routes->get('suggest', 'Web\\CodRemittancesController::suggest'); // JSON helper for the create form
    $routes->post('/', 'Web\\CodRemittancesController::store');
    $routes->post('(:num)/remit', 'Web\\CodRemittancesController::markRemitted/$1');
});

$routes->group('reports', ['filter' => ['auth', 'permission:reports.view']], static function ($routes) {
    $routes->get('/', 'Web\\ReportsController::index');
    $routes->get('export/shipment-summary', 'Web\\ReportsController::exportShipmentSummary');
});

// ---------------------------------------------------------------------
// Shipment lifecycle + last-mile modules.
// 'auth' is applied to every group; 'permission:...' is merged per route
// (comma = needs ALL codes, pipe = needs ANY). POST routes are CSRF-protected
// by the global filter.
// ---------------------------------------------------------------------
$routes->group('shipments', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Web\\ShipmentsController::index', ['filter' => 'permission:shipments.view']);
    $routes->get('create', 'Web\\ShipmentsController::create', ['filter' => 'permission:shipments.create']);
    $routes->post('/', 'Web\\ShipmentsController::store', ['filter' => 'permission:shipments.create']);
    $routes->get('(:num)', 'Web\\ShipmentsController::show/$1', ['filter' => 'permission:shipments.view']);
    $routes->post('(:num)/ready', 'Web\\ShipmentsController::markReady/$1', ['filter' => 'permission:shipments.update']);
    $routes->post('(:num)/pickup', 'Web\\ShipmentsController::bookPickup/$1', ['filter' => 'permission:shipments.update']);
    $routes->post('(:num)/courier', 'Web\\ShipmentsController::assignCourier/$1', ['filter' => 'permission:shipments.update']);
    $routes->post('(:num)/advance', 'Web\\ShipmentsController::advanceStatus/$1', ['filter' => 'permission:shipments.update']);
    $routes->post('(:num)/cancel', 'Web\\ShipmentsController::cancel/$1', ['filter' => 'permission:shipments.update']);
});

$routes->group('pickups', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Web\\PickupsController::index', ['filter' => 'permission:pickups.manage']);
    $routes->post('(:num)/cancel', 'Web\\PickupsController::cancel/$1', ['filter' => 'permission:pickups.manage']);
    $routes->post('(:num)/complete', 'Web\\PickupsController::complete/$1', ['filter' => 'permission:pickups.manage']);
});

$routes->group('delivery', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Web\\DeliveryController::index', ['filter' => 'permission:delivery.assign|delivery.update_status']);
    $routes->get('tasks', 'Web\\DeliveryController::tasks', ['filter' => 'permission:delivery.assign|delivery.update_status']);
    $routes->post('assign', 'Web\\DeliveryController::assign', ['filter' => 'permission:delivery.assign']);
    $routes->post('(:num)/out-for-delivery', 'Web\\DeliveryController::markOutForDelivery/$1', ['filter' => 'permission:delivery.assign|delivery.update_status']);
    $routes->post('(:num)/attempt', 'Web\\DeliveryController::recordAttempt/$1', ['filter' => 'permission:delivery.update_status']);
    $routes->get('(:num)/pod', 'Web\\DeliveryController::pod/$1', ['filter' => 'permission:delivery.update_status']);
    $routes->post('(:num)/pod', 'Web\\DeliveryController::submitPod/$1', ['filter' => 'permission:delivery.update_status']);
});

$routes->group('ndr', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Web\\NdrController::index', ['filter' => 'permission:ndr.manage']);
    $routes->post('(:num)/resolve', 'Web\\NdrController::resolve/$1', ['filter' => 'permission:ndr.manage']);
});

$routes->group('returns', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Web\\ReturnsController::index', ['filter' => 'permission:returns.manage']);
    $routes->get('create', 'Web\\ReturnsController::create', ['filter' => 'permission:returns.manage']);
    $routes->post('/', 'Web\\ReturnsController::store', ['filter' => 'permission:returns.manage']);
    // Ops decisions: returns.manage AND shipments.update (merchants can request but not decide).
    $routes->post('(:num)/approve', 'Web\\ReturnsController::approve/$1', ['filter' => 'permission:returns.manage,shipments.update']);
    $routes->post('(:num)/reject', 'Web\\ReturnsController::reject/$1', ['filter' => 'permission:returns.manage,shipments.update']);
    $routes->post('(:num)/received', 'Web\\ReturnsController::received/$1', ['filter' => 'permission:returns.manage,shipments.update']);
});

// ---------------------------------------------------------------------
// REST API v1 (JSON only). Token auth via the 'apiAuth' filter; CSRF is
// already excluded for api/* in Config\Filters. Permission checks are done
// inside the controllers (BaseApiController::deny) so they never depend on
// filter ordering.
// ---------------------------------------------------------------------
$routes->group('api/v1', static function ($routes) {
    // Public
    $routes->post('auth/login', 'Api\V1\AuthController::login');
    $routes->get('tracking/(:segment)', 'Api\V1\TrackingController::show/$1');

    // Bearer token required
    $routes->group('', ['filter' => 'apiAuth'], static function ($routes) {
        $routes->post('auth/logout', 'Api\V1\AuthController::logout');
        $routes->get('auth/me', 'Api\V1\AuthController::me');

        $routes->get('shipments', 'Api\V1\ShipmentsController::index');
        $routes->post('shipments', 'Api\V1\ShipmentsController::create');
        $routes->get('shipments/(:num)', 'Api\V1\ShipmentsController::show/$1');
        $routes->post('shipments/(:num)/book-pickup', 'Api\V1\ShipmentsController::bookPickup/$1');
        $routes->post('shipments/(:num)/assign-courier', 'Api\V1\ShipmentsController::assignCourier/$1');
        $routes->post('shipments/(:num)/delivery-attempts', 'Api\V1\ShipmentsController::deliveryAttempts/$1');
        $routes->post('shipments/(:num)/proof-of-delivery', 'Api\V1\ShipmentsController::proofOfDelivery/$1');
        $routes->post('shipments/(:num)/return-requests', 'Api\V1\ShipmentsController::returnRequests/$1');

        $routes->get('reports/shipment-summary', 'Api\V1\ReportsController::shipmentSummary');
    });
});
