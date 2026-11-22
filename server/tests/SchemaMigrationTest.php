<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the create migration builds every table with its indexes, runs twice and rolls back', function () {
    $default = config('database.default');
    $sqlite  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::purge('sqlite');
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap(DB::connection('sqlite')->getSchemaBuilder());

    try {
        $files = glob(dirname(__DIR__) . '/migrations/*.php');
        expect($files)->toHaveCount(1);

        $migration = require $files[0];
        $migration->up();
        $migration->up();

        $indexes = [
            'quickbooks_connections'   => ['quickbooks_connections_company_uuid_unique', 'quickbooks_connections_realm_id_index'],
            'quickbooks_links'         => ['quickbooks_links_local_unique', 'quickbooks_links_realm_qbo', 'quickbooks_links_company_realm_qbo', 'quickbooks_links_company_realm_kept_customer_index'],
            'quickbooks_pending_syncs' => ['quickbooks_pending_open_unique', 'quickbooks_pending_due', 'qb_pending_claimed_by_idx', 'qb_pending_status_updated_idx'],
            'quickbooks_sync_batches'  => ['qb_batches_company_created_idx', 'qb_batches_company_status_finished_idx', 'qb_batches_created_idx'],
            'quickbooks_sync_attempts' => ['qb_attempts_created_idx'],
        ];
        foreach ($indexes as $table => $names) {
            foreach ($names as $name) {
                expect(Schema::hasIndex($table, $name))->toBeTrue($table . ' ' . $name);
            }
        }
        expect(Schema::hasColumns('quickbooks_links', ['tax_source', 'refused_tax', 'kept_customer', 'invoice_tokens']))->toBeTrue()
            ->and(Schema::hasColumns('quickbooks_pending_syncs', ['open_identity', 'claimed_until', 'claimed_by', 'priority']))->toBeTrue()
            ->and(Schema::hasColumns('quickbooks_connections', ['customer_import_start', 'default_item_account']))->toBeTrue();

        $migration->down();
        foreach (array_keys($indexes) as $table) {
            expect(Schema::hasTable($table))->toBeFalse($table);
        }
    } finally {
        DB::purge('sqlite');
        Schema::swap($originalSchema);
        config()->set('database.default', $default);
        config()->set('database.connections.sqlite', $sqlite);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
