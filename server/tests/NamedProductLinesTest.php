<?php

use Fleetbase\Quickbooks\Services\ProductItems;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;
use Fleetbase\Quickbooks\Tests\Support\Products;

/*
 * A line can name its product in the Ledger line's meta (meta.product.name), as a pricing
 * extension does. It then uses the QuickBooks product of that name, whatever its text: the one
 * QuickBooks has, else one Fleetbase makes like a fee's product.
 */

test('a line naming its product goes on the QuickBooks product of that name', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Distance (10 km)' => 3500, 'Delivery' => 300]);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance', [
        'Type'            => 'Service',
        'SalesTaxCodeRef' => ['value' => '5'],
    ]);
    Products::nameLine($ledger, 0, 'Distance');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and($client->itemCreates)->toBe(0)
        ->and($client->itemWrites)->toBe([])
        ->and(Products::linkOf($ledger, 'Distance')['state'])->toBe(ProductItems::ADOPTED)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-7', 'item-1'])
        ->and($client->invoices['inv-1']['Line'][0]['Description'])->toBe('Distance (10 km)');
});

test('a named product QuickBooks lacks is made like a fee product and kept as a line product', function () {
    [$engine, $client] = qbEngine();
    $ledger            = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    Products::nameLine($ledger, 0, 'Distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $link = Products::linkOf($ledger, 'Distance');
    $made = $client->items[$link['qbo_id']];

    expect($client->itemCreates)->toBe(1)
        ->and($made['Name'])->toBe('Distance')
        ->and($made['Type'])->toBe('Service')
        ->and($made)->not->toHaveKey('UnitPrice')
        ->and($made)->not->toHaveKey('Description')
        ->and($made['IncomeAccountRef'])->toBe(['value' => '79'])
        ->and($made['Taxable'])->toBeTrue()
        ->and($link['state'])->toBe(ProductItems::LINE)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe([$link['qbo_id']]);
});

test('lines naming one product share its QuickBooks product whatever their text', function () {
    [$engine, $client] = qbEngine();
    $ledger            = Products::invoiceLedger($client, ['Distance (10 km)' => 3500, 'Distance (4 km)' => 1400]);
    Products::nameLine($ledger, 0, 'Distance');
    Products::nameLine($ledger, 1, 'distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $made = Products::linkOf($ledger, 'Distance')['qbo_id'];

    expect($client->itemCreates)->toBe(1)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe([$made, $made]);
});

test('a named line takes its product over a Storefront product of its text', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::invoiceLedger($client, ['Mug' => 1200]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $client->items['qb-7']   = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    Products::nameLine($ledger, 0, 'Distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-7'])
        ->and($client->itemCreates)->toBe(0);
});

test('a named line is sent while Storefront products cannot be read', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    $ledger->productsUnavailable = true;
    $client->items['qb-7']       = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    Products::nameLine($ledger, 0, 'Distance');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-7']);
});

test('a line naming the service item uses it', function () {
    [$engine, $client] = qbEngine();
    $ledger            = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    Products::nameLine($ledger, 0, 'Fleetbase service');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemCreates)->toBe(0)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['item-1']);
});

test('a product name row does not make a line product inactive', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::ledger($client);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $ledger->links[]       = Products::link('Distance', 'qb-7', ProductItems::LINE);
    $ledger->pending[]     = Products::namePending('Distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and($client->items['qb-7']['Active'])->toBeTrue();
});

test('a line product made inactive in QuickBooks stays inactive and its lines use the service item', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance (deleted)', ['Type' => 'Service', 'Active' => false]);
    $ledger->links[]       = Products::link('Distance', 'qb-7', ProductItems::LINE);
    Products::nameLine($ledger, 0, 'Distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['item-1'])
        ->and(qbText($ledger->attempts[0]['error']))->toContain('Distance');
});

test('a matched line moves to the product its line now names, though nothing else changed', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $client->items['qb-8'] = Products::item('qb-8', 'Express distance', ['Type' => 'Service']);
    $ledger->links[]       = Products::link('Distance', 'qb-7', ProductItems::LINE);
    Products::remoteInvoice($client, $ledger, [['Distance (10 km)', '35.00', 'qb-7']]);
    Products::nameLine($ledger, 0, 'Express distance');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['updated'])->toBe(1)
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['qb-8'])
        ->and($client->invoices['qb-1']['Line'][0]['Id'])->toBe('1');
});

test('a matched line stays on its named product', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $ledger->links[]       = Products::link('Distance', 'qb-7', ProductItems::LINE);
    Products::remoteInvoice($client, $ledger, [['Distance (10 km)', '35.00', 'qb-7']]);
    Products::nameLine($ledger, 0, 'Distance');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('lines copied back from QuickBooks keep the product their Fleetbase line named', function () {
    $held    = static fn (string $invoice, string $id): array => ['realm' => 'r', 'invoice' => $invoice, 'id' => $id];
    $current = [
        ['description' => 'Distance (10 km)', 'qbo_line_id' => '1', 'product' => ['name' => 'Distance', 'code' => 'D']],
        ['description' => 'Delivery', 'qbo_line' => $held('qb-1', '2')],
        // Held for another QuickBooks invoice, so it does not pair.
        ['description' => 'Old', 'qbo_line' => $held('qb-0', '3'), 'product' => ['name' => 'Old']],
    ];
    $remote  = ['Id' => 'qb-1', 'Line' => [
        InvoiceLines::sales('2', 'Delivery', '3.00'),
        InvoiceLines::sales('1', 'Distance (10 km)', '40.00'),
        InvoiceLines::sales('3', 'Added in QuickBooks', '1.00'),
    ]];
    $mapped  = (new InvoiceMapper())->fromQuickBooks($remote, $current);

    $items = (new InvoiceMapper())->withLineProducts($mapped['items'], $remote, $current);

    expect($items[0])->not->toHaveKey('product')
        ->and($items[1]['product'])->toBe(['name' => 'Distance', 'code' => 'D'])
        ->and($items[1]['amount'])->toBe(4000)
        ->and($items[2])->not->toHaveKey('product');
});

test('under QuickBooks primary lines copied from QuickBooks keep their product', function () {
    $line                      = static fn (string $id, string $text, string $amount): array => [
        'Id'                  => $id,
        'Amount'              => $amount,
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => $text,
        'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => $amount, 'ItemRef' => ['value' => 'qb-7']],
    ];
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['items' => [[
        'description' => 'Distance (10 km)', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000,
        'qbo_line_id' => '1', 'product' => ['name' => 'Distance', 'code' => 'D'],
    ]]], [
        'SyncToken' => '2',
        'TotalAmt'  => '15.00',
        'Line'      => [$line('1', 'Distance (10 km)', '12.00'), $line('2', 'Waiting', '3.00')],
    ]);
    $client->items['item-1'] = Products::item('item-1', 'Fleetbase service', ['Type' => 'Service']);
    $client->items['qb-7']   = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $ledger->links[]         = Products::link('Distance', 'qb-7', ProductItems::LINE);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    $items = $ledger->invoices['inv-1']['items'];

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['items_from_quickbooks'])->toBeTrue()
        ->and(array_column($items, 'amount'))->toBe([1200, 300])
        ->and($items[0]['product'])->toBe(['name' => 'Distance', 'code' => 'D'])
        ->and($items[1]['product'])->toBe(['name' => 'Distance']);
});

test('under QuickBooks primary a line that names another product moves, though nothing else changed', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['items' => [[
        'description' => 'Delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000,
        'qbo_line_id' => '1', 'product' => ['name' => 'Express distance'],
    ]]], ['Line' => [[
        'Id'                  => '1',
        'Amount'              => '10.00',
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => 'Delivery',
        'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00', 'ItemRef' => ['value' => 'qb-7']],
    ]]]);
    $client->items['item-1'] = Products::item('item-1', 'Fleetbase service', ['Type' => 'Service']);
    $client->items['qb-7']   = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $client->items['qb-8']   = Products::item('qb-8', 'Express distance', ['Type' => 'Service']);
    $ledger->links[]         = Products::link('Distance', 'qb-7', ProductItems::LINE);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateInvoice')
        ->and(Products::lineItems($client->invoices['qb-1']))->toBe(['qb-8']);
});

test('a named product does not take over a QuickBooks product another record uses', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $other                 = ['company_uuid' => 'other', 'local_uuid' => 'other-item'];
    $ledger->links[]       = $other + Products::link('Distance', 'qb-7');
    Products::nameLine($ledger, 0, 'Distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $made = Products::linkOf($ledger, 'Distance')['qbo_id'];

    expect($made)->not->toBe('qb-7')
        ->and($client->items[$made]['Name'])->toBe('Distance (Fleetbase)')
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe([$made]);
});

test('a product link lines start naming is kept as a line product', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::invoiceLedger($client, ['Distance (10 km)' => 3500]);
    $client->items['qb-7'] = Products::item('qb-7', 'Distance', ['Type' => 'Service']);
    $ledger->links[]       = Products::link('Distance', 'qb-7');
    Products::nameLine($ledger, 0, 'Distance');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and(Products::linkOf($ledger, 'Distance')['state'])->toBe(ProductItems::LINE)
        ->and(Products::lineItems($client->invoices['inv-1']))->toBe(['qb-7']);
});

test('a line product name is cleaned like any product name', function (mixed $name, ?string $expected) {
    expect(ProductMapper::lineProduct(['product' => ['name' => $name]]))->toBe($expected);
})->with([
    'colon'     => ['Zone: A', 'Zone A'],
    'long'      => [str_repeat('a', 120), str_repeat('a', 100)],
    'blank'     => [' : ', null],
    'not text'  => [12, null],
]);
