<?php

/*
 * Characterization tests for BatchRunner::run(). They pin how the company lock is
 * released around QuickBooks HTTP and taken again for the save, that the ledger is
 * saved and claims released when the body throws, and when drain and catalog
 * follow-ups are dispatched.
 */

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
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->brcPreviousCache = Cache::getFacadeRoot();
    $this->brcRepository    = new Repository(new ArrayStore());
    $container              = Container::getInstance();
    $this->brcPreviousBind  = $container->bound(CacheRepository::class) === true ? $container->make(CacheRepository::class) : null;
    Cache::swap($this->brcRepository);
    $container->instance(CacheRepository::class, $this->brcRepository);
    $container->instance('cache', $this->brcRepository);
});

afterEach(function () {
    Cache::swap($this->brcPreviousCache);
    $container = Container::getInstance();
    if ($this->brcPreviousBind !== null) {
        $container->instance(CacheRepository::class, $this->brcPreviousBind);
    } else {
        $container->forgetInstance(CacheRepository::class);
    }
});

const BRC_COMPANY = 'brc-company';

/**
 * Records each directory call with whether this process held the company lock at that moment.
 */
class BrcDirectory extends FleetbaseDirectory
{
    /** @var array<int, string> */
    public array $events = [];

    /** @var array<int, string> */
    public array $claimReturns = [];

    /** @var array<int, string> */
    public array $claimAfter = [];

    public ?Throwable $saveThrows = null;

    private function brcNote(string $name): void
    {
        $this->events[] = $name . ':' . (BatchRunner::holds(BRC_COMPANY) === true ? 'held' : 'free');
    }

    public function loadPending(string $companyUuid, int $limit, int $now): ?array
    {
        $this->brcNote('loadPending');

        return parent::loadPending($companyUuid, $limit, $now);
    }

    public function loadCustomerBlock(string $companyUuid, array $uuids): ?array
    {
        $this->brcNote('loadCustomerBlock');

        return parent::loadCustomerBlock($companyUuid, $uuids);
    }

    public function save(SyncLedger $ledger): void
    {
        $this->brcNote('save');
        if ($this->saveThrows !== null) {
            throw $this->saveThrows;
        }
        parent::save($ledger);
    }

    public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
    {
        $this->brcNote('saveSkipped');
        parent::saveSkipped($companyUuid, $trigger, $direction, $message);
    }

    public function releaseClaims(): void
    {
        $this->brcNote('releaseClaims');
        parent::releaseClaims();
    }

    public function hasDuePending(string $companyUuid, int $now): bool
    {
        $this->brcNote('hasDuePending');

        return parent::hasDuePending($companyUuid, $now);
    }

    public function claimInScopeInvoices(string $companyUuid, int $limit, string $afterUuid = ''): string
    {
        $this->brcNote('claimInScopeInvoices');
        $this->claimAfter[] = $afterUuid;

        return array_shift($this->claimReturns) ?? '';
    }
}

/**
 * Replaces the engine's sync work with closures, so the runner's own control flow is what is tested.
 */
class BrcEngine extends SyncEngine
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<int, array<int, string>> */
    public array $entityPages = [];

    public ?Closure $work = null;

    public bool $refreshFailedFlag = false;

    /** @var array<int, string> */
    public array $httpLock = [];

    public function runScheduled(SyncLedger $ledger, string $companyUuid, array $settings, int $now, bool $force = false, bool $ignoreInterval = false): array
    {
        $this->calls[] = 'runScheduled:' . ($force === true ? 'force' : 'normal') . ':' . ($ignoreInterval === true ? 'ignore' : 'interval');

        return $this->brcWork($ledger, $force === true ? 'now' : 'scheduled');
    }

    public function reconcile(SyncLedger $ledger, string $companyUuid, array $settings, int $now): array
    {
        $this->calls[] = 'reconcile';

        return $this->brcWork($ledger, 'manual');
    }

    public function syncEntities(SyncLedger $ledger, string $companyUuid, array $rows, array $settings, int $now): array
    {
        $ids                 = array_map(static fn (array $row): string => (string) $row['local_uuid'], $rows);
        $this->calls[]       = 'syncEntities';
        $this->entityPages[] = $ids;

        return $this->brcWork($ledger, 'catalog', count($ids));
    }

    public function refreshFailed(string $companyUuid): bool
    {
        return $this->refreshFailedFlag;
    }

    /**
     * @return array<string, mixed>
     */
    private function brcWork(SyncLedger $ledger, string $trigger, int $count = 1): array
    {
        $this->httpLock[] = 'before:' . (BatchRunner::holds(BRC_COMPANY) === true ? 'held' : 'free');
        $this->runHttp(function (): void {
            $this->httpLock[] = 'during:' . (BatchRunner::holds(BRC_COMPANY) === true ? 'held' : 'free');
        });
        $this->httpLock[] = 'after:' . (BatchRunner::holds(BRC_COMPANY) === true ? 'held' : 'free');
        if ($this->work !== null) {
            return ($this->work)($ledger, $trigger, $count);
        }
        $batch             = ['trigger' => $trigger, 'status' => 'finished', 'created' => $count, 'updated' => 0, 'aligned' => 0, 'failed' => 0, 'skipped' => 0];
        $ledger->batches[] = $batch;

        return $batch;
    }
}

/**
 * Blocks every connection as if Intuit refused the refresh for now.
 */
class BrcBlockedTokens extends ConnectionTokens
{
    public int $refreshes = 0;

    public function __construct()
    {
    }

    public function refreshIfDue(array $connection, int $now): array
    {
        $this->refreshes++;
        $connection['refresh_error']    = 'QuickBooks token refresh failed.';
        $connection['token_expires_at'] = null;

        return $connection;
    }
}

class BrcDispatcher implements Dispatcher
{
    /** @var array<int, mixed> */
    public array $jobs = [];

    /** @var array<int, bool> */
    public array $heldAtDispatch = [];

    public function dispatch($command)
    {
        $this->jobs[]           = $command;
        $this->heldAtDispatch[] = BatchRunner::holds(BRC_COMPANY);

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
}

/**
 * @param array<string, mixed> $connection
 * @param array<int, string>   $pendingUuids
 * @param array<string, mixed> $sync
 *
 * @return array{runner: BatchRunner, directory: BrcDirectory, engine: BrcEngine}
 */
function brcRunner(array $connection = [], array $pendingUuids = ['cust-1'], array $sync = [], ?ConnectionTokens $tokens = null, array $customers = []): array
{
    $client            = new FakeQuickBooks();
    $directory         = new BrcDirectory();
    $directory->memory = new SyncLedger();
    $now               = time();

    $directory->memory->connections[BRC_COMPANY] = array_merge([
        'company_uuid'             => BRC_COMPANY,
        'realm_id'                 => 'realm-1',
        'access_token'             => 'access',
        'refresh_token'            => 'refresh',
        'token_expires_at'         => $now + 86400,
        'needs_reauth'             => false,
        'last_batch_at'            => $now - 3600,
        'last_customer_catalog_at' => $now,
        'home_currency'            => 'USD',
        'default_item_id'          => 'item-1',
    ], $connection);
    foreach ($pendingUuids as $uuid) {
        $directory->memory->customers[$uuid] = ['uuid' => $uuid, 'company_uuid' => BRC_COMPANY, 'name' => 'Customer ' . $uuid];
        $directory->memory->pending[]        = [
            'company_uuid'    => BRC_COMPANY,
            'local_type'      => 'customer',
            'local_uuid'      => $uuid,
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => null,
        ];
    }
    foreach ($customers as $uuid) {
        $directory->memory->customers[$uuid] = ['uuid' => $uuid, 'company_uuid' => BRC_COMPANY, 'name' => 'Customer ' . $uuid];
    }

    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = array_merge(['batch_size' => 10, 'interval_minutes' => 5], $sync);
    $settings                               = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $tokens ??= new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine = new BrcEngine($client, new CustomerMapper(), new InvoiceMapper(), new WalletMapper(), new BackoffPolicy(static fn (int $wait): int => $wait));

    return [
        'runner'    => new BatchRunner($engine, $directory, $settings, $store, $tokens),
        'directory' => $directory,
        'engine'    => $engine,
    ];
}

/**
 * @return array{result: mixed, error: ?Throwable, dispatcher: BrcDispatcher}
 */
function brcRun(BatchRunner $runner, string $trigger): array
{
    $dispatcher = new BrcDispatcher();
    $container  = Container::getInstance();
    $previous   = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
    $container->instance(Dispatcher::class, $dispatcher);
    $result = null;
    $error  = null;
    try {
        $result = $runner->run(BRC_COMPANY, $trigger);
    } catch (Throwable $exception) {
        $error = $exception;
    } finally {
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }

    return ['result' => $result, 'error' => $error, 'dispatcher' => $dispatcher];
}

/**
 * @return array<int, string>
 */
function brcDispatched(BrcDispatcher $dispatcher): array
{
    return array_map(static fn ($job): string => $job instanceof SyncCompanyBatch ? 'batch:' . $job->trigger : get_class($job), $dispatcher->jobs);
}

function brcLockFree(): bool
{
    $probe = Cache::getFacadeRoot()->getStore()->lock('quickbooks.batch.' . BRC_COMPANY, 10);
    if ($probe->get() === true) {
        $probe->release();

        return true;
    }

    return false;
}

test('a sync now run holds the lock to load, drops it for token refresh and quickbooks http, and takes it again only to save', function () {
    $built = brcRunner();
    $run   = brcRun($built['runner'], 'now');

    expect($run['error'])->toBeNull()
        ->and($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['runScheduled:force:interval'])
        ->and($built['engine']->httpLock)->toBe(['before:free', 'during:free', 'after:free'])
        ->and($built['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(brcDispatched($run['dispatcher']))->toBe([])
        ->and(BatchRunner::holds(BRC_COMPANY))->toBeFalse()
        ->and(brcLockFree())->toBeTrue();
});

test('when the engine throws the ledger is still saved under the lock, claims are released and nothing is dispatched', function () {
    $built                  = brcRunner([], ['cust-1', 'cust-2']);
    $built['engine']->work  = function (SyncLedger $ledger): array {
        $ledger->links[] = ['company_uuid' => BRC_COMPANY, 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'qbo_entity' => 'Customer', 'qbo_id' => '77'];

        throw new RuntimeException('quickbooks exploded');
    };

    $run = brcRun($built['runner'], 'drain');

    expect($run['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($run['error']->getMessage())->toBe('quickbooks exploded')
        ->and($built['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(array_column($built['directory']->memory->links, 'qbo_id'))->toBe(['77'])
        ->and(brcDispatched($run['dispatcher']))->toBe([])
        ->and(BatchRunner::holds(BRC_COMPANY))->toBeFalse()
        ->and(brcLockFree())->toBeTrue();
});

test('when the save throws the claims are released by the recovery, the lock is still released'
. ' and nothing is dispatched', function () {
    $built                            = brcRunner([], ['cust-1', 'cust-2']);
    $built['directory']->saveThrows   = new RuntimeException('database down');

    $run = brcRun($built['runner'], 'drain');

    expect($run['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($run['error']->getMessage())->toBe('database down')
        ->and($built['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(brcDispatched($run['dispatcher']))->toBe([])
        ->and(BatchRunner::holds(BRC_COMPANY))->toBeFalse()
        ->and(brcLockFree())->toBeTrue();
});

test('a finished drain with rows still due queues one drain after the lock is released', function () {
    $built = brcRunner([], ['cust-1', 'cust-2']);

    $run = brcRun($built['runner'], 'drain');

    expect($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['runScheduled:force:interval'])
        ->and($built['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held', 'hasDuePending:held'])
        ->and(brcDispatched($run['dispatcher']))->toBe(['batch:drain'])
        ->and($run['dispatcher']->jobs[0]->companyUuid)->toBe(BRC_COMPANY)
        ->and($run['dispatcher']->heldAtDispatch)->toBe([false]);
});

test('a drain follow-up needs a finished batch, rows still due, and no failed token refresh', function () {
    $nothingDue                  = brcRunner();
    $nothingDue['engine']->work  = function (SyncLedger $ledger): array {
        $ledger->pending[0]['status'] = 'done';
        $batch                        = ['trigger' => 'now', 'status' => 'finished'];
        $ledger->batches[]            = $batch;

        return $batch;
    };
    $notFinished                 = brcRunner([], ['cust-1', 'cust-2']);
    $notFinished['engine']->work = function (SyncLedger $ledger): array {
        $batch             = ['trigger' => 'now', 'status' => 'halted'];
        $ledger->batches[] = $batch;

        return $batch;
    };
    $refreshFailed                              = brcRunner([], ['cust-1', 'cust-2']);
    $refreshFailed['engine']->refreshFailedFlag = true;
    $syncNow                                    = brcRunner([], ['cust-1', 'cust-2']);

    $nothingDueRun    = brcRun($nothingDue['runner'], 'drain');
    $notFinishedRun   = brcRun($notFinished['runner'], 'drain');
    $refreshFailedRun = brcRun($refreshFailed['runner'], 'drain');
    $syncNowRun       = brcRun($syncNow['runner'], 'now');

    expect(brcDispatched($nothingDueRun['dispatcher']))->toBe([])
        ->and($nothingDue['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held', 'hasDuePending:held'])
        ->and(brcDispatched($notFinishedRun['dispatcher']))->toBe([])
        ->and($notFinished['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(brcDispatched($refreshFailedRun['dispatcher']))->toBe([])
        ->and($refreshFailed['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(brcDispatched($syncNowRun['dispatcher']))->toBe([])
        ->and($syncNow['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held']);
});

test('a skipped batch that recorded nothing is not saved, one that recorded a batch is', function () {
    $quiet                   = brcRunner([], ['cust-1', 'cust-2']);
    $quiet['engine']->work   = static fn (): array => ['trigger' => 'now', 'status' => 'skipped'];
    $logged                  = brcRunner([], ['cust-1', 'cust-2']);
    $logged['engine']->work  = function (SyncLedger $ledger): array {
        $batch             = ['trigger' => 'now', 'status' => 'skipped', 'message' => 'rate limited'];
        $ledger->batches[] = $batch;

        return $batch;
    };

    $quietRun  = brcRun($quiet['runner'], 'drain');
    $loggedRun = brcRun($logged['runner'], 'drain');

    expect($quietRun['result']['status'])->toBe('skipped')
        ->and($quiet['directory']->events)->toBe(['loadPending:held', 'releaseClaims:free'])
        ->and(brcDispatched($quietRun['dispatcher']))->toBe([])
        ->and($loggedRun['result']['status'])->toBe('skipped')
        ->and($logged['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(brcDispatched($loggedRun['dispatcher']))->toBe([])
        ->and(brcLockFree())->toBeTrue();
});

test('a manual reconcile claims a page first and claims the next page under the lock when it finished', function () {
    $built                              = brcRunner([], ['cust-1', 'cust-2'], ['batch_size' => 3]);
    $built['directory']->claimReturns   = ['inv-5', 'inv-9'];

    $run = brcRun($built['runner'], 'manual');

    expect($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['reconcile'])
        ->and($built['directory']->claimAfter)->toBe(['', 'inv-5'])
        ->and($built['directory']->events)->toBe([
            'claimInScopeInvoices:held',
            'loadPending:held',
            'save:held',
            'releaseClaims:held',
            'claimInScopeInvoices:held',
            'hasDuePending:held',
        ])
        ->and(Cache::get('quickbooks.reconcile-after.' . BRC_COMPANY))->toBe('inv-9')
        ->and(Cache::get('quickbooks.reconcile-open.' . BRC_COMPANY))->toBeTrue()
        ->and(brcDispatched($run['dispatcher']))->toBe(['batch:drain'])
        ->and($run['dispatcher']->heldAtDispatch)->toBe([false]);
});

test('a manual reconcile whose next claim is empty clears the reconcile cursor', function () {
    $built                              = brcRunner([], ['cust-1']);
    $built['directory']->claimReturns   = ['inv-5', ''];
    $built['engine']->work              = function (SyncLedger $ledger): array {
        $ledger->pending[0]['status'] = 'done';
        $batch                        = ['trigger' => 'manual', 'status' => 'finished'];
        $ledger->batches[]            = $batch;

        return $batch;
    };

    $run = brcRun($built['runner'], 'manual');

    expect($built['directory']->claimAfter)->toBe(['', 'inv-5'])
        ->and(Cache::get('quickbooks.reconcile-after.' . BRC_COMPANY))->toBeNull()
        ->and(Cache::get('quickbooks.reconcile-open.' . BRC_COMPANY))->toBeNull()
        ->and(brcDispatched($run['dispatcher']))->toBe([]);
});

test('a drain does not claim a reconcile page unless a reconcile is open', function () {
    $closed                              = brcRunner([], ['cust-1', 'cust-2']);
    $closed['directory']->claimReturns   = ['inv-1'];
    $open                                = brcRunner([], ['cust-1', 'cust-2']);
    $open['directory']->claimReturns     = ['inv-2'];

    $closedRun = brcRun($closed['runner'], 'drain');
    Cache::put('quickbooks.reconcile-open.' . BRC_COMPANY, true, 3600);
    Cache::put('quickbooks.reconcile-after.' . BRC_COMPANY, 'inv-1', 3600);
    $openRun = brcRun($open['runner'], 'drain');

    expect($closed['directory']->claimAfter)->toBe([])
        ->and(brcDispatched($closedRun['dispatcher']))->toBe(['batch:drain'])
        ->and($open['directory']->claimAfter)->toBe(['inv-1'])
        ->and(Cache::get('quickbooks.reconcile-after.' . BRC_COMPANY))->toBe('inv-2')
        ->and(brcDispatched($openRun['dispatcher']))->toBe(['batch:drain']);
});

test('a token refresh that is blocked records the skip under the lock and runs nothing', function () {
    $tokens = new BrcBlockedTokens();
    $built  = brcRunner([], ['cust-1', 'cust-2'], [], $tokens);

    $run = brcRun($built['runner'], 'drain');

    expect($run['result'])->toBe(['trigger' => 'drain', 'status' => 'skipped'])
        ->and($tokens->refreshes)->toBe(1)
        ->and($built['engine']->calls)->toBe([])
        ->and($built['directory']->events)->toBe(['loadPending:held', 'saveSkipped:held', 'save:held', 'releaseClaims:held'])
        ->and($built['directory']->memory->attempts[0]['error'])->toBe('QuickBooks token refresh failed.')
        ->and($built['directory']->memory->batches[0]['trigger'])->toBe('drain')
        ->and($built['directory']->memory->batches[0]['direction'])->toBe('outbound')
        ->and(brcDispatched($run['dispatcher']))->toBe([])
        ->and(brcLockFree())->toBeTrue();
});

test('a busy company lock skips the run and records it only for a non scheduled trigger', function () {
    $now       = brcRunner();
    $scheduled = brcRunner();
    $held      = Cache::getFacadeRoot()->getStore()->lock('quickbooks.batch.' . BRC_COMPANY, 600);
    expect($held->get())->toBeTrue();

    try {
        $nowRun       = brcRun($now['runner'], 'now');
        $scheduledRun = brcRun($scheduled['runner'], 'scheduled');
    } finally {
        $held->release();
    }

    expect($nowRun['result'])->toBe(['trigger' => 'now', 'status' => 'skipped', 'reason' => 'busy'])
        ->and(qbText($now['directory']->memory->attempts[0]['error']))->toBe('Another QuickBooks sync is already '
            . 'running.')
        ->and($now['directory']->events)->toBe(['saveSkipped:free', 'save:free'])
        ->and($now['engine']->calls)->toBe([])
        ->and($scheduledRun['result'])->toBe(['trigger' => 'scheduled', 'status' => 'skipped', 'reason' => 'busy'])
        ->and($scheduled['directory']->events)->toBe([])
        ->and(brcDispatched($nowRun['dispatcher']))->toBe([])
        ->and(brcDispatched($scheduledRun['dispatcher']))->toBe([]);
});

test('a scheduled run with nothing due and no catalog due does not take the lock', function () {
    $built = brcRunner(['last_batch_at' => time()], []);

    $run = brcRun($built['runner'], 'scheduled');

    expect($run['result'])->toBe(['trigger' => 'scheduled', 'status' => 'skipped'])
        ->and($built['directory']->events)->toBe([])
        ->and($built['engine']->calls)->toBe([]);
});

test('a due scheduled run keeps the interval check and queues the catalog when the catalog is also due', function () {
    $built = brcRunner(['last_customer_catalog_at' => null], ['cust-1']);

    $run = brcRun($built['runner'], 'scheduled');

    expect($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['runScheduled:normal:interval'])
        ->and($built['directory']->events)->toBe(['loadPending:held', 'save:held', 'releaseClaims:held'])
        ->and(brcDispatched($run['dispatcher']))->toBe(['batch:catalog'])
        ->and($run['dispatcher']->heldAtDispatch)->toBe([false]);
});

test('a scheduled run started early by a full update batch skips the interval wait and queues no drain', function () {
    $uuids = [];
    for ($index = 1; $index <= 20; $index++) {
        $uuids[] = 'cust-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }
    $built = brcRunner(['last_batch_at' => time()], $uuids);

    $run = brcRun($built['runner'], 'scheduled');

    expect($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['runScheduled:normal:ignore'])
        ->and(brcDispatched($run['dispatcher']))->toBe([]);
});

test('a now run that fills its batch dispatches a drain', function () {
    $uuids = [];
    for ($index = 1; $index <= 150; $index++) {
        $uuids[] = 'cust-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
    }
    $built = brcRunner([], $uuids, ['batch_size' => 100]);

    $run = brcRun($built['runner'], 'now');

    expect($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['runScheduled:force:interval'])
        ->and(brcDispatched($run['dispatcher']))->toBe(['batch:drain'])
        ->and($run['dispatcher']->heldAtDispatch)->toBe([false]);
});

test('a scheduled run with only the catalog due runs one catalog page and does not queue another catalog', function () {
    $built = brcRunner(['last_batch_at' => time(), 'last_customer_catalog_at' => null], [], ['batch_size' => 5], null, ['c-1', 'c-2']);

    $run = brcRun($built['runner'], 'scheduled');

    expect($run['result']['status'])->toBe('finished')
        ->and($built['engine']->calls)->toBe(['syncEntities'])
        ->and($built['engine']->entityPages)->toBe([['c-1', 'c-2']])
        ->and($built['directory']->memory->connections[BRC_COMPANY]['last_customer_catalog_at'])->toBeInt()
        ->and(brcDispatched($run['dispatcher']))->toBe([]);
});

test('the catalog walks pages with a cursor and queues the next page after release', function () {
    $built = brcRunner([], [], ['batch_size' => 2], null, ['c-1', 'c-2', 'c-3']);

    $first = brcRun($built['runner'], 'catalog');

    expect($first['result']['status'])->toBe('finished')
        ->and($built['engine']->entityPages)->toBe([['c-1', 'c-2']])
        ->and($built['engine']->httpLock)->toBe(['before:free', 'during:free', 'after:free'])
        ->and($built['directory']->events)->toBe(['loadCustomerBlock:held', 'save:held', 'releaseClaims:held'])
        ->and(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBe('c-2')
        ->and(Cache::get('quickbooks.customer-catalog-lease.' . BRC_COMPANY))->toBeNull()
        ->and(brcDispatched($first['dispatcher']))->toBe(['batch:catalog'])
        ->and($first['dispatcher']->heldAtDispatch)->toBe([false]);

    $stampBefore = $built['directory']->memory->connections[BRC_COMPANY]['last_customer_catalog_at'];
    $second      = brcRun($built['runner'], 'catalog');

    expect($built['engine']->entityPages)->toBe([['c-1', 'c-2'], ['c-3']])
        ->and(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBeNull()
        ->and($built['directory']->memory->connections[BRC_COMPANY]['last_customer_catalog_at'])->toBeGreaterThanOrEqual($stampBefore)
        ->and(brcDispatched($second['dispatcher']))->toBe([]);

    $third = brcRun($built['runner'], 'catalog');

    // The cursor was cleared, so the next catalog run starts again from the first page.
    expect($third['result']['created'])->toBe(2)
        ->and($built['engine']->entityPages)->toBe([['c-1', 'c-2'], ['c-3'], ['c-1', 'c-2']])
        ->and(brcDispatched($third['dispatcher']))->toBe(['batch:catalog']);
});

test('a catalog page with a failure keeps the cursor, is still saved, and queues nothing', function () {
    $built                 = brcRunner([], [], ['batch_size' => 2], null, ['c-1', 'c-2', 'c-3']);
    $built['engine']->work = function (SyncLedger $ledger, string $trigger, int $count): array {
        return ['trigger' => 'catalog', 'status' => 'finished', 'created' => $count - 1, 'updated' => 0, 'aligned' => 0, 'failed' => 1, 'skipped' => 0];
    };
    Cache::put('quickbooks.customer-catalog-after.' . BRC_COMPANY, 'c-0', 3600);

    $run = brcRun($built['runner'], 'catalog');

    expect($run['result']['failed'])->toBe(1)
        ->and($built['directory']->events)->toBe(['loadCustomerBlock:held', 'save:held', 'releaseClaims:held'])
        ->and(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBe('c-0')
        ->and(brcDispatched($run['dispatcher']))->toBe([]);
});

test('a catalog page with a skipped row still commits the cursor and queues the next page', function () {
    $built                 = brcRunner([], [], ['batch_size' => 2], null, ['c-1', 'c-2', 'c-3']);
    $built['engine']->work = function (SyncLedger $ledger, string $trigger, int $count): array {
        return ['trigger' => 'catalog', 'status' => 'finished', 'created' => $count - 1, 'updated' => 0, 'aligned' => 0, 'failed' => 0, 'skipped' => 1];
    };

    $run = brcRun($built['runner'], 'catalog');

    expect(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBe('c-2')
        ->and(brcDispatched($run['dispatcher']))->toBe(['batch:catalog']);
});

test('a catalog page whose engine throws is still saved under the lock and drops the lease', function () {
    $built                 = brcRunner([], [], ['batch_size' => 2], null, ['c-1', 'c-2', 'c-3']);
    $built['engine']->work = function (): array {
        throw new RuntimeException('catalog exploded');
    };

    $run = brcRun($built['runner'], 'catalog');

    expect($run['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($built['directory']->events)->toBe(['loadCustomerBlock:held', 'save:held', 'releaseClaims:held'])
        ->and(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBeNull()
        ->and(Cache::get('quickbooks.customer-catalog-lease.' . BRC_COMPANY))->toBeNull()
        ->and(brcDispatched($run['dispatcher']))->toBe([])
        ->and(brcLockFree())->toBeTrue();
});

test('a catalog run while another holds the catalog lease is skipped as busy', function () {
    $built = brcRunner([], [], ['batch_size' => 2], null, ['c-1']);
    Cache::put('quickbooks.customer-catalog-lease.' . BRC_COMPANY, true, 600);

    $run = brcRun($built['runner'], 'catalog');

    expect($run['result'])->toBe(['trigger' => 'catalog', 'status' => 'skipped', 'reason' => 'busy'])
        ->and($built['engine']->calls)->toBe([])
        ->and($built['directory']->events)->toBe(['releaseClaims:held'])
        ->and(Cache::get('quickbooks.customer-catalog-lease.' . BRC_COMPANY))->toBeTrue()
        ->and(brcDispatched($run['dispatcher']))->toBe([]);
});

test('a catalog run with customers turned off returns at once and forgets the catalog cursor', function () {
    $built = brcRunner([], [], ['customer_enabled' => false], null, ['c-1']);
    Cache::put('quickbooks.customer-catalog-after.' . BRC_COMPANY, 'c-0', 3600);

    $run = brcRun($built['runner'], 'catalog');

    expect($run['result'])->toBe(['trigger' => 'catalog', 'status' => 'skipped'])
        ->and($built['engine']->calls)->toBe([])
        ->and($built['directory']->events)->toBe(['releaseClaims:held'])
        ->and(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBeNull()
        ->and(brcDispatched($run['dispatcher']))->toBe([])
        ->and(brcLockFree())->toBeTrue();
});

test('a blocked token on a catalog page records the skip and keeps the cursor', function () {
    $tokens = new BrcBlockedTokens();
    $built  = brcRunner([], [], ['batch_size' => 2], $tokens, ['c-1', 'c-2', 'c-3']);

    $run = brcRun($built['runner'], 'catalog');

    expect($run['result'])->toBe(['trigger' => 'catalog', 'status' => 'skipped'])
        ->and($built['engine']->calls)->toBe([])
        ->and($built['directory']->events)->toBe(['loadCustomerBlock:held', 'saveSkipped:held', 'save:held', 'releaseClaims:held'])
        ->and(Cache::get('quickbooks.customer-catalog-after.' . BRC_COMPANY))->toBeNull()
        ->and(brcDispatched($run['dispatcher']))->toBe([]);
});
