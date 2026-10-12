<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After a global sync change, queues the existing rows of every organization that has a
 * QuickBooks connection and whose own effective switch turned on. An organization with its
 * own override keeps that override.
 */
class QueueGlobalScope implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $before the global sync settings before the save
     * @param array<string, mixed> $after  the global sync settings after the save
     */
    public function __construct(
        public array $before,
        public array $after,
    ) {
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function dispatch(array $before, array $after): void
    {
        // Without a bus (a bare unit test) there is nothing to queue on.
        $container  = Container::getInstance();
        $dispatcher = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch(new self($before, $after));
        }
    }

    public function handle(FleetbaseDirectory $directory, SettingsStore $store): void
    {
        $resolver = new SyncSettingsResolver();
        $defaults = $store->defaultSync();
        foreach ($this->companies() as $companyUuid) {
            $override = $store->companySync($companyUuid);
            $enabled  = QueueCompanyScope::turnedOn(
                $resolver->resolve($override, $this->before, $defaults),
                $resolver->resolve($override, $this->after, $defaults)
            );
            if ($enabled !== []) {
                $directory->queueInScope($companyUuid, $enabled);
            }
        }
    }

    /**
     * Organizations with a QuickBooks company id, the same rows the scheduler reads.
     *
     * @return array<int, string>
     */
    private function companies(): array
    {
        $companies = [];
        $rows      = Connection::query()
            ->whereNotNull('realm_id')
            ->where('realm_id', '!=', '')
            ->get(['company_uuid', 'realm_id']);
        foreach ($rows as $row) {
            if ($row instanceof Connection === false) {
                continue;
            }
            $companyUuid = trim((string) $row->company_uuid);
            if ($companyUuid !== '' && trim((string) $row->realm_id) !== '') {
                $companies[$companyUuid] = $companyUuid;
            }
        }

        return array_values($companies);
    }
}
