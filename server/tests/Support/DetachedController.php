<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;

/**
 * A connection controller for tests that run without the link tables.
 */
class DetachedController extends ConnectionController
{
    use NoLinkedData;
}
