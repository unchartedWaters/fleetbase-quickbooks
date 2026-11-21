<?php

use Fleetbase\Quickbooks\Tests\Support\InvoiceLines;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * Invoice branches no other test reached: a matching invoice takes the due date QuickBooks
 * holds, a one-way inbound sync never voids in QuickBooks, and a line Fleetbase deleted goes
 * from QuickBooks when Fleetbase leads, even though QuickBooks supplies the lines.
 */

test('a matching invoice without a due date takes the one quickbooks holds', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['due_date' => '']);
    $settings                   = Primary::shippedSettings(['invoice_direction' => 'inbound']);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->invoices['inv-1']['due_date'])->toBe('2026-09-15')
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['updated'])
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a voided invoice is not voided in quickbooks by a one-way inbound sync', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['status' => 'void']);
    $settings                   = Primary::shippedSettings(['invoice_direction' => 'inbound']);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($client->calls)->not->toContain('voidInvoice')
        ->and($client->invoices['qb-1'])->not->toHaveKey('voided');
});

test('a line deleted in fleetbase goes from quickbooks when quickbooks did not change the invoice', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', [
        'items'         => [InvoiceLines::tracked('item-1', 'Delivery', 1000, '1')],
        'removed_lines' => [InvoiceLines::removed('item-2', 'Fuel', 500, '2')],
    ], [
        'TotalAmt' => '15.00',
        'Line'     => [InvoiceLines::sales('1', 'Delivery', '10.00'), InvoiceLines::sales('2', 'Fuel', '5.00')],
    ]);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Id'))->toBe(['1']);
});
