<?php

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\PaymentBatchFaultQuickBooks;

/*
 * Three invoices in one block: inv-1 is open (no payment), inv-2 and inv-3 are paid,
 * so their two payments go to QuickBooks in one batch after the invoices are sent.
 */
$paymentBlock = static function (Throwable $fault): array {
    $client                            = new PaymentBatchFaultQuickBooks();
    $client->paymentBatchFault         = $fault;
    [$engine]                          = qbEngine($client);
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    foreach (['inv-1' => 'open', 'inv-2' => 'paid', 'inv-3' => 'paid'] as $uuid => $status) {
        $ledger->invoices[$uuid] = invoiceFixture($uuid, 1000, $status, 'INV-' . $uuid);
        $ledger->pending[]       = pendingRow('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    return [$batch, $ledger, $client];
};

test('a 401 on the payment batch records the invoices before it and asks to reconnect', function () use ($paymentBlock) {
    /** @var SyncLedger $ledger */
    [$batch, $ledger, $client] = $paymentBlock(new QuickBooksException(401, qbRequestFailed(401)));
    $rows                      = array_column($ledger->pending, null, 'local_uuid');

    expect($client->calls)->toContain('batch')
        ->and($client->calls)->not->toContain('createPayment')
        ->and($rows['inv-1']['status'])->toBe('done')
        ->and($rows['inv-2']['status'])->toBe('pending')
        ->and($rows['inv-2']['attempts'])->toBe(0)
        ->and($rows['inv-3']['status'])->toBe('pending')
        ->and($rows['inv-3']['attempts'])->toBe(0)
        ->and($batch['failed'])->toBe(1)
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue()
        ->and(end($ledger->attempts)['error'])->toBe(SyncEngine::TOKEN_REJECTED_MESSAGE);
});

test('a 429 on the payment batch fails the first paid invoice and leaves the rest pending', function () use ($paymentBlock) {
    /** @var SyncLedger $ledger */
    [$batch, $ledger] = $paymentBlock(new QuickBooksException(429, 'slow down', '30'));
    $rows             = array_column($ledger->pending, null, 'local_uuid');

    expect($rows['inv-1']['status'])->toBe('done')
        ->and($rows['inv-2']['status'])->toBe('pending')
        ->and($rows['inv-2']['attempts'])->toBe(0)
        ->and($rows['inv-3']['status'])->toBe('pending')
        ->and($rows['inv-3']['attempts'])->toBe(0)
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeFalse()
        ->and(array_column($ledger->attempts, 'local_uuid'))->toContain('inv-2')
        ->and(array_column($ledger->attempts, 'local_uuid'))->not->toContain('inv-3');
});

test('any other payment batch failure fails every paid invoice and records the rest', function () use ($paymentBlock) {
    /** @var SyncLedger $ledger */
    [$batch, $ledger] = $paymentBlock(new RuntimeException('connection reset'));
    $rows             = array_column($ledger->pending, null, 'local_uuid');
    $failed           = array_values(array_filter($ledger->attempts, static fn (array $attempt): bool => $attempt['local_uuid'] !== 'inv-1'));

    expect($rows['inv-1']['status'])->toBe('done')
        ->and($rows['inv-2']['attempts'])->toBe(1)
        ->and($rows['inv-3']['attempts'])->toBe(1)
        ->and(array_column($failed, 'local_uuid'))->toBe(['inv-2', 'inv-3'])
        ->and(array_unique(array_column($failed, 'error')))->toBe(['quickbooks.messages.sync-failed'])
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeFalse();
});
