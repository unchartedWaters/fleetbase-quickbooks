<?php

use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;
use Fleetbase\Quickbooks\Tests\Support\TakenNameQuickBooks;

/*
 * QuickBooks as Primary in two-way sync sends a Fleetbase customer rename. When QuickBooks
 * refuses the name because another customer, vendor or employee uses it (fault 6240), the
 * update is sent once more without the name, so the other Fleetbase changes still reach
 * QuickBooks, and Activity names the customer whose name was refused.
 */

test('a refused rename still sends the other fleetbase changes of the customer', function () {
    $client                              = new TakenNameQuickBooks();
    [$engine, $client, $ledger]          = Primary::linkedCustomer('3', '3', '555-9999', '555-0000', $client);
    $ledger->customers['cust-1']['name'] = 'Bob';
    $ledger->pending[]                   = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('updateCustomer:6240')
        ->and($client->customerUpdates)->toHaveCount(1)
        ->and($client->customerUpdates[0])->not->toHaveKey('DisplayName')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-9999')
        ->and($client->customers['qbo-customer']['DisplayName'])->toBe('Ada')
        ->and($ledger->customers['cust-1']['name'])->toBe('Bob')
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['updated'])
        ->and(Primary::notes($ledger))->toBe([TakenNameQuickBooks::NOTE]);
});

test('a refused rename in a block still sends the other fleetbase changes', function () {
    $client                              = new TakenNameQuickBooks();
    [$engine, $client, $ledger]          = Primary::linkedCustomer('3', '3', '555-9999', '555-0000', $client);
    $ledger->customers['cust-1']['name'] = 'Bob';
    $ledger->customers['cust-2']         = Primary::customer(['uuid' => 'cust-2', 'name' => 'Cy', 'email' => 'cy@b.c']);
    $client->customers['qbo-cy']         = Primary::remoteCustomer('1', ['Id' => 'qbo-cy', 'DisplayName' => 'Cy']);
    $ledger->links[]                     = Primary::link('customer', 'cust-2', 'qbo-cy', '1');
    $ledger->pending[]                   = Primary::pending('customer', 'cust-1');
    $ledger->pending[]                   = Primary::pending('customer', 'cust-2');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    $outcomes = array_column($ledger->attempts, 'outcome', 'local_uuid');

    expect($client->calls)->toContain('batch')
        ->and($client->customers['qbo-customer']['PrimaryPhone']['FreeFormNumber'])->toBe('555-9999')
        ->and($client->customers['qbo-customer']['DisplayName'])->toBe('Ada')
        ->and($client->customers['qbo-cy']['PrimaryEmailAddr']['Address'])->toBe('cy@b.c')
        ->and($outcomes)->toBe(['cust-1' => 'updated', 'cust-2' => 'updated'])
        ->and(Primary::notes($ledger))->toBe([TakenNameQuickBooks::NOTE]);
});

test('a refused rename with nothing else to send fails as before, and a sent change is not sent again', function () {
    $client                              = new TakenNameQuickBooks();
    [$engine, $client, $ledger]          = Primary::linkedCustomer('3', '3', '555-9999', '555-0000', $client);
    $ledger->customers['cust-1']['name'] = 'Bob';
    $ledger->pending[]                   = Primary::pending('customer', 'cust-1');
    $settings                            = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    Primary::webhook($engine, $ledger, 'Customer', 'qbo-customer');
    $ledger->pending  = [Primary::pending('customer', 'cust-1', 'webhook')];
    $ledger->attempts = [];

    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect(Primary::count($client, 'updateCustomer:6240'))->toBe(2)
        ->and($client->customerUpdates)->toHaveCount(1)
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['failed'])
        ->and(Primary::notes($ledger))->toBe([TakenNameQuickBooks::NOTE]);
});
