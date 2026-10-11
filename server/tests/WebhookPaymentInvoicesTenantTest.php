<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Support\WebhookPaymentInvoices;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Runs the callback against an in-memory sqlite database holding the link and ledger
 * invoice tables, then restores the connection config.
 */
function wpiWithDatabase(callable $callback): mixed
{
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    $ledgerConnection  = config('fleetbase.connection.db');
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

    try {
        $schema->create('quickbooks_links', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id');
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('qbo_entity');
            $table->string('qbo_id');
            $table->string('sync_token')->default('0');
            $table->timestamps();
        });
        $schema->create('ledger_invoices', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        return $callback();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
}

/**
 * Inserts link rows given as [uuid, realm, local_type, local_uuid, qbo_entity, qbo_id, company?].
 *
 * @param array<int, array<int, string>> $rows
 */
function wpiLinks(array $rows): void
{
    $now    = now();
    $insert = [];
    foreach ($rows as $row) {
        $insert[] = [
            'uuid'         => $row[0],
            'company_uuid' => $row[6] ?? 'company-a',
            'realm_id'     => $row[1],
            'local_type'   => $row[2],
            'local_uuid'   => $row[3],
            'qbo_entity'   => $row[4],
            'qbo_id'       => $row[5],
            'sync_token'   => '0',
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }
    DB::table('quickbooks_links')->insert($insert);
}

function wpiPayment(string $realmId, string $paymentId, string $operation = 'update', string $entityType = 'payment'): QuickBooksEntityChanged
{
    return new QuickBooksEntityChanged('company-a', $realmId, $entityType, $paymentId, $operation, null);
}

/**
 * A reader that answers per realm and records each call as [company, realm, ids].
 *
 * @param array<string, array<string, array<int, string>|null>>          $byRealm
 * @param array<int, array{0: string, 1: string, 2: array<int, string>}> $calls
 */
function wpiReader(array $byRealm, array &$calls): Closure
{
    return function (string $companyUuid, string $realmId, array $ids) use ($byRealm, &$calls): array {
        $calls[] = [$companyUuid, $realmId, $ids];

        return $byRealm[$realmId] ?? [];
    };
}

test('a remote invoice id linked only by another company in the same realm resolves nothing', function () {
    wpiWithDatabase(function () {
        wpiLinks([
            ['link-pay-a', 'realm-1', 'payment', '4', 'Payment', '4'],
            ['link-inv-company-b', 'realm-1', 'invoice', 'inv-company-b', 'Invoice', '12', 'company-b'],
        ]);
        $calls  = [];
        $lookup = new WebhookPaymentInvoices();

        $resolved = $lookup->resolve('company-a', [wpiPayment('realm-1', '4')], false, wpiReader(['realm-1' => ['4' => ['12']]], $calls));

        expect($resolved)->toBe([])
            ->and($calls)->toBe([['company-a', 'realm-1', ['4']]])
            ->and($lookup->reads)->toBe(['realm-1|4' => 'found']);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
