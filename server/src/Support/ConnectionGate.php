<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Models\Connection;

/**
 * Whether an organization has a QuickBooks company to sync.
 * A stored sync.enabled flag is not part of this check.
 */
class ConnectionGate
{
    /**
     * @param array<string, mixed>|Connection|null $connection
     */
    public static function hasRealm(mixed $connection): bool
    {
        return self::realm($connection) !== '';
    }

    /**
     * The install has a saved connection with a realm that does not need reauthorization.
     * Client ID and client secret are not read.
     */
    public static function hasActiveConnection(): bool
    {
        try {
            return Connection::query()
                ->where('needs_reauth', false)
                ->whereNotNull('realm_id')
                ->where('realm_id', '!=', '')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A realm is stored and QuickBooks does not need to be connected again.
     *
     * @param array<string, mixed>|Connection|null $connection
     */
    public static function usable(mixed $connection): bool
    {
        if (!self::hasRealm($connection)) {
            return false;
        }
        if ($connection instanceof Connection) {
            return $connection->needs_reauth !== true;
        }

        return empty($connection['needs_reauth']);
    }

    /**
     * @param array<string, mixed>|Connection|null $connection
     */
    private static function realm(mixed $connection): string
    {
        if ($connection instanceof Connection) {
            $realm = $connection->realm_id;

            return is_string($realm) ? trim($realm) : '';
        }
        if (!is_array($connection)) {
            return '';
        }
        $realm = $connection['realm_id'] ?? null;

        return is_string($realm) ? trim($realm) : '';
    }
}
