<?php

use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks as Primary in two-way sync: when QuickBooks changed a record since a known
 * baseline, it wins the whole record, fields it emptied included. Both sides are then equal,
 * the link stores the new token, and a later Fleetbase-only edit is sent, not reverted.
 */

test('a fleetbase email edit after quickbooks emptied the phone is sent, not reverted', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4', '555-1111', '');
    unset($client->customers['qbo-customer']['PrimaryPhone']);
    $settings          = Primary::shippedSettings();
    $ledger->pending[] = Primary::pending('customer', 'cust-1', 'webhook');
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->customers['cust-1']['phone'])->toBeNull()
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');

    $ledger->customers['cust-1']['email'] = 'ada@new.test';
    $ledger->pending                      = [Primary::pending('customer', 'cust-1')];
    $ledger->attempts                     = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 86400);

    expect($client->calls)->toContain('updateCustomer')
        ->and($client->customers['qbo-customer']['PrimaryEmailAddr']['Address'])->toBe('ada@new.test')
        ->and($ledger->customers['cust-1']['email'])->toBe('ada@new.test')
        ->and(Primary::notes($ledger))->toBe([]);
});

test('a phone quickbooks emptied is emptied in fleetbase by the webhook', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4', '555-1111', '');
    unset($client->customers['qbo-customer']['PrimaryPhone']);

    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');

    expect($ledger->customers['cust-1']['phone'])->toBeNull()
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');
});

test('customer notes and address quickbooks emptied are emptied in fleetbase', function () {
    [$engine, $client, $ledger]             = Primary::linkedCustomer('3', '4', '555-0000', '555-0000');
    $ledger->customers['cust-1']['notes']   = 'Back door';
    $ledger->customers['cust-1']['address'] = ['line1' => '1 Main St', 'city' => 'Springfield'];
    $ledger->pending[]                      = Primary::pending('customer', 'cust-1', 'webhook');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->customers['cust-1']['notes'])->toBeNull()
        ->and(array_filter($ledger->customers['cust-1']['address']))->toBe([])
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');
});

test('a phone fleetbase added while quickbooks changed the email is replaced once, then aligned', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4', '555-1111', '');
    unset($client->customers['qbo-customer']['PrimaryPhone']);
    $client->customers['qbo-customer']['PrimaryEmailAddr'] = ['Address' => 'ada@qb.test'];
    $settings                                              = Primary::shippedSettings();
    $ledger->pending[]                                     = Primary::pending('customer', 'cust-1');
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->customers['cust-1']['email'])->toBe('ada@qb.test')
        ->and($ledger->customers['cust-1']['phone'])->toBeNull()
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4')
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['updated'])
        ->and(Primary::notes($ledger))->toBe([Primary::REPLACED]);

    for ($run = 1; $run <= 3; $run++) {
        $ledger->pending  = [Primary::pending('customer', 'cust-1')];
        $ledger->attempts = [];
        Primary::runAgain($engine, $ledger, $settings, time() + 120 * $run);

        expect(array_column($ledger->attempts, 'outcome'))->toBe(['aligned'])
            ->and(Primary::notes($ledger))->toBe([]);
    }
    expect($client->calls)->not->toContain('updateCustomer')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('4');
});

test('a phone quickbooks emptied is kept in fleetbase without a known baseline', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('', '4', '555-1111', '');
    unset($client->customers['qbo-customer']['PrimaryPhone']);
    $ledger->pending[] = Primary::pending('customer', 'cust-1', 'webhook');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->customers['cust-1']['phone'])->toBe('555-1111');
});

test('a wallet rename after quickbooks emptied the account description is sent, not reverted', function () {
    [$engine, $client, $ledger]                = Primary::linkedWallet('3', '4');
    $client->accounts['acct-9']['Description'] = '';
    $ledger->wallets['wal-1']['description']   = 'Float';
    $settings                                  = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->wallets['wal-1']['description'])->toBe('')
        ->and(Primary::token($ledger, 'wallet', 'wal-1'))->toBe('4');

    $ledger->wallets['wal-1']['name'] = 'Operating 2';
    $ledger->attempts                 = [];
    $client->calls                    = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 600);

    expect($client->calls)->toContain('updateAccount')
        ->and($client->accounts['acct-9']['Name'])->toBe('Operating 2')
        ->and($ledger->wallets['wal-1']['name'])->toBe('Operating 2');
});

test('an account description quickbooks emptied is emptied in fleetbase by the webhook', function () {
    [$engine, $client, $ledger]                = Primary::linkedWallet('3', '4');
    $client->accounts['acct-9']['Description'] = '';
    $ledger->wallets['wal-1']['description']   = 'Float';

    Primary::webhook($engine, $ledger, 'Account', 'acct-9');

    expect($ledger->wallets['wal-1']['description'])->toBe('')
        ->and(Primary::token($ledger, 'wallet', 'wal-1'))->toBe('4');
});

test('a fleetbase line edit after quickbooks emptied the invoice note is sent, not reverted', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', ['notes' => 'Call first'], ['SyncToken' => '2']);
    $settings                   = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->invoices['inv-1']['notes'])->toBe('')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');

    $ledger->invoices['inv-1'] = array_merge($ledger->invoices['inv-1'], Primary::withDetention());
    $ledger->attempts          = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 600);

    expect($client->calls)->toContain('updateInvoice')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Detention 2h')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500);
});

test('an invoice note quickbooks emptied is emptied in fleetbase by the webhook', function () {
    [$engine, , $ledger] = Primary::linkedInvoice('1', ['notes' => 'Call first'], ['SyncToken' => '2']);

    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->invoices['inv-1']['notes'])->toBe('')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');
});

test('a quickbooks win that changed nothing in fleetbase is aligned, without the replaced note', function () {
    [$engine, $client, $ledger]           = Primary::linkedCustomer('', '3', '555-0000', '555-0000');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->pending[]                    = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect(array_column($ledger->attempts, 'outcome'))->toBe(['aligned'])
        ->and(Primary::notes($ledger))->toBe([])
        ->and($ledger->customers['cust-1']['notes'])->toBe('Back door')
        ->and($client->calls)->not->toContain('updateCustomer');
});
