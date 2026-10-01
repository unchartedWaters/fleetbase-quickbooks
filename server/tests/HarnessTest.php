<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Illuminate\Support\Facades\Http;

test('the harness can fake an http client', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['Customer' => ['Id' => '1', 'SyncToken' => '0']], 200),
    ]);

    $client   = new QuickBooksClient();
    $customer = $client->createCustomer([
        'realm_id'     => '123',
        'access_token' => 'token',
        'environment'  => 'sandbox',
    ], ['DisplayName' => 'Ada']);

    expect($customer['Id'])->toBe('1');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/customer'));
});
