<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\WebhookRetry;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyRemoteChange implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array<int, string> */
    public const ENTITIES = ['Customer', 'Invoice', 'Payment', 'Account', SyncEngine::INVOICE_PAYMENTS];

    /**
     * Intuit does not send a delivery again once it got HTTP 200, and Laravel must not run
     * this job again on its own. A busy company lock and a QuickBooks read that failed for a
     * temporary reason are the exceptions: that delivery is queued again here.
     */
    public int $tries = 1;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    /**
     * Retries after a busy company lock, waiting WebhookRetry::busyDelay() each time, so a
     * delivery outlasts a lock held (or orphaned) for its whole lifetime.
     */
    public const LOCK_RETRY_LIMIT = 8;

    /**
     * Retries made after QuickBooks could not answer. Not a constructor argument, so a job
     * queued before this counter existed still loads, and starts at 0.
     */
    public int $quickbooksAttempt = 0;

    /**
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     */
    public function __construct(
        public string $companyUuid,
        public array $entities,
        public int $lockAttempt = 0,
    ) {
    }

    /**
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     */
    public static function dispatch(string $companyUuid, array $entities): void
    {
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch(new self($companyUuid, $entities));
        }
    }

    public function handle(
        SyncEngine $engine,
        FleetbaseDirectory $directory,
        SettingsService $settings,
        SettingsStore $store,
        ?ConnectionTokens $tokens = null,
    ): void {
        $entities   = $this->knownEntities();
        $connection = $directory->connection($this->companyUuid);
        if ($entities === [] || ConnectionGate::hasRealm($connection) === false) {
            return;
        }
        if ($this->waitedForRateLimit($directory, $connection) === true) {
            return;
        }

        $lock = $this->acquiredWebhookLock($directory);
        if ($lock === null) {
            return;
        }

        $this->acceptWebhookChanges($engine, $directory, $settings, $store, $tokens, $lock, $entities);
    }

    private function acquiredWebhookLock(FleetbaseDirectory $directory): ?Lock
    {
        $lock = BatchRunner::lock($this->companyUuid);
        if ($lock === null) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', BatchRunner::LOCK_UNAVAILABLE);

            return null;
        }
        if ($lock->get() === false) {
            // Activity records the busy lock only once this delivery gives up.
            if ($this->retryBusyLock() === false) {
                $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', 'quickbooks.messages.sync-running');
            }

            return null;
        }

        return $lock;
    }

    /**
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     */
    private function acceptWebhookChanges(
        SyncEngine $engine,
        FleetbaseDirectory $directory,
        SettingsService $settings,
        SettingsStore $store,
        ?ConnectionTokens $tokens,
        Lock $lock,
        array $entities,
    ): void {
        // Same boundary as a company batch: the lock covers the local read and the
        // local save. QuickBooks queries, including payments on an invoice, run
        // after this releases it. The lock is not held for the job timeout.
        $engine->setHttpBoundary(function (callable $call) use ($lock) {
            if (BatchRunner::holds($this->companyUuid) === true) {
                $lock->release();
            }

            return $call();
        });
        $ledger = null;
        $save   = false;
        try {
            [$ledger, $save] = $this->loadedWebhookChanges($engine, $directory, $settings, $store, $tokens, $lock, $entities);
        } finally {
            $this->finishWebhook($engine, $directory, $lock, $save, $ledger);
        }
    }

    /**
     * Links for these QuickBooks ids, and the local rows those links need.
     * Direction filtering happens inside acceptRemoteChanges. This does not reconcile the catalog.
     *
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     *
     * @return array{0: mixed, 1: bool}
     */
    private function loadedWebhookChanges(
        SyncEngine $engine,
        FleetbaseDirectory $directory,
        SettingsService $settings,
        SettingsStore $store,
        ?ConnectionTokens $tokens,
        Lock $lock,
        array $entities,
    ): array {
        $loaded = $directory->loadLinked($this->companyUuid, $entities);
        if (is_array($loaded) === false) {
            return [null, false];
        }

        $syncSettings = $settings->resolveSync(
            $store->companySync($this->companyUuid),
            $store->adminSync(),
            $store->defaultSync()
        );
        $ledger = $loaded['ledger'];
        if ($ledger instanceof SyncLedger === false) {
            return [$ledger, false];
        }
        if ($tokens !== null && $this->tokenUnusable($engine, $directory, $tokens, $ledger, $lock) === true) {
            return [$ledger, false];
        }
        try {
            $engine->acceptRemoteChanges($ledger, $this->companyUuid, $entities, $syncSettings, time());
        } catch (QuickBooksException $exception) {
            if ($exception->isTransient() === false) {
                throw $exception;
            }
            // Changes read before the failure are saved; the retry reads them again.
            $this->quickbooksUnavailable($ledger, $exception);
        }

        return [$ledger, true];
    }

    /**
     * True when QuickBooks asked for a wait that has not ended. This delivery is then
     * queued for after it, so it does not call QuickBooks while rate limited.
     *
     * @param array<string, mixed>|null $connection
     */
    private function waitedForRateLimit(FleetbaseDirectory $directory, ?array $connection): bool
    {
        $wait = WebhookRetry::rateLimitedFor($connection, time());
        if ($wait < 1) {
            return false;
        }
        if ($this->retryQuickBooks($wait) === false) {
            $this->logGaveUp('rate limited');
            $directory->saveFailed($this->companyUuid, 'webhook', 'inbound', WebhookRetry::MESSAGE);
        }

        return true;
    }

    /**
     * QuickBooks answered with a 429 or a 5xx, or did not answer. Store a rate limit on the
     * connection, then queue this delivery again, or record it as failed after the last retry.
     */
    private function quickbooksUnavailable(SyncLedger $ledger, QuickBooksException $exception): void
    {
        $now        = time();
        $company    = $this->companyUuid;
        $connection = $ledger->connection($company) ?? [];
        $wait       = WebhookRetry::delay($exception, $connection, $this->quickbooksAttempt, $now);
        if (isset($ledger->connections[$company]) === true) {
            $ledger->connections[$company] = WebhookRetry::withRateLimit($connection, $exception, $wait, $now);
        }
        if ($this->retryQuickBooks($wait) === false) {
            $this->logGaveUp('status ' . $exception->status);
            $ledger->fail($this->companyUuid, 'webhook', 'inbound', WebhookRetry::failedMessage($exception), $now);
        }
    }

    /**
     * Queue this delivery again after $wait seconds. False when the retries are used up.
     */
    private function retryQuickBooks(int $wait): bool
    {
        if ($this->quickbooksAttempt >= WebhookRetry::LIMIT) {
            return false;
        }

        $retry                    = new self($this->companyUuid, $this->entities, $this->lockAttempt);
        $retry->quickbooksAttempt = $this->quickbooksAttempt + 1;
        $retry->delay(WebhookRetry::spread($wait));
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch($retry);
        }

        return true;
    }

    private function logGaveUp(string $reason): void
    {
        SafeLog::warning('QuickBooks webhook change dropped after its last retry.', [
            'company_uuid' => $this->companyUuid,
            'retries'      => $this->quickbooksAttempt,
            'reason'       => $reason,
        ]);
    }

    private function finishWebhook(SyncEngine $engine, FleetbaseDirectory $directory, Lock $lock, bool $save, mixed $ledger): void
    {
        $engine->setHttpBoundary(null);
        try {
            if ($save === true && $ledger instanceof SyncLedger === true) {
                $this->reacquireCompanyLock($lock);
                $directory->save($ledger);
            }
        } finally {
            if (BatchRunner::holds($this->companyUuid) === true) {
                $lock->release();
            }
        }
    }

    /**
     * Refresh the access token when it is close to expiring, as a company batch does, and
     * put the result on the ledger connection. The webhook can arrive long after the last
     * sync, so the stored token may have expired. The Intuit call runs with the company
     * lock released and takes it again itself. True when QuickBooks cannot be called: the
     * run is recorded as skipped, and a temporary refresh failure queues this delivery again.
     */
    private function tokenUnusable(SyncEngine $engine, FleetbaseDirectory $directory, ConnectionTokens $tokens, SyncLedger $ledger, Lock $lock): bool
    {
        $connection = $ledger->connection($this->companyUuid);
        if (is_array($connection) === false) {
            return false;
        }

        $now        = time();
        $refreshed  = $engine->runHttp(fn () => $tokens->refreshIfDue($connection, $now));
        $connection = is_array($refreshed) === true ? $refreshed : $connection;
        $blocked    = ConnectionTokens::blockedMessage($connection, $now);
        if ($blocked === null) {
            $ledger->connections[$this->companyUuid] = $connection;

            return false;
        }

        $this->reacquireCompanyLock($lock);
        $temporary = in_array($blocked, [ConnectionTokens::ALREADY_RUNNING, TokenRefresher::UNAVAILABLE_MESSAGE], true);
        if ($temporary === false || $this->retryBusyLock() === false) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', $blocked);
        }

        return true;
    }

    /**
     * The company lock is held by another sync. Queue this delivery again. False once the
     * retries are used up: giving up drops the webhook, as Intuit already received HTTP 200.
     */
    private function retryBusyLock(): bool
    {
        if ($this->lockAttempt >= self::LOCK_RETRY_LIMIT) {
            return false;
        }

        $retry                    = new self($this->companyUuid, $this->entities, $this->lockAttempt + 1);
        $retry->quickbooksAttempt = $this->quickbooksAttempt;
        $retry->delay(WebhookRetry::busyDelay($this->lockAttempt));
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch($retry);
        }

        return true;
    }

    /**
     * Take the company lock again after QuickBooks HTTP released it.
     * A local save still runs when the lock cannot be taken, so rows already
     * applied in memory are not dropped.
     */
    private function reacquireCompanyLock(Lock $lock): void
    {
        if (BatchRunner::holds($this->companyUuid) === true) {
            return;
        }
        if ($lock->get() === true) {
            return;
        }

        try {
            $lock->block(BatchRunner::LOCK_SECONDS);
        } catch (\Throwable) {
            // Save anyway. The local rows are already decided.
        }
    }

    /**
     * @return array<int, array{entity: string, id: string, operation: string}>
     */
    private function knownEntities(): array
    {
        $entities = [];
        foreach ($this->entities as $entity) {
            if (is_array($entity) === false) {
                continue;
            }
            $name = $entity['entity'] ?? null;
            if (is_string($name) === false || in_array($name, self::ENTITIES, true) === false) {
                continue;
            }
            $id = $entity['id'] ?? null;
            if (is_int($id) === true) {
                $id = (string) $id;
            }
            if (is_string($id) === false || $id === '') {
                continue;
            }
            $operation = $entity['operation'] ?? '';
            if (is_string($operation) === false) {
                continue;
            }
            $entities[] = [
                'entity'    => $name,
                'id'        => $id,
                'operation' => $operation,
            ];
        }

        return $entities;
    }
}
