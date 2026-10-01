<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Decides whether the every-minute scheduler should start quickbooks:sync.
 * The cron entry stays every minute so a 1-minute frequency can still run.
 */
class SyncSchedule
{
    private const NOT_BEFORE = 'quickbooks.schedule.not_before';

    /**
     * True when no future hold is stored. A missing cache store fails open.
     */
    public static function shouldStart(int $now): bool
    {
        if (!self::cacheReady()) {
            return true;
        }

        try {
            $until = Cache::get(self::NOT_BEFORE);
        } catch (\Throwable) {
            return true;
        }

        return !is_int($until) || $until <= $now;
    }

    /**
     * Hold the next start until at least 60 seconds from $now.
     */
    public static function defer(int $now, int $seconds): void
    {
        if (!self::cacheReady()) {
            return;
        }

        try {
            Cache::forever(self::NOT_BEFORE, $now + max(60, $seconds));
        } catch (\Throwable) {
            // A missing cache must not stop the sync command.
        }
    }

    /**
     * Drop the hold so the next schedule:run can start the command.
     */
    public static function wake(): void
    {
        if (!self::cacheReady()) {
            return;
        }

        try {
            Cache::forget(self::NOT_BEFORE);
        } catch (\Throwable) {
            // Flagging must succeed even when the cache is not booted.
        }
    }

    private static function cacheReady(): bool
    {
        try {
            return is_object(Cache::getFacadeRoot());
        } catch (\Throwable) {
            return false;
        }
    }
}
