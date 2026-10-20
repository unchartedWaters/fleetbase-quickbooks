<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncLedger;

/**
 * A directory with one stored connection for company-uuid that keeps every ledger it is
 * asked to save, so a test can read the batches, attempts and connection fields written.
 */
class RemoteChangeDirectory extends FleetbaseDirectory
{
    /** @var array<int, SyncLedger> */
    public array $saved = [];

    /**
     * @param array<string, mixed> $stored fields on the stored connection
     */
    public function __construct(public array $stored = [])
    {
        $this->stored = array_merge([
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'needs_reauth' => false,
        ], $stored);
    }

    public function connection(string $companyUuid): ?array
    {
        return $this->stored;
    }

    public function loadLinked(string $companyUuid, array $entities): ?array
    {
        $ledger                            = new SyncLedger();
        $ledger->connections[$companyUuid] = $this->stored;

        return ['ledger' => $ledger, 'connection' => $this->stored, 'customers' => []];
    }

    public function save(SyncLedger $ledger): void
    {
        $this->saved[] = $ledger;
    }

    /**
     * Every batch saved, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function batches(): array
    {
        $batches = [];
        foreach ($this->saved as $ledger) {
            $batches = array_merge($batches, $ledger->batches);
        }

        return $batches;
    }

    /**
     * Every attempt saved, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function attempts(): array
    {
        $attempts = [];
        foreach ($this->saved as $ledger) {
            $attempts = array_merge($attempts, $ledger->attempts);
        }

        return $attempts;
    }
}
