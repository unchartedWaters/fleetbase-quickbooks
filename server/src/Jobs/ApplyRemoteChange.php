<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\ConnectionGate;
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
    public const ENTITIES = ['Customer', 'Invoice', 'Payment', 'Account'];

    /**
     * Intuit retries the HTTP delivery. Laravel must not run this job again on its own.
     */
    public int $tries = 1;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    /**
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     */
    public function __construct(public string $companyUuid, public array $entities)
    {
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
    ): void {
        $entities = $this->knownEntities();
        if ($entities === [] || ConnectionGate::hasRealm($directory->connection($this->companyUuid)) === false) {
            return;
        }

        $lock = BatchRunner::lock($this->companyUuid);
        if ($lock === null) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', BatchRunner::LOCK_UNAVAILABLE);

            return;
        }
        if ($lock->get() === false) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', 'Another QuickBooks sync is already running.');

            return;
        }

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
            // Links for these QuickBooks ids, and the local rows those links need.
            // Direction filtering happens inside acceptRemoteChanges. This does not reconcile the catalog.
            $loaded = $directory->loadLinked($this->companyUuid, $entities);
            if (is_array($loaded) === false) {
                return;
            }

            $syncSettings = $settings->resolveSync(
                [],
                $store->adminSync(),
                $store->defaultSync()
            );
            $ledger = $loaded['ledger'];
            if ($ledger instanceof SyncLedger === false) {
                return;
            }
            $engine->acceptRemoteChanges($ledger, $this->companyUuid, $entities, $syncSettings, time());
            $save = true;
        } finally {
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
