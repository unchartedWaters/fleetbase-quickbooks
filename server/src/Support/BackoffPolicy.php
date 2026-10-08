<?php

namespace Fleetbase\Quickbooks\Support;

class BackoffPolicy
{
    public const CAP_SECONDS = 900;

    /** @var callable(int): int */
    private $jitter;

    /**
     * @param callable(int): int|null $jitter
     */
    public function __construct(?callable $jitter = null)
    {
        $this->jitter = $jitter ?? static fn (int $wait): int => $wait + random_int(0, max(1, (int) floor($wait * 0.1)));
    }

    /**
     * Wait after an HTTP 429. A non-empty Retry-After wins, including on a later 429.
     * With no Retry-After, a previous wait is doubled. With neither, wait is 60 seconds.
     * This path does not use the default backoff setting.
     */
    public function rateLimitWait(?string $retryAfter, ?int $previousWait): int
    {
        if ($retryAfter !== null && $retryAfter !== '') {
            $wait = $this->parseRetryAfter($retryAfter);
        } elseif ($previousWait !== null && $previousWait > 0) {
            $wait = $previousWait * 2;
        } else {
            $wait = 60;
        }

        $jitter = $this->jitter;
        $wait   = (int) $jitter($wait);

        return min(self::CAP_SECONDS, max(1, $wait));
    }

    /**
     * Wait before a retry that is not a rate limit.
     * $attempt is 1 for the first retry: wait = base * 2^(attempt - 1), capped at 900 seconds.
     */
    public function defaultWait(int $baseSeconds, int $attempt): int
    {
        $retryIndex = max(0, $attempt - 1);
        $wait       = max(1, $baseSeconds);
        for ($step = 0; $step < $retryIndex; $step++) {
            if ($wait > intdiv(self::CAP_SECONDS, 2)) {
                return self::CAP_SECONDS;
            }
            $wait *= 2;
        }

        return min(self::CAP_SECONDS, $wait);
    }

    private function parseRetryAfter(string $retryAfter): int
    {
        if (is_numeric($retryAfter) === true) {
            return max(1, (int) $retryAfter);
        }

        $timestamp = strtotime($retryAfter);
        if ($timestamp === false) {
            return 60;
        }

        return max(1, $timestamp - time());
    }
}
