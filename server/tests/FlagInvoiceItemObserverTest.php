<?php

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Observers\FlagInvoiceItemObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Fleetbase\Quickbooks\Tests\Support\CountingDirectory;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessed;

beforeEach(function () {
    resetFlaggedInvoices();
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

afterEach(function () {
    resetFlaggedInvoices();
    Container::getInstance()->forgetInstance(FlagInvoiceListener::class);
    Container::getInstance()->forgetInstance(FleetbaseDirectory::class);
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

test('five lines of one invoice in one request load the parent once', function () {
    [$observer, $directory] = itemObserverHarness();

    $observer->created(invoiceLine('inv-1'));
    $observer->created(invoiceLine('inv-1'));
    $observer->updated(invoiceLine('inv-1', ['quantity']));
    $observer->updated(invoiceLine('inv-1', ['amount']));
    $observer->deleted(invoiceLine('inv-1'));

    expect($observer->loads)->toBe(1)
        ->and($directory->written)->toHaveCount(1)
        ->and($directory->written[0]['local_type'])->toBe('invoice')
        ->and($directory->written[0]['local_uuid'])->toBe('inv-1')
        ->and($directory->written[0]['company_uuid'])->toBe('company-uuid')
        ->and($directory->written[0]['status'])->toBe('pending');
});

test('a second save after the job finishes loads the parent again', function () {
    [$observer, $directory] = itemObserverHarness();

    $observer->created(invoiceLine('inv-1'));
    $observer->updated(invoiceLine('inv-1', ['description']));

    Container::getInstance()->make('events')->dispatch(JobProcessed::class);

    $observer->updated(invoiceLine('inv-1', ['unit_price']));
    $observer->deleted(invoiceLine('inv-1'));

    expect($observer->loads)->toBe(2)
        ->and($directory->written)->toHaveCount(2);
});

// Under Octane the terminating callback is registered on the first request's copy of the container only.
test('a second save after the HTTP request is handled loads the parent again', function () {
    [$observer, $directory] = itemObserverHarness();

    $observer->updated(invoiceLine('inv-1', ['description']));

    Container::getInstance()->make('events')->dispatch('Illuminate\\Foundation\\Http\\Events\\RequestHandled');

    $observer->updated(invoiceLine('inv-1', ['unit_price']));

    expect($observer->loads)->toBe(2)
        ->and($directory->written)->toHaveCount(2);
});

test('an unwatched line change does not load the parent', function () {
    [$observer, $directory] = itemObserverHarness();

    $observer->updated(invoiceLine('inv-1', ['tax_rate']));

    expect($observer->loads)->toBe(0)
        ->and($directory->written)->toBe([]);
});

test('a second invoice still loads its parent', function () {
    [$observer, $directory] = itemObserverHarness();

    $observer->created(invoiceLine('inv-1'));
    $observer->created(invoiceLine('inv-2'));

    expect($observer->loads)->toBe(2)
        ->and($directory->written)->toHaveCount(2);
});

test('a paused line does not load the parent or use up the one flag', function () {
    [$observer, $directory] = itemObserverHarness();

    SyncSuppressor::pause();
    $observer->created(invoiceLine('inv-1'));
    $observer->deleted(invoiceLine('inv-1'));
    SyncSuppressor::resume();
    $observer->created(invoiceLine('inv-1'));

    expect($observer->loads)->toBe(1)
        ->and($directory->written)->toHaveCount(1);
});

test('a line with no invoice uuid does not load a parent', function () {
    [$observer, $directory] = itemObserverHarness();

    $observer->created(invoiceLine(''));
    $observer->deleted(invoiceLine(''));

    expect($observer->loads)->toBe(0)
        ->and($directory->written)->toBe([]);
});

test('an organization without a connection never loads the invoice', function () {
    [$observer, $directory]      = itemObserverHarness();
    $directory->storedConnection = null;

    $observer->created(invoiceLine('inv-1'));
    $observer->deleted(invoiceLine('inv-1'));

    expect($observer->loads)->toBe(0)
        ->and($directory->written)->toBe([]);
});

test('a line carrying its own company uuid is checked without a lookup', function () {
    [$observer, $directory]      = itemObserverHarness();
    $directory->storedConnection = null;

    $observer->created(invoiceLine('inv-1', [], 'other-company'));

    expect($observer->companyLookups)->toBe(0)
        ->and($observer->loads)->toBe(0);
});

test('lines of an untracked organization cost one lookup per invoice', function () {
    [$observer]                         = itemObserverHarness();
    $container                          = Container::getInstance();
    CountingDirectory::$connectionReads = 0;
    CountingDirectory::$connections     = [];
    $container->forgetInstance(FleetbaseDirectory::class);
    $container->forgetInstance(FlagInvoiceListener::class);
    $container->bind(FleetbaseDirectory::class, fn () => new CountingDirectory());
    $container->singleton(FlagInvoiceListener::class);

    try {
        for ($line = 0; $line < 5; $line++) {
            $observer->created(invoiceLine('inv-1'));
        }

        expect(CountingDirectory::$connectionReads)->toBe(1)
            ->and($observer->companyLookups)->toBe(1)
            ->and($observer->loads)->toBe(0);
    } finally {
        unset($container[FleetbaseDirectory::class], $container[FlagInvoiceListener::class]);
    }
});

function resetFlaggedInvoices(): void
{
    FlagInvoiceItemObserver::forget();
}

/**
 * @return array{0: FlagInvoiceItemObserver, 1: FleetbaseDirectory}
 */
function itemObserverHarness(): array
{
    $directory = new class extends FleetbaseDirectory {
        /** @var array<string, mixed>|null */
        public ?array $storedConnection = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1'];

        /** @var array<int, array<string, mixed>> */
        public array $written = [];

        public function connection(string $companyUuid): ?array
        {
            return $this->storedConnection;
        }

        public function save(SyncLedger $ledger): void
        {
            throw new RuntimeException('flag() must not save the ledger');
        }

        protected function writePending(array $row): void
        {
            $this->written[] = $row;
        }
    };

    Container::getInstance()->instance(FleetbaseDirectory::class, $directory);
    Container::getInstance()->instance(FlagInvoiceListener::class, new FlagInvoiceListener($directory, new SyncFlagger()));

    $observer = new class extends FlagInvoiceItemObserver {
        public int $loads = 0;

        public int $companyLookups = 0;

        protected function companyOf(string $invoiceUuid): string
        {
            $this->companyLookups++;

            return 'company-uuid';
        }

        protected function loadParent(string $invoiceUuid): ?object
        {
            $this->loads++;

            return (object) [
                'uuid'         => $invoiceUuid,
                'company_uuid' => 'company-uuid',
                'status'       => 'sent',
            ];
        }
    };

    return [$observer, $directory];
}

function invoiceLine(string $invoiceUuid, array $changed = [], ?string $companyUuid = null): object
{
    return new class($invoiceUuid, $changed, $companyUuid) {
        /**
         * @param array<int, string> $changed
         */
        public function __construct(public string $invoice_uuid, private array $changed, public ?string $company_uuid = null)
        {
        }

        public function wasChanged(array|string|null $attributes = null): bool
        {
            foreach ((array) $attributes as $field) {
                if (in_array($field, $this->changed, true) === true) {
                    return true;
                }
            }

            return false;
        }
    };
}
