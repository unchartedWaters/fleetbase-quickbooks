<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;

/**
 * A directory with no connections that counts every connection read, across instances.
 */
class CountingDirectory extends FleetbaseDirectory
{
    public static int $connectionReads = 0;

    /**
     * @var array<string, array<string, mixed>>
     */
    public static array $connections = [];

    public function connection(string $companyUuid): ?array
    {
        self::$connectionReads++;

        return self::$connections[$companyUuid] ?? null;
    }
}
