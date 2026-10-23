<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * A fake QuickBooks where another customer, vendor or employee already uses one name: a
 * customer update that sends it is refused with fault 6240, as QuickBooks does.
 */
class TakenNameQuickBooks extends FakeQuickBooks
{
    /** The Activity note when QuickBooks refused the name "Bob", as an en-us reader sees it. */
    public const NOTE = 'QuickBooks did not accept customer "Bob": a QuickBooks customer, vendor or employee already'
        . ' uses that name. Rename one of them, then sync again.';

    public string $takenName = 'Bob';

    /** @var array<int, array<string, mixed>> */
    public array $customerUpdates = [];

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateCustomer(array $connection, string $id, string $syncToken, array $payload): array
    {
        if (($payload['DisplayName'] ?? null) === $this->takenName) {
            $this->calls[] = 'updateCustomer:6240';
            throw new QuickBooksException(400, 'Duplicate Name Exists Error', null, '6240');
        }
        $this->customerUpdates[] = $payload;

        return parent::updateCustomer($connection, $id, $syncToken, $payload);
    }
}
