<?php

use Fleetbase\Quickbooks\Support\ProductMapper;

/*
 * Storefront products and Fleet-Ops fees become QuickBooks products, kept up to date one way.
 * QuickBooks product names are unique, at most 100 characters, and ":" marks a sub-product.
 */

test('a product name is trimmed, loses the characters QuickBooks reads as structure, and fits in 100', function () {
    expect(ProductMapper::name("  Iced\tcoffee:  large \n"))->toBe('Iced coffee large')
        ->and(mb_strlen(ProductMapper::name(str_repeat('é', 150))))->toBe(100)
        ->and(ProductMapper::name('', 'PRD123'))->toBe('Product PRD123')
        ->and(ProductMapper::name(null))->toBe('Product');
});

test('names match whatever their case or spacing', function () {
    expect(ProductMapper::key(' Iced  COFFEE '))->toBe(ProductMapper::key('iced coffee'))
        ->and(ProductMapper::key('Iced coffee'))->not->toBe(ProductMapper::key('Iced tea'));
});

test('Fleet-Ops and Ledger fee labels map to one QuickBooks product each', function () {
    expect(ProductMapper::feeName('Base Fee'))->toBe('Base Fee')
        ->and(ProductMapper::feeName('base fee'))->toBe('Base Fee')
        ->and(ProductMapper::feeName('Service Fee'))->toBe('Service Fee')
        ->and(ProductMapper::feeName('Service fee'))->toBe('Service Fee')
        ->and(ProductMapper::feeName('Cash on delivery fee'))->toBe('Cash on delivery fee')
        ->and(ProductMapper::feeName('Peak hours fee'))->toBe('Peak hours fee')
        ->and(ProductMapper::feeName('Delivery fee'))->toBe('Delivery fee')
        ->and(ProductMapper::feeName('Small parcel fee'))->toBe('Parcel fee')
        ->and(ProductMapper::feeName('Downtown distance charge (3.2 km x $1.00)'))->toBe('Distance charge')
        ->and(ProductMapper::feeName('Out-of-zone distance charge (12 mi x $2.00)'))->toBe('Distance charge')
        ->and(ProductMapper::feeName('Coffee'))->toBeNull()
        ->and(ProductMapper::feeName('Delivery service — Order ORD123'))->toBeNull()
        ->and(ProductMapper::feeName('parcel fee'))->toBeNull();
});

test('each product name of a company has one fixed link id, whatever its case', function () {
    $id = ProductMapper::itemUuid('company-1', 'Base Fee');

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(ProductMapper::itemUuid('company-1', ' base  fee'))->toBe($id)
        ->and(ProductMapper::itemUuid('company-1', 'Service Fee'))->not->toBe($id)
        ->and(ProductMapper::itemUuid('company-2', 'Base Fee'))->not->toBe($id);
});

test('fee products are known by name whatever their case', function () {
    expect(ProductMapper::feeProduct('base fee'))->toBe('Base Fee')
        ->and(ProductMapper::feeProduct('PARCEL FEE'))->toBe('Parcel fee')
        ->and(ProductMapper::feeProduct('distance charge'))->toBe('Distance charge')
        ->and(ProductMapper::feeProduct('Small parcel fee'))->toBeNull()
        ->and(ProductMapper::feeProduct('Coffee'))->toBeNull();
});

test('a name another QuickBooks product holds gets a suffix and still fits in 100', function () {
    expect(ProductMapper::suffixed('Coffee'))->toBe('Coffee (Fleetbase)')
        ->and(mb_strlen(ProductMapper::suffixed(str_repeat('a', 100))))->toBe(100)
        ->and(ProductMapper::suffixed(str_repeat('a', 100)))->toEndWith(' (Fleetbase)');
});

test('the name of several products is the one they agree on, else the first in order', function () {
    expect(ProductMapper::sharedName([['name' => 'Mug'], ['name' => ' Mug ']]))->toBe('Mug')
        ->and(ProductMapper::sharedName([['name' => 'mug'], ['name' => 'Mug']]))->toBe('Mug')
        ->and(ProductMapper::sharedName([]))->toBeNull();
});

test('a new product copies the account and tax settings of the service item', function () {
    $mapper  = new ProductMapper();
    $service = [
        'IncomeAccountRef' => ['value' => '79', 'name' => 'Sales'],
        'Taxable'          => true,
        'SalesTaxCodeRef'  => ['value' => '3'],
    ];
    $payload = $mapper->create('Gift wrap', [[
        'name'        => 'Gift wrap',
        'description' => 'Wrapped by hand',
        'sku'         => 'GW-1',
        'price'       => 250,
        'currency'    => 'USD',
        'is_service'  => true,
    ]], 'USD', $service);

    expect($payload)->toBe([
        'Name'             => 'Gift wrap',
        'Type'             => 'Service',
        'Description'      => 'Wrapped by hand',
        'Sku'              => 'GW-1',
        'UnitPrice'        => '2.50',
        'IncomeAccountRef' => ['value' => '79'],
        'Taxable'          => true,
        'SalesTaxCodeRef'  => ['value' => '3'],
    ]);
});

test('goods become a non-inventory product and a fee a service with no price', function () {
    $mapper = new ProductMapper();
    $goods  = $mapper->create(
        'Mug',
        [['name' => 'Mug', 'price' => 1200, 'currency' => '', 'is_service' => false]],
        'USD',
        []
    );
    $fee    = $mapper->create('Base Fee', [], 'USD', ['IncomeAccountRef' => ['value' => '79']]);

    expect($goods['Type'])->toBe('NonInventory')
        ->and($goods['UnitPrice'])->toBe('12.00')
        ->and($goods)->not->toHaveKeys(['Description', 'Sku', 'IncomeAccountRef', 'Taxable'])
        ->and($fee)->toBe(['Name' => 'Base Fee', 'Type' => 'Service', 'IncomeAccountRef' => ['value' => '79']]);
});

test('the price is sent only in the QuickBooks home currency, in its own minor units', function () {
    $mapper = new ProductMapper();
    $price  = fn (string $currency, string $home): ?string => $mapper->create(
        'Mug',
        [['name' => 'Mug', 'price' => 1200, 'currency' => $currency]],
        $home,
        []
    )['UnitPrice'] ?? null;

    expect($price('EUR', 'USD'))->toBeNull()
        ->and($price('JPY', 'JPY'))->toBe('1200.00')
        ->and($price('usd', 'USD'))->toBe('12.00');
});

test('long descriptions and skus are cut to what QuickBooks takes', function () {
    $payload = (new ProductMapper())->create(
        'Mug',
        [['name' => 'Mug', 'description' => str_repeat('d', 5000), 'sku' => str_repeat('s', 150)]],
        'USD',
        []
    );

    expect(mb_strlen($payload['Description']))->toBe(4000)
        ->and(mb_strlen($payload['Sku']))->toBe(100);
});

test('products sharing a name send only the fields they agree on', function () {
    $mapper   = new ProductMapper();
    $products = [
        ['name' => 'Mug', 'description' => 'Blue', 'sku' => 'M-1', 'price' => 1200, 'currency' => 'USD'],
        ['name' => 'mug', 'description' => 'Blue', 'sku' => 'M-2', 'price' => 1200, 'currency' => 'EUR'],
    ];

    expect($mapper->create('Mug', $products, 'USD', []))->toBe([
        'Name'        => 'Mug',
        'Type'        => 'NonInventory',
        'Description' => 'Blue',
    ]);
});

test('an update sends only what Fleetbase changed, clears included, and never the product type', function () {
    $mapper  = new ProductMapper();
    $product = [
        'name'        => 'Mug',
        'description' => '',
        'sku'         => 'M-2',
        'price'       => 1500,
        'currency'    => 'USD',
        'is_service'  => true,
    ];
    $remote  = [
        'Name'        => 'Mug',
        'Type'        => 'NonInventory',
        'Description' => 'Old text',
        'Sku'         => 'M-1',
        'UnitPrice'   => '12.00',
    ];
    $same    = ['Name' => 'Mug', 'Sku' => 'M-2', 'UnitPrice' => '15'];

    expect($mapper->changes(
        'Mug',
        [$product],
        $remote,
        'USD'
    ))->toBe(['Description' => '', 'Sku' => 'M-2', 'UnitPrice' => '15.00'])
        ->and($mapper->changes('Mug', [$product], $same, 'USD'))->toBe([]);
});

test('an update sends a change of case, and keeps the suffix of a name QuickBooks already had', function () {
    $mapper = new ProductMapper();

    expect($mapper->changes('Iced Tea', [['name' => 'Iced Tea']], ['Name' => 'Iced tea', 'UnitPrice' => '0'], 'USD'))
        ->toBe(['Name' => 'Iced Tea'])
        ->and($mapper->changes('Mug', [['name' => 'Mug']], ['Name' => 'Mug (Fleetbase)', 'UnitPrice' => '0'], 'USD'))
        ->toBe([])
        ->and($mapper->changes('MUG', [['name' => 'MUG']], ['Name' => 'Mug (Fleetbase)', 'UnitPrice' => '0'], 'USD'))
        ->toBe(['Name' => 'MUG (Fleetbase)']);
});

test('products sharing a name in different cases leave the QuickBooks name as it is', function () {
    $mapper   = new ProductMapper();
    $products = [['name' => 'Mug'], ['name' => 'MUG']];

    expect($mapper->changes('Mug', $products, ['Name' => 'mug', 'UnitPrice' => '0'], 'USD'))->toBe([]);
});

test('a price in another currency is never compared', function () {
    $product = ['name' => 'Mug', 'price' => 1500, 'currency' => 'EUR'];

    expect((new ProductMapper())->changes(
        'Mug',
        [$product],
        ['Name' => 'Mug', 'UnitPrice' => '9.00'],
        'USD'
    ))->toBe([]);
});

test('a fee product is never updated', function () {
    expect((new ProductMapper())->changes(
        'Base Fee',
        [],
        ['Name' => 'Base fee', 'UnitPrice' => '3.00'],
        'USD'
    ))->toBe([]);
});
