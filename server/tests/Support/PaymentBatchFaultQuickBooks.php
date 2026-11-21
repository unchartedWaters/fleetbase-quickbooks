<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * A FakeQuickBooks whose Batch API call throws when it carries payment writes,
 * so the engine sees the whole payment batch fail.
 */
class PaymentBatchFaultQuickBooks extends FakeQuickBooks
{
    public ?\Throwable $paymentBatchFault = null;

    public function batch(array $connection, array $items): array
    {
        $payments = array_filter($items, static fn (array $item): bool => ($item['entity'] ?? '') === 'Payment');
        if ($this->paymentBatchFault !== null && $payments !== []) {
            $this->calls[] = 'batch';

            throw $this->paymentBatchFault;
        }

        return parent::batch($connection, $items);
    }
}
