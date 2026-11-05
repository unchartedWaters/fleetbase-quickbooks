<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * The console address. Intuit sends the browser back to the console's QuickBooks page,
 * which is the OAuth redirect URL. It is computed from CONSOLE_HOST and is never stored.
 */
class ConsoleHost
{
    /**
     * The console page the engine is mounted at (package.json fleetbase.route).
     */
    public const QUICKBOOKS_PATH = '/quickbooks';

    /**
     * The console origin, or '' when it is not known.
     * QUICKBOOKS_CONSOLE_HOST is used first, then CONSOLE_HOST (fleetbase.console.host).
     */
    public static function origin(): string
    {
        $host = trim((string) config('quickbooks.console_host', ''));
        if ($host === '') {
            // fleetbase.console.host falls back to 'fleetbase.io' when CONSOLE_HOST is unset.
            $fleetbaseHost = trim((string) config('fleetbase.console.host', ''));
            $host          = $fleetbaseHost === 'fleetbase.io' ? '' : $fleetbaseHost;
        }
        if ($host === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $host) !== 1) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/');
    }

    /**
     * The OAuth redirect URL: the console's QuickBooks page, or '' when the console host is not known.
     */
    public static function oauthRedirectUrl(): string
    {
        $origin = self::origin();

        return $origin === '' ? '' : $origin . self::QUICKBOOKS_PATH;
    }
}
