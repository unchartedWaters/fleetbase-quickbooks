<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class FlagCustomerListener
{
    public function __construct(private FleetbaseDirectory $directory, private SyncFlagger $flagger)
    {
    }

    public function handle(object $event): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        $customer = $event->customer ?? $event;
        if (is_object($customer) === false) {
            return;
        }

        $companyUuid = (string) ($customer->company_uuid ?? '');
        $this->directory->flag(function ($ledger) use ($customer): void {
            $this->flagger->fromCustomerEvent($ledger, $customer);
        }, $companyUuid);
    }
}
