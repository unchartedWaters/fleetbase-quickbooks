<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Models\Company;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Support\SafeLog;
use Illuminate\Support\Facades\Cache;

/**
 * The QuickBooks company name and home currency for the connection card. QuickBooks answers them
 * from CompanyInfo (CompanyName) and Preferences (CurrencyPrefs.HomeCurrency, a code with no name),
 * so they are read once a day per company and realm, not on every page load. A failed read is
 * remembered for a few minutes, so a QuickBooks outage does not add a request to every page load.
 */
class CompanyDetails
{
    public const TTL = 86400;

    public const RETRY = 300;

    public function __construct(private ConnectionProbe $probe)
    {
    }

    /**
     * @return array{company_name: string|null, home_currency: string|null}
     */
    public function for(Connection $connection): array
    {
        $none = ['company_name' => null, 'home_currency' => null];
        if ((string) $connection->realm_id === '' || $connection->needs_reauth === true) {
            return $none;
        }

        $key    = 'quickbooks.company-details.' . $connection->company_uuid . '.' . $connection->realm_id;
        $cached = Cache::get($key);
        if (is_array($cached) === true) {
            return [
                'company_name'  => is_string($cached['company_name'] ?? null) === true ? $cached['company_name'] : null,
                'home_currency' => is_string($cached['home_currency'] ?? null) === true ? $cached['home_currency'] : null,
            ];
        }

        $result  = $this->probe->probe(FleetbaseDirectory::connectionToArray($connection));
        $details = [
            'company_name'  => $result['company_name'],
            'home_currency' => $result['home_currency'],
        ];
        Cache::put($key, $details, $result['ok'] === true ? self::TTL : self::RETRY);

        return $details;
    }

    /**
     * The currency of the Fleetbase organization (companies.currency), upper case, or null when it
     * has none or cannot be read. It only adds a warning to the card, so it never fails the card.
     */
    public function organizationCurrency(string $companyUuid): ?string
    {
        try {
            $value = trim((string) $this->storedCurrency($companyUuid));
        } catch (\Throwable $exception) {
            SafeLog::debug('The organization currency could not be read.', ['company_uuid' => $companyUuid]);

            return null;
        }

        return $value === '' ? null : strtoupper($value);
    }

    protected function storedCurrency(string $companyUuid): mixed
    {
        return Company::query()->where('uuid', $companyUuid)->value('currency');
    }
}
