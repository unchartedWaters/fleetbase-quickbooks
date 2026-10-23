<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Support\WebhookPaymentInvoices;
use Fleetbase\Quickbooks\Support\WebhookPaymentLinks;
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
            $table->string('name')->nullable();
            $table->string('state')->nullable();
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

test('the stored invoice map reads only this company\'s payment-invoice links for the requested realms', function () {
    wpiWithDatabase(function () {
        wpiLinks([
            ['map-1', 'realm-1', 'payment-invoice', '5', 'Payment', 'inv-x'],
            ['map-2', 'realm-1', 'payment-invoice', '5', 'Payment', 'inv-x'],
            ['map-3', 'realm-1', 'payment-invoice', '5', 'Payment', 'inv-y'],
            ['map-self', 'realm-1', 'payment-invoice', '5', 'Payment', '5'],
            ['map-other-company', 'realm-1', 'payment-invoice', '5', 'Payment', 'inv-b', 'company-b'],
            ['map-other-realm', 'realm-2', 'payment-invoice', '5', 'Payment', 'inv-z'],
            ['map-other-type', 'realm-1', 'invoice', '5', 'Invoice', 'inv-w'],
        ]);
        $links = new WebhookPaymentLinks();

        expect($links->storedPaymentInvoices('company-a', ['realm-1' => ['5', '6']]))
            ->toBe(['realm-1|5' => ['inv-x', 'inv-y']])
            ->and($links->storedPaymentInvoices('', ['realm-1' => ['5']]))->toBe([]);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('invoices without a link in their realm are looked up on file within the company', function () {
    wpiWithDatabase(function () {
        $now      = now();
        $invoices = [
            ['inv-a', 'company-a'],
            ['inv-b', 'company-b'],
            ['inv-c', 'company-a'],
            ['inv-linked', 'company-a'],
        ];
        foreach ($invoices as [$uuid, $company]) {
            DB::table('ledger_invoices')->insert([
                'uuid'         => $uuid,
                'company_uuid' => $company,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        $onFile = (new WebhookPaymentLinks())->invoicesOnFile(
            'company-a',
            ['realm-1|4' => ['inv-a', 'inv-b', 'inv-linked']],
            ['realm-1|5' => ['inv-c']],
            ['realm-1|inv-linked' => true]
        );

        expect($onFile)->toBe(['inv-a' => true, 'inv-c' => true]);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
