<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queues the existing in-scope rows of one organization. The catalog can be large, so
 * this runs in the background instead of inside the request that turned an entity on.
 */
class QueueCompanyScope implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, bool> $enabled
     */
    public function __construct(
        public string $companyUuid,
        public array $enabled,
        public bool $syncAfter = false,
    ) {
    }

    /**
     * @param array<string, bool> $enabled
     */
    public static function dispatch(string $companyUuid, array $enabled, bool $syncAfter = false): void
    {
        // Without a bus (a bare unit test) there is nothing to queue on.
        $container  = Container::getInstance();
        $dispatcher = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch(new self($companyUuid, $enabled, $syncAfter));
        }
    }

    /**
     * The entities whose switch went from off to on. A switch that stays on, or that is
     * turned off, is not listed. A payment switch turned on queues the invoices.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, bool>
     */
    public static function turnedOn(array $before, array $after): array
    {
        $enabled = [];
        foreach ([
            'customer_enabled' => 'customer',
            'invoice_enabled'  => 'invoice',
            'wallet_enabled'   => 'wallet',
        ] as $key => $entity) {
            if (self::switchOn($before, $key) === false && self::switchOn($after, $key) === true) {
                $enabled[$entity] = true;
            }
        }
        if (self::switchOn($before, 'payment_enabled') === false && self::switchOn($after, 'payment_enabled') === true) {
            $enabled['invoice'] = true;
        }

        return $enabled;
    }

    /**
     * @param array<string, mixed> $sync
     */
    private static function switchOn(array $sync, string $key): bool
    {
        // A wallet switch that was never stored is off; the other entities default to on.
        if (array_key_exists($key, $sync) === false) {
            return $key !== 'wallet_enabled';
        }

        return $sync[$key] !== false;
    }

    public function handle(FleetbaseDirectory $directory): void
    {
        if ($this->companyUuid === '') {
            return;
        }

        $directory->queueInScope($this->companyUuid, $this->enabled);
        if ($this->syncAfter === true) {
            SyncCompanyBatch::dispatch($this->companyUuid, 'now');
        }
    }
}
