<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The QuickBooks number last reserved for a Fleetbase invoice's create. When the answer to that
 * create is lost, the retry looks the number up before creating again: with custom transaction
 * numbers a retry gets a new number, so its body and requestid differ and Intuit would save a
 * second invoice. A missing cache store fails open (no lookup), as before this guard.
 */
class ReservedDocNumbers
{
    /** About as long as outage retries keep going, so a late retry still finds the number. */
    public const SECONDS = 259200;

    public static function remember(string $realmId, string $invoiceUuid, string $number): void
    {
        try {
            Cache::put(self::key($realmId, $invoiceUuid), $number, self::SECONDS);
        } catch (\Throwable) {
            // Without a cache the retry cannot look the number up; the create still runs.
        }
    }

    public static function recall(string $realmId, string $invoiceUuid): ?string
    {
        try {
            $number = Cache::get(self::key($realmId, $invoiceUuid));
        } catch (\Throwable) {
            return null;
        }

        return is_string($number) === true && $number !== '' ? $number : null;
    }

    private static function key(string $realmId, string $invoiceUuid): string
    {
        return 'quickbooks.docnumber-reserved.' . $realmId . '.' . $invoiceUuid;
    }
}
