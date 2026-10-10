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
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rejects the old access token with 401 and counts the Intuit refreshes.
 */
class TokenRecoveryClient extends FakeQuickBooks
{
    public int $refreshes = 0;

    /** @var array<int, string> */
    public array $tokensSeen = [];

    /** @var array<int, string> */
    public array $paymentTokens = [];

    public bool $refuseNewToken = false;

    private bool $inBatch = false;

    public ?QuickBooksException $refreshError = null;

    public function refresh(array $credentials, string $refreshToken): array
    {
        $this->refreshes++;
        if ($this->refreshError !== null) {
            throw $this->refreshError;
        }

        return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600];
    }

    /**
     * Like the real client, a rejected access token fails the whole request with a thrown 401.
     */
    public function batch(array $connection, array $items): array
    {
        $this->rejectStaleToken($connection);
        $this->inBatch = true;
        try {
            return parent::batch($connection, $items);
        } finally {
            $this->inBatch = false;
        }
    }

    public function createCustomer(array $connection, array $payload): array
    {
        if ($this->inBatch === false) {
            $this->rejectStaleToken($connection);
        }

        return parent::createCustomer($connection, $payload);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function rejectStaleToken(array $connection): void
    {
        $token              = (string) ($connection['access_token'] ?? '');
        $this->tokensSeen[] = $token;
        if ($token === 'old-access' || $this->refuseNewToken === true) {
            throw new QuickBooksException(401, 'QuickBooks request failed with status 401');
        }
    }

    public function getPayment(array $connection, string $id): ?array
    {
        $token                 = (string) ($connection['access_token'] ?? '');
        $this->paymentTokens[] = $token;
        if ($token === 'old-access') {
            throw new QuickBooksException(401, 'QuickBooks request failed with status 401');
        }

        return parent::getPayment($connection, $id);
    }
}

/**
 * @return array{0: ConnectionTokens, 1: FleetbaseDirectory}
 */
function trcTokens(TokenRecoveryClient $client, ?int $expiresAt = null): array
{
    $directory                                      = new FleetbaseDirectory();
    $directory->memory                              = new SyncLedger();
    $directory->memory->connections['company-uuid'] = trcConnection($expiresAt);
    $settings                                       = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());

    return [new ConnectionTokens(new TokenRefresher($client), $settings, new MemorySettingsStore(), $directory), $directory];
}

/**
 * @return array<string, mixed>
 */
function trcConnection(?int $expiresAt): array
{
    return [
        'company_uuid'     => 'company-uuid',
        'realm_id'         => 'realm-1',
        'access_token'     => 'old-access',
        'refresh_token'    => 'old-refresh',
        'token_expires_at' => $expiresAt ?? time() + 3600,
        'needs_reauth'     => false,
        'last_batch_at'    => null,
    ];
}

/**
 * A ledger of its own with the old token and two customers waiting to be created.
 */
function trcLedger(): SyncLedger
{
    $ledger                                = new SyncLedger();
    $ledger->connections['company-uuid']   = trcConnection(null);
    foreach (['cust-1', 'cust-2'] as $uuid) {
        $ledger->customers[$uuid] = [
            'uuid'         => $uuid,
            'company_uuid' => 'company-uuid',
            'name'         => 'Customer ' . $uuid,
            'email'        => $uuid . '@example.test',
        ];
    }

    return $ledger;
}

/**
 * @return array<int, array<string, mixed>>
 */
function trcRows(): array
{
    $rows = [];
    foreach (['cust-1', 'cust-2'] as $uuid) {
        $rows[] = ['company_uuid' => 'company-uuid', 'local_type' => 'customer', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
    }

    return $rows;
}

function trcEngine(TokenRecoveryClient $client, ?ConnectionTokens $tokens): SyncEngine
{
    return new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait),
        $tokens
    );
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function trcSync(array $overrides = []): array
{
    return qbSettings($overrides);
}

test('a 401 refreshes the access token once and repeats the call without asking to reconnect', function () {
    $client               = new TokenRecoveryClient();
    [$tokens, $directory] = trcTokens($client);
    $engine               = trcEngine($client, $tokens);
    $ledger               = trcLedger();

    $batch = $engine->syncEntities($ledger, 'company-uuid', trcRows(), trcSync(), time());

    expect($batch['created'])->toBe(2)
        ->and($batch['failed'])->toBe(0)
        ->and($client->refreshes)->toBe(1)
        ->and($client->tokensSeen[0])->toBe('old-access')
        ->and(array_count_values($client->tokensSeen)['old-access'])->toBe(1)
        ->and(end($client->tokensSeen))->toBe('new-access')
        ->and(empty($ledger->connections['company-uuid']['needs_reauth']))->toBeTrue()
        ->and($directory->memory->connections['company-uuid']['access_token'])->toBe('new-access')
        ->and($directory->memory->connections['company-uuid']['refresh_token'])->toBe('new-refresh');
});

test('a later call that still holds the replaced token uses the new one without a second refresh', function () {
    $client               = new TokenRecoveryClient();
    [$tokens]             = trcTokens($client);
    $engine               = trcEngine($client, $tokens);
    $ledger               = trcLedger();
    $rows                 = trcRows();

    $engine->syncEntities($ledger, 'company-uuid', [$rows[0]], trcSync(), time());
    $refreshesAfterFirst = $client->refreshes;
    $before              = count($client->tokensSeen);
    $second              = $engine->syncEntities($ledger, 'company-uuid', [$rows[1]], trcSync(), time());

    expect($refreshesAfterFirst)->toBe(1)
        ->and($client->refreshes)->toBe(1)
        ->and($second['created'])->toBe(1)
        ->and(array_unique(array_slice($client->tokensSeen, $before)))->toBe(['new-access']);
});

test('a refused refresh token still asks the user to reconnect, once, after a single refresh', function () {
    $client               = new TokenRecoveryClient();
    $client->refreshError = new QuickBooksException(400, 'invalid_grant');
    [$tokens, $directory] = trcTokens($client);
    $engine               = trcEngine($client, $tokens);
    $ledger               = trcLedger();

    $engine->syncEntities($ledger, 'company-uuid', trcRows(), trcSync(), time());

    $errors = array_column($ledger->attempts, 'error');

    expect($client->refreshes)->toBe(1)
        ->and(array_unique($client->tokensSeen))->toBe(['old-access'])
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue()
        ->and($errors)->toContain(SyncEngine::TOKEN_REJECTED_MESSAGE)
        ->and($directory->memory->connections['company-uuid']['needs_reauth'])->toBeTrue();
});

test('a token that is refused again after a refresh asks the user to reconnect', function () {
    $client                 = new TokenRecoveryClient();
    $client->refuseNewToken = true;
    [$tokens]               = trcTokens($client);
    $engine                 = trcEngine($client, $tokens);
    $ledger                 = trcLedger();

    $engine->syncEntities($ledger, 'company-uuid', trcRows(), trcSync(), time());

    expect($client->refreshes)->toBe(1)
        ->and(array_values(array_unique($client->tokensSeen)))->toBe(['old-access', 'new-access'])
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue();
});

test('the reauth flag is compared against the rotated tokens so it survives the save', function () {
    $client                 = new TokenRecoveryClient();
    $client->refuseNewToken = true;
    [$tokens]               = trcTokens($client);
    $engine                 = trcEngine($client, $tokens);
    $ledger                 = trcLedger();

    $engine->syncEntities($ledger, 'company-uuid', trcRows(), trcSync(), time());

    expect($ledger->connections['company-uuid']['access_token'])->toBe('new-access')
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue();
});

test('a refresh that fails for another reason does not ask the user to reconnect', function () {
    $client               = new TokenRecoveryClient();
    $client->refreshError = new QuickBooksException(503, 'unavailable');
    [$tokens, $directory] = trcTokens($client);
    $engine               = trcEngine($client, $tokens);
    $ledger               = trcLedger();

    $engine->syncEntities($ledger, 'company-uuid', trcRows(), trcSync(), time());

    expect(empty($ledger->connections['company-uuid']['needs_reauth']))->toBeTrue()
        ->and(empty($directory->memory->connections['company-uuid']['needs_reauth']))->toBeTrue()
        ->and(array_column($ledger->attempts, 'error'))->not->toContain(SyncEngine::TOKEN_REJECTED_MESSAGE);
});

test('without a token service a 401 still asks the user to reconnect at once', function () {
    $client = new TokenRecoveryClient();
    $engine = trcEngine($client, null);
    $ledger = trcLedger();

    $engine->syncEntities($ledger, 'company-uuid', trcRows(), trcSync(), time());

    expect($client->refreshes)->toBe(0)
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue();
});

/**
 * Runs the callback with an array cache for the company lock and a dispatcher that keeps jobs.
 */
function trcWithBus(callable $callback): mixed
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
    $cache     = Cache::getFacadeRoot();
    $container->instance(Dispatcher::class, $dispatcher);
    Cache::swap(new Repository(new ArrayStore()));
    try {
        return $callback($dispatcher);
    } finally {
        Cache::swap($cache);
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }
}

/**
 * A directory whose stored connection is the one the token service refreshes, so a
 * webhook job sees the refreshed token. The engine records what it was handed.
 *
 * @return array{0: SyncEngine, 1: FleetbaseDirectory}
 */
function trcWebhookParts(TokenRecoveryClient $client, ConnectionTokens $tokens, FleetbaseDirectory $stored): array
{
    $engine = new class($client, new CustomerMapper(), new InvoiceMapper(), new WalletMapper(), new BackoffPolicy(), $tokens) extends SyncEngine {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function acceptRemoteChanges(SyncLedger $ledger, string $companyUuid, array $entities, array $settings, int $now): void
        {
            $this->calls[] = ['connection' => $ledger->connection($companyUuid), 'entities' => $entities];
        }
    };
    $directory = new class extends FleetbaseDirectory {
        /** @var array<int, array<string, string>> */
        public array $skipped = [];

        public function loadLinked(string $companyUuid, array $entities): ?array
        {
            $ledger                            = new SyncLedger();
            $ledger->connections[$companyUuid] = $this->connection($companyUuid);

            return ['ledger' => $ledger, 'connection' => $ledger->connections[$companyUuid], 'customers' => []];
        }

        public function save(SyncLedger $ledger): void
        {
        }

        public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
        {
            $this->skipped[] = ['trigger' => $trigger, 'direction' => $direction, 'message' => $message];
        }
    };
    $directory->memory = $stored->memory;

    return [$engine, $directory];
}

test('a remote change refreshes an expired access token before it reads from quickbooks', function () {
    $client               = new TokenRecoveryClient();
    [$tokens, $stored]    = trcTokens($client, time() - 30);
    [$engine, $directory] = trcWebhookParts($client, $tokens, $stored);
    $settings             = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());

    trcWithBus(function () use ($engine, $directory, $settings, $tokens) {
        $job = new ApplyRemoteChange('company-uuid', [['entity' => 'Customer', 'id' => '1', 'operation' => 'Update']]);
        $job->handle($engine, $directory, $settings, new MemorySettingsStore(), $tokens);
    });

    expect($client->refreshes)->toBe(1)
        ->and($engine->calls)->toHaveCount(1)
        ->and($engine->calls[0]['connection']['access_token'])->toBe('new-access')
        ->and($stored->memory->connections['company-uuid']['access_token'])->toBe('new-access')
        ->and($directory->skipped)->toBe([]);
});

test('a remote change leaves a token that is not due alone', function () {
    $client               = new TokenRecoveryClient();
    [$tokens, $stored]    = trcTokens($client);
    [$engine, $directory] = trcWebhookParts($client, $tokens, $stored);
    $settings             = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());

    trcWithBus(function () use ($engine, $directory, $settings, $tokens) {
        $job = new ApplyRemoteChange('company-uuid', [['entity' => 'Customer', 'id' => '1', 'operation' => 'Update']]);
        $job->handle($engine, $directory, $settings, new MemorySettingsStore(), $tokens);
    });

    expect($client->refreshes)->toBe(0)
        ->and($engine->calls[0]['connection']['access_token'])->toBe('old-access');
});

test('a remote change is recorded as skipped when the refresh token was refused', function () {
    $client               = new TokenRecoveryClient();
    $client->refreshError = new QuickBooksException(400, 'invalid_grant');
    [$tokens, $stored]    = trcTokens($client, time() - 30);
    [$engine, $directory] = trcWebhookParts($client, $tokens, $stored);
    $settings             = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());

    $jobs = trcWithBus(function ($dispatcher) use ($engine, $directory, $settings, $tokens) {
        $job = new ApplyRemoteChange('company-uuid', [['entity' => 'Customer', 'id' => '1', 'operation' => 'Update']]);
        $job->handle($engine, $directory, $settings, new MemorySettingsStore(), $tokens);

        return $dispatcher->jobs;
    });

    expect($engine->calls)->toBe([])
        ->and($directory->skipped)->toHaveCount(1)
        ->and($directory->skipped[0]['message'])->toBe(ConnectionTokens::REAUTH_MESSAGE)
        ->and($directory->skipped[0]['trigger'])->toBe('webhook')
        ->and($jobs)->toBe([]);
});

test('a remote change is recorded as skipped and queued again when the refresh was temporary', function () {
    $client               = new TokenRecoveryClient();
    $client->refreshError = new QuickBooksException(503, 'unavailable');
    [$tokens, $stored]    = trcTokens($client, time() - 30);
    [$engine, $directory] = trcWebhookParts($client, $tokens, $stored);
    $settings             = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());

    $jobs = trcWithBus(function ($dispatcher) use ($engine, $directory, $settings, $tokens) {
        $job = new ApplyRemoteChange('company-uuid', [['entity' => 'Customer', 'id' => '1', 'operation' => 'Update']]);
        $job->handle($engine, $directory, $settings, new MemorySettingsStore(), $tokens);

        return $dispatcher->jobs;
    });

    expect($engine->calls)->toBe([])
        ->and($directory->skipped[0]['message'])->toBe(TokenRefresher::UNAVAILABLE_MESSAGE)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0])->toBeInstanceOf(ApplyRemoteChange::class)
        ->and($jobs[0]->lockAttempt)->toBe(1);
});

/**
 * One keyed payment (its link is stored under the QuickBooks payment id) linked to a
 * Fleetbase invoice, in an in-memory database. Runs the callback, then restores config.
 */
function trcWithPaymentTables(callable $callback): mixed
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
        DB::table('ledger_invoices')->insert(['uuid' => 'inv-1', 'company_uuid' => 'company-uuid', 'total_amount' => 1000, 'amount_paid' => 0, 'tax' => 0, 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-pay', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => '4', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);

        return $callback();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
}

/**
 * @return array<string, mixed>
 */
function trcPaymentSync(): array
{
    return ['payment_direction' => 'both', 'payment_conflict' => 'quickbooks', 'invoice_direction' => 'both'];
}

test('the webhook request does not call quickbooks and leaves a keyed payment to a queued job', function () {
    $client                = new TokenRecoveryClient();
    $client->payments['4'] = ['Id' => '4', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];
    $container             = Container::getInstance();
    $previousClient        = $container->bound(QuickBooksClient::class) === true ? $container->make(QuickBooksClient::class) : null;
    $container->instance(QuickBooksClient::class, $client);

    try {
        $jobs = trcWithPaymentTables(function () {
            return trcWithBus(function ($dispatcher) {
                $store                                  = new MemorySettingsStore();
                $store->rows[SettingsKeys::adminSync()] = trcPaymentSync();
                $listener                               = new EnqueueWebhookSync(new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()));
                $listener->handle(new QuickBooksEntityChanged('company-uuid', 'realm-1', 'payment', '4', 'update', '4'));
                $listener->flush($store);

                return $dispatcher->jobs;
            });
        });
    } finally {
        if ($previousClient !== null) {
            $container->instance(QuickBooksClient::class, $previousClient);
        } else {
            $container->forgetInstance(QuickBooksClient::class);
        }
    }

    expect($client->calls)->toBe([])
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0])->toBeInstanceOf(ResolveWebhookPayments::class)
        ->and($jobs[0]->companyUuid)->toBe('company-uuid')
        ->and($jobs[0]->events)->toBe([
            ['realm_id' => 'realm-1', 'entity_type' => 'payment', 'id' => '4', 'operation' => 'update', 'local_uuid' => '4'],
        ]);
});

test('a keyed payment delete is also left to the queued job and changes nothing in the request', function () {
    $client    = new TokenRecoveryClient();
    $container = Container::getInstance();
    $previous  = $container->bound(QuickBooksClient::class) === true ? $container->make(QuickBooksClient::class) : null;
    $container->instance(QuickBooksClient::class, $client);

    try {
        $result = trcWithPaymentTables(function () {
            return trcWithBus(function ($dispatcher) {
                $store                                  = new MemorySettingsStore();
                $store->rows[SettingsKeys::adminSync()] = trcPaymentSync();
                $listener                               = new EnqueueWebhookSync(new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()));
                $listener->handle(new QuickBooksEntityChanged('company-uuid', 'realm-1', 'payment', '4', 'delete', '4'));
                $listener->flush($store);

                return [$dispatcher->jobs, DB::table('quickbooks_links')->where('uuid', 'link-pay')->exists()];
            });
        });
    } finally {
        if ($previous !== null) {
            $container->instance(QuickBooksClient::class, $previous);
        } else {
            $container->forgetInstance(QuickBooksClient::class);
        }
    }

    expect($client->calls)->toBe([])
        ->and($result[0])->toHaveCount(1)
        ->and($result[0][0])->toBeInstanceOf(ResolveWebhookPayments::class)
        ->and($result[0][0]->events[0]['operation'])->toBe('delete')
        ->and($result[1])->toBeTrue();
});

test('the queued job reads the payment with a refreshed token and queues the usual follow-up', function () {
    $client                = new TokenRecoveryClient();
    $client->payments['4'] = ['Id' => '4', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];
    [$tokens, $stored]     = trcTokens($client, time() - 30);
    $container             = Container::getInstance();
    $previousClient        = $container->bound(QuickBooksClient::class) === true ? $container->make(QuickBooksClient::class) : null;
    $previousDirectory     = $container->bound(FleetbaseDirectory::class) === true ? $container->make(FleetbaseDirectory::class) : null;
    $container->instance(QuickBooksClient::class, $client);
    $container->instance(FleetbaseDirectory::class, $stored);
    $container->instance(ConnectionTokens::class, $tokens);

    try {
        $jobs = trcWithPaymentTables(function () use ($stored, $tokens) {
            return trcWithBus(function ($dispatcher) use ($stored, $tokens) {
                $store                                  = new MemorySettingsStore();
                $store->rows[SettingsKeys::adminSync()] = trcPaymentSync();
                $settings                               = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
                $job                                    = new ResolveWebhookPayments('company-uuid', [
                    ['realm_id' => 'realm-1', 'entity_type' => 'payment', 'id' => '4', 'operation' => 'update', 'local_uuid' => '4'],
                ]);
                $job->handle($settings, $store, $stored, $tokens);

                return $dispatcher->jobs;
            });
        });
    } finally {
        if ($previousClient !== null) {
            $container->instance(QuickBooksClient::class, $previousClient);
        } else {
            $container->forgetInstance(QuickBooksClient::class);
        }
        if ($previousDirectory !== null) {
            $container->instance(FleetbaseDirectory::class, $previousDirectory);
        } else {
            $container->forgetInstance(FleetbaseDirectory::class);
        }
        $container->forgetInstance(ConnectionTokens::class);
    }

    $kinds = array_map(static fn (object $job): string => $job::class, $jobs);

    expect($client->refreshes)->toBe(1)
        ->and($client->paymentTokens)->toBe(['new-access'])
        ->and($kinds)->toContain(SyncWebhookBatch::class)
        ->and($kinds)->toContain(ApplyRemoteChange::class);
});

test('the queued job refreshes and reads again when quickbooks rejects a token that looked valid', function () {
    $client                = new TokenRecoveryClient();
    $client->payments['4'] = ['Id' => '4', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];
    [$tokens, $stored]     = trcTokens($client);
    $container             = Container::getInstance();
    $previousClient        = $container->bound(QuickBooksClient::class) === true ? $container->make(QuickBooksClient::class) : null;
    $previousDirectory     = $container->bound(FleetbaseDirectory::class) === true ? $container->make(FleetbaseDirectory::class) : null;
    $container->instance(QuickBooksClient::class, $client);
    $container->instance(FleetbaseDirectory::class, $stored);
    $container->instance(ConnectionTokens::class, $tokens);

    try {
        $jobs = trcWithPaymentTables(function () use ($stored, $tokens) {
            return trcWithBus(function ($dispatcher) use ($stored, $tokens) {
                $store                                  = new MemorySettingsStore();
                $store->rows[SettingsKeys::adminSync()] = trcPaymentSync();
                $settings                               = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
                $job                                    = new ResolveWebhookPayments('company-uuid', [
                    ['realm_id' => 'realm-1', 'entity_type' => 'payment', 'id' => '4', 'operation' => 'update', 'local_uuid' => '4'],
                ]);
                $job->handle($settings, $store, $stored, $tokens);

                return $dispatcher->jobs;
            });
        });
    } finally {
        if ($previousClient !== null) {
            $container->instance(QuickBooksClient::class, $previousClient);
        } else {
            $container->forgetInstance(QuickBooksClient::class);
        }
        if ($previousDirectory !== null) {
            $container->instance(FleetbaseDirectory::class, $previousDirectory);
        } else {
            $container->forgetInstance(FleetbaseDirectory::class);
        }
        $container->forgetInstance(ConnectionTokens::class);
    }

    $kinds = array_map(static fn (object $job): string => $job::class, $jobs);

    expect($client->refreshes)->toBe(1)
        ->and($client->paymentTokens)->toBe(['old-access', 'new-access'])
        ->and($kinds)->toContain(SyncWebhookBatch::class);
});

test('the queued job records a skipped sync and reads nothing when the refresh token was refused', function () {
    $client                = new TokenRecoveryClient();
    $client->refreshError  = new QuickBooksException(400, 'invalid_grant');
    $client->payments['4'] = ['Id' => '4', 'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '10']]]]];
    [$tokens, $stored]     = trcTokens($client, time() - 30);
    $directory             = new class extends FleetbaseDirectory {
        /** @var array<int, string> */
        public array $messages = [];

        public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
        {
            $this->messages[] = $message;
        }
    };
    $directory->memory = $stored->memory;

    $jobs = trcWithBus(function ($dispatcher) use ($directory, $tokens) {
        $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
        $job      = new ResolveWebhookPayments('company-uuid', [
            ['realm_id' => 'realm-1', 'entity_type' => 'payment', 'id' => '4', 'operation' => 'update', 'local_uuid' => '4'],
        ]);
        $job->handle($settings, new MemorySettingsStore(), $directory, $tokens);

        return $dispatcher->jobs;
    });

    expect($client->paymentTokens)->toBe([])
        ->and($directory->messages)->toBe([ConnectionTokens::REAUTH_MESSAGE])
        ->and($jobs)->toBe([]);
});
