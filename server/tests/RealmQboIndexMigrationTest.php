<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the realm and qbo id index migration adds the index once and can be rolled back', function () {
    $default = config('database.default');
    $sqlite  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::purge('sqlite');
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap(DB::connection('sqlite')->getSchemaBuilder());

    try {
        $files = glob(dirname(__DIR__) . '/migrations/*_add_realm_qbo_index_to_quickbooks_links.php');
        expect($files)->toHaveCount(1)
            ->and(basename($files[0]) > '2026_10_09_120000')->toBeTrue();

        $migration = require $files[0];
        // The guard makes it a no-op before the table exists.
        $migration->up();

        Schema::create('quickbooks_links', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id');
            $table->string('qbo_id');
        });

        $migration->up();
        $migration->up();
        expect(Schema::hasIndex('quickbooks_links', 'quickbooks_links_realm_qbo'))->toBeTrue()
            ->and(Schema::hasIndex('quickbooks_links', ['realm_id', 'qbo_id']))->toBeTrue();

        $migration->down();
        expect(Schema::hasIndex('quickbooks_links', 'quickbooks_links_realm_qbo'))->toBeFalse();
    } finally {
        DB::purge('sqlite');
        Schema::swap($originalSchema);
        config()->set('database.default', $default);
        config()->set('database.connections.sqlite', $sqlite);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
