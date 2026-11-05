<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

$prefix         = (string) config('quickbooks.api.routing.prefix', 'quickbooks');
$internalPrefix = (string) config('quickbooks.api.routing.internal_prefix', 'int');

Route::prefix($prefix)->group(function ($router) use ($internalPrefix) {
    $router->prefix($internalPrefix)->group(function ($router) {
        // Intuit posts event notifications here with no Fleetbase session. This file is loaded
        // by loadRoutesFrom, outside the web middleware group, so session CSRF does not apply.
        // Other public API posts, such as ledger webhooks, are left out of that group the same way.
        // The OAuth sign-in returns to the console's QuickBooks page, which posts to oauth/complete.
        $router->post('v1/webhooks', [WebhookController::class, 'handle']);

        $router->group(['prefix' => 'v1', 'middleware' => ['fleetbase.protected']], function ($router) {
            $router->get('connection', [ConnectionController::class, 'show']);
            $router->get('batches', [ConnectionController::class, 'batches']);
            $router->get('summary', [ConnectionController::class, 'summary']);

            // QuickBooks Operator and Administrator grant these. Authorizer also allows installation admins.
            $router->get('settings', [SettingController::class, 'show']);
            $router->post('settings', [SettingController::class, 'save']);
            $router->post('oauth/start', [ConnectionController::class, 'start']);
            $router->post('oauth/complete', [ConnectionController::class, 'complete']);
            $router->post('disconnect', [ConnectionController::class, 'disconnect']);
            $router->post('import', [ConnectionController::class, 'import']);
            $router->post('reconcile', [ConnectionController::class, 'reconcile']);
            $router->post('sync', [ConnectionController::class, 'sync']);

            // view connection is also on the read-only policy. The connection test stays installation-admin only.
            $router->group(['middleware' => [Fleetbase\Http\Middleware\AdminGuard::class]], function ($router) {
                $router->post('connection/test', [ConnectionController::class, 'test']);
            });
        });
    });
});
