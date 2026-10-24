<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;

/**
 * When a queued webhook job that could not read from QuickBooks runs again. Intuit already
 * received HTTP 200 for the delivery and does not send it again, so a temporary QuickBooks
 * failure (a 429, a 5xx or no response) queues the job again instead of dropping the change.
 */
class WebhookRetry
{
    /**
     * Retries after the first run. A run past this that still cannot read QuickBooks is
     * recorded as a failed sync.
     */
    public const LIMIT = 5;

    /**
     * The first wait after a 5xx or no response. It doubles on each retry.
     */
    public const BASE_DELAY_SECONDS = 30;

    public const MESSAGE = 'quickbooks.messages.webhook-read-failed';

    /**
     * The first wait after the company lock was busy (or a token refresh could not run). It
     * doubles per retry up to BUSY_MAX_DELAY_SECONDS, so eight retries wait about 515 seconds:
     * close to the lock lifetime, the longest a held or orphaned lock can block.
     */
    public const BUSY_BASE_DELAY_SECONDS = 5;

    public const BUSY_MAX_DELAY_SECONDS = 120;

    /**
     * Seconds until a stored rate limit ends, or 0 when QuickBooks may be called now.
     *
     * @param array<string, mixed>|null $connection
     */
    public static function rateLimitedFor(?array $connection, int $now): int
    {
        $stored = is_array($connection) === true ? ($connection['rate_limited_until'] ?? null) : null;
        $until  = FleetbaseDirectory::timestamp($stored);

        return $until !== null && $until > $now ? $until - $now : 0;
    }

    /**
     * Seconds before the next try. A 429 waits for its Retry-After, or doubles the last
     * rate-limit wait. Any other temporary failure waits 30 seconds, doubled per retry
     * already made, and never less than a rate limit that is still running.
     *
     * @param array<string, mixed>|null $connection
     */
    public static function delay(QuickBooksException $exception, ?array $connection, int $retries, int $now): int
    {
        $policy = new BackoffPolicy(static fn (int $wait): int => $wait);
        if ($exception->isRateLimit() === true) {
            $stored   = is_array($connection) === true ? ($connection['last_rate_limit_wait'] ?? null) : null;
            $previous = is_numeric($stored) === true ? (int) $stored : null;

            return $policy->rateLimitWait($exception->retryAfter, $previous);
        }

        $wait = $policy->defaultWait(self::BASE_DELAY_SECONDS, $retries + 1);

        return max($wait, self::rateLimitedFor($connection, $now));
    }

    /**
     * The queue delay after $retries busy-lock retries already made, with jitter.
     */
    public static function busyDelay(int $retries): int
    {
        $wait = self::BUSY_BASE_DELAY_SECONDS;
        for ($step = 0; $step < $retries && $wait < self::BUSY_MAX_DELAY_SECONDS; $step++) {
            $wait *= 2;
        }

        return self::spread(min($wait, self::BUSY_MAX_DELAY_SECONDS));
    }

    /**
     * The queue delay for a retry: the wait plus up to a tenth of it (at most 60 seconds), so
     * jobs held by the same rate limit wake at slightly different times instead of all calling
     * QuickBooks at the same moment and running into a new 429.
     */
    public static function spread(int $wait): int
    {
        if ($wait < 1) {
            return 0;
        }

        return $wait + random_int(0, max(1, min(60, intdiv($wait, 10))));
    }

    /**
     * The connection with the rate limit a 429 asks for, so the scheduled sync and the
     * other webhook jobs also wait it out. Other failures leave the connection unchanged.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public static function withRateLimit(array $connection, QuickBooksException $exception, int $wait, int $now): array
    {
        if ($exception->isRateLimit() === false) {
            return $connection;
        }
        $connection['rate_limited_until']   = $now + $wait;
        $connection['last_rate_limit_wait'] = $wait;

        return $connection;
    }

    /**
     * The failed-sync message shown once the retries are used up.
     */
    public static function failedMessage(QuickBooksException $exception): string
    {
        return Translation::append(self::MESSAGE, $exception->getMessage());
    }
}
