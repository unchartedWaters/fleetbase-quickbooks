<?php

use Fleetbase\Quickbooks\Tests\Support\FleetbaseTaxCompany as Company;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;
use Fleetbase\Quickbooks\Tests\Support\LostCreateQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/*
 * Tax worked out by Fleetbase (tax_source fleetbase): an invoice first sent to QuickBooks while
 * the setting is on carries the Fleetbase tax, keeps it in Fleetbase, and a different tax in
 * QuickBooks is replaced with it on the next sync. Older invoices keep QuickBooks' tax.
 */

test('a new invoice sends its Fleetbase tax to QuickBooks and keeps it', function () {
    [$engine, $client, $ledger] = Company::us();
    $settings                   = Company::settings();

    $first  = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $sent   = $client->sentInvoices[0];
    $second = Company::again($engine, $ledger, $settings, time() + 10);

    expect($first['created'])->toBe(1)
        ->and($sent['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($sent['TxnTaxDetail'])->toBe(['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '1.50'])
        ->and($client->invoices['inv-1']['TotalAmt'])->toBe('11.50')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_rate'])->toBe('15.00')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1150)
        ->and(Company::invoiceLink($ledger)['tax_source'] ?? null)->toBe('fleetbase')
        ->and($second['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('an invoice sent before Fleetbase worked out tax keeps the QuickBooks tax', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::link($client, $ledger, '1', [
        'TotalAmt'     => '10.80',
        'Line'         => [InvoiceLines::coded('1', 'Delivery', '10.00', 'TAX')],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.80'],
    ], false);

    $engine->runScheduled($ledger, 'company-uuid', Company::settings(), time());

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(80)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1080);
});

test('with Fleetbase as Primary a Fleetbase tax change is sent', function () {
    [$engine, $client, $ledger] = Company::us([
        'tax'   => 100,
        'total' => 1100,
        'items' => [Company::line(1000, '10.00', 100)],
    ]);
    Company::link($client, $ledger, '1', [
        'TotalAmt'     => '11.50',
        'Line'         => [InvoiceLines::coded('1', 'Delivery', '10.00', 'TAX')],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '1.50'],
    ], true);

    $batch = $engine->runScheduled($ledger, 'company-uuid', Company::settings(), time());

    expect($batch['updated'])->toBe(1)
        ->and($client->invoices['qb-1']['TxnTaxDetail']['TotalTax'])->toBe('1.00')
        ->and($client->invoices['qb-1']['TotalAmt'])->toBe('11.00')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(100);
});

test('with QuickBooks as Primary a QuickBooks tax edit is put back and Activity says so', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::link($client, $ledger, '2', [
        'TotalAmt'     => '10.80',
        'Line'         => [InvoiceLines::coded('1', 'Delivery', '10.00', 'TAX')],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.80'],
    ], true, '1');
    $settings = Primary::shippedSettings(['tax_source' => 'fleetbase']);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $client->calls = [];
    $again         = Company::again($engine, $ledger, $settings, time() + 10);

    expect($client->invoices['qb-1']['TxnTaxDetail']['TotalTax'])->toBe('1.50')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_rate'])->toBe('15.00')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1150)
        ->and(Primary::notes($ledger))->toContain(
            'QuickBooks had a different tax for this invoice. Fleetbase works out tax for this organization, so its'
                . ' tax was sent back to QuickBooks.'
        )
        ->and($again['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a webhook copy that leaves a tax difference queues the invoice so Fleetbase sends its tax', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::link($client, $ledger, '2', [
        'TotalAmt'     => '10.80',
        'Line'         => [InvoiceLines::coded('1', 'Delivery', '10.00', 'TAX')],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.80'],
    ], true, '1');
    $ledger->pending = [];
    $settings        = Primary::shippedSettings(['tax_source' => 'fleetbase']);
    $entities        = [['entity' => 'Invoice', 'id' => 'qb-1', 'operation' => 'Update']];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, $settings, time());

    expect($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and(array_column($ledger->pending, 'local_uuid'))->toContain('inv-1');
});

test('QuickBooks not keeping the Fleetbase tax fails the invoice and it is not sent again unchanged', function () {
    [$engine, $client, $ledger] = Company::us();
    $client->keepsSentTax       = false;
    $settings                   = Company::settings();

    $first         = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $client->calls = [];
    $second        = Company::again($engine, $ledger, $settings, time() + 10);

    expect($first['failed'])->toBe(1)
        ->and(Primary::notes($ledger)[0] ?? '')->toContain('QuickBooks did not keep the tax Fleetbase sent')
        ->and(Primary::notes($ledger)[0] ?? '')->toContain('1.50')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and(Company::invoiceLink($ledger)['tax_source'] ?? null)->toBe('fleetbase')
        ->and($second['failed'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('outside the US the Fleetbase tax is sent per QuickBooks rate of the line code', function () {
    [$engine, $client, $ledger] = Company::canada();
    $settings                   = Company::settings();

    $first  = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $sent   = $client->sentInvoices[0];
    $second = Company::again($engine, $ledger, $settings, time() + 10);

    expect($first['created'])->toBe(1)
        ->and($sent['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '4'])
        ->and($sent['TxnTaxDetail'])->toBe(['TotalTax' => '12.00', 'TaxLine' => [
            InvoiceLines::rateTax('5.00', '5', 'gst', '100.00'),
            InvoiceLines::rateTax('7.00', '7', 'pst', '100.00'),
        ]])
        ->and($ledger->invoices['inv-1']['tax'])->toBe(1200)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_amount'])->toBe(1200)
        ->and($client->taxRateReads)->toBe(1)
        ->and($second['aligned'])->toBe(1);
});

test('a line whose QuickBooks tax code has another rate fails before anything is sent', function () {
    [$engine, $client, $ledger] = Company::canada([
        'tax'   => 1300,
        'total' => 11300,
        'items' => [Company::line(10000, '13.00', 1300)],
    ]);

    $batch = $engine->runScheduled($ledger, 'company-uuid', Company::settings(), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->invoiceCreates)->toBe(0)
        ->and(Primary::notes($ledger)[0] ?? '')->toContain('adds up to 12%');
});

test('with sales tax off in QuickBooks the invoice is sent without tax and Fleetbase keeps its tax', function () {
    [$engine, $client, $ledger] = Company::us();
    $client->salesTax           = ['mode' => 'off', 'automated' => false, 'rate' => null];
    $settings                   = Company::settings();

    $first  = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $second = Company::again($engine, $ledger, $settings, time() + 10);

    expect($first['created'])->toBe(1)
        ->and($client->sentInvoices[0])->not->toHaveKey('TxnTaxDetail')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and(Primary::notes($ledger)[0] ?? '')->toContain('Sales tax is off in QuickBooks')
        ->and($second['aligned'])->toBe(1);
});

test('invoices only from QuickBooks keep the Fleetbase rate of each line', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::link($client, $ledger, '2', [
        'TotalAmt'     => '21.60',
        'Line'         => [
            InvoiceLines::coded('1', 'Delivery', '20.00', 'TAX'),
            InvoiceLines::coded('2', 'Waiting time', '5.00', 'TAX'),
        ],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '2.00'],
    ], true);
    $client->invoices['qb-1']['TotalAmt'] = '27.00';

    $engine->runScheduled($ledger, 'company-uuid', Company::settings(['invoice_direction' => 'inbound']), time());
    $items = $ledger->invoices['inv-1']['items'];

    expect($client->calls)->not->toContain('updateInvoice')
        ->and(array_column($items, 'tax_rate'))->toBe(['15.00', '0.00'])
        ->and(array_column($items, 'tax_amount'))->toBe([300, 0])
        ->and($ledger->invoices['inv-1']['tax'])->toBe(300)
        ->and($ledger->invoices['inv-1']['total'])->toBe(2800)
        ->and(Primary::notes($ledger))->toContain(
            'QuickBooks added lines to this invoice. Fleetbase works out tax for this organization, so the new'
                . ' lines have no tax in Fleetbase until a tax rate is set on them.'
        );
});

test('invoices sent together keep their Fleetbase tax', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::second($ledger);
    $settings = Company::settings();

    $first  = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $second = Company::again($engine, $ledger, $settings, time() + 10, ['inv-1', 'inv-2']);
    $marks  = array_column(
        array_filter($ledger->links, static fn (array $link): bool => $link['local_type'] === 'invoice'),
        'tax_source'
    );

    expect($first['created'])->toBe(2)
        ->and(array_column($client->sentInvoices, 'TxnTaxDetail'))->each->toBe([
            'TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '1.50',
        ])
        ->and($marks)->toBe(['fleetbase', 'fleetbase'])
        ->and($ledger->invoices['inv-2']['tax'])->toBe(150)
        ->and($second['aligned'])->toBe(2)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('invoices sent together whose tax QuickBooks did not keep fail and are not sent again unchanged', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::second($ledger);
    $client->keepsSentTax = false;
    $settings             = Company::settings();

    $first         = $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $client->calls = [];
    $second        = Company::again($engine, $ledger, $settings, time() + 10, ['inv-1', 'inv-2']);

    expect($first['failed'])->toBe(2)
        ->and(Primary::notes($ledger)[1] ?? '')->toContain('QuickBooks did not keep the tax Fleetbase sent')
        ->and($ledger->invoices['inv-2']['tax'])->toBe(150)
        ->and($second['failed'])->toBe(2)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a QuickBooks invoice changed to amounts that include tax fails and Fleetbase keeps its lines', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::link($client, $ledger, '2', [
        'TotalAmt'             => '11.50',
        'GlobalTaxCalculation' => 'TaxInclusive',
        'Line'                 => [InvoiceLines::coded('1', 'Delivery', '11.50', 'TAX')],
        'TxnTaxDetail'         => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.85'],
    ], true, '1');
    $settings = Primary::shippedSettings(['tax_source' => 'fleetbase']);

    $batch = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($batch['failed'])->toBe(1)
        ->and(Primary::notes($ledger)[0] ?? '')->toContain('include tax')
        ->and($ledger->invoices['inv-1']['items'][0]['amount'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_rate'])->toBe('15.00')
        ->and($ledger->invoices['inv-1']['tax'])->toBe(150)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a webhook for a QuickBooks invoice with amounts that include tax copies nothing and queues it', function () {
    [$engine, $client, $ledger] = Company::us();
    Company::link($client, $ledger, '2', [
        'TotalAmt'             => '11.50',
        'GlobalTaxCalculation' => 'TaxInclusive',
        'Line'                 => [InvoiceLines::coded('1', 'Delivery', '11.50', 'TAX')],
        'TxnTaxDetail'         => ['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '0.85'],
    ], true, '1');
    $ledger->pending = [];
    $settings        = Primary::shippedSettings(['tax_source' => 'fleetbase']);
    $entities        = [['entity' => 'Invoice', 'id' => 'qb-1', 'operation' => 'Update']];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, $settings, time());

    expect($ledger->invoices['inv-1']['items'][0]['amount'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['items'][0]['tax_rate'])->toBe('15.00')
        ->and(array_column($ledger->pending, 'local_uuid'))->toContain('inv-1');
});

test('an invoice whose lost create is found under its reserved number keeps its Fleetbase tax', function () {
    $previousCache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
    try {
        $client                     = new LostCreateQuickBooks();
        $client->customTxnNumbers   = true;
        $client->loseInvoiceCreates = 1;
        [$engine, , $ledger]        = Company::us([], $client);
        $settings                   = Company::settings(['invoice_reference' => 'quickbooks']);

        $first = $engine->runScheduled($ledger, 'company-uuid', $settings, 1_000);
        Company::again($engine, $ledger, $settings, 1_000_000);

        expect($first['failed'])->toBe(1)
            ->and($client->invoiceCreates)->toBe(1)
            ->and(Company::invoiceLink($ledger)['tax_source'] ?? null)->toBe('fleetbase');
    } finally {
        Cache::swap($previousCache);
    }
});
