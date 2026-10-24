<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the kept customer migration adds the invoice link column once and can be rolled back', function () {
    $default = config('database.default');
    $sqlite  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::purge('sqlite');
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap(DB::connection('sqlite')->getSchemaBuilder());

    try {
        $files = glob(dirname(__DIR__) . '/migrations/*_add_kept_customer_to_quickbooks_links.php');
        expect($files)->toHaveCount(1)
            ->and(basename($files[0]) > '2026_10_25_120000')->toBeTrue();
        $migration = require $files[0];
        $migration->up();

        Schema::create('quickbooks_links', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->string('company_uuid')->nullable();
            $table->string('realm_id')->nullable();
            $table->string('local_type');
            $table->string('sync_token')->default('0');
        });

        $migration->up();
        $migration->up();
        expect(Schema::hasColumn('quickbooks_links', 'kept_customer'))->toBeTrue()
            ->and(Schema::hasColumn('quickbooks_links', 'kept_from'))->toBeTrue();

        DB::table('quickbooks_links')->insert(['uuid' => 'l1', 'local_type' => 'invoice']);
        expect(DB::table('quickbooks_links')->value('kept_customer'))->toBeNull();

        $migration->down();
        $migration->down();
        expect(Schema::hasColumn('quickbooks_links', 'kept_customer'))->toBeFalse()
            ->and(Schema::hasColumn('quickbooks_links', 'kept_from'))->toBeFalse()
            ->and(Schema::hasColumn('quickbooks_links', 'sync_token'))->toBeTrue();
    } finally {
        DB::purge('sqlite');
        Schema::swap($originalSchema);
        config()->set('database.default', $default);
        config()->set('database.connections.sqlite', $sqlite);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
