<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param array<string, mixed> $invoice
 *
 * @return array{0: mixed, 1: mixed, 2: SyncLedger}
 */
function currencyRun(array $invoice, bool $multiCurrency, string $home = 'USD'): array
{
    [$engine, $client]                   = qbEngine();
    $client->multiCurrency               = $multiCurrency;
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid'    => 'company-uuid',
        'realm_id'        => 'realm-1',
        'needs_reauth'    => false,
        'home_currency'   => $home,
        'default_item_id' => 'item-1',
    ];
    $ledger->customers['cust-1']         = ['uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'];
    $ledger->links[]                     = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-1',
        'qbo_entity'   => 'Customer', 'qbo_id' => 'qbo-customer', 'sync_token' => '0',
    ];
    $ledger->invoices['inv-1']           = array_merge([
        'uuid'          => 'inv-1',
        'company_uuid'  => 'company-uuid',
        'customer_uuid' => 'cust-1',
        'number'        => 'INV-1',
        'date'          => '2026-09-01',
        'due_date'      => '2026-09-15',
        'currency'      => 'USD',
        'tax'           => 0,
        'total'         => 10000,
        'status'        => 'sent',
        'items'         => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 10000, 'amount' => 10000]],
    ], $invoice);
    $ledger->pending[]                   = ['company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-1', 'status' => 'pending', 'attempts' => 0];

    return [$engine, $client, $ledger];
}

test('an invoice in the home currency is created with its currency and needs no preference lookup', function () {
    [$engine, $client, $ledger] = currencyRun([], false);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($client->invoices['inv-1']['CurrencyRef']['value'])->toBe('USD')
        ->and($client->calls)->not->toContain('multiCurrencyEnabled');
});

test('a foreign currency invoice fails with the currency message when multi-currency is off', function () {
    [$engine, $client, $ledger] = currencyRun(['currency' => 'EUR'], false);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(0)
        ->and($batch['failed'])->toBe(1)
        ->and($ledger->attempts[0]['outcome'])->toBe('failed')
        ->and($ledger->attempts[0]['error'])->toBe('Invoice currency EUR does not match QuickBooks home currency USD')
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($client->invoices)->toBeEmpty();
});

test('a foreign currency invoice keeps its currency reference when multi-currency is on', function () {
    [$engine, $client, $ledger] = currencyRun(['currency' => 'EUR'], true);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($client->invoices['inv-1']['CurrencyRef']['value'])->toBe('EUR');
});

test('a linked foreign currency invoice is not updated when multi-currency is off', function () {
    [$engine, $client, $ledger] = currencyRun(['currency' => 'EUR', 'total' => 12000, 'items' => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 12000, 'amount' => 12000]]], false);
    $client->invoices['qb-1']   = [
        'Id'   => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line' => [['Amount' => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery', 'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00']]],
    ];
    $ledger->links[]            = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity'   => 'Invoice', 'qbo_id' => 'qb-1', 'sync_token' => '0',
    ];

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($batch['updated'])->toBe(0)
        ->and($ledger->attempts[0]['error'])->toBe('Invoice currency EUR does not match QuickBooks home currency USD')
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('voiding a foreign currency invoice does not hit the currency check', function () {
    [$engine, $client, $ledger] = currencyRun(['currency' => 'EUR', 'status' => 'cancelled'], false);
    $client->invoices['qb-1']   = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10];
    $ledger->links[]            = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity'   => 'Invoice', 'qbo_id' => 'qb-1', 'sync_token' => '0',
    ];

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['voided'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($client->calls)->toContain('voidInvoice');
});

test('an inbound only invoice sync does not fail a foreign currency invoice', function () {
    [$engine, $client, $ledger] = currencyRun(['currency' => 'EUR'], false);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_direction' => 'inbound']), time());

    expect($batch['failed'])->toBe(0)
        ->and($client->calls)->not->toContain('createInvoice');
});

test('multi-currency is read from the currency preferences once per company', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::sequence()
            ->push(['Preferences' => ['CurrencyPrefs' => ['MultiCurrencyEnabled' => true, 'HomeCurrency' => ['value' => 'USD']]]], 200)
            ->push(['Preferences' => ['CurrencyPrefs' => ['MultiCurrencyEnabled' => false]]], 200),
    ]);
    $connection = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'access_token' => 'token', 'environment' => 'sandbox'];

    $client = new QuickBooksClient();
    expect($client->multiCurrencyEnabled($connection))->toBeTrue()
        ->and($client->homeCurrency($connection))->toBe('USD')
        ->and((new QuickBooksClient())->multiCurrencyEnabled($connection))->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v3/company/realm-1/preferences'));
});

test('a missing multi-currency preference means it is off', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['Preferences' => ['CurrencyPrefs' => []]], 200),
    ]);
    $connection = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'access_token' => 'token', 'environment' => 'sandbox'];

    expect((new QuickBooksClient())->multiCurrencyEnabled($connection))->toBeFalse();
});
