<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncLedger;

/**
 * A directory for one connected company that keeps the pending rows it would write.
 */
class PendingRecorder extends FleetbaseDirectory
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $written
     */
    public function __construct(private \ArrayObject $written)
    {
    }

    public function connection(string $companyUuid): ?array
    {
        return ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1'];
    }

    public function save(SyncLedger $ledger): void
    {
        throw new \RuntimeException('flag() must not save the ledger');
    }

    protected function writePending(array $row): void
    {
        $this->written[] = $row;
    }
}
