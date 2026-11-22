<?php

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;

/*
 * Two paid invoices in one block, so their two payments go to QuickBooks in one batch.
 * QuickBooks refuses only the first payment item of that batch.
 */
$itemFault = static function (QuickBooksException $fault): array {
    $client                            = new FakeQuickBooks();
    $client->beforeCreatePayment       = static function (FakeQuickBooks $fake) use ($fault): void {
        if (count(array_keys($fake->calls, 'createPayment', true)) === 1) {
            throw $fault;
        }
    };
    [$engine]                          = qbEngine($client);
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada'];
    foreach (['inv-1', 'inv-2'] as $uuid) {
        $ledger->invoices[$uuid] = invoiceFixture($uuid, 1000, 'paid', 'INV-' . $uuid);
        $ledger->pending[]       = pendingRow('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    return [$batch, $ledger, $client];
};

test('a refused payment item fails its invoice and the next payment is still sent', function () use ($itemFault) {
    /** @var SyncLedger $ledger */
    [$batch, $ledger, $client] = $itemFault(new QuickBooksException(400, ''));
    $rows                      = array_column($ledger->pending, null, 'local_uuid');

    expect(array_keys($client->calls, 'createPayment', true))->toHaveCount(2)
        ->and($rows['inv-1']['status'])->toBe('pending')
        ->and($rows['inv-1']['attempts'])->toBe(1)
        ->and($rows['inv-2']['status'])->toBe('done')
        ->and(array_column($ledger->attempts, 'outcome', 'local_uuid'))
        ->toBe(['inv-1' => 'failed', 'inv-2' => 'created'])
        ->and($batch->failed)->toBe(1)
        ->and($ledger->connections['company-uuid']['rate_limited_until'] ?? null)->toBeNull();
});

test('a rate-limited payment item stops the batch and leaves the next invoice pending', function () use ($itemFault) {
    /** @var SyncLedger $ledger */
    [$batch, $ledger, $client] = $itemFault(new QuickBooksException(429, 'slow down', '30'));
    $rows                      = array_column($ledger->pending, null, 'local_uuid');

    expect(array_keys($client->calls, 'createPayment', true))->toHaveCount(1)
        ->and($rows['inv-1']['status'])->toBe('pending')
        ->and($rows['inv-1']['attempts'])->toBe(0)
        ->and($rows['inv-2']['status'])->toBe('pending')
        ->and($rows['inv-2']['attempts'])->toBe(0)
        ->and(array_column($ledger->attempts, 'local_uuid'))->toBe(['inv-1'])
        ->and($batch->failed)->toBe(1)
        ->and($ledger->connections['company-uuid']['rate_limited_until'])->toBeGreaterThan(time())
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeFalse();
});

test('a rejected token on a payment item stops the batch and asks to reconnect', function () use ($itemFault) {
    /** @var SyncLedger $ledger */
    [, $ledger] = $itemFault(new QuickBooksException(401, qbRequestFailed(401)));
    $rows       = array_column($ledger->pending, null, 'local_uuid');

    expect($rows['inv-1']['status'])->toBe('pending')
        ->and($rows['inv-1']['attempts'])->toBe(0)
        ->and($rows['inv-2']['status'])->toBe('pending')
        ->and($rows['inv-2']['attempts'])->toBe(0)
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue();
});
