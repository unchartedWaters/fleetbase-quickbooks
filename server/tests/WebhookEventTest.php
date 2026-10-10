<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Http\Controllers\WebhookController;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\WebhookSubscriptions;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WebhookSignature;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function qboChangedSettings(): SettingsService
{
    return new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
}

function qboChangedSignature(string $body, string $verifier): string
{
    return base64_encode(hash_hmac('sha256', $body, $verifier, true));
}

function qboChangedRequest(string $body, ?string $signature): Request
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT'  => 'application/json',
    ];
    if ($signature !== null) {
        $server['HTTP_INTUIT_SIGNATURE'] = $signature;
    }

    return Request::create('/quickbooks/int/v1/webhooks', 'POST', [], [], [], $server, $body);
}

function qboChangedQueryException(string $sqlState, int $driverCode, string $message): QueryException
{
    $previous            = new PDOException($message);
    $previous->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('testing', 'insert into test values (?)', [], $previous);
}

function qboChangedHandleInsertException(QueryException $exception): void
{
    $method = new ReflectionMethod(SyncWebhookBatch::class, 'ignoreDuplicateOrThrow');
    $method->invoke(new SyncWebhookBatch('company-a', []), $exception);
}

/**
 * @param array<string, string|array<int, string>>                   $realms
 * @param array<string, string>|array<string, array<string, string>> $links
 */
function qboChangedController(SettingsService $settings, MemorySettingsStore $store, array $realms, array $links): WebhookController
{
    return new class($settings, $store, new WebhookSignature(), $realms, $links) extends WebhookController {
        /**
         * @param array<string, string|array<int, string>>                   $realms
         * @param array<string, string>|array<string, array<string, string>> $links
         */
        public function __construct(
            SettingsService $settings,
            SettingsStore $store,
            WebhookSignature $signature,
            private array $realms,
            private array $links,
        ) {
            parent::__construct($settings, $store, $signature);
        }

        /**
         * @return array<int, Connection>
         */
        protected function connectionsForRealm(string $realmId): array
        {
            $companies = $this->realms[$realmId] ?? [];
            if (is_string($companies) === true) {
                $companies = [$companies];
            }

            $connections = [];
            foreach ($companies as $companyUuid) {
                if ($companyUuid === '') {
                    continue;
                }
                $connection               = new Connection();
                $connection->company_uuid = $companyUuid;
                $connection->realm_id     = $realmId;
                $connections[]            = $connection;
            }

            return $connections;
        }

        protected function localUuids(string $companyUuid, string $realmId, array $quickbooksIds): array
        {
            $realmLinks = $this->links[$companyUuid][$realmId] ?? [];

            return is_array($realmLinks) === true ? $realmLinks : [];
        }

        protected function linksOnRealm(string $realmId, array $quickbooksIds): array
        {
            return [];
        }
    };
}

/**
 * @template T
 *
 * @param callable(): T $callback
 *
 * @return T
 */
function qboChangedWithoutVerifier(callable $callback): mixed
{
    if (class_exists(Cache::class) === true) {
        Cache::flush();
    }
    $previous  = getenv('QUICKBOOKS_WEBHOOK_VERIFIER');
    $hadEnv    = array_key_exists('QUICKBOOKS_WEBHOOK_VERIFIER', $_ENV);
    $hadServer = array_key_exists('QUICKBOOKS_WEBHOOK_VERIFIER', $_SERVER);
    $env       = $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'] ?? null;
    $server    = $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] ?? null;
    $config    = config('quickbooks.webhook_verifier');
    putenv('QUICKBOOKS_WEBHOOK_VERIFIER');
    unset($_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'], $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER']);
    config()->set('quickbooks.webhook_verifier', null);
    try {
        return $callback();
    } finally {
        if ($previous === false) {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER');
        } else {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER=' . $previous);
        }
        if ($hadEnv === true) {
            $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'] = $env;
        }
        if ($hadServer === true) {
            $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] = $server;
        }
        config()->set('quickbooks.webhook_verifier', $config);
    }
}

/**
 * @template T
 *
 * @param callable(object): T $callback
 *
 * @return T
 */
function qboChangedBus(callable $callback): mixed
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

    $container = Container::getInstance();
    $previous  = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
    $container->instance(Dispatcher::class, $dispatcher);
    try {
        return $callback($dispatcher);
    } finally {
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }
}

test('a missing or bad signature returns 401 and does not dispatch events', function () {
    $settings = qboChangedSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

    qboChangedWithoutVerifier(function () use ($settings, $body) {
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $controller                             = qboChangedController($settings, $store, ['realm-1' => 'company-a'], []);
        qboChangedCollect();

        try {
            $missing = $controller->handle(qboChangedRequest($body, null));
            $bad     = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'other-token')));

            expect($missing->getStatusCode())->toBe(401)
                ->and($bad->getStatusCode())->toBe(401)
                ->and(qboChangedSeen())->toBe([]);
        } finally {
            qboChangedRestoreEvents();
        }
    });
});

test('a valid signature dispatches one public event per in-scope entity', function () {
    $settings = qboChangedSettings();
    $body     = <<<'JSON'
{ "eventNotifications": [
  { "realmId": "realm-1", "dataChangeEvent": { "entities": [
    { "name": "Customer", "id": 55, "operation": "Create" },
    { "name": "Vendor", "id": "9", "operation": "Create" },
    { "name": "Invoice", "id": "8", "operation": "Update" },
    { "name": "Account", "id": "7", "operation": "Merge" }
  ] } },
  { "realmId": "realm-unknown", "dataChangeEvent": { "entities": [
    { "name": "Payment", "id": "3", "operation": "Create" }
  ] } }
] }
JSON;

    qboChangedWithoutVerifier(function () use ($settings, $body) {
        $store                                               = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()]              = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $controller                                          = qboChangedController($settings, $store, ['realm-1' => 'company-a'], [
            'company-a' => ['realm-1' => ['Invoice|8' => 'inv-1']],
        ]);
        qboChangedCollect();
        Http::fake();

        try {
            $response = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
            $events   = qboChangedSeen();

            expect($response->getStatusCode())->toBe(200)
                ->and($events)->toHaveCount(3)
                ->and($events[0])->toBeInstanceOf(QuickBooksEntityChanged::class)
                ->and($events[0]->companyUuid)->toBe('company-a')
                ->and($events[0]->realmId)->toBe('realm-1')
                ->and($events[0]->entityType)->toBe('customer')
                ->and($events[0]->quickbooksId)->toBe('55')
                ->and($events[0]->operation)->toBe('create')
                ->and($events[0]->localUuid)->toBeNull()
                ->and($events[1]->entityType)->toBe('invoice')
                ->and($events[1]->quickbooksId)->toBe('8')
                ->and($events[1]->operation)->toBe('update')
                ->and($events[1]->localUuid)->toBe('inv-1')
                ->and($events[2]->entityType)->toBe('wallet')
                ->and($events[2]->quickbooksId)->toBe('7')
                ->and($events[2]->operation)->toBe('update')
                ->and($events[2]->localUuid)->toBeNull()
                ->and(Http::recorded())->toHaveCount(0);
        } finally {
            qboChangedRestoreEvents();
        }
    });
});

test('one delivery for a shared realm dispatches once per company', function () {
    $settings = qboChangedSettings();
    $body     = <<<'JSON'
{ "eventNotifications": [
  { "realmId": "realm-shared", "dataChangeEvent": { "entities": [
    { "name": "Invoice", "id": "8", "operation": "Update" }
  ] } },
  { "realmId": "realm-unknown", "dataChangeEvent": { "entities": [
    { "name": "Invoice", "id": "9", "operation": "Create" }
  ] } }
] }
JSON;

    qboChangedWithoutVerifier(function () use ($settings, $body) {
        $store                                               = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()]              = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $controller                                          = qboChangedController($settings, $store, [
            'realm-shared' => ['company-a', 'company-b'],
        ], [
            'company-a' => ['realm-shared' => ['Invoice|8' => 'inv-a']],
            'company-b' => ['realm-shared' => []],
        ]);
        qboChangedCollect();
        Http::fake();

        try {
            $response = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
            $events   = qboChangedSeen();

            expect($response->getStatusCode())->toBe(200)
                ->and($events)->toHaveCount(2)
                ->and($events[0]->companyUuid)->toBe('company-a')
                ->and($events[0]->realmId)->toBe('realm-shared')
                ->and($events[0]->entityType)->toBe('invoice')
                ->and($events[0]->quickbooksId)->toBe('8')
                ->and($events[0]->operation)->toBe('update')
                ->and($events[0]->localUuid)->toBe('inv-a')
                ->and($events[1]->companyUuid)->toBe('company-b')
                ->and($events[1]->realmId)->toBe('realm-shared')
                ->and($events[1]->entityType)->toBe('invoice')
                ->and($events[1]->quickbooksId)->toBe('8')
                ->and($events[1]->operation)->toBe('update')
                ->and($events[1]->localUuid)->toBeNull()
                ->and(Http::recorded())->toHaveCount(0);
        } finally {
            qboChangedRestoreEvents();
        }
    });
});

test('the same quickbooks id resolves only through the event realm', function () {
    $settings = qboChangedSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-new","dataChangeEvent":{"entities":[{"name":"Invoice","id":"8","operation":"Update"}]}}]}';

    qboChangedWithoutVerifier(function () use ($settings, $body) {
        $store                                               = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()]              = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $controller                                          = qboChangedController($settings, $store, ['realm-new' => 'company-a'], [
            'company-a' => [
                'realm-old' => ['Invoice|8' => 'old-invoice'],
                'realm-new' => ['Invoice|8' => 'new-invoice'],
            ],
        ]);
        qboChangedCollect();

        try {
            $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
            $events = qboChangedSeen();

            expect($events)->toHaveCount(1)
                ->and($events[0]->realmId)->toBe('realm-new')
                ->and($events[0]->localUuid)->toBe('new-invoice');
        } finally {
            qboChangedRestoreEvents();
        }
    });
});

test('the sync listener treats a stored off direction as both and skips outbound and unknown invoices', function () {
    $settings                                            = qboChangedSettings();
    $store                                               = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()]              = [
        'override'           => true,
        'customer_direction' => 'off',
        'customer_conflict'  => 'quickbooks',
        'payment_direction'  => 'outbound',
        'invoice_direction'  => 'both',
        'invoice_conflict'   => 'quickbooks',
        'wallet_enabled'     => true,
        'wallet_direction'   => 'both',
        'wallet_conflict'    => 'quickbooks',
    ];
    $listener = new class($settings) extends EnqueueWebhookSync {
        protected function knownInvoices(string $companyUuid, array $events): array
        {
            return [
                'qbo'   => ['realm-1' => ['8' => 'inv-1']],
                'local' => [],
            ];
        }
    };
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '1', 'create', 'cust-1'));
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '8', 'update', null));
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '99', 'create', null));
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'create', 'pay-1'));
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'wallet', '7', 'update', 'wal-1'));

    qboChangedBus(function ($dispatcher) use ($listener, $store) {
        $listener->flush($store);

        expect($dispatcher->jobs)->toHaveCount(2)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(ApplyRemoteChange::class)
            ->and($dispatcher->jobs[0]->companyUuid)->toBe('company-a')
            ->and($dispatcher->jobs[0]->entities)->toBe([
                ['entity' => 'Customer', 'id' => '1', 'operation' => 'create'],
                ['entity' => 'Invoice', 'id' => '8', 'operation' => 'update'],
                ['entity' => 'Account', 'id' => '7', 'operation' => 'update'],
            ])
            ->and($dispatcher->jobs[1])->toBeInstanceOf(SyncWebhookBatch::class)
            ->and($dispatcher->jobs[1]->companyUuid)->toBe('company-a')
            ->and($dispatcher->jobs[1]->records)->toBe([
                ['local_type' => 'customer', 'local_uuid' => 'cust-1'],
                ['local_type' => 'invoice', 'local_uuid' => 'inv-1'],
                ['local_type' => 'wallet', 'local_uuid' => 'wal-1'],
            ]);
    });
});

test('pending inserts ignore only mysql duplicate entry errors', function () {
    qboChangedHandleInsertException(qboChangedQueryException(
        '23000',
        1062,
        "Duplicate entry 'invoice-1' for key 'pending_identity'"
    ));

    $foreignKey = qboChangedQueryException(
        '23000',
        1452,
        'Cannot add or update a child row: a foreign key constraint fails'
    );
    $caught = null;
    try {
        qboChangedHandleInsertException($foreignKey);
    } catch (QueryException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBe($foreignKey);
});

test('pending inserts ignore postgres unique violations', function () {
    qboChangedHandleInsertException(qboChangedQueryException(
        '23505',
        7,
        'duplicate key value violates unique constraint "pending_identity"'
    ));

    expect(true)->toBeTrue();
});

test('a controller link lookup failure propagates without dispatching an event or job', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');

    $settings                                            = qboChangedSettings();
    $store                                               = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()]              = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
    $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);

    $controller = new class($settings, $store, new WebhookSignature()) extends WebhookController {
        public function __construct(
            SettingsService $settings,
            SettingsStore $store,
            WebhookSignature $signature,
        ) {
            parent::__construct($settings, $store, $signature);
        }

        protected function connectionsForRealm(string $realmId): array
        {
            $connection               = new Connection();
            $connection->company_uuid = 'company-a';
            $connection->realm_id     = $realmId;

            return [$connection];
        }
    };
    $body = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Invoice","id":"8","operation":"Update"}]}}]}';
    qboChangedCollect();

    try {
        qboChangedBus(function ($dispatcher) use ($controller, $body) {
            $caught = null;
            try {
                $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
            } catch (QueryException $exception) {
                $caught = $exception;
            }

            expect($caught)->toBeInstanceOf(QueryException::class)
                ->and(qboChangedSeen())->toBe([])
                ->and($dispatcher->jobs)->toBe([]);
        });
    } finally {
        qboChangedRestoreEvents();
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('an invoice link lookup failure propagates without queuing an empty result', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');

    $settings = qboChangedSettings();
    $store    = new MemorySettingsStore();
    $listener = new EnqueueWebhookSync($settings);
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '8', 'update', null));

    try {
        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $caught = null;
            try {
                $listener->flush($store);
            } catch (QueryException $exception) {
                $caught = $exception;
            }

            expect($caught)->toBeInstanceOf(QueryException::class)
                ->and($dispatcher->jobs)->toBe([]);
        });
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a duplicate pending insert does not discard a later new row', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');
    $schema = DB::connection('sqlite')->getSchemaBuilder();

    try {
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id')->nullable();
            $table->boolean('needs_reauth')->default(false);
            $table->timestamps();
        });
        DB::table('quickbooks_connections')->insert([
            'uuid'         => 'conn-company-a',
            'company_uuid' => 'company-a',
            'realm_id'     => 'realm-1',
            'needs_reauth' => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('reason')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            $table->unique(['company_uuid', 'local_type', 'local_uuid', 'status']);
        });
        DB::statement(<<<'SQL'
CREATE TRIGGER race_duplicate_before_insert
BEFORE INSERT ON quickbooks_pending_syncs
WHEN NEW.local_uuid = 'duplicate'
BEGIN
    INSERT OR IGNORE INTO quickbooks_pending_syncs
        (uuid, company_uuid, local_type, local_uuid, reason, status, attempts, created_at, updated_at)
    VALUES
        ('race-duplicate', NEW.company_uuid, NEW.local_type, NEW.local_uuid, 'webhook', NEW.status, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
END
SQL);

        $job = new SyncWebhookBatch('company-a', [
            ['local_type' => 'invoice', 'local_uuid' => 'duplicate'],
            ['local_type' => 'invoice', 'local_uuid' => 'new-invoice'],
        ]);

        qboChangedBus(function () use ($job) {
            $job->handle();
        });

        $saved = DB::table('quickbooks_pending_syncs')->orderBy('local_uuid')->pluck('local_uuid')->all();
        // The duplicate statement can roll back its own row. The following new row must still be saved.
        expect($job->tries)->toBe(1)
            ->and($saved)->toContain('new-invoice');
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('apply does not call Intuit and the webhook url is the computed receiver url', function () {
    config()->set('app.url', 'https://api.example.test');
    Http::fake();

    $subscriptions = new WebhookSubscriptions();
    $subscriptions->apply('company', 'company-a');

    expect(SettingController::publicReceiverUrl())->toBe('https://api.example.test/quickbooks/int/v1/webhooks')
        ->and($subscriptions->webhookUrl('company-a'))->toBe('https://api.example.test/quickbooks/int/v1/webhooks');

    config()->set('app.url', '   ');
    config()->set('fleetbase.url', null);
    config()->set('quickbooks.console_host', null);
    config()->set('fleetbase.console.host', null);

    expect($subscriptions->webhookUrl('company-a'))->toBe('/quickbooks/int/v1/webhooks');

    Http::assertNothingSent();
});

test('a quickbooks delete voids the invoice and retires customers and wallets without an outbound create', function () {
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
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id')->nullable();
            $table->boolean('needs_reauth')->default(false);
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('reason')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        $schema->create('ledger_wallets', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $now = now();
        DB::table('quickbooks_connections')->insert([
            'uuid' => 'conn-company-a', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'needs_reauth' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('contacts')->insert([
            ['uuid' => 'cust-1', 'company_uuid' => 'company-a', 'name' => 'Ada', 'type' => 'customer', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'cust-2', 'company_uuid' => 'company-a', 'name' => 'Bea', 'type' => 'customer', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-1', 'company_uuid' => 'company-a', 'total_amount' => 1000, 'amount_paid' => 0, 'tax' => 0, 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('ledger_wallets')->insert([
            'uuid' => 'wal-1', 'company_uuid' => 'company-a', 'name' => 'Operating', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-cust', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'qbo_entity' => 'Customer', 'qbo_id' => '1', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-cust-2', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-2', 'qbo_entity' => 'Customer', 'qbo_id' => '2', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1', 'qbo_entity' => 'Invoice', 'qbo_id' => '8', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv-paid', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-wal', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-1', 'qbo_entity' => 'Account', 'qbo_id' => '7', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_pending_syncs')->insert([
            ['uuid' => 'pend-inv', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-1', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-cust', 'company_uuid' => 'company-a', 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-wal', 'company_uuid' => 'company-a', 'local_type' => 'wallet', 'local_uuid' => 'wal-1', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-paid', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = [
            'customer_direction' => 'both',
            'customer_conflict'  => 'quickbooks',
            'invoice_direction'  => 'both',
            'invoice_conflict'   => 'quickbooks',
            'wallet_direction'   => 'both',
            'wallet_conflict'    => 'quickbooks',
            'payment_direction'  => 'both',
            'payment_conflict'   => 'quickbooks',
        ];
        $listener = new EnqueueWebhookSync($settings);
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '1', 'delete', 'cust-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '8', 'delete', 'inv-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'wallet', '7', 'delete', 'wal-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', 'inv-paid'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '2', 'update', 'cust-2'));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            $batch  = null;
            $remote = null;
            foreach ($dispatcher->jobs as $job) {
                if (($job instanceof SyncWebhookBatch) === true) {
                    $batch = $job;
                }
                if (($job instanceof ApplyRemoteChange) === true) {
                    $remote = $job;
                }
            }

            expect($batch)->toBeInstanceOf(SyncWebhookBatch::class)
                ->and($batch->records)->toBe([
                    ['local_type' => 'customer', 'local_uuid' => 'cust-2'],
                ])
                ->and($remote)->toBeInstanceOf(ApplyRemoteChange::class)
                ->and($remote->entities)->toBe([
                    ['entity' => 'Customer', 'id' => '2', 'operation' => 'update'],
                ]);
        });

        $voided = DB::table('ledger_invoices')->where('uuid', 'inv-1')->first();
        $paid   = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();

        expect((string) $voided->status)->toBe('void')
            ->and((int) $voided->total_amount)->toBe(1000)
            ->and((int) $voided->amount_paid)->toBe(0)
            ->and((int) $voided->tax)->toBe(0)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-inv')->exists())->toBeFalse()
            ->and((string) $paid->status)->toBe('sent')
            ->and((int) $paid->total_amount)->toBe(2500)
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-inv-paid')->exists())->toBeTrue()
            ->and(DB::table('contacts')->where('uuid', 'cust-1')->whereNotNull('deleted_at')->exists())->toBeTrue()
            ->and(DB::table('contacts')->where('uuid', 'cust-2')->whereNull('deleted_at')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-cust')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-cust-2')->exists())->toBeTrue()
            ->and(DB::table('ledger_wallets')->where('uuid', 'wal-1')->whereNotNull('deleted_at')->value('status'))->toBe('closed')
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-wal')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-1')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'cust-1')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'wal-1')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done');

        $directory = new FleetbaseDirectory();
        $directory->queueInScope('company-a', ['customer' => true, 'wallet' => true, 'invoice' => false]);

        expect(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->where('local_uuid', 'cust-1')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->where('local_uuid', 'wal-1')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->where('local_uuid', 'cust-2')->exists())->toBeTrue();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a deleted payment stored under its quickbooks id unmarks the invoice and does not enqueue a create', function () {
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
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
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'partial', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-other', 'company_uuid' => 'company-a', 'total_amount' => 900, 'amount_paid' => 900, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-other', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'qbo_entity' => 'Invoice', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_pending_syncs')->insert([
            ['uuid' => 'pend-paid', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-other', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = ['payment_direction' => 'both', 'invoice_direction' => 'both'];
        $listener                               = new class($settings) extends EnqueueWebhookSync {
            protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
            {
                return ['4' => ['10']];
            }
        };
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', '4'));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            $batch = null;
            foreach ($dispatcher->jobs as $job) {
                if (($job instanceof SyncWebhookBatch) === true) {
                    $batch = $job;
                }
            }

            expect($batch)->toBeNull();
        });

        $paid  = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $other = DB::table('ledger_invoices')->where('uuid', 'inv-other')->first();

        expect((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((int) $paid->total_amount)->toBe(2500)
            ->and((string) $other->status)->toBe('paid')
            ->and((int) $other->amount_paid)->toBe(900)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-inv')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-other')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-other')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->where('local_uuid', '4')->exists())->toBeFalse();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a deleted or voided payment quickbooks no longer returns unmarks only the remembered invoice', function () {
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
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
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-partial', 'company_uuid' => 'company-a', 'total_amount' => 900, 'amount_paid' => 400, 'tax' => 0, 'status' => 'partial', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-paid', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-partial', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-partial', 'qbo_entity' => 'Invoice', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_pending_syncs')->insert([
            ['uuid' => 'pend-paid', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-partial', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-partial', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = ['payment_direction' => 'both', 'invoice_direction' => 'both'];
        $listener                               = new class($settings) extends EnqueueWebhookSync {
            protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
            {
                return ['4' => null];
            }
        };
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', '4'));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            expect($dispatcher->jobs)->toBe([]);
        });

        $paid    = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $partial = DB::table('ledger_invoices')->where('uuid', 'inv-partial')->first();

        expect((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((int) $paid->total_amount)->toBe(2500)
            ->and((string) $partial->status)->toBe('partial')
            ->and((int) $partial->amount_paid)->toBe(400)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-map')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-inv')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-partial')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-partial')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->where('local_uuid', '4')->exists())->toBeFalse();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a payment read does not unmark an invoice that payment did not pay', function () {
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
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
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-other', 'company_uuid' => 'company-a', 'total_amount' => 900, 'amount_paid' => 900, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-other', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-other', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'qbo_entity' => 'Invoice', 'qbo_id' => '11', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_pending_syncs')->insert([
            ['uuid' => 'pend-paid', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-other', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = ['payment_direction' => 'both', 'invoice_direction' => 'both'];
        $listener                               = new class($settings) extends EnqueueWebhookSync {
            protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
            {
                return ['4' => ['10']];
            }
        };
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', '4'));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            expect($dispatcher->jobs)->toBe([]);
        });

        $paid  = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $other = DB::table('ledger_invoices')->where('uuid', 'inv-other')->first();

        expect((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((string) $other->status)->toBe('paid')
            ->and((int) $other->amount_paid)->toBe(900)
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-other')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-other')->exists())->toBeTrue();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a deleted payment unmarks every invoice it paid and leaves the others', function () {
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
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
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-partial', 'company_uuid' => 'company-a', 'total_amount' => 900, 'amount_paid' => 400, 'tax' => 0, 'status' => 'partial', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-other', 'company_uuid' => 'company-a', 'total_amount' => 700, 'amount_paid' => 700, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-other', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-paid', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-partial', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-partial', 'qbo_entity' => 'Invoice', 'qbo_id' => '12', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-other', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'qbo_entity' => 'Invoice', 'qbo_id' => '11', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach (['inv-paid', 'inv-partial', 'inv-other'] as $index => $uuid) {
            DB::table('quickbooks_pending_syncs')->insert([
                'uuid'   => 'pend-' . $index, 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => $uuid,
                'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = ['payment_direction' => 'both', 'invoice_direction' => 'both'];
        $listener                               = new class($settings) extends EnqueueWebhookSync {
            protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
            {
                return ['4' => ['10', '12']];
            }
        };
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', '4'));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            expect($dispatcher->jobs)->toBe([]);
        });

        $paid    = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $partial = DB::table('ledger_invoices')->where('uuid', 'inv-partial')->first();
        $other   = DB::table('ledger_invoices')->where('uuid', 'inv-other')->first();

        expect((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((string) $partial->status)->toBe('sent')
            ->and((int) $partial->amount_paid)->toBe(400)
            ->and((string) $other->status)->toBe('paid')
            ->and((int) $other->amount_paid)->toBe(700)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-other')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-partial')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-other')->value('status'))->toBe('pending');
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a missing payment read unmarks every stored invoice on that payment', function () {
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
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
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-partial', 'company_uuid' => 'company-a', 'total_amount' => 900, 'amount_paid' => 400, 'tax' => 0, 'status' => 'partial', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-other', 'company_uuid' => 'company-a', 'total_amount' => 700, 'amount_paid' => 700, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map-paid', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-paid', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map-partial', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-partial', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-other', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'qbo_entity' => 'Invoice', 'qbo_id' => '11', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach (['inv-paid', 'inv-partial', 'inv-other'] as $index => $uuid) {
            DB::table('quickbooks_pending_syncs')->insert([
                'uuid'   => 'pend-' . $index, 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => $uuid,
                'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = ['payment_direction' => 'both', 'invoice_direction' => 'both'];
        $listener                               = new class($settings) extends EnqueueWebhookSync {
            protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
            {
                return ['4' => null];
            }
        };
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', '4'));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            expect($dispatcher->jobs)->toBe([]);
        });

        $paid    = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $partial = DB::table('ledger_invoices')->where('uuid', 'inv-partial')->first();
        $other   = DB::table('ledger_invoices')->where('uuid', 'inv-other')->first();

        expect((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((string) $partial->status)->toBe('sent')
            ->and((int) $partial->amount_paid)->toBe(400)
            ->and((string) $other->status)->toBe('paid')
            ->and((int) $other->amount_paid)->toBe(700)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-map-paid')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-map-partial')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-other')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-partial')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-other')->value('status'))->toBe('pending');
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

/**
 * Delete or void when the payment body was not read. The stored invoice is unmarked.
 * A client that names a different invoice must not be applied on these failures.
 */
function qboChangedUnreadPaymentDelete(string $failure): void
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

    $client = new class($failure) extends QuickBooksClient {
        public int $reads = 0;

        public function __construct(private string $failure)
        {
        }

        public function getPayment(array $connection, string $id): ?array
        {
            $this->reads++;
            if ($this->failure === 'throw') {
                throw new RuntimeException('payment read failed');
            }

            return [
                'Id'   => $id,
                'Line' => [
                    ['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '11']]],
                ],
            ];
        }

        public function batch(array $connection, array $items): array
        {
            $this->reads++;

            return [
                'webhook-payments-0' => [
                    'ok'     => false,
                    'body'   => [],
                    'rows'   => [[
                        'Id'   => '4',
                        'Line' => [
                            ['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '11']]],
                        ],
                    ]],
                    'error'  => 'fault',
                    'status' => 400,
                    'halt'   => false,
                ],
            ];
        }
    };
    $container      = Container::getInstance();
    $previousClient = $container->bound(QuickBooksClient::class) === true ? $container->make(QuickBooksClient::class) : null;
    $container->instance(QuickBooksClient::class, $client);

    try {
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id')->nullable();
            $table->boolean('needs_reauth')->default(false);
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
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
        DB::table('quickbooks_connections')->insert([
            'uuid'         => 'conn-company-a',
            'company_uuid' => 'company-a',
            'realm_id'     => $failure === 'realm' ? 'realm-other' : 'realm-1',
            'needs_reauth' => $failure === 'reauth' ? 1 : 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-partial', 'company_uuid' => 'company-a', 'total_amount' => 900, 'amount_paid' => 400, 'tax' => 0, 'status' => 'partial', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-other', 'company_uuid' => 'company-a', 'total_amount' => 700, 'amount_paid' => 700, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '4', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-paid', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-pay-5', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '5', 'qbo_entity' => 'Payment', 'qbo_id' => '5', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-map-5', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment-invoice', 'local_uuid' => '5', 'qbo_entity' => 'PaymentInvoice', 'qbo_id' => 'inv-partial', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-partial', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-partial', 'qbo_entity' => 'Invoice', 'qbo_id' => '12', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-other', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'qbo_entity' => 'Invoice', 'qbo_id' => '11', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('quickbooks_pending_syncs')->insert([
            ['uuid' => 'pend-paid', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-partial', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-partial', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'pend-other', 'company_uuid' => 'company-a', 'local_type' => 'invoice', 'local_uuid' => 'inv-other', 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = ['payment_direction' => 'both', 'invoice_direction' => 'both'];
        $listener                               = new EnqueueWebhookSync($settings);
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', '4'));
        if ($failure === 'batch') {
            $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'delete', '5'));
        }

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            expect($dispatcher->jobs)->toBe([]);
        });

        $paid    = DB::table('ledger_invoices')->where('uuid', 'inv-paid')->first();
        $partial = DB::table('ledger_invoices')->where('uuid', 'inv-partial')->first();
        $other   = DB::table('ledger_invoices')->where('uuid', 'inv-other')->first();
        $batch   = $failure === 'batch';

        expect((string) $paid->status)->toBe('sent')
            ->and((int) $paid->amount_paid)->toBe(2500)
            ->and((int) $paid->total_amount)->toBe(2500)
            ->and((string) $partial->status)->toBe($batch === true ? 'sent' : 'partial')
            ->and((int) $partial->amount_paid)->toBe(400)
            ->and((string) $other->status)->toBe('paid')
            ->and((int) $other->amount_paid)->toBe(700)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-map')->exists())->toBeFalse()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-pay-5')->exists())->toBe($batch === false)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-map-5')->exists())->toBe($batch === false)
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-inv')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-partial')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_links')->where('uuid', 'link-other')->exists())->toBeTrue()
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-paid')->value('status'))->toBe('done')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-partial')->value('status'))->toBe($batch === true ? 'done' : 'pending')
            ->and(DB::table('quickbooks_pending_syncs')->where('local_uuid', 'inv-other')->value('status'))->toBe('pending')
            ->and(DB::table('quickbooks_pending_syncs')->where('status', 'pending')->whereIn('local_uuid', ['4', '5'])->exists())->toBeFalse()
            ->and($client->reads)->toBe(in_array($failure, ['throw', 'batch'], true) === true ? 1 : 0);
    } finally {
        if ($previousClient !== null) {
            $container->instance(QuickBooksClient::class, $previousClient);
        } else {
            $container->forgetInstance(QuickBooksClient::class);
        }
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
}

test('a thrown payment read on delete unmarks only the remembered invoice', function () {
    qboChangedUnreadPaymentDelete('throw');
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a reauth skip on delete unmarks only the remembered invoice', function () {
    qboChangedUnreadPaymentDelete('reauth');
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a realm mismatch on delete unmarks only the remembered invoice', function () {
    qboChangedUnreadPaymentDelete('realm');
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a batch fault on delete unmarks only the remembered invoices', function () {
    qboChangedUnreadPaymentDelete('batch');
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a delete or void webhook does not create a pending sync row', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');
    $schema = DB::connection('sqlite')->getSchemaBuilder();

    try {
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id')->nullable();
            $table->boolean('needs_reauth')->default(false);
            $table->timestamps();
        });
        DB::table('quickbooks_connections')->insert([
            'uuid'         => 'conn-company-a',
            'company_uuid' => 'company-a',
            'realm_id'     => 'realm-1',
            'needs_reauth' => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('reason')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = [
            'customer_direction' => 'both',
            'customer_conflict'  => 'quickbooks',
            'invoice_direction'  => 'both',
            'invoice_conflict'   => 'quickbooks',
            'wallet_direction'   => 'both',
            'wallet_conflict'    => 'quickbooks',
            'payment_direction'  => 'both',
            'payment_conflict'   => 'quickbooks',
        ];
        $listener = new class($settings) extends EnqueueWebhookSync {
            protected function knownInvoices(string $companyUuid, array $events): array
            {
                return [
                    'qbo'   => ['realm-1' => ['8' => 'inv-live']],
                    'local' => ['inv-live' => true],
                ];
            }
        };
        // The controller maps both Delete and Void onto operation delete before this listener runs.
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '1', 'delete', 'cust-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '8', 'delete', 'inv-voided'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'wallet', '7', 'delete', 'wal-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '8', 'update', null));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            $batch  = null;
            $remote = null;
            foreach ($dispatcher->jobs as $job) {
                if (($job instanceof SyncWebhookBatch) === true) {
                    $batch = $job;
                }
                if (($job instanceof ApplyRemoteChange) === true) {
                    $remote = $job;
                }
            }

            expect($batch)->toBeInstanceOf(SyncWebhookBatch::class)
                ->and($remote)->toBeInstanceOf(ApplyRemoteChange::class)
                ->and($batch->records)->toBe([
                    ['local_type' => 'invoice', 'local_uuid' => 'inv-live'],
                ])
                ->and($remote->entities)->toBe([
                    ['entity' => 'Invoice', 'id' => '8', 'operation' => 'update'],
                ]);

            $batch->handle();
            $pending = DB::table('quickbooks_pending_syncs')->orderBy('local_uuid')->get(['local_type', 'local_uuid']);

            expect($pending)->toHaveCount(1)
                ->and($pending[0]->local_type)->toBe('invoice')
                ->and($pending[0]->local_uuid)->toBe('inv-live')
                ->and($pending->pluck('local_uuid')->all())->not->toContain('cust-1')
                ->and($pending->pluck('local_uuid')->all())->not->toContain('inv-voided')
                ->and($pending->pluck('local_uuid')->all())->not->toContain('wal-1');
        });
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a payment webhook queues the linked invoice and skips an unlinked payment', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
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
        $now = now();
        DB::table('quickbooks_links')->insert([
            [
                'uuid'         => 'link-payment',
                'company_uuid' => 'company-a',
                'realm_id'     => 'realm-1',
                'local_type'   => 'payment',
                'local_uuid'   => 'inv-9',
                'qbo_entity'   => 'Payment',
                'qbo_id'       => '4',
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'uuid'         => 'link-invoice',
                'company_uuid' => 'company-a',
                'realm_id'     => 'realm-1',
                'local_type'   => 'invoice',
                'local_uuid'   => 'inv-9',
                'qbo_entity'   => 'Invoice',
                'qbo_id'       => '8',
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = [
            'customer_direction' => 'both',
            'customer_conflict'  => 'quickbooks',
            'invoice_direction'  => 'both',
            'invoice_conflict'   => 'quickbooks',
            'payment_direction'  => 'both',
            'payment_conflict'   => 'quickbooks',
            'wallet_direction'   => 'both',
            'wallet_conflict'    => 'quickbooks',
        ];
        $listener = new EnqueueWebhookSync($settings);
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '1', 'update', 'cust-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'create', 'pay-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '99', 'create', null));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $connection = (new Fleetbase\Quickbooks\Models\Link())->getConnection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $listener->flush($store);
            $queries = array_column($connection->getQueryLog(), 'query');

            $batch  = null;
            $remote = null;
            foreach ($dispatcher->jobs as $job) {
                if (($job instanceof SyncWebhookBatch) === true) {
                    $batch = $job;
                }
                if (($job instanceof ApplyRemoteChange) === true) {
                    $remote = $job;
                }
            }

            expect($batch)->toBeInstanceOf(SyncWebhookBatch::class)
                ->and($remote)->toBeInstanceOf(ApplyRemoteChange::class)
                ->and($batch->records)->toBe([
                    ['local_type' => 'customer', 'local_uuid' => 'cust-1'],
                    ['local_type' => 'invoice', 'local_uuid' => 'inv-9'],
                ])
                ->and(array_column($batch->records, 'local_type'))->not->toContain('payment')
                ->and($remote->entities)->toBe([
                    ['entity' => 'Customer', 'id' => '1', 'operation' => 'update'],
                    ['entity' => 'Payment', 'id' => '4', 'operation' => 'create'],
                ])
                ->and($queries)->toHaveCount(2)
                ->and($queries[0])->toContain('qbo_id')
                ->and($queries[1])->toContain('local_uuid');
        });
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a payment stored under its quickbooks id queues the fleetbase invoice', function () {
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
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
        $now = now();
        DB::table('quickbooks_links')->insert([
            [
                'uuid'         => 'link-payment-id',
                'company_uuid' => 'company-a',
                'realm_id'     => 'realm-1',
                'local_type'   => 'payment',
                'local_uuid'   => '4',
                'qbo_entity'   => 'Payment',
                'qbo_id'       => '4',
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'uuid'         => 'link-unlinked-payment',
                'company_uuid' => 'company-a',
                'realm_id'     => 'realm-1',
                'local_type'   => 'payment',
                'local_uuid'   => '5',
                'qbo_entity'   => 'Payment',
                'qbo_id'       => '5',
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'uuid'         => 'link-invoice',
                'company_uuid' => 'company-a',
                'realm_id'     => 'realm-1',
                'local_type'   => 'invoice',
                'local_uuid'   => 'inv-9',
                'qbo_entity'   => 'Invoice',
                'qbo_id'       => '8',
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = [
            'payment_direction' => 'both',
            'payment_conflict'  => 'quickbooks',
            'invoice_direction' => 'both',
            'invoice_conflict'  => 'quickbooks',
        ];
        $listener = new class($settings) extends EnqueueWebhookSync {
            /** @var array<int, string> */
            public array $asked = [];

            protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
            {
                $this->asked = $paymentIds;

                return ['4' => ['8'], '5' => ['missing-invoice']];
            }
        };
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'update', null));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '5', 'create', null));

        qboChangedBus(function ($dispatcher) use ($listener, $store) {
            $listener->flush($store);

            $batch  = null;
            $remote = null;
            foreach ($dispatcher->jobs as $job) {
                if (($job instanceof SyncWebhookBatch) === true) {
                    $batch = $job;
                }
                if (($job instanceof ApplyRemoteChange) === true) {
                    $remote = $job;
                }
            }

            expect($listener->asked)->toBe(['4', '5'])
                ->and($batch)->toBeInstanceOf(SyncWebhookBatch::class)
                ->and($batch->records)->toBe([
                    ['local_type' => 'invoice', 'local_uuid' => 'inv-9'],
                ])
                ->and($remote)->toBeInstanceOf(ApplyRemoteChange::class)
                ->and($remote->entities)->toBe([
                    ['entity' => 'Payment', 'id' => '4', 'operation' => 'update'],
                    ['entity' => 'Invoice', 'id' => '8', 'operation' => 'update'],
                ]);
        });
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a connection is not shared with another organization on the same realm', function () {
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
        $schema->create('quickbooks_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id')->nullable();
            $table->boolean('needs_reauth')->default(false);
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
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('reason')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        $schema->create('ledger_invoices', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $now = now();
        DB::table('quickbooks_connections')->insert([
            'uuid'         => 'conn-owner',
            'company_uuid' => 'company-a',
            'realm_id'     => 'realm-shared',
            'needs_reauth' => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DB::table('quickbooks_links')->insert([
            'uuid'         => 'link-other-invoice',
            'company_uuid' => 'company-b',
            'realm_id'     => 'realm-shared',
            'local_type'   => 'invoice',
            'local_uuid'   => 'inv-b',
            'qbo_entity'   => 'Invoice',
            'qbo_id'       => '8',
            'sync_token'   => '0',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DB::table('ledger_invoices')->insert([
            'uuid'         => 'inv-b',
            'company_uuid' => 'company-b',
            'deleted_at'   => null,
        ]);

        $settings                               = qboChangedSettings();
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $store->rows[SettingsKeys::adminSync()] = [
            'invoice_direction' => 'both',
        ];
        $body = '{"eventNotifications":[{"realmId":"realm-shared","dataChangeEvent":{"entities":[{"name":"Invoice","id":"8","operation":"Update"}]}}]}';

        qboChangedWithoutVerifier(function () use ($settings, $store, $body) {
            $controller = new WebhookController($settings, $store, new WebhookSignature());
            qboChangedCollect();
            qboChangedBus(function ($dispatcher) use ($controller, $body, $settings, $store) {
                try {
                    $response = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
                    $events   = qboChangedSeen();

                    expect($response->getStatusCode())->toBe(200)
                        ->and($events)->toHaveCount(2)
                        ->and($events[0]->companyUuid)->toBe('company-a')
                        ->and($events[0]->localUuid)->toBeNull()
                        ->and($events[1]->companyUuid)->toBe('company-b')
                        ->and($events[1]->entityType)->toBe('invoice')
                        ->and($events[1]->quickbooksId)->toBe('8')
                        ->and($events[1]->localUuid)->toBe('inv-b');

                    $listener = new EnqueueWebhookSync($settings);
                    foreach ($events as $event) {
                        $listener->handle($event);
                    }
                    $listener->flush($store);

                    $batch = null;
                    foreach ($dispatcher->jobs as $job) {
                        if (($job instanceof SyncWebhookBatch) === true && $job->companyUuid === 'company-b') {
                            $batch = $job;
                        }
                    }

                    expect($batch)->toBeInstanceOf(SyncWebhookBatch::class)
                        ->and($batch->records)->toBe([
                            ['local_type' => 'invoice', 'local_uuid' => 'inv-b'],
                        ]);

                    $batch->handle();
                    $pending = DB::table('quickbooks_pending_syncs')->count();
                    $synced  = false;
                    foreach ($dispatcher->jobs as $job) {
                        if ($job instanceof SyncCompanyBatch === true) {
                            $synced = true;
                        }
                    }

                    expect($pending)->toBe(0)
                        ->and($synced)->toBeFalse();
                } finally {
                    qboChangedRestoreEvents();
                }
            });
        });
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('fleetbase primary does not queue a quickbooks copy and still queues the fleetbase push', function () {
    $settings                               = qboChangedSettings();
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = [
        'customer_direction' => 'both',
        'customer_conflict'  => 'fleetbase',
    ];
    $listener = new EnqueueWebhookSync($settings);
    $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '1', 'update', 'cust-1'));

    qboChangedBus(function ($dispatcher) use ($listener, $store) {
        $listener->flush($store);

        expect($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(SyncWebhookBatch::class)
            ->and($dispatcher->jobs[0]->records)->toBe([
                ['local_type' => 'customer', 'local_uuid' => 'cust-1'],
            ]);
    });
});

test('a signed webhook older than ten minutes is rejected and a down replay store returns 503', function () {
    $settings = qboChangedSettings();
    $staleAt  = (new DateTimeImmutable('@' . (time() - 700)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sO');
    $freshAt  = (new DateTimeImmutable('@' . time()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sO');
    $stale    = '{"eventNotifications":[{"realmId":"realm-replay","dataChangeEvent":{"entities":[{"name":"Customer","id":"77","operation":"Create","lastUpdated":"' . $staleAt . '"}]}}]}';
    $fresh    = '{"eventNotifications":[{"realmId":"realm-replay","dataChangeEvent":{"entities":[{"name":"Customer","id":"78","operation":"Update","lastUpdated":"' . $freshAt . '"}]}}]}';

    qboChangedWithoutVerifier(function () use ($settings, $stale, $fresh) {
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $controller                             = qboChangedController($settings, $store, ['realm-replay' => 'company-a'], []);
        qboChangedCollect();

        try {
            $rejected = $controller->handle(qboChangedRequest($stale, qboChangedSignature($stale, 'verifier-token')));
            expect($rejected->getStatusCode())->toBe(401)
                ->and(qboChangedSeen())->toBe([]);

            $previous = Cache::getFacadeRoot();
            Cache::swap(new class {
                public function add(string $key, mixed $value, mixed $ttl = null): bool
                {
                    throw new RuntimeException('redis down');
                }
            });
            try {
                $down = $controller->handle(qboChangedRequest($fresh, qboChangedSignature($fresh, 'verifier-token')));
            } finally {
                Cache::swap($previous);
            }

            expect($down->getStatusCode())->toBe(503)
                ->and($down->getData(true)['message'])->not->toBe('Invalid signature.')
                ->and(qboChangedSeen())->toBe([]);
        } finally {
            qboChangedRestoreEvents();
        }
    });
});

test('the same signed webhook body is rejected on replay and a bad signature is not cached', function () {
    $settings = qboChangedSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-replay","dataChangeEvent":{"entities":[{"name":"Customer","id":"77","operation":"Create"}]}}]}';
    $other    = '{"eventNotifications":[{"realmId":"realm-replay","dataChangeEvent":{"entities":[{"name":"Customer","id":"78","operation":"Update"}]}}]}';

    qboChangedWithoutVerifier(function () use ($settings, $body, $other) {
        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);
        $controller                             = qboChangedController($settings, $store, ['realm-replay' => 'company-a'], []);
        qboChangedCollect();

        try {
            $bad   = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'other-token')));
            $first = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
            expect($bad->getStatusCode())->toBe(401)
                ->and($first->getStatusCode())->toBe(200)
                ->and(qboChangedSeen())->toHaveCount(1);

            qboChangedRestoreEvents();
            qboChangedCollect();
            $replay = $controller->handle(qboChangedRequest($body, qboChangedSignature($body, 'verifier-token')));
            $again  = $controller->handle(qboChangedRequest($other, qboChangedSignature($other, 'verifier-token')));

            expect($replay->getStatusCode())->toBe(401)
                ->and(qboChangedSeen())->toHaveCount(1)
                ->and(qboChangedSeen()[0]->quickbooksId)->toBe('78')
                ->and($again->getStatusCode())->toBe(200);
        } finally {
            qboChangedRestoreEvents();
        }
    });
});

function qboChangedCollect(): void
{
    $GLOBALS['qboChangedEvents']   = [];
    $GLOBALS['qboChangedPrevious'] = app('events');
    $dispatcher                    = new Illuminate\Events\Dispatcher(app());
    $dispatcher->listen(QuickBooksEntityChanged::class, function (QuickBooksEntityChanged $event): void {
        $GLOBALS['qboChangedEvents'][] = $event;
    });
    app()->instance('events', $dispatcher);
}

/**
 * @return array<int, QuickBooksEntityChanged>
 */
function qboChangedSeen(): array
{
    $events = $GLOBALS['qboChangedEvents'] ?? [];

    return is_array($events) === true ? $events : [];
}

function qboChangedRestoreEvents(): void
{
    if (isset($GLOBALS['qboChangedPrevious']) === true) {
        app()->instance('events', $GLOBALS['qboChangedPrevious']);
        unset($GLOBALS['qboChangedPrevious'], $GLOBALS['qboChangedEvents']);
    }
}
