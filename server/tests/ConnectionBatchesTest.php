<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('page 1 of sync activity returns meta and omits later batches', function () {
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
    session(['company' => 'company-uuid']);
    $schema = DB::connection('sqlite')->getSchemaBuilder();

    try {
        $schema->create('quickbooks_sync_batches', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->index();
            $table->string('trigger');
            $table->string('direction')->default('outbound');
            $table->string('status');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('aligned_count')->default(0);
            $table->unsignedInteger('voided_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('linked_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        $schema->create('quickbooks_sync_attempts', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('batch_uuid', 36)->nullable()->index();
            $table->char('company_uuid', 36)->index();
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('outcome');
            $table->text('error')->nullable();
            $table->json('diff')->nullable();
            $table->timestamps();
        });

        for ($number = 1; $number <= 26; $number++) {
            $createdAt = Carbon::parse('2026-01-01 00:00:00')->addMinutes($number);
            $batch     = new SyncBatch();
            $batch->fill([
                'uuid'         => sprintf('batch-%02d', $number),
                'company_uuid' => 'company-uuid',
                'trigger'      => 'now',
                'direction'    => 'outbound',
                'status'       => 'finished',
                'created_at'   => $createdAt,
                'updated_at'   => $createdAt,
            ]);
            $batch->save();
        }

        $otherCreatedAt = Carbon::parse('2026-02-01 00:00:00');
        $other          = new SyncBatch();
        $other->fill([
            'uuid'         => 'other-company-batch',
            'company_uuid' => 'other-company',
            'trigger'      => 'now',
            'direction'    => 'outbound',
            'status'       => 'finished',
            'created_at'   => $otherCreatedAt,
            'updated_at'   => $otherCreatedAt,
        ]);
        $other->save();

        foreach (['batch-26' => 'newest failed', 'batch-01' => 'later page failed'] as $batchUuid => $message) {
            $attempt = new SyncAttempt();
            $attempt->fill([
                'uuid'         => 'attempt-' . $batchUuid,
                'batch_uuid'   => $batchUuid,
                'company_uuid' => 'company-uuid',
                'local_type'   => 'invoice',
                'local_uuid'   => 'inv-' . $batchUuid,
                'outcome'      => 'failed',
                'error'        => $message,
            ]);
            $attempt->save();
        }

        $controller = new ConnectionController(
            new Authorizer(static fn () => true),
            new OAuthFlow(new QuickBooksClient()),
            new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
            new MemorySettingsStore(),
            new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
        );
        $pageOne = $controller->batches(Request::create('/batches', 'GET', [
            'page'     => '1',
            'per_page' => '25',
        ]))->getData(true);

        $expected = [];
        for ($number = 26; $number >= 2; $number--) {
            $expected[] = sprintf('batch-%02d', $number);
        }

        expect($pageOne['meta'])->toBe([
            'current_page' => 1,
            'last_page'    => 2,
            'per_page'     => 25,
            'total'        => 26,
        ])
            ->and(array_column($pageOne['batches'], 'uuid'))->toBe($expected)
            ->and(array_column($pageOne['batches'], 'uuid'))->not->toContain('batch-01')
            ->and(array_column($pageOne['batches'], 'uuid'))->not->toContain('other-company-batch')
            ->and($pageOne['batches'][0]['error'])->toBe('newest failed')
            ->and(array_column($pageOne['batches'], 'error'))->not->toContain('later page failed')
            ->and(array_keys($pageOne['batches'][0]))->toBe([
                'uuid',
                'trigger',
                'direction',
                'status',
                'created',
                'created_count',
                'updated',
                'updated_count',
                'aligned',
                'aligned_count',
                'voided',
                'voided_count',
                'unmatched',
                'unmatched_count',
                'failed',
                'failed_count',
                'linked',
                'linked_count',
                'skipped',
                'skipped_count',
                'error',
                'started_at',
                'finished_at',
                'created_at',
            ]);

        $clamped = $controller->batches(Request::create('/batches', 'GET', [
            'page'     => '0',
            'per_page' => '100',
        ]))->getData(true);

        expect($clamped['meta'])->toBe($pageOne['meta'])
            ->and(array_column($clamped['batches'], 'uuid'))->toBe($expected);
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        session(['company' => null]);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
