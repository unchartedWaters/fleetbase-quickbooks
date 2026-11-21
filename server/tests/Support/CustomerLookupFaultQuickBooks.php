<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * A FakeQuickBooks whose batched customer lookups answer with the result set here for their
 * bId, so the engine sees one lookup fail or halt the batch.
 */
class CustomerLookupFaultQuickBooks extends FakeQuickBooks
{
    /** @var array<string, array<string, mixed>> batch results by bId */
    public array $lookupResults = [];

    public function batch(array $connection, array $items): array
    {
        $results = parent::batch($connection, $items);
        foreach ($items as $item) {
            $bId = (string) ($item['bId'] ?? '');
            if (isset($item['query']) === true && isset($this->lookupResults[$bId]) === true) {
                $results[$bId] = $this->lookupResults[$bId];
            }
        }

        return $results;
    }
}
