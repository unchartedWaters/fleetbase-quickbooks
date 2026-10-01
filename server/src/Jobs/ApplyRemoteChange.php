<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
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
        if ($dispatcher instanceof Dispatcher) {
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
        if ($entities === [] || !ConnectionGate::hasRealm($directory->connection($this->companyUuid))) {
            return;
        }

        $lock = BatchRunner::lock($this->companyUuid);
        if ($lock === null) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', BatchRunner::LOCK_UNAVAILABLE);

            return;
        }
        if (!$lock->get()) {
            $directory->saveSkipped($this->companyUuid, 'webhook', 'inbound', 'Another QuickBooks sync is already running.');

            return;
        }

        try {
            $loaded = $directory->load($this->companyUuid);
            if (!is_array($loaded)) {
                return;
            }

            $syncSettings = $settings->resolveSync(
                $store->companySync($this->companyUuid),
                [],
                $store->defaultSync()
            );
            // Direction filtering happens inside acceptRemoteChanges. This does not reconcile the catalog.
            $engine->acceptRemoteChanges($loaded['ledger'], $this->companyUuid, $entities, $syncSettings, time());
            $directory->save($loaded['ledger']);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<int, array{entity: string, id: string, operation: string}>
     */
    private function knownEntities(): array
    {
        $entities = [];
        foreach ($this->entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $name = $entity['entity'] ?? null;
            if (!is_string($name) || !in_array($name, self::ENTITIES, true)) {
                continue;
            }
            $id = $entity['id'] ?? null;
            if (is_int($id)) {
                $id = (string) $id;
            }
            if (!is_string($id) || $id === '') {
                continue;
            }
            $operation = $entity['operation'] ?? '';
            if (!is_string($operation)) {
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
