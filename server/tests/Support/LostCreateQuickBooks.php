<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * QuickBooks saves the invoice, but the answer never reaches Fleetbase (a timeout or a dropped
 * connection), for the next $loseInvoiceCreates creates.
 */
class LostCreateQuickBooks extends FakeQuickBooks
{
    public int $loseInvoiceCreates = 0;

    public function createInvoice(array $connection, array $payload): array
    {
        $record = parent::createInvoice($connection, $payload);
        if ($this->loseInvoiceCreates > 0) {
            $this->loseInvoiceCreates--;

            throw new QuickBooksException(0, QuickBooksClient::TRANSPORT_MESSAGE);
        }

        return $record;
    }
}
