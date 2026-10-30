<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * A Ledger invoice line just saved with only its meta changed: the raw meta it had, and the
 * meta it has now as the Json cast gives it.
 */
class MetaChangedLine
{
    /**
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $before
     */
    public function __construct(public string $invoice_uuid, public ?array $meta, private ?array $before)
    {
    }

    /**
     * @param array<int, string>|string|null $attributes
     */
    public function wasChanged(array|string|null $attributes = null): bool
    {
        return in_array('meta', (array) $attributes, true);
    }

    public function getRawOriginal(?string $key = null): ?string
    {
        return $key === 'meta' && $this->before !== null ? (string) json_encode($this->before) : null;
    }
}
