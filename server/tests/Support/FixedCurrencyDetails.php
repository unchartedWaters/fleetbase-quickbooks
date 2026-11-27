<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\CompanyDetails;

/**
 * CompanyDetails with the organization currency fixed, so no Fleetbase database is needed.
 */
class FixedCurrencyDetails extends CompanyDetails
{
    public mixed $currency = null;

    protected function storedCurrency(string $companyUuid): mixed
    {
        return $this->currency;
    }
}
