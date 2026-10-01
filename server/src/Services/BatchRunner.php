<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class BatchRunner
{
    /**
     * Also the SyncCompanyBatch timeout, so the lock never expires while a batch still runs.
     */
    public const LOCK_SECONDS = 600;

    public const LOCK_UNAVAILABLE = 'QuickBooks could not lock this organization, so this run was skipped.';

    /** @var array<string, int> */
    private static array $heldLocks = [];

    public function __construct(
        private SyncEngine $engine,
        private FleetbaseDirectory $directory,
        private SettingsService $settings,
        private SettingsStore $store,
        private ConnectionTokens $tokens,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $companyUuid, string $trigger = 'scheduled'): array
    {
        $skipped = ['trigger' => $trigger, 'status' => 'skipped'];
        if (!ConnectionGate::hasRealm($this->directory->connection($companyUuid))) {
            return $skipped;
        }
        $resolved   = $this->settingsFor($companyUuid);
        $catalogDue = $this->catalogDue($companyUuid, $resolved, time());
        if ($trigger === 'scheduled' && !$this->scheduledIsDue($companyUuid, $resolved, time()) && !$catalogDue) {
            return $skipped;
        }

        $lock = self::lock($companyUuid);
        if ($lock === null) {
            $this->directory->saveSkipped($companyUuid, $trigger, 'outbound', self::LOCK_UNAVAILABLE);

            return $skipped;
        }
        if (!$lock->get()) {
            if ($trigger !== 'scheduled') {
                $this->directory->saveSkipped($companyUuid, $trigger, 'outbound', 'Another QuickBooks sync is already running.');
            }

            return $skipped + ['reason' => 'busy'];
        }

        $ledger          = null;
        $save            = false;
        $followUp        = false;
        $ranCatalog      = false;
        $continueCatalog = false;
        try {
            if ($trigger === 'manual') {
                $this->claimReconcilePage($companyUuid, (int) $resolved['batch_size']);
            }
            if ($trigger === 'catalog' || ($trigger === 'scheduled' && !$this->scheduledIsDue($companyUuid, $resolved, time()) && $catalogDue)) {
                if (array_key_exists('customer_enabled', $resolved) && $resolved['customer_enabled'] === false) {
                    Cache::forget($this->catalogCursorKey($companyUuid));

                    return $skipped;
                }
                $ranCatalog = true;

                return $this->runCustomerCatalog($companyUuid, $resolved, $trigger, $continueCatalog);
            }
            $now    = time();
            $loaded = $this->directory->loadPending($companyUuid, (int) $resolved['batch_size'], $now);
            if ($loaded === null) {
                return $skipped;
            }

            $now        = time();
            $connection = $this->tokens->refreshIfDue($loaded['connection'], $now);
            $blocked    = ConnectionTokens::blockedMessage($connection, $now);
            if ($blocked !== null) {
                $this->directory->saveSkipped($companyUuid, $trigger, 'outbound', $blocked);

                return $skipped;
            }
            $ledger                            = $loaded['ledger'];
            $ledger->connections[$companyUuid] = $connection;
            $batchesBefore                     = count($ledger->batches);

            $save = true;
            // The size trigger already decided this scheduled run may start early.
            // Skip only the interval wait. The company lock above still covers the run.
            $ignoreInterval = $trigger === 'scheduled' && !$this->intervalElapsed($connection, $resolved, $now);
            $batch          = match ($trigger) {
                'manual'       => $this->engine->reconcile($ledger, $companyUuid, $resolved, $now),
                'now', 'drain' => $this->engine->runScheduled($ledger, $companyUuid, $resolved, $now, true),
                default        => $this->engine->runScheduled($ledger, $companyUuid, $resolved, $now, false, $ignoreInterval),
            };
            $nothingRecorded = count($ledger->batches) === $batchesBefore;
            $save            = !(($batch['status'] ?? null) === 'skipped' && $nothingRecorded);
            $followUp        = in_array($trigger, ['manual', 'drain'], true) && ($batch['status'] ?? '') === 'finished';

            return $batch;
        } finally {
            // Saved even when the engine throws, so links for records already created in QuickBooks are kept.
            if ($save && $ledger !== null) {
                $this->directory->save($ledger);
            }
            if ($followUp && Cache::get($this->reconcileOpenKey($companyUuid))) {
                $this->claimReconcilePage($companyUuid, (int) ($resolved['batch_size'] ?? 100));
            }
            // Decide while the claim is visible, then release. The follow-up has tries = 1,
            // so it must be able to take quickbooks.batch.{company} as soon as it starts.
            $dispatchDrain   = $followUp && $this->directory->hasDuePending($companyUuid, time());
            $dispatchCatalog = $continueCatalog || ($trigger === 'scheduled' && !$ranCatalog && $catalogDue);
            $lock->release();
            if ($dispatchDrain) {
                SyncCompanyBatch::dispatch($companyUuid, 'drain');
            }
            if ($dispatchCatalog) {
                SyncCompanyBatch::dispatch($companyUuid, 'catalog');
            }
        }
    }

    /**
     * The every-minute command and the scheduled job share this check.
     */
    public function isScheduledDue(string $companyUuid, int $now): bool
    {
        if (!$this->hasConnection($companyUuid)) {
            return false;
        }
        $settings = $this->settingsFor($companyUuid);

        return $this->scheduledIsDue($companyUuid, $settings, $now) || $this->catalogDue($companyUuid, $settings, $now);
    }

    /**
     * True when this organization has a QuickBooks company that can be synced.
     * A stored sync.enabled flag is not read.
     */
    public function hasConnection(string $companyUuid): bool
    {
        return ConnectionGate::usable($this->directory->connection($companyUuid));
    }

    /**
     * The customer catalog is not part of the fast pending loop.
     * A missing periodic_interval_hours does not scan customers unless a stamp is already stored.
     *
     * @param array<string, mixed> $settings
     */
    private function catalogDue(string $companyUuid, array $settings, int $now): bool
    {
        if (array_key_exists('customer_enabled', $settings) && $settings['customer_enabled'] === false) {
            return false;
        }

        $hasInterval = array_key_exists('periodic_interval_hours', $settings);
        $stamp       = $this->directory->customerCatalogStamp($companyUuid);
        if (!$hasInterval && $stamp === null) {
            return false;
        }

        $hours = $hasInterval ? max(1, (int) $settings['periodic_interval_hours']) : 24;
        if ($stamp === null) {
            return $hasInterval;
        }

        return ($now - $stamp) >= ($hours * 3600);
    }

    private function claimReconcilePage(string $companyUuid, int $limit): void
    {
        $after  = (string) Cache::get($this->reconcileCursorKey($companyUuid), '');
        $cursor = $this->directory->claimInScopeInvoices($companyUuid, max(1, $limit), $after);
        if ($cursor === '') {
            Cache::forget($this->reconcileCursorKey($companyUuid));
            Cache::forget($this->reconcileOpenKey($companyUuid));

            return;
        }

        Cache::put($this->reconcileOpenKey($companyUuid), true, 3600);
        Cache::put($this->reconcileCursorKey($companyUuid), $cursor, 3600);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function runCustomerCatalog(string $companyUuid, array $settings, string $trigger, bool &$continueCatalog): array
    {
        $now   = time();
        $after = (string) Cache::get($this->catalogCursorKey($companyUuid), '');
        $limit = max(1, (int) ($settings['batch_size'] ?? 100));
        $ids   = $this->directory->customerIdsPage($companyUuid, $limit + 1, $after);
        $more  = count($ids) > $limit;
        $ids   = array_slice($ids, 0, $limit);
        if ($ids === []) {
            Cache::forget($this->catalogCursorKey($companyUuid));
            $this->directory->rememberCustomerCatalogStamp($companyUuid, $now);

            return ['trigger' => 'catalog', 'status' => 'finished', 'created' => 0, 'updated' => 0, 'aligned' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $loaded = $this->directory->loadCustomerBlock($companyUuid, $ids);
        if ($loaded === null) {
            return ['trigger' => 'catalog', 'status' => 'skipped'];
        }

        $connection = $this->tokens->refreshIfDue($loaded['connection'], $now);
        $blocked    = ConnectionTokens::blockedMessage($connection, $now);
        if ($blocked !== null) {
            $this->directory->saveSkipped($companyUuid, 'catalog', 'outbound', $blocked);

            return ['trigger' => 'catalog', 'status' => 'skipped'];
        }

        $ledger                            = $loaded['ledger'];
        $ledger->connections[$companyUuid] = $connection;
        $rows                              = [];
        foreach ($ids as $uuid) {
            $rows[] = [
                'company_uuid' => $companyUuid,
                'local_type'   => 'customer',
                'local_uuid'   => $uuid,
                'status'       => 'pending',
                'attempts'     => 0,
            ];
        }
        try {
            $batch = $this->engine->syncEntities($ledger, $companyUuid, $rows, $settings, $now);
        } finally {
            $this->directory->save($ledger);
        }
        if (!$this->catalogPageSucceeded($batch, $ledger, $companyUuid, count($ids), $now)) {
            return $batch;
        }
        if ($more) {
            Cache::put($this->catalogCursorKey($companyUuid), (string) $ids[array_key_last($ids)], 3600);
            // run() dispatches this after it releases the company lock.
            $continueCatalog = true;
        } else {
            Cache::forget($this->catalogCursorKey($companyUuid));
            $this->directory->rememberCustomerCatalogStamp($companyUuid, $now);
        }

        return $batch;
    }

    /**
     * A catalog cursor is a page commit marker. Failed, skipped, or halted rows
     * leave it untouched so the whole page is retried from the previous marker.
     *
     * @param array<string, mixed> $batch
     */
    private function catalogPageSucceeded(array $batch, SyncLedger $ledger, string $companyUuid, int $expected, int $now): bool
    {
        $connection = $ledger->connection($companyUuid);
        if ($connection === null || ConnectionTokens::blockedMessage($connection, $now) !== null) {
            return false;
        }

        $completed = (int) ($batch['created'] ?? 0)
            + (int) ($batch['updated'] ?? 0)
            + (int) ($batch['aligned'] ?? 0);

        return ($batch['status'] ?? null) === 'finished'
            && (int) ($batch['failed'] ?? 0) === 0
            && (int) ($batch['skipped'] ?? 0) === 0
            && $completed === $expected;
    }

    private function reconcileCursorKey(string $companyUuid): string
    {
        return 'quickbooks.reconcile-after.' . $companyUuid;
    }

    private function reconcileOpenKey(string $companyUuid): string
    {
        return 'quickbooks.reconcile-open.' . $companyUuid;
    }

    private function catalogCursorKey(string $companyUuid): string
    {
        return 'quickbooks.customer-catalog-after.' . $companyUuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFor(string $companyUuid): array
    {
        return $this->settings->resolveSync(
            $this->store->companySync($companyUuid),
            [],
            $this->store->defaultSync()
        );
    }

    /**
     * Seconds until an idle schedule should look at this organization again.
     * A stored sync.enabled flag does not turn the schedule off.
     */
    public function deferSeconds(string $companyUuid): int
    {
        $settings = $this->settingsFor($companyUuid);

        return max(60, (int) ($settings['interval_minutes'] ?? 0) * 60);
    }

    /**
     * Cheap checks for the every-minute schedule, made before the full ledger is loaded.
     * The interval is the usual trigger: any due pending row is sent once it arrives.
     * Due rows that fill an update batch may start a sync before that.
     * A stored batch_size is not read. A company with nothing due does not sync.
     *
     * @param array<string, mixed> $settings
     */
    private function scheduledIsDue(string $companyUuid, array $settings, int $now): bool
    {
        $connection = $this->directory->connection($companyUuid);
        if ($connection === null || !empty($connection['needs_reauth'])) {
            return false;
        }

        $rateLimitedUntil = $connection['rate_limited_until'] ?? null;
        if ($rateLimitedUntil !== null && (int) $rateLimitedUntil > $now) {
            return false;
        }

        $due = $this->directory->countDuePending($companyUuid, $now);
        if ($due < 1) {
            return false;
        }
        if ($this->intervalElapsed($connection, $settings, $now)) {
            return true;
        }

        return $due >= QuickBooksClient::UPDATE_BATCH_SIZE;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function intervalElapsed(array $connection, array $settings, int $now): bool
    {
        $last = $connection['last_batch_at'] ?? null;
        if ($last === null) {
            return true;
        }

        return ($now - (int) $last) >= ((int) $settings['interval_minutes'] * 60);
    }

    /**
     * One lock per company, shared by batches and customer import, since both save the ledger.
     */
    public static function lock(string $companyUuid): ?Lock
    {
        $cache = Cache::getFacadeRoot();
        if (!is_object($cache)) {
            return null;
        }

        $store = method_exists($cache, 'getStore') ? $cache->getStore() : null;
        if ($store instanceof \Illuminate\Contracts\Cache\LockProvider) {
            return new TrackingLock($store->lock('quickbooks.batch.' . $companyUuid, self::LOCK_SECONDS), $companyUuid);
        }
        if (method_exists($cache, 'lock')) {
            return new TrackingLock(Cache::lock('quickbooks.batch.' . $companyUuid, self::LOCK_SECONDS), $companyUuid);
        }

        return null;
    }

    public static function holds(string $companyUuid): bool
    {
        return (self::$heldLocks[$companyUuid] ?? 0) > 0;
    }

    public static function markHeld(string $companyUuid): void
    {
        self::$heldLocks[$companyUuid] = (self::$heldLocks[$companyUuid] ?? 0) + 1;
    }

    public static function markReleased(string $companyUuid): void
    {
        $remaining = (self::$heldLocks[$companyUuid] ?? 0) - 1;
        if ($remaining <= 0) {
            unset(self::$heldLocks[$companyUuid]);

            return;
        }

        self::$heldLocks[$companyUuid] = $remaining;
    }
}

/**
 * Counts locks this process already holds so a token refresh can run inside a batch.
 */
class TrackingLock implements Lock
{
    private bool $holding = false;

    public function __construct(private Lock $inner, private string $companyUuid)
    {
    }

    public function get($callback = null)
    {
        if ($callback !== null) {
            return $this->inner->get(function () use ($callback) {
                $this->noteAcquired();
                try {
                    return $callback();
                } finally {
                    $this->noteReleased();
                }
            });
        }

        $acquired = $this->inner->get();
        if ($acquired) {
            $this->noteAcquired();
        }

        return $acquired;
    }

    public function block($seconds, $callback = null)
    {
        if ($callback !== null) {
            return $this->inner->block($seconds, function () use ($callback) {
                $this->noteAcquired();
                try {
                    return $callback();
                } finally {
                    $this->noteReleased();
                }
            });
        }

        $acquired = $this->inner->block($seconds);
        if ($acquired) {
            $this->noteAcquired();
        }

        return $acquired;
    }

    public function release()
    {
        $this->noteReleased();

        return $this->inner->release();
    }

    public function owner()
    {
        return $this->inner->owner();
    }

    public function forceRelease()
    {
        $this->noteReleased();
        $this->inner->forceRelease();
    }

    private function noteAcquired(): void
    {
        if ($this->holding) {
            return;
        }

        $this->holding = true;
        BatchRunner::markHeld($this->companyUuid);
    }

    private function noteReleased(): void
    {
        if (!$this->holding) {
            return;
        }

        $this->holding = false;
        BatchRunner::markReleased($this->companyUuid);
    }
}
