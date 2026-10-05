<?php

use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
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
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->previousCache     = Cache::getFacadeRoot();
    $this->lockRepository    = new Repository(new ArrayStore());
    $container               = Container::getInstance();
    $this->previousCacheBind = $container->bound(CacheRepository::class) ? $container->make(CacheRepository::class) : null;
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

test('the sync job stops one margin before the company lock and still outlasts redis retry_after', function () {
    $job = new SyncCompanyBatch('company-uuid');

    expect(BatchRunner::jobTimeout())->toBe(BatchRunner::LOCK_SECONDS - BatchRunner::LOCK_MARGIN_SECONDS)
        ->and($job->timeout)->toBe(BatchRunner::jobTimeout())
        ->and($job->timeout)->toBeLessThan(BatchRunner::LOCK_SECONDS)
        ->and($job->timeout)->toBeGreaterThan(BatchRunner::REDIS_RETRY_AFTER_SECONDS)
        ->and($job->tries)->toBe(BatchRunner::SYNC_JOB_TRIES)
        ->and($job->maxExceptions)->toBe(1);
});

test('a redis redelivery is not failed while the sync is still running and an exception is not retried', function () {
    $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
    $worker->setCache(new Repository(new ArrayStore()));
    $attempts = new ReflectionMethod(Worker::class, 'markJobAsFailedIfAlreadyExceedsMaxAttempts');
    $errors   = new ReflectionMethod(Worker::class, 'markJobAsFailedIfWillExceedMaxExceptions');

    $redelivery = new BatchLockProbeJob(BatchRunner::SYNC_JOB_TRIES, BatchRunner::SYNC_JOB_TRIES, null);
    $attempts->invoke($worker, 'redis', $redelivery, 1);
    expect($redelivery->markedFailed)->toBeFalse();

    $over   = new BatchLockProbeJob(2, 1, null);
    $failed = false;
    try {
        $attempts->invoke($worker, 'redis', $over, 1);
    } catch (Throwable $exception) {
        $failed = true;
    }
    expect($failed)->toBeTrue()
        ->and($over->markedFailed)->toBeTrue();

    $thrown = new BatchLockProbeJob(1, BatchRunner::SYNC_JOB_TRIES, 1);
    $errors->invoke($worker, 'redis', $thrown, new RuntimeException('quickbooks failed'));
    expect($thrown->markedFailed)->toBeTrue();
});

test('the in-flight sync drops a redelivery and still lets a new follow-up run', function () {
    $same    = new BatchLockQueueJob('same-uuid');
    $running = new SyncCompanyBatch('company-uuid', 'drain');
    $running->setJob($same);
    $overlap = $running->middleware()[0];
    $held    = $this->lockRepository->lock($overlap->getLockKey($running), BatchRunner::LOCK_SECONDS);
    expect($held->get())->toBeTrue()
        ->and($overlap->releaseAfter)->toBeNull()
        ->and($overlap->expiresAfter)->toBe(BatchRunner::LOCK_SECONDS);

    $again    = false;
    $delivery = new SyncCompanyBatch('company-uuid', 'drain');
    $delivery->setJob($same);
    $delivery->middleware()[0]->handle($delivery, function () use (&$again) {
        $again = true;
    });

    $followUp = new SyncCompanyBatch('company-uuid', 'drain');
    $followUp->setJob(new BatchLockQueueJob('follow-up-uuid'));
    $followed = false;
    $followUp->middleware()[0]->handle($followUp, function () use (&$followed) {
        $followed = true;
    });

    expect($again)->toBeFalse()
        ->and($same->isReleased())->toBeFalse()
        ->and($same->hasFailed())->toBeFalse()
        ->and($followed)->toBeTrue()
        ->and($running->redeliveryKey())->toBe('same-uuid')
        ->and($followUp->redeliveryKey())->toBe('follow-up-uuid');

    $held->release();
});

test('quickbooks http stays outside the company lock and follow-up dispatch happens after release', function () {
    $client = new class extends FakeQuickBooks {
        public bool $heldDuringHttp = false;

        public function createCustomer(array $connection, array $payload): array
        {
            $this->heldDuringHttp = BatchRunner::holds('company-uuid');

            return parent::createCustomer($connection, $payload);
        }
    };
    $directory = new class extends FleetbaseDirectory {
        public bool $heldDuringLoad = false;

        public bool $heldDuringSave = false;

        public function loadPending(string $companyUuid, int $limit, int $now): ?array
        {
            $this->heldDuringLoad = BatchRunner::holds($companyUuid);

            return parent::loadPending($companyUuid, $limit, $now);
        }

        public function save(SyncLedger $ledger): void
        {
            $this->heldDuringSave = BatchRunner::holds('company-uuid');
            parent::save($ledger);
        }
    };
    $directory->memory                              = new SyncLedger();
    $now                                            = time();
    $directory->memory->connections['company-uuid'] = [
        'company_uuid'             => 'company-uuid',
        'realm_id'                 => 'realm-1',
        'access_token'             => 'access',
        'refresh_token'            => 'refresh',
        'token_expires_at'         => $now + 86400,
        'needs_reauth'             => false,
        'last_batch_at'            => $now,
        'last_customer_catalog_at' => $now,
        'home_currency'            => 'USD',
        'default_item_id'          => 'item-1',
    ];
    foreach (['cust-1', 'cust-2'] as $uuid) {
        $directory->memory->customers[$uuid] = [
            'uuid'         => $uuid,
            'company_uuid' => 'company-uuid',
            'name'         => 'Customer ' . $uuid,
            'email'        => $uuid . '@example.test',
        ];
        $directory->memory->pending[] = [
            'company_uuid'    => 'company-uuid',
            'local_type'      => 'customer',
            'local_uuid'      => $uuid,
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => null,
        ];
    }
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = ['batch_size' => 1];
    $settings                               = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $tokens                                 = new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine                                 = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );
    $runner = new BatchRunner($engine, $directory, $settings, $store, $tokens);

    $dispatcher = new class implements Dispatcher {
        /** @var array<int, bool> */
        public array $heldAtDispatch = [];

        /** @var array<int, mixed> */
        public array $jobs = [];

        public function dispatch($command)
        {
            $this->jobs[]           = $command;
            $this->heldAtDispatch[] = BatchRunner::holds('company-uuid');

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
        $batch = $runner->run('company-uuid', 'drain');
    } finally {
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }

    expect($batch['status'])->toBe('finished')
        ->and($directory->heldDuringLoad)->toBeTrue()
        ->and($client->heldDuringHttp)->toBeFalse()
        ->and($directory->heldDuringSave)->toBeTrue()
        ->and($client->calls)->toContain('createCustomer')
        ->and(BatchRunner::holds('company-uuid'))->toBeFalse()
        ->and($dispatcher->jobs)->toHaveCount(1)
        ->and($dispatcher->jobs[0])->toBeInstanceOf(SyncCompanyBatch::class)
        ->and($dispatcher->jobs[0]->trigger)->toBe('drain')
        ->and($dispatcher->heldAtDispatch)->toBe([false]);
});

test('a due token refresh keeps the company lock and the new token is saved under it', function () {
    $client = new class extends FakeQuickBooks {
        public ?bool $heldDuringRefresh = null;

        public ?bool $secondCouldStart = null;

        public function refresh(array $credentials, string $refreshToken): array
        {
            $this->heldDuringRefresh = BatchRunner::holds('company-uuid');
            $probe                   = Cache::getFacadeRoot()->getStore()->lock('quickbooks.batch.company-uuid', BatchRunner::LOCK_SECONDS);
            $this->secondCouldStart  = $probe->get() === true;
            if ($this->secondCouldStart) {
                $probe->release();
            }

            return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600];
        }
    };
    $directory = new class extends FleetbaseDirectory {
        public ?bool $heldDuringTokenSave = null;

        public function saveConnection(array $connection): void
        {
            $this->heldDuringTokenSave = BatchRunner::holds((string) ($connection['company_uuid'] ?? ''));
            parent::saveConnection($connection);
        }
    };
    $directory->memory                              = new SyncLedger();
    $directory->memory->connections['company-uuid'] = [
        'company_uuid'             => 'company-uuid',
        'realm_id'                 => 'realm-1',
        'access_token'             => 'old-access',
        'refresh_token'            => 'old-refresh',
        'token_expires_at'         => null,
        'needs_reauth'             => false,
        'last_batch_at'            => time(),
        'last_customer_catalog_at' => time(),
        'home_currency'            => 'USD',
        'default_item_id'          => 'item-1',
    ];
    $directory->memory->customers['cust-1'] = [
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Customer cust-1',
        'email'        => 'cust-1@example.test',
    ];
    $directory->memory->pending[] = [
        'company_uuid'    => 'company-uuid',
        'local_type'      => 'customer',
        'local_uuid'      => 'cust-1',
        'status'          => 'pending',
        'attempts'        => 0,
        'next_attempt_at' => null,
    ];
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = ['batch_size' => 1];
    $settings                               = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $tokens                                 = new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine                                 = new SyncEngine($client, new CustomerMapper(), new InvoiceMapper(), new WalletMapper(), new BackoffPolicy(static fn (int $wait): int => $wait));
    $runner                                 = new BatchRunner($engine, $directory, $settings, $store, $tokens);

    $batch = $runner->run('company-uuid', 'drain');

    expect($batch['status'])->toBe('finished')
        ->and($client->heldDuringRefresh)->toBeTrue()
        ->and($client->secondCouldStart)->toBeFalse()
        ->and($directory->heldDuringTokenSave)->toBeTrue()
        ->and($directory->memory->connections['company-uuid']['access_token'])->toBe('new-access')
        ->and(BatchRunner::holds('company-uuid'))->toBeFalse();
});

class BatchLockQueueJob extends Job implements QueueJob
{
    public function __construct(private string $id)
    {
    }

    public function getJobId()
    {
        return $this->id;
    }

    public function getRawBody()
    {
        return json_encode([
            'uuid' => $this->id,
            'job'  => 'Illuminate\Queue\CallQueuedHandler@call',
        ]);
    }

    public function attempts()
    {
        return 2;
    }
}

class BatchLockProbeJob extends Job
{
    public bool $markedFailed = false;

    public function __construct(
        private int $attemptCount,
        private int $allowedTries,
        private ?int $exceptionLimit,
    ) {
    }

    public function getJobId()
    {
        return 'probe';
    }

    public function getRawBody()
    {
        return json_encode([
            'uuid' => 'probe-uuid',
            'job'  => 'Illuminate\Queue\CallQueuedHandler@call',
        ]);
    }

    public function attempts()
    {
        return $this->attemptCount;
    }

    public function maxTries()
    {
        return $this->allowedTries;
    }

    public function maxExceptions()
    {
        return $this->exceptionLimit;
    }

    public function retryUntil()
    {
        return null;
    }

    public function fail($e = null)
    {
        $this->markedFailed = true;
    }
}
