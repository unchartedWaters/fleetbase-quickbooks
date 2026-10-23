<?php

use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks as Primary in two-way sync: a link stores a SyncToken only when both sides were
 * equal, so a webhook whose token equals the stored one is the echo of a Fleetbase write and
 * changes nothing. Fleetbase clears are sent when Fleetbase leads, and a QuickBooks change
 * Fleetbase could not take is never sent back.
 */

test('a webhook echo does not revert a fleetbase customer edit made after the push', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '3');

    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');

    expect($ledger->customers['cust-1']['phone'])->toBe('555-1111');

    $ledger->pending[] = Primary::pending('customer', 'cust-1', 'webhook');
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-1111')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-1111');
});

test('a webhook echo of an invoice push does not drop a newer fleetbase line', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', Primary::withDetention());
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    $ledger->invoices['inv-1']['items'][] = [
        'description' => 'Fuel',
        'quantity'    => 1,
        'unit_price'  => 300,
        'amount'      => 300,
    ];
    $ledger->invoices['inv-1']['total']   = 1800;

    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->invoices['inv-1']['total'])->toBe(1800);

    $client->calls = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Fuel')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1800);
});

test('a webhook echo of a payment push does not erase a newer ledger payment', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['status' => 'partial', 'amount_paid' => 600], [
        'Balance'   => '6.00',
        'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
    ]);
    $client->payments['pay-1'] = Primary::remotePayment('pay-1', '0', '4.00');
    $ledger->links[]           = Primary::paymentLink('pay-1', '0');
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    $client->invoices['qb-1']['Balance']      = '4.00';
    $ledger->invoices['inv-1']['amount_paid'] = 1000;
    $ledger->invoices['inv-1']['status']      = 'paid';

    Primary::webhook($engine, $ledger, 'Payment', 'pay-1');

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid');

    $client->calls = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->toContain('updatePayment')
        ->and($client->payments['pay-1']['TotalAmt'])->toBe('10.00')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000);
});

test('a webhook still copies the quickbooks record when sync runs only from quickbooks', function () {
    [$engine, , $ledger] = Primary::linkedCustomer('3', '3');
    $settings            = Primary::shippedSettings(['customer_direction' => 'inbound']);

    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer', $settings);

    expect($ledger->customers['cust-1']['phone'])->toBe('555-0000');
});

test('a customer phone cleared in fleetbase is sent once and the webhook echoes end there', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '3', '', '555-0000');
    $ledger->pending[]          = Primary::pending('customer', 'cust-1');
    $settings                   = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    for ($cycle = 1; $cycle <= 3; $cycle++) {
        Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');
        Primary::runAgain($engine, $ledger, $settings, time() + 120 * $cycle);
    }

    expect(Primary::count($client, 'updateCustomer'))->toBe(1)
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('')
        ->and($ledger->customers['cust-1']['phone'] ?? '')->toBe('');
});

test('an invoice note cleared in fleetbase is sent once and the webhook echoes end there', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['notes' => ''], ['PrivateNote' => 'Call first']);
    $settings                   = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    for ($cycle = 1; $cycle <= 3; $cycle++) {
        Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');
        Primary::runAgain($engine, $ledger, $settings, time() + 120 * $cycle);
    }

    expect(Primary::count($client, 'updateInvoice'))->toBe(1)
        ->and($client->invoices['qb-1']['PrivateNote'])->toBe('')
        ->and($ledger->invoices['inv-1']['notes'])->toBe('');
});

test('customer notes and address cleared in fleetbase are sent, not filled back from quickbooks', function () {
    [$engine, $client, $ledger]                     = Primary::linkedCustomer('3', '3', '555-0000', '555-0000');
    $client->customers['qbo-customer']['Notes']     = 'Gate code 1234';
    $client->customers['qbo-customer']['BillAddr']  = ['Line1' => '1 Main St', 'City' => 'Springfield'];
    $ledger->pending[]                              = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['notes'] ?? '')->toBe('')
        ->and($ledger->customers['cust-1']['address'] ?? null)->toBeNull()
        ->and($client->customers['qbo-customer']['Notes'])->toBe('')
        ->and($client->customers['qbo-customer']['BillAddr']['Line1'])->toBe('');
});

test('an invoice due date emptied in fleetbase takes the quickbooks due date, which always holds one', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['due_date' => null]);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['DueDate'])->toBe('2026-09-15')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-09-15')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('1');
});

test('a phone cleared in quickbooks is emptied in fleetbase and not sent back', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4', '555-1111', '');
    unset($client->customers['qbo-customer']['PrimaryPhone']);
    $ledger->pending[] = Primary::pending('customer', 'cust-1', 'webhook');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($client->customers['qbo-customer'])->not->toHaveKey('PrimaryPhone')
        ->and($ledger->customers['cust-1']['phone'])->toBeNull()
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');
});

test('a phone cleared in quickbooks and applied from a webhook is not sent back from fleetbase', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4', '555-1111', '');
    unset($client->customers['qbo-customer']['PrimaryPhone']);

    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');
    $ledger->pending[] = Primary::pending('customer', 'cust-1', 'webhook');
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($client->customers['qbo-customer'])->not->toHaveKey('PrimaryPhone')
        ->and($ledger->customers['cust-1']['phone'])->toBeNull()
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');
});

test('an invoice moved in quickbooks to a customer fleetbase has not linked is not moved back', function () {
    [$engine, $client, $ledger]                = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']     = '2';
    $client->invoices['qb-1']['CustomerRef']   = ['value' => 'qbo-x'];
    $ledger->pending                           = [Primary::pending('invoice', 'inv-1', 'webhook')];

    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-x')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->not->toBe('2');
});

test('a due date quickbooks added is copied before the invoice token is stored', function () {
    $remote              = ['SyncToken' => '2', 'DueDate' => '2026-09-30'];
    [$engine, , $ledger] = Primary::linkedInvoice('1', ['due_date' => null], $remote);
    $ledger->pending     = [Primary::pending('invoice', 'inv-1', 'webhook')];

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->invoices['inv-1']['due_date'])->toBe('2026-09-30')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');

    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->invoices['inv-1']['due_date'])->toBe('2026-09-30');
});

test('a first link sends what fleetbase holds and quickbooks lacks on the next sync', function () {
    [$engine, $client, $ledger]           = Primary::linkedCustomer('', '3');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->pending[]                    = Primary::pending('customer', 'cust-1', 'reconcile');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-0000');

    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['Notes'])->toBe('Back door')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-0000');
});

test('a customer renamed in fleetbase is sent when quickbooks did not change the customer', function () {
    [$engine, $client, $ledger]          = Primary::linkedCustomer('3', '3', '555-0000', '555-0000');
    $ledger->customers['cust-1']['name'] = 'Ada Lovelace';
    $ledger->pending[]                   = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['DisplayName'])->toBe('Ada Lovelace')
        ->and($ledger->customers['cust-1']['name'])->toBe('Ada Lovelace');
});

test('a customer renamed in both systems takes the quickbooks name', function () {
    [$engine, $client, $ledger]                           = Primary::linkedCustomer('3', '4', '555-0000', '555-0000');
    $client->customers['qbo-customer']['DisplayName']     = 'Ada King';
    $ledger->customers['cust-1']['name']                  = 'Ada Lovelace';
    $ledger->pending[]                                    = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['name'])->toBe('Ada King');
});

test('an invoice number still comes from quickbooks when quickbooks did not change the invoice', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['number' => 'INV-9']);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->invoices['inv-1']['number'])->toBe('INV-1')
        ->and($client->invoices['qb-1']['DocNumber'])->toBe('INV-1');
});
