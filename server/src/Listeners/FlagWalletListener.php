<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class FlagWalletListener
{
    public function __construct(private FleetbaseDirectory $directory, private SyncFlagger $flagger)
    {
    }

    public function handle(object $event): void
    {
        if (SyncSuppressor::paused()) {
            return;
        }

        $wallet = $event->wallet ?? $event;
        if (!is_object($wallet)) {
            return;
        }

        $companyUuid = (string) ($wallet->company_uuid ?? '');
        $this->directory->flag(function ($ledger) use ($wallet): void {
            $this->flagger->fromWalletEvent($ledger, $wallet);
        }, $companyUuid);
    }
}
