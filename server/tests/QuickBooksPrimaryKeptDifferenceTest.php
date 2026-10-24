<?php

use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks as Primary in two-way sync: two QuickBooks changes cannot be copied into
 * Fleetbase. An invoice moved to a QuickBooks customer Fleetbase has not linked keeps its
 * Fleetbase customer, and a wallet that still holds money stays open when its account is made
 * inactive. Both count as in sync once noted, so a later Fleetbase-only edit is sent, and the
 * push keeps the QuickBooks customer and leaves the account inactive.
 */

test('a fleetbase line edit on an invoice quickbooks moved to an unlinked customer is sent there', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    $settings                                = Primary::shippedSettings();
    $ledger->pending                         = [Primary::pending('invoice', 'inv-1', 'webhook')];
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');

    $ledger->invoices['inv-1'] = array_merge($ledger->invoices['inv-1'], Primary::withDetention());
    $ledger->pending           = [Primary::pending('invoice', 'inv-1')];
    $ledger->attempts          = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 86400);

    expect($client->calls)->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['CustomerRef']['value'])->toBe('qbo-x')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Detention 2h')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1500)
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1');
});

test('an invoice quickbooks moved to an unlinked customer is in sync after its webhook', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];

    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-1');
});

test('a wallet rename after quickbooks made its account inactive is sent, leaving the account inactive', function () {
    [$engine, $client, $ledger]           = Primary::linkedWallet('3', '4');
    $client->accounts['acct-9']['Active'] = false;
    $ledger->wallets['wal-1']['balance']  = 500;
    $settings                             = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->wallets['wal-1']['status'])->toBe('active')
        ->and(Primary::token($ledger, 'wallet', 'wal-1'))->toBe('4');

    $ledger->wallets['wal-1']['name'] = 'Operating 2';
    $ledger->attempts                 = [];
    Primary::runAgain($engine, $ledger, $settings, time() + 600);
    Primary::runAgain($engine, $ledger, $settings, time() + 1200);

    expect(Primary::count($client, 'updateAccount'))->toBe(1)
        ->and($client->accounts['acct-9']['Name'])->toBe('Operating 2')
        ->and($client->accounts['acct-9']['Active'])->toBeFalse()
        ->and($ledger->wallets['wal-1']['name'])->toBe('Operating 2')
        ->and($ledger->wallets['wal-1']['status'])->toBe('active');
});

test('an account quickbooks made inactive is in sync after its webhook while the wallet holds money', function () {
    [$engine, $client, $ledger]           = Primary::linkedWallet('3', '4');
    $client->accounts['acct-9']['Active'] = false;
    $ledger->wallets['wal-1']['balance']  = 500;

    Primary::webhook($engine, $ledger, 'Account', 'acct-9');

    expect(Primary::token($ledger, 'wallet', 'wal-1'))->toBe('4')
        ->and($ledger->wallets['wal-1']['status'])->toBe('active');
});
