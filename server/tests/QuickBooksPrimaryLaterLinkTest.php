<?php

use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks as Primary in two-way sync, after a first link or a move Fleetbase could not take
 * at once. A first link keeps no baseline until what Fleetbase holds and QuickBooks lacks is
 * sent, so a QuickBooks change made meanwhile never empties it. An invoice QuickBooks moved to
 * a customer Fleetbase had not linked follows that customer once Fleetbase links it.
 */

test('a quickbooks change before a queued first-link follow-up keeps what quickbooks lacks', function () {
    $client                            = new FakeQuickBooks();
    [$engine, $ledger]                 = Primary::newInvoice($client);
    $ledger->links                     = [];
    $ledger->customers['cust-1']       = Primary::customer(['notes' => 'Back door']);
    $client->customers['qbo-customer'] = Primary::remoteCustomer('3');
    $settings                          = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect(Primary::token($ledger, 'customer', 'cust-1'))->toBe('~3');

    $client->customers['qbo-customer']['SyncToken']    = '4';
    $client->customers['qbo-customer']['PrimaryPhone'] = ['FreeFormNumber' => '555-2222'];
    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');

    expect($ledger->customers['cust-1']['notes'])->toBe('Back door')
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-2222')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('~4');

    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->customers['qbo-customer']['Notes'])->toBe('Back door')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-2222')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('5');
});

test('a webhook on a link without a token queues what quickbooks lacks', function () {
    [$engine, $client, $ledger]           = Primary::linkedCustomer('', '3', '555-0000', '555-0000');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->pending                      = [];
    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');

    expect(Primary::token($ledger, 'customer', 'cust-1'))->toBe('~3')
        ->and(array_column($ledger->pending, 'local_uuid'))->toBe(['cust-1']);

    $client->customers['qbo-customer']['SyncToken']    = '4';
    $client->customers['qbo-customer']['PrimaryPhone'] = ['FreeFormNumber' => '555-2222'];
    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->customers['cust-1']['notes'])->toBe('Back door')
        ->and($client->customers['qbo-customer']['Notes'])->toBe('Back door')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-2222');
});

test('a first link copied one way from quickbooks stores a plain token', function () {
    [$engine, , $ledger]                  = Primary::linkedCustomer('', '3', '555-0000', '555-0000');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->pending                      = [];
    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer', Primary::shippedSettings([
        'customer_direction' => 'inbound',
    ]));

    expect(Primary::token($ledger, 'customer', 'cust-1'))->toBe('3')
        ->and($ledger->pending)->toBe([]);
});

test('a quickbooks change after a first link was filled empties a field quickbooks emptied', function () {
    [$engine, $client, $ledger]           = Primary::linkedCustomer('', '3', '555-0000', '555-0000');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->pending[]                    = Primary::pending('customer', 'cust-1', 'reconcile');
    $settings                             = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    $token = (string) $client->customers['qbo-customer']['SyncToken'];
    unset($client->customers['qbo-customer']['Notes']);
    $client->customers['qbo-customer']['SyncToken'] = (string) ((int) $token + 1);
    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');

    expect($ledger->customers['cust-1']['notes'] ?? null)->toBeNull();
});

test('an invoice moved to an unlinked customer follows it once fleetbase links it', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    $settings                                = Primary::shippedSettings();
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1');

    $ledger->customers['cust-2'] = Primary::customer(['uuid' => 'cust-2', 'name' => 'Bo']);
    $client->customers['qbo-x']  = Primary::remoteCustomer('0', [
        'Id'               => 'qbo-x',
        'DisplayName'      => 'Bo',
        'PrimaryEmailAddr' => ['Address' => 'bo@example.test'],
    ]);
    $ledger->putLink(Primary::link('customer', 'cust-2', 'qbo-x', ''));
    $ledger->pending  = [Primary::pending('invoice', 'inv-1', 'customer-linked')];
    $ledger->attempts = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-x')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2')
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('an edited invoice that followed its quickbooks customer keeps that customer', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    $settings                                = Primary::shippedSettings();
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    $ledger->customers['cust-2'] = Primary::customer(['uuid' => 'cust-2', 'name' => 'Bo']);
    $client->customers['qbo-x']  = Primary::remoteCustomer('0', [
        'Id'               => 'qbo-x',
        'DisplayName'      => 'Bo',
        'PrimaryEmailAddr' => ['Address' => 'bo@example.test'],
    ]);
    $ledger->putLink(Primary::link('customer', 'cust-2', 'qbo-x', '0'));
    $ledger->invoices['inv-1'] = array_merge($ledger->invoices['inv-1'], Primary::withDetention());
    $ledger->pending           = [Primary::pending('invoice', 'inv-1')];
    $ledger->attempts          = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-x')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Detention 2h')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2');
});

test('an invoice follows its newly linked quickbooks customer on the next webhook', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    $ledger->customers['cust-2'] = Primary::customer(['uuid' => 'cust-2', 'name' => 'Bo']);
    $ledger->putLink(Primary::link('customer', 'cust-2', 'qbo-x', '0'));
    $client->invoices['qb-1']['SyncToken']   = '3';
    $client->invoices['qb-1']['PrivateNote'] = 'Gate code 4';
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2');
});

test('an invoice moved on to another unlinked customer does not follow the first one', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    $settings                                = Primary::shippedSettings();
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    $client->invoices['qb-1']['SyncToken']   = '3';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-y'];
    $ledger->customers['cust-2']             = Primary::customer(['uuid' => 'cust-2', 'name' => 'Bo']);
    $ledger->putLink(Primary::link('customer', 'cust-2', 'qbo-x', '0'));
    $ledger->pending  = [Primary::pending('invoice', 'inv-1', 'customer-linked')];
    $ledger->attempts = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1')
        ->and($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-y')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['kept_customer'])->toBe('qbo-y');
});

test('a fleetbase customer change while kept is sent, and a later link does not undo it', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    $settings                                = Primary::shippedSettings();
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    $ledger->customers['cust-3']  = Primary::customer(['uuid' => 'cust-3', 'name' => 'Cy']);
    $client->customers['qbo-cy']  = Primary::remoteCustomer('0', [
        'Id'               => 'qbo-cy',
        'DisplayName'      => 'Cy',
        'PrimaryEmailAddr' => ['Address' => 'cy@example.test'],
    ]);
    $ledger->putLink(Primary::link('customer', 'cust-3', 'qbo-cy', '0'));
    $ledger->invoices['inv-1']['customer_uuid'] = 'cust-3';
    $ledger->pending                            = [Primary::pending('invoice', 'inv-1')];
    $ledger->attempts                           = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-cy')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-3');

    $ledger->customers['cust-2'] = Primary::customer(['uuid' => 'cust-2', 'name' => 'Bo']);
    $ledger->putLink(Primary::link('customer', 'cust-2', 'qbo-x', '0'));
    $ledger->pending = [Primary::pending('invoice', 'inv-1', 'customer-linked')];
    Primary::runAgain($engine, $ledger, $settings, time() + 240);

    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-3')
        ->and($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-cy');
});

test('an invoice is no longer kept once fleetbase becomes primary', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['kept_customer'])->toBe('qbo-x');

    $fleetbase = Primary::shippedSettings(['invoice_conflict' => 'fleetbase']);
    Primary::runAgain($engine, $ledger, $fleetbase, time() + 120);

    expect($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-customer')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['kept_customer'])->toBeNull();
});

test('an invoice does not follow a customer whose fleetbase customer is deleted', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    $ledger->customers['cust-2'] = Primary::customer([
        'uuid'       => 'cust-2',
        'name'       => 'Bo',
        'deleted_at' => '2026-10-23 10:00:00',
    ]);
    $ledger->putLink(Primary::link('customer', 'cust-2', 'qbo-x', '0'));
    $ledger->pending  = [Primary::pending('invoice', 'inv-1', 'customer-linked')];
    $ledger->attempts = [];
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1')
        ->and($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-x');
});

test('a first link whose difference cannot be sent closes its row', function () {
    [$engine, $client, $ledger]            = Primary::linkedWallet('', '3');
    $client->accounts['acct-9']['AcctNum'] = '100';
    $settings                              = Primary::shippedSettings(['wallet_reference' => 'fleetbase']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect(array_column($ledger->pending, 'status'))->toBe(['done'])
        ->and(Primary::token($ledger, 'wallet', 'wal-1'))->not->toStartWith('~');
});
