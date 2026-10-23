<?php

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

test('payment invoice rows are looked up per company and results are unchanged', function () {
    $default = config('database.default');
    $sqlite  = config('database.connections.sqlite');
    $ledger  = config('fleetbase.connection.db');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('fleetbase.connection.db', 'sqlite');
    DB::purge('sqlite');

    try {
        DB::connection('sqlite')->getSchemaBuilder()->create('quickbooks_links', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id');
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('qbo_entity');
            $table->string('qbo_id');
            $table->string('sync_token')->default('0');
            $table->string('name')->nullable();
            $table->string('state')->nullable();
            $table->text('invoice_tokens')->nullable();
            $table->timestamps();
        });
        $now = now();
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'l-a', 'company_uuid' => 'company-a', 'realm_id' => 'r1', 'local_type' => 'payment-invoice', 'local_uuid' => 'p1', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'old', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'l-b', 'company_uuid' => 'company-b', 'realm_id' => 'r2', 'local_type' => 'payment-invoice', 'local_uuid' => 'p1', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'keep', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('sqlite')->enableQueryLog();

        $method = new ReflectionMethod(FleetbaseDirectory::class, 'storePaymentInvoiceRows');
        $method->invoke(new FleetbaseDirectory(), [
            'company-a|r1|p1' => ['company_uuid' => 'company-a', 'realm_id' => 'r1', 'local_uuid' => 'p1', 'qbo_id' => 'new'],
            'company-b|r2|p2' => ['company_uuid' => 'company-b', 'realm_id' => 'r2', 'local_uuid' => 'p2', 'qbo_id' => 'fresh'],
        ]);

        $lookups = array_values(array_filter(array_column(DB::connection('sqlite')->getQueryLog(), 'query'), static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select') === true && str_contains($sql, 'local_type') === true));

        expect($lookups)->not->toBe([])
            ->and(count(array_filter($lookups, static fn (string $sql): bool => preg_match('/where.*company_uuid.{0,3} = /i', $sql) !== 1)))->toBe(0)
            ->and(Link::query()->where('uuid', 'l-a')->value('qbo_id'))->toBe('new')
            ->and(Link::query()->where('uuid', 'l-b')->value('qbo_id'))->toBe('keep')
            ->and(Link::query()->where('company_uuid', 'company-b')->where('local_uuid', 'p2')->value('qbo_id'))->toBe('fresh')
            ->and(Link::query()->count())->toBe(3);
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $default);
        config()->set('database.connections.sqlite', $sqlite);
        config()->set('fleetbase.connection.db', $ledger);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
