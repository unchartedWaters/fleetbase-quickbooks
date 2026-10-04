<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\SettingsStore;

/**
 * Keeps settings in memory so controller tests never touch the database.
 */
class MemorySettingsStore extends SettingsStore
{
    /** @var array<int, string> */
    public array $asked = [];

    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function get(string $key): array
    {
        $this->asked[] = $key;

        return $this->rows[$key] ?? [];
    }

    public function put(string $key, array $value): void
    {
        $this->rows[$key] = $value;
    }
}
