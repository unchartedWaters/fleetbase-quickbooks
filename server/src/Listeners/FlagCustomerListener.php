<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class FlagCustomerListener
{
    public function __construct(private FleetbaseDirectory $directory, private SyncFlagger $flagger)
    {
    }

    /**
     * Whether this organization has a QuickBooks connection worth flagging for.
     */
    public function tracks(string $companyUuid): bool
    {
        return $this->directory->tracks($companyUuid);
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
        // The listener also runs from ledger events: a failure must not reach the code that raised them.
        FlagGuard::run(function () use ($customer, $companyUuid): void {
            $this->directory->flag(function ($ledger) use ($customer): void {
                $this->flagger->fromCustomerEvent($ledger, $customer);
            }, $companyUuid);
        }, 'customer', ['company_uuid' => $companyUuid]);
    }
}
