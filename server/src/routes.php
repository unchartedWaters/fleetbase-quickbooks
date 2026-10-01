<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

$prefix         = (string) config('quickbooks.api.routing.prefix', 'quickbooks');
$internalPrefix = (string) config('quickbooks.api.routing.internal_prefix', 'int');

Route::prefix($prefix)->group(function ($router) use ($internalPrefix) {
    $router->prefix($internalPrefix)->group(function ($router) {
        // Intuit redirects the user's browser here without a Fleetbase session. It only keeps
        // the code; the signed-in console finishes the connection through oauth/complete.
        $router->get('v1/oauth/callback', [ConnectionController::class, 'callback']);

        // Intuit posts event notifications here with no Fleetbase session. This file is loaded
        // by loadRoutesFrom, outside the web middleware group, so session CSRF does not apply.
        // Other public API posts, such as ledger webhooks, are left out of that group the same way.
        $router->post('v1/webhooks', [WebhookController::class, 'handle']);

        $router->group(['prefix' => 'v1', 'middleware' => ['fleetbase.protected']], function ($router) {
            $router->get('settings', [SettingController::class, 'show']);
            $router->post('settings', [SettingController::class, 'save']);
            $router->get('connection', [ConnectionController::class, 'show']);
            $router->get('batches', [ConnectionController::class, 'batches']);
            $router->post('oauth/start', [ConnectionController::class, 'start']);
            $router->post('oauth/complete', [ConnectionController::class, 'complete']);
            $router->post('disconnect', [ConnectionController::class, 'disconnect']);
            $router->post('import', [ConnectionController::class, 'import']);
            $router->post('reconcile', [ConnectionController::class, 'reconcile']);
            $router->post('sync', [ConnectionController::class, 'sync']);
            $router->post('connection/test', [ConnectionController::class, 'test']);
            $router->get('summary', [ConnectionController::class, 'summary']);
        });
    });
});
