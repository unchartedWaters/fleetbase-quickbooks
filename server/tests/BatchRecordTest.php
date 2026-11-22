<?php

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Batch;

test('a new batch starts with every counter at 0 and no times', function () {
    $batch = new Batch('manual');

    expect([$batch->trigger, $batch->status, $batch->direction, $batch->companyUuid])->toBe(['manual', 'finished', 'outbound', null])
        ->and([$batch->created, $batch->updated, $batch->aligned, $batch->voided, $batch->unmatched, $batch->failed, $batch->linked, $batch->skipped])
        ->toBe([0, 0, 0, 0, 0, 0, 0, 0])
        ->and([$batch->continue, $batch->startedAt, $batch->finishedAt])->toBe([false, null, null]);
});

test('a batch for a company carries its status, direction and times', function () {
    $batch = Batch::forCompany('company-1', 'import', 'running', 'inbound', 10, null);

    expect([$batch->companyUuid, $batch->trigger, $batch->status, $batch->direction, $batch->startedAt, $batch->finishedAt])
        ->toBe(['company-1', 'import', 'running', 'inbound', 10, null]);
});

test('a row is counted under its outcome, taken back without going below 0, and an unknown outcome is ignored', function () {
    $batch = new Batch('now');
    foreach (['created', 'updated', 'aligned', 'voided', 'failed', 'linked', 'skipped'] as $outcome) {
        $batch->add($outcome);
    }
    $batch->add('created', 2);
    $batch->add('updated', -1);
    $batch->add('aligned', -5);
    $batch->add('unmatched');
    $batch->add('nonsense');

    expect([$batch->created, $batch->updated, $batch->aligned, $batch->voided, $batch->unmatched, $batch->failed, $batch->linked, $batch->skipped])
        ->toBe([3, 0, 0, 1, 0, 1, 1, 1]);
});

test('a skipped or failed run is one batch and one connection attempt that agree', function () {
    $ledger  = new SyncLedger();
    $skipped = $ledger->skip('company-1', 'scheduled', 'outbound', 'quickbooks.messages.sync-running', 5);
    $failed  = $ledger->fail('company-1', 'now', 'inbound', 'quickbooks.messages.save-failed', 6);

    expect($ledger->batches)->toBe([$skipped, $failed])
        ->and([$skipped->status, $skipped->failed, $skipped->startedAt, $skipped->finishedAt])->toBe(['skipped', 0, 5, 5])
        ->and([$failed->status, $failed->failed, $failed->direction, $failed->startedAt, $failed->finishedAt])->toBe(['failed', 1, 'inbound', 6, 6])
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['skipped', 'failed'])
        ->and(array_column($ledger->attempts, 'error'))->toBe(['quickbooks.messages.sync-running', 'quickbooks.messages.save-failed']);
});
