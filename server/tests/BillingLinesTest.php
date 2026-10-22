<?php

use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;

/*
 * How invoice lines keep what QuickBooks holds on them: the product/service, the tax code,
 * line types Fleetbase does not write, and descriptions longer than a Fleetbase line holds.
 */

test('an update sends description-only and bundle lines back so QuickBooks keeps them', function () {
    $note = [
        'Id'                    => '2',
        'DetailType'            => 'DescriptionOnly',
        'Description'           => 'Deliver to dock B',
        'DescriptionLineDetail' => [],
    ];
    $bundle = [
        'Id'              => '3',
        'Amount'          => '20.00',
        'DetailType'      => 'GroupLineDetail',
        'GroupLineDetail' => ['GroupItemRef' => ['value' => '70', 'name' => 'Kit'], 'Quantity' => 1, 'Line' => []],
    ];
    $remote  = ['Line' => [InvoiceLines::sales('1', 'Base Fee', '10.00'), $note, $bundle]];
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['items' => [['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1200]]],
        'cust-1',
        'item-1'
    );

    $merged = (new InvoiceMapper())->withExistingLineIds($payload, $remote);
    $types  = array_map(
        static fn (array $line): string => $line['DetailType'] . ':' . ($line['Id'] ?? ''),
        $merged['Line']
    );

    expect($types)->toBe(['SalesItemLineDetail:1', 'DescriptionOnly:2', 'GroupLineDetail:3'])
        // QuickBooks sends an empty detail as {}; it must go back as {}, not [], or the update is refused.
        ->and(json_encode($merged['Line'][1]['DescriptionLineDetail']))->toBe('{}')
        ->and($merged['Line'][2]['GroupLineDetail']['GroupItemRef'])->toBe(['value' => '70', 'name' => 'Kit']);
});

test('a bundle line is left out of the compared total, like an extra QuickBooks sales line', function () {
    $remote = ['TotalAmt' => '30.00', 'Line' => [
        InvoiceLines::sales('1', 'Base Fee', '10.00'),
        ['Id' => '3', 'Amount' => '20.00', 'DetailType' => 'GroupLineDetail', 'GroupLineDetail' => ['Quantity' => 1]],
        ['Id' => '4', 'DetailType' => 'DescriptionOnly', 'Description' => 'Note', 'DescriptionLineDetail' => []],
    ]];

    $limited = (new InvoiceMapper())->withoutUnmatchedSalesLines([InvoiceLines::item('a', 'Base Fee', 1000)], $remote);

    expect($limited['omitted_cents'])->toBe(2000)
        ->and(count($limited['remote']['Line']))->toBe(3);
});

test('a matched line keeps its QuickBooks product and tax code, and a new line uses the service item', function () {
    $remote = ['Line' => [[
        'Id'                  => '1',
        'Amount'              => '10.00',
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => 'Base Fee',
        'SalesItemLineDetail' => [
            'ItemRef'    => ['value' => '55', 'name' => 'Delivery: Base'],
            'TaxCodeRef' => ['value' => 'TAX'],
            'Qty'        => 1,
            'UnitPrice'  => '10.00',
        ],
    ]]];
    $payload = (new InvoiceMapper())->toQuickBooks(['items' => [
        ['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1200, 'qbo_line_id' => '1'],
        ['description' => 'Cash on delivery fee', 'quantity' => 1, 'amount' => 500],
    ]], 'cust-1', 'svc');

    $merged = (new InvoiceMapper())->withExistingLineIds($payload, $remote, [], true);

    expect($merged['Line'][0]['Id'])->toBe('1')
        ->and($merged['Line'][0]['Amount'])->toBe('12.00')
        ->and($merged['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => '55', 'name' => 'Delivery: Base'])
        ->and($merged['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($merged['Line'][1])->not->toHaveKey('Id')
        ->and($merged['Line'][1]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => 'svc'])
        ->and($merged['Line'][1]['SalesItemLineDetail'])->not->toHaveKey('TaxCodeRef');
});

test('a QuickBooks line with no description arrives with its product name', function () {
    $line                                   = InvoiceLines::sales('1', '', '7.50');
    $line['SalesItemLineDetail']['ItemRef'] = ['value' => '56', 'name' => 'Fuel surcharge'];

    $mapped = (new InvoiceMapper())->fromQuickBooks(['TotalAmt' => '7.50', 'Line' => [$line]]);

    expect($mapped['items'][0]['description'])->toBe('Fuel surcharge');
});

test('a QuickBooks description longer than a Fleetbase line holds is cut to fit', function () {
    $long   = str_repeat('é', 150) . str_repeat('x', 150);
    $remote = ['TotalAmt' => '1.00', 'Line' => [InvoiceLines::sales('1', $long, '1.00')]];
    $mapped = (new InvoiceMapper())->fromQuickBooks($remote);

    expect(mb_strlen($mapped['items'][0]['description']))->toBe(InvoiceMapper::LINE_DESCRIPTION_MAX)
        ->and(InvoiceMapper::LINE_DESCRIPTION_MAX)->toBe(191)
        ->and($mapped['items'][0]['description'])->toBe(mb_substr($long, 0, 191));
});

test('an update keeps the full QuickBooks description when Fleetbase holds its first part', function () {
    $long    = str_repeat('Long delivery note. ', 15);
    $remote  = ['Line' => [InvoiceLines::sales('1', $long, '1.00')]];
    $short   = mb_substr($long, 0, InvoiceMapper::LINE_DESCRIPTION_MAX);
    $payload = (new InvoiceMapper())->toQuickBooks(['items' => [
        ['description' => $short, 'quantity' => 1, 'amount' => 200, 'qbo_line_id' => '1'],
    ]], 'cust-1', 'item-1');

    $merged = (new InvoiceMapper())->withExistingLineIds($payload, $remote, [], true);

    expect($merged['Line'][0]['Description'])->toBe($long);
});

test('a long QuickBooks description still compares equal to the line Fleetbase stored', function () {
    $long   = str_repeat('Long delivery note. ', 15);
    $remote = ['Line' => [InvoiceLines::sales('9', $long, '1.00')]];
    $local  = [InvoiceLines::item('a', mb_substr($long, 0, InvoiceMapper::LINE_DESCRIPTION_MAX), 100)];

    expect((new InvoiceMapper())->pairedLineIds($local, $remote))->toBe(['9']);
});

test('a line paired only by position does not take the product or tax code of that QuickBooks line', function () {
    $remote = ['Line' => [[
        'Id'                  => '1',
        'Amount'              => '10.00',
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => 'Something else',
        'SalesItemLineDetail' => ['ItemRef' => ['value' => '55'], 'TaxCodeRef' => ['value' => 'TAX'], 'Qty' => 1],
    ]]];
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['items' => [['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1200]]],
        'cust-1',
        'svc'
    );

    $merged = (new InvoiceMapper())->withExistingLineIds($payload, $remote);

    expect($merged['Line'][0]['Id'])->toBe('1')
        ->and($merged['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => 'svc'])
        ->and($merged['Line'][0]['SalesItemLineDetail'])->not->toHaveKey('TaxCodeRef');
});

test('a product made inactive in QuickBooks is replaced by the service item on a matched line', function () {
    $line                                   = InvoiceLines::sales('1', 'Base Fee', '10.00');
    $line['SalesItemLineDetail']['ItemRef'] = ['value' => '20', 'name' => 'Fleetbase service (deleted)'];
    $payload                                = (new InvoiceMapper())->toQuickBooks(['items' => [
        ['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1200, 'qbo_line_id' => '1'],
    ]], 'cust-1', 'svc');

    $merged = (new InvoiceMapper())->withExistingLineIds($payload, ['Line' => [$line]], [], true);

    expect($merged['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => 'svc']);
});

test('the product name shown for an empty QuickBooks description is not written back as one', function () {
    $line                                   = InvoiceLines::sales('1', '', '2.00');
    $line['SalesItemLineDetail']['ItemRef'] = ['value' => '56', 'name' => 'Concrete'];
    $payload                                = (new InvoiceMapper())->toQuickBooks(['items' => [
        ['description' => 'Concrete', 'quantity' => 1, 'amount' => 300, 'qbo_line_id' => '1'],
    ]], 'cust-1', 'svc');

    $merged = (new InvoiceMapper())->withExistingLineIds($payload, ['Line' => [$line]], [], true);

    expect($merged['Line'][0]['Description'])->toBe('')
        ->and($merged['Line'][0]['Amount'])->toBe('3.00');
});

test('an empty QuickBooks description stays empty next to an empty fleetbase line', function () {
    $line                                   = InvoiceLines::sales('1', '', '2.00');
    $line['SalesItemLineDetail']['ItemRef'] = ['value' => '56', 'name' => 'Concrete'];
    $remote                                 = ['TotalAmt' => '2.00', 'Line' => [$line]];

    $mapper = new InvoiceMapper();

    expect($mapper->fromQuickBooks($remote, [InvoiceLines::item('a', '', 200)])['items'][0]['description'])->toBe('')
        ->and($mapper->fromQuickBooks($remote)['items'][0]['description'])->toBe('Concrete')
        ->and($mapper->pairedLineIds([InvoiceLines::item('a', '', 200)], $remote, [], true))->toBe(['1']);
});
