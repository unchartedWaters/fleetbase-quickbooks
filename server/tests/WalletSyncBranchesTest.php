<?php

use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * Wallet branches no other test reached: the batched update is sent sparse, a Fleetbase-led
 * update sends a cleared description, QuickBooks reference keeps the account number, and a
 * new account's currency is copied back under QuickBooks as Primary.
 */

test('a batched wallet update is sent sparse with the cleared description fleetbase leads', function () {
    [$engine, $client, $ledger]              = Primary::linkedWallet('3', '3');
    $ledger->wallets['wal-1']['name']        = 'Operating 2';
    $ledger->wallets['wal-1']['description'] = '';
    $ledger->wallets['wal-2']                = array_merge($ledger->wallets['wal-1'], [
        'uuid'      => 'wal-2',
        'public_id' => 'wallet_wal-2',
        'name'      => 'Reserve',
    ]);
    $client->accounts['acct-8'] = array_merge($client->accounts['acct-9'], ['Id' => 'acct-8', 'Name' => 'Reserve']);
    $ledger->links[]            = Primary::link('wallet', 'wal-2', 'acct-8', '3');
    $ledger->pending[]          = Primary::pending('wallet', 'wal-2');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    $sent = array_values(array_filter(
        $client->accountPayloads,
        fn (array $payload): bool => $payload['Id'] === 'acct-9'
    ));
    expect($client->calls)->toContain('batch')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['sparse'] ?? null)->toBeTrue()
        ->and($sent[0]['Name'])->toBe('Operating 2')
        ->and($sent[0]['Description'] ?? null)->toBe('');
});

test('a wallet update under quickbooks reference keeps the quickbooks account number', function () {
    [$engine, $client, $ledger]             = Primary::linkedWallet('3', '3');
    $ledger->wallets['wal-1']['name']       = 'Operating 2';
    $client->accounts['acct-9']['AcctNum']  = '1200';

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->accountPayloads)->toHaveCount(1)
        ->and($client->accountPayloads[0]['Name'])->toBe('Operating 2')
        ->and($client->accountPayloads[0]['AcctNum'] ?? null)->toBe('1200');
});

test('a new wallet without a currency takes the home currency under quickbooks as primary', function () {
    [$engine, $client]        = \qbEngine();
    $ledger                   = Primary::ledger();
    $ledger->wallets['wal-1'] = [
        'uuid'         => 'wal-1',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_wal-1',
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => '',
        'status'       => 'active',
    ];
    $ledger->pending[] = Primary::pending('wallet', 'wal-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('createAccount')
        ->and($ledger->wallets['wal-1']['currency'])->toBe('USD');
});
