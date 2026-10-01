<?php

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('stored datetimes reach the engine as unix seconds', function () {
    $row = FleetbaseDirectory::toTimestamps([
        'token_expires_at'   => '2026-09-27T20:50:00.000000Z',
        'rate_limited_until' => Carbon::createFromTimestamp(1790000000),
        'last_batch_at'      => null,
        'realm_id'           => '2026',
    ], FleetbaseDirectory::CONNECTION_TIMES);

    expect($row['token_expires_at'])->toBe(strtotime('2026-09-27T20:50:00Z'))
        ->and($row['rate_limited_until'])->toBe(1790000000)
        ->and($row['last_batch_at'])->toBeNull()
        ->and($row['realm_id'])->toBe('2026');
});

test('unix seconds are written back as dates and null stays null', function () {
    $row = FleetbaseDirectory::toDates([
        'next_attempt_at' => 1790000000,
        'attempts'        => 3,
    ], FleetbaseDirectory::PENDING_TIMES);
    $empty = FleetbaseDirectory::toDates(['next_attempt_at' => null], FleetbaseDirectory::PENDING_TIMES);

    expect($row['next_attempt_at'])->toBeInstanceOf(Carbon::class)
        ->and($row['next_attempt_at']->getTimestamp())->toBe(1790000000)
        ->and($row['attempts'])->toBe(3)
        ->and($empty['next_attempt_at'])->toBeNull();
});

test('writePending ignores only driver-specific duplicate violations', function () {
    expect(directoryDuplicatePendingWrite(directoryQueryException(
        '23000',
        1062,
        "Duplicate entry 'invoice-1' for key 'pending_identity'"
    )))->toBeTrue()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            1452,
            'Cannot add or update a child row: a foreign key constraint fails'
        )))->toBeFalse()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23505',
            7,
            'duplicate key value violates unique constraint "pending_identity"'
        )))->toBeTrue()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            2067,
            'UNIQUE constraint failed: quickbooks_pending_sync.company_uuid'
        )))->toBeTrue()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            19,
            'NOT NULL constraint failed: quickbooks_pending_sync.local_uuid'
        )))->toBeFalse()
        ->and(directoryDuplicatePendingWrite(directoryQueryException(
            '23000',
            19,
            'CHECK constraint failed: pending_status'
        )))->toBeFalse();
});

test('flagging writes one pending row and never saves the ledger', function () {
    $directory = directoryWithConnection();
    $invoice   = (object) ['uuid' => 'inv-1', 'company_uuid' => 'company-uuid', 'status' => 'sent'];

    $directory->flag(function (SyncLedger $ledger) use ($invoice): void {
        (new SyncFlagger())->fromInvoiceEvent($ledger, $invoice, 'updated');
    }, 'company-uuid');

    expect($directory->written)->toHaveCount(1)
        ->and($directory->written[0]['company_uuid'])->toBe('company-uuid')
        ->and($directory->written[0]['local_type'])->toBe('invoice')
        ->and($directory->written[0]['local_uuid'])->toBe('inv-1')
        ->and($directory->written[0]['status'])->toBe('pending');
});

test('flagging a company without a connection writes nothing', function () {
    $directory                   = directoryWithConnection();
    $directory->storedConnection = null;

    $directory->flag(function (SyncLedger $ledger): void {
        (new SyncFlagger())->fromWalletEvent($ledger, (object) ['uuid' => 'wal-1', 'company_uuid' => 'company-uuid']);
    }, 'company-uuid');

    expect($directory->written)->toBe([]);
});

test('a change to the amount paid flags the invoice', function () {
    $directory = directoryWithConnection();
    Container::getInstance()->instance(FlagInvoiceListener::class, new FlagInvoiceListener($directory, new SyncFlagger()));
    $invoice = new class {
        public string $uuid = 'inv-1';

        public string $company_uuid = 'company-uuid';

        public string $status = 'sent';

        public function wasChanged(array $fields): bool
        {
            return in_array('amount_paid', $fields, true);
        }
    };

    (new FlagInvoiceObserver())->updated($invoice);

    expect($directory->written)->toHaveCount(1)
        ->and($directory->written[0]['local_uuid'])->toBe('inv-1');
});

test('listeners do not flag while the extension is writing', function () {
    $directory = directoryWithConnection();
    $listener  = new FlagCustomerListener($directory, new SyncFlagger());
    $customer  = (object) ['uuid' => 'cus-1', 'company_uuid' => 'company-uuid', 'type' => 'customer'];

    SyncSuppressor::pause();
    try {
        $listener->handle((object) ['customer' => $customer]);
    } finally {
        SyncSuppressor::resume();
    }
    expect($directory->written)->toBe([]);

    $listener->handle((object) ['customer' => $customer]);
    expect($directory->written)->toHaveCount(1);
});

function directoryWithConnection(): FleetbaseDirectory
{
    return new class extends FleetbaseDirectory {
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
}

function directoryQueryException(string $sqlState, int $driverCode, string $message): QueryException
{
    $previous            = new PDOException($message);
    $previous->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('testing', 'insert into test values (?)', [], $previous);
}

function directoryDuplicatePendingWrite(QueryException $exception): bool
{
    $method = new ReflectionMethod(FleetbaseDirectory::class, 'isDuplicatePendingWrite');

    return $method->invoke(null, $exception);
}
