<?php

use Fleetbase\Quickbooks\Support\ActivityErrors;
use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/*
 * Activity names the product an error is about: a Storefront product by its name, a product name
 * row by the name its QuickBooks product link keeps.
 */

test('a product error is labeled with the product name', function () {
    expect(ActivityErrors::labeled(
        'product',
        'Mug',
        'QuickBooks refused it'
    ))->toBe('Product "Mug": QuickBooks refused it.')
        ->and(ActivityErrors::labeled(
            'item',
            'Mug',
            'QuickBooks refused it'
        ))->toBe('Product "Mug": QuickBooks refused it.')
        ->and(ActivityErrors::labeled(
            'item',
            'Mug',
            Translation::get('quickbooks.messages.product-name-taken', ['name' => 'Mug'])
        ))
        ->not->toStartWith('Product "Mug":');
});

test('product and product name rows are named from this company only', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    $fleetbase         = config('fleetbase.connection.db');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('fleetbase.connection.db', 'sqlite');
    DB::purge('sqlite');
    try {
        $schema = DB::connection('sqlite')->getSchemaBuilder();
        $schema->create('quickbooks_links', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('name')->nullable();
        });
        $schema->create('products', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->string('name')->nullable();
            $table->softDeletes();
        });
        DB::table('quickbooks_links')->insert([
            [
                'uuid'         => 'l-1',
                'company_uuid' => 'company-uuid',
                'local_type'   => 'item',
                'local_uuid'   => 'name-1',
                'name'         => 'Old mug',
            ],
            [
                'uuid'         => 'l-2',
                'company_uuid' => 'company-2',
                'local_type'   => 'item',
                'local_uuid'   => 'name-2',
                'name'         => 'Other',
            ],
        ]);
        DB::table('products')->insert([
            ['uuid' => 'p-1', 'company_uuid' => 'company-uuid', 'name' => 'Mug', 'deleted_at' => now()],
            ['uuid' => 'p-2', 'company_uuid' => 'company-2', 'name' => 'Other', 'deleted_at' => null],
        ]);

        expect(ActivityErrors::names(
            'company-uuid',
            ['item' => ['name-1', 'name-2'], 'product' => ['p-1', 'p-2']]
        ))->toBe([
            'item|name-1'  => 'Old mug',
            'product|p-1'  => 'Mug',
        ]);
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbase);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
