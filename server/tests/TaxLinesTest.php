<?php

use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;

/*
 * QuickBooks works out the tax: Fleetbase sends line tax codes and the invoice rate, never a tax
 * amount, and reads the tax QuickBooks calculated back onto the invoice and its lines.
 */

test('a new invoice sends the line tax codes and the invoice rate instead of a tax line', function () {
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['tax' => 500, 'items' => [
            ['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1000],
            ['description' => 'Cash on delivery fee', 'quantity' => 1, 'amount' => 200],
        ]],
        'cust-1',
        'svc',
        false,
        ['lines' => ['TAX', 'NON'], 'rate' => '2', 'global' => null]
    );

    expect($payload['Line'])->toHaveCount(2)
        ->and($payload['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'TAX'])
        ->and($payload['Line'][1]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => 'NON'])
        ->and($payload['TxnTaxDetail'])->toBe(['TxnTaxCodeRef' => ['value' => '2']])
        ->and($payload)->not->toHaveKey('GlobalTaxCalculation');
});

test('outside the US a new invoice says its amounts are before tax', function () {
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['items' => [['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1000]]],
        'cust-1',
        'svc',
        false,
        ['lines' => ['3'], 'rate' => null, 'global' => 'TaxExcluded']
    );

    expect($payload['GlobalTaxCalculation'])->toBe('TaxExcluded')
        ->and($payload['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '3'])
        ->and($payload)->not->toHaveKey('TxnTaxDetail');
});

test('with sales tax off nothing about tax is sent', function () {
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['tax' => 300, 'items' => [['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1000]]],
        'cust-1',
        'svc'
    );

    expect($payload['Line'])->toHaveCount(1)
        ->and($payload['Line'][0]['SalesItemLineDetail'])->not->toHaveKey('TaxCodeRef')
        ->and($payload)->not->toHaveKey('TxnTaxDetail')
        ->and($payload)->not->toHaveKey('GlobalTaxCalculation');
});

test('an update leaves out the tax-exclusive flag so a QuickBooks choice stays', function () {
    $remote  = ['Line' => [InvoiceLines::sales('1', 'Base Fee', '10.00')], 'GlobalTaxCalculation' => 'TaxInclusive'];
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['items' => [['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1000, 'qbo_line_id' => '1']]],
        'cust-1',
        'svc',
        false,
        ['lines' => ['3'], 'rate' => null, 'global' => 'TaxExcluded']
    );

    $updated = (new InvoiceMapper())->withExistingLineIds($payload, $remote, [], true);

    expect($updated)->not->toHaveKey('GlobalTaxCalculation');
});

test('an update keeps the sales tax rate QuickBooks has over the customer rate', function () {
    $remote  = [
        'Line'         => [InvoiceLines::sales('1', 'Base Fee', '10.00')],
        'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '5']],
    ];
    $payload = (new InvoiceMapper())->toQuickBooks(
        ['items' => [['description' => 'Base Fee', 'quantity' => 1, 'amount' => 1000, 'qbo_line_id' => '1']]],
        'cust-1',
        'svc',
        false,
        ['lines' => ['TAX'], 'rate' => '2', 'global' => null]
    );

    $updated = (new InvoiceMapper())->withExistingLineIds($payload, $remote, [], true);

    expect($updated['TxnTaxDetail'])->toBe(['TxnTaxCodeRef' => ['value' => '5']])
        ->and((new InvoiceMapper())->withExistingLineIds($payload, ['Line' => []])['TxnTaxDetail'])
        ->toBe(['TxnTaxCodeRef' => ['value' => '2']]);
});

test('the tax QuickBooks calculated is read onto the invoice and shared over the taxed lines', function () {
    $taxed                                       = InvoiceLines::sales('1', 'Base Fee', '10.00');
    $taxed['SalesItemLineDetail']['TaxCodeRef']  = ['value' => 'TAX'];
    $other                                       = InvoiceLines::sales('2', 'Peak hours fee', '20.00');
    $other['SalesItemLineDetail']['TaxCodeRef']  = ['value' => 'TAX'];
    $exempt                                      = InvoiceLines::sales('3', 'Cash on delivery fee', '5.00');
    $exempt['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'NON'];
    $remote                                      = [
        'TotalAmt'     => '37.73',
        'Line'         => [$taxed, $other, $exempt],
        'TxnTaxDetail' => [
            'TxnTaxCodeRef' => ['value' => '3'],
            'TotalTax'      => '2.73',
            'TaxLine'       => [InvoiceLines::tax('2.13', '7.1'), InvoiceLines::tax('0.60', '2')],
        ],
    ];

    $mapped = (new InvoiceMapper())->fromQuickBooks($remote);

    expect($mapped['tax'])->toBe(273)
        ->and($mapped['total'])->toBe(3773)
        ->and(array_column($mapped['items'], 'tax_rate'))->toBe(['9.10', '9.10', '0.00'])
        // 2.73 shared 10:20 is 0.91 and 1.82; the line taxes add up to the invoice tax.
        ->and(array_column($mapped['items'], 'tax_amount'))->toBe([91, 182, 0]);
});

test('line taxes still add up to the invoice tax when the shares round', function () {
    $lines = [];
    foreach (['1', '2', '3'] as $id) {
        $line                                      = InvoiceLines::sales($id, 'Line ' . $id, '1.00');
        $line['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
        $lines[]                                   = $line;
    }
    $remote = ['TotalAmt' => '3.25', 'Line' => $lines, 'TxnTaxDetail' => ['TotalTax' => '0.25']];

    $mapped = (new InvoiceMapper())->fromQuickBooks($remote);

    expect(array_sum(array_column($mapped['items'], 'tax_amount')))->toBe(25)
        // No TaxLine percent: the rate is the tax over the taxed amount.
        ->and($mapped['items'][0]['tax_rate'])->toBe('8.33');
});

test('no line rate is worked out when nothing taxed has a positive amount, or the rate would not fit', function () {
    $credit                                      = InvoiceLines::sales('1', 'Credit', '-5.00');
    $credit['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
    $tiny                                        = InvoiceLines::sales('2', 'Tiny', '0.01');
    $tiny['SalesItemLineDetail']['TaxCodeRef']   = ['value' => 'TAX'];

    $mapper   = new InvoiceMapper();
    $negative = $mapper->fromQuickBooks(
        ['TotalAmt' => '-4.00', 'Line' => [$credit], 'TxnTaxDetail' => ['TotalTax' => '1.00']]
    );
    $huge     = $mapper->fromQuickBooks(
        ['TotalAmt' => '500.01', 'Line' => [$tiny], 'TxnTaxDetail' => ['TotalTax' => '500.00']]
    );

    expect($negative['tax'])->toBe(100)
        ->and($negative['items'][0]['tax_rate'])->toBe('0.00')
        ->and($negative['items'][0]['tax_amount'])->toBe(0)
        ->and($huge['items'][0]['tax_rate'])->toBe('0.00')
        ->and($huge['items'][0]['tax_amount'])->toBe(0);
});

test('an invoice with no tax gives lines with no tax', function () {
    $line   = InvoiceLines::sales('1', 'Base Fee', '10.00');
    $mapped = (new InvoiceMapper())->fromQuickBooks(['TotalAmt' => '10.00', 'Line' => [$line]]);

    expect($mapped['tax'])->toBe(0)
        ->and($mapped['items'][0]['tax_rate'])->toBe('0.00')
        ->and($mapped['items'][0]['tax_amount'])->toBe(0);
});

test('a line named like the old tax line is an ordinary line', function () {
    $mapped = (new InvoiceMapper())->fromQuickBooks(['TotalAmt' => '10.00', 'Line' => [
        InvoiceLines::sales('1', "\u{2060}Tax", '10.00'),
    ]]);

    expect($mapped['tax'])->toBe(0)
        ->and($mapped['items'])->toHaveCount(1);
});

test('the tax copied onto Fleetbase lines leaves out the tax of QuickBooks lines Fleetbase does not have', function () {
    $mine                                       = InvoiceLines::sales('1', 'Delivery', '10.00');
    $mine['SalesItemLineDetail']['TaxCodeRef']  = ['value' => 'TAX'];
    $extra                                      = InvoiceLines::sales('2', 'Added in QuickBooks', '5.00');
    $extra['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
    $remote                                     = [
        'Line'         => [$mine, $extra],
        'TxnTaxDetail' => ['TotalTax' => '1.20', 'TaxLine' => [InvoiceLines::tax('1.20', '8')]],
    ];

    $mapper = new InvoiceMapper();
    $taxed  = $mapper->quickBooksTax($remote, [['description' => 'Delivery', 'amount' => 1000, 'qbo_line_id' => '1']]);
    $exempt = $mapper->quickBooksTax($remote, [['description' => 'Other', 'amount' => 500, 'qbo_line_id' => '9']]);

    expect($taxed['tax'])->toBe(80)
        ->and($taxed['items'][0]['tax_amount'])->toBe(80)
        ->and($taxed['items'][0]['tax_rate'])->toBe('8.00')
        ->and($exempt['tax'])->toBe(0)
        ->and($exempt['items'][0]['tax_amount'])->toBe(0);
});

test('a tax that cannot be shared over lines is copied whole onto the invoice', function () {
    $line   = InvoiceLines::sales('1', 'Delivery', '10.00');
    $remote = ['Line' => [$line], 'TxnTaxDetail' => ['TotalTax' => '2.00']];

    $items  = [['description' => 'Delivery', 'amount' => 1000, 'qbo_line_id' => '1']];
    $copied = (new InvoiceMapper())->quickBooksTax($remote, $items);

    // Outside the US lines carry rate codes, not TAX, so nothing says which line holds the tax.
    expect($copied['tax'])->toBe(200)
        ->and($copied['items'][0]['tax_amount'])->toBe(0);
});

/*
 * Outside the US each line names a tax code, and the invoice holds one tax line per QuickBooks
 * tax rate. With the rates of each code (InvoiceTax::codeRates), each rate's tax is shared over
 * the lines whose code holds it, so a Ledger edit, which adds up the line taxes, keeps it.
 */

test('outside the US the tax of each rate is shared over the lines whose code holds that rate', function () {
    $remote = [
        'TotalAmt'     => '184.50',
        'Line'         => [
            InvoiceLines::coded('1', 'Delivery', '100.00', '4'),
            InvoiceLines::coded('2', 'Fuel surcharge', '50.00', '5'),
            InvoiceLines::coded('3', 'Customs fee', '20.00', '6'),
        ],
        'TxnTaxDetail' => ['TotalTax' => '14.50', 'TaxLine' => [
            InvoiceLines::rateTax('7.50', '5', 'gst', '150.00'),
            InvoiceLines::rateTax('7.00', '7', 'pst', '100.00'),
        ]],
    ];
    $codes  = ['4' => ['gst', 'pst'], '5' => ['gst'], '6' => []];

    $mapper = new InvoiceMapper();
    $mapped = $mapper->fromQuickBooks($remote, [], $codes);
    $items  = [
        ['description' => 'Delivery', 'amount' => 10000, 'qbo_line_id' => '1'],
        ['description' => 'Fuel surcharge', 'amount' => 5000, 'qbo_line_id' => '2'],
        ['description' => 'Customs fee', 'amount' => 2000, 'qbo_line_id' => '3'],
    ];
    $copied = $mapper->quickBooksTax($remote, $items, $codes);

    expect($mapped['tax'])->toBe(1450)
        ->and(array_column($mapped['items'], 'tax_rate'))->toBe(['12.00', '5.00', '0.00'])
        ->and(array_column($mapped['items'], 'tax_amount'))->toBe([1200, 250, 0])
        ->and($copied['tax'])->toBe(1450)
        ->and(array_column($copied['items'], 'tax_rate'))->toBe(['12.00', '5.00', '0.00'])
        ->and(array_column($copied['items'], 'tax_amount'))->toBe([1200, 250, 0]);
});

test('outside the US each rate share rounds so the line taxes add up to the invoice tax', function () {
    $lines = [];
    foreach (['1', '2', '3'] as $id) {
        $lines[] = InvoiceLines::coded($id, 'Line ' . $id, '0.33', '4');
    }
    $remote = ['Line' => $lines, 'TxnTaxDetail' => ['TotalTax' => '0.12', 'TaxLine' => [
        InvoiceLines::rateTax('0.05', '5', 'gst', '0.99'),
        InvoiceLines::rateTax('0.07', '7', 'pst', '0.99'),
    ]]];
    $items  = [];
    foreach (['1', '2', '3'] as $id) {
        $items[] = ['description' => 'Line ' . $id, 'amount' => 33, 'qbo_line_id' => $id];
    }

    $copied = (new InvoiceMapper())->quickBooksTax($remote, $items, ['4' => ['gst', 'pst']]);

    expect($copied['tax'])->toBe(12)
        ->and(array_sum(array_column($copied['items'], 'tax_amount')))->toBe(12)
        ->and($copied['items'][0]['tax_rate'])->toBe('12.00');
});

test('outside the US the tax of QuickBooks lines Fleetbase does not have is left out', function () {
    $remote = ['Line' => [
        InvoiceLines::coded('1', 'Delivery', '100.00', '5'),
        InvoiceLines::coded('2', 'Added in QuickBooks', '100.00', '5'),
    ], 'TxnTaxDetail' => ['TotalTax' => '10.00', 'TaxLine' => [
        InvoiceLines::rateTax('10.00', '5', 'gst', '200.00'),
    ]]];

    $copied = (new InvoiceMapper())->quickBooksTax(
        $remote,
        [['description' => 'Delivery', 'amount' => 10000, 'qbo_line_id' => '1']],
        ['5' => ['gst']]
    );

    expect($copied['tax'])->toBe(500)
        ->and($copied['items'][0]['tax_amount'])->toBe(500)
        ->and($copied['items'][0]['tax_rate'])->toBe('5.00');
});

test('outside the US the tax stays whole on the invoice when a line code or rate is not known', function () {
    $line                                  = InvoiceLines::coded('1', 'Delivery', '100.00', '9');
    $items                                 = [['description' => 'Delivery', 'amount' => 10000, 'qbo_line_id' => '1']];
    $tax                                   = ['TotalTax' => '5.00', 'TaxLine' => [
        InvoiceLines::rateTax('5.00', '5', 'gst', '100.00'),
    ]];
    $flat                                  = InvoiceLines::rateTax('5.00', '5', 'gst', '100.00');
    $flat['TaxLineDetail']['PercentBased'] = false;
    $mapper                                = new InvoiceMapper();

    $unknown = $mapper->quickBooksTax(['Line' => [$line], 'TxnTaxDetail' => $tax], $items, ['4' => ['gst']]);
    $amount  = $mapper->quickBooksTax(
        ['Line' => [InvoiceLines::coded('1', 'Delivery', '100.00', '4')], 'TxnTaxDetail' => [
            'TotalTax' => '5.00', 'TaxLine' => [$flat],
        ]],
        $items,
        ['4' => ['gst']]
    );

    expect($unknown['tax'])->toBe(500)
        ->and($unknown['items'][0]['tax_amount'])->toBe(0)
        ->and($amount['tax'])->toBe(500)
        ->and($amount['items'][0]['tax_amount'])->toBe(0);
});

test('a tax-inclusive invoice keeps its tax on the invoice, since its lines already hold it', function () {
    $remote = [
        'GlobalTaxCalculation' => 'TaxInclusive',
        'Line'                 => [InvoiceLines::coded('1', 'Delivery', '105.00', '5')],
        'TxnTaxDetail'         => ['TotalTax' => '5.00', 'TaxLine' => [
            InvoiceLines::rateTax('5.00', '5', 'gst', '100.00'),
        ]],
    ];

    $copied = (new InvoiceMapper())->quickBooksTax(
        $remote,
        [['description' => 'Delivery', 'amount' => 10500, 'qbo_line_id' => '1']],
        ['5' => ['gst']]
    );

    expect($copied['tax'])->toBe(500)
        ->and($copied['inclusive'])->toBeTrue()
        ->and($copied['items'][0]['tax_amount'])->toBe(0);
});

test('outside the US a line coded NON holds no rate and the other lines are still shared', function () {
    $remote = ['Line' => [
        InvoiceLines::coded('1', 'Delivery', '100.00', '5'),
        InvoiceLines::coded('2', 'Exempt fee', '10.00', 'NON'),
    ], 'TxnTaxDetail' => ['TotalTax' => '5.00', 'TaxLine' => [
        InvoiceLines::rateTax('5.00', '5', 'gst', '100.00'),
    ]]];

    $mapped = (new InvoiceMapper())->fromQuickBooks($remote, [], ['5' => ['gst']]);

    expect(array_column($mapped['items'], 'tax_amount'))->toBe([500, 0])
        ->and(array_column($mapped['items'], 'tax_rate'))->toBe(['5.00', '0.00']);
});
