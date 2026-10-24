<?php

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks as Primary wins only when the QuickBooks record changed since the last sync
 * (its SyncToken differs from the token stored on the link). Otherwise the Fleetbase change
 * is sent. These tests use the shipped defaults: QuickBooks Primary and Both for every type.
 */

test('quickbooks primary sends a second ledger payment when quickbooks did not change the payment', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('0', ['status' => 'paid', 'amount_paid' => 1000], [
        'SyncToken' => '1',
        'Balance'   => '6.00',
        'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
    ]);
    $client->payments['pay-1'] = Primary::remotePayment('pay-1', '0', '4.00');
    $ledger->links[]           = Primary::paymentLink('pay-1', '0');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updatePayment')
        ->and(Amounts::toMinorUnits((string) $client->payments['pay-1']['TotalAmt']))->toBe(1000)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and(Primary::token($ledger, 'payment', 'pay-1'))->toBe('1');
});

test('quickbooks primary copies the payment when quickbooks changed it since the last sync', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('0', ['status' => 'paid', 'amount_paid' => 1000], [
        'SyncToken' => '1',
        'Balance'   => '6.00',
        'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment']],
    ]);
    $client->payments['pay-1'] = Primary::remotePayment('pay-1', '3', '4.00');
    $ledger->links[]           = Primary::paymentLink('pay-1', '0');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updatePayment')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(400)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and(Primary::token($ledger, 'payment', 'pay-1'))->toBe('3')
        ->and(Primary::notes($ledger))->toBe([Primary::REPLACED]);
});

test('quickbooks primary sends a line fleetbase added when quickbooks did not change the invoice', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', Primary::withDetention());

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Detention 2h')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500)
        ->and($ledger->invoices['inv-1'])->not->toHaveKey('items_from_quickbooks')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');

    $client->calls = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500);
});

test('quickbooks primary replaces the fleetbase invoice line when quickbooks also changed the invoice', function () {
    [$engine, $client, $ledger]            = Primary::linkedInvoice('1', Primary::withDetention());
    $client->invoices['qb-1']['SyncToken'] = '2';

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['items'])->toHaveCount(1)
        ->and($ledger->invoices['inv-1']['items_from_quickbooks'])->toBeTrue()
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2')
        ->and(Primary::notes($ledger))->toBe([Primary::REPLACED]);
});

test('quickbooks primary sends a customer moved in fleetbase when quickbooks did not change the invoice', function () {
    [$engine, $client, $ledger]  = Primary::linkedInvoice('1', ['customer_uuid' => 'cust-2']);
    $ledger->customers['cust-2'] = Primary::customer([
        'uuid'  => 'cust-2',
        'name'  => 'Bea',
        'email' => 'bea@example.test',
    ]);
    $client->customers['qbo-customer-2'] = Primary::remoteCustomer('0', [
        'Id'               => 'qbo-customer-2',
        'DisplayName'      => 'Bea',
        'PrimaryEmailAddr' => ['Address' => 'bea@example.test'],
    ]);
    $ledger->links[] = Primary::link('customer', 'cust-2', 'qbo-customer-2', '0');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['CustomerRef']['value'] ?? null)->toBe('qbo-customer-2')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2');
});

test('a payment fleetbase sends does not make the next invoice edit look like a quickbooks change', function () {
    [$engine, $client, $ledger]   = Primary::linkedInvoice('1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->paymentsBumpInvoices = true;

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('createPayment')
        ->and($client->invoices['qb-1']['SyncToken'])->toBe('2')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');

    $ledger->invoices['inv-1']['items'][] = Primary::detention();
    $ledger->invoices['inv-1']['total']   = 1500;
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500)
        ->and($ledger->invoices['inv-1']['items'])->toHaveCount(2);
});

test('a block of invoices sends the fleetbase edits and keeps the tokens quickbooks returns', function () {
    $paid                       = ['status' => 'paid', 'amount_paid' => 1500] + Primary::withDetention();
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', $paid);
    $ledger->invoices['inv-2']  = Primary::invoice([
        'uuid'     => 'inv-2',
        'number'   => 'INV-2',
        'due_date' => '2026-09-30',
    ]);
    $client->invoices['qb-2']     = Primary::remoteInvoice('4', ['Id' => 'qb-2', 'DocNumber' => 'INV-2']);
    $ledger->links[]              = Primary::link('invoice', 'inv-2', 'qb-2', '4');
    $ledger->pending[]            = Primary::pending('invoice', 'inv-2');
    $client->paymentsBumpInvoices = true;

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('batch')
        ->and($client->calls)->toContain('createPayment')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Detention 2h')
        ->and($client->invoices['qb-2']['DueDate'])->toBe('2026-09-30')
        // Updated to 2, then raised to 3 by the payment Fleetbase sent.
        ->and($client->invoices['qb-1']['SyncToken'])->toBe('3')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('3')
        ->and(Primary::token($ledger, 'invoice', 'inv-2'))->toBe('5');

    $client->calls = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500)
        ->and($ledger->invoices['inv-2']['due_date'])->toBe('2026-09-30');
});

test('a fleetbase line sent under quickbooks primary takes the quickbooks tax and then stays aligned', function () {
    [$engine, $client]                 = qbEngine();
    $client->salesTax                  = ['mode' => 'us', 'automated' => false, 'rate' => '2'];
    $client->taxRates                  = ['2' => '8'];
    $client->assignLineIds             = true;
    $client->items['item-1']           = ['Id' => 'item-1', 'Name' => 'Fleetbase service', 'Taxable' => true];
    $client->customers['qbo-customer'] = Primary::remoteCustomer('0', ['Taxable' => true]);
    $ledger                            = Primary::ledger();
    $ledger->customers['cust-1']       = Primary::customer();
    $ledger->links[]                   = Primary::link('customer', 'cust-1', 'qbo-customer', '0');
    $ledger->invoices['inv-1']         = Primary::invoice();
    $ledger->pending[]                 = Primary::pending('invoice', 'inv-1');
    $settings                          = Primary::shippedSettings();

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $remoteId = (string) $ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['qbo_id'];

    $ledger->invoices['inv-1']['items'][] = Primary::detention();
    $ledger->invoices['inv-1']['total']   = 1580;
    $client->calls                        = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->calls)->toContain('updateInvoice')
        ->and($client->invoices[$remoteId]['Line'])->toHaveCount(2)
        ->and($ledger->invoices['inv-1']['tax'])->toBe(120)
        ->and($ledger->invoices['inv-1']['total'])->toBe(1620);

    $client->calls = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 240);

    expect($client->calls)->not->toContain('updateInvoice')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1620)
        ->and($ledger->invoices['inv-1']['items'])->toHaveCount(2);
});

test('quickbooks primary sends a customer phone fleetbase changed when quickbooks did not change it', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '3');
    $ledger->pending[]          = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-1111')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-1111')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');

    $client->calls = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->not->toContain('updateCustomer');
});

test('quickbooks primary copies the customer phone when quickbooks also changed the customer', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4');
    $ledger->pending[]          = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-0000')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4')
        ->and(Primary::notes($ledger))->toBe([Primary::REPLACED]);
});

test('a customer linked without a stored token keeps quickbooks winning', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('', '3');
    $ledger->pending[]          = Primary::pending('customer', 'cust-1', 'reconcile');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-0000')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('3')
        ->and(Primary::notes($ledger))->toBe([]);
});

test('quickbooks primary sends a wallet description fleetbase changed when quickbooks did not change it', function () {
    [$engine, $client, $ledger] = Primary::linkedWallet('1', '1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateAccount')
        ->and($client->accounts['acct-9']['Description'])->toBe('Fuel float')
        ->and($ledger->wallets['wal-1']['description'])->toBe('Fuel float')
        ->and(Primary::token($ledger, 'wallet', 'wal-1'))->toBe('2');

    $client->calls = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($client->calls)->not->toContain('updateAccount');
});

test('quickbooks primary copies the account description when quickbooks also changed the account', function () {
    [$engine, $client, $ledger] = Primary::linkedWallet('0', '1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateAccount')
        ->and($ledger->wallets['wal-1']['description'])->toBe('Float')
        ->and(Primary::token($ledger, 'wallet', 'wal-1'))->toBe('1')
        ->and(Primary::notes($ledger))->toBe([Primary::REPLACED]);
});

test('a quickbooks change applied from a webhook keeps its token, so a later fleetbase edit is sent', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '5', '555-0000', '555-2222');
    $entities                   = [['entity' => 'Customer', 'id' => 'qbo-customer', 'operation' => 'Update']];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, Primary::shippedSettings(), time());

    expect($ledger->customers['cust-1']['phone'])->toBe('555-2222')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('5');

    $ledger->customers['cust-1']['phone'] = '555-3333';
    $ledger->pending[]                    = Primary::pending('customer', 'cust-1');
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-3333')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-3333');
});

test('a link moved to the record quickbooks merged into stores no token, so quickbooks keeps winning', function () {
    $ledger            = Primary::ledger();
    $ledger->links[]   = Primary::link('customer', 'cust-1', 'qbo-old', '6');
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $moved = $directory->followMergedRecord('company-uuid', 'realm-1', 'customer', 'qbo-old', 'qbo-customer');

    expect($moved)->toBeTrue()
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-customer')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('');
});
