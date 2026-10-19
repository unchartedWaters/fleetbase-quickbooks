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
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Invoice currency EUR does not match the QuickBooks home '
            . 'currency '
            . 'USD.')
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
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Invoice currency EUR does not match the QuickBooks home '
            . 'currency '
            . 'USD.')
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

test('a yen invoice is sent in whole yen', function () {
    [$engine, $client, $ledger] = currencyRun([
        'currency' => 'JPY',
        'total'    => 10000,
        'items'    => [
            ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
            ['description' => 'Pallets', 'quantity' => 2, 'unit_price' => 2500, 'amount' => 5000],
        ],
    ], false, 'JPY');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $lines = $client->invoices['inv-1']['Line'];

    expect($batch['created'])->toBe(1)
        ->and($lines[0]['Amount'])->toBe('5000.00')
        ->and($lines[0]['SalesItemLineDetail']['UnitPrice'])->toBe('5000.00')
        ->and($lines[1]['Amount'])->toBe('5000.00')
        ->and($lines[1]['SalesItemLineDetail']['UnitPrice'])->toBe('2500.00')
        ->and($client->invoices['inv-1']['TotalAmt'])->toBe('10000.00');
});

test('a quickbooks yen invoice is copied back in whole yen', function () {
    [$engine, $client, $ledger] = currencyRun([
        'currency' => 'JPY',
        'total'    => 4000,
        'items'    => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 4000, 'amount' => 4000]],
    ], false, 'JPY');
    $client->invoices['qb-1'] = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 5000, 'Balance' => 5000,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'CurrencyRef' => ['value' => 'JPY'],
        'Line'    => [[
            'Id'                  => '1',
            'Amount'              => 5000,
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 2, 'UnitPrice' => 2500],
        ]],
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity'   => 'Invoice', 'qbo_id' => 'qb-1', 'sync_token' => '1',
    ];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'invoice_conflict' => 'quickbooks',
    ]), time());

    expect($ledger->invoices['inv-1']['total'])->toBe(5000)
        ->and($ledger->invoices['inv-1']['items'][0]['amount'])->toBe(5000)
        ->and($ledger->invoices['inv-1']['items'][0]['unit_price'])->toBe(2500)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a yen invoice that matches quickbooks is aligned and not sent again', function () {
    [$engine, $client, $ledger] = currencyRun([
        'currency' => 'JPY',
        'total'    => 5000,
        'items'    => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000]],
    ], false, 'JPY');
    $client->invoices['qb-1'] = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 5000, 'Balance' => 5000,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'CurrencyRef' => ['value' => 'JPY'],
        'Line'    => [[
            'Id'                  => '1',
            'Amount'              => 5000,
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 5000],
        ]],
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity'   => 'Invoice', 'qbo_id' => 'qb-1', 'sync_token' => '1',
    ];

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($batch['updated'])->toBe(0)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a yen payment is sent in whole yen and matches on the next sync', function () {
    [$engine, $client, $ledger] = currencyRun([
        'currency'    => 'JPY',
        'total'       => 5000,
        'status'      => 'partial',
        'amount_paid' => 3000,
        'items'       => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000]],
    ], false, 'JPY');
    $client->invoices['qb-1'] = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 5000, 'Balance' => 5000,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'CurrencyRef' => ['value' => 'JPY'],
        'Line'    => [[
            'Id'                  => '1',
            'Amount'              => 5000,
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 5000],
        ]],
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity'   => 'Invoice', 'qbo_id' => 'qb-1', 'sync_token' => '1',
    ];
    $settings = qbSettings(['interval_minutes' => 1]);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($client->paymentPayloads[0]['TotalAmt'])->toBe('3000.00')
        ->and($client->paymentPayloads[0]['Line'][0]['Amount'])->toBe('3000.00');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';

    $second = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a quickbooks yen payment and balance are copied in whole yen', function () {
    [$engine, $client, $ledger] = currencyRun([
        'currency' => 'JPY',
        'total'    => 5000,
        'items'    => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000]],
    ], false, 'JPY');
    $ledger->invoices['inv-2'] = array_merge($ledger->invoices['inv-1'], [
        'uuid' => 'inv-2', 'number' => 'INV-2', 'amount_paid' => 1000,
    ]);
    $ledger->pending[] = [
        'company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-2',
        'status'       => 'pending', 'attempts' => 0,
    ];
    $line = [
        'Id'                  => '1',
        'Amount'              => 5000,
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => 'Delivery',
        'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 5000],
    ];
    $client->invoices['qb-1'] = [
        'Id'        => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 5000, 'Balance' => 2000,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15', 'CurrencyRef' => ['value' => 'JPY'],
        'Line'      => [$line],
        'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
    ];
    $client->invoices['qb-2'] = array_merge($client->invoices['qb-1'], [
        'Id' => 'qb-2', 'DocNumber' => 'INV-2', 'Balance' => 1500, 'LinkedTxn' => [],
    ]);
    $client->payments['pay-1'] = [
        'Id'   => 'pay-1', 'SyncToken' => '1', 'TotalAmt' => 3000, 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => 3000, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    foreach (['inv-1' => 'qb-1', 'inv-2' => 'qb-2'] as $local => $remote) {
        $ledger->links[] = [
            'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice',
            'local_uuid'   => $local, 'qbo_entity' => 'Invoice', 'qbo_id' => $remote, 'sync_token' => '1',
        ];
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(3000)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($ledger->invoices['inv-2']['amount_paid'])->toBe(3500)
        ->and($client->calls)->not->toContain('updateInvoice');
});
