<?php

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Batch;

test('a new batch starts with every counter at 0 and no times', function () {
    expect(new Batch('manual'))->toMatchObject([
        'trigger'     => 'manual',
        'status'      => 'finished',
        'direction'   => 'outbound',
        'companyUuid' => null,
        'created'     => 0,
        'updated'     => 0,
        'aligned'     => 0,
        'voided'      => 0,
        'unmatched'   => 0,
        'failed'      => 0,
        'linked'      => 0,
        'skipped'     => 0,
        'continue'    => false,
        'startedAt'   => null,
        'finishedAt'  => null,
    ]);
});

test('a batch for a company carries its status, direction and times', function () {
    expect(Batch::forCompany('company-1', 'import', 'running', 'inbound', 10, null))->toMatchObject([
        'companyUuid' => 'company-1',
        'trigger'     => 'import',
        'status'      => 'running',
        'direction'   => 'inbound',
        'startedAt'   => 10,
        'finishedAt'  => null,
    ]);
});

test('a row is counted under its outcome, taken back down to 0 at most, and unknown ones are ignored', function () {
    $batch = new Batch('now');
    foreach (['created', 'updated', 'aligned', 'voided', 'failed', 'linked', 'skipped'] as $outcome) {
        $batch->add($outcome);
    }
    $batch->add('created', 2);
    $batch->add('updated', -1);
    $batch->add('aligned', -5);
    $batch->add('unmatched');
    $batch->add('nonsense');

    expect($batch)->toMatchObject([
        'created'   => 3,
        'updated'   => 0,
        'aligned'   => 0,
        'voided'    => 1,
        'unmatched' => 0,
        'failed'    => 1,
        'linked'    => 1,
        'skipped'   => 1,
    ]);
});

test('a skipped or failed run is one batch and one connection attempt that agree', function () {
    $ledger  = new SyncLedger();
    $skipped = $ledger->skip('company-1', 'scheduled', 'outbound', 'quickbooks.messages.sync-running', 5);
    $failed  = $ledger->fail('company-1', 'now', 'inbound', 'quickbooks.messages.save-failed', 6);

    expect($ledger->batches)->toBe([$skipped, $failed])
        ->and($skipped)->toMatchObject(['status' => 'skipped', 'failed' => 0, 'startedAt' => 5, 'finishedAt' => 5])
        ->and($failed)->toMatchObject(['status' => 'failed', 'failed' => 1, 'direction' => 'inbound', 'startedAt' => 6])
        ->and(array_column($ledger->attempts, 'outcome'))->toBe(['skipped', 'failed'])
        ->and(array_column($ledger->attempts, 'error'))
        ->toBe(['quickbooks.messages.sync-running', 'quickbooks.messages.save-failed']);
});
