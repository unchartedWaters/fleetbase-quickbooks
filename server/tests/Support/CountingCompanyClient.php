<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * Answers the two reads behind the connection card (CompanyInfo and Preferences) and counts them.
 */
class CountingCompanyClient extends QuickBooksClient
{
    public int $reads = 0;

    public bool $fail = false;

    public function companyInfo(array $connection): array
    {
        $this->reads++;
        if ($this->fail === true) {
            throw new QuickBooksException(503, 'QuickBooks is down.');
        }

        return ['CompanyName' => 'Craig Landscaping', 'Country' => 'US'];
    }

    public function homeCurrency(array $connection): ?string
    {
        return 'CAD';
    }
}
