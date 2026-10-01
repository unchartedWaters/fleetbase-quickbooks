<?php

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Listeners\FlagWalletListener;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Observers\FlagWalletObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

beforeEach(function () {
    while (SyncSuppressor::paused()) {
        SyncSuppressor::resume();
    }
});

afterEach(function () {
    Container::getInstance()->forgetInstance(FlagInvoiceListener::class);
    Container::getInstance()->forgetInstance(FlagWalletListener::class);
    while (SyncSuppressor::paused()) {
        SyncSuppressor::resume();
    }
});

test('compared or sent invoice fields flag a sync', function () {
    $flagged = ['status', 'tax', 'total_amount', 'date', 'due_date', 'notes', 'number', 'amount_paid', 'paid_at'];

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
