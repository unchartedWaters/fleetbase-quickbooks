<?php

use Fleetbase\Casts\Money;
use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('stored datetimes reach the engine as unix seconds', function () {
    $row = FleetbaseDirectory::toTimestamps([
        'token_expires_at'   => '2026-09-27T20:50:00.000000Z',
        'rate_limited_until' => Carbon::createFromTimestamp(1790000000),
        'last_batch_at'      => null,
        'realm_id'           => '2026',
    ], FleetbaseDirectory::CONNECTION_TIMES);

    expect($row['token_expires_at'])->toBe(strtotime('2026-09-27T20:50:00Z'))
        ->and($row['rate_limited_until'])->toBe(1790000000)
        ->and($row['last_batch_at'])->toBeNull()
        ->and($row['realm_id'])->toBe('2026');
});

test('signed minor units stay negative when the money cast would drop the minus', function () {
    $invoice = new class extends Illuminate\Database\Eloquent\Model {
        protected $table = 'ledger_invoices';

        protected $guarded = [];

        public $timestamps = false;

        protected $casts = [
            'tax'          => Money::class,
            'total_amount' => Money::class,
            'subtotal'     => Money::class,
            'amount_paid'  => Money::class,
            'balance'      => Money::class,
        ];
    };
    $invoice->setRawAttributes([
        'tax'          => 0,
        'total_amount' => 1000,
        'subtotal'     => 1000,
        'amount_paid'  => 0,
        'balance'      => 1000,
    ], true);

    $invoice->amount_paid = -250;
    $invoice->balance     = -1500;

    expect($invoice->getAttributes()['amount_paid'])->toBe(250)
        ->and($invoice->getAttributes()['balance'])->toBe(1500);

    $credit = FleetbaseDirectory::invoiceAmounts(-1000, -100, 0);
    $over   = FleetbaseDirectory::invoiceAmounts(1000, 0, 1500);
    foreach ($credit as $field => $minor) {
        FleetbaseDirectory::writeSignedMinor($invoice, $field, $minor);
    }
    FleetbaseDirectory::writeSignedMinor($invoice, 'amount_paid', $over['balance'] === -500 ? 1500 : 0);

    expect($invoice->getAttributes()['total_amount'])->toBe(-1000)
        ->and($invoice->getAttributes()['tax'])->toBe(-100)
        ->and($invoice->getAttributes()['subtotal'])->toBe(-900)
        ->and($invoice->getAttributes()['balance'])->toBe(-1000)
        ->and($invoice->getAttributes()['amount_paid'])->toBe(1500);

    foreach ($over as $field => $minor) {
        FleetbaseDirectory::writeSignedMinor($invoice, $field, $minor);
    }

    expect($invoice->getAttributes()['balance'])->toBe(-500)
        ->and($invoice->getAttributes()['subtotal'])->toBe(1000)
        ->and($over['balance'])->toBe(-500)
        ->and($credit['subtotal'])->toBe(-900);
});

test('unix seconds are written back as dates and null stays null', function () {
    $row = FleetbaseDirectory::toDates([
        'next_attempt_at' => 1790000000,
        'attempts'        => 3,
    ], FleetbaseDirectory::PENDING_TIMES);
    $empty = FleetbaseDirectory::toDates(['next_attempt_at' => null], FleetbaseDirectory::PENDING_TIMES);

    expect($row['next_attempt_at'])->toBeInstanceOf(Carbon::class)
        ->and($row['next_attempt_at']->getTimestamp())->toBe(1790000000)
        ->and($row['attempts'])->toBe(3)
        ->and($empty['next_attempt_at'])->toBeNull();
});

test('writePending ignores only driver-specific duplicate violations', function () {
    expect(directoryDuplicatePendingWrite(directoryQueryException(
        '23000',
        1062,
        "Duplicate entry 'invoice-1' for key 'pending_identity'"
    )))->toBeTrue()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            1452,
            'Cannot add or update a child row: a foreign key constraint fails'
        )))->toBeFalse()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23505',
            7,
            'duplicate key value violates unique constraint "pending_identity"'
        )))->toBeTrue()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            2067,
            'UNIQUE constraint failed: quickbooks_pending_sync.company_uuid'
        )))->toBeTrue()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            19,
            'NOT NULL constraint failed: quickbooks_pending_sync.local_uuid'
        )))->toBeFalse()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            19,
            'CHECK constraint failed: pending_status'
        )))->toBeFalse();
});

test('flagging writes one pending row and never saves the ledger', function () {
    $directory = directoryWithConnection();
    $invoice   = (object) ['uuid' => 'inv-1', 'company_uuid' => 'company-uuid', 'status' => 'sent'];

    $directory->flag(function (SyncLedger $ledger) use ($invoice): void {
        (new SyncFlagger())->fromInvoiceEvent($ledger, $invoice, 'updated');
    }, 'company-uuid');

    expect($directory->written)->toHaveCount(1)
        ->and($directory->written[0]['company_uuid'])->toBe('company-uuid')
        ->and($directory->written[0]['local_type'])->toBe('invoice')
        ->and($directory->written[0]['local_uuid'])->toBe('inv-1')
        ->and($directory->written[0]['status'])->toBe('pending');
});

test('flagging a company without a connection writes nothing', function () {
    $directory                   = directoryWithConnection();
    $directory->storedConnection = null;

    $directory->flag(function (SyncLedger $ledger): void {
        (new SyncFlagger())->fromWalletEvent($ledger, (object) ['uuid' => 'wal-1', 'company_uuid' => 'company-uuid']);
    }, 'company-uuid');

    expect($directory->written)->toBe([]);
});

test('flagging a connection without a realm writes nothing', function () {
    $directory                   = directoryWithConnection();
    $directory->storedConnection = ['company_uuid' => 'company-uuid', 'realm_id' => '  '];

    $directory->flag(function (SyncLedger $ledger): void {
        (new SyncFlagger())->fromCustomerEvent($ledger, (object) [
            'uuid'         => 'cus-1',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
        ]);
    }, 'company-uuid');

    expect($directory->written)->toBe([]);
});

test('the flagger does not queue a pending row when the connection has no realm', function () {
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = ['company_uuid' => 'company-uuid', 'realm_id' => ''];

    (new SyncFlagger())->fromCustomerEvent($ledger, (object) [
        'uuid'         => 'cus-1',
        'company_uuid' => 'company-uuid',
        'type'         => 'customer',
    ]);

    expect($ledger->pending)->toBe([]);
});

test('a change to the amount paid flags the invoice', function () {
    $directory = directoryWithConnection();
    Container::getInstance()->instance(FlagInvoiceListener::class, new FlagInvoiceListener($directory, new SyncFlagger()));
    $invoice = new class {
        public string $uuid = 'inv-1';

        public string $company_uuid = 'company-uuid';

        public string $status = 'sent';

        public function wasChanged(array $fields): bool
        {
            return in_array('amount_paid', $fields, true);
        }
    };

    (new FlagInvoiceObserver())->updated($invoice);

    expect($directory->written)->toHaveCount(1)
        ->and($directory->written[0]['local_uuid'])->toBe('inv-1');
});

test('listeners do not flag while the extension is writing', function () {
    $directory = directoryWithConnection();
    $listener  = new FlagCustomerListener($directory, new SyncFlagger());
    $customer  = (object) ['uuid' => 'cus-1', 'company_uuid' => 'company-uuid', 'type' => 'customer'];

    SyncSuppressor::pause();
    try {
        $listener->handle((object) ['customer' => $customer]);
    } finally {
        SyncSuppressor::resume();
    }
    expect($directory->written)->toBe([]);

    $listener->handle((object) ['customer' => $customer]);
    expect($directory->written)->toHaveCount(1);
});

test('a webhook load keeps only the rows linked to those quickbooks ids', function () {
    $directory         = new FleetbaseDirectory();
    $directory->memory = directoryWebhookLedger();

    $loaded    = $directory->loadLinked('company-uuid', directoryWebhookEntities());
    $customers = array_keys($loaded['ledger']->customers);
    $invoices  = array_keys($loaded['ledger']->invoices);
    $wallets   = array_keys($loaded['ledger']->wallets);
    $qboIds    = array_column($loaded['ledger']->links, 'qbo_id');
    sort($customers);
    sort($invoices);
    sort($wallets);
    sort($qboIds);

    expect($loaded['connection']['realm_id'])->toBe('realm-1')
        ->and($customers)->toBe(['cust-1', 'cust-9'])
        ->and($invoices)->toBe(['inv-3', 'inv-9'])
        ->and($wallets)->toBe(['wal-5'])
        ->and($loaded['ledger']->pending)->toBe([])
        ->and($qboIds)->toBe(['1', '3', '4', '5', '6', '8'])
        ->and(array_keys($directory->memory->customers))->toContain('cust-extra');

    $full = $directory->load('company-uuid');
    expect(array_keys($full['ledger']->customers))->toContain('cust-extra')
        ->and(array_keys($full['ledger']->invoices))->toContain('inv-extra');
});

test('a webhook load with no matching link does not read the catalog', function () {
    $directory         = new FleetbaseDirectory();
    $directory->memory = directoryWebhookLedger();

    $loaded = $directory->loadLinked('company-uuid', [
        ['entity' => 'Vendor', 'id' => '2', 'operation' => 'Create'],
        ['entity' => 'Customer', 'id' => '404', 'operation' => 'Update'],
    ]);

    expect($loaded['ledger']->customers)->toBe([])
        ->and($loaded['ledger']->invoices)->toBe([])
        ->and($loaded['ledger']->wallets)->toBe([])
        ->and($loaded['ledger']->links)->toBe([])
        ->and($loaded['ledger']->pending)->toBe([])
        ->and($loaded['connection']['realm_id'])->toBe('realm-1')
        ->and(array_keys($directory->memory->customers))->toContain('cust-extra');
});

test('a webhook load without a connection returns null', function () {
    $directory         = new FleetbaseDirectory();
    $directory->memory = new SyncLedger();

    expect($directory->loadLinked('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
    ]))->toBeNull();
});

test('a webhook load reads linked rows from the database and leaves the rest of the catalog', function () {
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
    $schema = DB::connection('sqlite')->getSchemaBuilder();

    try {
        directoryWebhookSchema($schema);
        directoryWebhookRows();
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $loaded    = (new FleetbaseDirectory())->loadLinked('company-uuid', [
            ['entity' => 'Payment', 'id' => '4', 'operation' => 'Create'],
            ['entity' => 'Invoice', 'id' => '11', 'operation' => 'Update'],
            ['entity' => 'Account', 'id' => '5', 'operation' => 'Update'],
            ['entity' => 'Customer', 'id' => '404', 'operation' => 'Update'],
        ]);
        $queries   = $connection->getQueryLog();
        $sql       = implode("\n", array_column($queries, 'query'));
        $bindings  = [];
        foreach ($queries as $query) {
            foreach ($query['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }
        $customers = array_keys($loaded['ledger']->customers);
        $invoices  = array_keys($loaded['ledger']->invoices);
        $wallets   = array_keys($loaded['ledger']->wallets);
        $qboIds    = array_column($loaded['ledger']->links, 'qbo_id');
        sort($customers);
        sort($invoices);
        sort($wallets);
        sort($qboIds);

        expect($customers)->toBe(['cust-9', 'cust-void'])
            ->and($invoices)->toBe(['inv-9', 'inv-void'])
            ->and($wallets)->toBe(['wal-5'])
            ->and($loaded['ledger']->pending)->toBe([])
            ->and($qboIds)->toBe(['4', '5', '6', '8', '11', '12'])
            ->and($sql)->toContain('qbo_id')
            ->and($sql)->not->toContain('quickbooks_pending_syncs')
            ->and($bindings)->not->toContain('cust-extra')
            ->and($bindings)->not->toContain('inv-extra')
            ->and($bindings)->not->toContain('wal-extra');

        $full = (new FleetbaseDirectory())->load('company-uuid');
        expect(array_keys($full['ledger']->customers))->toContain('cust-extra')
            ->and(array_keys($full['ledger']->invoices))->toContain('inv-extra')
            ->and(array_keys($full['ledger']->invoices))->not->toContain('inv-void');
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbaseConnection);
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('rekeying a payment link from the invoice uuid persists the quickbooks payment id', function () {
    [$restore] = directorySqlite();
    try {
        $schema = DB::connection('sqlite')->getSchemaBuilder();
        directoryWebhookSchema($schema);
        $schema->table('quickbooks_links', function (Blueprint $table): void {
            $table->unique(['company_uuid', 'local_type', 'local_uuid'], 'quickbooks_links_local_unique');
        });
        directoryWebhookRows();
        $now = now();
        DB::table('quickbooks_links')->insert([
            'uuid'         => 'link-payment-id',
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'local_type'   => 'payment',
            'local_uuid'   => '4',
            'qbo_entity'   => 'Payment',
            'qbo_id'       => '4',
            'sync_token'   => '1',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DB::table('quickbooks_links')->insert([
            'uuid'         => 'link-other-payment',
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'local_type'   => 'payment',
            'local_uuid'   => 'inv-other',
            'qbo_entity'   => 'Payment',
            'qbo_id'       => '9',
            'sync_token'   => '0',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $directory     = new FleetbaseDirectory();
        $loaded        = $directory->load('company-uuid');
        $ledger        = $loaded['ledger'];
        $ledger->links = array_values(array_filter(
            $ledger->links,
            static fn (array $link): bool => !(($link['local_type'] ?? '') === 'payment' && ($link['local_uuid'] ?? '') === 'inv-9' && ($link['qbo_id'] ?? '') === '4')
        ));
        $directory->save($ledger);

        $stored = Link::query()
            ->where('company_uuid', 'company-uuid')
            ->where('local_type', 'payment')
            ->where('qbo_id', '4')
            ->get();

        expect($stored)->toHaveCount(1)
            ->and((string) $stored[0]->local_uuid)->toBe('4')
            ->and((string) $stored[0]->qbo_id)->toBe('4')
            ->and(Link::query()->where('local_type', 'payment')->where('local_uuid', 'inv-9')->exists())->toBeFalse()
            ->and(Link::query()->where('uuid', 'link-other-payment')->value('local_uuid'))->toBe('inv-other');

        $again = new FleetbaseDirectory();
        $fresh = $again->load('company-uuid');
        foreach ($fresh['ledger']->links as $index => $link) {
            if (($link['local_type'] ?? '') === 'payment' && ($link['qbo_id'] ?? '') === '9') {
                $fresh['ledger']->links[$index]['local_uuid'] = '9';
                $fresh['ledger']->links[$index]['qbo_id']     = '9';
                $fresh['ledger']->links[$index]['sync_token'] = '8';
            }
        }
        $again->save($fresh['ledger']);
        $rekeyed = Link::query()->where('local_type', 'payment')->where('qbo_id', '9')->get();

        expect($rekeyed)->toHaveCount(1)
            ->and((string) $rekeyed[0]->uuid)->toBe('link-other-payment')
            ->and((string) $rekeyed[0]->local_uuid)->toBe('9')
            ->and((string) $rekeyed[0]->sync_token)->toBe('8')
            ->and(Link::query()->where('local_type', 'payment')->where('local_uuid', 'inv-other')->exists())->toBeFalse();
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('changed links and pending rows update in bulk and attempts insert in one chunk', function () {
    [$restore] = directorySqlite();
    try {
        directoryWebhookSchema(DB::connection('sqlite')->getSchemaBuilder());
        directoryWebhookRows();
        $directory = new FleetbaseDirectory();
        $loaded    = $directory->load('company-uuid');
        $ledger    = $loaded['ledger'];
        foreach ($ledger->links as $index => $link) {
            if (($link['local_uuid'] ?? '') === 'cust-9') {
                $ledger->links[$index]['sync_token'] = '9';
            }
            if (($link['local_uuid'] ?? '') === 'inv-9' && ($link['local_type'] ?? '') === 'invoice') {
                $ledger->links[$index]['qbo_id'] = '80';
            }
        }
        foreach ($ledger->pending as $index => $row) {
            if (($row['uuid'] ?? '') === 'pend-1') {
                $ledger->pending[$index]['attempts'] = 2;
            }
        }
        $ledger->attempts = [
            ['company_uuid' => 'company-uuid', 'local_type' => 'customer', 'local_uuid' => 'cust-9', 'outcome' => 'failed', 'error' => 'one'],
            ['company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-9', 'outcome' => 'failed', 'error' => 'two'],
        ];
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $directory->save($ledger);
        $queries = array_column($connection->getQueryLog(), 'query');
        $updates = array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'update "quickbooks_links"') || str_starts_with(strtolower($sql), 'update `quickbooks_links`')));
        $pending = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'quickbooks_pending_syncs') && str_starts_with(strtolower(ltrim($sql)), 'update')));
        $inserts = array_values(array_filter($queries, static fn (string $sql): bool => str_contains(strtolower($sql), 'insert into') && str_contains($sql, 'quickbooks_sync_attempts')));

        expect($updates)->toHaveCount(1)
            ->and($pending)->toHaveCount(1)
            ->and($inserts)->toHaveCount(1)
            ->and(Link::query()->where('local_uuid', 'cust-9')->where('local_type', 'customer')->value('sync_token'))->toBe('9')
            ->and(Link::query()->where('local_uuid', 'inv-9')->where('local_type', 'invoice')->value('qbo_id'))->toBe('80')
            ->and((int) PendingSync::query()->where('uuid', 'pend-1')->value('attempts'))->toBe(2)
            ->and(SyncAttempt::query()->count())->toBe(2);
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('inbound invoice lines are replaced with one delete and one insert', function () {
    [$restore] = directorySqlite();
    try {
        $schema = DB::connection('sqlite')->getSchemaBuilder();
        directoryWebhookSchema($schema);
        $schema->table('ledger_invoices', function (Blueprint $table) {
            $table->integer('subtotal')->default(0);
            $table->integer('balance')->default(0);
        });
        $schema->table('ledger_invoice_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->integer('tax_amount')->default(0);
            $table->timestamps();
        });
        directoryWebhookRows();
        $now = now();
        DB::table('ledger_invoice_items')->insert([
            ['uuid' => 'line-old-1', 'invoice_uuid' => 'inv-9', 'description' => 'Old', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100],
            ['uuid' => 'line-old-2', 'invoice_uuid' => 'inv-9', 'description' => 'Older', 'quantity' => 2, 'unit_price' => 50, 'amount' => 100],
        ]);
        $directory                                            = new FleetbaseDirectory();
        $loaded                                               = $directory->load('company-uuid');
        $ledger                                               = $loaded['ledger'];
        $ledger->invoices['inv-9']['replace_from_quickbooks'] = true;
        $ledger->invoices['inv-9']['payment_from_quickbooks'] = true;
        $ledger->invoices['inv-9']['items_from_quickbooks']   = true;
        $ledger->invoices['inv-9']['total']                   = -100;
        $ledger->invoices['inv-9']['tax']                     = -25;
        $ledger->invoices['inv-9']['amount_paid']             = -40;
        $ledger->invoices['inv-9']['items']                   = [
            ['description' => 'New', 'quantity' => 3, 'unit_price' => -50, 'amount' => -150],
            ['description' => 'Newer', 'quantity' => 1, 'unit_price' => 25, 'amount' => 25],
        ];
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $directory->save($ledger);
        $queries = array_column($connection->getQueryLog(), 'query');
        $itemSql = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'ledger_invoice_items')
        ));
        $stored = DB::table('ledger_invoice_items')->where('invoice_uuid', 'inv-9')->whereNull('deleted_at')->orderBy('description')->get();

        $deletes = array_values(array_filter($itemSql, static fn (string $sql): bool => str_contains(strtolower($sql), 'deleted_at') && str_starts_with(strtolower(ltrim($sql)), 'update')));
        $inserts = array_values(array_filter($itemSql, static fn (string $sql): bool => str_contains(strtolower($sql), 'insert')));
        $eager   = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'templates')
                || str_contains($sql, 'tracking_numbers')
                || str_contains($sql, '"orders"')
                || (str_contains($sql, 'ledger_invoice_items') && str_starts_with(strtolower(ltrim($sql)), 'select'))
                || (str_contains($sql, 'contacts') && !str_contains($sql, 'select "uuid"'))
        ));
        $invoice = DB::table('ledger_invoices')->where('uuid', 'inv-9')->first();

        expect($deletes)->toHaveCount(1)
            ->and($inserts)->toHaveCount(1)
            ->and($eager)->toBe([])
            ->and($stored)->toHaveCount(2)
            ->and((int) $stored[0]->unit_price)->toBe(-50)
            ->and((int) $stored[0]->amount)->toBe(-150)
            ->and((int) $invoice->tax)->toBe(-25)
            ->and((int) $invoice->total_amount)->toBe(-100)
            ->and((int) $invoice->subtotal)->toBe(-75)
            ->and((int) $invoice->balance)->toBe(-60)
            ->and((int) $invoice->amount_paid)->toBe(-40)
            ->and(DB::table('ledger_invoice_items')->where('uuid', 'line-old-1')->whereNotNull('deleted_at')->exists())->toBeTrue();
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('stale invoice payment links for a chunk are deleted in one statement', function () {
    [$restore] = directorySqlite();
    try {
        directoryWebhookSchema(DB::connection('sqlite')->getSchemaBuilder());
        directoryWebhookRows();
        $now = now();
        DB::table('quickbooks_links')->insert([
            [
                'uuid'       => 'link-pay-4', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment',
                'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '1', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'uuid'       => 'link-pay-9-id', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment',
                'local_uuid' => '9', 'qbo_entity' => 'Payment', 'qbo_id' => '9', 'sync_token' => '1', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'uuid'       => 'link-pay-9-invoice', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment',
                'local_uuid' => 'inv-other', 'qbo_entity' => 'Payment', 'qbo_id' => '9', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        $directory     = new FleetbaseDirectory();
        $loaded        = $directory->load('company-uuid');
        $ledger        = $loaded['ledger'];
        $ledger->links = array_values(array_filter(
            $ledger->links,
            static fn (array $link): bool => !((string) ($link['local_type'] ?? '') === 'payment' && in_array((string) ($link['local_uuid'] ?? ''), ['inv-9', 'inv-other'], true))
        ));
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $directory->save($ledger);
        $deletes = array_values(array_filter(
            array_column($connection->getQueryLog(), 'query'),
            static fn (string $sql): bool => str_contains($sql, 'quickbooks_links') && str_starts_with(strtolower(ltrim($sql)), 'delete')
        ));

        expect($deletes)->toHaveCount(1)
            ->and(Link::query()->where('local_type', 'payment')->where('local_uuid', 'inv-9')->exists())->toBeFalse()
            ->and(Link::query()->where('local_type', 'payment')->where('local_uuid', 'inv-other')->exists())->toBeFalse()
            ->and(Link::query()->where('local_type', 'payment')->where('local_uuid', '4')->exists())->toBeTrue()
            ->and(Link::query()->where('local_type', 'payment')->where('local_uuid', '9')->exists())->toBeTrue();
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('a payment link stored under the quickbooks id unmarks the paid invoice', function () {
    $ledger                       = new SyncLedger();
    $ledger->invoices['inv-paid'] = [
        'uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'status' => 'paid', 'amount_paid' => 2500, 'total' => 2500,
    ];
    $ledger->invoices['inv-open'] = [
        'uuid' => 'inv-open', 'company_uuid' => 'company-a', 'status' => 'paid', 'amount_paid' => 100, 'total' => 100,
    ];
    $ledger->links = [
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-paid'],
        ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-open', 'qbo_entity' => 'Invoice', 'qbo_id' => '8'],
    ];
    $ledger->pending = [
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'status' => 'pending'],
        ['company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-open', 'status' => 'pending'],
    ];
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    $directory->releaseRemoteDelete('company-a', 'realm-1', 'payment', '4', '4');

    expect($ledger->invoices['inv-paid']['status'])->toBe('sent')
        ->and($ledger->invoices['inv-paid']['amount_paid'])->toBe(2500)
        ->and($ledger->invoices['inv-open']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-open']['amount_paid'])->toBe(100)
        ->and($ledger->pending[0]['status'])->toBe('done')
        ->and($ledger->pending[1]['status'])->toBe('pending')
        ->and($ledger->link('company-a', 'realm-1', 'payment', '4'))->toBeNull()
        ->and($ledger->link('company-a', 'realm-1', 'invoice', 'inv-open')['qbo_id'])->toBe('8');
});

test('a delete batch uses one link select and bulk status, link, and pending writes', function () {
    [$restore] = directorySqlite();
    try {
        directoryWebhookSchema(DB::connection('sqlite')->getSchemaBuilder());
        $now = now();
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-void-me', 'company_uuid' => 'company-uuid', 'status' => 'sent', 'total_amount' => 1000, 'amount_paid' => 0, 'tax' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-uuid', 'status' => 'paid', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-same-id', 'company_uuid' => 'company-uuid', 'status' => 'paid', 'total_amount' => 800, 'amount_paid' => 800, 'tax' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-inv', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-void-me', 'qbo_entity' => 'Invoice', 'qbo_id' => '8', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-same', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-same-id', 'qbo_entity' => 'Invoice', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-pay', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-cust', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'qbo_entity' => 'Customer', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach (['inv-void-me', 'inv-paid', 'inv-same-id', 'cust-1'] as $index => $uuid) {
            DB::table('quickbooks_pending_syncs')->insert([
                'uuid'       => 'pend-' . $index, 'company_uuid' => 'company-uuid', 'local_type' => $uuid === 'cust-1' ? 'customer' : 'invoice',
                'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        (new FleetbaseDirectory())->releaseRemoteDeletes('company-uuid', [
            ['realm_id' => 'realm-1', 'local_type' => 'invoice', 'qbo_id' => '8', 'local_uuid' => 'inv-void-me', 'invoice_uuids' => []],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '4', 'local_uuid' => '4', 'invoice_uuids' => ['inv-paid']],
        ]);

        $queries        = array_column($connection->getQueryLog(), 'query');
        $linkSelects    = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'quickbooks_links') && str_starts_with(strtolower(ltrim($sql)), 'select')));
        $invoiceUpdates = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'ledger_invoices') && str_starts_with(strtolower(ltrim($sql)), 'update')));
        $invoiceSelects = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'ledger_invoices') && str_starts_with(strtolower(ltrim($sql)), 'select')));
        $linkDeletes    = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'quickbooks_links') && str_starts_with(strtolower(ltrim($sql)), 'delete')));
        $pendingUpdates = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'quickbooks_pending_syncs') && str_starts_with(strtolower(ltrim($sql)), 'update')));
        $voided         = DB::table('ledger_invoices')->where('uuid', 'inv-void-me')->first();
        $paid           = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $same           = DB::table('ledger_invoices')->where('uuid', 'inv-same-id')->first();

        expect($linkSelects)->toHaveCount(1)
            ->and($invoiceUpdates)->toHaveCount(1)
            ->and($invoiceSelects)->toBe([])
            ->and($linkDeletes)->toHaveCount(1)
            ->and($pendingUpdates)->toHaveCount(1)
            ->and((string) $voided->status)->toBe('void')
            ->and((int) $voided->amount_paid)->toBe(0)
            ->and((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((string) $same->status)->toBe('paid')
            ->and((int) $same->amount_paid)->toBe(800)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-inv')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-same')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-cust')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-void-me')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-same-id')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'cust-1')->value('status'))->toBe('pending');
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('reconcile pending inserts use one bulk insert', function () {
    [$restore] = directorySqlite();
    try {
        $schema = DB::connection('sqlite')->getSchemaBuilder();
        directoryWebhookSchema($schema);
        directoryWebhookRows();
        DB::table('ledger_invoices')->insert([
            'uuid'         => 'inv-b',
            'company_uuid' => 'company-uuid',
            'number'       => 'inv-b',
            'status'       => 'sent',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $cursor = (new FleetbaseDirectory())->claimInScopeInvoices('company-uuid', 10);
        $writes = array_values(array_filter(
            array_column($connection->getQueryLog(), 'query'),
            static fn (string $sql): bool => str_contains($sql, 'quickbooks_pending_syncs')
        ));
        $inserts = array_values(array_filter(
            $writes,
            static fn (string $sql): bool => str_contains(strtolower($sql), 'insert')
        ));

        expect($cursor)->not->toBe('')
            ->and($inserts)->toHaveCount(1)
            ->and($writes)->toHaveCount(1)
            ->and(PendingSync::query()->where('local_type', 'invoice')->where('reason', 'reconcile')->count())->toBeGreaterThan(1);
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

test('queueInScope pages the catalog and inserts each page', function () {
    [$restore] = directorySqlite();
    try {
        directoryWebhookSchema(DB::connection('sqlite')->getSchemaBuilder());
        $now = now();
        foreach (['cust-a', 'cust-b', 'cust-c'] as $uuid) {
            DB::table('contacts')->insert([
                'uuid'         => $uuid,
                'company_uuid' => 'company-uuid',
                'name'         => $uuid,
                'type'         => 'customer',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
        DB::table('contacts')->insert([
            'uuid'         => 'vend-a',
            'company_uuid' => 'company-uuid',
            'name'         => 'Vendor',
            'type'         => 'vendor',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DB::table('ledger_invoices')->insert([
            [
                'uuid'         => 'inv-draft',
                'company_uuid' => 'company-uuid',
                'number'       => 'draft',
                'status'       => 'draft',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'uuid'         => 'inv-sent',
                'company_uuid' => 'company-uuid',
                'number'       => 'sent',
                'status'       => 'sent',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ]);
        DB::table('quickbooks_links')->insert([
            'uuid'         => 'link-only',
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'local_type'   => 'invoice',
            'local_uuid'   => 'inv-linked',
            'qbo_entity'   => 'Invoice',
            'qbo_id'       => '50',
            'sync_token'   => '0',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $directory = new class extends FleetbaseDirectory {
            protected function catalogPageSize(): int
            {
                return 2;
            }
        };
        $connection = DB::connection('sqlite');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $directory->queueInScope('company-uuid', ['customer' => true, 'invoice' => true]);
        $queries  = array_column($connection->getQueryLog(), 'query');
        $customer = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'contacts') && str_contains(strtolower($sql), 'limit')
        ));
        $queued = PendingSync::query()->orderBy('local_type')->orderBy('local_uuid')->get();
        $ids    = $queued->map(static fn ($row): string => $row->local_type . ':' . $row->local_uuid)->all();

        expect($customer)->toHaveCount(2)
            ->and(strtolower($customer[1]))->toContain('uuid')
            ->and($ids)->toEqualCanonicalizing([
                'customer:cust-a',
                'customer:cust-b',
                'customer:cust-c',
                'invoice:inv-sent',
                'invoice:inv-linked',
            ]);
    } finally {
        $restore();
    }
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

function directorySqlite(): array
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

    return [function () use ($defaultConnection, $sqliteConnection, $fleetbaseConnection): void {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbaseConnection);
    }];
}

function directoryWithConnection(): FleetbaseDirectory
{
    return new class extends FleetbaseDirectory {
        /** @var array<string, mixed>|null */
        public ?array $storedConnection = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1'];

        /** @var array<int, array<string, mixed>> */
        public array $written = [];

        public function connection(string $companyUuid): ?array
        {
            return $this->storedConnection;
        }

        public function save(SyncLedger $ledger): void
        {
            throw new RuntimeException('flag() must not save the ledger');
        }

        protected function writePending(array $row): void
        {
            $this->written[] = $row;
        }
    };
}

function directoryQueryException(string $sqlState, int $driverCode, string $message): QueryException
{
    $previous            = new PDOException($message);
    $previous->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('testing', 'insert into test values (?)', [], $previous);
}

function directoryDuplicatePendingWrite(QueryException $exception): bool
{
    $method = new ReflectionMethod(FleetbaseDirectory::class, 'isDuplicatePendingWrite');

    return $method->invoke(null, $exception);
}

function directoryWebhookLedger(): SyncLedger
{
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
    ];
    $ledger->customers = [
        'cust-1'     => ['uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Linked'],
        'cust-9'     => ['uuid' => 'cust-9', 'company_uuid' => 'company-uuid', 'name' => 'Invoice customer'],
        'cust-extra' => ['uuid' => 'cust-extra', 'company_uuid' => 'company-uuid', 'name' => 'Catalog'],
        'cust-b'     => ['uuid' => 'cust-b', 'company_uuid' => 'company-b', 'name' => 'Other company'],
        'cust-realm' => ['uuid' => 'cust-realm', 'company_uuid' => 'company-uuid', 'name' => 'Other realm'],
    ];
    $ledger->invoices = [
        'inv-3'     => ['uuid' => 'inv-3', 'company_uuid' => 'company-uuid', 'customer_uuid' => 'cust-1', 'status' => 'sent'],
        'inv-9'     => ['uuid' => 'inv-9', 'company_uuid' => 'company-uuid', 'customer_uuid' => 'cust-9', 'status' => 'sent'],
        'inv-extra' => ['uuid' => 'inv-extra', 'company_uuid' => 'company-uuid', 'customer_uuid' => 'cust-extra', 'status' => 'sent'],
    ];
    $ledger->wallets = [
        'wal-5'     => ['uuid' => 'wal-5', 'company_uuid' => 'company-uuid', 'name' => 'Operating'],
        'wal-extra' => ['uuid' => 'wal-extra', 'company_uuid' => 'company-uuid', 'name' => 'Other'],
    ];
    $ledger->links = [
        directoryWebhookLink('company-uuid', 'realm-1', 'customer', 'cust-1', 'Customer', '1'),
        directoryWebhookLink('company-uuid', 'realm-1', 'invoice', 'inv-3', 'Invoice', '3'),
        directoryWebhookLink('company-uuid', 'realm-1', 'payment', 'inv-9', 'Payment', '4'),
        directoryWebhookLink('company-uuid', 'realm-1', 'invoice', 'inv-9', 'Invoice', '8'),
        directoryWebhookLink('company-uuid', 'realm-1', 'customer', 'cust-9', 'Customer', '6'),
        directoryWebhookLink('company-uuid', 'realm-1', 'wallet', 'wal-5', 'Account', '5'),
        directoryWebhookLink('company-uuid', 'realm-1', 'customer', 'cust-extra', 'Customer', '99'),
        directoryWebhookLink('company-uuid', 'realm-1', 'invoice', 'inv-extra', 'Invoice', '99'),
        directoryWebhookLink('company-uuid', 'realm-1', 'wallet', 'wal-extra', 'Account', '99'),
        directoryWebhookLink('company-b', 'realm-1', 'customer', 'cust-b', 'Customer', '1'),
        directoryWebhookLink('company-uuid', 'realm-2', 'customer', 'cust-realm', 'Customer', '1'),
    ];
    $ledger->pending = [[
        'uuid'         => 'pend-1',
        'company_uuid' => 'company-uuid',
        'local_type'   => 'invoice',
        'local_uuid'   => 'inv-extra',
        'status'       => 'pending',
    ]];

    return $ledger;
}

/**
 * @return array<string, string>
 */
function directoryWebhookLink(string $companyUuid, string $realmId, string $localType, string $localUuid, string $entity, string $qboId): array
{
    return [
        'company_uuid' => $companyUuid,
        'realm_id'     => $realmId,
        'local_type'   => $localType,
        'local_uuid'   => $localUuid,
        'qbo_entity'   => $entity,
        'qbo_id'       => $qboId,
        'sync_token'   => '0',
    ];
}

/**
 * @return array<int, array{entity: string, id: string, operation: string}>
 */
function directoryWebhookEntities(): array
{
    return [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
        ['entity' => 'Invoice', 'id' => '3', 'operation' => 'Update'],
        ['entity' => 'Payment', 'id' => '4', 'operation' => 'Create'],
        ['entity' => 'Account', 'id' => '5', 'operation' => 'Update'],
        ['entity' => 'Vendor', 'id' => '2', 'operation' => 'Create'],
        ['entity' => 'Customer', 'id' => '404', 'operation' => 'Update'],
    ];
}

function directoryWebhookSchema(Illuminate\Database\Schema\Builder $schema): void
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
        $table->char('company_uuid', 36)->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes();
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
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    foreach (['templates', 'orders', 'tracking_numbers'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->softDeletes();
        });
    }
}

function directoryWebhookRows(): void
{
    $now      = now();
    $customer = 'Fleetbase\\FleetOps\\Models\\Customer';
    DB::table('quickbooks_connections')->insert([
        'uuid'         => 'conn-1',
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'environment'  => 'sandbox',
        'needs_reauth' => 0,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $contacts = [
        ['uuid' => 'cust-9', 'name' => 'Invoice customer'],
        ['uuid' => 'cust-void', 'name' => 'Void customer'],
        ['uuid' => 'cust-extra', 'name' => 'Catalog'],
    ];
    foreach ($contacts as $contact) {
        DB::table('contacts')->insert([
            'uuid'         => $contact['uuid'],
            'company_uuid' => 'company-uuid',
            'name'         => $contact['name'],
            'type'         => 'customer',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
    $invoices = [
        ['uuid' => 'inv-9', 'customer_uuid' => 'cust-9', 'deleted_at' => null],
        ['uuid' => 'inv-void', 'customer_uuid' => 'cust-void', 'deleted_at' => $now],
        ['uuid' => 'inv-extra', 'customer_uuid' => 'cust-extra', 'deleted_at' => null],
    ];
    foreach ($invoices as $invoice) {
        DB::table('ledger_invoices')->insert([
            'uuid'          => $invoice['uuid'],
            'company_uuid'  => 'company-uuid',
            'customer_uuid' => $invoice['customer_uuid'],
            'customer_type' => $customer,
            'number'        => $invoice['uuid'],
            'status'        => 'sent',
            'currency'      => 'USD',
            'tax'           => 0,
            'total_amount'  => 1000,
            'amount_paid'   => 0,
            'deleted_at'    => $invoice['deleted_at'],
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
    foreach (['wal-5' => 'Operating', 'wal-extra' => 'Other'] as $uuid => $name) {
        DB::table('ledger_wallets')->insert([
            'uuid'         => $uuid,
            'public_id'    => $uuid,
            'company_uuid' => 'company-uuid',
            'name'         => $name,
            'currency'     => 'USD',
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
    $links = [
        directoryWebhookLink('company-uuid', 'realm-1', 'payment', 'inv-9', 'Payment', '4'),
        directoryWebhookLink('company-uuid', 'realm-1', 'invoice', 'inv-9', 'Invoice', '8'),
        directoryWebhookLink('company-uuid', 'realm-1', 'customer', 'cust-9', 'Customer', '6'),
        directoryWebhookLink('company-uuid', 'realm-1', 'invoice', 'inv-void', 'Invoice', '11'),
        directoryWebhookLink('company-uuid', 'realm-1', 'customer', 'cust-void', 'Customer', '12'),
        directoryWebhookLink('company-uuid', 'realm-1', 'wallet', 'wal-5', 'Account', '5'),
        directoryWebhookLink('company-uuid', 'realm-1', 'customer', 'cust-extra', 'Customer', '99'),
        directoryWebhookLink('company-uuid', 'realm-1', 'invoice', 'inv-extra', 'Invoice', '99'),
        directoryWebhookLink('company-uuid', 'realm-1', 'wallet', 'wal-extra', 'Account', '99'),
        directoryWebhookLink('company-b', 'realm-1', 'customer', 'cust-b', 'Customer', '4'),
        directoryWebhookLink('company-uuid', 'realm-2', 'customer', 'cust-realm', 'Customer', '4'),
    ];
    foreach ($links as $index => $link) {
        DB::table('quickbooks_links')->insert($link + [
            'uuid'       => 'link-' . $index,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    DB::table('quickbooks_pending_syncs')->insert([
        'uuid'         => 'pend-1',
        'company_uuid' => 'company-uuid',
        'local_type'   => 'invoice',
        'local_uuid'   => 'inv-extra',
        'status'       => 'pending',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
}
