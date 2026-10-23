<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
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

test('the fake quickbooks keeps the customer fields a sparse update leaves out, as quickbooks does', function () {
    $client                   = new FakeQuickBooks();
    $client->customers['c-1'] = [
        'Id'           => 'c-1',
        'SyncToken'    => '2',
        'DisplayName'  => 'Ada',
        'PrimaryPhone' => ['FreeFormNumber' => '555-0000'],
        'Notes'        => 'Gate code 1234',
    ];

    $updated = $client->updateCustomer([], 'c-1', '2', ['DisplayName' => 'Ada Lovelace', 'Notes' => '']);

    expect($updated['DisplayName'])->toBe('Ada Lovelace')
        ->and($updated['PrimaryPhone']['FreeFormNumber'] ?? null)->toBe('555-0000')
        ->and($updated['Notes'])->toBe('')
        ->and($updated['SyncToken'])->toBe('3')
        ->and($client->customers['c-1'])->toBe($updated);
});
