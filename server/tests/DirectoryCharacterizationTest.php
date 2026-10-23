<?php

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/*
 * Characterization tests for FleetbaseDirectory: ledger loading (full and pending-limited),
 * persistence of customers, invoices, wallets and links, delete batches, and the in-memory
 * delete path. They pin the behavior the directory already has.
 */

/**
 * Switches the default connection to a fresh in-memory sqlite database with the schema below.
 *
 * @return callable restores the previous configuration
 */
function dctSqlite(): callable
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
    dctSchema(DB::connection('sqlite')->getSchemaBuilder());

    return function () use ($defaultConnection, $sqliteConnection, $fleetbaseConnection): void {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbaseConnection);
    };
}

function dctSchema(Illuminate\Database\Schema\Builder $schema): void
{
    $schema->create('quickbooks_connections', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('realm_id')->nullable();
        $table->text('access_token')->nullable();
        $table->text('refresh_token')->nullable();
        $table->timestamp('token_expires_at')->nullable();
        $table->string('environment')->default('sandbox');
        $table->boolean('needs_reauth')->default(false);
        $table->string('home_currency', 3)->nullable();
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
    $schema->create('ledger_invoices', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36)->nullable();
        $table->char('customer_uuid', 36)->nullable();
        $table->string('customer_type')->nullable();
        $table->char('order_uuid', 36)->nullable();
        $table->char('template_uuid', 36)->nullable();
        $table->string('number')->nullable();
        $table->date('date')->nullable();
        $table->date('due_date')->nullable();
        $table->text('notes')->nullable();
        $table->string('currency')->nullable();
        $table->integer('tax')->default(0);
        $table->integer('total_amount')->default(0);
        $table->integer('subtotal')->default(0);
        $table->integer('balance')->default(0);
        $table->integer('amount_paid')->default(0);
        $table->string('status')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('ledger_invoice_items', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('invoice_uuid', 36)->nullable();
        $table->string('description')->nullable();
        $table->integer('quantity')->default(0);
        $table->integer('unit_price')->default(0);
        $table->integer('amount')->default(0);
        $table->json('meta')->nullable();
        $table->softDeletes();
    });
    $schema->create('ledger_wallets', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->string('public_id')->nullable();
        $table->char('company_uuid', 36)->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('status')->nullable();
        $table->integer('balance')->default(0);
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('companies', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->string('timezone')->nullable();
    });
    $schema->create('transactions', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid', 191)->nullable();
        $table->string('company_uuid', 191)->nullable();
        $table->string('subject_uuid', 191)->nullable();
        $table->string('type', 191)->nullable();
        $table->integer('amount')->nullable();
        $table->string('reference', 191)->nullable();
        $table->string('payment_method', 191)->nullable();
        $table->softDeletes();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
    foreach (['templates', 'orders', 'tracking_numbers'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->softDeletes();
        });
    }
}

function dctConnection(string $companyUuid = 'company-uuid', string $realmId = 'realm-1'): void
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

function dctContact(string $uuid, string $name, string $companyUuid = 'company-uuid', array $extra = []): void
{
    DB::table('contacts')->insert(array_merge([
        'uuid'         => $uuid,
        'company_uuid' => $companyUuid,
        'name'         => $name,
        'type'         => 'customer',
        'created_at'   => now(),
        'updated_at'   => now(),
    ], $extra));
}

function dctInvoice(string $uuid, string $customerUuid, array $extra = []): void
{
    DB::table('ledger_invoices')->insert(array_merge([
        'uuid'          => $uuid,
        'company_uuid'  => 'company-uuid',
        'customer_uuid' => $customerUuid,
        'customer_type' => 'Fleetbase\\FleetOps\\Models\\Customer',
        'number'        => $uuid,
        'status'        => 'sent',
        'currency'      => 'USD',
        'tax'           => 0,
        'total_amount'  => 1000,
        'amount_paid'   => 0,
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $extra));
}

function dctWallet(string $uuid, string $name, int $balance = 0): void
{
    DB::table('ledger_wallets')->insert([
        'uuid'         => $uuid,
        'public_id'    => $uuid,
        'company_uuid' => 'company-uuid',
        'name'         => $name,
        'currency'     => 'USD',
        'status'       => 'active',
        'balance'      => $balance,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function dctLinkRow(string $uuid, string $localType, string $localUuid, string $entity, string $qboId, string $realmId = 'realm-1', string $companyUuid = 'company-uuid'): void
{
    DB::table('quickbooks_links')->insert([
        'uuid'         => $uuid,
        'company_uuid' => $companyUuid,
        'realm_id'     => $realmId,
        'local_type'   => $localType,
        'local_uuid'   => $localUuid,
        'qbo_entity'   => $entity,
        'qbo_id'       => $qboId,
        'sync_token'   => '0',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function dctPendingRow(string $uuid, string $localType, string $localUuid, string $status = 'pending', mixed $nextAttemptAt = null): void
{
    DB::table('quickbooks_pending_syncs')->insert([
        'uuid'            => $uuid,
        'company_uuid'    => 'company-uuid',
        'local_type'      => $localType,
        'local_uuid'      => $localUuid,
        'status'          => $status,
        'attempts'        => 0,
        'next_attempt_at' => $nextAttemptAt,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

/**
 * Runs the callback with the query log on and returns the SQL it ran.
 *
 * @return array<int, string>
 */
function dctQueries(callable $callback): array
{
    $connection = DB::connection('sqlite');
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $callback();
    $queries = array_column($connection->getQueryLog(), 'query');
    $connection->disableQueryLog();

    return $queries;
}

/**
 * @param array<int, string> $queries
 *
 * @return array<int, string>
 */
function dctStatements(array $queries, string $verb, string $table): array
{
    return array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), $verb) === true
            && (str_contains($sql, '"' . $table . '"') === true || str_contains($sql, '`' . $table . '`') === true)
    ));
}

/**
 * @param array<int, array<string, mixed>> $links
 *
 * @return array<int, string>
 */
function dctLinkIdentities(array $links): array
{
    $identities = [];
    foreach ($links as $link) {
        $identities[] = (string) ($link['local_type'] ?? '') . ':' . (string) ($link['local_uuid'] ?? '');
    }
    sort($identities);

    return $identities;
}

/**
 * @param array<string, mixed> $array
 *
 * @return array<int, string>
 */
function dctSortedKeys(array $array): array
{
    $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($array));
    sort($keys);

    return $keys;
}

/**
 * @param array<int, array<string, mixed>> $pending
 *
 * @return array<int, string>
 */
function dctPendingUuids(array $pending): array
{
    $uuids = array_map(static fn (array $row): string => (string) ($row['uuid'] ?? ''), $pending);
    sort($uuids);

    return $uuids;
}

/**
 * Loading fixture: three company customers and one of another company, three invoices (one
 * soft-deleted), two wallets, a link for each record, and pending rows of every kind.
 */
function dctLoadRows(): void
{
    dctConnection();
    dctContact('cust-a', 'Ada');
    dctContact('cust-b', 'Babbage');
    dctContact('cust-c', 'Countess');
    dctContact('cust-other', 'Other company', 'company-b');
    dctInvoice('inv-a', 'cust-c');
    dctInvoice('inv-b', 'cust-b');
    dctInvoice('inv-del', 'cust-b', ['deleted_at' => now()]);
    dctWallet('wal-a', 'Operating');
    dctWallet('wal-b', 'Reserve');
    dctLinkRow('l-cust-a', 'customer', 'cust-a', 'Customer', '1');
    dctLinkRow('l-cust-b', 'customer', 'cust-b', 'Customer', '2');
    dctLinkRow('l-cust-c', 'customer', 'cust-c', 'Customer', '3');
    dctLinkRow('l-inv-a', 'invoice', 'inv-a', 'Invoice', '10');
    dctLinkRow('l-inv-b', 'invoice', 'inv-b', 'Invoice', '11');
    dctLinkRow('l-inv-del', 'invoice', 'inv-del', 'Invoice', '12');
    dctLinkRow('l-wal-a', 'wallet', 'wal-a', 'Account', '20');
    dctLinkRow('l-wal-b', 'wallet', 'wal-b', 'Account', '21');
    dctLinkRow('l-other', 'customer', 'cust-other', 'Customer', '1', 'realm-1', 'company-b');
    dctPendingRow('p1', 'customer', 'cust-a');
    dctPendingRow('p2', 'wallet', 'wal-a');
    dctPendingRow('p3', 'invoice', 'inv-a');
    dctPendingRow('p4', 'customer', 'cust-b', 'done');
    dctPendingRow('p5', 'invoice', 'inv-b', 'pending', now()->addHour());
    dctPendingRow('p6', 'invoice', 'inv-del');
}

$dctNoSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true) === false;

test('a pending load reads the due rows and only the records and links they name', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();

        $loaded = (new FleetbaseDirectory())->loadPending('company-uuid', 50, time());
        $ledger = $loaded['ledger'];

        expect($loaded['connection']['realm_id'])->toBe('realm-1')
            ->and(dctPendingUuids($ledger->pending))->toBe(['p1', 'p2', 'p3', 'p6'])
            // inv-del is soft-deleted but flagged, so it is read; its customer cust-b comes with it.
            ->and(dctSortedKeys($ledger->invoices))->toBe(['inv-a', 'inv-del'])
            ->and(dctSortedKeys($ledger->customers))->toBe(['cust-a', 'cust-b', 'cust-c'])
            ->and(dctSortedKeys($ledger->wallets))->toBe(['wal-a'])
            ->and(dctLinkIdentities($ledger->links))->toBe([
                'customer:cust-a',
                'customer:cust-b',
                'customer:cust-c',
                'invoice:inv-a',
                'invoice:inv-del',
                'wallet:wal-a',
            ])
            ->and($ledger->invoices['inv-a']['total'])->toBe(1000)
            ->and($ledger->invoices['inv-a']['customer_uuid'])->toBe('cust-c')
            ->and($ledger->wallets['wal-a']['balance'])->toBe(0)
            ->and($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-a')['qbo_id'])->toBe('20')
            ->and(array_column($loaded['customers'], 'uuid'))->not->toContain('cust-other');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a full load reads every pending row, the whole company catalog, and only flagged deleted invoices', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();

        $full   = (new FleetbaseDirectory())->load('company-uuid');
        $ledger = $full['ledger'];

        expect(dctPendingUuids($ledger->pending))->toBe(['p1', 'p2', 'p3', 'p5', 'p6'])
            ->and(dctSortedKeys($ledger->customers))->toBe(['cust-a', 'cust-b', 'cust-c'])
            ->and(dctSortedKeys($ledger->invoices))->toBe(['inv-a', 'inv-b', 'inv-del'])
            ->and(dctSortedKeys($ledger->wallets))->toBe(['wal-a', 'wal-b'])
            ->and(count($ledger->links))->toBe(8)
            ->and(dctLinkIdentities($ledger->links))->not->toContain('customer:cust-other');

        DB::table('quickbooks_pending_syncs')->where('uuid', 'p6')->update(['status' => 'done']);
        $unflagged = (new FleetbaseDirectory())->load('company-uuid');

        expect(dctSortedKeys($unflagged['ledger']->invoices))->toBe(['inv-a', 'inv-b'])
            ->and(dctPendingUuids($unflagged['ledger']->pending))->toBe(['p1', 'p2', 'p3', 'p5']);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a pending load of only a wallet row reads no customers or invoices and one link query', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        DB::table('quickbooks_pending_syncs')->whereIn('uuid', ['p1', 'p3', 'p6'])->update(['status' => 'done']);

        $loaded  = null;
        $queries = dctQueries(function () use (&$loaded): void {
            $loaded = (new FleetbaseDirectory())->loadPending('company-uuid', 50, time());
        });

        expect(dctPendingUuids($loaded['ledger']->pending))->toBe(['p2'])
            ->and($loaded['ledger']->customers)->toBe([])
            ->and($loaded['ledger']->invoices)->toBe([])
            ->and(dctSortedKeys($loaded['ledger']->wallets))->toBe(['wal-a'])
            ->and(dctLinkIdentities($loaded['ledger']->links))->toBe(['wallet:wal-a'])
            ->and(dctStatements($queries, 'select', 'contacts'))->toBe([])
            ->and(dctStatements($queries, 'select', 'ledger_invoices'))->toBe([])
            ->and(dctStatements($queries, 'select', 'quickbooks_links'))->toHaveCount(1)
            ->and(dctStatements($queries, 'select', 'ledger_wallets'))->toHaveCount(1);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a pending load takes rows without a next attempt first, then by uuid, up to the limit', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        DB::table('quickbooks_pending_syncs')->where('uuid', 'p1')->update(['next_attempt_at' => now()->subMinute()]);

        $two = (new FleetbaseDirectory())->loadPending('company-uuid', 2, time());
        $one = (new FleetbaseDirectory())->loadPending('company-uuid', 0, time());

        expect(dctPendingUuids($two['ledger']->pending))->toBe(['p2', 'p3'])
            ->and(dctPendingUuids($one['ledger']->pending))->toBe(['p2'])
            ->and(dctSortedKeys($two['ledger']->customers))->toBe(['cust-c'])
            ->and(dctSortedKeys($two['ledger']->wallets))->toBe(['wal-a']);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('customers save only the fields the engine changed and new customers insert in one statement', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada', 'company-uuid', ['email' => 'ada@example.test']);
        dctContact('cust-b', 'Babbage', 'company-uuid', ['email' => 'charles@example.test']);
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->load('company-uuid')['ledger'];
        // A change made in Fleetbase while the batch ran stays.
        DB::table('contacts')->where('uuid', 'cust-a')->update(['email' => 'edited@example.test']);

        $ledger->customers['cust-a']['name'] = 'Ada Lovelace';
        $ledger->customers['cust-new-1']     = [
            'uuid'         => 'cust-new-1',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => 'Grace Hopper',
            'email'        => 'grace@example.test',
            'phone'        => '555',
            'notes'        => 'Imported',
            'meta'         => ['source' => 'quickbooks'],
            'address'      => ['line1' => '9 Cobol Lane', 'city' => 'Arlington', 'state' => 'VA', 'postal_code' => '22202', 'country' => 'US'],
        ];
        $ledger->customers['cust-new-2'] = [
            'uuid'         => 'cust-new-2',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => '',
            'email'        => 'nameless@example.test',
        ];
        $ledger->customers['cust-no-company'] = ['uuid' => 'cust-no-company', 'company_uuid' => '', 'name' => 'Orphan'];

        $queries = dctQueries(fn () => $directory->save($ledger));
        $a       = DB::table('contacts')->where('uuid', 'cust-a')->first();
        $new1    = DB::table('contacts')->where('uuid', 'cust-new-1')->first();
        $new2    = DB::table('contacts')->where('uuid', 'cust-new-2')->first();
        $place   = DB::table('places')->where('uuid', $new1->place_uuid)->first();

        expect(dctStatements($queries, 'insert', 'contacts'))->toHaveCount(1)
            ->and(dctStatements($queries, 'update', 'contacts'))->toHaveCount(1)
            ->and($a->name)->toBe('Ada Lovelace')
            ->and($a->email)->toBe('edited@example.test')
            ->and(DB::table('contacts')->where('uuid', 'cust-b')->value('name'))->toBe('Babbage')
            ->and(DB::table('contacts')->where('uuid', 'cust-no-company')->exists())->toBeFalse()
            ->and($new1->type)->toBe('customer')
            ->and($new1->company_uuid)->toBe('company-uuid')
            ->and($new1->slug)->toBe('grace-hopper')
            ->and(str_starts_with((string) $new1->public_id, 'contact_'))->toBeTrue()
            ->and(strlen((string) $new1->internal_id))->toBe(6)
            ->and(json_decode((string) $new1->meta, true))->toBe(['source' => 'quickbooks'])
            ->and($place->street1)->toBe('9 Cobol Lane')
            ->and($place->owner_uuid)->toBe('cust-new-1')
            ->and($new2->name)->toBeNull()
            ->and($new2->slug)->toBeNull()
            ->and($new2->place_uuid)->toBeNull()
            ->and($new2->email)->toBe('nameless@example.test')
            ->and(DB::table('places')->count())->toBe(1);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a quickbooks payment writes amount paid, paid at, status, and a balance from the stored amounts', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        dctInvoice('inv-paid', 'cust-a', ['total_amount' => 1000, 'tax' => 100]);
        dctInvoice('inv-keep-paid', 'cust-a', ['total_amount' => 1000, 'amount_paid' => 250]);
        dctInvoice('inv-replace', 'cust-a', ['total_amount' => 1000, 'amount_paid' => 300]);
        dctInvoice('inv-total-only', 'cust-a', ['total_amount' => 1000]);
        dctInvoice('inv-same', 'cust-a');
        dctInvoice('inv-grown', 'cust-a', [
            'total_amount' => 1000, 'amount_paid' => 1000, 'status' => 'paid', 'paid_at' => '2026-09-01 10:00:00',
        ]);
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->load('company-uuid')['ledger'];

        $ledger->invoices['inv-paid']['payment_from_quickbooks'] = true;
        $ledger->invoices['inv-paid']['amount_paid']             = 400;
        $ledger->invoices['inv-paid']['paid_at']                 = '2026-09-01 10:00:00';
        $ledger->invoices['inv-paid']['status']                  = 'partial';

        $ledger->invoices['inv-keep-paid']['payment_from_quickbooks'] = true;
        $ledger->invoices['inv-keep-paid']['status']                  = 'partial';
        unset($ledger->invoices['inv-keep-paid']['amount_paid']);

        $ledger->invoices['inv-replace']['replace_from_quickbooks'] = true;
        $ledger->invoices['inv-replace']['total']                   = 2000;
        $ledger->invoices['inv-replace']['tax']                     = 200;
        $ledger->invoices['inv-replace']['status']                  = '';
        unset($ledger->invoices['inv-replace']['amount_paid']);

        // A line added in QuickBooks leaves a paid invoice part-paid, so it loses its paid date too.
        $ledger->invoices['inv-grown']['replace_from_quickbooks'] = true;
        $ledger->invoices['inv-grown']['total']                   = 1500;
        $ledger->invoices['inv-grown']['status']                  = 'partial';

        // Totals are written only from a QuickBooks replace or payment.
        $ledger->invoices['inv-total-only']['total'] = 5000;

        $queries = dctQueries(fn () => $directory->save($ledger));
        $paid    = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $kept    = DB::table('ledger_invoices')->where('uuid', 'inv-keep-paid')->first();
        $replace = DB::table('ledger_invoices')->where('uuid', 'inv-replace')->first();

        $grown   = DB::table('ledger_invoices')->where('uuid', 'inv-grown')->first();

        expect(dctStatements($queries, 'update', 'ledger_invoices'))->toHaveCount(4)
            ->and((int) $paid->amount_paid)->toBe(400)
            ->and((int) $paid->balance)->toBe(600)
            ->and((int) $paid->total_amount)->toBe(1000)
            ->and((string) $paid->status)->toBe('partial')
            // Ledger keeps paid_at for a fully paid invoice only (Invoice::markAsPaid).
            ->and($paid->paid_at)->toBeNull()
            ->and((int) $kept->amount_paid)->toBe(250)
            ->and((int) $kept->balance)->toBe(750)
            ->and((string) $kept->status)->toBe('partial')
            ->and((int) $replace->total_amount)->toBe(2000)
            ->and((int) $replace->tax)->toBe(200)
            ->and((int) $replace->subtotal)->toBe(1800)
            ->and((int) $replace->balance)->toBe(1700)
            ->and((int) $replace->amount_paid)->toBe(300)
            ->and((string) $replace->status)->toBe('sent')
            ->and((string) $grown->status)->toBe('partial')
            ->and((int) $grown->balance)->toBe(500)
            ->and($grown->paid_at)->toBeNull()
            ->and((int) DB::table('ledger_invoices')->where('uuid', 'inv-total-only')->value('total_amount'))->toBe(1000);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a paid date is the day in the company timezone and a date-only paid date keeps its day', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        DB::table('companies')->insert(['uuid' => 'company-uuid', 'timezone' => 'America/New_York']);
        // 02:30 UTC on the 21st is the evening of the 20th in New York.
        dctInvoice('inv-evening', 'cust-a', ['status' => 'paid', 'paid_at' => '2026-09-21 02:30:00']);
        // A QuickBooks payment date is stored at midnight and is already that day.
        dctInvoice('inv-from-qb', 'cust-a', ['status' => 'paid', 'paid_at' => '2026-09-21 00:00:00']);

        $ledger = (new FleetbaseDirectory())->load('company-uuid')['ledger'];

        expect($ledger->invoices['inv-evening']['paid_at'])->toBe('2026-09-20')
            ->and($ledger->invoices['inv-from-qb']['paid_at'])->toBe('2026-09-21');

        DB::table('companies')->where('uuid', 'company-uuid')->update(['timezone' => null]);
        $fallback = (new FleetbaseDirectory())->load('company-uuid')['ledger'];

        expect($fallback->invoices['inv-evening']['paid_at'])->toBe('2026-09-21');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a part-paid invoice without a paid date takes the day of the last payment ledger recorded', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        DB::table('companies')->insert(['uuid' => 'company-uuid', 'timezone' => 'America/New_York']);
        dctInvoice('inv-part', 'cust-a', ['status' => 'partial', 'amount_paid' => 400]);
        dctInvoice('inv-none', 'cust-a', ['status' => 'partial', 'amount_paid' => 200]);
        $payment = [
            'company_uuid' => 'company-uuid', 'subject_uuid' => 'inv-part', 'type' => 'invoice_payment',
            'amount'       => 200, 'deleted_at' => null,
        ];
        DB::table('transactions')->insert([
            ['uuid' => 't1', 'created_at' => '2026-09-18 15:00:00'] + $payment,
            // 02:30 UTC on the 21st is the evening of the 20th in New York.
            ['uuid' => 't2', 'created_at' => '2026-09-21 02:30:00'] + $payment,
            ['uuid' => 't3', 'created_at' => '2026-09-25 15:00:00', 'deleted_at' => '2026-09-26 10:00:00'] + $payment,
            ['uuid' => 't4', 'created_at' => '2026-09-27 15:00:00', 'type' => 'refund'] + $payment,
        ]);

        $ledger = (new FleetbaseDirectory())->load('company-uuid')['ledger'];

        expect($ledger->invoices['inv-part']['paid_at'])->toBe('')
            ->and($ledger->invoices['inv-part']['payment_date'])->toBe('2026-09-20')
            ->and($ledger->invoices['inv-none']['payment_date'] ?? '')->toBe('');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a paid invoice carries the reference and method of the last payment ledger recorded', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        dctInvoice('inv-paid', 'cust-a', ['status' => 'paid', 'amount_paid' => 1000]);
        dctInvoice('inv-none', 'cust-a', ['status' => 'paid', 'amount_paid' => 1000]);
        $payment = [
            'company_uuid' => 'company-uuid', 'subject_uuid' => 'inv-paid', 'type' => 'invoice_payment',
            'amount'       => 500, 'deleted_at' => null, 'payment_method' => 'card',
        ];
        DB::table('transactions')->insert([
            ['uuid' => 't1', 'created_at' => '2026-09-18 15:00:00', 'reference' => 'OLD-1', 'payment_method' => 'cash']
            + $payment,
            ['uuid' => 't2', 'created_at' => '2026-09-20 15:00:00', 'reference' => 'CHK-9', 'payment_method' => 'check']
            + $payment,
            ['uuid' => 't3', 'created_at' => '2026-09-25 15:00:00', 'reference' => 'GONE', 'deleted_at' => '2026-09-26']
            + $payment,
            ['uuid' => 't4', 'created_at' => '2026-09-27 15:00:00', 'reference' => 'OTHER', 'company_uuid' => 'other']
            + $payment,
        ]);

        $ledger = (new FleetbaseDirectory())->load('company-uuid')['ledger'];

        expect($ledger->invoices['inv-paid']['payment_reference'])->toBe('CHK-9')
            ->and($ledger->invoices['inv-paid']['payment_method'])->toBe('check')
            ->and($ledger->invoices['inv-none'])->not->toHaveKey('payment_reference');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a loaded ledger can ask which quickbooks accounts any wallet in the realm is linked to', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctLinkRow('link-1', 'wallet', 'wal-other', 'Account', 'acct-9');
        dctLinkRow('link-2', 'wallet', 'wal-org-b', 'Account', 'acct-10', 'realm-1', 'company-b');
        dctLinkRow('link-3', 'wallet', 'wal-realm-2', 'Account', 'acct-11', 'realm-2');

        $ledger = (new FleetbaseDirectory())->load('company-uuid')['ledger'];
        $ids    = ['acct-9', 'acct-10', 'acct-11', 'acct-12'];
        $linked = $ledger->remoteIdsLinked('realm-1', 'Account', $ids, 'wal-new');
        sort($linked);

        expect($linked)->toBe(['acct-10', 'acct-9'])
            ->and($ledger->remoteIdsLinked('realm-1', 'Account', ['acct-9'], 'wal-other'))->toBe([]);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('wallets save only changed fields, never blank a currency or status, and are never created', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctWallet('wal-a', 'Operating');
        dctWallet('wal-b', 'Reserve');
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->load('company-uuid')['ledger'];

        $ledger->wallets['wal-a']['name']     = 'Operating account';
        $ledger->wallets['wal-a']['currency'] = '';
        $ledger->wallets['wal-a']['status']   = '';
        $ledger->wallets['wal-ghost']         = ['uuid' => 'wal-ghost', 'company_uuid' => 'company-uuid', 'name' => 'Ghost', 'currency' => 'USD', 'status' => 'active'];

        $queries = dctQueries(fn () => $directory->save($ledger));
        $a       = DB::table('ledger_wallets')->where('uuid', 'wal-a')->first();

        expect(dctStatements($queries, 'update', 'ledger_wallets'))->toHaveCount(1)
            ->and(dctStatements($queries, 'insert', 'ledger_wallets'))->toBe([])
            ->and($a->name)->toBe('Operating account')
            ->and($a->currency)->toBe('USD')
            ->and($a->status)->toBe('active')
            ->and(DB::table('ledger_wallets')->where('uuid', 'wal-b')->value('name'))->toBe('Reserve')
            ->and(DB::table('ledger_wallets')->where('uuid', 'wal-ghost')->exists())->toBeFalse();
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a stored payment invoice map is updated in place only when its invoice changes', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctLinkRow('l-pay-4', 'payment', '4', 'Payment', '4');
        dctLinkRow('l-map-4', 'payment-invoice', '4', 'PaymentInvoice', 'inv-old');
        dctLinkRow('l-pay-5', 'payment', '5', 'Payment', '5');
        dctLinkRow('l-map-5', 'payment-invoice', '5', 'PaymentInvoice', 'inv-5');
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->load('company-uuid')['ledger'];
        foreach ($ledger->links as $index => $link) {
            if (($link['local_type'] ?? '') === 'payment' && ($link['local_uuid'] ?? '') === '4') {
                $ledger->links[$index]['invoice_uuid'] = 'inv-new';
            }
            if (($link['local_type'] ?? '') === 'payment' && ($link['local_uuid'] ?? '') === '5') {
                $ledger->links[$index]['invoice_uuid'] = 'inv-5';
            }
        }
        $ledger->links[] = [
            'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '6',
            'qbo_entity'   => 'Payment', 'qbo_id' => '6', 'sync_token' => '0', 'invoice_uuid' => '6',
        ];

        $queries = dctQueries(fn () => $directory->save($ledger));

        expect(dctStatements($queries, 'update', 'quickbooks_links'))->toHaveCount(1)
            ->and(DB::table('quickbooks_links')->where('uuid', 'l-map-4')->value('qbo_id'))->toBe('inv-new')
            ->and(DB::table('quickbooks_links')->where('uuid', 'l-map-5')->value('qbo_id'))->toBe('inv-5')
            ->and(DB::table('quickbooks_links')->where('local_type', 'payment-invoice')->count())->toBe(2)
            // A payment whose only invoice hint is its own id gets no map.
            ->and(DB::table('quickbooks_links')->where('local_type', 'payment')->where('local_uuid', '6')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('local_type', 'payment-invoice')->where('local_uuid', '6')->exists())->toBeFalse();
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a new link carrying the uuid of a stored link updates that row instead of adding one', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        DB::table('quickbooks_pending_syncs')->whereIn('uuid', ['p2', 'p3', 'p6'])->update(['status' => 'done']);
        $directory = new FleetbaseDirectory();
        // Only cust-a is pending, so the invoice link l-inv-b is not loaded.
        $ledger          = $directory->loadPending('company-uuid', 10, time())['ledger'];
        $ledger->links[] = [
            'uuid'         => 'l-inv-b', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice',
            'local_uuid'   => 'inv-b', 'qbo_entity' => 'Invoice', 'qbo_id' => '78', 'sync_token' => '3',
        ];
        $ledger->links[] = [
            'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice',
            'local_uuid'   => 'inv-fresh', 'qbo_entity' => 'Invoice', 'qbo_id' => '79', 'sync_token' => '0',
        ];

        $directory->save($ledger);
        $stored = DB::table('quickbooks_links')->where('local_type', 'invoice')->where('local_uuid', 'inv-b')->get();
        $fresh  = DB::table('quickbooks_links')->where('local_uuid', 'inv-fresh')->first();

        expect($stored)->toHaveCount(1)
            ->and($stored[0]->uuid)->toBe('l-inv-b')
            ->and($stored[0]->qbo_id)->toBe('78')
            ->and($stored[0]->sync_token)->toBe('3')
            ->and($fresh->qbo_id)->toBe('79')
            ->and(strlen((string) $fresh->uuid))->toBe(36)
            ->and(DB::table('quickbooks_links')->count())->toBe(10);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a connection moved to another realm since the load keeps links, pending rows, and records as stored', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->load('company-uuid')['ledger'];
        DB::table('quickbooks_connections')->where('company_uuid', 'company-uuid')->update(['realm_id' => 'realm-9']);

        $ledger->customers['cust-a']['name'] = 'Changed';
        $ledger->updatePending('company-uuid', 'customer', 'cust-a', ['status' => 'done']);
        $ledger->links[] = [
            'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice',
            'local_uuid'   => 'inv-new', 'qbo_entity' => 'Invoice', 'qbo_id' => '90', 'sync_token' => '0',
        ];
        $ledger->attempts[] = ['company_uuid' => 'company-uuid', 'local_type' => 'customer', 'local_uuid' => 'cust-a', 'outcome' => 'updated', 'error' => null];

        $directory->save($ledger);

        expect(DB::table('contacts')->where('uuid', 'cust-a')->value('name'))->toBe('Ada')
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'p1')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_links')->where('local_uuid', 'inv-new')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_connections')->where('company_uuid', 'company-uuid')->value('realm_id'))->toBe('realm-9')
            // Attempts are not company-gated.
            ->and(DB::table('quickbooks_sync_attempts')->count())->toBe(1);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a delete batch retires linked customers, closes only empty wallets, and finishes their pending rows', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        dctContact('cust-r2', 'Other realm');
        dctWallet('wal-empty', 'Empty', 0);
        dctWallet('wal-held', 'Held', 500);
        dctLinkRow('l-cust-a', 'customer', 'cust-a', 'Customer', '21');
        dctLinkRow('l-cust-r2', 'customer', 'cust-r2', 'Customer', '21', 'realm-2');
        dctLinkRow('l-wal-empty', 'wallet', 'wal-empty', 'Account', '31');
        dctLinkRow('l-wal-held', 'wallet', 'wal-held', 'Account', '32');
        dctPendingRow('p-cust-a', 'customer', 'cust-a');
        dctPendingRow('p-cust-r2', 'customer', 'cust-r2');
        dctPendingRow('p-wal-empty', 'wallet', 'wal-empty');
        dctPendingRow('p-wal-held', 'wallet', 'wal-held');

        $queries = dctQueries(fn () => (new FleetbaseDirectory())->releaseRemoteDeletes('company-uuid', [
            ['realm_id' => 'realm-1', 'local_type' => 'customer', 'qbo_id' => '21', 'local_uuid' => null],
            ['realm_id' => 'realm-1', 'local_type' => 'wallet', 'qbo_id' => '31'],
            ['realm_id' => 'realm-1', 'local_type' => 'wallet', 'qbo_id' => '32'],
        ]));
        $empty = DB::table('ledger_wallets')->where('uuid', 'wal-empty')->first();
        $held  = DB::table('ledger_wallets')->where('uuid', 'wal-held')->first();

        expect(dctStatements($queries, 'select', 'quickbooks_links'))->toHaveCount(1)
            ->and(dctStatements($queries, 'delete', 'quickbooks_links'))->toHaveCount(1)
            ->and(dctStatements($queries, 'update', 'quickbooks_pending_syncs'))->toHaveCount(1)
            ->and(dctStatements($queries, 'update', 'ledger_invoices'))->toBe([])
            ->and(DB::table('contacts')->where('uuid', 'cust-a')->whereNotNull('deleted_at')->exists())->toBeTrue()
            ->and(DB::table('contacts')->where('uuid', 'cust-r2')->whereNull('deleted_at')->exists())->toBeTrue()
            ->and($empty->deleted_at)->not->toBeNull()
            ->and($empty->status)->toBe('closed')
            ->and($held->deleted_at)->toBeNull()
            ->and($held->status)->toBe('active')
            ->and(DB::table('quickbooks_links')->pluck('uuid')->sort()->values()->all())->toBe(['l-cust-r2', 'l-wal-held'])
            ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->pluck('uuid')->all())->toBe(['p-cust-r2']);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a delete batch unmarks paid invoices per payment, lets a void win, and keeps an already voided status', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        $invoices = [
            'inv-named'  => 'paid',
            'inv-mapped' => 'paid',
            'inv-local'  => 'partial',
            'inv-both'   => 'paid',
            'inv-voided' => 'Voided',
            'inv-map43'  => 'paid',
            'inv-draft'  => 'draft',
        ];
        foreach ($invoices as $uuid => $status) {
            dctInvoice($uuid, 'cust-a', ['status' => $status]);
            dctPendingRow('p-' . $uuid, 'invoice', $uuid);
        }
        dctLinkRow('l-pay-40', 'payment', '40', 'Payment', '40');
        dctLinkRow('l-map-40', 'payment-invoice', '40', 'PaymentInvoice', 'inv-mapped');
        dctLinkRow('l-pay-41', 'payment', 'inv-local', 'Payment', '41');
        dctLinkRow('l-inv-both', 'invoice', 'inv-both', 'Invoice', '50');
        dctLinkRow('l-pay-both', 'payment', 'inv-both', 'Payment', '42');
        dctLinkRow('l-inv-voided', 'invoice', 'inv-voided', 'Invoice', '51');
        dctLinkRow('l-pay-43', 'payment', '43', 'Payment', '43');
        dctLinkRow('l-map-43', 'payment-invoice', '43', 'PaymentInvoice', 'inv-map43');
        dctLinkRow('l-pay-44', 'payment', 'inv-draft', 'Payment', '44');

        $queries = dctQueries(fn () => (new FleetbaseDirectory())->releaseRemoteDeletes('company-uuid', [
            // The payment was read: its lines name every invoice, so the stored map is not applied.
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '40', 'local_uuid' => '40', 'invoice_uuids' => ['inv-named', '40'], 'invoices_from_payment' => true],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '41', 'local_uuid' => 'inv-local'],
            ['realm_id' => 'realm-1', 'local_type' => 'invoice', 'qbo_id' => '50', 'local_uuid' => 'inv-both'],
            ['realm_id' => 'realm-1', 'local_type' => 'invoice', 'qbo_id' => '51', 'local_uuid' => 'inv-voided'],
            // Not read: the stored map names the invoice.
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '43', 'local_uuid' => '43', 'invoice_uuids' => []],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '44', 'local_uuid' => 'inv-draft'],
        ]));
        $statuses = DB::table('ledger_invoices')->pluck('status', 'uuid')->all();
        $pending  = DB::table('quickbooks_pending_syncs')->pluck('status', 'local_uuid')->all();

        expect(dctStatements($queries, 'update', 'ledger_invoices'))->toHaveCount(1)
            ->and(dctStatements($queries, 'select', 'quickbooks_links'))->toHaveCount(1)
            ->and($statuses)->toBe([
                'inv-named'  => 'sent',
                'inv-mapped' => 'paid',
                'inv-local'  => 'sent',
                'inv-both'   => 'void',
                'inv-voided' => 'Voided',
                'inv-map43'  => 'sent',
                'inv-draft'  => 'draft',
            ])
            ->and($pending)->toBe([
                'inv-named'  => 'done',
                'inv-mapped' => 'pending',
                'inv-local'  => 'done',
                'inv-both'   => 'done',
                'inv-voided' => 'done',
                'inv-map43'  => 'done',
                'inv-draft'  => 'done',
            ])
            ->and(DB::table('quickbooks_links')->count())->toBe(0);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a delete without a realm matches every realm and drops payment links only in the realm of each matched invoice link', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        dctInvoice('inv-r1', 'cust-a');
        dctInvoice('inv-r2', 'cust-a');
        dctLinkRow('l-r1', 'invoice', 'inv-r1', 'Invoice', '60', 'realm-1');
        dctLinkRow('l-r2', 'invoice', 'inv-r2', 'Invoice', '60', 'realm-2');
        dctLinkRow('l-pay-r1', 'payment', 'inv-r1', 'Payment', '61', 'realm-1');
        dctLinkRow('l-pay-r2', 'payment', 'inv-r1', 'Payment', '61', 'realm-2');
        dctLinkRow('l-other-type', 'customer', 'cust-a', 'Customer', '60', 'realm-1');

        (new FleetbaseDirectory())->releaseRemoteDeletes('company-uuid', [
            ['realm_id' => '', 'local_type' => 'invoice', 'qbo_id' => '60'],
        ]);

        expect(DB::table('ledger_invoices')->pluck('status', 'uuid')->all())->toBe(['inv-r1' => 'void', 'inv-r2' => 'void'])
            ->and(DB::table('quickbooks_links')->pluck('uuid')->all())->toBe(['l-other-type', 'l-pay-r2'])
            ->and(DB::table('contacts')->where('uuid', 'cust-a')->whereNull('deleted_at')->exists())->toBeTrue();
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a delete batch with nothing to match runs no query', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        $directory = new FleetbaseDirectory();

        $queries = dctQueries(function () use ($directory): void {
            $directory->releaseRemoteDeletes('company-uuid', [
                ['realm_id' => 'realm-1', 'local_type' => '', 'qbo_id' => '1'],
                ['realm_id' => 'realm-1', 'local_type' => 'invoice', 'qbo_id' => '', 'local_uuid' => ''],
                'not-a-deletion',
            ]);
            $directory->releaseRemoteDeletes('', [['realm_id' => 'realm-1', 'local_type' => 'invoice', 'qbo_id' => '1']]);
            $directory->releaseRemoteDeletes('company-uuid', []);
        });

        expect($queries)->toBe([]);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a memory customer delete drops the customer, its links in that realm, and finishes its pending row', function () {
    $ledger            = new SyncLedger();
    $ledger->customers = [
        'cust-a' => ['uuid' => 'cust-a', 'company_uuid' => 'company-a', 'name' => 'Ada'],
        'cust-b' => ['uuid' => 'cust-b', 'company_uuid' => 'company-a', 'name' => 'Babbage'],
    ];
    $ledger->links = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-a', 'qbo_entity' => 'Customer', 'qbo_id' => '7'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-2', 'local_type' => 'customer', 'local_uuid' => 'cust-r2', 'qbo_entity' => 'Customer', 'qbo_id' => '7'],
        ['company_uuid' => 'company-b', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-c', 'qbo_entity' => 'Customer', 'qbo_id' => '7'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-7', 'qbo_entity' => 'Invoice', 'qbo_id' => '7'],
    ];
    $ledger->pending = [
        ['company_uuid' => 'company-a', 'local_type' => 'customer', 'local_uuid' => 'cust-a', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'customer', 'local_uuid' => 'cust-b', 'status' => 'pending'],
        ['company_uuid' => 'company-b', 'local_type' => 'customer', 'local_uuid' => 'cust-a', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'customer', 'local_uuid' => 'cust-a', 'status' => 'failed'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('company-a', 'realm-1', 'customer', '7', null);

    expect(array_keys($ledger->customers))->toBe(['cust-b'])
        ->and(dctLinkIdentities($ledger->links))->toBe(['customer:cust-c', 'customer:cust-r2', 'invoice:inv-7'])
        ->and($ledger->link('company-a', 'realm-1', 'customer', 'cust-a'))->toBeNull()
        ->and($ledger->link('company-a', 'realm-2', 'customer', 'cust-r2')['qbo_id'])->toBe('7')
        ->and(array_column($ledger->pending, 'status'))->toBe(['done', 'pending', 'pending', 'failed']);
});

test('a memory wallet delete removes the wallet whatever its balance', function () {
    $ledger          = new SyncLedger();
    $ledger->wallets = [
        'wal-a' => ['uuid' => 'wal-a', 'company_uuid' => 'company-a', 'name' => 'Held', 'balance' => 500],
    ];
    $ledger->links = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-a', 'qbo_entity' => 'Account', 'qbo_id' => '5'],
    ];
    $ledger->pending = [
        ['company_uuid' => 'company-a', 'local_type' => 'wallet', 'local_uuid' => 'wal-a', 'status' => 'pending'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('company-a', 'realm-1', 'wallet', '5', null);

    expect($ledger->wallets)->toBe([])
        ->and($ledger->links)->toBe([])
        ->and($ledger->pending[0]['status'])->toBe('done');
});

test('a memory invoice delete voids the invoice and drops its payment links in that realm only', function () {
    $ledger           = new SyncLedger();
    $ledger->invoices = [
        'inv-a' => ['uuid' => 'inv-a', 'company_uuid' => 'company-a', 'status' => 'paid'],
        'inv-b' => ['uuid' => 'inv-b', 'company_uuid' => 'company-a', 'status' => 'paid'],
    ];
    $ledger->links = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-a', 'qbo_entity' => 'Invoice', 'qbo_id' => '9'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => 'inv-a', 'qbo_entity' => 'Payment', 'qbo_id' => '3'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-2', 'local_type' => 'payment', 'local_uuid' => 'inv-a', 'qbo_entity' => 'Payment', 'qbo_id' => '3'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => 'inv-b', 'qbo_entity' => 'Payment', 'qbo_id' => '9'],
    ];
    $ledger->pending = [
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-a', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-b', 'status' => 'pending'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('company-a', 'realm-1', 'invoice', '9', null);

    expect($ledger->invoices['inv-a']['status'])->toBe('void')
        ->and($ledger->invoices['inv-b']['status'])->toBe('paid')
        ->and(array_map(static fn (array $link): string => $link['realm_id'] . ':' . $link['local_type'] . ':' . $link['local_uuid'], $ledger->links))->toBe([
            'realm-2:payment:inv-a',
            'realm-1:payment:inv-b',
        ])
        ->and(array_column($ledger->pending, 'status'))->toBe(['done', 'pending']);
});

test('a memory payment delete stored under its quickbooks id unmarks related and mapped invoices of that realm', function () {
    $ledger           = new SyncLedger();
    $ledger->invoices = [
        'inv-rel'  => ['uuid' => 'inv-rel', 'company_uuid' => 'company-a', 'status' => 'Partial'],
        'inv-map'  => ['uuid' => 'inv-map', 'company_uuid' => 'company-a', 'status' => 'paid'],
        'inv-sent' => ['uuid' => 'inv-sent', 'company_uuid' => 'company-a', 'status' => 'draft'],
        'inv-r2'   => ['uuid' => 'inv-r2', 'company_uuid' => 'company-a', 'status' => 'paid'],
    ];
    $ledger->links = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '12', 'qbo_entity' => 'Payment', 'qbo_id' => '12'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '12', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-map'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '12', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => '12'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-2', 'local_type' => 'payment-invoice', 'local_uuid' => '12', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-r2'],
    ];
    $ledger->pending = [
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-rel', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-map', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-sent', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-r2', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => '12', 'status' => 'pending'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('company-a', 'realm-1', 'payment', '12', '12', ['inv-rel', 'inv-sent', '12']);

    expect(array_column($ledger->invoices, 'status', 'uuid'))->toBe([
        'inv-rel'  => 'sent',
        'inv-map'  => 'sent',
        'inv-sent' => 'draft',
        'inv-r2'   => 'paid',
    ])
        ->and(array_map(static fn (array $link): string => $link['realm_id'] . ':' . $link['local_type'] . ':' . $link['qbo_id'], $ledger->links))->toBe([
            'realm-2:payment-invoice:inv-r2',
        ])
        ->and(array_column($ledger->pending, 'status', 'local_uuid'))->toBe([
            'inv-rel'  => 'done',
            'inv-map'  => 'done',
            'inv-sent' => 'done',
            'inv-r2'   => 'pending',
            '12'       => 'pending',
        ]);
});

test('a memory payment delete stored under the invoice uuid unmarks only that invoice', function () {
    $ledger           = new SyncLedger();
    $ledger->invoices = [
        'inv-x' => ['uuid' => 'inv-x', 'company_uuid' => 'company-a', 'status' => 'paid'],
        'inv-y' => ['uuid' => 'inv-y', 'company_uuid' => 'company-a', 'status' => 'paid'],
        'inv-m' => ['uuid' => 'inv-m', 'company_uuid' => 'company-a', 'status' => 'paid'],
    ];
    $ledger->links = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => 'inv-x', 'qbo_entity' => 'Payment', 'qbo_id' => '13'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '13', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-m'],
    ];
    $ledger->pending = [
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-x', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-y', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-m', 'status' => 'pending'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('company-a', 'realm-1', 'payment', '13', 'inv-x', ['inv-y']);

    expect(array_column($ledger->invoices, 'status', 'uuid'))->toBe(['inv-x' => 'sent', 'inv-y' => 'paid', 'inv-m' => 'paid'])
        ->and($ledger->links)->toBe([])
        ->and(array_column($ledger->pending, 'status', 'local_uuid'))->toBe(['inv-x' => 'done', 'inv-y' => 'pending', 'inv-m' => 'pending']);
});

test('memory deletes without a realm match every realm and an empty company or type changes nothing', function () {
    $ledger           = new SyncLedger();
    $ledger->invoices = [
        'inv-1' => ['uuid' => 'inv-1', 'company_uuid' => 'company-a', 'status' => 'sent'],
        'inv-2' => ['uuid' => 'inv-2', 'company_uuid' => 'company-a', 'status' => 'sent'],
    ];
    $ledger->customers = ['cust-1' => ['uuid' => 'cust-1', 'company_uuid' => 'company-a']];
    $ledger->links     = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1', 'qbo_entity' => 'Invoice', 'qbo_id' => '8'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-2', 'local_type' => 'invoice', 'local_uuid' => 'inv-2', 'qbo_entity' => 'Invoice', 'qbo_id' => '8'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'qbo_entity' => 'Customer', 'qbo_id' => '3'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('', 'realm-1', 'customer', '3', 'cust-1');
    $directory->releaseRemoteDelete('company-a', 'realm-1', '', '3', 'cust-1');
    expect($ledger->links)->toHaveCount(3)
        ->and($ledger->customers)->toHaveKey('cust-1');

    $directory->releaseRemoteDeletes('company-a', [
        'not-a-deletion',
        ['realm_id' => '', 'local_type' => 'invoice', 'qbo_id' => '8'],
        ['realm_id' => 'realm-1', 'local_type' => 'customer', 'qbo_id' => '', 'local_uuid' => 'cust-1'],
    ]);

    expect(array_column($ledger->invoices, 'status', 'uuid'))->toBe(['inv-1' => 'void', 'inv-2' => 'void'])
        ->and($ledger->customers)->toBe([])
        ->and($ledger->links)->toBe([]);
});

test('linking a customer queues again its invoices that failed while it had no QuickBooks customer', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        dctContact('cust-b', 'Bea');
        dctInvoice('inv-a1', 'cust-a');
        dctInvoice('inv-a2', 'cust-a');
        dctInvoice('inv-b1', 'cust-b');
        dctPendingRow('pend-cust-a', 'customer', 'cust-a');
        dctPendingRow('pend-a1', 'invoice', 'inv-a1', 'failed');
        dctPendingRow('pend-a2', 'invoice', 'inv-a2', 'done');
        dctPendingRow('pend-b1', 'invoice', 'inv-b1', 'failed');
        DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-a1')->update(['attempts' => 6]);
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->loadPending('company-uuid', 100, time())['ledger'];

        $ledger->putLink([
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'local_type'   => 'customer',
            'local_uuid'   => 'cust-a',
            'qbo_entity'   => 'Customer',
            'qbo_id'       => '93',
            'sync_token'   => '0',
        ]);
        $ledger->updatePending('company-uuid', 'customer', 'cust-a', ['status' => 'done']);
        $queries  = dctQueries(fn () => $directory->save($ledger));
        $rows     = DB::table('quickbooks_pending_syncs')->pluck('status', 'uuid')->all();
        // Only invoices with a failed row are read, never every invoice the linked customers have.
        $invoices = dctStatements($queries, 'select', 'ledger_invoices');

        expect($rows['pend-a1'])->toBe('pending')
            ->and((int) DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-a1')->value('attempts'))->toBe(0)
            ->and($rows['pend-a2'])->toBe('done')
            ->and($rows['pend-b1'])->toBe('failed')
            ->and($rows['pend-cust-a'])->toBe('done')
            ->and(array_filter($invoices, static fn (string $sql): bool => str_contains($sql, '"customer_uuid" in')))
            ->toBe([]);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

/**
 * A Storefront products table on the test connection, with these rows.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function dctProducts(array $rows): void
{
    DB::connection('sqlite')->getSchemaBuilder()->create('products', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36)->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->nullable();
        $table->string('currency')->nullable();
        $table->boolean('is_service')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    foreach ($rows as $row) {
        DB::table('products')->insert(array_merge([
            'company_uuid' => 'company-uuid',
            'public_id'    => 'product_' . $row['uuid'],
            'price'        => 1200,
            'currency'     => 'USD',
            'is_service'   => 0,
            'status'       => 'published',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $row));
    }
}

test('a pending load reads the products of its rows and every live product sharing their names', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctContact('cust-a', 'Ada');
        dctInvoice('inv-a', 'cust-a');
        DB::table('ledger_invoice_items')->insert([
            'uuid'     => 'line-a', 'invoice_uuid' => 'inv-a', 'description' => 'Iced  Coffee',
            'quantity' => 1, 'unit_price' => 450, 'amount' => 450,
        ]);
        dctProducts([
            ['uuid' => 'p-gone', 'name' => 'Mug', 'deleted_at' => now()],
            ['uuid' => 'p-mug', 'name' => 'MUG'],
            ['uuid' => 'p-coffee', 'name' => 'iced coffee'],
            ['uuid' => 'p-draft', 'name' => 'Iced coffee', 'status' => 'draft'],
            ['uuid' => 'p-other', 'name' => 'Mug', 'company_uuid' => 'company-2'],
            ['uuid' => 'p-tea', 'name' => 'Tea'],
            ['uuid' => 'p-old', 'name' => 'Old name'],
        ]);
        DB::table('quickbooks_links')->insert([
            'uuid'       => 'link-old', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1',
            'local_type' => 'item',
            'local_uuid' => Fleetbase\Quickbooks\Support\ProductMapper::itemUuid('company-uuid', 'Old name'),
            'qbo_entity' => 'Item', 'qbo_id' => '40', 'sync_token' => '0', 'name' => 'Old name', 'state' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        dctPendingRow('pend-gone', 'product', 'p-gone');
        dctPendingRow(
            'pend-old',
            'item',
            Fleetbase\Quickbooks\Support\ProductMapper::itemUuid('company-uuid', 'Old name')
        );
        dctPendingRow('pend-inv', 'invoice', 'inv-a');

        $ledger = (new FleetbaseDirectory())->loadPending('company-uuid', 50, time())['ledger'];

        expect(dctSortedKeys($ledger->products))->toBe(['p-coffee', 'p-gone', 'p-mug', 'p-old'])
            ->and($ledger->products['p-gone']['deleted_at'])->not->toBeNull()
            ->and($ledger->products['p-mug']['price'])->toBe(1200)
            ->and($ledger->products['p-mug']['is_service'])->toBeFalse()
            ->and($ledger->productsUnavailable)->toBeFalse()
            ->and(dctLinkIdentities($ledger->links))
            ->toContain('item:' . Fleetbase\Quickbooks\Support\ProductMapper::itemUuid('company-uuid', 'Old name'));
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('without a Storefront products table there are no products, and nothing is unavailable', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctPendingRow('pend-p', 'product', 'p-1');

        $ledger = (new FleetbaseDirectory())->loadPending('company-uuid', 50, time())['ledger'];

        expect($ledger->products)->toBe([])
            ->and($ledger->productsUnavailable)->toBeFalse()
            ->and($ledger->productsMissing)->toBeTrue();
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a Storefront database that is not configured leaves products unavailable', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        dctPendingRow('pend-p', 'product', 'p-1');
        Fleetbase\Storefront\Models\Product::$testConnection = 'storefront-missing';

        $ledger = (new FleetbaseDirectory())->loadPending('company-uuid', 50, time())['ledger'];

        expect($ledger->productsUnavailable)->toBeTrue()
            ->and($ledger->productsMissing)->toBeFalse();
    } finally {
        Fleetbase\Storefront\Models\Product::$testConnection = null;
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a product link keeps its name and state, and a cleared state is written', function () {
    $restore = dctSqlite();
    try {
        dctConnection();
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->loadPending('company-uuid', 50, time())['ledger'];
        $link      = [
            'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'item',
            'local_uuid'   => 'item-uuid',
            'qbo_entity'   => 'Item', 'qbo_id' => '40', 'sync_token' => '0', 'name' => 'Mug', 'state' => 'deactivated',
        ];
        $ledger->putLink($link);
        $directory->save($ledger);
        $stored = DB::table('quickbooks_links')->where('local_uuid', 'item-uuid')->first();

        dctPendingRow('pend-item', 'item', 'item-uuid');
        $directory = new FleetbaseDirectory();
        $ledger    = $directory->loadPending('company-uuid', 50, time())['ledger'];
        $loaded    = $ledger->link('company-uuid', 'realm-1', 'item', 'item-uuid');
        $ledger->putLink(['state' => null, 'sync_token' => '1'] + $link);
        $directory->save($ledger);
        $cleared = DB::table('quickbooks_links')->where('local_uuid', 'item-uuid')->first();

        expect($stored->name)->toBe('Mug')
            ->and($stored->state)->toBe('deactivated')
            ->and($loaded['state'])->toBe('deactivated')
            ->and($cleared->state)->toBeNull()
            ->and($cleared->sync_token)->toBe('1');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a webhook invoice moved to another linked customer in quickbooks takes that customer', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        $entities               = [['entity' => 'Invoice', 'id' => '10', 'operation' => 'Update']];
        $loaded                 = (new FleetbaseDirectory())->loadLinked('company-uuid', $entities);
        [$engine, $client]      = qbEngine();
        $client->invoices['10'] = [
            'Id'          => '10', 'SyncToken' => '1', 'DocNumber' => 'inv-a', 'CustomerRef' => ['value' => '2'],
            'TotalAmt'    => '10.00', 'Balance' => '10.00',
            'Line'        => [[
                'Id'                  => '1', 'DetailType' => 'SalesItemLineDetail', 'Amount' => '10.00',
                'Description'         => 'x', 'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
            ]],
        ];

        $engine->acceptRemoteChanges(
            $loaded['ledger'],
            'company-uuid',
            $entities,
            qbSettings(['invoice_conflict' => 'quickbooks']),
            time()
        );

        expect($loaded['ledger']->invoices['inv-a']['customer_uuid'])->toBe('cust-b');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('an invoice number and customer changed together in quickbooks are both copied', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        $entities               = [['entity' => 'Invoice', 'id' => '10', 'operation' => 'Update']];
        $loaded                 = (new FleetbaseDirectory())->loadLinked('company-uuid', $entities);
        [$engine, $client]      = qbEngine();
        $client->invoices['10'] = [
            'Id'          => '10', 'SyncToken' => '1', 'DocNumber' => 'QB-1001', 'CustomerRef' => ['value' => '2'],
            'TotalAmt'    => '10.00', 'Balance' => '10.00',
            'Line'        => [[
                'Id'                  => '1', 'DetailType' => 'SalesItemLineDetail', 'Amount' => '10.00',
                'Description'         => 'x', 'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
            ]],
        ];

        $engine->acceptRemoteChanges(
            $loaded['ledger'],
            'company-uuid',
            $entities,
            qbSettings(['invoice_conflict' => 'quickbooks', 'invoice_reference' => 'quickbooks']),
            time()
        );

        expect($loaded['ledger']->invoices['inv-a']['number'])->toBe('QB-1001')
            ->and($loaded['ledger']->invoices['inv-a']['customer_uuid'])->toBe('cust-b');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('an invoice moved to an unlinked quickbooks customer keeps its customer and says why', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        $entities               = [['entity' => 'Invoice', 'id' => '10', 'operation' => 'Update']];
        $loaded                 = (new FleetbaseDirectory())->loadLinked('company-uuid', $entities);
        [$engine, $client]      = qbEngine();
        $client->invoices['10'] = [
            'Id'          => '10', 'SyncToken' => '1', 'DocNumber' => 'inv-a', 'CustomerRef' => ['value' => '99'],
            'TotalAmt'    => '10.00', 'Balance' => '10.00', 'PrivateNote' => 'moved',
            'Line'        => [[
                'Id'                  => '1', 'DetailType' => 'SalesItemLineDetail', 'Amount' => '10.00',
                'Description'         => 'x', 'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
            ]],
        ];

        $engine->acceptRemoteChanges(
            $loaded['ledger'],
            'company-uuid',
            $entities,
            qbSettings(['invoice_conflict' => 'quickbooks']),
            time()
        );
        $notes = array_column(array_filter(
            $loaded['ledger']->attempts,
            static fn (array $attempt): bool => $attempt['local_uuid'] === 'inv-a'
        ), 'error');

        expect($loaded['ledger']->invoices['inv-a']['customer_uuid'])->toBe('cust-c')
            ->and(array_map('qbText', $notes))->toBe([
                'QuickBooks moved this invoice to customer 99, which is not linked to a Fleetbase customer.'
                . ' The Fleetbase customer was kept.',
            ]);
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');

test('a customer link whose fleetbase customer is deleted is not used for a moved invoice', function () {
    $restore = dctSqlite();
    try {
        dctLoadRows();
        DB::table('contacts')->where('uuid', 'cust-b')->update(['deleted_at' => now()]);
        $entities = [['entity' => 'Invoice', 'id' => '10', 'operation' => 'Update']];
        $loaded   = (new FleetbaseDirectory())->loadLinked('company-uuid', $entities);
        $reader   = $loaded['ledger']->customerLinkReader;

        expect($reader)->not->toBeNull()
            ->and($reader('realm-1', '2'))->toBeNull()
            ->and($reader('realm-1', '1')['local_uuid'])->toBe('cust-a');
    } finally {
        $restore();
    }
})->skip($dctNoSqlite, 'PDO SQLite is unavailable.');
