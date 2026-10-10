<?php

use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->previousCache     = Cache::getFacadeRoot();
    $this->lockRepository    = new Repository(new ArrayStore());
    $container               = Container::getInstance();
    $this->previousCacheBind = $container->bound(CacheRepository::class) === true ? $container->make(CacheRepository::class) : null;
    Cache::swap($this->lockRepository);
    $container->instance(CacheRepository::class, $this->lockRepository);
    $container->instance('cache', $this->lockRepository);
});

afterEach(function () {
    Cache::swap($this->previousCache);
    $container = Container::getInstance();
    if ($this->previousCacheBind !== null) {
        $container->instance(CacheRepository::class, $this->previousCacheBind);
    } else {
        $container->forgetInstance(CacheRepository::class);
    }
});

/**
 * A FakeQuickBooks that starts another sync for the same company just before it
 * records the first create of $on. The company lock is already released at that
 * point, the way it is while a real QuickBooks request is in flight.
 */
class ClaimProbeQuickBooks extends FakeQuickBooks
{
    public ?Closure $during = null;

    public string $on = 'createCustomer';

    public function interleave(string $method): void
    {
        $hook = $this->during;
        if ($hook === null || $method !== $this->on) {
            return;
        }
        $this->during = null;
        $hook();
    }

    public function createCustomer(array $connection, array $payload): array
    {
        $this->interleave('createCustomer');

        return parent::createCustomer($connection, $payload);
    }

    public function createInvoice(array $connection, array $payload): array
    {
        $this->interleave('createInvoice');

        return parent::createInvoice($connection, $payload);
    }
}

function claimSqlite(): array
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
    // The models generate their uuid in a creating hook, which needs an event dispatcher.
    $dispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(new Dispatcher());
    Model::clearBootedModels();

    return [function () use ($defaultConnection, $sqliteConnection, $fleetbaseConnection, $dispatcher): void {
        if ($dispatcher !== null) {
            Model::setEventDispatcher($dispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Model::clearBootedModels();
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbaseConnection);
    }];
}

function claimSchema(bool $withClaims = true): void
{
    $schema = DB::connection('sqlite')->getSchemaBuilder();
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
        $table->unique(['company_uuid', 'local_type', 'local_uuid'], 'quickbooks_links_local_unique');
    });
    $schema->create('quickbooks_pending_syncs', function (Blueprint $table) use ($withClaims) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->string('reason')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('next_attempt_at')->nullable();
        if ($withClaims === true) {
            $table->timestamp('claimed_until')->nullable();
            $table->string('claimed_by', 64)->nullable();
        }
        $table->timestamps();
    });
    $schema->create('quickbooks_sync_batches', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('trigger');
        $table->string('direction')->default('outbound');
        $table->string('status');
        $table->unsignedInteger('created_count')->default(0);
        $table->unsignedInteger('updated_count')->default(0);
        $table->unsignedInteger('aligned_count')->default(0);
        $table->unsignedInteger('voided_count')->default(0);
        $table->unsignedInteger('unmatched_count')->default(0);
        $table->unsignedInteger('failed_count')->default(0);
        $table->unsignedInteger('linked_count')->default(0);
        $table->unsignedInteger('skipped_count')->default(0);
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

function claimRows(string $company = 'company-1'): void
{
    $now = now();
    DB::table('quickbooks_connections')->insert([
        'uuid'             => 'conn-' . $company,
        'company_uuid'     => $company,
        'realm_id'         => 'realm-1',
        'access_token'     => 'access',
        'refresh_token'    => 'refresh',
        'token_expires_at' => $now->copy()->addDay(),
        'environment'      => 'sandbox',
        'default_item_id'  => 'item-1',
        'needs_reauth'     => 0,
        'home_currency'    => 'USD',
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);
    foreach (['cust-1' => 'Alpha Ltd', 'cust-2' => 'Beta Ltd'] as $uuid => $name) {
        DB::table('contacts')->insert([
            'uuid'         => $uuid,
            'company_uuid' => $company,
            'name'         => $name,
            'email'        => $uuid . '@example.test',
            'type'         => 'customer',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DB::table('quickbooks_pending_syncs')->insert([
            'uuid'         => 'pend-' . $uuid,
            'company_uuid' => $company,
            'local_type'   => 'customer',
            'local_uuid'   => $uuid,
            'status'       => 'pending',
            'attempts'     => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
    DB::table('ledger_invoices')->insert([
        'uuid'          => 'inv-1',
        'company_uuid'  => $company,
        'customer_uuid' => 'cust-1',
        'customer_type' => 'Fleetbase\\FleetOps\\Models\\Customer',
        'number'        => 'INV-1',
        'date'          => '2026-10-01',
        'due_date'      => '2026-10-15',
        'status'        => 'sent',
        'currency'      => 'USD',
        'tax'           => 0,
        'total_amount'  => 1000,
        'amount_paid'   => 0,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
    DB::table('ledger_invoice_items')->insert([
        'uuid'         => 'line-1',
        'invoice_uuid' => 'inv-1',
        'description'  => 'Delivery',
        'quantity'     => 1,
        'unit_price'   => 1000,
        'amount'       => 1000,
    ]);
    DB::table('quickbooks_pending_syncs')->insert([
        'uuid'         => 'pend-inv-1',
        'company_uuid' => $company,
        'local_type'   => 'invoice',
        'local_uuid'   => 'inv-1',
        'status'       => 'pending',
        'attempts'     => 0,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
}

/**
 * @return array{0: BatchRunner, 1: FleetbaseDirectory}
 */
function claimRunner(FakeQuickBooks $client): array
{
    $directory                              = new FleetbaseDirectory();
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = ['batch_size' => 50];
    $settings                               = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $tokens                                 = new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine                                 = new SyncEngine($client, new CustomerMapper(), new InvoiceMapper(), new WalletMapper(), new BackoffPolicy(static fn (int $wait): int => $wait));

    return [new BatchRunner($engine, $directory, $settings, $store, $tokens), $directory];
}

foreach (['createCustomer' => 'customers', 'createInvoice' => 'the invoice'] as $claimProbeMethod => $claimProbeLabel) {
    test('a second sync that starts while the first is creating ' . $claimProbeLabel . ' in quickbooks does not send the same create again', function () use ($claimProbeMethod) {
        [$restore] = claimSqlite();
        try {
            claimSchema();
            claimRows();
            $client         = new ClaimProbeQuickBooks();
            $client->on     = $claimProbeMethod;
            [$first]        = claimRunner($client);
            [$second]       = claimRunner($client);
            $secondResult   = null;
            $client->during = function () use ($second, &$secondResult): void {
                $secondResult = $second->run('company-1', 'now');
            };

            $batch = $first->run('company-1', 'now');

            expect($batch['status'])->toBe('finished')
                ->and($secondResult)->toBeArray()
                ->and($client->creates)->toBe(2)
                ->and($client->invoiceCreates)->toBe(1)
                ->and(DB::table('quickbooks_links')->where('local_type', 'customer')->count())->toBe(2)
                ->and(DB::table('quickbooks_links')->where('local_type', 'invoice')->count())->toBe(1)
                ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->count())->toBe(0)
                ->and(DB::table('quickbooks_pending_syncs')->whereNotNull('claimed_until')->count())->toBe(0)
                ->and(BatchRunner::holds('company-1'))->toBeFalse();
        } finally {
            $restore();
        }
    })->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
}

test('a pending row claimed by a running sync is skipped until the claim expires and then runs again', function () {
    [$restore] = claimSqlite();
    try {
        claimSchema();
        claimRows();
        $future = now()->addMinutes(10);
        $past   = now()->subMinute();
        DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-1')->update(['claimed_until' => $future, 'claimed_by' => 'other-run']);
        DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-2')->update(['claimed_until' => $past, 'claimed_by' => 'crashed-run']);

        $directory = new FleetbaseDirectory();
        $dueBefore = $directory->countDuePending('company-1', time());
        $loaded    = $directory->loadPending('company-1', 50, time());
        $uuids     = array_column($loaded['ledger']->pending, 'uuid');
        sort($uuids);

        expect($uuids)->toBe(['pend-cust-2', 'pend-inv-1'])
            ->and($dueBefore)->toBe(2)
            ->and($directory->countDuePending('company-1', time()))->toBe(0)
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-1')->value('claimed_by'))->toBe('other-run')
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-2')->value('claimed_by'))->not->toBe('crashed-run')
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-inv-1')->value('claimed_until'))->not->toBeNull();

        $directory->releaseClaims();

        expect(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-1')->value('claimed_by'))->toBe('other-run')
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-2')->value('claimed_by'))->toBeNull()
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-inv-1')->value('claimed_until'))->toBeNull();
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a claim lasts the configured number of seconds and rows stay pending while claimed', function () {
    [$restore] = claimSqlite();
    $previous  = config('quickbooks.sync.claim_seconds');
    try {
        claimSchema();
        claimRows();
        config()->set('quickbooks.sync.claim_seconds', 120);
        $before = time();
        (new FleetbaseDirectory())->loadPending('company-1', 50, $before);
        $until = strtotime((string) DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-inv-1')->value('claimed_until') . ' UTC');

        expect(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-inv-1')->value('status'))->toBe('pending')
            ->and($until)->toBeGreaterThan($before + 100)
            ->and($until)->toBeLessThanOrEqual($before + 125);
    } finally {
        config()->set('quickbooks.sync.claim_seconds', $previous);
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a sync still loads pending rows when the claim columns are not migrated yet', function () {
    [$restore] = claimSqlite();
    try {
        claimSchema(false);
        claimRows();
        $directory = new FleetbaseDirectory();
        $loaded    = $directory->loadPending('company-1', 50, time());
        $directory->releaseClaims();

        expect($loaded['ledger']->pending)->toHaveCount(3)
            ->and($directory->countDuePending('company-1', time()))->toBe(3);
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('the customer catalog leaves a customer to the run that holds its pending row', function () {
    [$restore] = claimSqlite();
    try {
        claimSchema();
        claimRows();
        DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-1')->update(['claimed_until' => now()->addMinutes(10), 'claimed_by' => 'other-run']);
        $client   = new ClaimProbeQuickBooks();
        [$runner] = claimRunner($client);

        $batch = $runner->run('company-1', 'catalog');

        $linked = DB::table('quickbooks_links')->where('local_type', 'customer')->pluck('local_uuid')->all();
        expect($batch['status'])->toBe('finished')
            ->and($client->creates)->toBe(1)
            ->and($linked)->toBe(['cust-2'])
            ->and(DB::table('quickbooks_pending_syncs')->where('uuid', 'pend-cust-1')->value('claimed_by'))->toBe('other-run');
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a second customer catalog run for the same company waits while the first page is in flight', function () {
    [$restore] = claimSqlite();
    try {
        claimSchema();
        claimRows();
        $client         = new ClaimProbeQuickBooks();
        [$first]        = claimRunner($client);
        [$second]       = claimRunner($client);
        $secondResult   = null;
        $client->during = function () use ($second, &$secondResult): void {
            $secondResult = $second->run('company-1', 'catalog');
        };

        $batch = $first->run('company-1', 'catalog');

        expect($batch['status'])->toBe('finished')
            ->and($secondResult['status'])->toBe('skipped')
            ->and($secondResult['reason'])->toBe('busy')
            ->and($client->creates)->toBe(2)
            ->and(Cache::get('quickbooks.customer-catalog-lease.company-1'))->toBeNull();
    } finally {
        $restore();
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
