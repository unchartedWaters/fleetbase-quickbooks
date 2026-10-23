<?php

use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * A QuickBooks payment can pay several invoices. Under QuickBooks as Primary each invoice
 * keeps the payment's SyncToken it last synced, so syncing one invoice does not hide a
 * QuickBooks change from the others.
 */

test('a shared payment changed in quickbooks reaches every invoice it pays', function () {
    [$engine, , $ledger] = Primary::sharedPayment();
    $ledger->pending     = [
        Primary::pending('invoice', 'inv-1', 'webhook'),
        Primary::pending('invoice', 'inv-2', 'webhook'),
    ];

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(400)
        ->and($ledger->invoices['inv-2']['amount_paid'])->toBe(300);
});

test('a shared payment applied from a webhook still reaches the other invoice it pays', function () {
    [$engine, , $ledger] = Primary::sharedPayment();

    Primary::webhook($engine, $ledger, 'Payment', 'pay-1');
    $ledger->pending = [
        Primary::pending('invoice', 'inv-1', 'webhook'),
        Primary::pending('invoice', 'inv-2', 'webhook'),
    ];
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->invoices['inv-1']['amount_paid'])->toBe(400)
        ->and($ledger->invoices['inv-2']['amount_paid'])->toBe(300);
});

test('a shared payment synced for every invoice it pays is no longer a quickbooks change', function () {
    [$engine, $client, $ledger] = Primary::sharedPayment();
    $ledger->pending            = [
        Primary::pending('invoice', 'inv-1', 'webhook'),
        Primary::pending('invoice', 'inv-2', 'webhook'),
    ];
    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());
    $ledger->invoices['inv-2']['amount_paid'] = 700;
    $ledger->invoices['inv-2']['status']      = 'paid';

    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    // QuickBooks did not change the payment again, so Fleetbase leads; a shared payment is not sent.
    expect($ledger->invoices['inv-2']['amount_paid'])->toBe(700)
        ->and($client->calls)->not->toContain('updatePayment');
});
