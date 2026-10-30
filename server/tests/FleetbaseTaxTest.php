<?php

use Fleetbase\Quickbooks\Support\FleetbaseTax;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\InvoiceTax;
use Fleetbase\Quickbooks\Tests\Support\FleetbaseTaxCompany as Company;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;

/*
 * With Fleetbase working out tax, the tax on the Fleetbase lines is what QuickBooks gets: US lines
 * are coded TAX or NON by it and the invoice tax is the TotalTax override; outside the US the tax
 * of each line is sent per QuickBooks rate of its tax code.
 */

test('a US invoice codes each line by its Fleetbase tax and overrides the invoice tax', function () {
    $items   = [
        InvoiceMapper::item('Delivery', 1, 10000, 10000, '8.00', 800),
        InvoiceMapper::item('Fuel surcharge', 1, 2000, 2000),
    ];
    $payload = [
        'Line'         => [
            InvoiceLines::coded('', 'Delivery', '100.00', 'NON'),
            InvoiceLines::coded('', 'Fuel surcharge', '20.00', 'TAX'),
        ],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '2']],
    ];
    $us      = ['mode' => InvoiceTax::US, 'automated' => false, 'rate' => '2'];

    $sent = FleetbaseTax::apply($payload, $items, $us, ['NON', 'TAX'], [], []);

    expect($sent['error'])->toBeNull()
        ->and($sent['payload']['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($sent['payload']['Line'][1]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'NON'])
        ->and($sent['payload']['TxnTaxDetail'])->toBe(['TxnTaxCodeRef' => ['value' => '2'], 'TotalTax' => '8.00']);
});

test('a US invoice with Fleetbase tax needs a QuickBooks sales tax rate or automated sales tax', function () {
    $taxed   = [InvoiceMapper::item('Delivery', 1, 10000, 10000, '8.00', 800)];
    $untaxed = [InvoiceMapper::item('Delivery', 1, 10000, 10000)];
    $payload = ['Line' => [InvoiceLines::sales('', 'Delivery', '100.00')]];
    $us      = ['mode' => InvoiceTax::US, 'automated' => false, 'rate' => null];
    $ast     = ['mode' => InvoiceTax::US, 'automated' => true, 'rate' => null];

    $refused   = FleetbaseTax::apply($payload, $taxed, $us, [null], [], []);
    $none      = FleetbaseTax::apply($payload, $untaxed, $us, [null], [], []);
    $automated = FleetbaseTax::apply($payload, $taxed, $ast, [null], [], []);

    expect(qbText($refused['error']))->toContain('no sales tax rate')
        ->and($none['error'])->toBeNull()
        ->and($none['payload'])->not->toHaveKey('TxnTaxDetail')
        ->and($none['payload']['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'NON'])
        ->and($automated['error'])->toBeNull()
        ->and($automated['payload']['TxnTaxDetail'])->toBe(['TotalTax' => '8.00']);
});

test('outside the US the Fleetbase line tax is sent per QuickBooks rate', function () {
    $gstPst  = Company::gstPst();
    $rates   = Company::rates();
    $items   = [
        InvoiceMapper::item('Delivery', 1, 10000, 10000, '12.00', 1200),
        InvoiceMapper::item('Fuel surcharge', 1, 5000, 5000, '5.00', 250),
    ];
    $payload = ['Line' => [
        InvoiceLines::coded('', 'Delivery', '100.00', '4'),
        InvoiceLines::coded('', 'Fuel surcharge', '50.00', '5'),
    ]];

    $sent = FleetbaseTax::apply($payload, $items, $gstPst, ['4', '5'], $rates, []);

    expect($sent['error'])->toBeNull()
        ->and($sent['payload']['TxnTaxDetail'])->toBe(['TotalTax' => '14.50', 'TaxLine' => [
            FleetbaseTax::taxLine('gst', '5', '7.50', '150.00'),
            FleetbaseTax::taxLine('pst', '7', '7.00', '100.00'),
        ]])
        ->and(InvoiceLines::rateTax('7.50', '5', 'gst', '150.00'))
        ->toBe(FleetbaseTax::taxLine('gst', '5', '7.50', '150.00'));
});

test('the tax of a line is split over its rates by percent, the rounding on the last rate', function () {
    $gstPst  = Company::gstPst();
    $rates   = Company::rates();
    $items   = [InvoiceMapper::item('Delivery', 1, 12492, 12492, '12.00', 1499)];
    $payload = ['Line' => [InvoiceLines::coded('', 'Delivery', '124.92', '4')]];

    $sent = FleetbaseTax::apply($payload, $items, $gstPst, ['4'], $rates, []);

    expect(array_column($sent['payload']['TxnTaxDetail']['TaxLine'], 'Amount'))->toBe(['6.25', '8.74'])
        ->and($sent['payload']['TxnTaxDetail']['TotalTax'])->toBe('14.99');
});

test('a line takes the product code when its own does not give its Fleetbase rate, else fails', function () {
    $gstPst  = Company::gstPst();
    $rates   = Company::rates();
    $items   = [InvoiceMapper::item('Delivery', 1, 10000, 10000, '12.00', 1200)];
    $payload = ['Line' => [InvoiceLines::coded('7', 'Delivery', '100.00', '5')]];

    $planned = FleetbaseTax::apply($payload, $items, $gstPst, ['4'], $rates, []);
    $refused = FleetbaseTax::apply($payload, $items, $gstPst, ['5'], $rates, []);

    expect($planned['error'])->toBeNull()
        ->and($planned['payload']['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '4'])
        ->and(qbText($refused['error']))->toContain('"Delivery"')
        ->and(qbText($refused['error']))->toContain('12.00%')
        ->and(qbText($refused['error']))->toContain('5%');
});

test('an untaxed line needs a QuickBooks code without rates', function () {
    $gstPst  = Company::gstPst();
    $rates   = Company::rates();
    $items   = [InvoiceMapper::item('Delivery', 1, 10000, 10000)];
    $payload = ['Line' => [InvoiceLines::coded('', 'Delivery', '100.00', '5')]];

    $exempt  = FleetbaseTax::apply($payload, $items, $gstPst, ['6'], $rates, []);
    $refused = FleetbaseTax::apply($payload, $items, $gstPst, ['5'], $rates, []);

    expect($exempt['error'])->toBeNull()
        ->and($exempt['payload']['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '6'])
        ->and($exempt['payload']['TxnTaxDetail'])->toBe(['TotalTax' => '0.00', 'TaxLine' => []])
        ->and(qbText($refused['error']))->toContain('0.00%');
});

test('codes Fleetbase cannot match exactly are refused, never sent split another way', function () {
    $gstPst   = Company::gstPst();
    $rates    = Company::rates();
    $quebec   = [InvoiceMapper::item('Delivery', 1, 100000, 100000, '14.98', 14980)];
    $compound = [InvoiceMapper::item('Delivery', 1, 10000, 10000, '12.00', 1200)];
    $line     = static fn (string $code): array => ['Line' => [InvoiceLines::coded('', 'Delivery', '100.00', $code)]];
    $unread   = $rates;
    unset($unread['pst']);
    $readOnly                     = $rates;
    $readOnly['pst']['read_only'] = true;

    $precise  = FleetbaseTax::apply($line('7'), $quebec, $gstPst, ['7'], $rates, []);
    $ordered  = FleetbaseTax::apply($line('8'), $compound, $gstPst, ['8'], $rates, []);
    $missing  = FleetbaseTax::apply($line('4'), $compound, $gstPst, ['4'], $unread, []);
    $locked   = FleetbaseTax::apply($line('4'), $compound, $gstPst, ['4'], $readOnly, []);
    $uncoded  = ['Line' => [InvoiceLines::sales('', 'Delivery', '100.00')]];
    $noCode   = FleetbaseTax::apply($uncoded, $compound, $gstPst, [null], $rates, []);

    expect(qbText($precise['error']))->toContain('14.975%')
        ->and(qbText($ordered['error']))->toContain('tax on tax')
        ->and(qbText($missing['error']))->toContain('could not read')
        ->and(qbText($locked['error']))->toContain('read-only')
        ->and(qbText($noCode['error']))->toContain('no QuickBooks tax code');
});

test('a tax-inclusive QuickBooks invoice is refused and sales tax off sends nothing', function () {
    $gstPst  = Company::gstPst();
    $rates   = Company::rates();
    $items   = [InvoiceMapper::item('Delivery', 1, 10000, 10000, '12.00', 1200)];
    $payload = ['Line' => [InvoiceLines::coded('', 'Delivery', '100.00', '4')]];
    $off     = ['mode' => InvoiceTax::OFF, 'automated' => false, 'rate' => null];

    $taxed     = ['GlobalTaxCalculation' => 'TaxInclusive'];
    $inclusive = FleetbaseTax::apply($payload, $items, $gstPst, ['4'], $rates, $taxed);
    $untaxed   = FleetbaseTax::apply($payload, $items, $off, [null], [], []);

    expect(qbText($inclusive['error']))->toContain('include tax')
        ->and($untaxed['error'])->toBeNull()
        ->and($untaxed['payload'])->toBe($payload);
});

test('lines only QuickBooks has keep their code and their rates are sent with no tax', function () {
    $gstPst  = Company::gstPst();
    $rates   = Company::rates();
    $items   = [InvoiceMapper::item('Delivery', 1, 10000, 10000, '5.00', 500)];
    $payload = ['Line' => [
        InvoiceLines::coded('1', 'Delivery', '100.00', '5'),
        InvoiceLines::coded('2', 'Added in QuickBooks', '30.00', '4'),
    ]];

    $sent = FleetbaseTax::apply($payload, $items, $gstPst, ['5'], $rates, []);

    expect($sent['error'])->toBeNull()
        ->and($sent['payload']['Line'][1]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '4'])
        ->and($sent['payload']['TxnTaxDetail'])->toBe(['TotalTax' => '5.00', 'TaxLine' => [
            FleetbaseTax::taxLine('gst', '5', '5.00', '130.00'),
            FleetbaseTax::taxLine('pst', '7', '0.00', '30.00'),
        ]]);
});

test('compound codes are the ones with a rate charged on tax', function () {
    $codes = [
        ['Id' => '4', 'SalesTaxRateList' => ['TaxRateDetail' => [
            ['TaxRateRef' => ['value' => 'gst'], 'TaxTypeApplicable' => 'TaxOnAmount', 'TaxOrder' => 0],
            ['TaxRateRef' => ['value' => 'pst'], 'TaxTypeApplicable' => 'TaxOnAmount', 'TaxOrder' => 0],
        ]]],
        ['Id' => '8', 'SalesTaxRateList' => ['TaxRateDetail' => [
            ['TaxRateRef' => ['value' => 'gst'], 'TaxTypeApplicable' => 'TaxOnAmount', 'TaxOrder' => 0],
            ['TaxRateRef' => ['value' => 'pst'], 'TaxTypeApplicable' => 'TaxOnAmountPlusTax', 'TaxOrder' => 1],
        ]]],
        ['Id' => '9', 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => 'gst']]]]],
    ];

    expect(InvoiceTax::compoundCodes($codes))->toBe(['8']);
});

test('tax rates are read with their percent and whether QuickBooks lets them be changed', function () {
    $rows = [
        ['Id' => 'gst', 'RateValue' => '5', 'Active' => true],
        ['Id' => 'qst', 'RateValue' => '9.975', 'DisplayType' => 'ReadOnly'],
        ['Id' => 'old', 'Active' => true],
    ];

    expect(FleetbaseTax::rates($rows))->toBe([
        'gst' => ['percent' => '5', 'read_only' => false],
        'qst' => ['percent' => '9.975', 'read_only' => true],
    ]);
});

test('a QuickBooks copy keeps the Fleetbase rate of each line it pairs by QuickBooks line id', function () {
    $current = [
        InvoiceLines::tracked('item-a', 'Delivery', 10000, '1') + ['tax_rate' => '12.00', 'tax_amount' => 1200],
        InvoiceLines::tracked('item-b', 'Fuel', 5000, '2') + ['tax_rate' => '5.00', 'tax_amount' => 250],
    ];
    $remote  = [
        'Id'           => 'qb-1',
        'TotalAmt'     => '190.00',
        'Line'         => [
            InvoiceLines::sales('2', 'Fuel', '60.00'),
            InvoiceLines::sales('1', 'Delivery', '100.00'),
            InvoiceLines::sales('3', 'Added in QuickBooks', '20.00'),
            ['Amount' => '10.00', 'DetailType' => 'DiscountLineDetail', 'DiscountLineDetail' => []],
        ],
        'TxnTaxDetail' => ['TotalTax' => '20.00'],
    ];
    $mapper  = new InvoiceMapper();

    $kept      = $mapper->withFleetbaseRates($mapper->fromQuickBooks($remote, $current), $remote, $current);
    $inclusive = $remote + ['GlobalTaxCalculation' => 'TaxInclusive'];
    $copied    = $mapper->fromQuickBooks($inclusive, $current);

    expect(array_column($kept['items'], 'tax_rate'))->toBe(['5.00', '12.00', '0.00'])
        ->and(array_column($kept['items'], 'tax_amount'))->toBe([300, 1200, 0])
        ->and($kept['tax'])->toBe(1500)
        // QuickBooks' total less its tax, plus Fleetbase's: the discount stays.
        ->and($kept['total'])->toBe(18500)
        ->and($kept['unpaired'])->toBe(1)
        ->and($mapper->withFleetbaseRates($copied, $inclusive, $current))->toBe($copied + ['unpaired' => 0]);
});

test('outside the US rate ids that are numbers, as QuickBooks gives them, are sent as text', function () {
    $settings = array_merge(Company::gstPst(), ['codes' => ['7' => ['15', '16']], 'compound' => []]);
    $rates    = FleetbaseTax::rates([
        ['Id' => '15', 'RateValue' => '5', 'DisplayType' => 'None'],
        ['Id' => '16', 'RateValue' => '7', 'DisplayType' => 'None'],
    ]);
    $items    = [InvoiceMapper::item('Delivery', 1, 10000, 10000, '12.00', 1200)];
    $payload  = ['Line' => [InvoiceLines::coded('', 'Delivery', '100.00', '7')]];

    $sent = FleetbaseTax::apply($payload, $items, $settings, ['7'], $rates, []);

    expect($sent['error'])->toBeNull()
        ->and($sent['payload']['TxnTaxDetail'])->toBe(['TotalTax' => '12.00', 'TaxLine' => [
            FleetbaseTax::taxLine('15', '5', '5.00', '100.00'),
            FleetbaseTax::taxLine('16', '7', '7.00', '100.00'),
        ]]);
});
