<?php

use Fleetbase\Quickbooks\Support\InvoiceTax;

/*
 * QuickBooks works out invoice tax. Its API applies none of the company's tax settings by itself
 * (checked in the sandbox), so Fleetbase sends the tax code of each line and the invoice rate.
 */

test('the sales tax settings come from the QuickBooks preferences and company country', function () {
    $prefs     = ['TaxPrefs' => ['UsingSalesTax' => true, 'TaxGroupCodeRef' => ['value' => '2']]];
    $manual    = InvoiceTax::settings($prefs, 'US');
    $automated = InvoiceTax::settings(['TaxPrefs' => ['UsingSalesTax' => true, 'PartnerTaxEnabled' => false]], 'US');

    expect($manual)->toBe(['mode' => InvoiceTax::US, 'automated' => false, 'rate' => '2'])
        ->and($automated)->toBe(['mode' => InvoiceTax::US, 'automated' => true, 'rate' => null])
        ->and(InvoiceTax::settings(['TaxPrefs' => ['UsingSalesTax' => false]], 'US')['mode'])->toBe(InvoiceTax::OFF)
        ->and(InvoiceTax::settings([], 'US')['mode'])->toBe(InvoiceTax::OFF)
        ->and(InvoiceTax::settings(['TaxPrefs' => ['UsingSalesTax' => true]], 'GB')['mode'])->toBe(InvoiceTax::GLOBAL);
});

test('a line is taxed when both its QuickBooks product and customer are taxable', function () {
    $us      = ['mode' => InvoiceTax::US, 'automated' => false, 'rate' => '2'];
    $off     = ['mode' => InvoiceTax::OFF, 'automated' => false, 'rate' => null];
    $taxable = ['Taxable' => true];
    $exempt  = ['Taxable' => false];

    expect(InvoiceTax::lineCode($us, $taxable, $taxable))->toBe('TAX')
        ->and(InvoiceTax::lineCode($us, $taxable, $exempt))->toBe('NON')
        ->and(InvoiceTax::lineCode($us, $exempt, $taxable))->toBe('NON')
        ->and(InvoiceTax::lineCode($us, null, $taxable))->toBe('NON')
        ->and(InvoiceTax::lineCode($us, $taxable, null))->toBe('NON')
        ->and(InvoiceTax::lineCode($off, $taxable, $taxable))->toBeNull();
});

test('outside the US a line takes the product tax code, then the customer default', function () {
    $global   = ['mode' => InvoiceTax::GLOBAL, 'automated' => false, 'rate' => null];
    $customer = ['DefaultTaxCodeRef' => ['value' => '7']];

    expect(InvoiceTax::lineCode($global, $customer, ['SalesTaxCodeRef' => ['value' => '3']]))->toBe('3')
        ->and(InvoiceTax::lineCode($global, $customer, []))->toBe('7')
        ->and(InvoiceTax::lineCode($global, [], []))->toBeNull();
});

test('the invoice rate is the customer rate, then the company rate, never TAX or NON', function () {
    $us = ['mode' => InvoiceTax::US, 'automated' => false, 'rate' => '2'];
    $as = fn (string $mode, string $rate): array => ['mode' => $mode, 'automated' => false, 'rate' => $rate];

    expect(InvoiceTax::invoiceRate($us, ['DefaultTaxCodeRef' => ['value' => '3']]))->toBe('3')
        ->and(InvoiceTax::invoiceRate($us, ['DefaultTaxCodeRef' => ['value' => 'TAX']]))->toBe('2')
        ->and(InvoiceTax::invoiceRate($us, ['DefaultTaxCodeRef' => ['value' => 'NON']]))->toBe('2')
        ->and(InvoiceTax::invoiceRate($us, null))->toBe('2')
        ->and(InvoiceTax::invoiceRate($as(InvoiceTax::US, 'TAX'), null))->toBeNull()
        ->and(InvoiceTax::invoiceRate($as(InvoiceTax::GLOBAL, '2'), null))->toBeNull()
        ->and(InvoiceTax::invoiceRate($as(InvoiceTax::OFF, '2'), null))->toBeNull();
});

test('the tax plan holds each line code, the invoice rate, and the tax-exclusive flag outside the US', function () {
    $us       = ['mode' => InvoiceTax::US, 'automated' => false, 'rate' => '2'];
    $global   = ['mode' => InvoiceTax::GLOBAL, 'automated' => false, 'rate' => null];
    $off      = ['mode' => InvoiceTax::OFF, 'automated' => false, 'rate' => null];
    $customer = ['Taxable' => true, 'DefaultTaxCodeRef' => ['value' => '3']];
    $items    = ['svc' => ['Taxable' => true, 'SalesTaxCodeRef' => ['value' => '9']], 'other' => ['Taxable' => false]];

    expect(InvoiceTax::plan($us, $customer, $items, ['svc', 'other', 'gone']))->toBe([
        'lines'  => ['TAX', 'NON', 'NON'],
        'rate'   => '3',
        'global' => null,
    ])->and(InvoiceTax::plan($global, $customer, $items, ['svc', 'gone']))->toBe([
        'lines'  => ['9', '3'],
        'rate'   => null,
        'global' => 'TaxExcluded',
    ])->and(InvoiceTax::plan($off, $customer, $items, ['svc']))->toBe([
        'lines'  => [null],
        'rate'   => null,
        'global' => null,
    ]);
});
