<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\WebhookRetry;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Payment webhooks whose invoices are only known by reading the payment from QuickBooks.
 * The webhook request returns at once and leaves these here, so it never waits on Intuit
 * and never fails on an access token that expired since the last sync. The payment is read
 * with a refreshed token, then the usual inbound apply and outbound push are queued.
 */
class ResolveWebhookPayments implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Intuit already received HTTP 200. Laravel must not run this job again on its own.
     * A refresh that could not run, and a payment read QuickBooks could not answer for a
     * temporary reason, are the exceptions: that delivery is queued again here.
     */
    public int $tries = 1;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    public const RETRY_LIMIT = 3;

    public const RETRY_DELAY_SECONDS = 5;

    /**
     * Retries made after QuickBooks could not answer. Not a constructor argument, so a job
     * queued before this counter existed still loads, and starts at 0.
     */
    public int $quickbooksAttempt = 0;

    /**
     * The events come back from the queue payload, so handle() checks each one again.
     *
     * @param array<int, mixed> $events
     */
    public function __construct(
        public string $companyUuid,
        public array $events,
        public int $attempt = 0,
    ) {
    }

    /**
     * @param array<int, array{realm_id: string, entity_type: string, id: string, operation: string, local_uuid: string|null}> $events
     */
    public static function dispatch(string $companyUuid, array $events): void
    {
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch(new self($companyUuid, $events));
        }
    }

    public function handle(
        SettingsService $settings,
        SettingsStore $store,
        FleetbaseDirectory $directory,
        ConnectionTokens $tokens,
    ): void {
        $connection = $directory->connection($this->companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return;
        }
        $hold = WebhookRetry::rateLimitedFor($connection, time());
        if ($hold > 0) {
            $this->retryQuickBooks($directory, $hold, WebhookRetry::MESSAGE);

            return;
        }

        $now        = time();
        $connection = $tokens->refreshIfDue($connection, $now);
        $blocked    = ConnectionTokens::blockedMessage($connection, $now);
        if ($blocked !== null) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', $blocked);
            if (in_array($blocked, [ConnectionTokens::ALREADY_RUNNING, TokenRefresher::UNAVAILABLE_MESSAGE], true) === true) {
                $this->retryLater();
            }

            return;
        }

        try {
            $this->resolve($settings, $store);
        } catch (QuickBooksException $exception) {
            if ($exception->isTransient() === false) {
                throw $exception;
            }
            $this->quickbooksUnavailable($directory, $connection, $exception);
        }
    }

    /**
     * Read the keyed payments and queue the inbound apply and outbound push they need.
     * A QuickBooks read that fails for a temporary reason throws before anything is queued.
     */
    private function resolve(SettingsService $settings, SettingsStore $store): void
    {
        $listener = (new EnqueueWebhookSync($settings))->readingQuickBooks();
        foreach ($this->events as $event) {
            if (is_array($event) === false) {
                continue;
            }
            $localUuid = $event['local_uuid'] ?? null;
            $listener->handle(new QuickBooksEntityChanged(
                $this->companyUuid,
                (string) ($event['realm_id'] ?? ''),
                (string) ($event['entity_type'] ?? 'payment'),
                (string) ($event['id'] ?? ''),
                (string) ($event['operation'] ?? 'update'),
                is_string($localUuid) === true && $localUuid !== '' ? $localUuid : null
            ));
        }
        $listener->flush($store);
    }

    /**
     * QuickBooks answered the payment read with a 429 or a 5xx, or did not answer. Store a
     * rate limit on the connection, then queue this delivery again.
     *
     * @param array<string, mixed> $connection
     */
    private function quickbooksUnavailable(
        FleetbaseDirectory $directory,
        array $connection,
        QuickBooksException $exception,
    ): void {
        $now  = time();
        $wait = WebhookRetry::delay($exception, $connection, $this->quickbooksAttempt, $now);
        if ($exception->isRateLimit() === true) {
            $directory->saveRateLimit(WebhookRetry::withRateLimit($connection, $exception, $wait, $now));
        }
        $this->retryQuickBooks($directory, $wait, WebhookRetry::failedMessage($exception));
    }

    /**
     * Queue this delivery again after $wait seconds, or record it as failed with $message
     * once the retries are used up.
     */
    private function retryQuickBooks(FleetbaseDirectory $directory, int $wait, string $message): void
    {
        if ($this->quickbooksAttempt >= WebhookRetry::LIMIT) {
            SafeLog::warning('QuickBooks webhook payments dropped after their last retry.', [
                'company_uuid' => $this->companyUuid,
                'retries'      => $this->quickbooksAttempt,
            ]);
            $directory->saveFailed($this->companyUuid, 'webhook', 'inbound', $message);

            return;
        }

        $retry                    = new self($this->companyUuid, $this->events, $this->attempt);
        $retry->quickbooksAttempt = $this->quickbooksAttempt + 1;
        $retry->delay($wait);
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch($retry);
        }
    }

    /**
     * The token could not be refreshed for a temporary reason. Queue this delivery again.
     */
    private function retryLater(): void
    {
        if ($this->attempt >= self::RETRY_LIMIT) {
            return;
        }

        $retry                    = new self($this->companyUuid, $this->events, $this->attempt + 1);
        $retry->quickbooksAttempt = $this->quickbooksAttempt;
        $retry->delay(self::RETRY_DELAY_SECONDS * ($this->attempt + 1));
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch($retry);
        }
    }
}
