<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;

/**
 * A connection controller that lets a test save a freshly authorized connection directly.
 */
class PersistingController extends ConnectionController
{
    /**
     * @param array<string, mixed> $connection
     */
    public function save(array $connection): void
    {
        $this->persist($connection);
    }
}
