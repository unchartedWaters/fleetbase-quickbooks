<?php

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;
use Fleetbase\Quickbooks\Tests\Support\LostCreateQuickBooks;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

test('a stored report customer conflict does not write the fleetbase customer', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Other Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->pending[]                 = enginePending('customer', 'cust-1');
    $settings                          = (new SyncSettingsResolver())->resolve(
        [],
        qbSettings(['override' => true, 'interval_minutes' => 1, 'customer_conflict' => 'report']),
        qbSettings()
    );

    expect($settings['customer_conflict'])->toBe('report')
        ->and($settings['customer_direction'])->toBe('off')
        ->and($settings['customer_enabled'])->toBeTrue();

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(0)
        ->and($batch['skipped'])->toBe(1)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($ledger->customers['cust-1']['name'])->toBe('Ada')
        ->and($client->customers['qbo-customer']['DisplayName'])->toBe('Other Ada')
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('a stored report invoice conflict does not write the fleetbase invoice', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $client->invoices['qb-1']    = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 25, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');
    $settings                    = (new SyncSettingsResolver())->resolve(
        [],
        qbSettings(['override' => true, 'interval_minutes' => 1, 'invoice_conflict' => 'report']),
        qbSettings()
    );

    expect($settings['invoice_conflict'])->toBe('report')
        ->and($settings['invoice_direction'])->toBe('off')
        ->and($settings['invoice_enabled'])->toBeTrue();

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(0)
        ->and($batch['skipped'])->toBe(1)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($ledger->invoices['inv-1']['total'])->toBe(1000)
        ->and($client->invoices['qb-1']['TotalAmt'])->toBe(25)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a stored report wallet conflict does not write the fleetbase wallet', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = engineLedger();
    $ledger->wallets['wal-1']   = engineWallet('wal-1');
    $client->accounts['acct-1'] = ['Id' => 'acct-1', 'SyncToken' => '0', 'Name' => 'QB Operating', 'Description' => 'Float', 'Active' => true, 'CurrencyRef' => ['value' => 'USD']];
    $ledger->links[]            = engineLink('wallet', 'wal-1', 'acct-1');
    $ledger->pending[]          = enginePending('wallet', 'wal-1');
    $settings                   = (new SyncSettingsResolver())->resolve(
        [],
        qbSettings(['override' => true, 'interval_minutes' => 1, 'wallet_conflict' => 'report']),
        qbSettings()
    );

    expect($settings['wallet_conflict'])->toBe('report')
        ->and($settings['wallet_direction'])->toBe('off')
        ->and($settings['wallet_enabled'])->toBeTrue();

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(0)
        ->and($batch['skipped'])->toBe(1)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($ledger->wallets['wal-1']['name'])->toBe('Operating')
        ->and($client->accounts['acct-1']['Name'])->toBe('QB Operating')
        ->and($client->calls)->not->toContain('updateAccount');
});

test('an unchanged invoice with the quickbooks primary ends aligned and is not retried', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $client->invoices['qb-1']    = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');
    $settings                    = qbSettings(['interval_minutes' => 1, 'invoice_conflict' => 'quickbooks']);
    $before                      = $ledger->invoices['inv-1'];

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['aligned'])->toBe(1)
        ->and($batch['skipped'])->toBe(0)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($ledger->pending[0]['status'])->toBe('done')
        ->and($ledger->invoices['inv-1'])->toBe($before)
        ->and($client->calls)->not->toContain('updateInvoice');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $calls                                                = count($client->calls);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect(count($client->calls))->toBe($calls);
});

test('a fleetbase invoice number change is sent when the other invoice fields match', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['number' => 'INV-2']);
    $client->invoices['qb-1']    = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['aligned'])->toBe(0)
        ->and($client->calls)->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['DocNumber'])->toBe('INV-2')
        ->and($ledger->invoices['inv-1']['number'])->toBe('INV-2');
});

test('a fleetbase invoice number is sent when quickbooks wins other invoice conflicts', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['number' => 'INV-2']);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line'    => [[
            'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'invoice_conflict' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($client->calls)->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['DocNumber'])->toBe('INV-2')
        ->and($ledger->invoices['inv-1']['number'])->toBe('INV-2');
});

test('a line edit that keeps the invoice total is sent once', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [['description' => 'Evening delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ], [
        'Line' => [[
            'Amount'              => '10.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($first['aligned'])->toBe(0)
        ->and($client->invoices['qb-1']['Line'][0]['Description'])->toBe('Evening delivery')
        ->and($client->invoices['qb-1']['TotalAmt'])->toBe('10.00');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a new invoice note is sent once', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['notes' => 'Leave at dock']);
    $settings                   = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['PrivateNote'])->toBe('Leave at dock')
        ->and($ledger->invoices['inv-1']['notes'])->toBe('Leave at dock');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a cleared invoice note is pushed once and is not restored', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['notes' => ''], ['PrivateNote' => 'Dock 4']);
    $settings                   = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['notes'])->toBe('')
        ->and($client->invoices['qb-1']['PrivateNote'])->toBe('');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($ledger->invoices['inv-1']['notes'])->toBe('')
        ->and($client->invoices['qb-1']['PrivateNote'])->toBe('')
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a cleared invoice due date is pushed once and then stays aligned', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['due_date' => '']);
    $settings                   = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('')
        ->and($client->invoices['qb-1']['DueDate'])->toBe('');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('')
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('an empty quickbooks note or due date does not wipe the fleetbase invoice', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(
        ['notes'       => 'Keep', 'due_date' => '2026-10-01'],
        ['PrivateNote' => '', 'DueDate' => '']
    );

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'invoice_direction'  => 'inbound',
        'invoice_conflict'   => 'quickbooks',
    ]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($batch['updated'])->toBe(0)
        ->and($ledger->invoices['inv-1']['notes'])->toBe('Keep')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-10-01')
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('quickbooks primary copies a note the fleetbase invoice does not have', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['notes' => ''], ['PrivateNote' => 'Dock 4']);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'invoice_conflict' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['notes'])->toBe('Dock 4')
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('changing the invoice customer updates the quickbooks customer once', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['customer_uuid' => 'cust-2'], [
        'CustomerRef' => ['value' => 'qbo-customer'],
    ]);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->links[]             = engineLink('customer', 'cust-2', 'qbo-bea');
    $settings                    = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-bea')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a different quickbooks currency is replaced with the fleetbase currency once', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['currency' => 'USD'], [
        'CurrencyRef' => ['value' => 'EUR'],
        'CustomerRef' => ['value' => 'qbo-customer'],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($first['failed'])->toBe(0)
        ->and($client->invoices['qb-1']['CurrencyRef']['value'])->toBe('USD');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a quickbooks balance of zero with no payment does not set amount paid', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'sent']);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line'    => [[
            'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($batch['aligned'])->toBe(1)
        ->and(array_key_exists('amount_paid', $ledger->invoices['inv-1']))->toBeFalse()
        ->and($ledger->invoices['inv-1']['status'])->toBe('sent')
        ->and($client->calls)->not->toContain('createPayment');
});

test('a partial fleetbase invoice stays partial when quickbooks balance is zero and no payment exists', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'partial', 'amount_paid' => 400]);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line'    => [[
            'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(400)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($client->calls)->not->toContain('createPayment');
});

test('a partial fleetbase payment is sent to quickbooks', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'partial', 'amount_paid' => 400]);
    $client->invoices['qb-1']    = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('createPayment')
        ->and($client->paymentPayloads[0]['TotalAmt'])->toBe('4.00');
});

test('a larger quickbooks total changes a paid invoice that no longer covers it', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 15, 'Balance' => 15,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'invoice_conflict' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000);
});

$paymentRefCases = [
    'a check'          => [['payment_reference' => 'CHK-1001', 'payment_method' => 'check'], 'CHK-1001', 'check'],
    'a long reference' => [
        ['payment_reference' => str_repeat('R', 30), 'payment_method' => 'manual'],
        str_repeat('R', 21),
        null,
    ],
    'no reference'     => [['payment_reference' => '  ', 'payment_method' => ''], null, null],
];
foreach ($paymentRefCases as $paymentRefLabel => [$paymentRefFields, $paymentRefNum, $paymentRefMethod]) {
    test('a new quickbooks payment carries the ledger payment reference and method: '
    . $paymentRefLabel, function () use (
        $paymentRefFields,
        $paymentRefNum,
        $paymentRefMethod
    ) {
        [$engine, $client]           = qbEngine();
        $ledger                      = engineLedger();
        $ledger->customers['cust-1'] = engineCustomer('cust-1');
        $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
        $paidInvoice                 = array_merge(['status' => 'paid', 'amount_paid' => 1000], $paymentRefFields);
        $ledger->invoices['inv-1']   = engineInvoice('inv-1', $paidInvoice);
        $client->invoices['qb-1']    = [
            'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 10,
            'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        ];
        $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
        $ledger->pending[] = enginePending('invoice', 'inv-1');

        $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
        $payload = $client->paymentPayloads[0] ?? [];

        expect($payload['PaymentRefNum'] ?? null)->toBe($paymentRefNum);
        if ($paymentRefMethod === null) {
            expect($payload)->not->toHaveKey('PrivateNote');
        } else {
            expect($payload['PrivateNote'])->toBe('Fleetbase payment method: ' . $paymentRefMethod);
        }
    });
}

test('a partial payment sends the amount paid and matches on the next sync', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 600]);
    $client->invoices['qb-1']    = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');
    $settings                    = qbSettings(['interval_minutes' => 1]);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($client->paymentPayloads[0]['TotalAmt'])->toBe('6.00')
        ->and($client->paymentPayloads[0]['Line'][0]['Amount'])->toBe('6.00');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updatePayment');
});

test('a paid invoice deleted in quickbooks is not recreated and its payment is not replaced', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000]);
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'deleted-in-quickbooks');
    $ledger->links[]             = engineLink('payment', 'inv-1', 'deleted-payment');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(0)
        ->and($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->not->toContain('createPayment')
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['qbo_id'])->toBe('deleted-in-quickbooks')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-1')['qbo_id'])->toBe('deleted-payment');
});

test('a customer billing address and notes are sent to quickbooks', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', [
        'notes'   => 'Dock 4',
        'address' => [
            'line1'       => '1 Analytical Engine',
            'line2'       => 'Suite 2',
            'city'        => 'London',
            'state'       => 'LN',
            'postal_code' => 'SW1',
            'country'     => 'UK',
        ],
    ]);
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $created = array_values($client->customers)[0];

    expect($created['Notes'])->toBe('Dock 4')
        ->and($created['BillAddr']['Line1'])->toBe('1 Analytical Engine')
        ->and($created['BillAddr']['Line2'])->toBe('Suite 2')
        ->and($created['BillAddr']['City'])->toBe('London')
        ->and($created['BillAddr']['CountrySubDivisionCode'])->toBe('LN')
        ->and($created['BillAddr']['PostalCode'])->toBe('SW1')
        ->and($created['BillAddr']['Country'])->toBe('UK');
});

test('inbound sync copies notes and billing address when the name is unchanged', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '1',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'Notes'            => 'Dock 4',
        'BillAddr'         => [
            'Line1'                  => '1 Analytical Engine',
            'Line2'                  => 'Suite 2',
            'City'                   => 'London',
            'CountrySubDivisionCode' => 'LN',
            'PostalCode'             => 'SW1',
            'Country'                => 'UK',
        ],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');
    $settings          = qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'inbound',
        'customer_reference' => 'quickbooks',
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['aligned'])->toBe(0)
        ->and($ledger->customers['cust-1']['name'])->toBe('Ada')
        ->and($ledger->customers['cust-1']['notes'])->toBe('Dock 4')
        ->and($ledger->customers['cust-1']['address'])->toBe([
            'line1'       => '1 Analytical Engine',
            'line2'       => 'Suite 2',
            'city'        => 'London',
            'state'       => 'LN',
            'postal_code' => 'SW1',
            'country'     => 'UK',
        ])
        ->and($client->calls)->not->toContain('updateCustomer');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $again                                                = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($again['aligned'])->toBe(1)
        ->and($again['updated'])->toBe(0)
        ->and($ledger->customers['cust-1']['notes'])->toBe('Dock 4');
});

test('a fleetbase primary clear is pushed and not restored from quickbooks', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['notes' => '']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '1',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'Notes'            => 'Dock 4',
        'BillAddr'         => [
            'Line1'                  => '1 Analytical Engine',
            'Line2'                  => 'Suite 2',
            'City'                   => 'London',
            'CountrySubDivisionCode' => 'LN',
            'PostalCode'             => 'SW1',
            'Country'                => 'UK',
        ],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $both = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($both['updated'])->toBe(1)
        ->and($ledger->customers['cust-1']['notes'])->toBe('')
        ->and($ledger->customers['cust-1'])->not->toHaveKey('address')
        ->and($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-1']['Notes'])->toBe('')
        ->and($client->customers['qbo-1']['BillAddr']['Line1'])->toBe('')
        ->and($client->customers['qbo-1']['BillAddr']['Line2'])->toBe('')
        ->and($client->customers['qbo-1']['BillAddr']['City'])->toBe('');

    $outboundLedger                      = engineLedger();
    $outboundLedger->customers['cust-1'] = engineCustomer('cust-1', ['notes' => '']);
    $outboundLedger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']          = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '2',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'Notes'            => 'Dock 4',
        'BillAddr'         => [
            'Line1' => '1 Analytical Engine',
            'City'  => 'London',
        ],
    ];
    $outboundLedger->pending[] = enginePending('customer', 'cust-1');
    $client->calls             = [];

    $outbound = $engine->runScheduled($outboundLedger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'outbound',
    ]), time());

    expect($outbound['updated'])->toBe(1)
        ->and($outbound['aligned'])->toBe(0)
        ->and($outboundLedger->customers['cust-1']['notes'])->toBe('')
        ->and($outboundLedger->customers['cust-1'])->not->toHaveKey('address')
        ->and($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-1']['Notes'])->toBe('');
});

test('quickbooks primary still copies a note and address the fleetbase customer does not have', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['notes' => '']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '1',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'Notes'            => 'Dock 4',
        'BillAddr'         => [
            'Line1'                  => '1 Analytical Engine',
            'Line2'                  => 'Suite 2',
            'City'                   => 'London',
            'CountrySubDivisionCode' => 'LN',
            'PostalCode'             => 'SW1',
            'Country'                => 'UK',
        ],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'customer_conflict' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->customers['cust-1']['notes'])->toBe('Dock 4')
        ->and($ledger->customers['cust-1']['address']['line1'])->toBe('1 Analytical Engine')
        ->and($ledger->customers['cust-1']['address']['line2'])->toBe('Suite 2')
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('an empty quickbooks note or address leaves the fleetbase value in place', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', [
        'notes'   => 'Keep',
        'address' => [
            'line1'       => '1 Analytical Engine',
            'line2'       => 'Suite 2',
            'city'        => 'London',
            'state'       => 'LN',
            'postal_code' => 'SW1',
            'country'     => 'UK',
        ],
    ]);
    $ledger->links[]            = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1'] = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '1',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'Notes'            => '',
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'inbound',
        'customer_reference' => 'quickbooks',
    ]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($batch['updated'])->toBe(0)
        ->and($ledger->customers['cust-1']['notes'])->toBe('Keep')
        ->and($ledger->customers['cust-1']['address']['line1'])->toBe('1 Analytical Engine')
        ->and($ledger->customers['cust-1']['address']['line2'])->toBe('Suite 2')
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('quickbooks primary counts an email change as updated when the name stays the same', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '0',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'new@example.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'inbound',
        'customer_reference' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['aligned'])->toBe(0)
        ->and($ledger->customers['cust-1']['name'])->toBe('Ada')
        ->and($ledger->customers['cust-1']['email'])->toBe('new@example.test')
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('a customer whose name matches links even when the emails differ', function (string $variant) {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $client->customers['qbo-5']  = ['Id' => 'qbo-5', 'SyncToken' => '2', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'old@example.test']];
    $ledger->pending[]           = enginePending('customer', 'cust-1');
    if ($variant === 'block') {
        $ledger->customers['cust-9'] = engineCustomer('cust-9', ['name' => 'Grace', 'email' => 'grace@example.test']);
        $ledger->pending[]           = enginePending('customer', 'cust-9');
    }

    $settings = qbSettings(['interval_minutes' => 1, 'customer_reference' => 'fleetbase']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-5')
        ->and(array_count_values($client->calls)['createCustomer'] ?? 0)->toBe($variant === 'block' ? 1 : 0);
})->with(['one row' => ['single'], 'block' => ['block']]);

test('two fleetbase customers with the same name do not both link to one quickbooks customer', function (
    string $variant,
) {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Acme', 'email' => 'east@acme.test']);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Acme', 'email' => 'west@acme.test']);
    $client->customers['58']     = [
        'Id'               => '58',
        'SyncToken'        => '0',
        'DisplayName'      => 'Acme',
        'PrimaryEmailAddr' => ['Address' => 'ap@acme.test'],
    ];
    if ($variant === 'linked') {
        $ledger->links[] = engineLink('customer', 'cust-1', '58');
    } else {
        $ledger->pending[] = enginePending('customer', 'cust-1');
    }
    $ledger->pending[] = enginePending('customer', 'cust-2');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_reference' => 'quickbooks',
        'customer_conflict'  => 'quickbooks',
    ]), time());

    $linked = array_filter(
        $ledger->links,
        static fn (array $link): bool => $link['local_type'] === 'customer' && $link['qbo_id'] === '58'
    );
    expect(array_column($linked, 'local_uuid'))->toBe(['cust-1'])
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->not->toBe('58')
        ->and($client->calls)->toContain('createCustomer')
        ->and($ledger->customers['cust-2']['email'])->toBe('west@acme.test');
})->with(['one already linked' => ['linked'], 'both in one block' => ['block']]);

test('a customer is not linked to a quickbooks customer another fleetbase customer already uses', function (
    string $variant,
) {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Acme West', 'email' => 'ap@acme.test']);
    $client->customers['58']     = [
        'Id'               => '58',
        'SyncToken'        => '0',
        'DisplayName'      => 'Acme East',
        'PrimaryEmailAddr' => ['Address' => 'ap@acme.test'],
    ];
    if ($variant === 'another organization') {
        $ledger->remoteLinkReader = static function (string $realm, string $entity, array $ids): array {
            return $entity === 'Customer' ? array_values(array_intersect($ids, ['58'])) : [];
        };
    } else {
        $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Acme East', 'email' => 'ap@acme.test']);
        $ledger->links[]             = engineLink('customer', 'cust-1', '58');
    }
    $ledger->pending[] = enginePending('customer', 'cust-2');
    if ($variant === 'block') {
        $ledger->pending[] = enginePending('customer', 'cust-1');
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_reference' => 'quickbooks',
        'customer_conflict'  => 'quickbooks',
    ]), time());

    expect($ledger->customers['cust-2']['name'])->toBe('Acme West')
        ->and($client->calls)->toContain('createCustomer')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->not->toBe('58')
        ->and($client->customers['58']['DisplayName'])->toBe('Acme East');
})->with(['one row' => ['single'], 'block' => ['block'], 'another organization' => ['another organization']]);

test('a customer whose email match is taken links to the free quickbooks customer with its name', function (
    string $variant,
) {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Acme East', 'email' => 'ap@acme.test']);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Acme West', 'email' => 'ap@acme.test']);
    $ledger->links[]             = engineLink('customer', 'cust-1', '58');
    $client->customers['58']     = [
        'Id'               => '58',
        'SyncToken'        => '0',
        'DisplayName'      => 'Acme East',
        'PrimaryEmailAddr' => ['Address' => 'ap@acme.test'],
    ];
    $client->customers['77'] = [
        'Id'               => '77',
        'SyncToken'        => '0',
        'DisplayName'      => 'Acme West',
        'PrimaryEmailAddr' => ['Address' => 'west@acme.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-2');
    if ($variant === 'block') {
        $ledger->pending[] = enginePending('customer', 'cust-1');
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_reference' => 'quickbooks',
    ]), time());

    expect($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'] ?? null)->toBe('77')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('58')
        ->and($client->calls)->not->toContain('createCustomer');
})->with(['one row' => ['single'], 'block' => ['block']]);

test('a customer whose email and name matches are both taken gets its own customer', function (string $variant) {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Acme East', 'email' => 'ap@acme.test']);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Acme West', 'email' => 'ap@acme.test']);
    $ledger->customers['cust-3'] = engineCustomer('cust-3', ['name' => 'Acme West', 'email' => 'w@acme.test']);
    $ledger->links[]             = engineLink('customer', 'cust-1', '58');
    $ledger->links[]             = engineLink('customer', 'cust-3', '77');
    $client->customers['58']     = [
        'Id'               => '58',
        'SyncToken'        => '0',
        'DisplayName'      => 'Acme East',
        'PrimaryEmailAddr' => ['Address' => 'ap@acme.test'],
    ];
    $client->customers['77'] = [
        'Id'               => '77',
        'SyncToken'        => '0',
        'DisplayName'      => 'Acme West',
        'PrimaryEmailAddr' => ['Address' => 'w@acme.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-2');
    if ($variant === 'block') {
        $ledger->pending[] = enginePending('customer', 'cust-1');
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'] ?? null)->not->toBeIn(['58', '77'])
        ->and($client->calls)->toContain('createCustomer');
})->with(['one row' => ['single'], 'block' => ['block']]);

test(
    'a customer whose email matches a quickbooks customer another fleetbase customer syncs with gets its own customer',
    function (string $variant) {
        [$engine, $client]           = qbEngine();
        $ledger                      = engineLedger();
        $ledger->customers['cust-2'] = engineCustomer('cust-2', ['email' => 'acme@x.test', 'name' => 'Other']);
        $client->customers['qbo-58'] = [
            'Id'               => 'qbo-58',
            'SyncToken'        => '0',
            'DisplayName'      => 'Acme',
            'PrimaryEmailAddr' => ['Address' => 'acme@x.test'],
        ];
        if ($variant === 'same organization') {
            $ledger->customers['cust-1'] = engineCustomer('cust-1', ['email' => 'acme@x.test', 'name' => 'Acme']);
            $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-58');
        } else {
            $ledger->remoteLinkReader = static function (string $realm, string $entity, array $ids): array {
                return $entity === 'Customer' ? array_values(array_intersect($ids, ['qbo-58'])) : [];
            };
        }
        $ledger->pending[] = enginePending('customer', 'cust-2');
        if ($variant === 'block') {
            $ledger->customers['cust-3'] = engineCustomer('cust-3', ['email' => 'third@x.test', 'name' => 'Third']);
            $ledger->pending[]           = enginePending('customer', 'cust-3');
        }

        $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

        expect($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->not->toBe('qbo-58')
            ->and($client->calls)->toContain('createCustomer')
            ->and($client->customers['qbo-58']['DisplayName'])->toBe('Acme');
    }
)->with(['another organization' => ['cross'], 'same organization' => ['same organization'], 'block' => ['block']]);

test('rows that fail without a quickbooks error record why and stop retrying', function () {
    [$engine, $client]         = qbEngine();
    $ledger                    = engineLedger();
    $ledger->invoices['loose'] = engineInvoice('loose', ['customer_uuid' => '']);
    foreach ([['customer', 'ghost-c'], ['wallet', 'ghost-w'], ['invoice', 'ghost-i'], ['invoice', 'loose']] as [$type, $uuid]) {
        $ledger->pending[] = enginePending($type, $uuid);
    }

    $batch  = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $errors = array_column($ledger->attempts, 'error', 'local_uuid');

    expect($batch['failed'])->toBe(4)
        ->and(qbText($errors['ghost-c']))->toBe('Customer no longer exists in Fleetbase.')
        ->and(qbText($errors['ghost-w']))->toBe('Wallet no longer exists in Fleetbase.')
        ->and(qbText($errors['ghost-i']))->toBe('Invoice no longer exists in Fleetbase.')
        ->and(qbText($errors['loose']))->toBe('Invoice customer is not a Fleetbase customer.')
        ->and(array_unique(array_column($ledger->pending, 'status')))->toBe(['failed'])
        ->and($client->calls)->toBe([]);
});

test('an invoice without a service item creates one and fails clearly when quickbooks has no income account', function () {
    [$engine, $client]                                      = qbEngine();
    $ledger                                                 = engineLedger();
    $ledger->connections['company-uuid']['default_item_id'] = null;
    $ledger->customers['cust-1']                            = engineCustomer('cust-1');
    $ledger->links[]                                        = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']                              = engineInvoice('inv-1');
    $ledger->pending[]                                      = enginePending('invoice', 'inv-1');

    $failed = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($failed['failed'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toContain('Income account')
        ->and($client->calls)->toContain('ensureServiceItem')
        ->and($client->calls)->not->toContain('createInvoice');

    $client->serviceItemId                                = 'svc-1';
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[]                                    = enginePending('invoice', 'inv-1');
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time() + 10);

    expect($client->invoices['inv-1']['Line'][0]['SalesItemLineDetail']['ItemRef']['value'])->toBe('svc-1')
        ->and($ledger->connections['company-uuid']['default_item_id'])->toBe('svc-1');
});

test('an unknown home currency is read from quickbooks before the currency check', function () {
    [$engine, $client]                                    = qbEngine();
    $client->homeCurrency                                 = 'USD';
    $ledger                                               = engineLedger();
    $ledger->connections['company-uuid']['home_currency'] = null;
    $ledger->links[]                                      = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']                            = engineInvoice('inv-1', ['currency' => 'EUR']);
    $ledger->pending[]                                    = enginePending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->connections['company-uuid']['home_currency'])->toBe('USD')
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->toContain('homeCurrency')
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Invoice currency EUR does not match the QuickBooks home '
            . 'currency '
            . 'USD.')
        ->and($client->invoices)->toBeEmpty();
});

test('the currency check is skipped when quickbooks does not report a home currency', function () {
    [$engine, $client]                                    = qbEngine();
    $ledger                                               = engineLedger();
    $ledger->connections['company-uuid']['home_currency'] = null;
    $ledger->customers['cust-1']                          = engineCustomer('cust-1');
    $ledger->links[]                                      = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']                            = engineInvoice('inv-1', ['currency' => 'EUR']);
    $ledger->pending[]                                    = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and($client->calls)->toContain('homeCurrency')
        ->and($client->calls)->toContain('createInvoice');
});

test('a second sync does not create a second payment when the remote invoice balance is 0', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid']);
    $client->invoices['qb-1']    = [
        'Id'        => 'qb-1',
        'SyncToken' => '1',
        'DocNumber' => 'INV-1',
        'TotalAmt'  => 10,
        'Balance'   => 10,
        'TxnDate'   => '2026-09-01',
        'DueDate'   => '2026-09-15',
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');
    $settings          = qbSettings(['interval_minutes' => 1]);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect(count(array_filter($client->calls, fn ($call) => $call === 'createPayment')))->toBe(1);

    $client->invoices['qb-1']['Balance']              = 0;
    $client->invoices['qb-1']['LinkedTxn']            = [['TxnId' => 'pay-1', 'TxnType' => 'Payment']];
    $ledger->links                                    = array_values(array_filter(
        $ledger->links,
        fn (array $link): bool => ($link['local_type'] ?? '') !== 'payment'
    ));
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect(count(array_filter($client->calls, fn ($call) => $call === 'createPayment')))->toBe(1)
        ->and($second['failed'])->toBe(0)
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-1')['qbo_id'])->toBe('pay-1');
});

test('a differing quickbooks invoice still copies its payment before the row is done', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'sent', 'total' => 1000]);
    $client->invoices['qb-1']    = [
        'Id'        => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 15, 'Balance' => 10,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
    ];
    $client->payments['pay-1'] = [
        'Id'   => 'pay-1', 'SyncToken' => '1', 'TotalAmt' => 5, 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => 5, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'invoice_conflict'  => 'quickbooks',
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($ledger->pending[0]['status'])->toBe('done')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(500)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-1')['qbo_id'])->toBe('pay-1')
        ->and($client->calls)->not->toContain('createPayment');
});

test('a shared quickbooks payment copies only the amount applied to this invoice', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'sent']);
    $client->invoices['qb-1']    = [
        'Id'        => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 5,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [['TxnId' => 'pay-shared', 'TxnType' => 'Payment']],
    ];
    $client->payments['pay-shared'] = [
        'Id'   => 'pay-shared', 'SyncToken' => '1', 'TotalAmt' => 15, 'TxnDate' => '2026-09-10',
        'Line' => [
            ['Amount' => 5, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]],
            ['Amount' => 10, 'LinkedTxn' => [['TxnId' => 'qb-other', 'TxnType' => 'Invoice']]],
        ],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(500)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($client->payments['pay-shared']['Line'])->toHaveCount(2)
        ->and($client->calls)->not->toContain('updatePayment');
});

test('a shared quickbooks payment is not rewritten from fleetbase', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000, 'paid_at' => '2026-09-20']);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 4,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line'    => [[
            'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ];
    $client->payments['pay-shared'] = [
        'Id'   => 'pay-shared', 'SyncToken' => '1', 'TotalAmt' => 15, 'TxnDate' => '2026-09-10',
        'Line' => [
            ['Amount' => 6, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]],
            ['Amount' => 9, 'LinkedTxn' => [['TxnId' => 'qb-other', 'TxnType' => 'Invoice']]],
        ],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('payment', 'inv-1', 'pay-shared');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['skipped'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('This QuickBooks payment also applies credits or other '
            . 'invoices, so Fleetbase left it unchanged. Change it in QuickBooks.')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->payments['pay-shared']['TotalAmt'])->toBe(15)
        ->and($client->payments['pay-shared']['Line'])->toHaveCount(2);
});

test('a quickbooks payment that also applies a credit memo is not rewritten from fleetbase', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $paid                        = ['status' => 'paid', 'amount_paid' => 1000, 'paid_at' => '2026-09-20'];
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', $paid);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line'    => [[
            'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ];
    // 10.00 settles the invoice: 7.00 received plus a 3.00 credit memo.
    $client->payments['pay-credit'] = [
        'Id'   => 'pay-credit', 'SyncToken' => '1', 'TotalAmt' => 7, 'TxnDate' => '2026-09-10',
        'Line' => [
            ['Amount' => 10, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]],
            ['Amount' => 3, 'LinkedTxn' => [['TxnId' => 'cm-1', 'TxnType' => 'CreditMemo']]],
        ],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('payment', 'inv-1', 'pay-credit');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['skipped'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('This QuickBooks payment also applies credits or other '
            . 'invoices, so Fleetbase left it unchanged. Change it in QuickBooks.')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->payments['pay-credit']['TotalAmt'])->toBe(7)
        ->and($client->payments['pay-credit']['Line'])->toHaveCount(2);
});

test('a quickbooks payment with an unapplied amount is not rewritten from fleetbase', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $paid                        = ['status' => 'paid', 'amount_paid' => 1000, 'paid_at' => '2026-09-20'];
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', $paid);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
        'Line'    => [[
            'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ];
    // 12.00 received, 10.00 applied to the invoice, 2.00 left as customer credit.
    $client->payments['pay-over'] = [
        'Id'      => 'pay-over', 'SyncToken' => '1', 'TotalAmt' => '12.00', 'UnappliedAmt' => '2.00',
        'TxnDate' => '2026-09-10',
        'Line'    => [
            ['Amount' => 10, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]],
        ],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('payment', 'inv-1', 'pay-over');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['skipped'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('This QuickBooks payment also applies credits or other '
            . 'invoices, so Fleetbase left it unchanged. Change it in QuickBooks.')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->payments['pay-over']['TotalAmt'])->toBe('12.00');
});

test('an invoice number longer than quickbooks allows fails the row before anything is sent', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['number' => 'ACME-LOGISTICS-2026-00042']);
    $ledger->pending[]           = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Invoice number ACME-LOGISTICS-2026-00042 has more than '
            . 'the 21 characters QuickBooks accepts, so it was not sent. Use a shorter invoice number or prefix.');
});

test('a fleetbase invoice number longer than quickbooks allows is not sent on an update', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['number' => 'ACME-LOGISTICS-2026-00042']);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['DocNumber'])->toBe('INV-1')
        ->and(qbText($ledger->attempts[0]['error']))->toContain('ACME-LOGISTICS-2026-00042');
});

test('a part-paid invoice is dated by the payment ledger recorded', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(
        ['status' => 'partial', 'amount_paid' => 400, 'paid_at' => '', 'payment_date' => '2026-09-20'],
        ['Balance' => 10]
    );

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('createPayment')
        ->and($client->paymentPayloads[0]['TxnDate'])->toBe('2026-09-20')
        ->and($client->paymentPayloads[0]['TotalAmt'])->toBe('4.00');
});

test('a part-paid invoice with no recorded payment date leaves the quickbooks payment date alone', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(
        ['status' => 'partial', 'amount_paid' => 400, 'paid_at' => ''],
        ['Balance' => 6, 'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']]]
    );
    $client->payments['pay-1'] = [
        'Id'   => 'pay-1', 'SyncToken' => '0', 'TotalAmt' => '4.00', 'TxnDate' => '2026-09-12',
        'Line' => [['Amount' => '4.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $settings = qbSettings(['interval_minutes' => 1]);

    $first  = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($first['aligned'])->toBe(1)
        ->and($second['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->payments['pay-1']['TxnDate'])->toBe('2026-09-12');
});

test('a quickbooks payment is copied onto an invoice that is not paid yet', function () {
    [$engine, $client]               = qbEngine();
    $ledger                          = engineLedger();
    $ledger->customers['cust-1']     = engineCustomer('cust-1');
    $ledger->links[]                 = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-partial'] = engineInvoice('inv-partial', ['number' => 'INV-PART', 'status' => 'sent']);
    $ledger->invoices['inv-full']    = engineInvoice('inv-full', ['number' => 'INV-FULL', 'status' => 'sent']);
    $client->invoices['qb-partial']  = [
        'Id'        => 'qb-partial', 'SyncToken' => '1', 'DocNumber' => 'INV-PART', 'TotalAmt' => 10, 'Balance' => 6,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [['TxnId' => 'pay-partial', 'TxnType' => 'Payment']],
    ];
    $client->invoices['qb-full'] = [
        'Id'        => 'qb-full', 'SyncToken' => '1', 'DocNumber' => 'INV-FULL', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [['TxnId' => 'pay-full', 'TxnType' => 'Payment']],
    ];
    $client->payments['pay-partial'] = [
        'Id'   => 'pay-partial', 'SyncToken' => '1', 'TotalAmt' => 4, 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => 4, 'LinkedTxn' => [['TxnId' => 'qb-partial', 'TxnType' => 'Invoice']]]],
    ];
    $client->payments['pay-full'] = [
        'Id'   => 'pay-full', 'SyncToken' => '1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-12',
        'Line' => [['Amount' => 10, 'LinkedTxn' => [['TxnId' => 'qb-full', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-partial', 'qb-partial');
    $ledger->links[]   = engineLink('invoice', 'inv-full', 'qb-full');
    $ledger->pending[] = enginePending('invoice', 'inv-partial');
    $ledger->pending[] = enginePending('invoice', 'inv-full');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(2)
        ->and($batch['failed'])->toBe(0)
        ->and($ledger->invoices['inv-partial']['amount_paid'])->toBe(400)
        ->and($ledger->invoices['inv-partial']['status'])->toBe('partial')
        ->and($ledger->invoices['inv-partial']['payment_from_quickbooks'])->toBeTrue()
        ->and($ledger->invoices['inv-full']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-full']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-full']['payment_from_quickbooks'])->toBeTrue()
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-partial')['qbo_id'])->toBe('pay-partial')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-full')['qbo_id'])->toBe('pay-full')
        ->and($client->calls)->not->toContain('createPayment')
        ->and($client->calls)->not->toContain('updatePayment');
});

test('a customer lookup failure does not create a customer', function () {
    [$engine, $client]           = qbEngine();
    $client->failCustomerLookup  = new QuickBooksException(500, qbRequestFailed(500));
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->pending[]           = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->calls)->toContain('findCustomerByEmail')
        ->and($client->calls)->not->toContain('createCustomer')
        ->and(qbText($ledger->attempts[0]['error']))->toBe('QuickBooks request failed with status 500')
        ->and($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(1);
});

test('a new wallet in a foreign currency is not created', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = engineLedger();
    $ledger->wallets['wal-1'] = engineWallet('wal-1', ['currency' => 'eur']);
    $ledger->pending[]        = enginePending('wallet', 'wal-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Wallet currency EUR does not match the QuickBooks home '
            . 'currency '
            . 'USD.')
        ->and($client->calls)->not->toContain('createAccount');
});

test('an unexpected error on one row is recorded and the rest of the batch still runs', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new RuntimeException('Unexpected customer data');
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Grace', 'email' => 'grace@example.test']);
    $ledger->pending[]           = enginePending('customer', 'cust-1');
    $ledger->pending[]           = enginePending('customer', 'cust-2');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($batch['created'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Sync failed.')
        ->and($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(1);
});

test('a quickbooks error message is stored on the attempt and the row is still retried', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new QuickBooksException(400, qbRequestFailed(400, 'The name supplied already '
        . 'exists.'));
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->pending[]           = enginePending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->attempts[0]['error'])->toContain('The name supplied already exists.')
        ->and($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(1);
});

test('a batch without a 429 clears the previous rate limit wait', function () {
    [$engine]                                                    = qbEngine();
    $ledger                                                      = engineLedger();
    $ledger->connections['company-uuid']['last_rate_limit_wait'] = 240;
    $ledger->customers['cust-1']                                 = engineCustomer('cust-1');
    $ledger->pending[]                                           = enginePending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->connections['company-uuid']['last_rate_limit_wait'])->toBeNull();
});

test('sync now and reconcile during a rate limit record why they were skipped', function () {
    [$engine, $client]                                           = qbEngine();
    $ledger                                                      = engineLedger();
    $now                                                         = time();
    $ledger->connections['company-uuid']['rate_limited_until']   = $now + 30;
    $ledger->connections['company-uuid']['last_rate_limit_wait'] = 30;

    $forced     = $engine->runScheduled($ledger, 'company-uuid', qbSettings(), $now, true);
    $reconciled = $engine->reconcile($ledger, 'company-uuid', qbSettings(), $now);

    expect($forced['status'])->toBe('skipped')
        ->and($reconciled['status'])->toBe('skipped')
        ->and($ledger->batches)->toHaveCount(2)
        ->and(array_map('qbText', array_column($ledger->attempts, 'error')))->toBe([
            'QuickBooks asked Fleetbase to wait (rate limit). Try again in a few minutes.',
            'QuickBooks asked Fleetbase to wait (rate limit). Try again in a few minutes.',
        ])
        ->and($ledger->connections['company-uuid']['last_rate_limit_wait'])->toBe(30)
        ->and($client->calls)->toBe([]);
});

test('sync now without a connection points to the connection page', function () {
    [$engine] = qbEngine();
    $ledger   = new SyncLedger();

    $forced     = $engine->runScheduled($ledger, 'company-uuid', qbSettings(), time(), true);
    $reconciled = $engine->reconcile($ledger, 'company-uuid', qbSettings(), time());

    expect($forced['status'])->toBe('skipped')
        ->and($reconciled['status'])->toBe('skipped')
        ->and($ledger->batches)->toBe([])
        ->and($ledger->attempts)->toBe([]);
});

test('reconcile processes at most batch_size and leaves the rest pending', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['number' => 'INV-1']);
    $ledger->invoices['inv-2']   = engineInvoice('inv-2', ['number' => 'INV-2']);

    $batch   = $engine->reconcile($ledger, 'company-uuid', qbSettings(['batch_size' => 1]), time());
    $pending = array_values(array_filter($ledger->pending, fn (array $row): bool => ($row['status'] ?? '') === 'pending' && ($row['local_type'] ?? '') === 'invoice'));

    expect($batch['created'])->toBe(1)
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]['local_uuid'])->toBe('inv-2')
        ->and($client->calls)->not->toContain('listInvoices')
        ->and(array_count_values($client->calls)['createInvoice'] ?? 0)->toBe(1);
});

test('a disabled customer is not synced', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->pending[]           = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'customer_enabled' => false,
    ]), time());

    expect($batch['skipped'])->toBe(1)
        ->and($client->calls)->not->toContain('createCustomer');
});

test('customer sync off fails an invoice that still needs that customer', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'off',
    ]), time());

    expect($batch['failed'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Customer sync is off, so this invoice cannot be synced.')
        ->and($client->calls)->not->toContain('createCustomer')
        ->and($client->calls)->not->toContain('createInvoice');
});

test('outbound does not copy a quickbooks customer onto fleetbase', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = [
        'Id'               => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Other Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'outbound',
        'customer_conflict'  => 'quickbooks',
    ]), time());

    expect($ledger->customers['cust-1']['name'])->toBe('Ada')
        ->and($client->calls)->toContain('updateCustomer');
});

test('inbound does not create a quickbooks customer', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->pending[]           = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_direction' => 'inbound',
    ]), time());

    expect($batch['skipped'])->toBe(1)
        ->and($client->calls)->not->toContain('createCustomer');
});

test('several customers in one block use the batch method', function () {
    [$engine, $client] = qbEngine();
    $ledger            = engineLedger();
    foreach (['cust-1', 'cust-2'] as $uuid) {
        $ledger->customers[$uuid] = engineCustomer($uuid, ['name' => 'Customer ' . $uuid, 'email' => $uuid . '@example.test']);
        $ledger->pending[]        = enginePending('customer', $uuid);
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('batch')
        ->and(array_count_values($client->calls)['createCustomer'] ?? 0)->toBe(2);
});

test('a customer note change is sent because the content hash differs', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1', ['notes' => 'Priority']);
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = [
        'Id'               => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['Notes'])->toBe('Priority');
});

test('a lost wallet link reattaches the one account with the same name and currency', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = engineLedger();
    $ledger->wallets['wal-1']   = engineWallet('wal-1');
    $ledger->links[]            = engineLink('wallet', 'wal-1', 'gone');
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9', 'SyncToken' => '3', 'Name' => 'Operating', 'Active' => true,
        'Description' => 'Float', 'CurrencyRef' => ['value' => 'USD'],
    ];
    $ledger->pending[] = enginePending('wallet', 'wal-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-1')['qbo_id'])->toBe('acct-9')
        ->and($client->calls)->not->toContain('createAccount')
        ->and($batch['aligned'])->toBe(1);
});

test('a cleared wallet description is pushed once and an empty quickbooks description is left in place', function () {
    $mapper  = new WalletMapper();
    $cleared = $mapper->toQuickBooks(['name' => 'Operating', 'description' => ''], 'fleetbase', true);
    $omitted = $mapper->toQuickBooks(['name' => 'Operating', 'description' => '']);

    expect($cleared['Description'])->toBe('')
        ->and($omitted)->not->toHaveKey('Description');

    [$engine, $client]          = qbEngine();
    $ledger                     = engineLedger();
    $ledger->wallets['wal-1']   = engineWallet('wal-1', ['description' => '']);
    $ledger->links[]            = engineLink('wallet', 'wal-1', 'acct-9');
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'Description' => 'Float',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'USD'],
    ];
    $ledger->pending[] = enginePending('wallet', 'wal-1');
    $settings          = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($first['aligned'])->toBe(0)
        ->and($ledger->wallets['wal-1']['description'])->toBe('')
        ->and($client->accounts['acct-9']['Description'])->toBe('')
        ->and(array_count_values($client->calls)['updateAccount'] ?? 0)->toBe(1);

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($ledger->wallets['wal-1']['description'])->toBe('')
        ->and($client->accounts['acct-9']['Description'])->toBe('')
        ->and(array_count_values($client->calls)['updateAccount'] ?? 0)->toBe(1);

    [$referenceEngine, $referenceClient] = qbEngine();
    $referenceLedger                     = engineLedger();
    $referenceLedger->wallets['wal-1']   = engineWallet('wal-1', ['description' => '']);
    $referenceLedger->links[]            = engineLink('wallet', 'wal-1', 'acct-9');
    $referenceClient->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'Description' => 'Float',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'USD'],
    ];
    $referenceLedger->pending[] = enginePending('wallet', 'wal-1');
    $referenceSettings          = qbSettings([
        'interval_minutes' => 1,
        'wallet_reference' => 'quickbooks',
    ]);

    $referenceFirst = $referenceEngine->runScheduled($referenceLedger, 'company-uuid', $referenceSettings, time());

    expect($referenceFirst['updated'])->toBe(1)
        ->and($referenceLedger->wallets['wal-1']['description'])->toBe('')
        ->and($referenceClient->accounts['acct-9']['Description'])->toBe('')
        ->and(array_count_values($referenceClient->calls)['updateAccount'] ?? 0)->toBe(1);

    $referenceLedger->connections['company-uuid']['last_batch_at'] = null;
    $referenceLedger->pending[0]['status']                         = 'pending';
    $referenceSecond                                               = $referenceEngine->runScheduled($referenceLedger, 'company-uuid', $referenceSettings, time() + 10);

    expect($referenceSecond['aligned'])->toBe(1)
        ->and($referenceSecond['updated'])->toBe(0)
        ->and(array_count_values($referenceClient->calls)['updateAccount'] ?? 0)->toBe(1);

    [$sourceEngine, $sourceClient]    = qbEngine();
    $sourceLedger                     = engineLedger();
    $sourceLedger->wallets['wal-1']   = engineWallet('wal-1', ['description' => 'Keep']);
    $sourceLedger->links[]            = engineLink('wallet', 'wal-1', 'acct-9');
    $sourceClient->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'Description' => '',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'USD'],
    ];
    $sourceLedger->pending[] = enginePending('wallet', 'wal-1');

    $fromQuickBooks = $sourceEngine->runScheduled($sourceLedger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'wallet_conflict'  => 'quickbooks',
    ]), time());

    expect($fromQuickBooks['aligned'])->toBe(1)
        ->and($fromQuickBooks['updated'])->toBe(0)
        ->and($sourceLedger->wallets['wal-1']['description'])->toBe('Keep')
        ->and($sourceClient->calls)->not->toContain('updateAccount');

    [$inboundEngine, $inboundClient]   = qbEngine();
    $inboundLedger                     = engineLedger();
    $inboundLedger->wallets['wal-1']   = engineWallet('wal-1', ['description' => 'Keep']);
    $inboundLedger->links[]            = engineLink('wallet', 'wal-1', 'acct-9');
    $inboundClient->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'Description' => '',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'USD'],
    ];
    $inboundLedger->pending[] = enginePending('wallet', 'wal-1');

    $inbound = $inboundEngine->runScheduled($inboundLedger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'wallet_direction' => 'inbound',
        'wallet_reference' => 'quickbooks',
    ]), time());

    expect($inbound['aligned'])->toBe(1)
        ->and($inbound['updated'])->toBe(0)
        ->and($inboundLedger->wallets['wal-1']['description'])->toBe('Keep')
        ->and($inboundClient->calls)->not->toContain('updateAccount');
});

test('a failed payment flush is recorded on the invoice and a 401 leaves the rest pending', function () {
    $client = new class extends FakeQuickBooks {
        public int $paymentAttempts = 0;

        public ?Throwable $failPayment = null;

        public int $failPaymentOn = 0;

        public function createPayment(array $connection, array $payload): array
        {
            $this->paymentAttempts++;
            if ($this->failPayment !== null && $this->paymentAttempts === $this->failPaymentOn) {
                $this->calls[] = 'createPayment';
                throw $this->failPayment;
            }

            return parent::createPayment($connection, $payload);
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-sent']      = engineInvoice('inv-sent', ['number' => 'INV-SENT']);
    $ledger->invoices['inv-ok']        = engineInvoice('inv-ok', ['number' => 'INV-OK', 'status' => 'paid']);
    $ledger->invoices['inv-bad']       = engineInvoice('inv-bad', ['number' => 'INV-BAD', 'status' => 'paid']);
    foreach (['sent' => 'INV-SENT', 'ok' => 'INV-OK', 'bad' => 'INV-BAD'] as $key => $number) {
        $id                    = 'qb-' . $key;
        $client->invoices[$id] = ['Id' => $id, 'SyncToken' => '1', 'DocNumber' => $number, 'TotalAmt' => 10, 'Balance' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
        $ledger->links[]       = engineLink('invoice', 'inv-' . $key, $id);
    }
    foreach (['inv-sent', 'inv-ok', 'inv-bad'] as $uuid) {
        $ledger->pending[] = enginePending('invoice', $uuid);
    }
    $client->failPayment   = new QuickBooksException(400, qbRequestFailed(400, 'The payment amount is invalid.'));
    $client->failPaymentOn = 2;

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows   = array_column($ledger->pending, null, 'local_uuid');
    $errors = array_column($ledger->attempts, 'error', 'local_uuid');

    expect($client->paymentAttempts)->toBe(2)
        ->and($rows['inv-sent']['status'])->toBe('done')
        ->and($rows['inv-ok']['status'])->toBe('done')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-1')['qbo_id'])->toBe('pay-1')
        ->and($rows['inv-bad']['status'])->toBe('pending')
        ->and($rows['inv-bad']['attempts'])->toBe(1)
        ->and(qbText($errors['inv-bad']))->toBe('QuickBooks rejected the request: The payment amount is invalid.')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-bad'))->toBeNull()
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeFalse();

    $client = new class extends FakeQuickBooks {
        public int $paymentAttempts = 0;

        public function createPayment(array $connection, array $payload): array
        {
            $this->paymentAttempts++;
            $this->calls[] = 'createPayment';
            throw new RuntimeException('disk failed');
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-sent']      = engineInvoice('inv-sent', ['number' => 'INV-SENT']);
    $ledger->invoices['inv-bad']       = engineInvoice('inv-bad', ['number' => 'INV-BAD', 'status' => 'paid']);
    foreach (['sent' => 'INV-SENT', 'bad' => 'INV-BAD'] as $key => $number) {
        $id                    = 'qb-' . $key;
        $client->invoices[$id] = ['Id' => $id, 'SyncToken' => '1', 'DocNumber' => $number, 'TotalAmt' => 10, 'Balance' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
        $ledger->links[]       = engineLink('invoice', 'inv-' . $key, $id);
    }
    $ledger->pending[] = enginePending('invoice', 'inv-sent');
    $ledger->pending[] = enginePending('invoice', 'inv-bad');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows   = array_column($ledger->pending, null, 'local_uuid');
    $errors = array_column($ledger->attempts, 'error', 'local_uuid');

    expect($client->paymentAttempts)->toBe(1)
        ->and($rows['inv-sent']['status'])->toBe('done')
        ->and($errors['inv-sent'])->toBeNull()
        ->and($rows['inv-bad']['status'])->toBe('pending')
        ->and(qbText($errors['inv-bad']))->toBe('Sync failed.');

    $client = new class extends FakeQuickBooks {
        public int $paymentAttempts = 0;

        public function createPayment(array $connection, array $payload): array
        {
            $this->paymentAttempts++;
            $this->calls[] = 'createPayment';
            throw new QuickBooksException(401, qbRequestFailed(401));
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    foreach (['before' => 'sent', 'paid' => 'paid', 'after' => 'sent'] as $key => $status) {
        $uuid                    = 'inv-' . $key;
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => 'INV-' . strtoupper($key), 'status' => $status]);
        $id                      = 'qb-' . $key;
        $client->invoices[$id]   = [
            'Id'      => $id, 'SyncToken' => '1', 'DocNumber' => 'INV-' . strtoupper($key), 'TotalAmt' => 10, 'Balance' => 10,
            'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
            'Line'    => [[
                'Amount'              => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery',
                'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
            ]],
        ];
        $ledger->links[]         = engineLink('invoice', $uuid, $id);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows  = array_column($ledger->pending, null, 'local_uuid');

    expect($client->paymentAttempts)->toBe(1)
        ->and($batch['aligned'])->toBe(1)
        ->and($rows['inv-before']['status'])->toBe('done')
        ->and($rows['inv-paid']['status'])->toBe('pending')
        ->and($rows['inv-paid']['attempts'])->toBe(0)
        ->and($rows['inv-after']['status'])->toBe('pending')
        ->and($rows['inv-after']['attempts'])->toBe(0)
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue()
        ->and(end($ledger->attempts)['error'])->toBe(SyncEngine::TOKEN_REJECTED_MESSAGE);
});

test('a failed customer lookup does not create a customer and a real miss checks the display name', function () {
    [$engine, $client]             = qbEngine();
    $ledger                        = engineLedger();
    $client->customers['qbo-ada']  = ['Id' => 'qbo-ada', 'SyncToken' => '1', 'DisplayName' => 'Ada'];
    $client->customers['qbo-bea']  = ['Id' => 'qbo-bea', 'SyncToken' => '1', 'DisplayName' => 'Bea'];
    $ledger->customers['cust-ada'] = engineCustomer('cust-ada', ['name' => 'Ada', 'email' => 'ada-new@example.test']);
    $ledger->customers['cust-bea'] = engineCustomer('cust-bea', ['name' => 'Bea', 'email' => 'bea-new@example.test']);
    $ledger->pending[]             = enginePending('customer', 'cust-ada');
    $ledger->pending[]             = enginePending('customer', 'cust-bea');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('createCustomer')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-ada')['qbo_id'])->toBe('qbo-ada')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-bea')['qbo_id'])->toBe('qbo-bea');

    $client = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            $results = parent::batch($connection, $items);
            foreach ($results as $bId => $result) {
                if (str_starts_with((string) $bId, 'customer:') === true) {
                    $results[$bId] = [
                        'ok'     => false,
                        'body'   => [],
                        'rows'   => [],
                        'error'  => qbRequestFailed(500),
                        'status' => 500,
                        'halt'   => false,
                    ];
                }
            }

            return $results;
        }
    };
    [$engine, $client]           = qbEngine($client);
    $ledger                      = engineLedger();
    $client->customers['qbo-1']  = ['Id' => 'qbo-1', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $client->customers['qbo-2']  = ['Id' => 'qbo-2', 'SyncToken' => '0', 'DisplayName' => 'Bea', 'PrimaryEmailAddr' => ['Address' => 'bea@example.test']];
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->customers['cust-3'] = engineCustomer('cust-3', ['name' => 'No Such', 'email' => 'missing@example.test']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $ledger->links[]             = engineLink('customer', 'cust-2', 'qbo-2');
    foreach (['cust-1', 'cust-2', 'cust-3'] as $uuid) {
        $ledger->pending[] = enginePending('customer', $uuid);
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows = array_column($ledger->pending, null, 'local_uuid');

    expect($client->calls)->not->toContain('createCustomer')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->toBe('qbo-2')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-3'))->toBeNull()
        ->and($rows['cust-3']['status'])->not->toBe('done')
        ->and($client->calls)->not->toContain('getCustomer:qbo-1')
        ->and($client->calls)->not->toContain('getCustomer:qbo-2')
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($client->calls)->not->toContain('findCustomerByDisplayName');
});

test('a failed or short invoice or wallet read is not treated as missing', function () {
    $failReads = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                if (str_contains($query, 'from Invoice where Id') === true || str_contains($query, 'from Account where Id') === true) {
                    return [
                        (string) $item['bId'] => [
                            'ok'     => false,
                            'body'   => [],
                            'rows'   => [],
                            'error'  => qbRequestFailed(500),
                            'status' => 500,
                            'halt'   => false,
                        ],
                    ];
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client]                 = qbEngine($failReads);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    foreach (['inv-a', 'inv-b'] as $index => $uuid) {
        $id                      = 'qb-' . ($index + 1);
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => 'INV-' . ($index + 1), 'status' => 'cancelled']);
        $client->invoices[$id]   = ['Id' => $id, 'SyncToken' => '1', 'DocNumber' => 'INV-' . ($index + 1), 'TotalAmt' => 10];
        $ledger->links[]         = engineLink('invoice', $uuid, $id);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }

    $failed = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows   = array_column($ledger->pending, null, 'local_uuid');

    expect($failed['voided'])->toBe(0)
        ->and($failed['aligned'])->toBe(0)
        ->and($failed['failed'])->toBe(2)
        ->and($client->calls)->not->toContain('voidInvoice')
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->not->toContain('getInvoice:qb-1')
        ->and($rows['inv-a']['status'])->not->toBe('done')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-a')['qbo_id'])->toBe('qb-1');

    $client                            = new FakeQuickBooks();
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-void']      = engineInvoice('inv-void', ['number' => 'INV-VOID', 'status' => 'cancelled']);
    $client->invoices['qb-void']       = ['Id' => 'qb-void', 'SyncToken' => '1', 'DocNumber' => 'INV-VOID', 'TotalAmt' => 10];
    $ledger->links[]                   = engineLink('invoice', 'inv-void', 'qb-void');
    $ledger->invoices['inv-gone']      = engineInvoice('inv-gone', ['number' => 'INV-GONE', 'status' => 'cancelled']);
    $ledger->links[]                   = engineLink('invoice', 'inv-gone', 'qb-gone');
    $ledger->invoices['inv-live']      = engineInvoice('inv-live', ['number' => 'INV-LIVE']);
    $ledger->links[]                   = engineLink('invoice', 'inv-live', 'qb-live');
    foreach (['inv-void', 'inv-gone', 'inv-live'] as $uuid) {
        $ledger->pending[] = enginePending('invoice', $uuid);
    }

    $miss     = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $missRows = array_column($ledger->pending, null, 'local_uuid');

    expect($miss['voided'])->toBe(1)
        ->and($miss['aligned'])->toBe(2)
        ->and($miss['created'])->toBe(0)
        ->and($client->calls)->toContain('voidInvoice')
        ->and($missRows['inv-gone']['status'])->toBe('done')
        ->and($missRows['inv-live']['status'])->toBe('done')
        ->and($ledger->invoices['inv-live']['status'])->toBe('sent')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-live')['qbo_id'])->toBe('qb-live')
        ->and(array_count_values($client->calls)['createInvoice'] ?? 0)->toBe(0);

    $one = new class extends FakeQuickBooks {
        public function getInvoice(array $connection, string $id): ?array
        {
            $this->calls[] = 'getInvoice:' . $id;
            if ($id === 'qb-a') {
                throw new QuickBooksException(500, qbRequestFailed(500));
            }

            return $this->invoices[$id] ?? null;
        }
    };
    [$engine, $client]                 = qbEngine($one);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-a']         = engineInvoice('inv-a', ['number' => 'INV-A']);
    $ledger->links[]                   = engineLink('invoice', 'inv-a', 'qb-a');
    $client->invoices['qb-a']          = ['Id' => 'qb-a', 'SyncToken' => '1', 'DocNumber' => 'INV-A', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->invoices['inv-b']         = engineInvoice('inv-b', ['number' => 'INV-B']);
    $ledger->pending[]                 = enginePending('invoice', 'inv-a');
    $ledger->pending[]                 = enginePending('invoice', 'inv-b');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $oneRows = array_column($ledger->pending, null, 'local_uuid');

    expect(array_count_values($client->calls)['getInvoice:qb-a'] ?? 0)->toBe(1)
        ->and($oneRows['inv-a']['status'])->not->toBe('done')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-a')['qbo_id'])->toBe('qb-a')
        ->and($oneRows['inv-b']['status'])->toBe('done')
        ->and(array_count_values($client->calls)['createInvoice'] ?? 0)->toBe(1);

    $accounts = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                if (str_contains((string) ($item['query'] ?? ''), 'from Account where Id') === true) {
                    return [
                        (string) $item['bId'] => [
                            'ok'     => false,
                            'body'   => [],
                            'rows'   => [],
                            'error'  => qbRequestFailed(500),
                            'status' => 500,
                            'halt'   => false,
                        ],
                    ];
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client] = qbEngine($accounts);
    $ledger            = engineLedger();
    foreach (['wal-1' => 'acct-1', 'wal-2' => 'acct-2'] as $uuid => $id) {
        $ledger->wallets[$uuid] = engineWallet($uuid, ['name' => 'Wallet ' . $uuid]);
        $client->accounts[$id]  = ['Id' => $id, 'SyncToken' => '0', 'Name' => 'Wallet ' . $uuid, 'Active' => true, 'CurrencyRef' => ['value' => 'USD']];
        $ledger->links[]        = engineLink('wallet', $uuid, $id);
        $ledger->pending[]      = enginePending('wallet', $uuid);
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('createAccount')
        ->and($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-1')['qbo_id'])->toBe('acct-1')
        ->and($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-2')['qbo_id'])->toBe('acct-2');
});

test('an invoice id query keeps paging until a short page', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $idQueries = [];

        public function batch(array $connection, array $items): array
        {
            $results = parent::batch($connection, $items);
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                $bId   = (string) ($item['bId'] ?? '');
                if (isset($results[$bId]) === false || str_contains($query, 'from Invoice where Id') === false) {
                    continue;
                }
                $this->idQueries[] = $query;
                $rows              = is_array($results[$bId]['rows'] ?? null) === true ? array_values($results[$bId]['rows']) : [];
                $start             = 1;
                $max               = 100;
                if (preg_match('/startposition\s+(\d+)/i', $query, $match) === 1) {
                    $start = (int) $match[1];
                }
                if (preg_match('/maxresults\s+(\d+)/i', $query, $match) === 1) {
                    $max = (int) $match[1];
                }
                if (str_contains($query, 'startposition') === false) {
                    $max = min($max, 100);
                }
                $results[$bId]['rows'] = array_slice($rows, max(0, $start - 1), $max);
            }

            return $results;
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    for ($i = 1; $i <= 101; $i++) {
        $uuid                    = 'inv-' . $i;
        $id                      = 'qb-' . $i;
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => 'INV-' . $i, 'status' => 'cancelled']);
        $client->invoices[$id]   = ['Id' => $id, 'SyncToken' => '1', 'DocNumber' => 'INV-' . $i, 'TotalAmt' => 10];
        $ledger->links[]         = engineLink('invoice', $uuid, $id);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'batch_size' => 120]), time());

    expect($batch['voided'])->toBe(101)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->not->toContain('getInvoice:qb-101')
        ->and($client->invoices['qb-101']['voided'] ?? false)->toBeTrue()
        ->and($client->idQueries)->not->toBeEmpty();
    foreach ($client->idQueries as $query) {
        preg_match_all("/'[^']*'/", $query, $ids);
        expect($query)->toContain('startposition')
            ->and($query)->toContain('maxresults')
            ->and(count($ids[0]))->toBeLessThanOrEqual(30);
    }
});

test('a doc number match in a block does not create a second payment', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-old']       = engineInvoice('inv-old', ['number' => 'INV-OLD']);
    $client->invoices['qb-old']        = ['Id' => 'qb-old', 'SyncToken' => '1', 'DocNumber' => 'INV-OLD', 'TotalAmt' => 10, 'Balance' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]                   = engineLink('invoice', 'inv-old', 'qb-old');
    $ledger->pending[]                 = enginePending('invoice', 'inv-old');
    $ledger->invoices['inv-new']       = engineInvoice('inv-new', ['number' => 'INV-NEW', 'status' => 'partial', 'amount_paid' => 400]);
    $client->invoices['qb-new']        = [
        'Id'          => 'qb-new', 'SyncToken' => '1', 'DocNumber' => 'INV-NEW', 'TotalAmt' => 10, 'Balance' => 6,
        'TxnDate'     => '2026-09-01', 'DueDate' => '2026-09-15',
        'CustomerRef' => ['value' => 'qbo-customer'],
        'LinkedTxn'   => [['TxnId' => 'pay-new', 'TxnType' => 'Payment']],
    ];
    $client->payments['pay-new'] = [
        'Id'   => 'pay-new', 'SyncToken' => '0', 'TotalAmt' => '4.00', 'TxnDate' => '2026-09-01',
        'Line' => [['Amount' => '4.00', 'LinkedTxn' => [['TxnId' => 'qb-new', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->pending[] = enginePending('invoice', 'inv-new');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('createPayment')
        ->and($client->calls)->not->toContain('findPaymentForInvoice')
        ->and($client->calls)->not->toContain('getInvoice:qb-new')
        ->and(array_count_values($client->calls)['getPayment:pay-new'] ?? 0)->toBe(1)
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-new')['qbo_id'])->toBe('qb-new')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-new')['qbo_id'])->toBe('pay-new');

    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-old']       = engineInvoice('inv-old', ['number' => 'INV-OLD']);
    $client->invoices['qb-old']        = ['Id' => 'qb-old', 'SyncToken' => '1', 'DocNumber' => 'INV-OLD', 'TotalAmt' => 10, 'Balance' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]                   = engineLink('invoice', 'inv-old', 'qb-old');
    $ledger->pending[]                 = enginePending('invoice', 'inv-old');
    $ledger->invoices['inv-zero']      = engineInvoice('inv-zero', ['number' => 'INV-ZERO', 'status' => 'paid']);
    $client->invoices['qb-zero']       = [
        'Id'      => 'qb-zero', 'SyncToken' => '1', 'DocNumber' => 'INV-ZERO', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'CustomerRef' => ['value' => 'qbo-customer'],
    ];
    $ledger->pending[]                 = enginePending('invoice', 'inv-zero');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('createPayment')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-zero')['qbo_id'])->toBe('qb-zero');
});

test('successful customer email misses are cached and display names are prefetched together', function () {
    $client = new class extends FakeQuickBooks {
        public array $displayNameBatchSizes = [];

        public function batch(array $connection, array $items): array
        {
            $names = array_values(array_filter($items, static fn (array $item): bool => str_contains((string) ($item['query'] ?? ''), 'DisplayName =')));
            if ($names !== []) {
                $this->displayNameBatchSizes[] = count($names);
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client] = qbEngine($client);
    $ledger            = engineLedger();
    foreach (['ada' => 'Ada', 'bea' => 'Bea'] as $key => $name) {
        $uuid                             = 'cust-' . $key;
        $ledger->customers[$uuid]         = engineCustomer($uuid, ['name' => $name, 'email' => $key . '-new@example.test']);
        $ledger->pending[]                = enginePending('customer', $uuid);
        $client->customers['qbo-' . $key] = ['Id' => 'qbo-' . $key, 'SyncToken' => '0', 'DisplayName' => $name];
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->displayNameBatchSizes)->toBe([2])
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($client->calls)->not->toContain('findCustomerByDisplayName')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-ada')['qbo_id'])->toBe('qbo-ada')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-bea')['qbo_id'])->toBe('qbo-bea');
});

test('failed linked payment reads fail their invoices without creating replacement payments', function () {
    $client = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            if (str_contains((string) ($items[0]['query'] ?? ''), 'from Payment where Id IN') === true) {
                return ['read-0-1' => [
                    'ok'     => false, 'body' => [], 'rows' => [],
                    'error'  => qbRequestFailed(500),
                    'status' => 500, 'halt' => false,
                ]];
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client]           = qbEngine($client);
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    foreach (['one', 'two'] as $key) {
        $uuid                    = 'inv-' . $key;
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => 'INV-' . strtoupper($key), 'status' => 'paid']);
        $ledger->links[]         = engineLink('payment', $uuid, 'pay-' . $key);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows  = array_column($ledger->pending, null, 'local_uuid');

    expect($batch['failed'])->toBe(2)
        ->and($rows['inv-one']['status'])->toBe('pending')
        ->and($rows['inv-two']['status'])->toBe('pending')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-one')['qbo_id'])->toBe('inv-1')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-two')['qbo_id'])->toBe('inv-2')
        ->and($client->calls)->not->toContain('createPayment');
});

test('a block read every invoice fails with keeps the status QuickBooks gave', function (
    string $read,
    int $status,
    string $expected,
) {
    $client = new class extends FakeQuickBooks {
        public string $failRead = '';

        public int $failStatus = 500;

        public function batch(array $connection, array $items): array
        {
            if (str_contains((string) ($items[0]['query'] ?? ''), $this->failRead) === true) {
                return [(string) $items[0]['bId'] => [
                    'ok'     => false, 'body' => [], 'rows' => [],
                    'error'  => qbRequestFailed($this->failStatus, 'Refused'),
                    'status' => $this->failStatus, 'halt' => false,
                ]];
            }

            return parent::batch($connection, $items);
        }
    };
    $client->failRead   = $read;
    $client->failStatus = $status;
    [$engine, $client]  = qbEngine($client);
    $client->salesTax   = ['mode' => 'us', 'automated' => false, 'rate' => '2'];
    $ledger             = engineLedger();
    foreach (['one', 'two'] as $key) {
        $ledger->customers['cust-' . $key]  = engineCustomer('cust-' . $key, ['name' => 'Ada ' . $key]);
        $ledger->links[]                    = engineLink('customer', 'cust-' . $key, 'qbo-' . $key);
        $client->customers['qbo-' . $key]   = [
            'Id'          => 'qbo-' . $key,
            'SyncToken'   => '0',
            'DisplayName' => 'Ada ' . $key,
        ];
        $ledger->invoices['inv-' . $key]    = engineInvoice('inv-' . $key, [
            'number'        => 'INV-' . strtoupper($key),
            'customer_uuid' => 'cust-' . $key,
            'status'        => 'paid',
        ]);
        $ledger->links[]   = engineLink('payment', 'inv-' . $key, 'pay-' . $key);
        $ledger->pending[] = enginePending('invoice', 'inv-' . $key);
    }
    $settings = qbSettings(['interval_minutes' => 1, 'retry_limit' => 1]);

    for ($run = 0; $run < 3; $run++) {
        foreach (array_keys($ledger->pending) as $index) {
            $ledger->pending[$index]['next_attempt_at'] = null;
        }
        $ledger->connections['company-uuid']['last_batch_at'] = null;
        $engine->runScheduled($ledger, 'company-uuid', $settings, time() + $run * 60);
    }

    expect(array_column($ledger->pending, 'status'))->toBe([$expected, $expected])
        ->and($client->calls)->not->toContain('createPayment');
})->with([
    'a refused payment read'       => ['from Payment where Id IN', 400, 'failed'],
    'an outage on a payment read'  => ['from Payment where Id IN', 503, 'pending'],
    'a refused tax customer read'  => ['from Customer where Id IN', 400, 'failed'],
    'an outage on a customer read' => ['from Customer where Id IN', 503, 'pending'],
]);

test('a block customer lookup QuickBooks refuses stops at the retry limit', function (int $status, string $expected) {
    $client = new class extends FakeQuickBooks {
        public int $failStatus = 500;

        public function batch(array $connection, array $items): array
        {
            $results = parent::batch($connection, $items);
            foreach ($items as $item) {
                if (str_contains((string) ($item['query'] ?? ''), 'PrimaryEmailAddr') === true) {
                    $results[(string) $item['bId']] = [
                        'ok'     => false, 'body' => [], 'rows' => [],
                        'error'  => qbRequestFailed($this->failStatus, 'Refused'),
                        'status' => $this->failStatus, 'halt' => false,
                    ];
                }
            }

            return $results;
        }
    };
    $client->failStatus = $status;
    [$engine, $client]  = qbEngine($client);
    $ledger             = engineLedger();
    foreach (['one', 'two'] as $key) {
        $ledger->customers['cust-' . $key] = engineCustomer('cust-' . $key, ['email' => $key . '@example.test']);
        $ledger->pending[]                 = enginePending('customer', 'cust-' . $key);
    }
    $settings = qbSettings(['interval_minutes' => 1, 'retry_limit' => 1]);

    for ($run = 0; $run < 3; $run++) {
        foreach (array_keys($ledger->pending) as $index) {
            $ledger->pending[$index]['next_attempt_at'] = null;
        }
        $ledger->connections['company-uuid']['last_batch_at'] = null;
        $engine->runScheduled($ledger, 'company-uuid', $settings, time() + $run * 60);
    }

    expect(array_column($ledger->pending, 'status'))->toBe([$expected, $expected])
        ->and($client->calls)->not->toContain('createCustomer');
})->with(['a refusal' => [400, 'failed'], 'an outage' => [503, 'pending']]);

test('an invoice link and quickbooks number survive a 401 during payment prefetch', function () {
    $client = new class extends FakeQuickBooks {
        public function getPayment(array $connection, string $id): ?array
        {
            throw new QuickBooksException(401, qbRequestFailed(401));
        }
    };
    [$engine, $client]             = qbEngine($client);
    $ledger                        = engineLedger();
    $ledger->customers['cust-1']   = engineCustomer('cust-1');
    $ledger->links[]               = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-paid']  = engineInvoice('inv-paid', ['number' => 'LOCAL-1', 'status' => 'paid']);
    $ledger->invoices['inv-after'] = engineInvoice('inv-after', ['number' => 'LOCAL-2']);
    $ledger->links[]               = engineLink('payment', 'inv-paid', 'pay-old');
    $ledger->pending[]             = enginePending('invoice', 'inv-paid');
    $ledger->pending[]             = enginePending('invoice', 'inv-after');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'invoice_reference' => 'quickbooks',
    ]), time());
    $rows = array_column($ledger->pending, null, 'local_uuid');

    expect($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-paid')['qbo_id'])->toBe('inv-1')
        ->and($ledger->invoices['inv-paid']['number'])->toBe('1')
        ->and($rows['inv-paid']['status'])->toBe('pending')
        ->and($rows['inv-after']['status'])->toBe('pending')
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue();
});

test('a failed multi-id remote read is not applied as a miss', function () {
    $failReads = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                if (str_contains((string) ($item['query'] ?? ''), 'from Customer where Id IN') === true) {
                    return [
                        (string) $item['bId'] => [
                            'ok'     => false,
                            'body'   => [],
                            'rows'   => [],
                            'error'  => qbRequestFailed(500),
                            'status' => 500,
                            'halt'   => false,
                        ],
                    ];
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine]                    = qbEngine($failReads);
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Local Bea', 'email' => 'bea@example.test']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $ledger->links[]             = engineLink('customer', 'cust-2', 'qbo-2');
    $entities                    = [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ['entity' => 'Customer', 'id' => 'qbo-2', 'operation' => 'Update'],
    ];

    $remoteSettings = qbSettings([
        'customer_conflict'  => 'quickbooks',
        'customer_reference' => 'quickbooks',
    ]);

    expect(fn () => $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, $remoteSettings, time()))
        ->toThrow(QuickBooksException::class, qbRequestFailed(500));

    expect($ledger->customers['cust-1']['name'])->toBe('Local Ada')
        ->and($ledger->customers['cust-2']['name'])->toBe('Local Bea')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->toBe('qbo-2')
        ->and($ledger->attempts)->toBe([]);

    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Local Bea', 'email' => 'bea@example.test']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $ledger->links[]             = engineLink('customer', 'cust-2', 'qbo-2');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, $remoteSettings, time());

    expect($ledger->customers['cust-1']['name'])->toBe('Remote Ada')
        ->and($ledger->customers['cust-2']['name'])->toBe('Local Bea')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->toBe('qbo-2');
});

test('a paid invoice with amount paid 0 is not sent as a zero payment', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 0]);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('createPayment')
        ->and($client->paymentPayloads[0]['TotalAmt'])->toBe('10.00')
        ->and($client->paymentPayloads[0]['Line'][0]['Amount'])->toBe('10.00');
});

test('a zero quickbooks payment marks a paid invoice unpaid', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->invoices['qb-1']    = [
        'Id'        => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [['TxnId' => 'pay-zero', 'TxnType' => 'Payment']],
    ];
    $client->payments['pay-zero'] = [
        'Id'   => 'pay-zero', 'SyncToken' => '1', 'TotalAmt' => '0.00', 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => '0.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'payment_conflict' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(0)
        ->and($ledger->invoices['inv-1']['status'])->toBe('sent')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->calls)->not->toContain('createPayment');
});

test('a zero total is not marked paid', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'sent', 'total' => 0, 'amount_paid' => 0, 'items' => []]);
    $client->invoices['qb-1']    = [
        'Id'        => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 0, 'Balance' => 0,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [['TxnId' => 'pay-zero', 'TxnType' => 'Payment']],
    ];
    $client->payments['pay-zero'] = [
        'Id'   => 'pay-zero', 'SyncToken' => '1', 'TotalAmt' => '0.00', 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => '0.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'payment_conflict' => 'quickbooks',
    ]), time());

    expect($ledger->invoices['inv-1']['status'])->toBe('sent')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(0);
});

test('customers with no email and no link are looked up by display name in batches of 30', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, int> */
        public array $displayNameBatchSizes = [];

        public function batch(array $connection, array $items): array
        {
            $names = array_values(array_filter($items, static fn (array $item): bool => str_contains((string) ($item['query'] ?? ''), 'DisplayName =')));
            if ($names !== []) {
                $this->displayNameBatchSizes[] = count($names);
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client] = qbEngine($client);
    $ledger            = engineLedger();
    for ($i = 1; $i <= 31; $i++) {
        $uuid                           = 'cust-' . $i;
        $name                           = 'Customer ' . $i;
        $ledger->customers[$uuid]       = engineCustomer($uuid, ['name' => $name, 'email' => '']);
        $ledger->pending[]              = enginePending('customer', $uuid);
        $client->customers['qbo-' . $i] = ['Id' => 'qbo-' . $i, 'SyncToken' => '0', 'DisplayName' => $name];
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->displayNameBatchSizes)->toBe([30])
        ->and(array_count_values($client->calls)['findCustomerByDisplayName'] ?? 0)->toBe(1)
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-31')['qbo_id'])->toBe('qbo-31');
});

test('a failed display name lookup does not query quickbooks again per customer', function () {
    $client = new class extends FakeQuickBooks {
        public function batch(array $connection, array $items): array
        {
            $results = parent::batch($connection, $items);
            foreach ($results as $bId => $result) {
                if (str_starts_with((string) $bId, 'customer-name:') === true) {
                    $results[$bId] = [
                        'ok'     => false,
                        'body'   => [],
                        'rows'   => [],
                        'error'  => qbRequestFailed(500),
                        'status' => 500,
                        'halt'   => false,
                    ];
                }
            }

            return $results;
        }
    };
    [$engine, $client] = qbEngine($client);
    $ledger            = engineLedger();
    foreach (['ada' => 'Ada', 'bea' => 'Bea'] as $key => $name) {
        $uuid                             = 'cust-' . $key;
        $ledger->customers[$uuid]         = engineCustomer($uuid, ['name' => $name, 'email' => '']);
        $ledger->pending[]                = enginePending('customer', $uuid);
        $client->customers['qbo-' . $key] = ['Id' => 'qbo-' . $key, 'SyncToken' => '0', 'DisplayName' => $name];
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows = array_column($ledger->pending, null, 'local_uuid');

    expect($client->calls)->not->toContain('createCustomer')
        ->and($client->calls)->not->toContain('findCustomerByDisplayName')
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($rows['cust-ada']['status'])->not->toBe('done')
        ->and($rows['cust-bea']['status'])->not->toBe('done')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-ada'))->toBeNull();
});

test('block payments are taken from invoices already in memory and each payment is loaded once', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $invoiceIdQueries = [];

        /** @var array<int, string> */
        public array $paymentIdQueries = [];

        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                if (str_contains($query, 'from Invoice where Id') === true) {
                    $this->invoiceIdQueries[] = $query;
                }
                if (str_contains($query, 'from Payment where Id') === true) {
                    $this->paymentIdQueries[] = $query;
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    foreach (['partial' => 'pay-partial', 'full' => 'pay-full'] as $key => $paymentId) {
        $uuid                    = 'inv-' . $key;
        $id                      = 'qb-' . $key;
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => 'INV-' . strtoupper($key), 'status' => 'sent']);
        $client->invoices[$id]   = [
            'Id'        => $id, 'SyncToken' => '1', 'DocNumber' => 'INV-' . strtoupper($key), 'TotalAmt' => 10,
            'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
            'LinkedTxn' => [['TxnId' => $paymentId, 'TxnType' => 'Payment']],
        ];
        $client->payments[$paymentId] = [
            'Id'   => $paymentId, 'SyncToken' => '1', 'TotalAmt' => '4.00', 'TxnDate' => '2026-09-10',
            'Line' => [['Amount' => '4.00', 'LinkedTxn' => [['TxnId' => $id, 'TxnType' => 'Invoice']]]],
        ];
        $ledger->links[]   = engineLink('invoice', $uuid, $id);
        $ledger->links[]   = engineLink('payment', $uuid, $paymentId);
        $ledger->pending[] = enginePending('invoice', $uuid);
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($client->invoiceIdQueries)->toHaveCount(1)
        ->and($client->paymentIdQueries)->toHaveCount(1)
        ->and($client->calls)->not->toContain('findPaymentsForCustomers:batch')
        ->and($client->calls)->not->toContain('findPaymentForInvoice')
        ->and($client->calls)->not->toContain('getInvoice:qb-partial')
        ->and($client->calls)->not->toContain('getPayment:pay-partial')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-partial')['qbo_id'])->toBe('pay-partial')
        ->and($ledger->invoices['inv-partial']['amount_paid'])->toBe(400);
});

test('every payment on one invoice is summed and stored under its quickbooks id', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'sent']);
    $client->invoices['qb-1']    = [
        'Id'        => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [
            ['TxnId' => 'pay-a', 'TxnType' => 'Payment'],
            ['TxnId' => 'pay-b', 'TxnType' => 'Payment'],
        ],
    ];
    $client->payments['pay-a'] = [
        'Id'   => 'pay-a', 'SyncToken' => '1', 'TotalAmt' => '4.00', 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => '4.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $client->payments['pay-b'] = [
        'Id'   => 'pay-b', 'SyncToken' => '1', 'TotalAmt' => '6.00', 'TxnDate' => '2026-09-12',
        'Line' => [['Amount' => '6.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('payment', 'inv-1', 'pay-a');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('2026-09-12')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-1'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-a')['qbo_id'])->toBe('pay-a')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-b')['qbo_id'])->toBe('pay-b')
        ->and($client->calls)->not->toContain('createPayment')
        ->and($client->calls)->not->toContain('updatePayment');
});

test('a block prefetch sums every payment linked on the invoice', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $paymentIdQueries = [];

        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                if (str_contains($query, 'from Payment where Id') === true) {
                    $this->paymentIdQueries[] = $query;
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-multi']     = engineInvoice('inv-multi', ['number' => 'INV-MULTI', 'status' => 'sent']);
    $client->invoices['qb-multi']      = [
        'Id'        => 'qb-multi', 'SyncToken' => '1', 'DocNumber' => 'INV-MULTI', 'TotalAmt' => 10, 'Balance' => 0,
        'TxnDate'   => '2026-09-01', 'DueDate' => '2026-09-15',
        'LinkedTxn' => [
            ['TxnId' => 'pay-a', 'TxnType' => 'Payment'],
            ['TxnId' => 'pay-b', 'TxnType' => 'Payment'],
        ],
    ];
    $client->payments['pay-a'] = [
        'Id'   => 'pay-a', 'SyncToken' => '1', 'TotalAmt' => 4, 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => 4, 'LinkedTxn' => [['TxnId' => 'qb-multi', 'TxnType' => 'Invoice']]]],
    ];
    $client->payments['pay-b'] = [
        'Id'   => 'pay-b', 'SyncToken' => '1', 'TotalAmt' => 6, 'TxnDate' => '2026-09-11',
        'Line' => [['Amount' => 6, 'LinkedTxn' => [['TxnId' => 'qb-multi', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]               = engineLink('invoice', 'inv-multi', 'qb-multi');
    $ledger->links[]               = engineLink('payment', 'inv-multi', 'pay-a');
    $ledger->pending[]             = enginePending('invoice', 'inv-multi');
    $ledger->invoices['inv-other'] = engineInvoice('inv-other', ['number' => 'INV-OTHER', 'status' => 'sent']);
    $client->invoices['qb-other']  = [
        'Id'      => 'qb-other', 'SyncToken' => '1', 'DocNumber' => 'INV-OTHER', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-other', 'qb-other');
    $ledger->pending[] = enginePending('invoice', 'inv-other');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($client->paymentIdQueries)->toHaveCount(1)
        ->and($client->paymentIdQueries[0])->toContain("'pay-a'")
        ->and($client->paymentIdQueries[0])->toContain("'pay-b'")
        ->and($client->calls)->not->toContain('getPayment:pay-a')
        ->and($client->calls)->not->toContain('getPayment:pay-b')
        ->and($client->calls)->not->toContain('createPayment')
        ->and($ledger->invoices['inv-multi']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-multi']['status'])->toBe('paid')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-multi'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-a')['qbo_id'])->toBe('pay-a')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-b')['qbo_id'])->toBe('pay-b');
});

test('a doc number match links only when the quickbooks customer is the same', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-same']      = engineInvoice('inv-same', ['number' => 'INV-SAME']);
    $ledger->invoices['inv-other']     = engineInvoice('inv-other', ['number' => 'INV-OTHER']);
    $client->invoices['qb-same']       = [
        'Id'      => 'qb-same', 'SyncToken' => '1', 'DocNumber' => 'INV-SAME', 'TotalAmt' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'CustomerRef' => ['value' => 'qbo-customer'],
    ];
    $client->invoices['qb-other'] = [
        'Id'      => 'qb-other', 'SyncToken' => '1', 'DocNumber' => 'INV-OTHER', 'TotalAmt' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'CustomerRef' => ['value' => 'someone-else'],
    ];
    $ledger->pending[] = enginePending('invoice', 'inv-same');
    $ledger->pending[] = enginePending('invoice', 'inv-other');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-same')['qbo_id'])->toBe('qb-same')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-other')['qbo_id'])->not->toBe('qb-other')
        ->and($client->calls)->toContain('createInvoice');
});

test('a void cancelled or deleted invoice is not marked paid when a payment covers it', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $cases                       = [
        'inv-void'      => ['status' => 'void'],
        'inv-cancelled' => ['status' => 'cancelled'],
        'inv-deleted'   => ['status' => 'sent', 'deleted_at' => '2026-09-20 00:00:00'],
    ];
    $entities = [];
    foreach ($cases as $uuid => $overrides) {
        $paymentId                    = 'pay-' . $uuid;
        $invoiceId                    = 'qb-' . $uuid;
        $ledger->invoices[$uuid]      = engineInvoice($uuid, $overrides);
        $ledger->links[]              = engineLink('invoice', $uuid, $invoiceId);
        $ledger->links[]              = engineLink('payment', $uuid, $paymentId);
        $client->payments[$paymentId] = [
            'Id'   => $paymentId, 'SyncToken' => '1', 'TotalAmt' => 10, 'TxnDate' => '2026-09-12',
            'Line' => [['Amount' => 10, 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]]],
        ];
        $entities[] = ['entity' => 'Payment', 'id' => $paymentId, 'operation' => 'Create'];
    }

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, qbSettings([
        'payment_conflict'  => 'quickbooks',
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($ledger->invoices['inv-void']['status'])->toBe('void')
        ->and($ledger->invoices['inv-void']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-cancelled']['status'])->toBe('cancelled')
        ->and($ledger->invoices['inv-cancelled']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-deleted']['status'])->toBe('sent')
        ->and($ledger->invoices['inv-deleted']['amount_paid'])->toBe(1000);
});

test('outbound customer compare ignores email case and phone punctuation', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['email' => ' Ada@Example.test ', 'phone' => '(555) 010-1234']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'PrimaryPhone'     => ['FreeFormNumber' => '555-010-1234'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateCustomer');

    $ledger->customers['cust-1']['phone']                 = '5550109999';
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time() + 10);

    expect($second['updated'])->toBe(1)
        ->and($client->calls)->toContain('updateCustomer');
});

test('a cached customer miss is not looked up again', function () {
    [$engine, $client]              = qbEngine();
    $ledger                         = engineLedger();
    $ledger->customers['cust-1']    = engineCustomer('cust-1');
    $ledger->customers['cust-2']    = engineCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->links[]                = engineLink('customer', 'cust-1', 'qbo-missing-1');
    $ledger->links[]                = engineLink('customer', 'cust-2', 'qbo-missing-2');
    $client->customers['qbo-other'] = [
        'Id'               => 'qbo-other', 'SyncToken' => '0', 'DisplayName' => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');
    $ledger->pending[] = enginePending('customer', 'cust-2');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('getCustomer:qbo-missing-1')
        ->and($client->calls)->not->toContain('getCustomer:qbo-missing-2')
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($client->calls)->not->toContain('findCustomerByDisplayName')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->not->toBe('qbo-other');

    [$engine, $client]              = qbEngine();
    $ledger                         = engineLedger();
    $ledger->customers['cust-1']    = engineCustomer('cust-1');
    $ledger->links[]                = engineLink('customer', 'cust-1', 'qbo-missing');
    $client->customers['qbo-other'] = [
        'Id'               => 'qbo-other', 'SyncToken' => '0', 'DisplayName' => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];
    $ledger->pending[] = enginePending('customer', 'ghost');
    $ledger->pending[] = enginePending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect(array_count_values($client->calls)['getCustomer:qbo-missing'] ?? 0)->toBe(1)
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($client->calls)->not->toContain('findCustomerByDisplayName');
});

test('a linked customer or wallet deleted in quickbooks is left unchanged', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-gone');
    $ledger->pending[]           = enginePending('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($batch['created'])->toBe(0)
        ->and($client->calls)->not->toContain('createCustomer')
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($ledger->customers['cust-1']['name'])->toBe('Local Ada')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-gone');

    [$engine, $client]        = qbEngine();
    $ledger                   = engineLedger();
    $ledger->wallets['wal-1'] = engineWallet('wal-1', ['name' => 'Operating']);
    $ledger->links[]          = engineLink('wallet', 'wal-1', 'acct-gone');
    $ledger->pending[]        = enginePending('wallet', 'wal-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('createAccount')
        ->and($ledger->wallets['wal-1']['name'])->toBe('Operating')
        ->and($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-1')['qbo_id'])->toBe('acct-gone');
});

test('a linked customer deleted in fleetbase is made inactive in quickbooks', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->pending[]                 = enginePending('customer', 'cust-1');
    $client->customers['qbo-customer'] = [
        'Id'          => 'qbo-customer',
        'SyncToken'   => '3',
        'DisplayName' => 'Ada',
        'Active'      => true,
    ];

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($client->calls)->toContain('updateCustomer')
        ->and($client->calls)->not->toContain('createCustomer')
        ->and($client->customers['qbo-customer']['Active'])->toBeFalse()
        ->and($client->customers['qbo-customer'])->not->toHaveKey('DisplayName');
});

test('a deleted fleetbase customer or wallet is never created and an inactive one is left alone', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-new']     = engineCustomer('cust-new', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->customers['cust-1']       = engineCustomer('cust-1', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = [
        'Id'          => 'qbo-customer',
        'SyncToken'   => '3',
        'DisplayName' => 'Ada',
        'Active'      => false,
    ];
    $ledger->wallets['wal-new']        = engineWallet('wal-new', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->pending[]                 = enginePending('customer', 'cust-new');
    $ledger->pending[]                 = enginePending('customer', 'cust-1');
    $ledger->pending[]                 = enginePending('wallet', 'wal-new');

    $settings = qbSettings(['interval_minutes' => 1, 'wallet_enabled' => true]);
    $batch    = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['aligned'])->toBe(3)
        ->and($batch['failed'])->toBe(0)
        ->and($client->calls)->not->toContain('createCustomer')
        ->and($client->calls)->not->toContain('updateCustomer')
        ->and($client->calls)->not->toContain('findCustomerByEmail')
        ->and($client->calls)->not->toContain('createAccount')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-new'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-new'))->toBeNull();
});

test('a deleted fleetbase customer is not sent when customers only come from quickbooks', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->pending[]                 = enginePending('customer', 'cust-1');
    $client->customers['qbo-customer'] = [
        'Id'          => 'qbo-customer',
        'SyncToken'   => '3',
        'DisplayName' => 'Ada',
        'Active'      => true,
    ];

    $settings = qbSettings(['interval_minutes' => 1, 'customer_direction' => 'inbound']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['Active'])->toBeTrue();
});

test('a linked wallet deleted in fleetbase makes its quickbooks account inactive', function () {
    [$engine, $client]             = qbEngine();
    $ledger                        = engineLedger();
    $ledger->wallets['wal-1']      = engineWallet('wal-1', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->links[]               = engineLink('wallet', 'wal-1', 'acct-1');
    $ledger->pending[]             = enginePending('wallet', 'wal-1');
    $client->accounts['acct-1']    = [
        'Id'          => 'acct-1',
        'SyncToken'   => '2',
        'Name'        => 'Operating',
        'AcctNum'     => 'wallet_wal-1',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'USD'],
    ];

    $settings = qbSettings(['interval_minutes' => 1, 'wallet_enabled' => true]);
    $batch    = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(1)
        ->and($client->calls)->toContain('updateAccount')
        ->and($client->accounts['acct-1']['Active'])->toBeFalse()
        ->and($client->accounts['acct-1']['Name'])->toBe('Operating');
});

test('a voided cancelled deleted or removed invoice is not pushed', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $cases                             = [
        'inv-void'      => ['status' => 'void'],
        'inv-cancelled' => ['status' => 'cancelled'],
        'inv-deleted'   => ['status' => 'deleted'],
        'inv-removed'   => ['status' => 'sent', 'deleted_at' => '2026-09-20 00:00:00'],
    ];
    foreach ($cases as $uuid => $overrides) {
        $ledger->invoices[$uuid] = engineInvoice($uuid, $overrides + ['number' => strtoupper($uuid)]);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }
    $ledger->invoices['inv-live-deleted'] = engineInvoice('inv-live-deleted', ['number' => 'INV-LIVE-DELETED', 'status' => 'deleted']);
    $client->invoices['qb-live']          = ['Id' => 'qb-live', 'SyncToken' => '1', 'DocNumber' => 'INV-LIVE-DELETED', 'TotalAmt' => 10];
    $ledger->links[]                      = engineLink('invoice', 'inv-live-deleted', 'qb-live');
    $ledger->pending[]                    = enginePending('invoice', 'inv-live-deleted');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(0)
        ->and($batch['updated'])->toBe(0)
        ->and($batch['voided'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($client->calls)->toContain('voidInvoice')
        ->and($ledger->invoices['inv-deleted']['status'])->toBe('deleted')
        ->and($ledger->invoices['inv-removed']['deleted_at'])->toBe('2026-09-20 00:00:00')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-deleted'))->toBeNull();
});

test('a payment stored under its quickbooks id is not replaced when the invoice no longer lists it', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $paymentIdQueries = [];

        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                if (str_contains($query, 'from Payment where Id') === true) {
                    $this->paymentIdQueries[] = $query;
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client]            = qbEngine($client);
    $ledger                       = engineLedger();
    $ledger->customers['cust-1']  = engineCustomer('cust-1');
    $ledger->links[]              = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']    = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000]);
    $ledger->invoices['inv-open'] = engineInvoice('inv-open', ['number' => 'INV-OPEN', 'status' => 'sent']);
    $client->invoices['qb-1']     = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $client->invoices['qb-open'] = [
        'Id'      => 'qb-open', 'SyncToken' => '1', 'DocNumber' => 'INV-OPEN', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $client->payments['pay-kept'] = [
        'Id'   => 'pay-kept', 'SyncToken' => '1', 'TotalAmt' => '10.00', 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => '10.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('invoice', 'inv-open', 'qb-open');
    $ledger->links[]   = engineLink('payment', 'pay-kept', 'pay-kept');
    $ledger->links[]   = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice',
        'local_uuid'   => 'pay-kept', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-1', 'sync_token' => '0',
    ];
    $ledger->pending[] = enginePending('invoice', 'inv-1');
    $ledger->pending[] = enginePending('invoice', 'inv-open');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    $sawKept = in_array('getPayment:pay-kept', $client->calls, true) === true
        || str_contains(implode("\n", $client->paymentIdQueries), 'pay-kept') === true;

    expect($client->calls)->not->toContain('createPayment')
        ->and($sawKept)->toBeTrue()
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-kept')['qbo_id'])->toBe('pay-kept');

    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('payment', 'pay-gone', 'pay-gone');
    $ledger->links[]   = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice',
        'local_uuid'   => 'pay-gone', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-1', 'sync_token' => '0',
    ];
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('createPayment')
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-gone')['qbo_id'])->toBe('pay-gone');
});

test('a linked payment that quickbooks no longer has is not replaced', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->invoices['qb-1']    = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => 10, 'Balance' => 10,
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15',
    ];
    $ledger->links[]   = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]   = engineLink('payment', 'inv-1', 'pay-gone');
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('createPayment')
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-1')['qbo_id'])->toBe('pay-gone');
});

test('remote id reads stay within the batch cap and keep paging', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $idQueries = [];

        public function batch(array $connection, array $items): array
        {
            $results = parent::batch($connection, $items);
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                $bId   = (string) ($item['bId'] ?? '');
                if (isset($results[$bId]) === false || str_contains($query, 'where Id IN') === false) {
                    continue;
                }
                $this->idQueries[] = $query;
                $rows              = is_array($results[$bId]['rows'] ?? null) === true ? array_values($results[$bId]['rows']) : [];
                $start             = 1;
                $max               = 100;
                if (preg_match('/startposition\s+(\d+)/i', $query, $match) === 1) {
                    $start = (int) $match[1];
                }
                if (preg_match('/maxresults\s+(\d+)/i', $query, $match) === 1) {
                    $max = (int) $match[1];
                }
                if (str_contains($query, 'startposition') === false) {
                    $max = min($max, 100);
                }
                $results[$bId]['rows'] = array_slice($rows, max(0, $start - 1), $max);
            }

            return $results;
        }
    };
    [$engine, $client] = qbEngine($client);
    $ledger            = engineLedger();
    $entities          = [];
    for ($i = 1; $i <= 101; $i++) {
        $uuid                        = 'cust-' . $i;
        $id                          = 'qbo-' . $i;
        $ledger->customers[$uuid]    = engineCustomer($uuid, ['name' => 'Local ' . $i, 'email' => 'c' . $i . '@example.test']);
        $client->customers[$id]      = ['Id' => $id, 'SyncToken' => '1', 'DisplayName' => 'Remote ' . $i, 'PrimaryEmailAddr' => ['Address' => 'c' . $i . '@example.test']];
        $ledger->links[]             = engineLink('customer', $uuid, $id);
        $entities[]                  = ['entity' => 'Customer', 'id' => $id, 'operation' => 'Update'];
    }

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, qbSettings([
        'customer_conflict'  => 'quickbooks',
        'customer_reference' => 'quickbooks',
    ]), time());

    expect($ledger->customers['cust-1']['name'])->toBe('Remote 1')
        ->and($ledger->customers['cust-101']['name'])->toBe('Remote 101')
        ->and($client->calls)->not->toContain('getCustomer:qbo-101')
        ->and($client->idQueries)->not->toBeEmpty();
    foreach ($client->idQueries as $query) {
        preg_match_all("/'[^']*'/", $query, $ids);
        expect($query)->toContain('startposition')
            ->and($query)->toContain('maxresults')
            ->and(count($ids[0]))->toBeLessThanOrEqual(30);
    }

    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Local Ada']);
    $client->customers['qbo-1']  = ['Id' => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], qbSettings([
        'customer_conflict'  => 'quickbooks',
        'customer_reference' => 'quickbooks',
    ]), time());

    expect($ledger->customers['cust-1']['name'])->toBe('Remote Ada')
        ->and($client->calls)->toContain('getCustomer:qbo-1')
        ->and($client->calls)->not->toContain('batch');
});

test('payments touching an invoice are not read in one unbounded query', function () {
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $paymentQueries = [];

        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                $query = (string) ($item['query'] ?? '');
                if (str_contains($query, 'from Payment where Id IN') === true) {
                    $this->paymentQueries[] = $query;
                }
            }

            return parent::batch($connection, $items);
        }
    };
    [$engine, $client]           = qbEngine($client);
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $client->payments['pay-0']   = [
        'Id'   => 'pay-0', 'SyncToken' => '1', 'TotalAmt' => 1, 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => 1, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[] = engineLink('payment', 'inv-1', 'pay-0');
    for ($i = 1; $i <= 31; $i++) {
        $id                      = 'pay-' . $i;
        $client->payments[$id]   = [
            'Id'   => $id, 'SyncToken' => '1', 'TotalAmt' => 1, 'TxnDate' => '2026-09-10',
            'Line' => [['Amount' => 1, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
        ];
        $ledger->links[] = engineLink('payment', $id, $id);
    }

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-0', 'operation' => 'Update'],
    ], qbSettings([
        'payment_conflict'  => 'quickbooks',
        'payment_reference' => 'quickbooks',
    ]), time());

    expect($client->paymentQueries)->not->toBeEmpty()
        ->and($client->calls)->not->toContain('createPayment');
    foreach ($client->paymentQueries as $query) {
        preg_match_all("/'[^']*'/", $query, $ids);
        expect($query)->toContain('startposition')
            ->and($query)->toContain('maxresults')
            ->and(count($ids[0]))->toBeLessThanOrEqual(30);
    }
});

test('an invoice line update sends the existing sales line id', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [['description' => 'Evening delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ], [
        'Line' => [[
            'Id'                  => '9',
            'Amount'              => '10.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00', 'ItemRef' => ['value' => 'item-1']],
        ]],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['Line'])->toHaveCount(1)
        ->and($client->invoices['qb-1']['Line'][0]['Id'])->toBe('9')
        ->and($client->invoices['qb-1']['Line'][0]['Description'])->toBe('Evening delivery');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('extra quickbooks sales lines stay and one update aligns the invoice', function () {
    $warehouse = [
        'Id'                  => '10',
        'Amount'              => '4.00',
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => 'Warehouse fee',
        'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '4.00', 'ItemRef' => ['value' => 'item-1']],
    ];
    $subtotal = [
        'Amount'              => '14.00',
        'DetailType'          => 'SubTotalLineDetail',
        'Description'         => 'Subtotal',
        'SubTotalLineDetail'  => [],
    ];
    // An empty detail goes back to QuickBooks as {} (QuickBooks refuses []).
    $subtotalSent = array_merge($subtotal, ['SubTotalLineDetail' => new stdClass()]);
    $discount     = [
        'Amount'             => '0.00',
        'DetailType'         => 'DiscountLineDetail',
        'Description'        => 'Discount',
        'DiscountLineDetail' => ['PercentBased' => false],
    ];
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [['description' => 'Evening delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ], [
        'TotalAmt' => '14.00',
        'Line'     => [[
            'Id'                  => '9',
            'Amount'              => '10.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00', 'ItemRef' => ['value' => 'item-1']],
        ], $warehouse, $subtotal, $discount],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($first['aligned'])->toBe(0)
        ->and($client->invoices['qb-1']['Line'])->toHaveCount(4)
        ->and($client->invoices['qb-1']['Line'][0]['Id'])->toBe('9')
        ->and($client->invoices['qb-1']['Line'][0]['Description'])->toBe('Evening delivery')
        ->and($client->invoices['qb-1']['Line'][1])->toBe($warehouse)
        ->and($client->invoices['qb-1']['Line'][2])->toEqual($subtotalSent)
        ->and($client->invoices['qb-1']['Line'][3])->toBe($discount)
        ->and($ledger->invoices['inv-1']['items'][0]['qbo_line_id'])->toBe('9');

    unset($ledger->invoices['inv-1']['items'][0]['qbo_line_id']);
    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1)
        ->and($client->invoices['qb-1']['Line'][1])->toBe($warehouse)
        ->and($client->invoices['qb-1']['Line'][2])->toEqual($subtotalSent)
        ->and($client->invoices['qb-1']['Line'][3])->toBe($discount);
});

test('a quickbooks discount kept on the invoice converges in one sync', function () {
    $discount = [
        'Id'                 => '20',
        'Amount'             => '1.50',
        'DetailType'         => 'DiscountLineDetail',
        'Description'        => 'Loyalty discount',
        'DiscountLineDetail' => ['PercentBased' => false],
    ];
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [['description' => 'Evening delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ], [
        'TotalAmt' => '8.50',
        'Line'     => [InvoiceLines::sales('9', 'Delivery', '10.00'), $discount],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['TotalAmt'])->toBe('8.50')
        ->and($client->invoices['qb-1']['Line'][1])->toBe($discount);

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('an invoice that differs from quickbooks only by a discount is aligned', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([], [
        'TotalAmt' => '9.00',
        'Line'     => [InvoiceLines::sales('9', 'Delivery', '10.00'), [
            'Id'                 => '20',
            'Amount'             => '1.00',
            'DetailType'         => 'DiscountLineDetail',
            'DiscountLineDetail' => ['PercentBased' => true, 'DiscountPercent' => 10],
        ]],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('sales tax quickbooks adds on top of the fleetbase lines does not update the invoice again', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [['description' => 'Evening delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ], [
        'TotalAmt'     => '10.80',
        'Line'         => [InvoiceLines::sales('9', 'Delivery', '10.00')],
        'TxnTaxDetail' => ['TotalTax' => '0.80'],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['TotalAmt'])->toBe('10.80');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a discounted invoice paid in full in quickbooks becomes paid', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([], [
        'TotalAmt'  => '9.00',
        'Balance'   => '0.00',
        'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
        'Line'      => [InvoiceLines::sales('9', 'Delivery', '10.00'), [
            'Id'                 => '20',
            'Amount'             => '1.00',
            'DetailType'         => 'DiscountLineDetail',
            'DiscountLineDetail' => ['PercentBased' => false],
        ]],
    ]);
    $client->payments['pay-1'] = [
        'Id'   => 'pay-1', 'SyncToken' => '0', 'TotalAmt' => '9.00', 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => '9.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $settings = qbSettings(['interval_minutes' => 1, 'payment_reference' => 'quickbooks']);

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(900)
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($client->calls)->not->toContain('updatePayment');
});

test('a discounted invoice left part-paid becomes paid once quickbooks shows it settled', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(
        ['status' => 'partial', 'amount_paid' => 900, 'paid_at' => '2026-09-10'],
        [
            'TotalAmt'  => '9.00',
            'Balance'   => '0.00',
            'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
            'Line'      => [InvoiceLines::sales('9', 'Delivery', '10.00'), [
                'Id'                 => '20',
                'Amount'             => '1.00',
                'DetailType'         => 'DiscountLineDetail',
                'DiscountLineDetail' => ['PercentBased' => false],
            ]],
        ]
    );
    $client->payments['pay-1'] = [
        'Id'   => 'pay-1', 'SyncToken' => '0', 'TotalAmt' => '9.00', 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => '9.00', 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $settings = qbSettings(['interval_minutes' => 1, 'payment_reference' => 'quickbooks']);

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($client->calls)->not->toContain('updatePayment');
});

test('a line deleted in fleetbase is removed from the quickbooks invoice and a quickbooks line stays', function () {
    $warehouse                  = InvoiceLines::sales('3', 'Warehouse fee', '4.00');
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items'         => [InvoiceLines::tracked('item-1', 'Delivery', 1000, '1')],
        'removed_lines' => [InvoiceLines::removed('item-2', 'Fuel', 500, '2')],
    ], [
        'TotalAmt' => '19.00',
        'Line'     => [
            InvoiceLines::sales('1', 'Delivery', '10.00'),
            InvoiceLines::sales('2', 'Fuel', '5.00'),
            $warehouse,
        ],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1', '3'])
        ->and($client->invoices['qb-1']['Line'][1])->toBe($warehouse)
        ->and($ledger->invoices['inv-1']['line_map'])->toBe(['realm' => 'realm-1', 'invoice' => 'qb-1'])
        ->and($ledger->invoices['inv-1']['items'][0]['qbo_line_id'])->toBe('1')
        ->and($ledger->invoices['inv-1']['cleared_lines'])->toBe(['item-2']);

    unset(
        $ledger->invoices['inv-1']['removed_lines'],
        $ledger->invoices['inv-1']['line_map'],
        $ledger->invoices['inv-1']['cleared_lines']
    );
    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'] ?? 0)->toBe(1);
});

test('a removed line stored for another quickbooks company or invoice stays', function (
    string $realm,
    string $invoice,
) {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items'         => [InvoiceLines::tracked('item-1', 'Delivery', 1000, '1')],
        'removed_lines' => [InvoiceLines::removed('item-2', 'Fuel', 500, '2', $realm, $invoice)],
    ], [
        'TotalAmt' => '15.00',
        'Line'     => [InvoiceLines::sales('1', 'Delivery', '10.00'), InvoiceLines::sales('2', 'Fuel', '5.00')],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1', '2'])
        ->and($ledger->invoices['inv-1']['cleared_lines'] ?? [])->toBe([]);
})->with([['realm-2', 'qb-1'], ['realm-1', 'qb-9']]);

test('a removed line id that a fleetbase line still holds is not removed', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items'         => [InvoiceLines::tracked('item-new', 'Delivery', 1000, '1')],
        'removed_lines' => [InvoiceLines::removed('item-old', 'Delivery', 1000, '1')],
    ], [
        'TotalAmt' => '10.00',
        'Line'     => [InvoiceLines::sales('1', 'Delivery', '10.00')],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1']);
});

test('an aligned invoice without stored line ids records which quickbooks line each item is', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [
            InvoiceLines::item('item-1', 'Delivery', 1000),
            InvoiceLines::item('item-2', 'Fuel', 500),
        ],
        'total' => 1500,
    ], [
        'TotalAmt' => '19.00',
        'Line'     => [
            InvoiceLines::sales('1', 'Delivery', '10.00'),
            InvoiceLines::sales('2', 'Fuel', '5.00'),
            InvoiceLines::sales('3', 'Warehouse fee', '4.00'),
        ],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['line_map'])->toBe(['realm' => 'realm-1', 'invoice' => 'qb-1'])
        ->and(array_column($ledger->invoices['inv-1']['items'], 'qbo_line_id'))->toBe(['1', '2']);
});

test('a removed line that was edited in quickbooks stays', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items'         => [InvoiceLines::tracked('item-1', 'Delivery', 1000, '1')],
        'removed_lines' => [InvoiceLines::removed('item-2', 'Fuel', 500, '2')],
    ], [
        'TotalAmt' => '16.00',
        'Line'     => [
            InvoiceLines::sales('1', 'Delivery', '10.00'),
            InvoiceLines::sales('2', 'Fuel surcharge', '6.00'),
        ],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1', '2']);
});

test('a new fleetbase line on an invoice with stored ids does not replace a line added in quickbooks', function () {
    $warehouse                  = InvoiceLines::sales('3', 'Warehouse fee', '4.00');
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [
            InvoiceLines::tracked('item-1', 'Delivery', 1000, '1'),
            InvoiceLines::item('item-3', 'Tolls', 300),
        ],
        'total' => 1300,
    ], [
        'TotalAmt' => '14.00',
        'Line'     => [InvoiceLines::sales('1', 'Delivery', '10.00'), $warehouse],
    ]);
    $client->assignLineIds = true;

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1', '4', '3'])
        ->and($client->invoices['qb-1']['Line'][2])->toBe($warehouse)
        ->and(array_column($ledger->invoices['inv-1']['items'], 'qbo_line_id'))->toBe(['1', '4']);
});

test('an invoice without stored ids pairs lines by content, so a line deleted earlier is not duplicated', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [
            InvoiceLines::item('item-1', 'Delivery', 1000),
            InvoiceLines::item('item-3', 'Tolls', 300),
        ],
        'total' => 1300,
    ], [
        'TotalAmt' => '18.00',
        'Line'     => [
            InvoiceLines::sales('1', 'Delivery', '10.00'),
            InvoiceLines::sales('2', 'Fuel', '5.00'),
            InvoiceLines::sales('3', 'Tolls', '3.00'),
        ],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and(array_column($ledger->invoices['inv-1']['items'], 'qbo_line_id'))->toBe(['1', '3']);
});

test('a new fleetbase line takes the quickbooks id, and nothing is recorded when the update fails', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items' => [
            InvoiceLines::tracked('item-1', 'Delivery', 1000, '1'),
            InvoiceLines::item('item-3', 'Tolls', 300),
        ],
        'total' => 1300,
    ], [
        'TotalAmt' => '10.00',
        'Line'     => [InvoiceLines::sales('1', 'Delivery', '10.00')],
    ]);
    $client->assignLineIds = true;

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toBe(['Delivery', 'Tolls'])
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1', '2'])
        ->and(array_column($ledger->invoices['inv-1']['items'], 'qbo_line_id'))->toBe(['1', '2']);

    [$engine, $client, $ledger] = engineLinkedInvoice([
        'items'         => [InvoiceLines::tracked('item-1', 'Delivery', 1000, '1')],
        'removed_lines' => [InvoiceLines::removed('item-2', 'Fuel', 500, '2')],
    ], [
        'TotalAmt' => '15.00',
        'Line'     => [InvoiceLines::sales('1', 'Delivery', '10.00'), InvoiceLines::sales('2', 'Fuel', '5.00')],
    ]);
    $client->failInvoiceUpdates = true;

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->invoices['inv-1'])->not->toHaveKey('line_map')
        ->and($ledger->invoices['inv-1'])->not->toHaveKey('cleared_lines');
});

test('after a quickbooks payment delete the amount paid comes from the invoice balance', function (
    string $balance,
    int $paid,
    string $status,
) {
    $paidInvoice                = ['total' => 5000, 'amount_paid' => 2500, 'status' => 'sent'];
    [$engine, $client, $ledger] = engineLinkedInvoice($paidInvoice, [
        'TotalAmt' => '50.00',
        'Balance'  => $balance,
        'Line'     => [InvoiceLines::sales('1', 'Changed in QuickBooks', '50.00')],
    ]);

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'InvoicePayments', 'id' => 'qb-1', 'operation' => 'update'],
    ], qbSettings(['payment_conflict' => 'quickbooks']), time());

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe($paid)
        ->and($ledger->invoices['inv-1']['status'])->toBe($status)
        ->and($ledger->invoices['inv-1']['payment_from_quickbooks'])->toBeTrue()
        ->and($ledger->invoices['inv-1']['items'][0]['description'])->toBe('Delivery')
        ->and($ledger->invoices['inv-1'])->not->toHaveKey('replace_from_quickbooks');
})->with([
    'no payment left'  => ['50.00', 0, 'sent'],
    'one payment left' => ['30.00', 2000, 'partial'],
]);

test('a payment amount read is not applied when quickbooks does not supply payments or the invoice is void', function (
    array $overrides,
    array $invoice,
) {
    $paidInvoice                = array_merge(['total' => 5000, 'amount_paid' => 2500, 'status' => 'sent'], $invoice);
    [$engine, $client, $ledger] = engineLinkedInvoice($paidInvoice, [
        'TotalAmt' => '50.00',
        'Balance'  => '50.00',
    ]);

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'InvoicePayments', 'id' => 'qb-1', 'operation' => 'update'],
    ], qbSettings($overrides), time());

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(2500);
})->with([
    'fleetbase wins' => [[], []],
    'payments off'   => [['payment_conflict' => 'quickbooks', 'payment_enabled' => false], []],
    'void invoice'   => [['payment_conflict' => 'quickbooks'], ['status' => 'void']],
]);

test('a quickbooks primary sync clears an amount paid that no quickbooks payment backs', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['amount_paid' => 600, 'status' => 'sent'], [
        'TotalAmt' => '10.00',
        'Balance'  => '10.00',
    ]);

    $settings = qbSettings(['interval_minutes' => 1, 'payment_conflict' => 'quickbooks']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(0)
        ->and($ledger->invoices['inv-1']['status'])->toBe('sent')
        ->and($client->calls)->not->toContain('createPayment');
});

test('a fleetbase primary webhook does not copy quickbooks onto fleetbase', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Local Ada', 'notes' => 'Keep']);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada', 'Notes' => 'Remote note',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], qbSettings(), time());

    expect($ledger->customers['cust-1']['name'])->toBe('Local Ada')
        ->and($ledger->customers['cust-1']['notes'])->toBe('Keep')
        ->and($client->calls)->toBe([]);

    $client->customers['qbo-1']['Notes'] = '';
    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], qbSettings(['customer_direction' => 'inbound']), time());

    expect($ledger->customers['cust-1']['name'])->toBe('Remote Ada')
        ->and($ledger->customers['cust-1']['notes'])->toBe('Keep');
});

test('quickbooks primary saves the invoice customer and home currency', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['currency' => 'EUR'], [
        'CustomerRef' => ['value' => 'qbo-bea'],
        'CurrencyRef' => ['value' => 'USD'],
    ]);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->links[]             = engineLink('customer', 'cust-2', 'qbo-bea');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'invoice_conflict'   => 'quickbooks',
        'invoice_reference'  => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2')
        ->and($ledger->invoices['inv-1']['currency'])->toBe('USD')
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a non-home currency fails the push instead of updating the invoice in the home currency', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['currency' => 'EUR', 'notes' => 'Dock'], [
        'CurrencyRef' => ['value' => 'USD'],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(0)
        ->and($first['failed'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toBe('Invoice currency EUR does not match the QuickBooks home '
            . 'currency '
            . 'USD.')
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($client->invoices['qb-1'])->not->toHaveKey('PrivateNote')
        ->and($ledger->invoices['inv-1']['currency'])->toBe('EUR');
});

test('quickbooks primary does not copy a non-home invoice currency', function () {
    [$engine, $client, $ledger] = engineLinkedInvoice(['notes' => '', 'currency' => 'USD'], [
        'PrivateNote' => 'Dock',
        'CurrencyRef' => ['value' => 'EUR'],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'invoice_conflict' => 'quickbooks',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($batch['failed'])->toBe(0)
        ->and($ledger->invoices['inv-1']['notes'])->toBe('Dock')
        ->and($ledger->invoices['inv-1']['currency'])->toBe('USD');
});

test('clearing street2 email or phone is pushed once and then stays aligned', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', [
        'email'   => '',
        'phone'   => '',
        'address' => [
            'line1' => '1 Analytical Engine', 'line2' => '', 'city' => 'London',
            'state' => 'LN', 'postal_code' => 'SW1', 'country' => 'UK',
        ],
    ]);
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'PrimaryPhone'     => ['FreeFormNumber' => '555'],
        'BillAddr'         => [
            'Id'   => 'addr-1', 'Line1' => '1 Analytical Engine', 'Line2' => 'Suite 2',
            'City' => 'London', 'CountrySubDivisionCode' => 'LN', 'PostalCode' => 'SW1', 'Country' => 'UK',
        ],
    ];
    $ledger->pending[] = enginePending('customer', 'cust-1');
    $settings          = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($client->customers['qbo-1']['PrimaryEmailAddr']['Address'])->toBe('')
        ->and($client->customers['qbo-1']['PrimaryPhone']['FreeFormNumber'])->toBe('')
        ->and($client->customers['qbo-1']['BillAddr']['Line2'])->toBe('')
        ->and($client->customers['qbo-1']['BillAddr']['Line1'])->toBe('1 Analytical Engine')
        ->and($client->customers['qbo-1']['BillAddr']['Id'])->toBe('addr-1');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateCustomer'] ?? 0)->toBe(1);
});

test('outbound quickbooks primary sends a cleared wallet description once', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = engineLedger();
    $ledger->wallets['wal-1']   = engineWallet('wal-1', ['description' => '']);
    $ledger->links[]            = engineLink('wallet', 'wal-1', 'acct-9');
    $client->accounts['acct-9'] = [
        'Id'     => 'acct-9', 'SyncToken' => '1', 'Name' => 'Operating', 'Description' => 'Float',
        'Active' => true, 'CurrencyRef' => ['value' => 'USD'],
    ];
    $ledger->pending[] = enginePending('wallet', 'wal-1');
    $settings          = qbSettings([
        'interval_minutes'  => 1,
        'wallet_direction'  => 'outbound',
        'wallet_conflict'   => 'quickbooks',
        'wallet_reference'  => 'quickbooks',
    ]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($ledger->wallets['wal-1']['description'])->toBe('')
        ->and($client->accounts['acct-9']['Description'])->toBe('');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and(array_count_values($client->calls)['updateAccount'] ?? 0)->toBe(1);
});

test('a customer name with a colon or a line break is sent in a form quickbooks accepts'
. ' and the next sync is aligned', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => "Acme: North\tDepot\nEast"]);
    $ledger->pending[]           = enginePending('customer', 'cust-1');
    $settings                    = qbSettings(['interval_minutes' => 1]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled(
        $ledger,
        'company-uuid',
        $settings,
        time() + 10
    );

    expect($first['created'])->toBe(1)
        ->and($client->customers['1']['DisplayName'])->toBe('Acme- North Depot East')
        ->and($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['name'])->toBe("Acme: North\tDepot\nEast");

    // The webhook echo of that create, with QuickBooks primary, does not rewrite the Fleetbase name.
    $inbound = qbSettings(['interval_minutes' => 1, 'customer_conflict' => 'quickbooks']);
    $engine->acceptRemoteChanges(
        $ledger,
        'company-uuid',
        [['entity' => 'Customer', 'id' => '1', 'operation' => 'Update']],
        $inbound,
        time()
    );

    expect($ledger->customers['cust-1']['name'])->toBe("Acme: North\tDepot\nEast");
});

foreach ([1 => 'on its own', 2 => 'in a batch'] as $nameTakenCount => $nameTakenLabel) {
    test('a customer name quickbooks already uses for another record is reported by name '
    . $nameTakenLabel, function () use ($nameTakenCount) {
        [$engine, $client]           = qbEngine();
        $client->failOnCreate        = 1;
        $duplicate                   = qbRequestFailed(400, 'Duplicate Name Exists Error');
        $client->failWith            = new QuickBooksException(400, $duplicate, null, '6240');
        $ledger                      = engineLedger();
        $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Acme: North']);
        $ledger->pending[]           = enginePending('customer', 'cust-1');
        if ($nameTakenCount === 2) {
            $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Beta', 'email' => 'beta@example.test']);
            $ledger->pending[]           = enginePending('customer', 'cust-2');
        }

        $batch  = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
        $errors = array_column($ledger->attempts, 'error', 'local_uuid');

        expect($batch['failed'])->toBe(1)
            ->and(qbText($errors['cust-1']))->toBe(
                'QuickBooks did not accept customer "Acme- North": a QuickBooks customer, vendor or employee'
                . ' already uses that name. Rename one of them, then sync again.'
            );
    });
}

test('changes copied in from a quickbooks webhook show in activity as one inbound run', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->customers['cust-2'] = engineCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];
    $client->customers['qbo-2'] = [
        'Id'               => 'qbo-2', 'SyncToken' => '1', 'DisplayName' => 'Bea',
        'PrimaryEmailAddr' => ['Address' => 'bea@example.test'],
    ];
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-1');
    $ledger->links[]             = engineLink('customer', 'cust-2', 'qbo-2');
    $settings                    = qbSettings([
        'customer_conflict'  => 'quickbooks',
        'customer_reference' => 'quickbooks',
    ]);

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ['entity' => 'Customer', 'id' => 'qbo-2', 'operation' => 'Update'],
    ], $settings, 1_000);

    expect($ledger->batches)->toHaveCount(1)
        ->and($ledger->batches[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'trigger'      => 'webhook',
            'direction'    => 'inbound',
            'status'       => 'finished',
            'updated'      => 1,
            'failed'       => 0,
        ])
        ->and($ledger->attempts)->toBe([[
            'company_uuid' => 'company-uuid',
            'local_type'   => 'customer',
            'local_uuid'   => 'cust-1',
            'outcome'      => 'updated',
            'error'        => null,
        ]]);

    // The echo of a change Fleetbase made itself changes nothing and adds no row.
    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], $settings, 2_000);

    expect($ledger->batches)->toHaveCount(1);
});

test('a repeated quickbooks payment webhook that changes nothing adds no activity row', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $client->payments['pay-1']   = [
        'Id'   => 'pay-1', 'SyncToken' => '1', 'TotalAmt' => 1, 'TxnDate' => '2026-09-10',
        'Line' => [['Amount' => 1, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
    ];
    $ledger->links[] = engineLink('payment', 'inv-1', 'pay-1');
    $settings        = qbSettings(['payment_conflict' => 'quickbooks', 'payment_reference' => 'quickbooks']);
    $webhook         = [['entity' => 'Payment', 'id' => 'pay-1', 'operation' => 'Update']];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $webhook, $settings, 1_000);
    $batches = count($ledger->batches);
    // The next webhook reads the invoice fresh from the database, without the markers of the last run.
    $ledger->invoices['inv-1'] = array_filter(
        $ledger->invoices['inv-1'],
        static fn (string $key): bool => str_ends_with($key, '_from_quickbooks') === false,
        ARRAY_FILTER_USE_KEY
    );
    $engine->acceptRemoteChanges($ledger, 'company-uuid', $webhook, $settings, 2_000);

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(100)
        ->and($batches)->toBe(1)
        ->and($ledger->batches)->toHaveCount(1);
});

test('a quickbooks change that fleetbase primary replaces says so in activity', function () {
    foreach (['fleetbase' => true, 'quickbooks' => false] as $primary => $noted) {
        [$engine, $client]                 = qbEngine();
        $ledger                            = engineLedger();
        $ledger->customers['cust-1']       = engineCustomer('cust-1', ['name' => 'Ada']);
        $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
        $client->customers['qbo-customer'] = [
            'Id'               => 'qbo-customer', 'SyncToken' => '2', 'DisplayName' => 'Ada Renamed In QuickBooks',
            'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        ];
        $ledger->pending[] = array_merge(enginePending('customer', 'cust-1'), ['reason' => 'webhook']);

        $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
            'interval_minutes'   => 1,
            'customer_conflict'  => $primary,
            'customer_reference' => $primary,
        ]), time());
        $error = $ledger->attempts[0]['error'] ?? null;

        expect($batch['updated'])->toBe(1, $primary)
            ->and($batch['failed'])->toBe(0, $primary);
        if ($noted === true) {
            expect(qbText((string) $error))->toBe('Fleetbase is Primary here, so the change made in QuickBooks'
                . ' was replaced with the Fleetbase values. Choose QuickBooks as Primary to keep changes made'
                . ' in QuickBooks.');
        } else {
            expect($error)->toBeNull();
        }
    }
});

test('a new wallet named like an account another wallet uses gets its own account with its id added', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = engineLedger();
    $ledger->wallets['wal-1']   = engineWallet('wal-1', ['name' => 'Personal Wallet']);
    $ledger->wallets['wal-2']   = engineWallet('wal-2', ['name' => 'Personal Wallet']);
    $ledger->links[]            = engineLink('wallet', 'wal-1', 'acct-9');
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9', 'SyncToken' => '0', 'Name' => 'Personal Wallet', 'Active' => true,
        'Description' => 'Float', 'CurrencyRef' => ['value' => 'USD'],
    ];
    $ledger->pending[] = enginePending('wallet', 'wal-2');

    $first = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $link  = $ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-2');

    expect($first['created'])->toBe(1)
        ->and($link['qbo_id'])->not->toBe('acct-9')
        ->and($client->accounts[$link['qbo_id']]['Name'])->toBe('Personal Wallet (wallet_wal-2)')
        ->and($ledger->wallets['wal-2']['name'])->toBe('Personal Wallet');

    // The added id is not a difference: Fleetbase primary leaves it, QuickBooks primary does not copy it.
    foreach (['fleetbase', 'quickbooks'] as $primary) {
        $again = engineRunAgain($engine, $ledger, qbSettings([
            'interval_minutes' => 1,
            'wallet_conflict'  => $primary,
            'wallet_reference' => $primary,
        ]), time() + 10);

        expect($again['aligned'])->toBe(1)
            ->and($client->calls)->not->toContain('updateAccount')
            ->and($ledger->wallets['wal-2']['name'])->toBe('Personal Wallet');
    }

    // Renamed in Fleetbase, the account keeps the added id, so the new name cannot clash either.
    $ledger->wallets['wal-2']['name'] = 'Business Wallet';
    $renamed                          = engineRunAgain($engine, $ledger, qbSettings(), time() + 20);

    expect($renamed['updated'])->toBe(1)
        ->and($client->accounts[$link['qbo_id']]['Name'])->toBe('Business Wallet (wallet_wal-2)')
        ->and($ledger->wallets['wal-2']['name'])->toBe('Business Wallet');
});

test('two new wallets with the same name in one run get two accounts', function () {
    [$engine, $client] = qbEngine();
    $ledger            = engineLedger();
    foreach (['wal-1', 'wal-2'] as $uuid) {
        $ledger->wallets[$uuid] = engineWallet($uuid, ['name' => 'Personal Wallet']);
        $ledger->pending[]      = enginePending('wallet', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $names = array_column($client->accounts, 'Name');
    sort($names);

    expect($batch['created'])->toBe(2)
        ->and($batch['failed'])->toBe(0)
        ->and($names)->toBe(['Personal Wallet', 'Personal Wallet (wallet_wal-2)']);
});

test('a lost wallet link does not reattach an account another wallet is linked to', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = engineLedger();
    $ledger->wallets['wal-2']   = engineWallet('wal-2');
    $ledger->links[]            = engineLink('wallet', 'wal-2', 'gone');
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9', 'SyncToken' => '3', 'Name' => 'Operating', 'Active' => true,
        'Description' => 'Float', 'CurrencyRef' => ['value' => 'USD'],
    ];
    $ledger->remoteLinkReader = static function (string $realm, string $entity, array $ids): array {
        return $realm === 'realm-1' && $entity === 'Account' ? array_values(array_intersect($ids, ['acct-9'])) : [];
    };
    $ledger->pending[] = enginePending('wallet', 'wal-2');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-2')['qbo_id'])->toBe('gone');
});

function engineLedger(): SyncLedger
{
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid'    => 'company-uuid',
        'realm_id'        => 'realm-1',
        'needs_reauth'    => false,
        'home_currency'   => 'USD',
        'default_item_id' => 'item-1',
    ];

    return $ledger;
}

function engineCustomer(string $uuid, array $overrides = []): array
{
    return array_merge(['uuid' => $uuid, 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'], $overrides);
}

function engineWallet(string $uuid, array $overrides = []): array
{
    return array_merge([
        'uuid'         => $uuid,
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_' . $uuid,
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'USD',
        'status'       => 'active',
    ], $overrides);
}

/**
 * @param array<string, mixed> $invoiceOverrides
 * @param array<string, mixed> $remoteOverrides
 *
 * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
 */
function engineLinkedInvoice(array $invoiceOverrides = [], array $remoteOverrides = []): array
{
    [$engine, $client]           = \qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1', $invoiceOverrides);
    $client->invoices['qb-1']    = engineRemoteInvoice('qb-1', $remoteOverrides);
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');

    return [$engine, $client, $ledger];
}

function engineRunAgain(SyncEngine $engine, SyncLedger $ledger, array $settings, int $when): array
{
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';

    return $engine->runScheduled($ledger, 'company-uuid', $settings, $when);
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function engineRemoteInvoice(string $id = 'qb-1', array $overrides = []): array
{
    return array_merge([
        'Id'        => $id,
        'SyncToken' => '1',
        'DocNumber' => 'INV-1',
        'TotalAmt'  => 10,
        'TxnDate'   => '2026-09-01',
        'DueDate'   => '2026-09-15',
        'Line'      => [[
            'Amount'              => '10.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ], $overrides);
}

function engineInvoice(string $uuid, array $overrides = []): array
{
    return array_merge([
        'uuid'          => $uuid,
        'company_uuid'  => 'company-uuid',
        'customer_uuid' => 'cust-1',
        'number'        => 'INV-1',
        'date'          => '2026-09-01',
        'due_date'      => '2026-09-15',
        'currency'      => 'USD',
        'tax'           => 0,
        'total'         => 1000,
        'status'        => 'sent',
        'items'         => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ], $overrides);
}

function enginePending(string $type, string $uuid): array
{
    return ['company_uuid' => 'company-uuid', 'local_type' => $type, 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
}

function engineLink(string $type, string $uuid, string $qboId): array
{
    return [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => $type,
        'local_uuid'   => $uuid,
        'qbo_entity'   => ['customer' => 'Customer', 'invoice' => 'Invoice', 'wallet' => 'Account', 'payment' => 'Payment'][$type],
        'qbo_id'       => $qboId,
        'sync_token'   => '0',
    ];
}

test('a fleetbase edit keeps the product, tax code and note line QuickBooks has on the invoice', function () {
    [$engine, $client]                          = qbEngine();
    $ledger                                     = engineLedger();
    $ledger->customers['cust-1']                = engineCustomer('cust-1');
    $ledger->links[]                            = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->links[]                            = engineLink('invoice', 'inv-1', 'qb-1');
    $sales                                      = InvoiceLines::sales('1', 'Delivery', '10.00');
    $sales['SalesItemLineDetail']['ItemRef']    = ['value' => '55', 'name' => 'Delivery: Base'];
    $sales['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
    $note                                       = [
        'Id'                    => '2',
        'DetailType'            => 'DescriptionOnly',
        'Description'           => 'Dock B',
        'DescriptionLineDetail' => [],
    ];
    $client->invoices['qb-1'] = [
        'Id'          => 'qb-1',
        'SyncToken'   => '0',
        'DocNumber'   => 'INV-1',
        'TxnDate'     => '2026-09-01',
        'DueDate'     => '2026-09-15',
        'CustomerRef' => ['value' => 'qbo-customer'],
        'TotalAmt'    => '10.00',
        'Line'        => [$sales, $note],
    ];
    $ledger->invoices['inv-1'] = engineInvoice('inv-1', [
        'total' => 1200,
        'items' => [InvoiceLines::tracked('item-a', 'Delivery', 1200, '1')],
    ]);
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $lines = $client->invoices['qb-1']['Line'];

    expect($batch['updated'])->toBe(1)
        ->and($lines[0]['Amount'])->toBe('12.00')
        ->and($lines[0]['SalesItemLineDetail']['ItemRef']['value'])->toBe('55')
        ->and($lines[0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($lines[1]['DetailType'])->toBe('DescriptionOnly')
        ->and($lines[1]['Description'])->toBe('Dock B');
});

test('a QuickBooks bundle line alone does not make a fleetbase primary invoice look changed', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $client->invoices['qb-1']    = [
        'Id'          => 'qb-1',
        'SyncToken'   => '0',
        'DocNumber'   => 'INV-1',
        'TxnDate'     => '2026-09-01',
        'DueDate'     => '2026-09-15',
        'CustomerRef' => ['value' => 'qbo-customer'],
        'TotalAmt'    => '30.00',
        'Line'        => [
            InvoiceLines::sales('1', 'Delivery', '10.00'),
            [
                'Id'              => '3',
                'Amount'          => '20.00',
                'DetailType'      => 'GroupLineDetail',
                'GroupLineDetail' => ['Quantity' => 1, 'Line' => []],
            ],
        ],
    ];
    $ledger->invoices['inv-1'] = engineInvoice('inv-1');
    $ledger->pending[]         = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['SyncToken'])->toBe('0');
});

test('an inactive service item is forgotten so the next sync finds or creates an active one', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $client->inactiveItems       = ['item-1'];
    $client->serviceItemId       = 'item-2';
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $ledger->invoices['inv-2']   = engineInvoice('inv-2', ['number' => 'INV-2']);
    $ledger->pending[]           = enginePending('invoice', 'inv-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-2');

    $failed = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $errors = array_column($ledger->attempts, 'error', 'local_uuid');

    expect($failed['failed'])->toBe(2)
        ->and(qbText($errors['inv-1'] ?? null))->toContain('Fleetbase service (deleted)')
        ->and(array_count_values($client->calls)['itemActive'] ?? 0)->toBe(1)
        ->and($ledger->connections['company-uuid']['default_item_id'])->toBeNull();

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    foreach ($ledger->pending as $index => $row) {
        $ledger->pending[$index]['next_attempt_at'] = null;
        $ledger->pending[$index]['status']          = 'pending';
    }
    $created = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time() + 600);

    expect($created['created'])->toBe(2)
        ->and($ledger->connections['company-uuid']['default_item_id'])->toBe('item-2')
        ->and($client->invoices['inv-1']['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => 'item-2']);
});

test('the stored service item is kept when an invalid reference is about something else', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $client->inactiveItems       = ['other'];
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = engineInvoice('inv-1');
    $ledger->pending[]           = enginePending('invoice', 'inv-1');
    $client->rejectInvoicesWith  = new QuickBooksException(400, 'Invalid Reference Id : Names element 9', null, '2500');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('itemActive')
        ->and($ledger->connections['company-uuid']['default_item_id'])->toBe('item-1');
});

test('a service item QuickBooks refuses to create is asked for once per block', function () {
    // Intuit's duplicate name fault, for a service item QuickBooks will not create.
    $taken = new QuickBooksException(400, 'Name already exists', null, '6240');

    [$engine, $client]                                      = qbEngine();
    $ledger                                                 = engineLedger();
    $ledger->connections['company-uuid']['default_item_id'] = null;
    $client->failServiceItem                                = $taken;
    $ledger->customers['cust-1']                            = engineCustomer('cust-1');
    $ledger->links[]                                        = engineLink('customer', 'cust-1', 'qbo-customer');
    foreach (['inv-1', 'inv-2', 'inv-3'] as $index => $uuid) {
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => 'INV-' . ($index + 1)]);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(3)
        ->and(array_count_values($client->calls)['ensureServiceItem'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice');
});

test('a refused service item does not end the run when QuickBooks numbers new invoices', function () {
    // Intuit's duplicate name fault, for a service item QuickBooks will not create.
    $taken = new QuickBooksException(400, 'Name already exists', null, '6240');

    [$engine, $client]                                      = qbEngine();
    $ledger                                                 = engineLedger();
    $ledger->connections['company-uuid']['default_item_id'] = null;
    $client->customTxnNumbers                               = true;
    $client->failServiceItem                                = $taken;
    $ledger->customers['cust-1']                            = engineCustomer('cust-1');
    $ledger->links[]                                        = engineLink('customer', 'cust-1', 'qbo-customer');
    foreach (['inv-1', 'inv-2'] as $uuid) {
        $ledger->invoices[$uuid] = engineInvoice($uuid, ['number' => null]);
        $ledger->pending[]       = enginePending('invoice', $uuid);
    }

    $settings = qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']);
    $batch    = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $errors   = array_column($ledger->attempts, 'error', 'local_uuid');

    expect($batch['failed'])->toBe(2)
        ->and(qbText($errors['inv-1'] ?? null))->toContain('already exists')
        ->and($client->calls)->not->toContain('createInvoice');
});

test('a blank fleetbase line and its blank QuickBooks line stay aligned', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = engineLedger();
    $ledger->customers['cust-1'] = engineCustomer('cust-1');
    $ledger->links[]             = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->links[]             = engineLink('invoice', 'inv-1', 'qb-1');
    $blank                       = InvoiceLines::sales('1', '', '10.00');
    // QuickBooks names the product on every read; the line itself has no description.
    $blank['SalesItemLineDetail']['ItemRef'] = ['value' => 'item-1', 'name' => 'Fleetbase service'];
    $client->invoices['qb-1']                = [
        'Id'          => 'qb-1',
        'SyncToken'   => '0',
        'DocNumber'   => 'INV-1',
        'TxnDate'     => '2026-09-01',
        'DueDate'     => '2026-09-15',
        'CustomerRef' => ['value' => 'qbo-customer'],
        'TotalAmt'    => '10.00',
        'Line'        => [$blank],
    ];
    $ledger->invoices['inv-1'] = engineInvoice('inv-1', [
        'items' => [['description' => '', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
    ]);

    foreach (['fleetbase', 'quickbooks'] as $conflict) {
        $settings = qbSettings(['interval_minutes' => 1, 'invoice_conflict' => $conflict]);

        $ledger->pending[]                                    = enginePending('invoice', 'inv-1');
        $ledger->connections['company-uuid']['last_batch_at'] = null;

        $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

        expect($batch['aligned'])->toBe(1)
            ->and($ledger->invoices['inv-1']['items'][0]['description'])->toBe('');
    }
    expect($client->calls)->not->toContain('updateInvoice');
});

test('a fleetbase edit of an invoice with a QuickBooks sales tax rate is accepted', function () {
    [$engine, $client]                          = qbEngine();
    $ledger                                     = engineLedger();
    $ledger->customers['cust-1']                = engineCustomer('cust-1');
    $ledger->links[]                            = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->links[]                            = engineLink('invoice', 'inv-1', 'qb-1');
    $sales                                      = InvoiceLines::sales('1', 'Delivery', '10.00');
    $sales['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
    $client->invoices['qb-1']                   = [
        'Id'           => 'qb-1',
        'SyncToken'    => '0',
        'DocNumber'    => 'INV-1',
        'TxnDate'      => '2026-09-01',
        'DueDate'      => '2026-09-15',
        'CustomerRef'  => ['value' => 'qbo-customer'],
        'TotalAmt'     => '10.80',
        'Line'         => [$sales],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.80'],
    ];
    $ledger->invoices['inv-1'] = engineInvoice('inv-1', [
        'total' => 1200,
        'items' => [InvoiceLines::tracked('item-a', 'Delivery', 1200, '1')],
    ]);
    $ledger->pending[] = enginePending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(0)
        ->and($batch['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['Line'][0]['Amount'])->toBe('12.00')
        ->and($client->invoices['qb-1']['TxnTaxDetail']['TxnTaxCodeRef'])->toBe(['value' => '2']);
});

/**
 * A US company taxing at 8% (rate code 2), with a taxable customer and service item.
 *
 * @param array<string, mixed> $invoiceOverrides
 *
 * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
 */
function engineTaxedCompany(array $invoiceOverrides = []): array
{
    [$engine, $client]                 = qbEngine();
    $client->salesTax                  = ['mode' => 'us', 'automated' => false, 'rate' => '2'];
    $client->taxRates                  = ['2' => '8'];
    $client->assignLineIds             = true;
    $client->items['item-1']           = [
        'Id'               => 'item-1',
        'Name'             => 'Fleetbase service',
        'Taxable'          => true,
        'IncomeAccountRef' => ['value' => '1', 'name' => 'Services'],
    ];
    $client->customers['qbo-customer'] = [
        'Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'Taxable' => true,
    ];
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1');
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']         = engineInvoice('inv-1', $invoiceOverrides);
    $ledger->pending[]                 = enginePending('invoice', 'inv-1');

    return [$engine, $client, $ledger];
}

test('QuickBooks works out the tax of a new invoice and Fleetbase takes it', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['tax' => 150, 'total' => 1150, 'items' => [
        InvoiceMapper::item('Delivery', 1, 1000, 1000, '15.00', 150),
    ]]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first   = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $created = $client->invoices['inv-1'];

    expect($first['created'])->toBe(1)
        ->and($created['Line'])->toHaveCount(1)
        ->and($created['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($created['TxnTaxDetail']['TxnTaxCodeRef'])->toBe(['value' => '2'])
        ->and($created['TxnTaxDetail']['TotalTax'])->toBe('0.80')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1080)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_rate'])->toBe('8.00')
        ->and($ledger->invoices['inv-1']['items'][0]['tax_amount'])->toBe(80)
        // The line had a higher Fleetbase tax rate, so Activity says the tax went down.
        ->and($ledger->attempts[0]['error'])->toBe('quickbooks.messages.invoice-tax-lowered')
        ->and($ledger->connections['company-uuid']['default_item_account'])->toBe('Services');

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a Fleetbase edit is sent without its tax and QuickBooks works the tax out again', function () {
    [$engine, $client, $ledger] = engineTaxedCompany();
    $settings                   = qbSettings(['interval_minutes' => 1]);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    $ledger->invoices['inv-1']['items'][0]['amount']     = 2000;
    $ledger->invoices['inv-1']['items'][0]['unit_price'] = 2000;
    $second                                              = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['updated'])->toBe(1)
        ->and($client->invoices['inv-1']['TxnTaxDetail']['TotalTax'])->toBe('1.60')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(160)
        ->and($ledger->invoices['inv-1']['total'])->toBe(2160)
        ->and($ledger->attempts[1]['error'])->toBeNull();

    $third = engineRunAgain($engine, $ledger, $settings, time() + 20);

    expect($third['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'])->toBe(1);
});

test('a QuickBooks discount stays while the tax is copied back, and the next syncs are aligned', function () {
    [$engine, $client, $ledger] = engineTaxedCompany();
    $settings                   = qbSettings(['interval_minutes' => 1]);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $discount                                = [
        'Id'                 => '20',
        'Amount'             => '1.50',
        'DetailType'         => 'DiscountLineDetail',
        'DiscountLineDetail' => ['PercentBased' => false],
    ];
    $client->invoices['inv-1']['Line'][]                 = $discount;
    $client->invoices['inv-1']['TotalAmt']               = '9.30';
    $client->invoices['inv-1']['SyncToken']              = '1';
    $ledger->invoices['inv-1']['items'][0]['amount']     = 2000;
    $ledger->invoices['inv-1']['items'][0]['unit_price'] = 2000;

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);
    $lines  = array_column($client->invoices['inv-1']['Line'], 'DetailType');

    expect($second['updated'])->toBe(1)
        ->and($lines)->toContain('DiscountLineDetail')
        ->and($client->invoices['inv-1']['TxnTaxDetail']['TotalTax'])->toBe('1.60')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(160)
        ->and($ledger->invoices['inv-1']['total'])->toBe(2160);

    $third  = engineRunAgain($engine, $ledger, $settings, time() + 20);
    $fourth = engineRunAgain($engine, $ledger, $settings, time() + 30);

    expect($third['aligned'])->toBe(1)
        ->and($fourth['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['updateInvoice'])->toBe(1)
        ->and($ledger->invoices['inv-1']['total'])->toBe(2160);
});

test('a customer QuickBooks does not tax gets lines that are not taxed', function () {
    [$engine, $client, $ledger]                   = engineTaxedCompany();
    $client->customers['qbo-customer']['Taxable'] = false;

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->invoices['inv-1']['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'NON'])
        ->and($client->invoices['inv-1']['TxnTaxDetail']['TotalTax'])->toBe('0.00')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(0)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1000);
});

test('the customer sales tax rate is used before the company rate', function () {
    [$engine, $client, $ledger]                             = engineTaxedCompany();
    $client->taxRates['3']                                  = '10';
    $client->customers['qbo-customer']['DefaultTaxCodeRef'] = ['value' => '3'];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->invoices['inv-1']['TxnTaxDetail']['TxnTaxCodeRef'])->toBe(['value' => '3'])
        ->and($ledger->invoices['inv-1']['tax'])->toBe(100);
});

test('with sales tax off in QuickBooks no tax codes are sent', function () {
    [$engine, $client, $ledger] = engineTaxedCompany();
    $client->salesTax           = ['mode' => 'off', 'automated' => false, 'rate' => null];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->invoices['inv-1']['Line'][0]['SalesItemLineDetail'])->not->toHaveKey('TaxCodeRef')
        ->and($client->invoices['inv-1'])->not->toHaveKey('TxnTaxDetail')
        ->and($client->calls)->not->toContain('getCustomer:qbo-customer');
});

test('Fleetbase to QuickBooks only keeps the Fleetbase tax', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['tax' => 150, 'total' => 1150]);

    $settings = qbSettings(['interval_minutes' => 1, 'invoice_direction' => 'outbound']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($client->invoices['inv-1']['TxnTaxDetail']['TotalTax'])->toBe('0.80')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1150);
});

test('a taxable invoice with no sales tax rate is sent and Activity says why it has no tax', function () {
    [$engine, $client, $ledger] = engineTaxedCompany();
    $client->salesTax['rate']   = null;

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and($client->invoices['inv-1'])->not->toHaveKey('TxnTaxDetail')
        ->and($ledger->attempts[0]['outcome'])->toBe('created')
        ->and($ledger->attempts[0]['error'])->toBe('quickbooks.messages.invoice-no-tax-rate');
});

test('with automated sales tax QuickBooks chooses the rate and Activity gives no missing-rate note', function () {
    [$engine, $client, $ledger]                             = engineTaxedCompany();
    $client->salesTax                                       = ['mode' => 'us', 'automated' => true, 'rate' => null];
    $client->automatedRate                                  = '2';
    $client->customers['qbo-customer']['DefaultTaxCodeRef'] = ['value' => '3'];
    $settings                                               = qbSettings(['interval_minutes' => 1]);

    $batch   = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $created = $client->sentInvoices[0] ?? [];

    expect($batch['created'])->toBe(1)
        ->and($created['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($created)->not->toHaveKey('TxnTaxDetail')
        ->and($client->invoices['inv-1']['TxnTaxDetail']['TotalTax'])->toBe('0.80')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->attempts[0]['error'])->toBeNull();

    expect(engineRunAgain($engine, $ledger, $settings, time() + 10)['aligned'])->toBe(1);
});

test('with no sales tax rate the missing rate stays the Activity note when the Fleetbase tax goes', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['tax' => 150, 'total' => 1150, 'items' => [
        InvoiceMapper::item('Delivery', 1, 1000, 1000, '15.00', 150),
    ]]);
    $client->salesTax['rate']   = null;

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->invoices['inv-1']['tax'])->toBe(0)
        ->and($ledger->attempts[0]['error'])->toBe('quickbooks.messages.invoice-no-tax-rate');
});

test('a failed read of the QuickBooks tax settings fails the invoice instead of sending it untaxed', function () {
    [$engine, $client, $ledger] = engineTaxedCompany();
    $client->failSalesTax       = new QuickBooksException(500, 'Intuit is down');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($client->calls)->not->toContain('batch');
});

test('an invoice block reads the tax settings, customers and service item once', function () {
    [$engine, $client, $ledger]        = engineTaxedCompany();
    $ledger->customers['cust-2']       = engineCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->links[]                   = engineLink('customer', 'cust-2', 'qbo-bea');
    $client->customers['qbo-bea']      = [
        'Id' => 'qbo-bea', 'SyncToken' => '0', 'DisplayName' => 'Bea', 'Taxable' => false,
    ];
    $ledger->invoices['inv-2']         = engineInvoice('inv-2', ['number' => 'INV-2', 'customer_uuid' => 'cust-2']);
    $ledger->pending[]                 = enginePending('invoice', 'inv-2');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    $codes = [];
    foreach ($client->invoices as $invoice) {
        $codes[(string) $invoice['DocNumber']] = $invoice['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'];
    }

    expect($batch['created'])->toBe(2)
        ->and($codes)->toBe(['INV-1' => 'TAX', 'INV-2' => 'NON'])
        ->and($client->salesTaxReads)->toBe(1)
        ->and($client->itemReads)->toBe(1)
        ->and($client->calls)->not->toContain('getCustomer:qbo-customer')
        ->and($client->calls)->not->toContain('getCustomer:qbo-bea')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->invoices['inv-2']['tax'])->toBe(0);
});

test('an inactive QuickBooks customer the block query leaves out is read by id and keeps its tax', function () {
    [$engine, $client, $ledger]                  = engineTaxedCompany();
    $client->customers['qbo-customer']['Active'] = false;
    $ledger->customers['cust-2']                 = engineCustomer('cust-2', [
        'name'  => 'Bea',
        'email' => 'bea@example.test',
    ]);
    $ledger->links[]                             = engineLink('customer', 'cust-2', 'qbo-bea');
    $client->customers['qbo-bea']                = [
        'Id' => 'qbo-bea', 'SyncToken' => '0', 'DisplayName' => 'Bea', 'Taxable' => false,
    ];
    $ledger->invoices['inv-2']                   = engineInvoice('inv-2', [
        'number'        => 'INV-2',
        'customer_uuid' => 'cust-2',
    ]);
    $ledger->pending[]                           = enginePending('invoice', 'inv-2');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    $codes = [];
    foreach ($client->invoices as $invoice) {
        $codes[(string) $invoice['DocNumber']] = $invoice['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'];
    }

    expect($codes)->toBe(['INV-1' => 'TAX', 'INV-2' => 'NON'])
        ->and(array_count_values($client->calls)['getCustomer:qbo-customer'] ?? 0)->toBe(1)
        ->and($client->calls)->not->toContain('getCustomer:qbo-bea')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(80);
});

test('QuickBooks Primary copies the line taxes and the next sync is aligned', function () {
    $taxed                                      = InvoiceLines::sales('1', 'Delivery', '10.00');
    $taxed['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
    [$engine, $client, $ledger]                 = engineLinkedInvoice([], [
        'TotalAmt'     => '10.80',
        'Line'         => [$taxed],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.80'],
    ]);
    $settings = qbSettings(['interval_minutes' => 1, 'invoice_conflict' => 'quickbooks']);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1080)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_rate'])->toBe('8.00')
        ->and($ledger->invoices['inv-1']['items'][0]['tax_amount'])->toBe(80);

    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a paid invoice with no amount stays paid when QuickBooks adds tax, and its payment covers it', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['status' => 'paid', 'amount_paid' => 0]);

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $payment = array_values($client->payments)[0] ?? [];

    expect($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1080)
        ->and($payment['TotalAmt'] ?? null)->toBe('10.80');
});

test('a payment above the QuickBooks invoice total is sent at that total with a note, then aligned', function () {
    [$engine, $client, $ledger] = engineTaxedCompany([
        'tax'         => 80,
        'total'       => 1080,
        'status'      => 'paid',
        'amount_paid' => 1200,
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $payment = array_values($client->payments)[0] ?? [];
    $note    = qbText((string) ($ledger->attempts[0]['error'] ?? ''));
    $second  = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($payment['TotalAmt'] ?? null)->toBe('10.80')
        ->and($payment)->not->toHaveKey('UnappliedAmt')
        ->and($note)->toContain('more than the QuickBooks invoice total')
        ->and($second['failed'])->toBe(0)
        ->and($client->calls)->not->toContain('updatePayment');
});

test('in a block of invoices a payment is capped at the total of the invoice just created', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['status' => 'paid', 'amount_paid' => 1200]);
    $ledger->invoices['inv-2']  = engineInvoice('inv-2', [
        'number'      => 'INV-2',
        'status'      => 'paid',
        'amount_paid' => 1200,
    ]);
    $ledger->pending[]          = enginePending('invoice', 'inv-2');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    $note = qbText((string) ($ledger->attempts[0]['error'] ?? ''));

    expect(array_column(array_values($client->payments), 'TotalAmt'))->toBe(['10.80', '10.80'])
        ->and($note)->toContain('more than the QuickBooks invoice total');
});

test('in a block of invoices a payment follows the total of the invoice just updated', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['status' => 'paid', 'amount_paid' => 1080]);
    $ledger->invoices['inv-2']  = engineInvoice('inv-2', [
        'number'      => 'INV-2',
        'status'      => 'paid',
        'amount_paid' => 1080,
    ]);
    $ledger->pending[]          = enginePending('invoice', 'inv-2');
    $settings                   = qbSettings(['interval_minutes' => 1]);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    foreach (['inv-1', 'inv-2'] as $index => $uuid) {
        $ledger->invoices[$uuid]['items'][0]['amount']     = 2000;
        $ledger->invoices[$uuid]['items'][0]['unit_price'] = 2000;
        $ledger->invoices[$uuid]['amount_paid']            = 2160;
        $ledger->pending[$index]['status']                 = 'pending';
    }
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect(array_column(array_values($client->payments), 'TotalAmt'))->toBe(['21.60', '21.60']);
});

test('a Fleetbase line rate QuickBooks replaces with a higher one is noted', function () {
    [$engine, $client, $ledger] = engineTaxedCompany(['tax' => 50, 'total' => 1050, 'items' => [
        InvoiceMapper::item('Delivery', 1, 1000, 1000, '5.00', 50),
    ]]);

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->attempts[0]['error'])->toBe('quickbooks.messages.invoice-tax-from-quickbooks');
});

test('less tax from QuickBooks lowers the Fleetbase total with a note, and the payment follows it', function () {
    // Paid in Fleetbase with 1.50 tax of its own; QuickBooks works out 0.80.
    [$engine, $client, $ledger] = engineTaxedCompany([
        'tax'         => 150,
        'total'       => 1150,
        'status'      => 'paid',
        'amount_paid' => 1150,
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $payment = array_values($client->payments)[0] ?? [];
    $note    = qbText((string) ($ledger->attempts[0]['error'] ?? ''));
    $second  = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($ledger->invoices['inv-1']['total'])->toBe(1080)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1150)
        ->and($payment['TotalAmt'] ?? null)->toBe('10.80')
        ->and($note)->toContain('less tax')
        ->and($second['failed'])->toBe(0)
        ->and($client->calls)->not->toContain('updatePayment');
});

test('an invoice QuickBooks holds with tax included is aligned and its total stays the QuickBooks total', function () {
    $taxed                                      = InvoiceLines::sales('1', 'Delivery', '10.00');
    $taxed['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
    [$engine, $client, $ledger]                 = engineLinkedInvoice([
        'items' => [InvoiceLines::tracked('item-a', 'Delivery', 1000, '1')],
    ], [
        'CustomerRef'          => ['value' => 'qbo-customer'],
        'GlobalTaxCalculation' => 'TaxInclusive',
        'TotalAmt'             => '10.00',
        'Line'                 => [$taxed],
        'TxnTaxDetail'         => ['TotalTax' => '0.83'],
    ]);
    $settings = qbSettings(['interval_minutes' => 1]);

    $first  = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $second = engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($first['failed'])->toBe(0)
        ->and($second['aligned'])->toBe(1)
        ->and($ledger->invoices['inv-1']['tax'])->toBe(83)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1000);
});

test('an invoice that is not sent to QuickBooks does not read the QuickBooks tax settings', function () {
    $remote                     = ['CustomerRef' => ['value' => 'qbo-customer'], 'PrivateNote' => 'From QuickBooks'];
    [$engine, $client, $ledger] = engineLinkedInvoice([], $remote);
    $client->failSalesTax       = new QuickBooksException(500, 'Intuit is down');

    $settings = qbSettings(['interval_minutes' => 1, 'invoice_direction' => 'inbound']);
    $batch    = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['failed'])->toBe(0)
        ->and($ledger->invoices['inv-1']['notes'])->toBe('From QuickBooks')
        ->and($client->salesTaxReads)->toBe(0);
});

test('an Activity note from a sync that stopped is not shown on a later sync', function () {
    [$engine, $client, $ledger] = engineTaxedCompany();
    $client->salesTax['rate']   = null;
    $client->rejectInvoicesWith = new QuickBooksException(401, 'Token refused');
    $settings                   = qbSettings(['interval_minutes' => 1]);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    $client->rejectInvoicesWith                           = null;
    $client->salesTax['rate']                             = '2';
    $ledger->connections['company-uuid']['needs_reauth']  = false;
    $ledger->attempts                                     = [];
    engineRunAgain($engine, $ledger, $settings, time() + 10);

    expect($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->attempts[0]['error'] ?? null)->toBeNull();
});

test('forgetting an inactive service item forgets its income account too', function () {
    [$engine, $client, $ledger]                                   = engineTaxedCompany();
    $ledger->connections['company-uuid']['default_item_account']  = 'Old income';
    $client->inactiveItems                                        = ['item-1'];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->connections['company-uuid']['default_item_id'])->toBeNull()
        ->and($ledger->connections['company-uuid']['default_item_account'])->toBeNull();
});

test('a retried create whose first answer was lost links the invoice QuickBooks saved'
. ' under its reserved number instead of creating a second one', function () {
    $previousCache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
    try {
        $client                            = new LostCreateQuickBooks();
        $client->customTxnNumbers          = true;
        $client->loseInvoiceCreates        = 1;
        $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada'];
        [$engine]                          = qbEngine($client);
        $ledger                            = engineLedger();
        $ledger->customers['cust-1']       = engineCustomer('cust-1');
        $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
        $ledger->invoices['inv-1']         = engineInvoice('inv-1');
        $ledger->pending[]                 = enginePending('invoice', 'inv-1');
        $settings                          = qbSettings(['invoice_reference' => 'quickbooks']);

        $first = $engine->runScheduled($ledger, 'company-uuid', $settings, 1_000);
        engineRunAgain($engine, $ledger, $settings, 1_000_000);

        expect($first['failed'])->toBe(1)
            ->and($client->invoiceCreates)->toBe(1)
            ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['qbo_id'] ?? null)->toBe('inv-1')
            ->and($ledger->invoices['inv-1']['number'])->toBe('1');
    } finally {
        Cache::swap($previousCache);
    }
});

test('a linked invoice whose note is longer than QuickBooks keeps is not updated again on every sync', function () {
    $note                       = str_repeat('n', 4001);
    [$engine, $client, $ledger] = engineLinkedInvoice(['notes' => $note], ['PrivateNote' => mb_substr($note, 0, 4000)]);

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('updateInvoice');
});

test('a deleted fleetbase customer with an open quickbooks balance is not made inactive', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = engineLedger();
    $ledger->customers['cust-1']       = engineCustomer('cust-1', ['deleted_at' => '2026-10-19 10:00:00']);
    $ledger->links[]                   = engineLink('customer', 'cust-1', 'qbo-customer');
    $ledger->pending[]                 = enginePending('customer', 'cust-1');
    $client->customers['qbo-customer'] = [
        'Id'              => 'qbo-customer',
        'SyncToken'       => '3',
        'DisplayName'     => 'Ada',
        'Active'          => true,
        'Balance'         => '1250.00',
        'BalanceWithJobs' => '1250.00',
    ];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['Active'])->toBeTrue()
        ->and($ledger->attempts[0]['outcome'])->toBe('skipped')
        ->and(qbText($ledger->attempts[0]['error']))->toBe(
            'Customer "Ada" was deleted in Fleetbase but still has an open balance of 1250.00 in QuickBooks,'
            . ' so it was left active.'
        )
        ->and($ledger->pending[0]['status'])->toBe('done');
});
