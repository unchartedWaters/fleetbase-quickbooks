<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Jobs\ResolveWebhookPayments;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Characterization tests for how a webhook delivery finds the Fleetbase invoices its
 * payments apply to, driven only through EnqueueWebhookSync and ResolveWebhookPayments.
 */

/**
 * A directory backed by an in-memory ledger whose remote deletes are recorded, not applied.
 */
class WplDirectory extends FleetbaseDirectory
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $released = [];

    /** @var array<int, string> */
    public array $skipped = [];

    public function releaseRemoteDeletes(string $companyUuid, array $deletions): void
    {
        $this->released[] = $deletions;
    }

    public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
    {
        $this->skipped[] = $message;
    }
}

/**
 * FakeQuickBooks whose batch query can fail, and that rejects the old access token with a 401.
 */
class WplClient extends FakeQuickBooks
{
    public string $batchFailure = '';

    public bool $rejectOldToken = false;

    public int $refreshes = 0;

    /** @var array<int, string> */
    public array $batchTokens = [];

    /** @var array<int, string> */
    public array $queries = [];

    public function refresh(array $credentials, string $refreshToken): array
    {
        $this->refreshes++;

        return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600];
    }

    public function batch(array $connection, array $items): array
    {
        $token               = (string) ($connection['access_token'] ?? '');
        $this->batchTokens[] = $token;
        foreach ($items as $item) {
            $this->queries[] = (string) ($item['query'] ?? '');
        }
        if ($this->rejectOldToken === true && $token === 'old-access') {
            $this->calls[] = 'batch';
            throw new QuickBooksException(401, 'QuickBooks request failed with status 401');
        }
        if ($this->batchFailure === 'throw') {
            $this->calls[] = 'batch';
            throw new RuntimeException('batch read failed');
        }
        if ($this->batchFailure === 'fault') {
            $this->calls[] = 'batch';
            $results       = [];
            foreach ($items as $item) {
                $results[(string) $item['bId']] = ['ok' => false, 'body' => [], 'rows' => [], 'error' => 'fault', 'status' => 400, 'halt' => false];
            }

            return $results;
        }

        return parent::batch($connection, $items);
    }
}

function wplSettings(): SettingsService
{
    return new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
}

/**
 * @param array<string, mixed> $overrides
 */
function wplStore(array $overrides = []): MemorySettingsStore
{
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = array_merge([
        'customer_direction' => 'both',
        'customer_conflict'  => 'quickbooks',
        'invoice_direction'  => 'both',
        'invoice_conflict'   => 'quickbooks',
        'payment_direction'  => 'both',
        'payment_conflict'   => 'quickbooks',
    ], $overrides);

    return $store;
}

/**
 * @return array<string, mixed>
 */
function wplLink(string $uuid, string $localType, string $localUuid, string $qboEntity, string $qboId, string $realm = 'realm-1'): array
{
    $now = now();

    return [
        'uuid'         => $uuid,
        'company_uuid' => 'company-a',
        'realm_id'     => $realm,
        'local_type'   => $localType,
        'local_uuid'   => $localUuid,
        'qbo_entity'   => $qboEntity,
        'qbo_id'       => $qboId,
        'sync_token'   => '0',
        'created_at'   => $now,
        'updated_at'   => $now,
    ];
}

/**
 * Runs the callback against an in-memory links and ledger invoice schema, then restores config.
 *
 * @param array<int, array<string, mixed>> $links
 * @param array<int, string>               $invoiceUuids ledger invoices on file for company-a
 */
function wplWithTables(array $links, array $invoiceUuids, callable $callback): mixed
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
            $table->integer('total_amount')->default(0);
            $table->integer('amount_paid')->default(0);
            $table->integer('tax')->default(0);
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $now = now();
        foreach ($invoiceUuids as $uuid) {
            DB::table('ledger_invoices')->insert(['uuid' => $uuid, 'company_uuid' => 'company-a', 'total_amount' => 1000, 'amount_paid' => 0, 'tax' => 0, 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($links !== []) {
            DB::table('quickbooks_links')->insert($links);
        }

        return $callback();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
}

/**
 * Binds the client, directory and token service, swaps in a recording dispatcher and an array
 * cache, runs the callback with the dispatcher, then restores every binding.
 */
function wplWithServices(QuickBooksClient $client, FleetbaseDirectory $directory, ?ConnectionTokens $tokens, callable $callback): mixed
{
    $dispatcher = new class implements Dispatcher {
        /** @var array<int, mixed> */
        public array $jobs = [];

        public function dispatch($command)
        {
            $this->jobs[] = $command;

            return $command;
        }

        public function dispatchSync($command, $handler = null)
        {
            return $command;
        }

        public function dispatchNow($command, $handler = null)
        {
            return $command;
        }

        public function hasCommandHandler($command)
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return false;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    };

    $container  = Container::getInstance();
    $previous   = [];
    $bindings   = [
        Dispatcher::class         => $dispatcher,
        QuickBooksClient::class   => $client,
        FleetbaseDirectory::class => $directory,
        ConnectionTokens::class   => $tokens,
    ];
    foreach ($bindings as $abstract => $instance) {
        $previous[$abstract] = $container->bound($abstract) === true ? $container->make($abstract) : null;
        if ($instance !== null) {
            $container->instance($abstract, $instance);
        }
    }
    $cache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));

    try {
        return $callback($dispatcher);
    } finally {
        Cache::swap($cache);
        foreach ($previous as $abstract => $instance) {
            if ($instance !== null) {
                $container->instance($abstract, $instance);
            } else {
                $container->forgetInstance($abstract);
            }
        }
    }
}

/**
 * @return array<string, mixed>
 */
function wplConnection(string $realm = 'realm-1'): array
{
    return [
        'company_uuid'     => 'company-a',
        'realm_id'         => $realm,
        'access_token'     => 'old-access',
        'refresh_token'    => 'old-refresh',
        'token_expires_at' => time() + 3600,
        'needs_reauth'     => false,
        'last_batch_at'    => null,
    ];
}

function wplDirectory(string $realm = 'realm-1'): WplDirectory
{
    $directory                                   = new WplDirectory();
    $directory->memory                           = new SyncLedger();
    $directory->memory->connections['company-a'] = wplConnection($realm);

    return $directory;
}

function wplTokens(QuickBooksClient $client, FleetbaseDirectory $directory): ConnectionTokens
{
    return new ConnectionTokens(new TokenRefresher($client), wplSettings(), new MemorySettingsStore(), $directory);
}

/**
 * The webhook request: queue the events and flush them, without reading QuickBooks.
 *
 * @param array<int, QuickBooksEntityChanged> $events
 */
function wplRequest(array $events, MemorySettingsStore $store): void
{
    $listener = new EnqueueWebhookSync(wplSettings());
    foreach ($events as $event) {
        $listener->handle($event);
    }
    $listener->flush($store);
}

/**
 * The queued job that resolves payments.
 *
 * @param array<int, array<string, mixed>> $events
 */
function wplRunJob(array $events, MemorySettingsStore $store, FleetbaseDirectory $directory, ConnectionTokens $tokens): void
{
    (new ResolveWebhookPayments('company-a', $events))->handle(wplSettings(), $store, $directory, $tokens);
}

/**
 * @return array<string, mixed>
 */
function wplPayload(string $id, string $operation = 'update', ?string $localUuid = null, string $realm = 'realm-1'): array
{
    return ['realm_id' => $realm, 'entity_type' => 'payment', 'id' => $id, 'operation' => $operation, 'local_uuid' => func_num_args() >= 3 ? $localUuid : $id];
}

/**
 * @param array<int, string> $invoiceIds
 *
 * @return array<string, mixed>
 */
function wplPayment(string $id, array $invoiceIds): array
{
    $lines = [];
    foreach ($invoiceIds as $invoiceId) {
        $lines[] = ['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => $invoiceId]]];
    }

    return ['Id' => $id, 'Line' => $lines];
}

/**
 * Each dispatched job as [kind, what it carries], in dispatch order.
 *
 * @param array<int, mixed> $jobs
 *
 * @return array<int, array{0: string, 1: mixed}>
 */
function wplJobs(array $jobs): array
{
    $summary = [];
    foreach ($jobs as $job) {
        $summary[] = match (true) {
            $job instanceof ResolveWebhookPayments => ['resolve', $job->events],
            $job instanceof ApplyRemoteChange      => ['apply', $job->entities],
            $job instanceof SyncWebhookBatch       => ['sync', $job->records],
            default                                => [is_object($job) === true ? $job::class : gettype($job), null],
        };
    }

    return $summary;
}

$wplNoSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true) === false;

test('a payment linked straight to invoices queues every linked or on-file invoice without reading quickbooks', function () {
    $client    = new WplClient();
    $directory = wplDirectory();
    $links     = [
        wplLink('pay-a', 'payment', 'inv-a', 'Payment', '4'),
        wplLink('pay-b', 'payment', 'inv-b', 'Payment', '4'),
        wplLink('pay-c', 'payment', 'inv-c', 'Payment', '4'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
    ];

    $jobs = wplWithTables($links, ['inv-b'], fn () => wplWithServices($client, $directory, null, function ($dispatcher) {
        wplRequest([new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'update', null)], wplStore());

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe([])
        ->and($directory->released)->toBe([])
        ->and(wplJobs($jobs))->toBe([
            ['apply', [['entity' => 'Payment', 'id' => '4', 'operation' => 'update']]],
            ['sync', [
                ['local_type' => 'invoice', 'local_uuid' => 'inv-a'],
                ['local_type' => 'invoice', 'local_uuid' => 'inv-b'],
            ]],
        ]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('the request resolves direct payments, defers keyed ones in order, and ignores unlinked payments', function () {
    $client    = new WplClient();
    $directory = wplDirectory();
    $links     = [
        wplLink('pay-4', 'payment', 'inv-a', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('pay-7', 'payment', '7', 'Payment', '7', 'realm-2'),
        wplLink('pay-8', 'payment', '8', 'Payment', '8'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, null, function ($dispatcher) {
        wplRequest([
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '8', 'delete', '8'),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'update', null),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'create', null),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '6', 'create', null),
            new QuickBooksEntityChanged('company-a', 'realm-2', 'payment', '7', 'update', 'pay-local-7'),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'update', null),
        ], wplStore());

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe([])
        ->and($directory->released)->toBe([[]])
        ->and(wplJobs($jobs))->toBe([
            ['resolve', [
                wplPayload('5', 'create', null),
                wplPayload('7', 'update', 'pay-local-7', 'realm-2'),
                wplPayload('5', 'update', null),
                wplPayload('8', 'delete', '8'),
            ]],
            ['apply', [['entity' => 'Payment', 'id' => '4', 'operation' => 'update']]],
            ['sync', [['local_type' => 'invoice', 'local_uuid' => 'inv-a']]],
        ]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('a keyed payment is not deferred when payments do not take quickbooks changes', function (array $overrides) {
    $client    = new WplClient();
    $directory = wplDirectory();
    $links     = [wplLink('pay-5', 'payment', '5', 'Payment', '5')];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, null, function ($dispatcher) use ($overrides) {
        wplRequest([
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'update', null),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'delete', '5'),
        ], wplStore($overrides));

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe([])
        ->and($directory->released)->toBe([])
        ->and($jobs)->toBe([]);
})->with([
    'outbound only' => [['payment_direction' => 'outbound']],
    'switched off'  => [['payment_enabled' => false]],
])->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('fleetbase primary still queues the invoices of a direct payment but sends no inbound change', function () {
    $client    = new WplClient();
    $directory = wplDirectory();
    $links     = [
        wplLink('pay-4', 'payment', 'inv-a', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, null, function ($dispatcher) {
        wplRequest([
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'update', null),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'update', null),
        ], wplStore(['payment_conflict' => 'fleetbase']));

        return $dispatcher->jobs;
    }));

    expect(wplJobs($jobs))->toBe([
        ['resolve', [wplPayload('5', 'update', null)]],
        ['sync', [['local_type' => 'invoice', 'local_uuid' => 'inv-a']]],
    ]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('the job reads several keyed payments in one batch query and queues every invoice they paid', function () {
    $client                = new WplClient();
    $client->payments['4'] = wplPayment('4', ['10', '11']);
    $client->payments['5'] = wplPayment('5', ['12', '99']);
    $directory             = wplDirectory();
    $tokens                = wplTokens($client, $directory);
    $links                 = [
        wplLink('pay-4', 'payment', '4', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
        wplLink('link-inv-b', 'invoice', 'inv-b', 'Invoice', '11'),
        wplLink('link-inv-c', 'invoice', 'inv-c', 'Invoice', '12'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
        wplRunJob([wplPayload('4'), wplPayload('5', 'create'), wplPayload('4')], wplStore(), $directory, $tokens);

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe(['batch'])
        ->and($client->queries)->toBe(["select * from Payment where Id IN ('4', '5')"])
        ->and($client->refreshes)->toBe(0)
        ->and(wplJobs($jobs))->toBe([
            ['apply', [
                ['entity' => 'Payment', 'id' => '4', 'operation' => 'update'],
                ['entity' => 'Invoice', 'id' => '10', 'operation' => 'update'],
                ['entity' => 'Invoice', 'id' => '11', 'operation' => 'update'],
                ['entity' => 'Payment', 'id' => '5', 'operation' => 'create'],
                ['entity' => 'Invoice', 'id' => '12', 'operation' => 'update'],
                ['entity' => 'Invoice', 'id' => '99', 'operation' => 'update'],
            ]],
            ['sync', [
                ['local_type' => 'invoice', 'local_uuid' => 'inv-a'],
                ['local_type' => 'invoice', 'local_uuid' => 'inv-b'],
                ['local_type' => 'invoice', 'local_uuid' => 'inv-c'],
            ]],
        ]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('the job reads one keyed payment on its own and skips a payment quickbooks no longer has', function () {
    $client                = new WplClient();
    $client->payments['4'] = wplPayment('4', ['10']);
    $directory             = wplDirectory();
    $tokens                = wplTokens($client, $directory);
    $links                 = [
        wplLink('pay-4', 'payment', '4', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
    ];

    [$single, $batched] = wplWithTables($links, [], function () use ($client, $directory, $tokens) {
        $single = wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
            wplRunJob([wplPayload('5')], wplStore(), $directory, $tokens);

            return $dispatcher->jobs;
        });
        $calls         = $client->calls;
        $client->calls = [];
        $batched       = wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
            wplRunJob([wplPayload('4'), wplPayload('5')], wplStore(), $directory, $tokens);

            return $dispatcher->jobs;
        });

        return [[$calls, $single], [$client->calls, $batched]];
    });

    expect($single[0])->toBe(['getPayment:5'])
        ->and($single[1])->toBe([])
        ->and($batched[0])->toBe(['batch'])
        ->and(wplJobs($batched[1]))->toBe([
            ['apply', [
                ['entity' => 'Payment', 'id' => '4', 'operation' => 'update'],
                ['entity' => 'Invoice', 'id' => '10', 'operation' => 'update'],
            ]],
            ['sync', [['local_type' => 'invoice', 'local_uuid' => 'inv-a']]],
        ]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('a failed batch read skips its payments without failing the job or deferring them again', function (string $failure) {
    $client                = new WplClient();
    $client->batchFailure  = $failure;
    $client->payments['4'] = wplPayment('4', ['10']);
    $client->payments['5'] = wplPayment('5', ['10']);
    $directory             = wplDirectory();
    $tokens                = wplTokens($client, $directory);
    $links                 = [
        wplLink('pay-4', 'payment', '4', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('pay-6', 'payment', 'inv-b', 'Payment', '6'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
        wplLink('link-inv-b', 'invoice', 'inv-b', 'Invoice', '11'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
        wplRunJob([wplPayload('4'), wplPayload('5'), wplPayload('6')], wplStore(), $directory, $tokens);

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe(['batch'])
        ->and($client->refreshes)->toBe(0)
        ->and($directory->skipped)->toBe([])
        ->and(wplJobs($jobs))->toBe([
            ['apply', [['entity' => 'Payment', 'id' => '6', 'operation' => 'update']]],
            ['sync', [['local_type' => 'invoice', 'local_uuid' => 'inv-b']]],
        ]);
})->with(['fault', 'throw'])->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('a batched payment read refused with a 401 is read again once with a refreshed token', function () {
    $client                 = new WplClient();
    $client->rejectOldToken = true;
    $client->payments['4']  = wplPayment('4', ['10']);
    $client->payments['5']  = wplPayment('5', ['11']);
    $directory              = wplDirectory();
    $tokens                 = wplTokens($client, $directory);
    $links                  = [
        wplLink('pay-4', 'payment', '4', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
        wplLink('link-inv-b', 'invoice', 'inv-b', 'Invoice', '11'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
        wplRunJob([wplPayload('4'), wplPayload('5')], wplStore(), $directory, $tokens);

        return $dispatcher->jobs;
    }));

    expect($client->refreshes)->toBe(1)
        ->and($client->batchTokens)->toBe(['old-access', 'new-access'])
        ->and(wplJobs($jobs))->toBe([
            ['apply', [
                ['entity' => 'Payment', 'id' => '4', 'operation' => 'update'],
                ['entity' => 'Invoice', 'id' => '10', 'operation' => 'update'],
                ['entity' => 'Payment', 'id' => '5', 'operation' => 'update'],
                ['entity' => 'Invoice', 'id' => '11', 'operation' => 'update'],
            ]],
            ['sync', [
                ['local_type' => 'invoice', 'local_uuid' => 'inv-a'],
                ['local_type' => 'invoice', 'local_uuid' => 'inv-b'],
            ]],
        ]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('a keyed payment for another realm than the connection is skipped by the job', function () {
    $client                = new WplClient();
    $client->payments['7'] = wplPayment('7', ['10']);
    $directory             = wplDirectory();
    $tokens                = wplTokens($client, $directory);
    $links                 = [
        wplLink('pay-7', 'payment', '7', 'Payment', '7', 'realm-2'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10', 'realm-2'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
        wplRunJob([wplPayload('7', 'update', null, 'realm-2')], wplStore(), $directory, $tokens);

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe([])
        ->and($jobs)->toBe([]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('deletes in the job take invoices from the batch read, fall back to stored invoices for a missing payment, and mark which came from the payment', function () {
    $client                = new WplClient();
    $client->payments['4'] = wplPayment('4', ['10', '12']);
    $client->payments['6'] = ['Id' => '6', 'Line' => []];
    $directory             = wplDirectory();
    $tokens                = wplTokens($client, $directory);
    $links                 = [
        wplLink('pay-4', 'payment', '4', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('pay-6', 'payment', '6', 'Payment', '6'),
        wplLink('map-4', 'payment-invoice', '4', 'PaymentInvoice', 'inv-stored-4'),
        wplLink('map-5a', 'payment-invoice', '5', 'PaymentInvoice', 'inv-b'),
        wplLink('map-5b', 'payment-invoice', '5', 'PaymentInvoice', 'inv-file'),
        wplLink('map-5c', 'payment-invoice', '5', 'PaymentInvoice', 'inv-gone'),
        wplLink('map-6', 'payment-invoice', '6', 'PaymentInvoice', 'inv-b'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
        wplLink('link-inv-b', 'invoice', 'inv-b', 'Invoice', '11'),
        wplLink('link-inv-c', 'invoice', 'inv-c', 'Invoice', '12'),
        wplLink('link-inv-stored', 'invoice', 'inv-stored-4', 'Invoice', '13'),
    ];

    $jobs = wplWithTables($links, ['inv-file'], fn () => wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
        wplRunJob([
            wplPayload('4', 'delete'),
            ['realm_id' => 'realm-1', 'entity_type' => 'customer', 'id' => '1', 'operation' => 'delete', 'local_uuid' => 'cust-1'],
            wplPayload('5', 'delete'),
            wplPayload('6', 'delete'),
        ], wplStore(), $directory, $tokens);

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe(['batch'])
        ->and($client->queries)->toBe(["select * from Payment where Id IN ('4', '5', '6')"])
        ->and($jobs)->toBe([])
        ->and($directory->released)->toBe([[
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '4', 'local_uuid' => '4', 'invoice_uuids' => ['inv-a', 'inv-c'], 'invoices_from_payment' => true],
            ['realm_id' => 'realm-1', 'local_type' => 'customer', 'qbo_id' => '1', 'local_uuid' => 'cust-1', 'invoice_uuids' => [], 'invoices_from_payment' => true],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '5', 'local_uuid' => '5', 'invoice_uuids' => ['inv-b', 'inv-file'], 'invoices_from_payment' => false],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '6', 'local_uuid' => '6', 'invoice_uuids' => [], 'invoices_from_payment' => true],
        ]]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('a failed batch read on delete leaves stored invoices out and does not mark them as read from the payment', function (string $failure) {
    $client                = new WplClient();
    $client->batchFailure  = $failure;
    $client->payments['4'] = wplPayment('4', ['10']);
    $directory             = wplDirectory();
    $tokens                = wplTokens($client, $directory);
    $links                 = [
        wplLink('pay-4', 'payment', '4', 'Payment', '4'),
        wplLink('pay-5', 'payment', '5', 'Payment', '5'),
        wplLink('map-4', 'payment-invoice', '4', 'PaymentInvoice', 'inv-a'),
        wplLink('map-5', 'payment-invoice', '5', 'PaymentInvoice', 'inv-b'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
        wplLink('link-inv-b', 'invoice', 'inv-b', 'Invoice', '11'),
    ];

    $jobs = wplWithTables($links, [], fn () => wplWithServices($client, $directory, $tokens, function ($dispatcher) use ($directory, $tokens) {
        wplRunJob([wplPayload('4', 'delete'), wplPayload('5', 'delete')], wplStore(), $directory, $tokens);

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe(['batch'])
        ->and($jobs)->toBe([])
        ->and($directory->released)->toBe([[
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '4', 'local_uuid' => '4', 'invoice_uuids' => [], 'invoices_from_payment' => false],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '5', 'local_uuid' => '5', 'invoice_uuids' => [], 'invoices_from_payment' => false],
        ]]);
})->with(['fault', 'throw'])->skip($wplNoSqlite, 'PDO SQLite is unavailable.');

test('the request retires a direct payment delete with its linked invoices and an unlinked one with none', function () {
    $client    = new WplClient();
    $directory = wplDirectory();
    $links     = [
        wplLink('pay-4', 'payment', 'inv-a', 'Payment', '4'),
        wplLink('pay-4b', 'payment', 'inv-file', 'Payment', '4'),
        wplLink('pay-4c', 'payment', 'inv-gone', 'Payment', '4'),
        wplLink('map-9', 'payment-invoice', '9', 'PaymentInvoice', 'inv-a'),
        wplLink('link-inv-a', 'invoice', 'inv-a', 'Invoice', '10'),
    ];

    $jobs = wplWithTables($links, ['inv-file'], fn () => wplWithServices($client, $directory, null, function ($dispatcher) {
        wplRequest([
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', null),
            new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '9', 'delete', null),
        ], wplStore());

        return $dispatcher->jobs;
    }));

    expect($client->calls)->toBe([])
        ->and($jobs)->toBe([])
        ->and($directory->released)->toBe([[
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '4', 'local_uuid' => null, 'invoice_uuids' => ['inv-a', 'inv-file'], 'invoices_from_payment' => false],
            ['realm_id' => 'realm-1', 'local_type' => 'payment', 'qbo_id' => '9', 'local_uuid' => null, 'invoice_uuids' => [], 'invoices_from_payment' => false],
        ]]);
})->skip($wplNoSqlite, 'PDO SQLite is unavailable.');
