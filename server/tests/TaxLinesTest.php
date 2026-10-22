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

    expect((new InvoiceMapper())->withExistingLineIds($payload, $remote, [], true))->not->toHaveKey('GlobalTaxCalculation');
});

test('an update keeps the sales tax rate QuickBooks has over the customer rate', function () {
    $remote  = ['Line' => [InvoiceLines::sales('1', 'Base Fee', '10.00')], 'TxnTaxDetail' => ['TxnTaxCodeRef' => ['value' => '5']]];
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
            'TaxLine'       => [
                ['Amount' => '2.13', 'DetailType' => 'TaxLineDetail', 'TaxLineDetail' => ['PercentBased' => true, 'TaxPercent' => '7.1']],
                ['Amount' => '0.60', 'DetailType' => 'TaxLineDetail', 'TaxLineDetail' => ['PercentBased' => true, 'TaxPercent' => '2']],
            ],
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

    $negative = (new InvoiceMapper())->fromQuickBooks(['TotalAmt' => '-4.00', 'Line' => [$credit], 'TxnTaxDetail' => ['TotalTax' => '1.00']]);
    $huge     = (new InvoiceMapper())->fromQuickBooks(['TotalAmt' => '500.01', 'Line' => [$tiny], 'TxnTaxDetail' => ['TotalTax' => '500.00']]);

    expect($negative['tax'])->toBe(100)
        ->and($negative['items'][0]['tax_rate'])->toBe('0.00')
        ->and($negative['items'][0]['tax_amount'])->toBe(0)
        ->and($huge['items'][0]['tax_rate'])->toBe('0.00')
        ->and($huge['items'][0]['tax_amount'])->toBe(0);
});

test('an invoice with no tax gives lines with no tax', function () {
    $mapped = (new InvoiceMapper())->fromQuickBooks(['TotalAmt' => '10.00', 'Line' => [InvoiceLines::sales('1', 'Base Fee', '10.00')]]);

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
