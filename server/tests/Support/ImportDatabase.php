<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * A fresh in-memory SQLite database with the tables a customer import reads and writes,
 * including the production unique local identity on quickbooks_links. A real
 * FleetbaseDirectory runs against it the way it does in production.
 */
class ImportDatabase
{
    /**
     * Switch the default connection to the new database.
     *
     * @return callable restores the previous configuration
     */
    public static function open(): callable
    {
        $defaultConnection   = config('database.default');
        $sqliteConnection    = config('database.connections.sqlite');
        $fleetbaseConnection = config('fleetbase.connection.db');
        $events              = Model::getEventDispatcher();
        // Model events give new QuickBooks rows their uuid, as they do in the app.
        Model::setEventDispatcher(new Dispatcher(Container::getInstance()));
        Model::clearBootedModels();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('fleetbase.connection.db', 'sqlite');
        DB::purge('sqlite');
        $schema = DB::connection('sqlite')->getSchemaBuilder();
        self::quickbooksTables($schema);
        self::fleetbaseTables($schema);

        return static function () use ($defaultConnection, $sqliteConnection, $fleetbaseConnection, $events): void {
            DB::purge('sqlite');
            if ($events === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($events);
            }
            Model::clearBootedModels();
            config()->set('database.default', $defaultConnection);
            config()->set('database.connections.sqlite', $sqliteConnection);
            config()->set('fleetbase.connection.db', $fleetbaseConnection);
        };
    }

    public static function connection(string $companyUuid = 'company-uuid', string $realmId = 'realm-1'): void
    {
        DB::table('quickbooks_connections')->insert([
            'uuid'         => 'conn-' . $companyUuid,
            'company_uuid' => $companyUuid,
            'realm_id'     => $realmId,
            'environment'  => 'sandbox',
            'needs_reauth' => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function contact(string $uuid, string $name, array $extra = []): void
    {
        DB::table('contacts')->insert(array_merge([
            'uuid'         => $uuid,
            'company_uuid' => 'company-uuid',
            'name'         => $name,
            'type'         => 'customer',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $extra));
    }

    public static function link(string $uuid, string $localUuid, string $qboId, string $companyUuid = 'company-uuid'): void
    {
        DB::table('quickbooks_links')->insert([
            'uuid'         => $uuid,
            'company_uuid' => $companyUuid,
            'realm_id'     => 'realm-1',
            'local_type'   => 'customer',
            'local_uuid'   => $localUuid,
            'qbo_entity'   => 'Customer',
            'qbo_id'       => $qboId,
            'sync_token'   => '0',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * @return array<int, string> the local uuids linked to this QuickBooks customer
     */
    public static function linkedTo(string $qboId): array
    {
        return DB::table('quickbooks_links')
            ->where('local_type', 'customer')
            ->where('qbo_entity', 'Customer')
            ->where('qbo_id', $qboId)
            ->orderBy('local_uuid')
            ->pluck('local_uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->all();
    }

    private static function quickbooksTables(Builder $schema): void
    {
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('environment')->default('sandbox');
            $table->string('default_item_id')->nullable();
            $table->timestamp('rate_limited_until')->nullable();
            $table->unsignedInteger('last_rate_limit_wait')->nullable();
            $table->boolean('needs_reauth')->default(false);
            $table->string('home_currency', 3)->nullable();
            $table->timestamp('last_batch_at')->nullable();
            $table->unsignedInteger('customer_import_start')->nullable();
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
            $table->string('sync_token')->default('0');
            $table->string('name')->nullable();
            $table->string('state')->nullable();
            $table->timestamps();
            $table->unique(['company_uuid', 'local_type', 'local_uuid']);
        });
        self::activityTables($schema);
    }

    private static function activityTables(Builder $schema): void
    {
        $schema->create('quickbooks_sync_batches', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('trigger');
            $table->string('direction')->default('outbound');
            $table->string('status');
            foreach (['created', 'updated', 'aligned', 'voided', 'unmatched', 'failed', 'linked', 'skipped'] as $count) {
                $table->unsignedInteger($count . '_count')->default(0);
            }
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        $schema->create('quickbooks_sync_attempts', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('batch_uuid', 36)->nullable();
            $table->char('company_uuid', 36)->nullable();
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
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    private static function fleetbaseTables(Builder $schema): void
    {
        $schema->create('contacts', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->string('public_id')->nullable();
            $table->string('internal_id')->nullable();
            $table->char('company_uuid', 36)->nullable();
            $table->char('place_uuid', 36)->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('places', function (Blueprint $table): void {
            $table->char('uuid', 36)->primary();
            $table->string('public_id')->nullable();
            $table->char('company_uuid', 36)->nullable();
            $table->char('owner_uuid', 36)->nullable();
            $table->string('owner_type')->nullable();
            $table->string('street1')->nullable();
            $table->string('street2')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });
        $schema->create('companies', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->string('timezone')->nullable();
        });
    }
}
