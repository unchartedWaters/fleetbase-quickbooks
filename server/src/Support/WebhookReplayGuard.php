<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Age and replay checks for signed QuickBooks webhook deliveries. A delivery whose change
 * timestamps are older than quickbooks.webhook.max_age_seconds is stale. A body is remembered
 * briefly while it is processed and for the maximum age once it was processed, so a repeat is
 * refused while a failed or interrupted delivery can still be retried by Intuit.
 */
class WebhookReplayGuard
{
    /**
     * Used when quickbooks.webhook.max_age_seconds is missing or not a positive number.
     */
    private const DEFAULT_MAX_AGE_SECONDS = 600;

    /**
     * How long a body is remembered while it is being processed, before it is committed for
     * the full maximum age. It only has to outlast the request.
     */
    private const PROCESSING_SECONDS = 120;

    /**
     * ok the first time this raw body is seen. replay when the same bytes were
     * stored already. unavailable when the replay store cannot be written.
     */
    public function remember(string $rawBody): string
    {
        try {
            $added = Cache::add($this->key($rawBody), 1, self::PROCESSING_SECONDS);
        } catch (\Throwable) {
            return 'unavailable';
        }

        return $added === true ? 'ok' : 'replay';
    }

    /**
     * The body was processed: remember it for the full maximum age. If the store cannot be
     * written, the short processing key still blocks an immediate repeat.
     */
    public function keep(string $rawBody): void
    {
        try {
            Cache::put($this->key($rawBody), 1, $this->maxAgeSeconds());
        } catch (\Throwable) {
            // The work is queued; only the replay window is shorter.
        }
    }

    /**
     * Forget a remembered body so a retry of it is processed again.
     * A store that cannot be reached leaves the key to expire on its own.
     */
    public function forget(string $rawBody): void
    {
        try {
            Cache::forget($this->key($rawBody));
        } catch (\Throwable) {
            // The original error is the one to report.
        }
    }

    /**
     * True when a signed change timestamp (CloudEvents time, or the older lastUpdated) is
     * older than the maximum delivery age.
     * A body with no timestamp is left to the replay store.
     */
    public function isStale(string $rawBody, int $now): bool
    {
        $maxAge = $this->maxAgeSeconds();
        foreach (WebhookPayload::changes($rawBody) as $change) {
            if (array_key_exists('time', $change) === false) {
                continue;
            }
            $timestamp = $this->timestamp($change['time']);
            if ($timestamp === null || ($now - $timestamp) > $maxAge) {
                return true;
            }
        }

        return false;
    }

    private function key(string $rawBody): string
    {
        return 'quickbooks.webhook.replay.' . hash('sha256', $rawBody);
    }

    /**
     * The oldest signed delivery that is accepted. The same value is how long a body is
     * remembered for replay protection, so the age check and the replay store agree.
     */
    private function maxAgeSeconds(): int
    {
        $configured = config('quickbooks.webhook.max_age_seconds', self::DEFAULT_MAX_AGE_SECONDS);
        if (is_numeric($configured) === false || (int) $configured < 1) {
            return self::DEFAULT_MAX_AGE_SECONDS;
        }

        return (int) $configured;
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value)))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
