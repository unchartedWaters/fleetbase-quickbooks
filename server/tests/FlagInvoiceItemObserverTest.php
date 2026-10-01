<?php

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Observers\FlagInvoiceItemObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessed;

beforeEach(function () {
    resetFlaggedInvoices();
    while (SyncSuppressor::paused()) {
        SyncSuppressor::resume();
    }
});

afterEach(function () {
    resetFlaggedInvoices();
    Container::getInstance()->forgetInstance(FlagInvoiceListener::class);
    while (SyncSuppressor::paused()) {
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

    Container::getInstance()->instance(FlagInvoiceListener::class, new FlagInvoiceListener($directory, new SyncFlagger()));

    $observer = new class extends FlagInvoiceItemObserver {
        public int $loads = 0;

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

function invoiceLine(string $invoiceUuid, array $changed = []): object
{
    return new class($invoiceUuid, $changed) {
        /**
         * @param array<int, string> $changed
         */
        public function __construct(public string $invoice_uuid, private array $changed)
        {
        }

        public function wasChanged(array|string|null $attributes = null): bool
        {
            foreach ((array) $attributes as $field) {
                if (in_array($field, $this->changed, true)) {
                    return true;
                }
            }

            return false;
        }
    };
}
