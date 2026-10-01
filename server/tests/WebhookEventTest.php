<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Http\Controllers\WebhookController;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Models\Connection;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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
            if (is_string($companies)) {
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

            return is_array($realmLinks) ? $realmLinks : [];
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
        if ($hadEnv) {
            $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'] = $env;
        }
        if ($hadServer) {
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
    $previous  = $container->bound(Dispatcher::class) ? $container->make(Dispatcher::class) : null;
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
    $store->rows[SettingsKeys::companySync('company-a')] = [
        'override'           => true,
        'customer_direction' => 'off',
        'payment_direction'  => 'outbound',
        'invoice_direction'  => 'both',
        'wallet_direction'   => 'both',
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

        expect($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(SyncWebhookBatch::class)
            ->and($dispatcher->jobs[0]->companyUuid)->toBe('company-a')
            ->and($dispatcher->jobs[0]->records)->toBe([
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
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

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
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

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
})->skip(!in_array('sqlite', PDO::getAvailableDrivers(), true), 'PDO SQLite is unavailable.');

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

    return is_array($events) ? $events : [];
}

function qboChangedRestoreEvents(): void
{
    if (isset($GLOBALS['qboChangedPrevious'])) {
        app()->instance('events', $GLOBALS['qboChangedPrevious']);
        unset($GLOBALS['qboChangedPrevious'], $GLOBALS['qboChangedEvents']);
    }
}
