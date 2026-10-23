<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagProductListener;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

/**
 * Storefront products become QuickBooks products, one per product name. A change to what
 * QuickBooks holds queues the product; a rename or a delete also queues its old name, whose
 * QuickBooks product goes inactive when no product uses that name any more.
 */
class FlagProductObserver
{
    private const WATCHED = ['name', 'description', 'sku', 'price', 'currency', 'is_service', 'status'];

    /**
     * After the write, so a sync that reads the product sees the new name. Eloquent keeps the
     * values from before the save as the original ones until the saved event is over, which
     * gives the name a rename left.
     */
    public function saved(object $product): void
    {
        $created = ($product->wasRecentlyCreated ?? false) === true;
        $changed = method_exists($product, 'wasChanged') === true && $product->wasChanged(self::WATCHED) === true;
        if ($created === false && $changed === false) {
            return;
        }
        $previous = $created === false
            && $product->wasChanged('name') === true
            && method_exists($product, 'getOriginal') === true
            ? $product->getOriginal('name')
            : null;
        $renamed  = is_string($previous) === true
            && trim($previous) !== ''
            && ProductMapper::key($previous) !== ProductMapper::key((string) ($product->name ?? ''));

        $this->flag($product, $renamed === true ? $previous : null, true);
    }

    /**
     * Also fired by a force delete, after which the product cannot be read: its name is queued.
     */
    public function deleted(object $product): void
    {
        $this->flag($product, (string) ($product->name ?? ''), true);
    }

    public function restored(object $product): void
    {
        $this->flag($product, null, true);
    }

    private function flag(object $product, ?string $name, bool $withProduct): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        // A failure here must never fail the product save or delete.
        FlagGuard::run(static function () use ($product, $name, $withProduct): void {
            $listener = Container::getInstance()->make(FlagProductListener::class);
            if ($listener instanceof FlagProductListener === true) {
                $listener->handle((object) ['product' => $product, 'name' => $name, 'withProduct' => $withProduct]);
            }
        }, 'product', [
            'company_uuid' => (string) ($product->company_uuid ?? ''),
            'uuid'         => (string) ($product->uuid ?? ''),
        ]);
    }
}
