<?php

use Fleetbase\Quickbooks\Services\ProductItems;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\Products;

/*
 * Storefront products become QuickBooks products, one per product name, kept up to date one way.
 * Products follow invoices: nothing is sent unless invoices are sent to QuickBooks.
 */

test('a new product becomes a QuickBooks product posting and taxed like the service item', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = Products::ledger($client);
    $ledger->products['p-1']    = Products::product(
        'p-1',
        'Iced coffee',
        ['description' => 'Large', 'sku' => 'IC-1', 'price' => 450]
    );
    $ledger->pending[]          = Products::pending('product', 'p-1');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $link  = Products::linkOf($ledger, 'Iced coffee');

    expect($batch['created'])->toBe(1)
        ->and($client->itemWrites)->toBe([['create' => [
            'Name'             => 'Iced coffee',
            'Type'             => 'NonInventory',
            'Description'      => 'Large',
            'Sku'              => 'IC-1',
            'UnitPrice'        => '4.50',
            'IncomeAccountRef' => ['value' => '79'],
            'Taxable'          => true,
        ]]])
        ->and($link['qbo_id'])->toBe('qb-item-1')
        ->and($link['name'])->toBe('Iced coffee')
        ->and($link['state'])->toBeNull()
        ->and($ledger->pending[0]['status'])->toBe('done');
});

test('an unchanged product is not written again, and a new price is sent alone', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = Products::ledger($client);
    $client->items['qb-9']      = Products::item('qb-9', 'Mug');
    $ledger->links[]            = Products::link('Mug', 'qb-9');
    $ledger->products['p-1']    = Products::product('p-1', 'Mug');
    $ledger->pending[]          = Products::pending('product', 'p-1');

    $first = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    $ledger->products['p-1']['price']                        = 1500;
    $ledger->pending[0]['status']                            = 'pending';
    $ledger->connections[Products::COMPANY]['last_batch_at'] = null;
    $second                                                  = $engine->runScheduled(
        $ledger,
        Products::COMPANY,
        qbSettings(['interval_minutes' => 1]),
        time() + 120
    );

    expect($first['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(1)
        ->and($client->itemWrites)->toBe([['update' => [
            'UnitPrice' => '15.00',
            'Id'        => 'qb-9',
            'SyncToken' => '0',
            'sparse'    => true,
        ]]])
        ->and($client->items['qb-9']['UnitPrice'])->toBe('15.00');
});

test('products with one name share one QuickBooks product, written once', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug', ['sku' => 'M-1']);
    $ledger->products['p-2'] = Products::product('p-2', ' mug ', ['sku' => 'M-2']);
    $ledger->pending[]       = Products::pending('product', 'p-1');
    $ledger->pending[]       = Products::pending('product', 'p-2');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemCreates)->toBe(1)
        ->and($batch['created'])->toBe(1)
        ->and($batch['aligned'])->toBe(1)
        ->and($client->itemWrites[0]['create'])->not->toHaveKey('Sku')
        ->and($client->itemWrites[0]['create']['Name'])->toBe('Mug');
});

test('a QuickBooks product that already has the name is used as it is and never written to', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-5']   = Products::item('qb-5', 'MUG', ['Type' => 'Service', 'UnitPrice' => '20.00']);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $batch                                                   = $engine->runScheduled(
        $ledger,
        Products::COMPANY,
        qbSettings(['interval_minutes' => 1]),
        time()
    );
    $ledger->products['p-1']['deleted_at']                   = '2026-10-23 10:00:00';
    $ledger->pending[0]['status']                            = 'pending';
    $ledger->connections[Products::COMPANY]['last_batch_at'] = null;
    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time() + 120);

    expect($batch['aligned'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toContain('MUG')
        ->and(Products::linkOf($ledger, 'Mug')['qbo_id'])->toBe('qb-5')
        ->and(Products::linkOf($ledger, 'Mug')['state'])->toBe(ProductItems::ADOPTED)
        ->and($client->itemWrites)->toBe([])
        ->and($client->items['qb-5']['Active'])->toBeTrue();
});

test('a name held by a product Fleetbase may not take gets a suffixed product of its own', function (array $taken) {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-5']   = Products::item('qb-5', 'Mug', $taken);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites[0]['create']['Name'])->toBe('Mug (Fleetbase)')
        ->and(Products::linkOf($ledger, 'Mug')['qbo_id'])->toBe('qb-item-1')
        ->and(Products::linkOf($ledger, 'Mug')['state'])->toBeNull();
})->with([
    'inventory' => [['Type' => 'Inventory']],
    'inactive'  => [['Active' => false]],
]);

test('a QuickBooks sub-product does not hold its name, so the top-level product is used', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-4']   = Products::item('qb-4', 'Mug', ['SubItem' => true, 'ParentRef' => ['value' => '3']]);
    $client->items['qb-5']   = Products::item('qb-5', 'Mug', ['Type' => 'Service']);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect(Products::linkOf($ledger, 'Mug')['qbo_id'])->toBe('qb-5')
        ->and(Products::linkOf($ledger, 'Mug')['state'])->toBe(ProductItems::ADOPTED)
        ->and($client->itemWrites)->toBe([]);
});

test('a name only a QuickBooks sub-product has gets a product of that name', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-4']   = Products::item('qb-4', 'Mug', ['SubItem' => true, 'ParentRef' => ['value' => '3']]);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites[0]['create']['Name'])->toBe('Mug')
        ->and(Products::linkOf($ledger, 'Mug')['qbo_id'])->toBe('qb-item-1');
});

test('a name another record already uses in QuickBooks is not taken over', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-5']   = Products::item('qb-5', 'Mug');
    $ledger->links[]         = [
        'local_type'   => 'item',
        'local_uuid'   => 'other',
        'company_uuid' => 'company-2',
    ] + Products::link('Mug', 'qb-5');
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites[0]['create']['Name'])->toBe('Mug (Fleetbase)');
});

test('the last product of a name gone makes its product inactive, and it comes back with the name', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-9']   = Products::item('qb-9', 'Mug');
    $ledger->links[]         = Products::link('Mug', 'qb-9');
    $ledger->products['p-1'] = Products::product('p-1', 'Mug', ['deleted_at' => '2026-10-23 10:00:00']);
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $deleted = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());
    $off     = $client->items['qb-9'];

    $ledger->products['p-1']['deleted_at']                   = null;
    $ledger->pending[0]['status']                            = 'pending';
    $ledger->connections[Products::COMPANY]['last_batch_at'] = null;
    $restored                                                = $engine->runScheduled(
        $ledger,
        Products::COMPANY,
        qbSettings(['interval_minutes' => 1]),
        time() + 120
    );

    expect($deleted['updated'])->toBe(1)
        ->and($off['Active'])->toBeFalse()
        ->and($off['Name'])->toBe('Mug (deleted)')
        ->and($restored['updated'])->toBe(1)
        ->and($client->items['qb-9']['Active'])->toBeTrue()
        ->and($client->items['qb-9']['Name'])->toBe('Mug')
        ->and(Products::linkOf($ledger, 'Mug')['state'])->toBeNull();
});

test('a renamed product leaves its old QuickBooks product in use while another product has the name', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-9']   = Products::item('qb-9', 'Mug');
    $ledger->links[]         = Products::link('Mug', 'qb-9');
    $ledger->products['p-2'] = Products::product('p-2', 'Mug');
    $ledger->pending[]       = Products::namePending('Mug');

    $kept = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    unset($ledger->products['p-2']);
    $ledger->pending[0]['status']                            = 'pending';
    $ledger->connections[Products::COMPANY]['last_batch_at'] = null;
    $gone                                                    = $engine->runScheduled(
        $ledger,
        Products::COMPANY,
        qbSettings(['interval_minutes' => 1]),
        time() + 120
    );

    expect($kept['aligned'])->toBe(1)
        ->and($gone['updated'])->toBe(1)
        ->and($client->items['qb-9']['Active'])->toBeFalse()
        ->and(Products::linkOf($ledger, 'Mug')['state'])->toBe(ProductItems::DEACTIVATED);
});

test('a product made inactive in QuickBooks stays inactive', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-9']   = Products::item('qb-9', 'Mug (deleted)', ['Active' => false]);
    $ledger->links[]         = Products::link('Mug', 'qb-9');
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->itemWrites)->toBe([])
        ->and(qbText($ledger->attempts[0]['error']))->toContain('Mug');
});

test('a fee product is never made inactive', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::ledger($client);
    $client->items['qb-3'] = Products::item('qb-3', 'Base Fee', ['Type' => 'Service']);
    $ledger->links[]       = Products::link('Base Fee', 'qb-3');
    $ledger->pending[]     = Products::namePending('Base Fee');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and($client->items['qb-3']['Active'])->toBeTrue();
});

test('product rows close with no Activity while invoices are not sent to QuickBooks', function (array $settings) {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $batch = $engine->runScheduled(
        $ledger,
        Products::COMPANY,
        qbSettings(['interval_minutes' => 1] + $settings),
        time()
    );

    expect($client->itemWrites)->toBe([])
        ->and($client->calls)->toBe([])
        ->and($ledger->attempts)->toBe([])
        ->and($batch['skipped'] + $batch['aligned'] + $batch['created'])->toBe(0)
        ->and($ledger->pending[0]['status'])->toBe('done');
})->with([
    'invoices off'             => [['invoice_enabled' => false]],
    'invoices from QuickBooks' => [['invoice_direction' => 'inbound']],
    'invoices paused'          => [['invoice_direction' => 'off']],
]);

test('when Storefront cannot be read no QuickBooks product is made inactive', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = Products::ledger($client);
    $client->items['qb-9']       = Products::item('qb-9', 'Mug');
    $ledger->links[]             = Products::link('Mug', 'qb-9');
    $ledger->productsUnavailable = true;
    $ledger->pending[]           = Products::namePending('Mug');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->itemWrites)->toBe([])
        ->and($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['next_attempt_at'])->toBeGreaterThan(time());
});

test('a name QuickBooks refuses as taken says which product', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->failItemCreate  = new QuickBooksException(400, 'Duplicate Name Exists Error', null, '6240');
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and(qbText($ledger->attempts[0]['error']))->toContain('Mug')
        ->and(qbText($ledger->attempts[0]['error']))->toContain('QuickBooks');
});

test('a product named like the service item uses it', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $ledger->products['p-1'] = Products::product('p-1', 'fleetbase SERVICE');
    $ledger->pending[]       = Products::pending('product', 'p-1');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['aligned'])->toBe(1)
        ->and($client->itemWrites)->toBe([]);
});

test('a product that is gone with no QuickBooks product closes without a write', function () {
    [$engine, $client] = qbEngine();
    $ledger            = Products::ledger($client);
    $ledger->pending[] = Products::pending('product', 'p-missing');
    $ledger->pending[] = Products::namePending('Never synced');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and(array_column($ledger->pending, 'status'))->toBe(['done', 'done']);
});

test('a product named like a fee is a fee, and is never made inactive', function () {
    [$engine, $client]     = qbEngine();
    $ledger                = Products::ledger($client);
    $client->items['qb-3'] = Products::item('qb-3', 'Parcel fee', ['Type' => 'Service']);
    $ledger->links[]       = Products::link('Parcel fee', 'qb-3');
    $ledger->pending[]     = Products::namePending('Parcel fee');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and($client->items['qb-3']['Active'])->toBeTrue();
});

test('products read only in part are not sent', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = Products::ledger($client);
    $ledger->products['p-1']     = Products::product('p-1', 'Mug');
    $ledger->productsUnavailable = true;
    $ledger->pending[]           = Products::pending('product', 'p-1');

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->itemWrites)->toBe([])
        ->and($ledger->pending[0]['status'])->toBe('pending');
});

test('without Storefront tables no QuickBooks product is made inactive', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-9']   = Products::item('qb-9', 'Mug');
    $ledger->links[]         = Products::link('Mug', 'qb-9');
    $ledger->productsMissing = true;
    $ledger->pending[]       = Products::namePending('Mug');

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->itemWrites)->toBe([])
        ->and($client->items['qb-9']['Active'])->toBeTrue()
        ->and($ledger->pending[0]['status'])->toBe('done');
});

test('a product changed in QuickBooks since it was read is tried again', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->items['qb-9']   = Products::item('qb-9', 'Mug', ['SyncToken' => '4']);
    $ledger->links[]         = Products::link('Mug', 'qb-9');
    $ledger->products['p-1'] = Products::product('p-1', 'Mug', ['price' => 1500]);
    $ledger->pending[]       = Products::pending('product', 'p-1');
    $client->staleItemReads  = true;

    $batch = $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($engine->permanentFailures(Products::COMPANY))->toBe(0)
        ->and($client->itemWrites)->toHaveCount(1);
});

test('a connection that has not stored its home currency reads it before pricing a product', function () {
    [$engine, $client]       = qbEngine();
    $ledger                  = Products::ledger($client);
    $client->homeCurrency    = 'USD';
    $ledger->products['p-1'] = Products::product('p-1', 'Mug');
    $ledger->pending[]       = Products::pending('product', 'p-1');
    unset($ledger->connections[Products::COMPANY]['home_currency']);

    $engine->runScheduled($ledger, Products::COMPANY, qbSettings(['interval_minutes' => 1]), time());

    expect($client->items[Products::linkOf($ledger, 'Mug')['qbo_id']]['UnitPrice'] ?? null)->toBe('12.00');
});
