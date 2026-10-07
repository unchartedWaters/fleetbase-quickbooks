<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Events;

use Fleetbase\TestSupport\EventRecorder;

trait Dispatchable
{
    public static function dispatch(...$arguments): object
    {
        return EventRecorder::record(new static(...$arguments));
    }
}
