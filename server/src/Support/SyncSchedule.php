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
     * True when QuickBooks is connected and no future hold is stored.
     * A saved client id without a connection does not start a sync.
     */
    public static function shouldRun(int $now, bool $connected): bool
    {
        return $connected === true && self::shouldStart($now) === true;
    }

    /**
     * True when no future hold is stored. A missing cache store fails open.
     */
    public static function shouldStart(int $now): bool
    {
        if (self::cacheReady() === false) {
            return true;
        }

        try {
            $until = Cache::get(self::NOT_BEFORE);
        } catch (\Throwable) {
            return true;
        }

        // Redis hands this value back as a numeric string. That is still a hold.
        if (is_int($until) === false && (is_string($until) === true && ctype_digit($until) === true) === false) {
            return true;
        }

        return (int) $until <= $now;
    }

    /**
     * Hold the next start until at least 60 seconds from $now.
     */
    public static function defer(int $now, int $seconds): void
    {
        if (self::cacheReady() === false) {
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
        if (self::cacheReady() === false) {
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
