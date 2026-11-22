<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\CompanyReset;

/**
 * A company with nothing linked to QuickBooks, for tests that run without the link tables.
 */
class EmptyReset extends CompanyReset
{
    public function linkCounts(string $companyUuid, bool $withImported = false): array
    {
        return [
            'customers' => 0,
            'invoices'  => 0,
            'wallets'   => 0,
            'products'  => 0,
            'total'     => 0,
            'imported'  => 0,
            'kept'      => 0,
        ];
    }

    public function linksOutsideRealm(string $companyUuid, string $realm): int
    {
        return 0;
    }
}
