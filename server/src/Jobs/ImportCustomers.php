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

    public int $tries = 1;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    public function __construct(
        public string $companyUuid,
        public ?int $continuationAttempt = null,
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
            ? 'QuickBooks needs to be connected again before import can continue.'
            : 'QuickBooks is not connected. Connect from Quickbooks Setup.';
    }

    public static function dispatch(string $companyUuid): void
    {
        self::dispatchJob(new self($companyUuid));
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
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', 'Another QuickBooks sync is already running.');
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
                self::dispatchJob(new self($this->companyUuid, 0));
            }
        }
    }

    private function retryBusyContinuation(): void
    {
        if ($this->continuationAttempt === null || $this->continuationAttempt >= self::CONTINUATION_RETRY_LIMIT) {
            return;
        }

        $attempt = $this->continuationAttempt + 1;
        $retry   = new self($this->companyUuid, $attempt);
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
        $connection = $directory->connection($this->companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return false;
        }

        if (empty($connection['needs_reauth']) === false) {
            $directory->saveSkipped($this->companyUuid, 'import', 'inbound', self::blockedMessage($connection));

            return false;
        }

        if ($settings !== null && $store !== null) {
            $resolved = $settings->resolveSync(
                [],
                $store->adminSync(),
                $store->defaultSync()
            );
            if (array_key_exists('customer_enabled', $resolved) === true && $resolved['customer_enabled'] === false) {
                $directory->saveSkipped($this->companyUuid, 'import', 'inbound', 'Customers are turned off in Data Resolution, so they are not imported.');

                return false;
            }
        }

        // Token refresh and customer page queries must not sit inside the company lock.
        // The lock is taken again only for the local save.
        $this->releaseCompanyLock($lock);

        if ($tokens !== null) {
            $connection = $tokens->refreshIfDue($connection, time());
            $blocked    = ConnectionTokens::blockedMessage($connection, time());
            if ($blocked !== null) {
                $this->ensureCompanyLock($lock);
                $directory->saveSkipped($this->companyUuid, 'import', 'inbound', $blocked);

                return false;
            }
        }

        $ledger                                  = $directory->memory ?? new SyncLedger();
        $ledger->connections[$this->companyUuid] = $connection;
        $started                                 = time();

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
            $this->ensureCompanyLock($lock);
            $directory->save($ledger);
        }
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
                [],
                $store->adminSync(),
                $store->defaultSync()
            );
            $batch = (int) ($resolved['batch_size'] ?? CustomerImporter::MAX_PAGE_SIZE);
        }

        return max(1, min(CustomerImporter::MAX_PAGE_SIZE, $batch));
    }
}
