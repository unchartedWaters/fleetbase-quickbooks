<?php

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Listeners\FlagWalletListener;
use Fleetbase\Quickbooks\Observers\FlagCustomerObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Observers\FlagPlaceObserver;
use Fleetbase\Quickbooks\Observers\FlagWalletObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

beforeEach(function () {
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

afterEach(function () {
    Container::getInstance()->forgetInstance(FlagCustomerListener::class);
    Container::getInstance()->forgetInstance(FlagInvoiceListener::class);
    Container::getInstance()->forgetInstance(FlagWalletListener::class);
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

test('compared or sent invoice fields flag a sync', function () {
    $flagged = ['status', 'tax', 'total_amount', 'date', 'due_date', 'notes', 'number', 'amount_paid', 'paid_at', 'customer_uuid', 'currency'];

    foreach ($flagged as $field) {
        $directory = bindFlagListener(FlagInvoiceListener::class);
        (new FlagInvoiceObserver())->updated(recordChanged($field, 'inv-1'));

        expect($directory->written)->toHaveCount(1)
            ->and($directory->written[0]['local_type'])->toBe('invoice')
            ->and($directory->written[0]['local_uuid'])->toBe('inv-1');
    }
});

test('an invoice subtotal or balance change does not flag a sync', function () {
    foreach (['subtotal', 'balance'] as $field) {
        $directory = bindFlagListener(FlagInvoiceListener::class);
        (new FlagInvoiceObserver())->updated(recordChanged($field, 'inv-1'));

        expect($directory->written)->toBe([]);
    }
});

test('compared or sent wallet fields flag a sync', function () {
    $flagged = ['name', 'description', 'currency', 'status', 'public_id'];

    foreach ($flagged as $field) {
        $directory = bindFlagListener(FlagWalletListener::class);
        (new FlagWalletObserver())->saved(recordChanged($field, 'wal-1'));

        expect($directory->written)->toHaveCount(1)
            ->and($directory->written[0]['local_type'])->toBe('wallet')
            ->and($directory->written[0]['local_uuid'])->toBe('wal-1');
    }
});

test('compared or sent customer fields flag a sync', function () {
    $flagged = ['name', 'email', 'phone', 'notes', 'place_uuid'];

    foreach ($flagged as $field) {
        $directory = bindFlagListener(FlagCustomerListener::class);
        (new FlagCustomerObserver())->saved(recordChanged($field, 'cust-1'));

        expect($directory->written)->toHaveCount(1)
            ->and($directory->written[0]['local_type'])->toBe('customer')
            ->and($directory->written[0]['local_uuid'])->toBe('cust-1');
    }
});

test('a customer meta change does not flag a sync', function () {
    $directory = bindFlagListener(FlagCustomerListener::class);
    (new FlagCustomerObserver())->saved(recordChanged('meta', 'cust-1'));

    expect($directory->written)->toBe([]);
});

test('a place street city state or postal edit flags every customer on that place', function () {
    $flagged = ['street1', 'street2', 'city', 'province', 'postal_code', 'country'];

    foreach ($flagged as $field) {
        $directory = bindFlagListener(FlagCustomerListener::class);
        placeObserver([
            customerRecord('cust-1'),
            customerRecord('cust-2'),
        ])->saved(recordChanged($field, 'place-1'));

        expect($directory->written)->toHaveCount(2)
            ->and($directory->written[0]['local_type'])->toBe('customer')
            ->and($directory->written[0]['local_uuid'])->toBe('cust-1')
            ->and($directory->written[1]['local_type'])->toBe('customer')
            ->and($directory->written[1]['local_uuid'])->toBe('cust-2');
    }
});

test('an unrelated place edit does not flag a customer', function () {
    foreach (['name', 'phone', 'location', 'neighborhood', 'meta'] as $field) {
        $directory = bindFlagListener(FlagCustomerListener::class);
        placeObserver([customerRecord('cust-1')])->saved(recordChanged($field, 'place-1'));

        expect($directory->written)->toBe([]);
    }
});

test('a place edit without a uuid does not flag a customer', function () {
    $directory = bindFlagListener(FlagCustomerListener::class);
    $place     = new class {
        public string $uuid = '   ';

        public function wasChanged(array $fields): bool
        {
            return true;
        }
    };

    (new FlagPlaceObserver())->saved($place);

    expect($directory->written)->toBe([]);
});

test('a wallet meta change does not flag a sync', function () {
    $directory = bindFlagListener(FlagWalletListener::class);
    (new FlagWalletObserver())->saved(recordChanged('meta', 'wal-1'));

    expect($directory->written)->toBe([]);
});

function bindFlagListener(string $listener): FleetbaseDirectory
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

    Container::getInstance()->instance($listener, new $listener($directory, new SyncFlagger()));

    return $directory;
}

function placeObserver(array $customers): FlagPlaceObserver
{
    return new class($customers) extends FlagPlaceObserver {
        /**
         * @param array<int, object> $customers
         */
        public function __construct(private array $customers)
        {
        }

        protected function customers(object $place): iterable
        {
            return $this->customers;
        }
    };
}

function customerRecord(string $uuid): object
{
    return (object) [
        'company_uuid' => 'company-uuid',
        'uuid'         => $uuid,
        'type'         => 'customer',
    ];
}

function recordChanged(string $field, string $uuid): object
{
    return new class($field, $uuid) {
        public string $company_uuid = 'company-uuid';

        public string $status = 'sent';

        public function __construct(private string $field, public string $uuid)
        {
        }

        public function wasChanged(array $fields): bool
        {
            return in_array($this->field, $fields, true);
        }
    };
}
