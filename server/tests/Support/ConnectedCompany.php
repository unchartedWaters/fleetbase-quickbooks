<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The quickbooks_connections table with one organization connected to a realm. Webhook deletes and
 * merges apply only to the realm an organization is connected to now.
 */
class ConnectedCompany
{
    public static function create(Builder $schema, string $companyUuid, string $realmId): void
    {
        if ($schema->hasTable('quickbooks_connections') === false) {
            $schema->create('quickbooks_connections', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36);
                $table->string('realm_id')->nullable();
                $table->boolean('needs_reauth')->default(false);
                $table->timestamps();
            });
        }
        DB::table('quickbooks_connections')->insert([
            'uuid'         => 'conn-' . $companyUuid,
            'company_uuid' => $companyUuid,
            'realm_id'     => $realmId,
            'needs_reauth' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
