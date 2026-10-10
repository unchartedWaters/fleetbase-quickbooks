<?php

use Fleetbase\Quickbooks\Console\Commands\PruneQuickbooks;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @return array{0: callable, 1: BufferedOutput}
 */
function pruneSetup(): array
{
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');
    $schema = DB::connection('sqlite')->getSchemaBuilder();
    $schema->create('quickbooks_sync_batches', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('trigger');
        $table->string('direction')->default('outbound');
        $table->string('status');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });
    $schema->create('quickbooks_sync_attempts', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('batch_uuid', 36)->nullable();
        $table->char('company_uuid', 36);
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->string('outcome');
        $table->text('error')->nullable();
        $table->json('diff')->nullable();
        $table->timestamps();
    });
    $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->string('reason')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('next_attempt_at')->nullable();
        $table->timestamps();
    });
    $schema->create('quickbooks_links', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('realm_id');
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->string('qbo_entity');
        $table->string('qbo_id');
        $table->timestamps();
    });

    return [function () use ($defaultConnection, $sqliteConnection): void {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('quickbooks.retention', ['attempt_days' => 90, 'batch_days' => 180, 'pending_days' => 30]);
    }, new BufferedOutput()];
}

function pruneRun(BufferedOutput $output): int
{
    $command = new PruneQuickbooks();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    return $command->handle();
}

function pruneDaysAgo(int $days): string
{
    return Carbon::now()->subDays($days)->toDateTimeString();
}

function pruneRows(): void
{
    foreach ([['att-old', 'batch-old', 100], ['att-new', 'batch-new', 10], ['att-keeps-old-batch', 'batch-old-kept', 5]] as [$uuid, $batch, $age]) {
        DB::table('quickbooks_sync_attempts')->insert([
            'uuid'    => $uuid, 'batch_uuid' => $batch, 'company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
            'outcome' => 'aligned', 'created_at' => pruneDaysAgo($age), 'updated_at' => pruneDaysAgo($age),
        ]);
    }
    foreach ([['batch-old', 'finished', 200], ['batch-old-kept', 'finished', 200], ['batch-old-running', 'running', 200], ['batch-mid', 'finished', 120], ['batch-new', 'finished', 10], ['batch-no-attempts', 'finished', 200], ['batch-skipped-old', 'skipped', 200], ['batch-skipped-new', 'skipped', 10]] as [$uuid, $status, $age]) {
        DB::table('quickbooks_sync_batches')->insert([
            'uuid'       => $uuid, 'company_uuid' => 'company-uuid', 'trigger' => 'scheduled', 'status' => $status,
            'created_at' => pruneDaysAgo($age), 'updated_at' => pruneDaysAgo($age),
        ]);
    }
    foreach ([['pend-done-old', 'done', 40], ['pend-done-new', 'done', 5], ['pend-pending-old', 'pending', 400], ['pend-failed-old', 'failed', 400]] as [$uuid, $status, $age]) {
        DB::table('quickbooks_pending_syncs')->insert([
            'uuid'       => $uuid, 'company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => $status,
            'created_at' => pruneDaysAgo($age), 'updated_at' => pruneDaysAgo($age),
        ]);
    }
    DB::table('quickbooks_links')->insert([
        'uuid'       => 'link-old', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity' => 'Invoice', 'qbo_id' => '8', 'created_at' => pruneDaysAgo(900), 'updated_at' => pruneDaysAgo(900),
    ]);
}

test('prune deletes attempts older than the retention and keeps newer ones', function () {
    [$restore, $output] = pruneSetup();
    try {
        pruneRows();

        pruneRun($output);

        $attempts = DB::table('quickbooks_sync_attempts')->orderBy('uuid')->pluck('uuid')->all();
        expect($attempts)->toBe(['att-keeps-old-batch', 'att-new']);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('prune deletes only finished or skipped batches past the retention that have no attempts left', function () {
    [$restore, $output] = pruneSetup();
    try {
        pruneRows();

        pruneRun($output);

        $batches = DB::table('quickbooks_sync_batches')->orderBy('uuid')->pluck('uuid')->all();
        expect($batches)->toBe(['batch-mid', 'batch-new', 'batch-old-kept', 'batch-old-running', 'batch-skipped-new']);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('prune deletes only done pending rows and never touches links', function () {
    [$restore, $output] = pruneSetup();
    try {
        pruneRows();

        $status = pruneRun($output);

        $pending = DB::table('quickbooks_pending_syncs')->orderBy('uuid')->pluck('uuid')->all();
        expect($status)->toBe(0)
            ->and($pending)->toBe(['pend-done-new', 'pend-failed-old', 'pend-pending-old'])
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-old')->exists())->toBeTrue()
            ->and($output->fetch())->toContain('Pruned 1 attempts, 3 batches, and 1 done pending rows.');
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('prune follows the configured retention and zero keeps history forever', function () {
    [$restore, $output] = pruneSetup();
    try {
        pruneRows();
        config()->set('quickbooks.retention', ['attempt_days' => 7, 'batch_days' => 0, 'pending_days' => 1]);

        pruneRun($output);

        expect(DB::table('quickbooks_sync_attempts')->pluck('uuid')->all())->toBe(['att-keeps-old-batch'])
            ->and(DB::table('quickbooks_sync_batches')->count())->toBe(8)
            ->and(DB::table('quickbooks_pending_syncs')->orderBy('uuid')->pluck('uuid')->all())->toBe(['pend-failed-old', 'pend-pending-old']);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('prune works through more rows than one chunk', function () {
    [$restore, $output] = pruneSetup();
    try {
        $rows = [];
        for ($index = 0; $index < 2500; $index++) {
            $rows[] = [
                'uuid'    => 'att-' . $index, 'batch_uuid' => null, 'company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
                'outcome' => 'aligned', 'created_at' => pruneDaysAgo(120), 'updated_at' => pruneDaysAgo(120),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('quickbooks_sync_attempts')->insert($chunk);
        }

        pruneRun($output);

        expect(DB::table('quickbooks_sync_attempts')->count())->toBe(0);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
