<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * A fake QuickBooks that, like QuickBooks, always holds an invoice due date: the transaction
 * date when none is given. It keeps each invoice update payload.
 */
class DueDateQuickBooks extends FakeQuickBooks
{
    /** @var array<int, array<string, mixed>> */
    public array $invoiceUpdates = [];

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createInvoice(array $connection, array $payload): array
    {
        return $this->withDueDate(parent::createInvoice($connection, $payload));
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateInvoice(array $connection, string $id, string $syncToken, array $payload): array
    {
        $this->invoiceUpdates[] = $payload;

        return $this->withDueDate(parent::updateInvoice($connection, $id, $syncToken, $payload));
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function withDueDate(array $record): array
    {
        if (trim((string) ($record['DueDate'] ?? '')) === '') {
            $record['DueDate']                      = (string) ($record['TxnDate'] ?? '2026-09-01');
            $this->invoices[(string) $record['Id']] = $record;
        }

        return $record;
    }
}
