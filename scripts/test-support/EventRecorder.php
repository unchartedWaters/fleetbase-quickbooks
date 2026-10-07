<?php

declare(strict_types=1);

namespace Fleetbase\TestSupport;

class EventRecorder
{
    public static array $events = [];

    public static function record(object $event): object
    {
        self::$events[] = $event;

        return $event;
    }

    public static function reset(): void
    {
        self::$events = [];
    }
}
