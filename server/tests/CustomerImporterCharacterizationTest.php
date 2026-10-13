<?php

/*
 * Characterization tests for CustomerImporter::import() and the ImportCustomers job.
 * They pin pagination, cursor writes, deadline handling, the failed-row cursor,
 * candidate matching against local contacts, link-or-create decisions, skip
 * reasons, and how the job uses the company lock.
 */

use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\CustomerImporter;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\CredentialResolver;
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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->cicPreviousDb = config('fleetbase.connection.db');
    cicEnsureContacts();
    DB::connection('sqlite')->table('contacts')->delete();
});

afterEach(function () {
    $config = config();
    if (is_object($config) === true && method_exists($config, 'set') === true) {
        $config->set('fleetbase.connection.db', $this->cicPreviousDb);
    }
});

/**
 * Records each page request with its size and the cursor the connection carried at that moment.
 */
class CicQuickBooks extends FakeQuickBooks
{
    /** @var array<int, array{start: int, max: int, cursor: mixed}> */
    public array $queries = [];

    public ?string $heldFor = null;

    /** @var array<int, bool> */
    public array $heldDuringQuery = [];

    public function queryCustomers(array $connection, int $start, int $max): array
    {
        $this->queries[] = ['start' => $start, 'max' => $max, 'cursor' => $connection[CustomerImporter::CURSOR_COLUMN] ?? null];
        if ($this->heldFor !== null) {
            $this->heldDuringQuery[] = BatchRunner::holds($this->heldFor);
        }

        return parent::queryCustomers($connection, $start, $max);
    }
}

class CicDirectory extends FleetbaseDirectory
{
    /** @var array<int, string> */
    public array $events = [];

    public function save(SyncLedger $ledger): void
    {
        $this->events[] = 'save:' . (BatchRunner::holds('company-uuid') === true ? 'held' : 'free');
        parent::save($ledger);
    }

    public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
    {
        $this->events[] = 'saveSkipped:' . (BatchRunner::holds('company-uuid') === true ? 'held' : 'free');
        parent::saveSkipped($companyUuid, $trigger, $direction, $message);
    }
}

class CicDispatcher implements Dispatcher
{
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
}

function cicEnsureContacts(): void
{
    $config = config();
    if (is_object($config) === true && method_exists($config, 'set') === true) {
        $config->set('fleetbase.connection.db', 'sqlite');
    }
    $schema = app('db')->connection('sqlite')->getSchemaBuilder();
    if ($schema->hasTable('contacts') === true) {
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

function cicLedger(array $connection = []): SyncLedger
{
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = array_merge([
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
    ], $connection);

    return $ledger;
}

function cicRemote(string $id, ?string $name, ?string $email = null, ?string $phone = null, array $extra = []): array
{
    return array_merge([
        'Id'               => $id,
        'SyncToken'        => '3',
        'DisplayName'      => $name,
        'Active'           => true,
        'PrimaryEmailAddr' => $email !== null ? ['Address' => $email] : null,
        'PrimaryPhone'     => $phone !== null ? ['FreeFormNumber' => $phone] : null,
    ], $extra);
}

/**
 * @return array<int, array<string, mixed>>
 */
function cicPage(int $from, int $count): array
{
    $page = [];
    for ($index = $from; $index < $from + $count; $index++) {
        $page[] = cicRemote((string) $index, 'Customer ' . $index, 'c' . $index . '@example.test');
    }

    return $page;
}

function cicCreator(array $failIds = []): Closure
{
    return function (array $connection, array $remote) use ($failIds): array {
        if (in_array((string) $remote['Id'], $failIds, true) === true) {
            throw new RuntimeException('create failed for ' . $remote['Id']);
        }

        return [
            'uuid'         => 'contact-' . $remote['Id'],
            'company_uuid' => $connection['company_uuid'],
            'type'         => 'customer',
            'name'         => (string) ($remote['DisplayName'] ?? ''),
            'email'        => $remote['PrimaryEmailAddr']['Address'] ?? null,
        ];
    };
}

function cicContact(string $uuid, ?string $name, ?string $email = null, ?string $phone = null, string $company = 'company-uuid'): void
{
    DB::connection('sqlite')->table('contacts')->insert([
        'uuid'         => $uuid,
        'company_uuid' => $company,
        'type'         => 'customer',
        'name'         => $name,
        'email'        => $email,
        'phone'        => $phone,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

/**
 * @return array{dispatcher: CicDispatcher, error: ?Throwable}
 */
function cicWithJobEnvironment(callable $callback): array
{
    $dispatcher    = new CicDispatcher();
    $container     = Container::getInstance();
    $previous      = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
    $previousCache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
    $container->instance(Dispatcher::class, $dispatcher);
    $error = null;
    try {
        $callback();
    } catch (Throwable $exception) {
        $error = $exception;
    } finally {
        Cache::swap($previousCache);
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }

    return ['dispatcher' => $dispatcher, 'error' => $error];
}

function cicSettings(array $adminSync): array
{
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = $adminSync;

    return [new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()), $store];
}

test('each page is requested at the stored cursor, and the cursor advances by the page size and clears on a short page', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 2), 3 => cicPage(3, 2), 5 => cicPage(5, 1)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 2);

    expect($client->queries)->toBe([
        ['start' => 1, 'max' => 2, 'cursor' => null],
        ['start' => 3, 'max' => 2, 'cursor' => 3],
        ['start' => 5, 'max' => 2, 'cursor' => 5],
    ])
        ->and($batch['created'])->toBe(5)
        ->and($batch['continue'])->toBeFalse()
        ->and($ledger->connections['company-uuid'])->not->toHaveKey(CustomerImporter::CURSOR_COLUMN)
        ->and($ledger->batches)->toHaveCount(1)
        ->and($ledger->batches[0])->toBe($batch);
});

test('a full last page is followed by one empty page request that resets the cursor', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 2)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 2);

    expect(array_column($client->queries, 'start'))->toBe([1, 3])
        ->and($batch['created'])->toBe(2)
        ->and($ledger->connections['company-uuid'])->not->toHaveKey(CustomerImporter::CURSOR_COLUMN);
});

test('the import starts at a stored cursor and treats a cursor of one or less as the start', function () {
    $resumeClient                = new CicQuickBooks();
    $resumeClient->customerPages = [7 => cicPage(7, 1)];
    $resumeLedger                = cicLedger([CustomerImporter::CURSOR_COLUMN => 7]);
    (new CustomerImporter($resumeClient, cicCreator()))->import($resumeLedger, $resumeLedger->connections['company-uuid'], [], true, 2);

    $starts = [];
    foreach ([0, -4, 1, '1', null] as $stored) {
        $client = new CicQuickBooks();
        $ledger = cicLedger([CustomerImporter::CURSOR_COLUMN => $stored]);
        (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 2);
        $starts[] = $client->queries[0]['start'];
    }

    expect(array_column($resumeClient->queries, 'start'))->toBe([7])
        ->and($resumeLedger->connections['company-uuid'])->not->toHaveKey(CustomerImporter::CURSOR_COLUMN)
        ->and($starts)->toBe([1, 1, 1, 1, 1]);
});

test('the page size is clamped between one and the maximum', function () {
    $sizes = [];
    foreach ([0, -3, 1, 50, 100, 101, 5000] as $requested) {
        $client = new CicQuickBooks();
        $ledger = cicLedger();
        (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, $requested);
        $sizes[] = $client->queries[0]['max'];
    }

    expect($sizes)->toBe([1, 1, 1, 50, 100, 100, 100]);
});

test('a passed deadline stops after the first full page with the next cursor stored and continue set', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 2), 3 => cicPage(3, 2)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 2, time() - 5);

    expect(array_column($client->queries, 'start'))->toBe([1])
        ->and($batch['continue'])->toBeTrue()
        ->and($batch['created'])->toBe(2)
        ->and($ledger->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(3)
        ->and($ledger->batches[0]['continue'])->toBeTrue();
});

test('a short page finishes the import even when the deadline has passed', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 1)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 2, time() - 5);

    expect($batch['continue'])->toBeFalse()
        ->and($ledger->connections['company-uuid'])->not->toHaveKey(CustomerImporter::CURSOR_COLUMN);
});

test('a future deadline does not stop the walk', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 2), 3 => cicPage(3, 2), 5 => cicPage(5, 2)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 2, time() + 3600);

    expect(array_column($client->queries, 'start'))->toBe([1, 3, 5, 7])
        ->and($batch['continue'])->toBeFalse()
        ->and($batch['created'])->toBe(6);
});

test('a failed row on a later page keeps the cursor at that row and stops even though the page was full', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 3), 4 => cicPage(4, 3), 7 => cicPage(7, 3)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator(['5'])))->import($ledger, $ledger->connections['company-uuid'], [], true, 3);

    expect(array_column($client->queries, 'start'))->toBe([1, 4])
        ->and($batch['created'])->toBe(5)
        ->and($batch['failed'])->toBe(1)
        ->and($batch['continue'])->toBeFalse()
        ->and($ledger->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(5)
        ->and($ledger->attempts)->toBe([[
            'company_uuid' => 'company-uuid',
            'local_type'   => 'customer',
            'local_uuid'   => '5',
            'outcome'      => 'failed',
            'error'        => 'Customer import failed.',
        ]]);
});

test('only the first failed row of a page decides the cursor', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 3), 4 => cicPage(4, 3)];
    $ledger                = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator(['5', '6'])))->import($ledger, $ledger->connections['company-uuid'], [], true, 3);

    expect($batch['failed'])->toBe(2)
        ->and($batch['created'])->toBe(4)
        ->and($ledger->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(5);
});

test('a failure on the very first row stops the walk and leaves an absent cursor absent', function () {
    $freshClient                = new CicQuickBooks();
    $freshClient->customerPages = [1 => cicPage(1, 2)];
    $fresh                      = cicLedger();
    $freshBatch                 = (new CustomerImporter($freshClient, cicCreator(['1'])))->import($fresh, $fresh->connections['company-uuid'], [], true, 2);

    expect($freshBatch['failed'])->toBe(1)
        ->and($freshBatch['created'])->toBe(1)
        ->and(array_column($freshClient->queries, 'start'))->toBe([1])
        ->and($fresh->connections['company-uuid'])->not->toHaveKey(CustomerImporter::CURSOR_COLUMN);
});

test('entries that are not arrays still count toward the page position and the page length', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [
        1 => ['not-a-customer', cicRemote('2', 'Two', 'two@example.test')],
        3 => [null, cicRemote('4', 'Four', 'four@example.test')],
    ];
    $ledger = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator(['4'])))->import($ledger, $ledger->connections['company-uuid'], [], true, 2);

    expect(array_column($client->queries, 'start'))->toBe([1, 3])
        ->and($batch['created'])->toBe(1)
        ->and($batch['failed'])->toBe(1)
        ->and($ledger->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(4);
});

test('inactive, parent-referenced and job customers are skipped without an attempt, other shapes are imported', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => [
        cicRemote('1', 'Inactive', null, null, ['Active' => false]),
        cicRemote('2', 'Child', null, null, ['ParentRef' => ['value' => '9']]),
        cicRemote('3', 'Job', null, null, ['Job' => true]),
        cicRemote('4', 'Active missing', null, null, ['Active' => null]),
        cicRemote('5', 'Job false', null, null, ['Job' => false, 'ParentRef' => null]),
        cicRemote('6', 'Active zero', null, null, ['Active' => 0]),
    ]];
    $ledger = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true);

    expect($batch['skipped'])->toBe(3)
        ->and($batch['created'])->toBe(3)
        ->and(array_keys($ledger->customers))->toBe(['contact-4', 'contact-5', 'contact-6'])
        ->and($ledger->attempts)->toBe([]);
});

test('a created customer gets a link with the realm, quickbooks id, sync token and the creator company', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => [
        cicRemote('11', 'Ada', 'ada@example.test'),
        cicRemote('12', 'Bea', 'bea@example.test', null, ['SyncToken' => null]),
    ]];
    $ledger  = cicLedger();
    $seen    = [];
    $creator = function (array $connection, array $remote) use (&$seen): array {
        $seen[] = [$connection['company_uuid'], $connection['realm_id'], $remote['Id']];

        return [
            'uuid'         => 'made-' . $remote['Id'],
            'company_uuid' => $remote['Id'] === '11' ? 'owner-company' : null,
            'name'         => $remote['DisplayName'],
        ];
    };

    $batch = (new CustomerImporter($client, $creator))->import($ledger, $ledger->connections['company-uuid'], [], true);
    $links = [];
    foreach ($ledger->links as $link) {
        $links[] = [$link['company_uuid'], $link['realm_id'], $link['local_type'], $link['local_uuid'], $link['qbo_entity'], $link['qbo_id'], $link['sync_token']];
    }

    expect($batch['created'])->toBe(2)
        ->and($seen)->toBe([['company-uuid', 'realm-1', '11'], ['company-uuid', 'realm-1', '12']])
        ->and($links)->toEqualCanonicalizing([
            ['owner-company', 'realm-1', 'customer', 'made-11', 'Customer', '11', '3'],
            ['company-uuid', 'realm-1', 'customer', 'made-12', 'Customer', '12', '0'],
        ])
        ->and(array_keys($ledger->customers))->toBe(['made-11', 'made-12']);
});

test('a remote id already linked is counted as linked without calling the creator or adding a link', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => [cicRemote('21', 'Linked', 'linked@example.test')]];
    $ledger                = cicLedger();
    $ledger->putLink([
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => 'customer',
        'local_uuid'   => 'local-21',
        'qbo_entity'   => 'Customer',
        'qbo_id'       => '21',
        'sync_token'   => '0',
    ]);
    $created = 0;

    $batch = (new CustomerImporter($client, function () use (&$created): array {
        $created++;

        return ['uuid' => 'never', 'company_uuid' => 'company-uuid'];
    }))->import($ledger, $ledger->connections['company-uuid'], [], true);

    expect($batch['linked'])->toBe(1)
        ->and($created)->toBe(0)
        ->and($ledger->links)->toHaveCount(1);
});

test('a fleetbase match links with the remote sync token unless it is already linked to another quickbooks id', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => [
        cicRemote('31', 'Same', 'same@example.test'),
        cicRemote('32', 'Other', 'taken@example.test'),
        cicRemote('', 'No id', 'noid@example.test'),
    ]];
    $ledger = cicLedger();
    $ledger->putLink([
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => 'customer',
        'local_uuid'   => 'local-taken',
        'qbo_entity'   => 'Customer',
        'qbo_id'       => '99',
        'sync_token'   => '0',
    ]);
    $local = [
        ['uuid' => 'local-same', 'company_uuid' => 'company-uuid', 'name' => 'L1', 'email' => 'same@example.test'],
        ['uuid' => 'local-taken', 'company_uuid' => 'company-uuid', 'name' => 'L2', 'email' => 'taken@example.test'],
        ['uuid' => 'local-noid', 'company_uuid' => 'company-uuid', 'name' => 'L3', 'email' => 'noid@example.test'],
    ];

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], $local, true);

    expect($batch['linked'])->toBe(2)
        ->and($batch['created'])->toBe(1)
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-same')['qbo_id'])->toBe('31')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-same')['sync_token'])->toBe('3')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-taken')['qbo_id'])->toBe('99')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'contact-32')['qbo_id'])->toBe('32')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'local-noid'))->toBeNull()
        ->and($ledger->links)->toHaveCount(3);
});

test('local contacts that match a page key are remembered on the ledger and linked, others are not loaded', function () {
    cicContact('db-email', 'Stored A', '  ADA@example.test ', null);
    cicContact('db-phone', 'Stored B', null, '+1 (555) 222-3333');
    cicContact('db-name', 'Grace Hopper', null, null);
    cicContact('db-miss', 'Nobody', 'nobody@example.test', '999');
    cicContact('', 'Grace Hopper', null, null);
    cicContact('db-other', 'Grace Hopper', null, null, 'other-company');

    $client                = new CicQuickBooks();
    $client->customerPages = [1 => [
        cicRemote('41', 'Remote A', 'ada@example.test'),
        cicRemote('42', 'Remote B', null, '15552223333'),
        cicRemote('43', null, null, null, ['GivenName' => 'Grace', 'FamilyName' => 'Hopper']),
    ]];
    $ledger = cicLedger();

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true);

    expect($batch['linked'])->toBe(3)
        ->and($batch['created'])->toBe(0)
        ->and(array_keys($ledger->customers))->toEqualCanonicalizing(['db-email', 'db-phone', 'db-name'])
        ->and($ledger->customers['db-email'])->toBe([
            'uuid'         => 'db-email',
            'company_uuid' => 'company-uuid',
            'type'         => 'customer',
            'name'         => 'Stored A',
            'email'        => '  ADA@example.test ',
            'phone'        => null,
        ])
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'db-email')['qbo_id'])->toBe('41')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'db-phone')['qbo_id'])->toBe('42')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'db-name')['qbo_id'])->toBe('43');
});

test('local contacts are read once per import across pages and read again by the next import', function () {
    cicContact('db-1', 'Someone', 'someone@example.test', null);
    $db = DB::connection('sqlite');
    $db->flushQueryLog();
    $db->enableQueryLog();
    $selects = static function () use ($db): int {
        $count = 0;
        foreach ($db->getQueryLog() as $entry) {
            $sql = strtolower((string) $entry['query']);
            if (str_contains($sql, 'contacts') === true && str_starts_with(ltrim($sql), 'select') === true) {
                $count++;
            }
        }

        return $count;
    };

    try {
        $client                = new CicQuickBooks();
        $client->customerPages = [1 => cicPage(1, 2), 3 => cicPage(3, 1)];
        $ledger                = cicLedger();
        $importer              = new CustomerImporter($client, cicCreator());
        $importer->import($ledger, $ledger->connections['company-uuid'], [], true, 2);
        $afterFirst = $selects();

        $client->customerPages = [1 => [cicRemote('9', 'Nine', 'nine@example.test')]];
        $importer->import($ledger, $ledger->connections['company-uuid'], [], true, 2);
        $afterSecond = $selects();
    } finally {
        $db->disableQueryLog();
        $db->flushQueryLog();
    }

    expect(array_column($client->queries, 'start'))->toBe([1, 3, 1])
        ->and($afterFirst)->toBe(1)
        ->and($afterSecond)->toBe(2);
});

test('a page with no email, phone or name does not look up local contacts at all', function () {
    $schema = app('db')->connection('sqlite')->getSchemaBuilder();
    $schema->drop('contacts');

    try {
        $client                = new CicQuickBooks();
        $client->customerPages = [1 => [cicRemote('51', null), cicRemote('52', '')]];
        $ledger                = cicLedger();

        $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true);

        expect($batch['created'])->toBe(2)
            ->and($batch['failed'])->toBe(0);
    } finally {
        cicEnsureContacts();
    }
});

test('a failed contact lookup fails the page rows, still counts skipped rows, and stops without a cursor', function () {
    $schema = app('db')->connection('sqlite')->getSchemaBuilder();
    $schema->drop('contacts');

    try {
        $client                = new CicQuickBooks();
        $client->customerPages = [
            3 => [
                cicRemote('61', 'Ok', 'ok@example.test'),
                cicRemote('62', 'Off', 'off@example.test', null, ['Active' => false]),
                'junk',
            ],
            6 => cicPage(6, 3),
        ];
        $ledger = cicLedger([CustomerImporter::CURSOR_COLUMN => 3]);

        $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [], true, 3);

        expect(array_column($client->queries, 'start'))->toBe([3])
            ->and($batch['failed'])->toBe(1)
            ->and($batch['skipped'])->toBe(1)
            ->and($batch['created'])->toBe(0)
            ->and($batch['status'])->toBe('finished')
            ->and($ledger->attempts)->toBe([[
                'company_uuid' => 'company-uuid',
                'local_type'   => 'customer',
                'local_uuid'   => '61',
                'outcome'      => 'failed',
                'error'        => 'Customer import failed.',
            ]])
            ->and($ledger->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(3);
    } finally {
        cicEnsureContacts();
    }
});

test('a disabled import records one empty batch and does not touch the cursor', function () {
    $client = new CicQuickBooks();
    $ledger = cicLedger([CustomerImporter::CURSOR_COLUMN => 9]);

    $batch = (new CustomerImporter($client, cicCreator()))->import($ledger, $ledger->connections['company-uuid'], [['uuid' => 'x', 'email' => 'x@example.test']], false);

    expect($client->queries)->toBe([])
        ->and($ledger->batches)->toBe([$batch])
        ->and($batch['company_uuid'])->toBe('company-uuid')
        ->and($batch['trigger'])->toBe('import')
        ->and($batch['status'])->toBe('finished')
        ->and([$batch['created'], $batch['linked'], $batch['skipped'], $batch['failed'], $batch['continue']])->toBe([0, 0, 0, 0, false])
        ->and($ledger->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(9);
});

test('the import job passes the settings page size, a lock based deadline and an empty local list', function () {
    $captured = [];
    $importer = new class(new CicQuickBooks()) extends CustomerImporter {
        /** @var array<int, mixed> */
        public array $captured = [];

        public function import(SyncLedger $ledger, array $connection, array $fleetbaseCustomers, bool $enabled, int $pageSize = self::MAX_PAGE_SIZE, ?int $deadline = null): array
        {
            $this->captured[] = [$fleetbaseCustomers, $enabled, $pageSize, $deadline - time()];

            return ['continue' => false];
        }
    };
    $env = cicWithJobEnvironment(function () use ($importer): void {
        foreach ([['batch_size' => 7], ['batch_size' => 500]] as $sync) {
            [$settings, $store] = cicSettings($sync);
            $directory          = new FleetbaseDirectory();
            $directory->memory  = cicLedger();
            (new ImportCustomers('company-uuid'))->handle($importer, $directory, null, $settings, $store);
        }
        $directory         = new FleetbaseDirectory();
        $directory->memory = cicLedger();
        (new ImportCustomers('company-uuid'))->handle($importer, $directory);
    });

    expect($env['error'])->toBeNull()
        ->and(array_map(static fn (array $call): array => array_slice($call, 0, 3), $importer->captured))->toBe([
            [[], true, 7],
            [[], true, 100],
            [[], true, 100],
        ])
        ->and($importer->captured[0][3])->toBeGreaterThanOrEqual(BatchRunner::LOCK_SECONDS - 30 - 2)
        ->and($importer->captured[0][3])->toBeLessThanOrEqual(BatchRunner::LOCK_SECONDS - 30)
        ->and($env['dispatcher']->jobs)->toBe([]);
});

test('the import job drops the lock for page queries and saves under it', function () {
    $client                = new CicQuickBooks();
    $client->heldFor       = 'company-uuid';
    $client->customerPages = [1 => cicPage(1, 2), 3 => cicPage(3, 1)];
    $directory             = new CicDirectory();
    $directory->memory     = cicLedger();
    [$settings, $store]    = cicSettings(['batch_size' => 2]);

    $env = cicWithJobEnvironment(function () use ($client, $directory, $settings, $store): void {
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client, cicCreator()), $directory, null, $settings, $store);
        expect(BatchRunner::holds('company-uuid'))->toBeFalse();
    });

    expect($env['error'])->toBeNull()
        ->and($client->heldDuringQuery)->toBe([false, false])
        ->and($directory->events)->toBe(['save:held'])
        ->and($directory->memory->batches[0]['created'])->toBe(3)
        ->and($env['dispatcher']->jobs)->toBe([]);
});

test('the import job still saves under the lock when a page query throws, and rethrows', function () {
    $client = new class extends CicQuickBooks {
        public function queryCustomers(array $connection, int $start, int $max): array
        {
            if ($start > 1) {
                throw new RuntimeException('page two failed');
            }

            return parent::queryCustomers($connection, $start, $max);
        }
    };
    $client->customerPages = [1 => cicPage(1, 2)];
    $directory             = new CicDirectory();
    $directory->memory     = cicLedger();
    [$settings, $store]    = cicSettings(['batch_size' => 2]);

    $env = cicWithJobEnvironment(function () use ($client, $directory, $settings, $store): void {
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client, cicCreator()), $directory, null, $settings, $store);
    });

    expect($env['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($env['error']->getMessage())->toBe('page two failed')
        ->and($directory->events)->toBe(['save:held'])
        ->and(array_keys($directory->memory->customers))->toBe(['contact-1', 'contact-2'])
        ->and($directory->memory->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(3)
        ->and($directory->memory->batches)->toBe([])
        ->and(BatchRunner::holds('company-uuid'))->toBeFalse()
        ->and($env['dispatcher']->jobs)->toBe([]);
});

test('the import job records why it skipped: reconnect needed, customers off, or a blocked token', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 1)];

    $reauth                                                     = new CicDirectory();
    $reauth->memory                                             = cicLedger(['needs_reauth' => true]);
    $off                                                        = new CicDirectory();
    $off->memory                                                = cicLedger();
    [$offSettings, $offStore]                                   = cicSettings(['customer_enabled' => false]);
    $blocked                                                    = new CicDirectory();
    $blocked->memory                                            = cicLedger();
    $blockedTokens                                              = new class extends ConnectionTokens {
        public ?bool $heldDuringRefresh = null;

        public function __construct()
        {
        }

        public function refreshIfDue(array $connection, int $now): array
        {
            $this->heldDuringRefresh     = BatchRunner::holds('company-uuid');
            $connection['refresh_error'] = 'QuickBooks token refresh failed.';

            return $connection;
        }
    };

    $env = cicWithJobEnvironment(function () use ($client, $reauth, $off, $offSettings, $offStore, $blocked, $blockedTokens): void {
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client), $reauth);
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client), $off, null, $offSettings, $offStore);
        (new ImportCustomers('company-uuid'))->handle(new CustomerImporter($client), $blocked, $blockedTokens);
        expect(BatchRunner::holds('company-uuid'))->toBeFalse();
    });

    expect($env['error'])->toBeNull()
        ->and($client->queries)->toBe([])
        ->and($reauth->events)->toBe(['saveSkipped:held', 'save:held'])
        ->and($reauth->memory->attempts[0]['error'])->toBe('QuickBooks needs to be connected again before import can '
            . 'continue. Choose Connect to QuickBooks on QuickBooks Setup.')
        ->and($reauth->memory->batches[0]['trigger'])->toBe('import')
        ->and($reauth->memory->batches[0]['direction'])->toBe('inbound')
        ->and($off->events)->toBe(['saveSkipped:held', 'save:held'])
        ->and($off->memory->attempts[0]['error'])->toBe('Customers are turned off in Data Resolution, so they are not imported.')
        ->and($blockedTokens->heldDuringRefresh)->toBeFalse()
        ->and($blocked->events)->toBe(['saveSkipped:held', 'save:held'])
        ->and($blocked->memory->attempts[0]['error'])->toBe('QuickBooks token refresh failed.')
        ->and($env['dispatcher']->jobs)->toBe([]);
});

test('a deadline stop in the job queues one continuation after the lock is released', function () {
    $client                = new CicQuickBooks();
    $client->customerPages = [1 => cicPage(1, 2)];
    $importer              = new class($client, cicCreator()) extends CustomerImporter {
        public function import(SyncLedger $ledger, array $connection, array $fleetbaseCustomers, bool $enabled, int $pageSize = self::MAX_PAGE_SIZE, ?int $deadline = null): array
        {
            return parent::import($ledger, $connection, $fleetbaseCustomers, $enabled, 2, time() - 1);
        }
    };
    $directory         = new CicDirectory();
    $directory->memory = cicLedger();

    $env = cicWithJobEnvironment(function () use ($importer, $directory): void {
        (new ImportCustomers('company-uuid', null, true))->handle($importer, $directory);
    });

    expect($env['error'])->toBeNull()
        ->and($directory->events)->toBe(['save:held'])
        ->and($env['dispatcher']->jobs)->toHaveCount(1)
        ->and($env['dispatcher']->jobs[0])->toBeInstanceOf(ImportCustomers::class)
        ->and($env['dispatcher']->jobs[0]->continuationAttempt)->toBe(0)
        ->and($env['dispatcher']->jobs[0]->waitForLock)->toBeTrue()
        ->and($env['dispatcher']->jobs[0]->lockWaitSeconds)->toBe(0)
        ->and($directory->memory->connections['company-uuid'][CustomerImporter::CURSOR_COLUMN])->toBe(3);
});
