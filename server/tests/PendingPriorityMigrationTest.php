<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the pending priority migration adds the column and the due index once and can be rolled back', function () {
    $default = config('database.default');
    $sqlite  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::purge('sqlite');
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap(DB::connection('sqlite')->getSchemaBuilder());

    try {
        $files = glob(dirname(__DIR__) . '/migrations/*_add_priority_to_quickbooks_pending_syncs.php');
        expect($files)->toHaveCount(1)
            ->and(basename($files[0]) > '2026_10_23_120000')->toBeTrue();
        $migration = require $files[0];
        $migration->up();

        Schema::create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('status')->default('pending');
            $table->timestamp('next_attempt_at')->nullable();
            $table->index(['company_uuid', 'status', 'next_attempt_at', 'uuid'], 'quickbooks_pending_due');
        });
        $newShape = ['company_uuid', 'status', 'priority', 'next_attempt_at', 'uuid'];

        $migration->up();
        $migration->up();
        expect(Schema::hasColumn('quickbooks_pending_syncs', 'priority'))->toBeTrue()
            ->and(Schema::hasIndex('quickbooks_pending_syncs', 'quickbooks_pending_due'))->toBeTrue()
            ->and(Schema::hasIndex('quickbooks_pending_syncs', $newShape))->toBeTrue();

        DB::table('quickbooks_pending_syncs')->insert(['uuid' => 'p1', 'company_uuid' => 'c1']);
        expect((int) DB::table('quickbooks_pending_syncs')->value('priority'))->toBe(0);

        $migration->down();
        expect(Schema::hasColumn('quickbooks_pending_syncs', 'priority'))->toBeFalse()
            ->and(Schema::hasIndex('quickbooks_pending_syncs', ['company_uuid', 'status', 'next_attempt_at', 'uuid']))
            ->toBeTrue();
    } finally {
        DB::purge('sqlite');
        Schema::swap($originalSchema);
        config()->set('database.default', $default);
        config()->set('database.connections.sqlite', $sqlite);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
