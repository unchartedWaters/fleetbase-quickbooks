<?php

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * A directory over sqlite with the DirectoryTest schema, a cross-company unique invoice number,
 * and a second company's invoice that already holds QB-100.
 *
 * @return array{0: callable, 1: FleetbaseDirectory, 2: SyncLedger}
 */
function atomicSaveSetup(): array
{
    [$restore] = directorySqlite();
    $schema    = DB::connection('sqlite')->getSchemaBuilder();
    directoryWebhookSchema($schema);
    $schema->table('ledger_invoices', function (Blueprint $table) {
        $table->integer('subtotal')->default(0);
        $table->integer('balance')->default(0);
        $table->unique('number');
    });
    directoryWebhookRows();
    $now = now();
    DB::table('ledger_invoices')->insert([
        'uuid'         => 'other-1',
        'company_uuid' => 'company-b',
        'number'       => 'QB-100',
        'status'       => 'sent',
        'currency'     => 'USD',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $directory = new FleetbaseDirectory();
    $loaded    = $directory->load('company-uuid');

    return [$restore, $directory, $loaded['ledger']];
}

test('a quickbooks invoice number that another company already uses is not copied', function () {
    [$restore, $directory, $ledger] = atomicSaveSetup();
    try {
        $ledger->invoices['inv-9']['number'] = 'QB-100';
        $ledger->attempts[]                  = ['company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-9', 'outcome' => 'updated', 'error' => null];
        $ledger->updatePending('company-uuid', 'invoice', 'inv-extra', ['status' => 'done']);

        $directory->save($ledger);

        $attempt = DB::table('quickbooks_sync_attempts')->where('local_uuid', 'inv-9')->first();
        expect(DB::table('ledger_invoices')->where('uuid', 'inv-9')->value('number'))->toBe('inv-9')
            ->and(DB::table('ledger_invoices')->where('uuid', 'other-1')->value('number'))->toBe('QB-100')
            ->and($ledger->invoices['inv-9']['number'])->toBe('inv-9')
            ->and($attempt->outcome)->toBe('updated')
            ->and($attempt->error)->toContain('QB-100')
            ->and($attempt->error)->toContain('already uses it')
            ->and($attempt->error)->toContain('keeps inv-9')
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-1')->value('status'))->toBe('done');
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a free quickbooks invoice number is copied onto the invoice', function () {
    [$restore, $directory, $ledger] = atomicSaveSetup();
    try {
        $ledger->invoices['inv-9']['number'] = 'QB-200';
        $ledger->attempts[]                  = ['company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-9', 'outcome' => 'updated', 'error' => null];

        $directory->save($ledger);

        expect(DB::table('ledger_invoices')->where('uuid', 'inv-9')->value('number'))->toBe('QB-200')
            ->and(DB::table('quickbooks_sync_attempts')->where('local_uuid', 'inv-9')->value('error'))->toBeNull();
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('two invoices in one batch cannot take the same quickbooks number', function () {
    [$restore, $directory, $ledger] = atomicSaveSetup();
    try {
        $ledger->invoices['inv-9']['number']     = 'QB-300';
        $ledger->invoices['inv-extra']['number'] = 'QB-300';

        $directory->save($ledger);

        $numbers = DB::table('ledger_invoices')->whereIn('uuid', ['inv-9', 'inv-extra'])->pluck('number', 'uuid')->all();
        expect($numbers['inv-9'])->toBe('QB-300')
            ->and($numbers['inv-extra'])->toBe('inv-extra');
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a failure while saving an invoice rolls back links pending rows and attempts', function () {
    [$restore, $directory, $ledger] = atomicSaveSetup();
    try {
        DB::statement("CREATE TRIGGER fail_invoice_write BEFORE UPDATE ON ledger_invoices BEGIN SELECT RAISE(ABORT, 'invoice write failed'); END;");
        $ledger->invoices['inv-9']['notes'] = 'Changed in QuickBooks';
        $ledger->putLink(['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-new', 'qbo_entity' => 'Invoice', 'qbo_id' => '700', 'sync_token' => '0']);
        $ledger->updatePending('company-uuid', 'invoice', 'inv-extra', ['status' => 'done']);
        $ledger->attempts[] = ['company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-9', 'outcome' => 'updated', 'error' => null];
        $threw              = false;

        try {
            $directory->save($ledger);
        } catch (Throwable $exception) {
            $threw = true;
        }

        expect($threw)->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('qbo_id', '700')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-1')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_sync_attempts')->count())->toBe(0);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('an in-memory directory still saves without a database transaction', function () {
    $directory         = new FleetbaseDirectory();
    $directory->memory = new SyncLedger();
    $ledger            = new SyncLedger();
    $ledger->invoices['inv-1'] = ['uuid' => 'inv-1', 'company_uuid' => 'company-uuid', 'number' => 'INV-1'];

    $directory->save($ledger);

    expect($directory->memory)->toBe($ledger);
});
