<?php

use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
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
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->lockCache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
});

afterEach(function () {
    Cache::swap($this->lockCache);
});

test('a token is due when its expiry is unknown or within five minutes', function () {
    $refresher = new TokenRefresher(new RefreshingQuickBooks());
    $now       = time();

    expect($refresher->isDue(['token_expires_at' => null], $now))->toBeTrue()
        ->and($refresher->isDue(['token_expires_at' => $now + 300], $now))->toBeTrue()
        ->and($refresher->isDue(['token_expires_at' => $now + 301], $now))->toBeFalse();
});

test('a due token is rotated and saved right away', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    $connection           = refreshLedger($directory, null);

    $fresh = $tokens->refreshIfDue($connection, time());

    expect($client->refreshes)->toBe(1)
        ->and($fresh['access_token'])->toBe('new-access')
        ->and($fresh['refresh_token'])->toBe('new-refresh')
        ->and($fresh['token_expires_at'])->toBeGreaterThan(time() + 3000)
        ->and($directory->memory->connections['company-uuid']['refresh_token'])->toBe('new-refresh');
});

test('a token that is not due is left alone', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    $connection           = refreshLedger($directory, time() + 3600);

    expect($tokens->refreshIfDue($connection, time()))->toBe($connection)
        ->and($client->refreshes)->toBe(0);
});

test('a refused refresh token marks the connection for reauth but an outage does not', function () {
    $refused              = new RefreshingQuickBooks(new QuickBooksException(400, 'invalid_grant'));
    [$tokens, $directory] = tokensWith($refused);
    $fresh                = $tokens->refreshIfDue(refreshLedger($directory, null), time());
    expect($fresh['needs_reauth'])->toBeTrue()
        ->and($directory->memory->connections['company-uuid']['needs_reauth'])->toBeTrue();

    $down                 = new RefreshingQuickBooks(new QuickBooksException(503, 'unavailable'));
    [$tokens, $directory] = tokensWith($down);
    $connection           = refreshLedger($directory, null);
    $returned             = $tokens->refreshIfDue($connection, time());
    expect($returned['needs_reauth'])->toBeFalse()
        ->and($returned['refresh_error'])->toBe(TokenRefresher::UNAVAILABLE_MESSAGE);
});

test('a batch is skipped with a reconnect message when the refresh token is refused', function () {
    $client               = new RefreshingQuickBooks(new QuickBooksException(400, 'invalid_grant'));
    [$tokens, $directory] = tokensWith($client);
    refreshLedger($directory, null);

    $batch = batchRunner($directory, $tokens, $client)->run('company-uuid', 'now');

    expect($batch['status'])->toBe('skipped')
        ->and($directory->memory->connections['company-uuid']['needs_reauth'])->toBeTrue()
        ->and($directory->memory->batches)->toHaveCount(1)
        ->and($directory->memory->attempts[0]['error'])->toBe(ConnectionTokens::REAUTH_MESSAGE)
        ->and($client->calls)->toBe([]);
});

test('a busy lock does not send a second refresh', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    $connection           = refreshLedger($directory, null);
    $held                 = Cache::getFacadeRoot()->getStore()->lock('quickbooks.batch.company-uuid', BatchRunner::LOCK_SECONDS);
    expect($held->get())->toBeTrue();

    $fresh = $tokens->refreshIfDue($connection, time());
    $held->release();

    expect($client->refreshes)->toBe(0)
        ->and($fresh['refresh_error'])->toBe(ConnectionTokens::ALREADY_RUNNING)
        ->and($fresh['access_token'])->toBe('old-access');
});

test('a missing lock skips refresh instead of running unlocked', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    $connection           = refreshLedger($directory, null);
    $previous             = Cache::getFacadeRoot();
    Cache::swap(new class {
    });

    try {
        $fresh = $tokens->refreshIfDue($connection, time());
    } finally {
        Cache::swap($previous);
    }

    expect($client->refreshes)->toBe(0)
        ->and($fresh['access_token'])->toBe('old-access')
        ->and($fresh)->not->toHaveKey('refresh_error');
});

test('a refresh inside the company lock still rotates the token', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    $connection           = refreshLedger($directory, null);
    $lock                 = BatchRunner::lock('company-uuid');
    expect($lock)->not->toBeNull()
        ->and($lock->get())->toBeTrue();

    $fresh = $tokens->refreshIfDue($connection, time());
    $lock->release();

    expect($client->refreshes)->toBe(1)
        ->and($fresh['access_token'])->toBe('new-access');
});

test('a stored sync enabled flag does not skip a due scheduled sync', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    refreshLedger($directory, time() + 3600);
    $directory->memory->customers['cust-1']                 = [
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ada',
        'email'        => 'ada@example.test',
    ];
    $directory->memory->pending[]                           = [
        'company_uuid'    => 'company-uuid',
        'local_type'      => 'customer',
        'local_uuid'      => 'cust-1',
        'status'          => 'pending',
        'attempts'        => 0,
        'next_attempt_at' => null,
    ];
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('company-uuid')] = ['enabled' => false];

    $scheduled = withBatchDispatcher(fn () => batchRunner($directory, $tokens, $client, $store)->run('company-uuid'));

    expect($scheduled['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer');
});

test('a halted first catalog page preserves an empty cursor and does not stamp completion', function () {
    [$runner, $directory, $client] = catalogRunner();
    $client->failOnCreate          = 1;
    $client->failWith              = new QuickBooksException(429, 'slow down', '30');

    withBatchDispatcher(fn () => $runner->run('company-uuid', 'scheduled'));

    expect(Cache::get('quickbooks.customer-catalog-after.company-uuid'))->toBeNull()
        ->and($directory->customerCatalogStamp('company-uuid'))->toBeNull();
});

test('a successful scheduled first catalog page advances without stamping completion', function () {
    [$runner, $directory] = catalogRunner();

    withBatchDispatcher(fn () => $runner->run('company-uuid', 'scheduled'));

    expect(Cache::get('quickbooks.customer-catalog-after.company-uuid'))->toBe('cust-2')
        ->and($directory->customerCatalogStamp('company-uuid'))->toBeNull();
});

test('a halted middle catalog page preserves the previous cursor and does not stamp completion', function () {
    [$runner, $directory, $client] = catalogRunner();

    withBatchDispatcher(function () use ($runner, $client) {
        $runner->run('company-uuid', 'catalog');
        $client->failOnCreate = 3;
        $client->failWith     = new QuickBooksException(401, 'token rejected');
        $runner->run('company-uuid', 'catalog');
    });

    expect(Cache::get('quickbooks.customer-catalog-after.company-uuid'))->toBe('cust-2')
        ->and($directory->customerCatalogStamp('company-uuid'))->toBeNull();
});

test('a halted final catalog page preserves the previous cursor and does not stamp completion', function () {
    [$runner, $directory, $client] = catalogRunner();

    withBatchDispatcher(function () use ($runner, $client) {
        $runner->run('company-uuid', 'catalog');
        $runner->run('company-uuid', 'catalog');
        $client->failOnCreate = 5;
        $client->failWith     = new QuickBooksException(429, 'slow down', '30');
        $runner->run('company-uuid', 'catalog');
    });

    expect(Cache::get('quickbooks.customer-catalog-after.company-uuid'))->toBe('cust-4')
        ->and($directory->customerCatalogStamp('company-uuid'))->toBeNull();
});

test('a successful final catalog page clears the cursor and stamps completion', function () {
    [$runner, $directory] = catalogRunner();

    withBatchDispatcher(function () use ($runner) {
        $runner->run('company-uuid', 'catalog');
        $runner->run('company-uuid', 'catalog');
        $runner->run('company-uuid', 'catalog');
    });

    expect(Cache::get('quickbooks.customer-catalog-after.company-uuid'))->toBeNull()
        ->and($directory->customerCatalogStamp('company-uuid'))->toBeInt();
});

test('busy continuations retry three times with bounded delays while other triggers do not retry', function () {
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    refreshLedger($directory, time() + 3600);
    $runner = batchRunner($directory, $tokens, $client);
    $held   = Cache::getFacadeRoot()->getStore()->lock('quickbooks.batch.company-uuid', BatchRunner::LOCK_SECONDS);
    expect($held->get())->toBeTrue();

    try {
        withBatchDispatcher(function ($dispatcher) use ($runner) {
            (new SyncCompanyBatch('company-uuid', 'scheduled'))->handle($runner);
            (new SyncCompanyBatch('company-uuid', 'manual'))->handle($runner);

            expect($dispatcher->jobs)->toBe([]);
        });
        foreach (['drain', 'catalog'] as $trigger) {
            withBatchDispatcher(function ($dispatcher) use ($runner, $trigger) {
                (new SyncCompanyBatch('company-uuid', $trigger))->handle($runner);

                for ($index = 0; $index < SyncCompanyBatch::CONTINUATION_RETRY_LIMIT; $index++) {
                    $dispatcher->jobs[$index]->handle($runner);
                }

                expect($dispatcher->jobs)->toHaveCount(SyncCompanyBatch::CONTINUATION_RETRY_LIMIT)
                    ->and(array_map(fn (SyncCompanyBatch $job) => $job->trigger, $dispatcher->jobs))->toBe([$trigger, $trigger, $trigger])
                    ->and(array_map(fn (SyncCompanyBatch $job) => $job->continuationRetry, $dispatcher->jobs))->toBe([1, 2, 3])
                    ->and(array_map(fn (SyncCompanyBatch $job) => $job->delay, $dispatcher->jobs))->toBe([5, 10, 15]);
            });
        }
    } finally {
        $held->release();
    }
});

test('the company lock is held while the refresh token is sent to intuit', function () {
    $client                = new RefreshingQuickBooks();
    $secondCouldStart      = true;
    $client->duringRefresh = function () use (&$secondCouldStart): void {
        $probe            = Cache::getFacadeRoot()->getStore()->lock('quickbooks.batch.company-uuid', BatchRunner::LOCK_SECONDS);
        $secondCouldStart = $probe->get() === true;
        if ($secondCouldStart === true) {
            $probe->release();
        }
    };
    [$tokens, $directory] = tokensWith($client);
    $connection           = refreshLedger($directory, null);

    $fresh = $tokens->refreshIfDue($connection, time());

    expect($secondCouldStart)->toBeFalse()
        ->and($client->refreshes)->toBe(1)
        ->and($fresh['refresh_token'])->toBe('new-refresh')
        ->and($directory->memory->connections['company-uuid']['refresh_token'])->toBe('new-refresh');
});

test('a refresh token that changed during the intuit call is not overwritten', function () {
    $client                = new RefreshingQuickBooks();
    [$tokens, $directory]  = tokensWith($client);
    $connection            = refreshLedger($directory, null);
    $client->duringRefresh = function () use ($directory): void {
        $directory->memory->connections['company-uuid']['refresh_token'] = 'winner-refresh';
        $directory->memory->connections['company-uuid']['access_token']  = 'winner-access';
    };

    $fresh = $tokens->refreshIfDue($connection, time());

    expect($client->refreshes)->toBe(1)
        ->and($directory->memory->connections['company-uuid']['refresh_token'])->toBe('winner-refresh')
        ->and($directory->memory->connections['company-uuid']['access_token'])->toBe('winner-access')
        ->and($fresh['refresh_token'])->toBe('winner-refresh')
        ->and($fresh['access_token'])->toBe('winner-access');
});

test('a refresh token that was already rotated is not sent to intuit', function () {
    $client                                                          = new RefreshingQuickBooks();
    [$tokens, $directory]                                            = tokensWith($client);
    $stale                                                           = refreshLedger($directory, null);
    $directory->memory->connections['company-uuid']['refresh_token'] = 'already-new';
    $directory->memory->connections['company-uuid']['access_token']  = 'already-access';

    $fresh = $tokens->refreshIfDue($stale, time());

    expect($client->refreshes)->toBe(0)
        ->and($fresh['refresh_token'])->toBe('already-new')
        ->and($fresh['access_token'])->toBe('already-access')
        ->and($directory->memory->connections['company-uuid']['refresh_token'])->toBe('already-new');
});

class RefreshingQuickBooks extends FakeQuickBooks
{
    public int $refreshes = 0;

    /** @var callable|null */
    public $duringRefresh;

    public function __construct(private ?QuickBooksException $refreshError = null)
    {
    }

    public function refresh(array $credentials, string $refreshToken): array
    {
        $this->refreshes++;
        if ($this->duringRefresh !== null) {
            ($this->duringRefresh)($refreshToken);
        }
        if ($this->refreshError !== null) {
            throw $this->refreshError;
        }

        return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600];
    }
}

function tokensWith(FakeQuickBooks $client): array
{
    $directory         = new FleetbaseDirectory();
    $directory->memory = new SyncLedger();
    $tokens            = new ConnectionTokens(new TokenRefresher($client), refreshSettings(), new MemorySettingsStore(), $directory);

    return [$tokens, $directory];
}

function refreshSettings(): SettingsService
{
    return new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
}

function refreshLedger(FleetbaseDirectory $directory, ?int $expiresAt): array
{
    $directory->memory->connections['company-uuid'] = [
        'company_uuid'     => 'company-uuid',
        'realm_id'         => 'realm-1',
        'access_token'     => 'old-access',
        'refresh_token'    => 'old-refresh',
        'token_expires_at' => $expiresAt,
        'needs_reauth'     => false,
        'last_batch_at'    => null,
    ];

    return $directory->memory->connections['company-uuid'];
}

function batchRunner(FleetbaseDirectory $directory, ConnectionTokens $tokens, FakeQuickBooks $client, ?MemorySettingsStore $store = null): BatchRunner
{
    [$engine] = qbEngine($client);

    return new BatchRunner($engine, $directory, refreshSettings(), $store ?? new MemorySettingsStore(), $tokens);
}

function catalogRunner(): array
{
    $client               = new RefreshingQuickBooks();
    [$tokens, $directory] = tokensWith($client);
    refreshLedger($directory, time() + 3600);
    foreach (range(1, 5) as $number) {
        $uuid                                = 'cust-' . $number;
        $directory->memory->customers[$uuid] = [
            'uuid'         => $uuid,
            'company_uuid' => 'company-uuid',
            'name'         => 'Customer ' . $number,
            'email'        => 'customer-' . $number . '@example.test',
        ];
    }
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()]                 = ['batch_size' => 2, 'periodic_interval_hours' => 24];

    return [batchRunner($directory, $tokens, $client, $store), $directory, $client];
}

/**
 * @template T
 *
 * @param callable(object): T $callback
 *
 * @return T
 */
function withBatchDispatcher(callable $callback): mixed
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
