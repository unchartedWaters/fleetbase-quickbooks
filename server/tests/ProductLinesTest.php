<?php

use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;
use Fleetbase\Quickbooks\Tests\Support\Products;

/*
 * An invoice line uses the QuickBooks product of its name: a live Storefront product's, else a
 * Fleet-Ops fee's, else the "Fleetbase service" item.
 */

test('a new invoice puts each line on its product, fee or the service item', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200, 'Base Fee' => 500, 'Delivery' => 300]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $mug   = Products::linkOf($ledger, 'Mug')['qbo_id'];
    $fee   = Products::linkOf($ledger, 'Base Fee')['qbo_id'];

    expect($batch['created'])->toBe(1)
        ->and($client->itemCreates)->toBe(2)
        ->and($client->items[$fee]['Type'])->toBe('Service')
        ->and($client->items[$fee])->not->toHaveKey('UnitPrice')
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe([$mug, $fee, 'item-1']);
});

test('a line taxes by its own product', function () {
    [$engine, $client]       = qbEngine();
    $client->salesTax        = ['mode' => 'us', 'automated' => false, 'rate' => '2'];
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200, 'Delivery' => 300]);
    $client->items['qb-5']   = Products::item('qb-5', 'Mug', ['Taxable' => false]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $codes = array_map(
        static fn (array $line): string => (string) ($line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? ''),
        $client->invoices['inv-1']['Line']
    );

    expect(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-5', 'item-1'])
        ->and($codes)->toBe(['NON', 'TAX'])
        ->and($client->itemReads)->toBe(1);
});

test('a single invoice makes the products of its lines first', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    $created = array_search('createItem', $client->calls, true);

    expect($created)->toBeLessThan(array_search('createInvoice', $client->calls, true))
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-item-1']);
});

test('invoices of one block share the product of a name', function () {
    [$engine, $client]         = qbEngine();
    $ledger                    = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1']   = Products::product('p-1', 'Mug');
    $second                    = $ledger->invoices['inv-1'];
    $second['uuid']            = 'inv-2';
    $second['number']          = 'INV-2';
    $ledger->invoices['inv-2'] = $second;
    $ledger->pending[]         = Products::pending('invoice', 'inv-2');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(2)
        ->and($client->itemCreates)->toBe(1)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-item-1'])
        ->and(Products::lineItems($client->invoices['inv-2']))->toBe(['qb-item-1']);
});

test('a matched line on the service item moves to its product, though nothing else changed', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1']  = Products::product('p-1', 'Mug');
    $client->items['qb-9']    = Products::item('qb-9', 'Mug');
    $ledger->links[]          = Products::link('Mug', 'qb-9');
    $client->invoices['qb-1'] = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => '12.00',
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'Line' => [InvoiceLines::sales('1', 'Mug', '12.00')],
    ];
    $ledger->links[]                                      = [
        'local_type' => 'invoice',
        'local_uuid' => 'inv-1',
        'qbo_entity' => 'Invoice',
        'qbo_id'     => 'qb-1',
    ] + Products::link('x', 'x');
    $ledger->invoices['inv-1']['items'][0]['qbo_line_id'] = '1';

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['qb-9'])
        ->and($client->invoices['qb-1']['Line'][0]['Id'])->toBe('1');
});

test('a matched line keeps a product chosen in QuickBooks', function () {
    [$engine, $client]                      = qbEngine();
    $ledger                                 = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1']                = Products::product('p-1', 'Mug');
    $client->items['qb-9']                  = Products::item('qb-9', 'Mug');
    $ledger->links[]                        = Products::link('Mug', 'qb-9');
    $line                                   = InvoiceLines::sales('1', 'Mug', '12.00');
    $line['SalesItemLineDetail']['ItemRef'] = ['value' => '55', 'name' => 'Ceramics: Mug'];
    $client->invoices['qb-1']               = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => '12.00',
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'Line' => [$line],
    ];
    $ledger->links[]                                      = [
        'local_type' => 'invoice',
        'local_uuid' => 'inv-1',
        'qbo_entity' => 'Invoice',
        'qbo_id'     => 'qb-1',
    ] + Products::link('x', 'x');
    $ledger->invoices['inv-1']['items'][0]['qbo_line_id'] = '1';

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a matched line keeps its inactive product once its Fleetbase product is gone', function () {
    [$engine, $client]                      = qbEngine();
    $ledger                                 = Products::invoiceLedger($client, ['Mug' => 1200, 'Delivery' => 300]);
    $client->items['qb-9']                  = Products::item('qb-9', 'Mug (deleted)', ['Active' => false]);
    $ledger->links[]                        = Products::link('Mug', 'qb-9', 'deactivated');
    $line                                   = InvoiceLines::sales('1', 'Mug', '12.00');
    $line['SalesItemLineDetail']['ItemRef'] = ['value' => 'qb-9', 'name' => 'Mug (deleted)'];
    $client->invoices['qb-1']               = [
        'Id'      => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'INV-1', 'TotalAmt' => '12.00',
        'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'Line' => [$line],
    ];
    $ledger->links[]                                      = [
        'local_type' => 'invoice',
        'local_uuid' => 'inv-1',
        'qbo_entity' => 'Invoice',
        'qbo_id'     => 'qb-1',
    ] + Products::link('x', 'x');
    $ledger->invoices['inv-1']['items'][0]['qbo_line_id'] = '1';

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['qb-9', 'item-1'])
        ->and($client->itemWrites)->toBe([]);
});

test('a line whose product QuickBooks refuses uses the service item and says so', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $client->failItemCreate  = new QuickBooksException(400, 'Invalid account', null, '2050');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['item-1'])
        ->and(qbText($ledger->attempts[0]['error']))->toContain('Mug');
});

test('a temporary failure making a line product fails the invoice to try again', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $client->failItemCreate  = new QuickBooksException(503, 'Service unavailable');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($ledger->pending[0]['status'])->toBe('pending');
});

test('a draft or deleted product does not give its name to a line', function (array $state) {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug', $state);

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemCreates)->toBe(0)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['item-1']);
})->with([
    'draft'         => [['status' => 'draft']],
    'deleted'       => [['deleted_at' => '2026-10-23 10:00:00']],
    'other company' => [['company_uuid' => 'company-2']],
]);

test('invoices sent only from QuickBooks make no products', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200, 'Base Fee' => 100]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');

    $engine->runScheduled(
        $ledger,
        Products::COMPANY,
        qbSettings(['interval_minutes' => 1, 'invoice_direction' => 'inbound']),
        time()
    );

    expect($client->itemCreates)->toBe(0)
        ->and($client->calls)->not->toContain('batch');
});

test('parcel and distance fee lines each use their own fee product', function () {
    [$engine, $client] = qbEngine();
    $ledger            = Products::invoiceLedger($client, [
        'Small parcel fee'                          => 300,
        'Downtown distance charge (3.2 km x $1.00)' => 320,
    ]);

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $parcel   = (string) (Products::linkOf($ledger, 'Parcel fee')['qbo_id'] ?? '');
    $distance = (string) (Products::linkOf($ledger, 'Distance charge')['qbo_id'] ?? '');

    expect($parcel)->not->toBe('')
        ->and($distance)->not->toBe('')
        ->and($client->items[$parcel]['Name'])->toBe('Parcel fee')
        ->and($client->items[$distance]['Name'])->toBe('Distance charge')
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe([$parcel, $distance]);
});

test('the invoices of one run read and make their products together', function () {
    [$engine, $client] = qbEngine();
    $ledger            = Products::invoiceLedger($client, ['Mug' => 1200]);
    foreach (['Mug' => 'inv-1', 'Cup' => 'inv-2', 'Jar' => 'inv-3'] as $name => $uuid) {
        $ledger->products['p-' . $name]     = Products::product('p-' . $name, $name);
        $invoice                            = $ledger->invoices['inv-1'];
        $invoice['uuid']                    = $uuid;
        $invoice['number']                  = strtoupper($uuid);
        $invoice['items'][0]['description'] = $name;
        $ledger->invoices[$uuid]            = $invoice;
        if ($uuid !== 'inv-1') {
            $ledger->pending[] = Products::pending('invoice', $uuid);
        }
    }

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $written = array_slice($client->calls, 0, (int) array_search('createInvoice', $client->calls, true));

    expect(array_count_values($written))->toBe([
        'batch'        => 4,
        'batchChunk:3' => 2,
        'createItem'   => 3,
    ])->and(Products::lineItems($client->invoices['inv-3']))->toBe([Products::linkOf($ledger, 'Jar')['qbo_id']]);
});

test('a matched line keeps its product once its Fleetbase product is gone, while it is still active', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Mug' => 1200]);
    $client->items['qb-9'] = Products::item('qb-9', 'Mug');
    $ledger->links[]       = Products::link('Mug', 'qb-9', 'adopted');
    Products::remoteInvoice($client, $ledger, [['Mug', '12.00', 'qb-9']]);

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice')
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['qb-9']);
});

test('a matched line whose text no longer names its product moves to the service item', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Gift wrap' => 1200]);
    $client->items['qb-9'] = Products::item('qb-9', 'Mug');
    $ledger->links[]       = Products::link('Mug', 'qb-9');
    Products::remoteInvoice($client, $ledger, [['Mug', '12.00', 'qb-9']]);

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['item-1']);
});

test('a product with no name gives no line its product', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Product' => 1200]);
    $ledger->products['p-1'] = Products::product('p-1', '');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['item-1']);
});

test('a matched fee line keeps its inactive fee product, whatever the fee label', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Small parcel fee' => 300]);
    $client->items['qb-7'] = Products::item('qb-7', 'Parcel fee (deleted)', ['Active' => false, 'Type' => 'Service']);
    $ledger->links[]       = Products::link('Parcel fee', 'qb-7');
    Products::remoteInvoice($client, $ledger, [['Small parcel fee', '3.00', 'qb-7']]);

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['qb-7']);
});
