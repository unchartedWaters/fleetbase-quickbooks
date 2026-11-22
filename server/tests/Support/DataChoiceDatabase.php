<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\CompanyReset;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * An in-memory database with the tables an organization's QuickBooks data lives in, and two organizations' rows.
 */
class DataChoiceDatabase
{
    /**
     * @param callable(): void $work
     */
    public static function with(callable $work): void
    {
        $default    = config('database.default');
        $connection = config('database.connections.sqlite');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        self::tables();

        try {
            $work();
        } finally {
            DB::purge('sqlite');
            config()->set('database.default', $default);
            config()->set('database.connections.sqlite', $connection);
            session(['company' => null, 'user' => null]);
        }
    }

    /**
     * The reset service over the test customer and place tables.
     */
    public static function reset(MemorySettingsStore $store): CompanyReset
    {
        return new CompanyReset($store, TestCustomer::class, TestPlace::class);
    }

    private static function tables(): void
    {
        $schema = DB::connection('sqlite')->getSchemaBuilder();
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->index();
            $table->string('realm_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('environment')->default('sandbox');
            $table->boolean('needs_reauth')->default(false);
            $table->string('home_currency', 3)->nullable();
            $table->timestamp('last_batch_at')->nullable();
            $table->timestamp('customer_import_start')->nullable();
            $table->timestamp('rate_limited_until')->nullable();
            $table->unsignedInteger('last_rate_limit_wait')->nullable();
            $table->timestamps();
        });
        $names = [
            'quickbooks_pending_syncs', 'quickbooks_links', 'quickbooks_sync_batches', 'quickbooks_sync_attempts',
        ];
        foreach ($names as $name) {
            $schema->create($name, function (Blueprint $table) use ($name) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36)->index();
                $table->string('status')->nullable();
                if ($name === 'quickbooks_links') {
                    $table->string('realm_id')->nullable();
                    $table->string('local_type')->nullable();
                }
                if ($name === 'quickbooks_pending_syncs') {
                    $table->string('local_type')->nullable();
                    $table->char('local_uuid', 36)->nullable();
                }
                $table->timestamps();
            });
        }
        $schema->create('test_customers', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->index();
            $table->text('meta')->nullable();
        });
        $schema->create('test_places', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('owner_uuid', 36)->index();
            $table->char('company_uuid', 36)->index();
        });
    }

    /**
     * Two organizations on different QuickBooks companies, each with a connection, links, queued work and history.
     * company-uuid has one imported and one native customer; other-company has an imported one.
     */
    public static function seed(): void
    {
        foreach (['company-uuid' => '9341453000000001', 'other-company' => '9341453000000002'] as $company => $realm) {
            $connection = new Connection();
            $connection->fill([
                'uuid'          => 'conn-' . $company,
                'company_uuid'  => $company,
                'realm_id'      => $realm,
                'refresh_token' => 'token-' . $company,
            ]);
            $connection->save();
            foreach (['customer', 'customer', 'invoice', 'wallet', 'item'] as $index => $type) {
                $link = new Link();
                $link->forceFill([
                    'uuid'         => 'link-' . $company . $index,
                    'company_uuid' => $company,
                    'realm_id'     => $realm,
                    'local_type'   => $type,
                ]);
                $link->save();
            }
            $history = [
                [PendingSync::class, 'pending-'],
                [SyncBatch::class, 'batch-'],
                [SyncAttempt::class, 'attempt-'],
            ];
            foreach ($history as [$class, $prefix]) {
                $row = new $class();
                $row->forceFill(['uuid' => $prefix . $company, 'company_uuid' => $company]);
                $row->save();
            }
        }
        $marker    = CompanyReset::importMarker('9341453000000001', '5');
        $customers = [
            ['imported-1', 'company-uuid', $marker],
            ['native-1', 'company-uuid', null],
            ['imported-2', 'other-company', $marker],
        ];
        foreach ($customers as [$uuid, $company, $meta]) {
            TestCustomer::query()->create(['uuid' => $uuid, 'company_uuid' => $company, 'meta' => $meta]);
            TestPlace::query()->create(['uuid' => 'place-' . $uuid, 'owner_uuid' => $uuid, 'company_uuid' => $company]);
        }
    }

    /**
     * @return array<string, int> the rows each table holds for $company
     */
    public static function rows(string $company): array
    {
        $count  = [];
        $tables = [
            'quickbooks_connections', 'quickbooks_links', 'quickbooks_pending_syncs', 'quickbooks_sync_batches',
            'quickbooks_sync_attempts', 'test_customers', 'test_places',
        ];
        foreach ($tables as $table) {
            $count[$table] = DB::table($table)->where('company_uuid', $company)->count();
        }

        return $count;
    }
}
