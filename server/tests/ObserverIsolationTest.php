<?php

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Listeners\FlagWalletListener;
use Fleetbase\Quickbooks\Observers\FlagCustomerObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceItemObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Observers\FlagPlaceObserver;
use Fleetbase\Quickbooks\Observers\FlagWalletObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
    FlagInvoiceItemObserver::forget();
});

afterEach(function () {
    Container::getInstance()->forgetInstance(FlagCustomerListener::class);
    Container::getInstance()->forgetInstance(FlagInvoiceListener::class);
    Container::getInstance()->forgetInstance(FlagWalletListener::class);
    FlagInvoiceItemObserver::forget();
});

/**
 * A directory whose connection lookup counts calls and can fail the way a missing
 * quickbooks table or a dropped database connection does.
 */
function isolationDirectory(?array $connection, bool $fails = false): FleetbaseDirectory
{
    return new class($connection, $fails) extends FleetbaseDirectory {
        public int $lookups = 0;

        /** @var array<int, array<string, mixed>> */
        public array $written = [];

        /**
         * @param array<string, mixed>|null $stored
         */
        public function __construct(public ?array $stored, public bool $fails)
        {
        }

        public function connection(string $companyUuid): ?array
        {
            $this->lookups++;
            if ($this->fails === true) {
                throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: 1146 Table quickbooks_connections doesn\'t exist');
            }

            return $this->stored;
        }

        protected function writePending(array $row): void
        {
            $this->written[] = $row;
        }
    };
}

function isolationBind(FleetbaseDirectory $directory): void
{
    $container = Container::getInstance();
    $container->instance(FlagCustomerListener::class, new FlagCustomerListener($directory, new SyncFlagger()));
    $container->instance(FlagInvoiceListener::class, new FlagInvoiceListener($directory, new SyncFlagger()));
    $container->instance(FlagWalletListener::class, new FlagWalletListener($directory, new SyncFlagger()));
}

function isolationRecord(string $uuid, string $field = 'name'): object
{
    return new class($uuid, $field) {
        public string $company_uuid = 'company-uuid';

        public string $status = 'sent';

        public string $type = 'customer';

        public string $invoice_uuid = 'inv-1';

        public function __construct(public string $uuid, private string $field)
        {
        }

        public function wasChanged(array $fields): bool
        {
            return in_array($this->field, $fields, true);
        }
    };
}

test('a failing lookup never throws out of a customer, wallet or invoice save', function () {
    $directory = isolationDirectory(null, true);
    isolationBind($directory);

    (new FlagCustomerObserver())->saved(isolationRecord('cust-1', 'name'));
    (new FlagWalletObserver())->saved(isolationRecord('wal-1', 'name'));
    (new FlagInvoiceObserver())->updated(isolationRecord('inv-1', 'status'));
    (new FlagInvoiceObserver())->deleted(isolationRecord('inv-1'));

    expect($directory->lookups)->toBe(4)
        ->and($directory->written)->toBe([]);
});

test('a failing lookup never throws out of a line item or place save', function () {
    $directory = isolationDirectory(null, true);
    isolationBind($directory);
    $items = new class extends FlagInvoiceItemObserver {
        protected function loadParent(string $invoiceUuid): ?object
        {
            return isolationRecord($invoiceUuid, 'status');
        }
    };
    $broken = new class extends FlagInvoiceItemObserver {
        protected function loadParent(string $invoiceUuid): ?object
        {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    };
    $places = new class extends FlagPlaceObserver {
        protected function customers(object $place): iterable
        {
            throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: 1146 Table contacts doesn\'t exist');
        }
    };

    $items->created(isolationRecord('line-1'));
    FlagInvoiceItemObserver::forget();
    $broken->created(isolationRecord('line-2'));
    $places->saved(isolationRecord('place-1', 'city'));

    expect($directory->lookups)->toBe(2)
        ->and($directory->written)->toBe([]);
});

test('the ledger event listeners never throw into the code that raised the event', function () {
    $directory = isolationDirectory(null, true);

    (new FlagInvoiceListener($directory, new SyncFlagger()))->handle((object) ['invoice' => isolationRecord('inv-1', 'status')]);
    (new FlagCustomerListener($directory, new SyncFlagger()))->handle((object) ['customer' => isolationRecord('cust-1')]);
    (new FlagWalletListener($directory, new SyncFlagger()))->handle((object) ['wallet' => isolationRecord('wal-1')]);

    expect($directory->lookups)->toBe(3);
});

test('a company without a connection costs one lookup, not one per save', function () {
    $directory = isolationDirectory(null);
    isolationBind($directory);

    for ($save = 0; $save < 5; $save++) {
        (new FlagCustomerObserver())->saved(isolationRecord('cust-' . $save, 'email'));
    }
    (new FlagWalletObserver())->saved(isolationRecord('wal-1', 'name'));
    (new FlagInvoiceObserver())->updated(isolationRecord('inv-1', 'status'));

    expect($directory->lookups)->toBe(1)
        ->and($directory->written)->toBe([]);
});

test('a company with a connection is flagged on every save and is never remembered as untracked', function () {
    $directory = isolationDirectory(['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1']);
    isolationBind($directory);

    (new FlagCustomerObserver())->saved(isolationRecord('cust-1', 'email'));
    (new FlagCustomerObserver())->saved(isolationRecord('cust-2', 'email'));

    expect($directory->written)->toHaveCount(2)
        ->and($directory->lookups)->toBe(2)
        ->and($directory->tracks('company-uuid'))->toBeTrue();

    $directory->stored = null;
    expect($directory->tracks('company-uuid'))->toBeFalse();
    $directory->stored = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1'];
    $lookups           = $directory->lookups;
    expect($directory->tracks('company-uuid'))->toBeFalse()
        ->and($directory->lookups)->toBe($lookups);
});

test('a place edit does not look up customers for a company without a connection', function () {
    $directory = isolationDirectory(null);
    isolationBind($directory);
    $places = new class extends FlagPlaceObserver {
        public int $customerQueries = 0;

        protected function customers(object $place): iterable
        {
            $this->customerQueries++;

            return [];
        }
    };

    $places->saved(isolationRecord('place-1', 'city'));
    $places->saved(isolationRecord('place-2', 'city'));

    expect($places->customerQueries)->toBe(0)
        ->and($directory->lookups)->toBe(1);

    $connected = isolationDirectory(['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1']);
    isolationBind($connected);
    $places->saved(isolationRecord('place-3', 'city'));

    expect($places->customerQueries)->toBe(1);
});

test('the real directory does not throw out of a save when the quickbooks tables are missing', function () {
    $defaultConnection   = config('database.default');
    $sqliteConnection    = config('database.connections.sqlite');
    $fleetbaseConnection = config('fleetbase.connection.db');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('fleetbase.connection.db', 'sqlite');
    DB::purge('sqlite');
    try {
        $directory = new FleetbaseDirectory();
        isolationBind($directory);

        (new FlagCustomerObserver())->saved(isolationRecord('cust-1', 'email'));
        (new FlagWalletObserver())->saved(isolationRecord('wal-1', 'name'));
        (new FlagInvoiceObserver())->updated(isolationRecord('inv-1', 'status'));

        expect(true)->toBeTrue();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $fleetbaseConnection);
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
