<?php

use Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks;
use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\CustomerImporter;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('disconnected sync reconcile drain catalog and import do not record activity', function () {
    $ledger = new SyncLedger();
    $runner = gateRunner($ledger);
    foreach (['scheduled', 'now', 'manual', 'drain', 'catalog'] as $trigger) {
        $batch = $runner->run('company-uuid', $trigger);
        expect($batch['status'])->toBe('skipped')
            ->and($runner->isScheduledDue('company-uuid', time()))->toBeFalse();
    }

    $client = new FakeQuickBooks();
    (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client), gateDirectory($ledger));

    expect($ledger->batches)->toBe([])
        ->and($ledger->attempts)->toBe([])
        ->and($client->calls)->toBe([]);
});

test('the global connection gates sync and a missing connection writes no activity', function () {
    $now   = time();
    $empty = new SyncLedger();
    $idle  = gateRunner($empty);

    expect($idle->hasConnection('company-uuid'))->toBeFalse()
        ->and($idle->run('company-uuid', 'now')['status'])->toBe('skipped')
        ->and($empty->batches)->toBe([])
        ->and($empty->attempts)->toBe([]);

    $ledger                                = new SyncLedger();
    $ledger->connections['other-company']  = [
        'company_uuid'             => 'other-company',
        'realm_id'                 => 'realm-1',
        'access_token'             => 'access',
        'refresh_token'            => 'refresh',
        'token_expires_at'         => $now + 86400,
        'needs_reauth'             => false,
        'last_batch_at'            => $now - 400,
        'last_customer_catalog_at' => $now,
        'home_currency'            => 'USD',
        'default_item_id'          => 'item-1',
    ];
    $ledger->customers['cust-1'] = [
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ada',
        'email'        => 'ada@example.test',
    ];
    $ledger->pending[] = [
        'company_uuid'    => 'company-uuid',
        'local_type'      => 'customer',
        'local_uuid'      => 'cust-1',
        'status'          => 'pending',
        'attempts'        => 0,
        'next_attempt_at' => null,
    ];
    [$runner, $client] = gateRunnerWith($ledger, new MemorySettingsStore());

    expect($runner->hasConnection('company-uuid'))->toBeTrue();

    $batch = null;
    gateDispatch(function () use ($runner, &$batch) {
        $batch = $runner->run('company-uuid', 'now');
    });

    expect($batch['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer')
        ->and($ledger->batches)->not->toBe([])
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['company_uuid'])->toBe('company-uuid')
        ->and($ledger->pending[0]['company_uuid'])->toBe('company-uuid')
        ->and($ledger->pending[0]['status'])->toBe('done');
});

test('one connection schedules every organization with due pending rows', function () {
    expect(SyncQuickbooks::companiesToSchedule(['owner-company'], ['owner-company', 'other-company']))
        ->toEqualCanonicalizing(['owner-company', 'other-company'])
        ->and(SyncQuickbooks::companiesToSchedule(['owner-company', 'second-company'], ['other-company']))
        ->toEqualCanonicalizing(['owner-company', 'second-company'])
        ->and(SyncQuickbooks::companiesToSchedule([], ['other-company']))->toBe([]);
});

test('a connected organization still syncs when due even if sync enabled is stored false', function () {
    $now                         = time();
    $ledger                      = gateConnectedLedger($now - 400);
    $ledger->customers['cust-1'] = [
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ada',
        'email'        => 'ada@example.test',
    ];
    $ledger->pending[] = [
        'company_uuid'    => 'company-uuid',
        'local_type'      => 'customer',
        'local_uuid'      => 'cust-1',
        'status'          => 'pending',
        'attempts'        => 0,
        'next_attempt_at' => null,
    ];
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('company-uuid')] = ['enabled' => false, 'interval_minutes' => 5];
    [$runner, $client]                                      = gateRunnerWith($ledger, $store);

    expect($runner->hasConnection('company-uuid'))->toBeTrue()
        ->and($runner->isScheduledDue('company-uuid', $now))->toBeTrue();

    $batch = null;
    gateDispatch(function () use ($runner, &$batch) {
        $batch = $runner->run('company-uuid', 'scheduled');
    });

    expect($batch['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer')
        ->and($ledger->batches)->not->toBe([]);
});

test('the sync command stays quiet when nothing is connected even if credentials are saved', function () {
    $output  = new BufferedOutput();
    $command = new class([]) extends SyncQuickbooks {
        /**
         * @param array<int, string> $companies
         */
        public function __construct(private array $companies)
        {
            parent::__construct();
        }

        protected function connectedCompanies(): array
        {
            return $this->companies;
        }
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'client_id'     => 'saved-id',
        'client_secret' => 'saved-secret',
    ];
    [$runner] = gateRunnerWith(new SyncLedger(), $store);

    gateDispatch(function ($dispatcher) use ($command, $runner, $output) {
        expect($command->handle($runner))->toBe(0)
            ->and($dispatcher->jobs)->toBe([])
            ->and($output->fetch())->toBe('')
            ->and(SyncSchedule::shouldStart(time()))->toBeTrue();
    });
});

test('the scheduler does not queue work when no organization is connected', function () {
    $ledger                                                 = new SyncLedger();
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('company-uuid')] = [
        'enabled'                 => true,
        'periodic_interval_hours' => 1,
    ];
    [$runner] = gateRunnerWith($ledger, $store);

    gateDispatch(function ($dispatcher) use ($runner) {
        $idle = gateCommand([]);
        expect($idle->handle($runner))->toBe(0)
            ->and($dispatcher->jobs)->toBe([]);

        $listed = gateCommand(['company-uuid']);
        expect($listed->handle($runner))->toBe(0)
            ->and($dispatcher->jobs)->toBe([])
            ->and($runner->isScheduledDue('company-uuid', time()))->toBeFalse();
    });
});

test('the scheduler queues a connected organization that is due when sync enabled is stored false', function () {
    $now               = time();
    $ledger            = gateConnectedLedger(null);
    $ledger->pending[] = [
        'company_uuid'    => 'company-uuid',
        'local_type'      => 'customer',
        'local_uuid'      => 'cust-1',
        'status'          => 'pending',
        'attempts'        => 0,
        'next_attempt_at' => null,
    ];
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('company-uuid')] = ['enabled' => false, 'interval_minutes' => 5];
    [$runner]                                               = gateRunnerWith($ledger, $store);

    gateDispatch(function ($dispatcher) use ($runner, $now) {
        expect(gateCommand(['company-uuid'])->handle($runner))->toBe(0)
            ->and($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(SyncCompanyBatch::class)
            ->and($dispatcher->jobs[0]->trigger)->toBe('scheduled')
            ->and($dispatcher->jobs[0]->companyUuid)->toBe('company-uuid')
            ->and($runner->isScheduledDue('company-uuid', $now))->toBeTrue();
    });
});

test('sync now reconcile and import are rejected without an activity row when quickbooks is not connected', function () {
    $connection = new class(new class extends PDO {
        public function __construct()
        {
        }
    }, 'testing', '', ['name' => 'testing']) extends DatabaseConnection {
        /** @var array<int, string> */
        public array $inserts = [];

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            return [];
        }

        public function insert($query, $bindings = [])
        {
            $this->inserts[] = $query;

            return true;
        }
    };
    $resolver = new ConnectionResolver(['testing' => $connection]);
    $resolver->setDefaultConnection('testing');
    $previous = Model::getConnectionResolver();
    Model::setConnectionResolver($resolver);
    session(['company' => 'company-uuid']);

    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        gateDispatch(function ($dispatcher) use ($controller, $connection) {
            foreach (['import' => 'import', 'reconcile' => 'reconcile', 'sync' => 'sync'] as $method => $path) {
                $response = $controller->{$method}(Request::create('/' . $path, 'POST', ['company_uuid' => 'company-uuid']));
                expect($response->getStatusCode())->toBe(422)
                    ->and($response->getData(true)['message'])->toBe('QuickBooks is not connected. Connect from Connection.');
            }

            expect($dispatcher->jobs)->toBe([])
                ->and($connection->inserts)->toBe([]);
        });
    } finally {
        if ($previous === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($previous);
        }
        session(['company' => null]);
    }
});

test('a webhook change is not applied when the organization has no connection', function () {
    $directory = new class extends FleetbaseDirectory {
        public int $loads = 0;

        public int $skipped = 0;

        public function connection(string $companyUuid): ?array
        {
            return null;
        }

        public function load(string $companyUuid): ?array
        {
            $this->loads++;

            return null;
        }

        public function loadLinked(string $companyUuid, array $entities): ?array
        {
            $this->loads++;

            return null;
        }

        public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
        {
            $this->skipped++;
        }
    };
    $client   = new FakeQuickBooks();
    $engine   = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );
    $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());

    (new ApplyRemoteChange('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
    ]))->handle($engine, $directory, $settings, new MemorySettingsStore());

    expect($directory->loads)->toBe(0)
        ->and($directory->skipped)->toBe(0)
        ->and($client->calls)->toBe([]);
});

test('a webhook batch does not queue a sync when the organization has no connection', function () {
    $connection = new class(new class extends PDO {
        public function __construct()
        {
        }
    }, 'testing', '', ['name' => 'testing']) extends DatabaseConnection {
        /** @var array<int, string> */
        public array $inserts = [];

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            if (str_contains(strtolower($query), 'exists')) {
                return [['exists' => 0]];
            }

            return [];
        }

        public function insert($query, $bindings = [])
        {
            $this->inserts[] = $query;

            return true;
        }
    };
    $resolver = new ConnectionResolver(['testing' => $connection]);
    $resolver->setDefaultConnection('testing');
    $previous = Model::getConnectionResolver();
    Model::setConnectionResolver($resolver);

    try {
        gateDispatch(function ($dispatcher) use ($connection) {
            (new SyncWebhookBatch('company-uuid', [
                ['local_type' => 'customer', 'local_uuid' => 'cust-1'],
            ]))->handle();

            expect($connection->inserts)->toBe([])
                ->and($dispatcher->jobs)->toBe([]);
        });
    } finally {
        if ($previous === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($previous);
        }
    }
});

/**
 * @return array{0: BatchRunner, 1: FakeQuickBooks}
 */
function gateRunnerWith(SyncLedger $ledger, ?MemorySettingsStore $store = null): array
{
    $client                            = new FakeQuickBooks();
    $directory                         = gateDirectory($ledger);
    $store ??= new MemorySettingsStore();
    $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $tokens   = new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine   = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );

    return [new BatchRunner($engine, $directory, $settings, $store, $tokens), $client];
}

function gateRunner(SyncLedger $ledger): BatchRunner
{
    return gateRunnerWith($ledger)[0];
}

function gateDirectory(SyncLedger $ledger): FleetbaseDirectory
{
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;

    return $directory;
}

function gateConnectedLedger(?int $lastBatchAt): SyncLedger
{
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid'             => 'company-uuid',
        'realm_id'                 => 'realm-1',
        'access_token'             => 'access',
        'refresh_token'            => 'refresh',
        'token_expires_at'         => time() + 86400,
        'needs_reauth'             => false,
        'last_batch_at'            => $lastBatchAt,
        'last_customer_catalog_at' => time(),
        'home_currency'            => 'USD',
        'default_item_id'          => 'item-1',
    ];

    return $ledger;
}

/**
 * @param array<int, string> $companies
 */
function gateCommand(array $companies): SyncQuickbooks
{
    $command = new class($companies) extends SyncQuickbooks {
        /**
         * @param array<int, string> $companies
         */
        public function __construct(private array $companies)
        {
            parent::__construct();
        }

        protected function connectedCompanies(): array
        {
            return $this->companies;
        }
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

    return $command;
}

function gateDispatch(callable $callback): void
{
    $dispatcher = new class implements Dispatcher {
        /** @var array<int, object> */
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
    $container          = Container::getInstance();
    $previousDispatcher = $container->bound(Dispatcher::class) ? $container->make(Dispatcher::class) : null;
    $previousCache      = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
    $container->instance(Dispatcher::class, $dispatcher);

    try {
        $callback($dispatcher);
    } finally {
        Cache::swap($previousCache);
        if ($previousDispatcher !== null) {
            $container->instance(Dispatcher::class, $previousDispatcher);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }
}
