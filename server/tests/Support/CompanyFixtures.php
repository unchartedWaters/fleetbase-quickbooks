<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\ConnectionProbe;

class CompanyFixtures
{
    /**
     * @return array{0: FixedCurrencyDetails, 1: CountingCompanyClient}
     */
    public static function details(): array
    {
        $client = new CountingCompanyClient();

        return [new FixedCurrencyDetails(new ConnectionProbe($client)), $client];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function row(array $overrides = []): Connection
    {
        $row = new Connection();
        $row->forceFill($overrides + [
            'company_uuid' => 'company-1',
            'realm_id'     => 'realm-1',
            'access_token' => 'token',
            'needs_reauth' => false,
        ]);

        return $row;
    }
}
