<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Age and replay checks for signed QuickBooks webhook deliveries. A delivery whose change
 * timestamps are older than quickbooks.webhook.max_age_seconds is stale, and one older than
 * quickbooks.webhook.stale_replay_seconds is expired. A body is remembered briefly while it is
 * processed, then for the maximum age (or, for a stale body, the stale replay window) once it
 * was processed, so a repeat is refused while a failed or interrupted delivery can still be
 * retried by Intuit. A processed body is remembered for as long as it would be accepted.
 */
class WebhookReplayGuard
{
    /**
     * Used when quickbooks.webhook.max_age_seconds is missing or not a positive number.
     */
    private const DEFAULT_MAX_AGE_SECONDS = 600;

    /**
     * Used when quickbooks.webhook.stale_replay_seconds is missing or not a number: 7 days.
     */
    private const DEFAULT_STALE_REPLAY_SECONDS = 604800;

    /**
     * How long a body is remembered while it is being processed, before it is committed for
     * the full maximum age. It only has to outlast the request.
     */
    private const PROCESSING_SECONDS = 120;

    /**
     * ok the first time this raw body is seen. done when the same bytes were processed
     * already, processing while another request is still working on them. unavailable
     * when the replay store cannot be written.
     */
    public function remember(string $rawBody): string
    {
        try {
            $added = Cache::add($this->key($rawBody), 'processing', self::PROCESSING_SECONDS);
        } catch (\Throwable) {
            return 'unavailable';
        }
        if ($added === true) {
            return 'ok';
        }

        try {
            return Cache::get($this->key($rawBody)) === 'done' ? 'done' : 'processing';
        } catch (\Throwable) {
            return 'processing';
        }
    }

    /**
     * The body was processed: remember it for the stale replay window, which is never shorter
     * than the maximum age. A body is then remembered for as long as any copy of it, fresh or
     * stale, would still be read, so a captured body is not processed twice. If the store
     * cannot be written, the short processing key still blocks an immediate repeat.
     */
    public function keep(string $rawBody): void
    {
        try {
            Cache::put($this->key($rawBody), 'done', $this->staleReplaySeconds());
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
     * A body with no timestamp is not stale; see hasUndatedChange.
     */
    public function isStale(string $rawBody, int $now): bool
    {
        return $this->olderThan($rawBody, $now, $this->maxAgeSeconds());
    }

    /**
     * True when a signed change timestamp is older than the stale replay window, or its value
     * cannot be parsed. Such a delivery is not applied at all: the replay store no longer
     * remembers a body that old, so applying it would let a captured body be replayed.
     * A change with no timestamp is not expired; its deletes and merges are withheld instead
     * (see hasUndatedChange).
     */
    public function isExpired(string $rawBody, int $now): bool
    {
        return $this->olderThan($rawBody, $now, $this->staleReplaySeconds());
    }

    /**
     * True when any change carries no timestamp. Its age cannot be checked, so once the replay
     * store forgets the body it could be replayed: its deletes and merges are not applied from
     * the payload, as for a stale body. Creates and updates are still read back from QuickBooks.
     */
    public function hasUndatedChange(string $rawBody): bool
    {
        foreach (WebhookPayload::changes($rawBody) as $change) {
            if (array_key_exists('time', $change) === false) {
                return true;
            }
        }

        return false;
    }

    private function olderThan(string $rawBody, int $now, int $seconds): bool
    {
        foreach (WebhookPayload::changes($rawBody) as $change) {
            if (array_key_exists('time', $change) === false) {
                continue;
            }
            $timestamp = $this->timestamp($change['time']);
            if ($timestamp === null || ($now - $timestamp) > $seconds) {
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

    /**
     * How long a processed stale body is remembered, and so the oldest delivery that is read
     * again at all. Never shorter than the maximum age: a window no longer than the maximum
     * age (for example 0) turns stale processing off.
     */
    private function staleReplaySeconds(): int
    {
        $configured = config('quickbooks.webhook.stale_replay_seconds', self::DEFAULT_STALE_REPLAY_SECONDS);
        if (is_numeric($configured) === false) {
            $configured = self::DEFAULT_STALE_REPLAY_SECONDS;
        }

        return max((int) $configured, $this->maxAgeSeconds());
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
