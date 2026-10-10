<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Runs the flagging work behind a save of a Fleetbase model, and keeps a failure of
 * that work from reaching the save. A missing quickbooks table (the application
 * deployed before its migration), a dropped database connection or a bug in this
 * extension must never stop a customer, place, invoice or wallet from being saved.
 * The sync catches up on the next full pass.
 */
class FlagGuard
{
    /**
     * @param callable             $work    the flagging work
     * @param string               $what    short description for the log, e.g. "customer"
     * @param array<string, mixed> $context ids only: never tokens or record contents
     */
    public static function run(callable $work, string $what, array $context = []): void
    {
        try {
            $work();
        } catch (\Throwable $exception) {
            // The class and code only. A database error message carries the SQL and its
            // bound values, which are customer data.
            SafeLog::warning('QuickBooks could not flag a Fleetbase ' . $what . ' change; the change itself was saved.', $context + [
                'exception' => $exception::class,
                'code'      => $exception->getCode(),
            ]);
        }
    }
}
