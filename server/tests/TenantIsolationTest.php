<?php

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\CustomerImporter;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

function tenantSqlite(): callable
{
    $defaultConnection   = config('database.default');
    $sqliteConnection    = config('database.connections.sqlite');
    $fleetbaseConnection = config('fleetbase.connection.db');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('fleetbase.connection.db', 'sqlite');
    DB::purge('sqlite');
    DB::connection('sqlite')->getSchemaBuilder()->create('quickbooks_connections', function (Blueprint $table) {
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

    return function () use ($defaultConnection, $sqliteConnection, $fleetbaseConnection): void {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbaseConnection);
    };
}

/**
 * Company B is the only row on realm-1. Company A had a row on the same realm that is gone.
 */
function tenantRows(bool $withCompanyA = false): void
{
    $base = ['realm_id' => 'realm-1', 'environment' => 'sandbox', 'needs_reauth' => false, 'home_currency' => 'USD'];
    Connection::query()->create($base + ['uuid' => 'conn-b', 'company_uuid' => 'company-b', 'access_token' => 'b-access', 'refresh_token' => 'b-refresh']);
    if ($withCompanyA === true) {
        Connection::query()->create($base + ['uuid' => 'conn-a', 'company_uuid' => 'company-a', 'access_token' => 'a-access', 'refresh_token' => 'a-refresh']);
    }
}

function tenantConnectionOfA(): array
{
    return [
        'company_uuid'         => 'company-a',
        'realm_id'             => 'realm-1',
        'access_token'         => 'a-new-access',
        'refresh_token'        => 'a-new-refresh',
        'token_expires_at'     => 1790000000,
        'needs_reauth'         => true,
        'rate_limited_until'   => 1790000100,
        'last_rate_limit_wait' => 30,
        'home_currency'        => 'EUR',
        'default_item_id'      => 'item-a',
        'last_batch_at'        => 1790000000,
    ];
}

function tenantRow(string $companyUuid): Connection
{
    $row = Connection::query()->where('company_uuid', $companyUuid)->first();
    if ($row instanceof Connection === false) {
        throw new RuntimeException('Missing test connection ' . $companyUuid);
    }

    return $row;
}

test('tokens of a disconnected company are not written onto another company on the same realm', function () {
    $restore = tenantSqlite();
    try {
        tenantRows();

        (new FleetbaseDirectory())->saveConnection(['needs_reauth' => false] + tenantConnectionOfA());
        $row = tenantRow('company-b');

        expect($row->access_token)->toBe('b-access')
            ->and($row->refresh_token)->toBe('b-refresh')
            ->and($row->needs_reauth)->toBeFalse()
            ->and($row->token_expires_at)->toBeNull()
            ->and(DB::table('quickbooks_connections')->count())->toBe(1);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('engine fields of a disconnected company are not written onto another company on the same realm', function () {
    $restore = tenantSqlite();
    try {
        tenantRows();
        $ledger                           = new SyncLedger();
        $ledger->connections['company-a'] = tenantConnectionOfA();

        (new FleetbaseDirectory())->save($ledger);
        $row = tenantRow('company-b');

        expect($row->needs_reauth)->toBeFalse()
            ->and($row->rate_limited_until)->toBeNull()
            ->and($row->last_batch_at)->toBeNull()
            ->and($row->default_item_id)->toBeNull()
            ->and($row->home_currency)->toBe('USD');
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a company with its own connection row is still updated and its neighbour is not', function () {
    $restore = tenantSqlite();
    try {
        tenantRows(true);

        (new FleetbaseDirectory())->saveConnection(['needs_reauth' => false] + tenantConnectionOfA());
        $a = tenantRow('company-a');
        $b = tenantRow('company-b');

        expect($a->access_token)->toBe('a-new-access')
            ->and($a->refresh_token)->toBe('a-new-refresh')
            ->and($b->access_token)->toBe('b-access')
            ->and($b->refresh_token)->toBe('b-refresh');
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('the customer import cursor of a disconnected company is not written onto the only other connection', function () {
    $restore = tenantSqlite();
    try {
        tenantRows();
        $store = new ReflectionMethod(CustomerImporter::class, 'storeCursor');
        $store->setAccessible(true);
        $importer = new CustomerImporter(new FakeQuickBooks());

        $store->invoke($importer, 'company-a', 7);

        expect(tenantRow('company-b')->customer_import_start)->toBeNull();
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('the customer import cursor is stored on the company own connection', function () {
    $restore = tenantSqlite();
    try {
        tenantRows(true);
        $store = new ReflectionMethod(CustomerImporter::class, 'storeCursor');
        $store->setAccessible(true);
        $importer = new CustomerImporter(new FakeQuickBooks());

        $store->invoke($importer, 'company-a', 7);

        expect((int) tenantRow('company-a')->customer_import_start)->toBe(7)
            ->and(tenantRow('company-b')->customer_import_start)->toBeNull();
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
