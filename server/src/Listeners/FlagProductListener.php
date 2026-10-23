<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class FlagProductListener
{
    public function __construct(private FleetbaseDirectory $directory, private SyncFlagger $flagger)
    {
    }

    public function handle(object $event): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        $product = $event->product ?? null;
        if (is_object($product) === false) {
            return;
        }
        $name        = is_string($event->name ?? null) === true ? $event->name : null;
        $withProduct = ($event->withProduct ?? true) === true;
        $companyUuid = (string) ($product->company_uuid ?? '');
        FlagGuard::run(function () use ($product, $name, $withProduct, $companyUuid): void {
            $this->directory->flag(function ($ledger) use ($product, $name, $withProduct): void {
                $this->flagger->fromProductEvent($ledger, $product, $name, $withProduct);
            }, $companyUuid);
        }, 'product', ['company_uuid' => $companyUuid]);
    }
}
