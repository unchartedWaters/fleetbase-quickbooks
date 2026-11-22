<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\CompanyReset;

/**
 * For a connection controller in a test with no link tables: nothing is linked, so no question is asked on a switch.
 */
trait NoLinkedData
{
    protected function reset(): CompanyReset
    {
        return new EmptyReset(new MemorySettingsStore());
    }

    protected function leavesLinksBehind(string $companyUuid, string $realmId): bool
    {
        return false;
    }
}
