<?php

use Fleetbase\Quickbooks\Tests\Support\FleetbaseTaxCompany as Company;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * Outside the US, QuickBooks refuses an invoice line with no sales tax code (checked in a Canadian
 * sandbox company: "Make sure all your transactions have a GST/HST rate"). With QuickBooks working
 * out tax, Fleetbase stops such an invoice before sending and Activity names the line.
 */

test('outside the US an invoice line with no QuickBooks tax code stops the invoice before it is sent', function () {
    [$engine, $client, $ledger] = Company::canada();
    unset($client->items['item-1']['SalesTaxCodeRef']);

    $batch = $engine->runScheduled($ledger, 'company-uuid', Company::settings(['tax_source' => 'quickbooks']), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->invoiceCreates)->toBe(0)
        ->and(Primary::notes($ledger)[0] ?? '')->toBe(
            'Fleetbase did not send this invoice. Line "Delivery" has no QuickBooks tax code. Give its product or'
                . ' the customer a sales tax code in QuickBooks.'
        );
});

test('outside the US an invoice line whose product has a tax code is sent with it', function () {
    [$engine, $client, $ledger] = Company::canada();

    $batch = $engine->runScheduled($ledger, 'company-uuid', Company::settings(['tax_source' => 'quickbooks']), time());

    expect($batch['created'])->toBe(1)
        ->and($client->sentInvoices[0]['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '4']);
});

test('outside the US a customer tax code is used when the product has none', function () {
    [$engine, $client, $ledger] = Company::canada();
    unset($client->items['item-1']['SalesTaxCodeRef']);
    $client->customers['qbo-customer']['DefaultTaxCodeRef'] = ['value' => '5'];

    $batch = $engine->runScheduled($ledger, 'company-uuid', Company::settings(['tax_source' => 'quickbooks']), time());

    expect($batch['created'])->toBe(1)
        ->and($client->sentInvoices[0]['Line'][0]['SalesItemLineDetail']['TaxCodeRef'])->toBe(['value' => '5']);
});
