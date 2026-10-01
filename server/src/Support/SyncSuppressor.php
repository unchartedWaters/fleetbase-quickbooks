<?php

namespace Fleetbase\Quickbooks\Support;

class SyncSuppressor
{
    private static int $depth = 0;

    public static function pause(): void
    {
        self::$depth++;
    }

    public static function resume(): void
    {
        if (self::$depth > 0) {
            self::$depth--;
        }
    }

    public static function paused(): bool
    {
        return self::$depth > 0;
    }
}
