<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Support\Facades\Log;

/**
 * Logging that can never throw. Used where a failure must not reach the caller,
 * such as the observers on Fleetbase's own models. Callers pass no secrets:
 * only ids and the exception class and message.
 */
class SafeLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            // Nothing more can be done when logging itself fails.
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        try {
            Log::debug($message, $context);
        } catch (\Throwable) {
            // Nothing more can be done when logging itself fails.
        }
    }
}
