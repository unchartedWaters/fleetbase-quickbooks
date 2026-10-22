<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\CustomerImporter;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\WebhookRetry;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportCustomers implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const DEADLINE_MARGIN_SECONDS = 30;

    public const CONTINUATION_RETRY_LIMIT = 3;

    public const CONTINUATION_RETRY_DELAY_SECONDS = 5;

    /**
     * Connect dispatches the sync first, and that sync holds the company lock.
     * Poll until the lock can expire, then the import runs or the caller records the skip.
     */
    public const LOCK_WAIT_DELAY_SECONDS = 15;

    public int $tries = 1;

    /** Retries of a named import that could not read QuickBooks, as webhook jobs count them. */
    public int $quickbooksAttempt = 0;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    /**
     * How long a customer created in QuickBooks waits before it is imported. A webhook for a
     * customer Fleetbase itself just created can arrive before that sync saved its link; by
     * then the link is there and the customer is skipped instead of imported twice.
     */
    public const NAMED_IMPORT_DELAY_SECONDS = 120;

    /**
     * @param array<int, string> $customerIds QuickBooks customers to import; empty imports the catalog
     * @param string             $realmId     the QuickBooks company those ids belong to
     */
    public function __construct(
        public string $companyUuid,
        public ?int $continuationAttempt = null,
        public bool $waitForLock = false,
        public int $lockWaitSeconds = 0,
        public array $customerIds = [],
        public string $realmId = '',
    ) {
    }

    /**
     * @param Connection|array<string, mixed>|null $connection
     */
    public static function blockedMessage(Connection|array|null $connection): string
    {
        $needsReauth = $connection instanceof Connection === true
            ? (bool) $connection->needs_reauth
            : (is_array($connection) === true && empty($connection['needs_reauth']) === false);

        return $needsReauth === true
            ? 'quickbooks.messages.import-reauth'
            : 'quickbooks.messages.not-connected-connect';
    }

    public static function dispatch(string $companyUuid): void
    {
        self::dispatchJob(new self($companyUuid));
    }

    /**
     * Import the QuickBooks customers a webhook reported as created, after a short delay.
     *
     * @param array<int, string> $customerIds
     */
    public static function dispatchCustomers(string $companyUuid, string $realmId, array $customerIds): void
    {
        $job = new self($companyUuid, null, true, 0, array_values(array_unique($customerIds)), $realmId);
        $job->delay(self::NAMED_IMPORT_DELAY_SECONDS);
        self::dispatchJob($job);
    }

    /**
     * Queue the customer import so a sync that already holds the company lock does not drop it.
     */
    public static function dispatchAfterLock(string $companyUuid): void
    {
        self::dispatchJob(new self($companyUuid, null, true));
    }

    public function handle(
        CustomerImporter $importer,
        FleetbaseDirectory $directory,
        ?ConnectionTokens $tokens = null,
        ?SettingsService $settings = null,
        ?SettingsStore $store = null,
    ): void {
        if (ConnectionGate::hasRealm($directory->connection($this->companyUuid)) === false) {
            return;
        }

        $lock = BatchRunner::lock($this->companyUuid);
        if ($lock === null) {
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', BatchRunner::LOCK_UNAVAILABLE);

            return;
        }
        if ($lock->get() === false) {
            if ($this->scheduleLockWait() === true) {
                return;
            }
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', 'quickbooks.messages.sync-running');
            $this->retryBusyContinuation();

            return;
        }

        $continue = false;
        try {
            $continue = $this->import($importer, $directory, $tokens, $settings, $store, $lock);
        } finally {
            if (BatchRunner::holds($this->companyUuid) === true) {
                $lock->release();
            }
            if ($continue === true) {
                // Mark only deadline-created jobs as continuations, and dispatch after releasing the company lock.
                self::dispatchJob(new self($this->companyUuid, 0, $this->waitForLock));
            }
        }
    }

    /**
     * True when this import was queued again and the busy lock was not recorded as a skip.
     */
    private function scheduleLockWait(): bool
    {
        if ($this->waitForLock !== true) {
            return false;
        }

        $waited = $this->lockWaitSeconds + self::LOCK_WAIT_DELAY_SECONDS;
        if ($waited > BatchRunner::LOCK_SECONDS) {
            return false;
        }

        $retry = new self(
            $this->companyUuid,
            $this->continuationAttempt,
            true,
            $waited,
            $this->customerIds,
            $this->realmId
        );
        $retry->delay(self::LOCK_WAIT_DELAY_SECONDS);
        self::dispatchJob($retry);

        return true;
    }

    private function retryBusyContinuation(): void
    {
        if ($this->continuationAttempt === null || $this->continuationAttempt >= self::CONTINUATION_RETRY_LIMIT) {
            return;
        }

        $attempt = $this->continuationAttempt + 1;
        $retry   = new self($this->companyUuid, $attempt, $this->waitForLock, 0, $this->customerIds, $this->realmId);
        $retry->delay(self::CONTINUATION_RETRY_DELAY_SECONDS * $attempt);
        self::dispatchJob($retry);
    }

    private static function dispatchJob(self $job): void
    {
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch($job);
        }
    }

    private function import(
        CustomerImporter $importer,
        FleetbaseDirectory $directory,
        ?ConnectionTokens $tokens,
        ?SettingsService $settings,
        ?SettingsStore $store,
        Lock $lock,
    ): bool {
        $connection = $this->importConnection($directory, $settings, $store);
        if ($connection === null) {
            return false;
        }

        // Token refresh and customer page queries must not sit inside the company lock.
        // The lock is taken again only for the local save.
        $this->releaseCompanyLock($lock);
        $connection = $this->refreshedImportConnection($directory, $tokens, $connection, $lock);
        if ($connection === null) {
            return false;
        }

        return $this->runCustomerImport($importer, $directory, $settings, $store, $lock, $connection);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function importConnection(FleetbaseDirectory $directory, ?SettingsService $settings, ?SettingsStore $store): ?array
    {
        $connection = $directory->connection($this->companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return null;
        }

        if (empty($connection['needs_reauth']) === false) {
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', self::blockedMessage($connection));

            return null;
        }

        if ($this->customersDisabled($settings, $store) === true) {
            $off = 'quickbooks.messages.import-customers-off';
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', $off);

            return null;
        }

        return $connection;
    }

    private function customersDisabled(?SettingsService $settings, ?SettingsStore $store): bool
    {
        if ($settings === null || $store === null) {
            return false;
        }

        $resolved = $settings->resolveSync(
            $store->companySync($this->companyUuid),
            $store->adminSync(),
            $store->defaultSync()
        );

        return array_key_exists('customer_enabled', $resolved) === true && $resolved['customer_enabled'] === false;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function refreshedImportConnection(FleetbaseDirectory $directory, ?ConnectionTokens $tokens, array $connection, Lock $lock): ?array
    {
        if ($tokens === null) {
            return $connection;
        }

        $connection = $tokens->refreshIfDue($connection, time());
        $blocked    = ConnectionTokens::blockedMessage($connection, time());
        if ($blocked !== null) {
            $this->ensureCompanyLock($lock);
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', $blocked);

            return null;
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function runCustomerImport(
        CustomerImporter $importer,
        FleetbaseDirectory $directory,
        ?SettingsService $settings,
        ?SettingsStore $store,
        Lock $lock,
        array $connection,
    ): bool {
        $ledger                                  = $directory->memory ?? new SyncLedger();
        $ledger->connections[$this->companyUuid] = $connection;
        $started                                 = time();
        if ($this->customerIds !== []) {
            return $this->runNamedImport($importer, $directory, $lock, $ledger, $connection);
        }

        try {
            $batch = $importer->import(
                $ledger,
                $connection,
                [],
                true,
                $this->pageSize($settings, $store),
                $started + BatchRunner::LOCK_SECONDS - self::DEADLINE_MARGIN_SECONDS
            );

            return empty($batch['continue']) === false;
        } finally {
            // Saved even when a page query throws, so customers already created keep their links.
            // The resume position is stored only once those customers are saved: a worker that
            // dies first, or a save that fails, imports the same pages again on the next run.
            $this->ensureCompanyLock($lock);
            $directory->saveImport($ledger);
            $importer->storeSavedCursor();
        }
    }

    /**
     * Only the customers a webhook named, and only while the organization is still connected
     * to the QuickBooks company they came from.
     *
     * @param array<string, mixed> $connection
     */
    private function runNamedImport(
        CustomerImporter $importer,
        FleetbaseDirectory $directory,
        Lock $lock,
        SyncLedger $ledger,
        array $connection,
    ): bool {
        $realm = (string) ($connection['realm_id'] ?? '');
        if ($this->realmId !== '' && $realm !== $this->realmId) {
            return false;
        }
        $linked = array_flip($directory->linkedRemoteIds($this->companyUuid, $realm, 'Customer', $this->customerIds));
        $ids    = array_values(array_filter(
            $this->customerIds,
            static fn (string $id): bool => isset($linked[$id]) === false
        ));
        if ($ids === []) {
            return false;
        }
        try {
            $importer->importIds($ledger, $connection, $ids);
        } catch (QuickBooksException $exception) {
            // Intuit does not send the webhook again, so a failed read is retried or shown in Activity.
            $this->namedImportFailed($directory, $ledger, $connection, $exception);
        } finally {
            $this->ensureCompanyLock($lock);
            $directory->saveImport($ledger);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function namedImportFailed(
        FleetbaseDirectory $directory,
        SyncLedger $ledger,
        array $connection,
        QuickBooksException $exception,
    ): void {
        $now = time();
        if ($exception->isTransient() === false || $this->quickbooksAttempt >= WebhookRetry::LIMIT) {
            $ledger->fail($this->companyUuid, 'import', 'inbound', WebhookRetry::failedMessage($exception), $now);

            return;
        }
        $wait = WebhookRetry::delay($exception, $connection, $this->quickbooksAttempt, $now);
        if ($exception->isRateLimit() === true) {
            $directory->saveRateLimit(WebhookRetry::withRateLimit($connection, $exception, $wait, $now));
        }
        $retry                    = new self($this->companyUuid, null, true, 0, $this->customerIds, $this->realmId);
        $retry->quickbooksAttempt = $this->quickbooksAttempt + 1;
        $retry->delay(WebhookRetry::spread($wait));
        self::dispatchJob($retry);
    }

    private function releaseCompanyLock(Lock $lock): void
    {
        if (BatchRunner::holds($this->companyUuid) === true) {
            $lock->release();
        }
    }

    /**
     * Take the company lock again after QuickBooks HTTP released it.
     * A local save still runs when the lock cannot be taken, so customers
     * already created are not dropped.
     */
    private function ensureCompanyLock(Lock $lock): void
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
            // Save anyway. The links are already decided.
        }
    }

    private function pageSize(?SettingsService $settings, ?SettingsStore $store): int
    {
        $batch = CustomerImporter::MAX_PAGE_SIZE;
        if ($settings !== null && $store !== null) {
            $resolved = $settings->resolveSync(
                $store->companySync($this->companyUuid),
                $store->adminSync(),
                $store->defaultSync()
            );
            $batch = (int) ($resolved['batch_size'] ?? CustomerImporter::MAX_PAGE_SIZE);
        }

        return max(1, min(CustomerImporter::MAX_PAGE_SIZE, $batch));
    }
}
