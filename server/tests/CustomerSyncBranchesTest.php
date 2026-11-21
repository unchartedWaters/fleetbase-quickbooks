<?php

use Fleetbase\Quickbooks\Tests\Support\CustomerLookupFaultQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * Customer branches no other test reached: a first match under QuickBooks reference is copied
 * without an update, a one-way inbound sync copies a difference, a one-way outbound update keeps
 * the QuickBooks name under QuickBooks reference, and a batched update is sent sparse.
 */

test('a first email match under quickbooks reference is copied to fleetbase, not overwritten', function () {
    [$engine, $client]                 = \qbEngine();
    $ledger                            = Primary::ledger();
    $ledger->customers['cust-1']       = Primary::customer(['phone' => '555-1111']);
    $client->customers['qbo-customer'] = Primary::remoteCustomer('0', [
        'PrimaryPhone' => ['FreeFormNumber' => '555-0000'],
    ]);
    $ledger->pending[] = Primary::pending('customer', 'cust-1');

    $settings = Primary::shippedSettings(['customer_conflict' => 'fleetbase']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->customers['cust-1']['phone'])->toBe('555-0000')
        ->and($client->calls)->not->toContain('updateCustomer')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'] ?? null)->toBe('qbo-customer');
});

test('a one-way inbound sync copies a quickbooks difference to fleetbase', function () {
    [$engine, $client, $ledger] = Primary::linkedCustomer('3', '4');
    $ledger->pending[]          = Primary::pending('customer', 'cust-1');
    $settings                   = Primary::shippedSettings([
        'customer_direction' => 'inbound',
        'customer_conflict'  => 'fleetbase',
    ]);

    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($ledger->customers['cust-1']['phone'])->toBe('555-0000')
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('a one-way outbound update under quickbooks reference keeps the quickbooks name', function () {
    [$engine, $client, $ledger]          = Primary::linkedCustomer('3', '3');
    $ledger->customers['cust-1']['name'] = 'Ada Lovelace';
    $ledger->pending[]                   = Primary::pending('customer', 'cust-1');

    $settings = Primary::shippedSettings(['customer_direction' => 'outbound']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($client->customerPayloads)->toHaveCount(1)
        ->and($client->customerPayloads[0]['DisplayName'])->toBe('Ada')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-1111');
});

test('a batched customer update is sent sparse', function () {
    [$engine, $client, $ledger]  = Primary::linkedCustomer('3', '3');
    $ledger->customers['cust-2'] = Primary::customer([
        'uuid'  => 'cust-2',
        'name'  => 'Grace',
        'email' => 'grace@example.test',
    ]);
    $client->customers['qbo-2']  = Primary::remoteCustomer('3', [
        'Id'               => 'qbo-2',
        'DisplayName'      => 'Grace',
        'PrimaryEmailAddr' => ['Address' => 'grace@example.test'],
    ]);
    $ledger->links[]   = Primary::link('customer', 'cust-2', 'qbo-2', '3');
    $ledger->pending[] = Primary::pending('customer', 'cust-1');
    $ledger->pending[] = Primary::pending('customer', 'cust-2');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    $sent = array_values(array_filter(
        $client->customerPayloads,
        fn (array $payload): bool => $payload['Id'] === 'qbo-customer'
    ));
    expect($client->calls)->toContain('batch')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['sparse'] ?? null)->toBeTrue()
        ->and($sent[0]['PrimaryPhone']['FreeFormNumber'] ?? null)->toBe('555-1111');
});

// Two linked customers in one block, both edited in Fleetbase, or both deleted there.
$customerBlock = static function (CustomerLookupFaultQuickBooks $client, bool $deleted): array {
    [$engine]                          = \qbEngine($client);
    $ledger                            = Primary::ledger();
    $ledger->customers['cust-1']       = Primary::customer(['phone' => '555-1111']);
    $ledger->customers['cust-2']       = Primary::customer([
        'uuid'  => 'cust-2',
        'name'  => 'Grace',
        'email' => 'grace@example.test',
    ]);
    $client->customers['qbo-customer'] = Primary::remoteCustomer('3');
    $client->customers['qbo-2']        = Primary::remoteCustomer('3', ['Id' => 'qbo-2', 'DisplayName' => 'Grace']);
    foreach (['cust-1' => 'qbo-customer', 'cust-2' => 'qbo-2'] as $uuid => $remoteId) {
        if ($deleted === true) {
            $ledger->customers[$uuid]['deleted_at'] = '2026-11-01 00:00:00';
        }
        $ledger->links[]   = Primary::link('customer', $uuid, $remoteId, '3');
        $ledger->pending[] = Primary::pending('customer', $uuid);
    }

    return [$engine, $ledger];
};

test('a halted customer lookup stops the block at its first row', function () use ($customerBlock) {
    $client                = new CustomerLookupFaultQuickBooks();
    $client->lookupResults = [
        'customer:cust-2' => ['ok' => false, 'rows' => [], 'error' => 'slow down', 'status' => 429, 'halt' => true],
    ];
    [$engine, $ledger] = $customerBlock($client, false);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toBe(['batch', 'batchChunk:2'])
        ->and(array_column($ledger->attempts, 'local_uuid'))->toBe(['cust-1'])
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['failed']);
});

test('a deleted customer whose lookup failed is not made inactive', function () use ($customerBlock) {
    $client                = new CustomerLookupFaultQuickBooks();
    $client->lookupResults = [
        'customer:cust-1' => ['ok' => false, 'rows' => [], 'error' => 'boom', 'status' => 500, 'halt' => false],
    ];
    [$engine, $ledger] = $customerBlock($client, true);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    $rows = array_column($ledger->pending, null, 'local_uuid');
    expect($client->customers['qbo-customer'])->not->toHaveKey('Active')
        ->and($client->calls)->not->toContain('getCustomer:qbo-customer')
        ->and($rows['cust-1']['status'])->not->toBe('done');
});

test('deleted customers in a block are made inactive from the batched lookup', function () use ($customerBlock) {
    [$engine, $ledger] = $customerBlock($client = new CustomerLookupFaultQuickBooks(), true);

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->customers['qbo-customer']['Active'])->toBeFalse()
        ->and($client->customers['qbo-2']['Active'])->toBeFalse()
        ->and(array_filter($client->calls, fn (string $call): bool => str_starts_with($call, 'getCustomer:')))
        ->toBe([]);
});
