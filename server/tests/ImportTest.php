<?php

use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\CustomerImporter;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->previousFleetbaseDb = config('fleetbase.connection.db');
    qbEnsureCustomerTable();
    DB::connection('sqlite')->table('contacts')->delete();
});

afterEach(function () {
    $config = config();
    if (is_object($config) && method_exists($config, 'set')) {
        $config->set('fleetbase.connection.db', $this->previousFleetbaseDb);
    }
});

test('a quickbooks customer with no fleetbase match creates a contact', function () {
    $batch = importWith([remoteCustomer('1', 'Ada Lovelace', 'ada@example.test', '5550100')], []);

    expect($batch['created'])->toBe(1)
        ->and($batch['finished_at'])->toBeInt()
        ->and($batch['ledger']->batches[0]['finished_at'])->toBe($batch['finished_at'])
        ->and(array_values($batch['ledger']->customers)[0]['type'])->toBe('customer')
        ->and(array_values($batch['ledger']->customers)[0]['company_uuid'])->toBe('company-uuid');
});

test('email phone and name matches link instead of creating', function () {
    $email = importWith(
        [remoteCustomer('1', 'Other', 'Ada@Example.test', '000')],
        [['uuid' => 'local-1', 'company_uuid' => 'company-uuid', 'name' => 'Kept', 'email' => 'ada@example.test', 'phone' => '111']]
    );
    $phone = importWith(
        [remoteCustomer('2', 'Other', 'nope@example.test', '(555) 010-0')],
        [['uuid' => 'local-2', 'company_uuid' => 'company-uuid', 'name' => 'Kept', 'email' => null, 'phone' => '5550100']]
    );
    $name = importWith(
        [remoteCustomer('3', 'Exact Name', null, null)],
        [['uuid' => 'local-3', 'company_uuid' => 'company-uuid', 'name' => 'Exact Name', 'email' => null, 'phone' => null]]
    );

    expect($email['created'])->toBe(0)->and($email['linked'])->toBe(1)
        ->and($phone['linked'])->toBe(1)
        ->and($name['linked'])->toBe(1);
});

test('existing fleetbase field values are not overwritten', function () {
    $existing = [['uuid' => 'local-1', 'company_uuid' => 'company-uuid', 'name' => 'Fleetbase Name', 'email' => 'ada@example.test', 'phone' => '111']];
    $result   = importWith([remoteCustomer('1', 'QuickBooks Name', 'ada@example.test', '999')], $existing);

    expect($result['customers'][0]['name'])->toBe('Fleetbase Name')
        ->and($result['customers'][0]['phone'])->toBe('111');
});

test('inactive customers and sub customers are skipped', function () {
    $result = importWith([
        remoteCustomer('1', 'Inactive', 'off@example.test', null, ['Active' => false]),
        remoteCustomer('2', 'Job', 'job@example.test', null, ['ParentRef' => ['value' => '1'], 'Job' => true]),
        remoteCustomer('3', 'Active', 'on@example.test', null),
    ], []);

    expect($result['skipped'])->toBe(2)->and($result['created'])->toBe(1);
});

test('a second import creates nothing and adds no duplicate links', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [remoteCustomer('1', 'Ada', 'ada@example.test', null)]];
    $ledger                = importLedger();
    $importer              = new CustomerImporter($client);
    $first                 = $importer->import($ledger, $ledger->connections['company-uuid'], [], true);
    $second                = $importer->import($ledger, $ledger->connections['company-uuid'], array_values($ledger->customers), true);

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['linked'])->toBe(1)
        ->and(count($ledger->links))->toBe(1);
});

test('imported customers produce no outbound write on the next batch', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [remoteCustomer('1', 'Ada', 'ada@example.test', null)]];
    $ledger                = importLedger();
    $flagger               = new SyncFlagger();
    $importer              = new CustomerImporter($client, function (array $connection, array $remote) use ($flagger, $ledger) {
        $flagger->flag($ledger, 'company-uuid', 'customer', 'contact-' . $remote['Id'], 'saved');

        return [
            'uuid'         => 'contact-' . $remote['Id'],
            'company_uuid' => $connection['company_uuid'],
            'type'         => 'customer',
            'name'         => $remote['DisplayName'],
            'email'        => $remote['PrimaryEmailAddr']['Address'] ?? null,
        ];
    });
    $importer->import($ledger, $ledger->connections['company-uuid'], [], true);
    [$engine, $syncClient]      = qbEngine();
    $syncClient->customers['1'] = [
        'Id'               => '1',
        'DisplayName'      => 'Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        'SyncToken'        => '0',
    ];
    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($ledger->pending)->toBe([])
        ->and($batch['created'])->toBe(0)
        ->and($syncClient->calls)->not->toContain('createCustomer');
});

test('pagination walks one block at a time', function () {
    $page = [];
    for ($i = 1; $i <= 100; $i++) {
        $page[] = remoteCustomer((string) $i, 'Customer ' . $i, 'c' . $i . '@example.test', null);
    }
    $client                = new FakeQuickBooks();
    $client->customerPages = [
        1   => $page,
        101 => [remoteCustomer('101', 'Last', 'last@example.test', null)],
    ];
    $ledger = importLedger();
    $batch  = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], true, 100);

    expect($batch['created'])->toBe(101)
        ->and($client->calls)->toContain('queryCustomers:1')
        ->and($client->calls)->toContain('queryCustomers:101')
        ->and($ledger->connections['company-uuid'])->not->toHaveKey('customer_import_start');
});

test('a smaller batch size is the page size', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [
        1 => [
            remoteCustomer('1', 'One', 'one@example.test', null),
            remoteCustomer('2', 'Two', 'two@example.test', null),
        ],
        3 => [remoteCustomer('3', 'Three', 'three@example.test', null)],
    ];
    $ledger = importLedger();
    $batch  = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], true, 2);

    expect($batch['created'])->toBe(3)
        ->and($client->calls)->toBe(['queryCustomers:1', 'queryCustomers:3']);
});

test('an import that stops keeps the cursor and the next run continues', function () {
    $page = [];
    for ($i = 1; $i <= 2; $i++) {
        $page[] = remoteCustomer((string) $i, 'Customer ' . $i, 'c' . $i . '@example.test', null);
    }
    $client                = new FakeQuickBooks();
    $client->customerPages = [
        1 => $page,
        3 => [remoteCustomer('3', 'Last', 'last@example.test', null)],
    ];
    $ledger    = importLedger();
    $importer  = new CustomerImporter($client);
    $stopped   = $importer->import($ledger, $ledger->connections['company-uuid'], [], true, 2, 1);

    expect($stopped['created'])->toBe(2)
        ->and($client->calls)->toBe(['queryCustomers:1'])
        ->and($ledger->connections['company-uuid']['customer_import_start'])->toBe(3);

    $client->calls = [];
    $resumed       = $importer->import($ledger, $ledger->connections['company-uuid'], [], true, 2);

    expect($resumed['created'])->toBe(1)
        ->and($client->calls)->toBe(['queryCustomers:3'])
        ->and($ledger->connections['company-uuid'])->not->toHaveKey('customer_import_start');
});

test('a deadline continuation dispatches after lock release and resumes the stored cursor', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [
        1 => [
            remoteCustomer('1', 'One', 'one@example.test', null),
            remoteCustomer('2', 'Two', 'two@example.test', null),
        ],
        3 => [remoteCustomer('3', 'Three', 'three@example.test', null)],
    ];
    $ledger            = importLedger();
    $directory         = new FleetbaseDirectory();
    $directory->memory = $ledger;
    $dispatcher        = new class implements Dispatcher {
        /** @var array<int, mixed> */
        public array $jobs = [];

        /** @var array<int, bool> */
        public array $lockHeld = [];

        public function dispatch($command)
        {
            $this->lockHeld[] = BatchRunner::holds($command->companyUuid);
            $this->jobs[]     = $command;

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
    $deadlineImporter = new class($client) extends CustomerImporter {
        public function import(SyncLedger $ledger, array $connection, array $fleetbaseCustomers, bool $enabled, int $pageSize = self::MAX_PAGE_SIZE, ?int $deadline = null): array
        {
            return parent::import($ledger, $connection, $fleetbaseCustomers, $enabled, 2, 1);
        }
    };
    $container          = Container::getInstance();
    $previousDispatcher = $container->bound(Dispatcher::class) ? $container->make(Dispatcher::class) : null;
    $previousCache      = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
    $container->instance(Dispatcher::class, $dispatcher);

    try {
        (new ImportCustomers('company-uuid'))->handle($deadlineImporter, $directory);

        expect($client->calls)->toBe(['queryCustomers:1'])
            ->and($ledger->connections['company-uuid']['customer_import_start'])->toBe(3)
            ->and($ledger->batches[0]['continue'])->toBeTrue()
            ->and($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->lockHeld)->toBe([false])
            ->and($dispatcher->jobs[0]->continuationAttempt)->toBe(0);

        $client->calls = [];
        $dispatcher->jobs[0]->handle(new CustomerImporter($client), $directory);

        expect($client->calls)->toBe(['queryCustomers:3'])
            ->and($ledger->connections['company-uuid'])->not->toHaveKey('customer_import_start')
            ->and($ledger->batches[1]['continue'])->toBeFalse()
            ->and($dispatcher->jobs)->toHaveCount(1);
    } finally {
        Cache::swap($previousCache);
        if ($previousDispatcher !== null) {
            $container->instance(Dispatcher::class, $previousDispatcher);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }
});

test('a busy deadline continuation requeues at five ten and fifteen seconds then stops', function () {
    withImportDispatcher(function ($dispatcher): void {
        $directory         = new FleetbaseDirectory();
        $directory->memory = importLedger();
        $lock              = BatchRunner::lock('company-uuid');

        expect($lock)->not->toBeNull()
            ->and($lock->get())->toBeTrue();

        try {
            $job = new ImportCustomers('company-uuid', 0);
            $job->handle(new CustomerImporter(new FakeQuickBooks()), $directory);
            $dispatcher->jobs[0]->handle(new CustomerImporter(new FakeQuickBooks()), $directory);
            $dispatcher->jobs[1]->handle(new CustomerImporter(new FakeQuickBooks()), $directory);
            $dispatcher->jobs[2]->handle(new CustomerImporter(new FakeQuickBooks()), $directory);
        } finally {
            $lock->release();
        }

        expect($dispatcher->jobs)->toHaveCount(3)
            ->and(array_map(fn (ImportCustomers $queued): int => $queued->continuationAttempt, $dispatcher->jobs))->toBe([1, 2, 3])
            ->and(array_map(fn (ImportCustomers $queued): int => $queued->delay, $dispatcher->jobs))->toBe([5, 10, 15]);
    });
});

test('an initial manual import does not requeue when the company lock is busy', function () {
    withImportDispatcher(function ($dispatcher): void {
        $directory         = new FleetbaseDirectory();
        $directory->memory = importLedger();
        $lock              = BatchRunner::lock('company-uuid');

        expect($lock)->not->toBeNull()
            ->and($lock->get())->toBeTrue();

        try {
            (new ImportCustomers('company-uuid'))->handle(new CustomerImporter(new FakeQuickBooks()), $directory);
        } finally {
            $lock->release();
        }

        expect($dispatcher->jobs)->toBe([]);
    });
});

test('a continuation does not requeue for non lock blocked states or import failures', function () {
    withImportDispatcher(function ($dispatcher): void {
        $disconnected                                                     = new FleetbaseDirectory();
        $disconnected->memory                                             = new SyncLedger();
        $needsReauth                                                      = new FleetbaseDirectory();
        $needsReauth->memory                                              = importLedger();
        $needsReauth->memory->connections['company-uuid']['needs_reauth'] = true;
        $blocked                                                          = new FleetbaseDirectory();
        $blocked->memory                                                  = importLedger();
        $failing                                                          = new FleetbaseDirectory();
        $failing->memory                                                  = importLedger();
        $blockedTokens                                                    = new class extends ConnectionTokens {
            public function __construct()
            {
            }

            public function refreshIfDue(array $connection, int $now): array
            {
                $connection['refresh_error'] = 'QuickBooks token refresh failed.';

                return $connection;
            }
        };
        $failingClient = new class extends FakeQuickBooks {
            public function queryCustomers(array $connection, int $start, int $max): array
            {
                throw new RuntimeException('QuickBooks import data failed.');
            }
        };

        (new ImportCustomers('company-uuid', 0))->handle(new CustomerImporter(new FakeQuickBooks()), $disconnected);
        (new ImportCustomers('company-uuid', 0))->handle(new CustomerImporter(new FakeQuickBooks()), $needsReauth);
        (new ImportCustomers('company-uuid', 0))->handle(new CustomerImporter(new FakeQuickBooks()), $blocked, $blockedTokens);

        expect(fn () => (new ImportCustomers('company-uuid', 0))->handle(new CustomerImporter($failingClient), $failing))
            ->toThrow(RuntimeException::class, 'QuickBooks import data failed.');

        expect($dispatcher->jobs)->toBe([]);
    });
});

test('a single failing record is recorded and the rest of the import continues', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [
        remoteCustomer('1', 'Good', 'good@example.test', null),
        remoteCustomer('2', 'Bad', 'bad@example.test', null),
        remoteCustomer('3', 'Also', 'also@example.test', null),
    ]];
    $ledger   = importLedger();
    $importer = new CustomerImporter($client, function (array $connection, array $remote) {
        if ($remote['Id'] === '2') {
            throw new RuntimeException('SQLSTATE[42S22]: Column not found at /var/www/api/vendor/laravel');
        }

        return [
            'uuid'         => 'contact-' . $remote['Id'],
            'company_uuid' => $connection['company_uuid'],
            'type'         => 'customer',
            'name'         => $remote['DisplayName'],
            'email'        => $remote['PrimaryEmailAddr']['Address'],
        ];
    });
    $batch = $importer->import($ledger, $ledger->connections['company-uuid'], [], true);

    expect($batch['created'])->toBe(2)
        ->and($batch['failed'])->toBe(1)
        ->and($ledger->attempts)->toHaveCount(1)
        ->and($ledger->attempts[0]['outcome'])->toBe('failed')
        ->and($ledger->attempts[0]['local_uuid'])->toBe('2')
        ->and($ledger->attempts[0]['error'])->toBe('Customer import failed.')
        ->and($ledger->connections['company-uuid']['customer_import_start'])->toBe(2);
});

test('a short page with a failure does not reset the cursor to the start', function () {
    $client                                                       = new FakeQuickBooks();
    $client->customerPages                                        = [5 => [remoteCustomer('9', 'Bad', 'bad@example.test', null)]];
    $ledger                                                       = importLedger();
    $ledger->connections['company-uuid']['customer_import_start'] = 5;
    $importer                                                     = new CustomerImporter($client, function () {
        throw new RuntimeException('row failed');
    });

    $batch = $importer->import($ledger, $ledger->connections['company-uuid'], [], true, 2);

    expect($batch['failed'])->toBe(1)
        ->and($batch['created'])->toBe(0)
        ->and($ledger->connections['company-uuid']['customer_import_start'])->toBe(5);
});

test('a failed candidate lookup does not create the page', function () {
    qbEnsureCustomerTable();
    $schema = app('db')->connection('sqlite')->getSchemaBuilder();
    $schema->drop('contacts');

    try {
        $client                = new FakeQuickBooks();
        $client->customerPages = [1 => [
            remoteCustomer('1', 'Ada', 'ada@example.test', null),
            remoteCustomer('2', 'Bea', 'bea@example.test', null),
        ]];
        $ledger = importLedger();
        $batch  = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], true);

        expect($batch['created'])->toBe(0)
            ->and($batch['failed'])->toBe(2)
            ->and($ledger->customers)->toBe([])
            ->and($ledger->links)->toBe([])
            ->and($ledger->connections['company-uuid'])->not->toHaveKey('customer_import_start');
    } finally {
        qbEnsureCustomerTable();
    }
});

test('imported customers match stored email case and digits-only phone', function () {
    qbEnsureCustomerTable();
    DB::connection('sqlite')->table('contacts')->insert([
        [
            'uuid'         => 'local-email',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => 'Kept',
            'email'        => 'Ada@Example.test',
            'phone'        => '111',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'local-phone',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => 'Kept',
            'email'        => null,
            'phone'        => '(555) 010-0',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [
        remoteCustomer('1', 'Other', 'ada@example.test', '000'),
        remoteCustomer('2', 'Other', 'nope@example.test', '5550100'),
    ]];
    $ledger = importLedger();
    $batch  = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], true);

    expect($batch['created'])->toBe(0)
        ->and($batch['linked'])->toBe(2)
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-email')['qbo_id'])->toBe('1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-phone')['qbo_id'])->toBe('2');
});

test('customer matching compares normalized email and phone in php', function () {
    qbEnsureCustomerTable();
    $now  = now();
    $rows = [
        [
            'uuid'         => 'local-trim',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => 'Kept',
            'email'        => '  Ada@Example.test ',
            'phone'        => '111',
            'deleted_at'   => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'other-company',
            'company_uuid' => 'other-company',
            'type'         => 'customer',
            'name'         => 'Kept',
            'email'        => 'bea@example.test',
            'phone'        => '222',
            'deleted_at'   => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'vendor-row',
            'company_uuid' => 'company-uuid',
            'type'         => 'vendor',
            'name'         => 'Kept',
            'email'        => 'bea@example.test',
            'phone'        => '222',
            'deleted_at'   => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'deleted-row',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => 'Kept',
            'email'        => 'bea@example.test',
            'phone'        => '222',
            'deleted_at'   => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ];
    foreach ($rows as $row) {
        DB::connection('sqlite')->table('contacts')->insert($row);
    }
    $sql    = [];
    $listen = DB::connection('sqlite');
    $listen->listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [
        remoteCustomer('1', 'Other', 'ada@example.test', '000'),
        remoteCustomer('2', 'Other', 'bea@example.test', '222'),
    ]];
    $ledger = importLedger();
    $batch  = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], true);
    $joined = strtolower(implode("\n", $sql));

    expect($batch['linked'])->toBe(1)
        ->and($batch['created'])->toBe(1)
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-trim')['qbo_id'])->toBe('1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'other-company'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'vendor-row'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'deleted-row'))->toBeNull()
        ->and($joined)->not->toContain('lower(')
        ->and($joined)->not->toContain('regexp');
});

test('customer import releases the company lock for token refresh and quickbooks pages', function () {
    $client = new class extends FakeQuickBooks {
        public ?bool $heldDuringQuery = null;

        public function queryCustomers(array $connection, int $start, int $max): array
        {
            $this->heldDuringQuery = BatchRunner::holds((string) ($connection['company_uuid'] ?? ''));

            return parent::queryCustomers($connection, $start, $max);
        }
    };
    $client->customerPages = [1 => [remoteCustomer('1', 'Ada', 'ada@example.test', null)]];
    $tokens                = new class extends ConnectionTokens {
        public ?bool $heldDuringRefresh = null;

        public function __construct()
        {
        }

        public function refreshIfDue(array $connection, int $now): array
        {
            $this->heldDuringRefresh = BatchRunner::holds((string) ($connection['company_uuid'] ?? ''));

            return $connection;
        }
    };
    withImportDispatcher(function ($dispatcher) use ($client, $tokens): void {
        $directory = new class extends FleetbaseDirectory {
            public ?bool $heldDuringSave = null;

            public function save(SyncLedger $ledger): void
            {
                $this->heldDuringSave = BatchRunner::holds('company-uuid');
                parent::save($ledger);
            }
        };
        $directory->memory = importLedger();
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client), $directory, $tokens);

        expect($tokens->heldDuringRefresh)->toBeFalse()
            ->and($client->heldDuringQuery)->toBeFalse()
            ->and($directory->heldDuringSave)->toBeTrue()
            ->and(BatchRunner::holds('company-uuid'))->toBeFalse()
            ->and($client->calls)->toBe(['queryCustomers:1'])
            ->and($dispatcher->jobs)->toBe([]);
    });
});

test('a second remote customer is not linked unless its quickbooks id is stored', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [
        remoteCustomer('1', 'Ada', 'ada@example.test', null),
        remoteCustomer('2', 'Ada Again', 'ada@example.test', null),
    ]];
    $ledger = importLedger();
    $batch  = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], true);

    $ids = array_map(static fn (array $link): string => (string) $link['qbo_id'], $ledger->links);

    expect($batch['created'])->toBe(2)
        ->and($batch['linked'])->toBe(0)
        ->and($ids)->toEqualCanonicalizing(['1', '2']);
});

test('customer import is refused when quickbooks is not connected', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [remoteCustomer('1', 'Ada', 'ada@example.test', null)]];
    $ledger                = new SyncLedger();
    $directory             = new FleetbaseDirectory();
    $directory->memory     = $ledger;

    $job = new ImportCustomers('company-uuid');
    $job->handle(new CustomerImporter($client), $directory);

    expect($job->tries)->toBe(1)
        ->and($client->calls)->toBe([])
        ->and($ledger->batches)->toBe([])
        ->and($ledger->attempts)->toBe([]);
});

test('customer import does not load every local record', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [remoteCustomer('1', 'Ada', 'ada@example.test', null)]];
    $ledger                = importLedger();
    $directory             = new class extends FleetbaseDirectory {
        public int $loads = 0;

        public function load(string $companyUuid): ?array
        {
            $this->loads++;

            return parent::load($companyUuid);
        }
    };
    $directory->memory = $ledger;
    $previous          = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));

    try {
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client), $directory);
    } finally {
        Cache::swap($previous);
    }

    expect($directory->loads)->toBe(0)
        ->and($client->calls)->toBe(['queryCustomers:1'])
        ->and($ledger->customers)->not->toBeEmpty()
        ->and($ledger->invoices)->toBe([])
        ->and($ledger->wallets)->toBe([])
        ->and($ledger->pending)->toBe([]);
});

test('nothing is imported when the option is off', function () {
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [remoteCustomer('1', 'Ada', 'ada@example.test', null)]];
    $ledger                = importLedger();
    $batch                 = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], [], false);

    expect($batch['created'])->toBe(0)
        ->and($batch['finished_at'])->toBeInt()
        ->and($batch['direction'])->toBe('inbound')
        ->and($client->calls)->toBe([]);
});

function importWith(array $remote, array $local): array
{
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => $remote];
    $ledger                = importLedger();
    $batch                 = (new CustomerImporter($client))->import($ledger, $ledger->connections['company-uuid'], $local, true);
    $batch['ledger']       = $ledger;
    $batch['customers']    = $local;

    return $batch;
}

function importLedger(): SyncLedger
{
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
    ];

    return $ledger;
}

function remoteCustomer(string $id, string $name, ?string $email, ?string $phone, array $extra = []): array
{
    return array_merge([
        'Id'               => $id,
        'SyncToken'        => '0',
        'DisplayName'      => $name,
        'Active'           => true,
        'PrimaryEmailAddr' => $email ? ['Address' => $email] : null,
        'PrimaryPhone'     => $phone ? ['FreeFormNumber' => $phone] : null,
    ], $extra);
}

if (!function_exists('qbEnsureCustomerTable')) {
    function qbEnsureCustomerTable(): void
    {
        $config = config();
        if (is_object($config) && method_exists($config, 'set')) {
            $config->set('fleetbase.connection.db', 'sqlite');
        }
        $database = app('db');
        if (!is_object($database) || !method_exists($database, 'connection')) {
            return;
        }
        $schema = $database->connection('sqlite')->getSchemaBuilder();
        if ($schema->hasTable('contacts')) {
            return;
        }
        $schema->create('contacts', function ($table): void {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('internal_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta')->nullable();
            $table->string('slug')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

function withImportDispatcher(callable $callback): void
{
    $dispatcher = new class implements Dispatcher {
        /** @var array<int, ImportCustomers> */
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
