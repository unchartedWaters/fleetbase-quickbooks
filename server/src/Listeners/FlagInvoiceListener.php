<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class FlagInvoiceListener
{
    public function __construct(private FleetbaseDirectory $directory, private SyncFlagger $flagger)
    {
    }

    public function handle(object $event): void
    {
        if (SyncSuppressor::paused()) {
            return;
        }

        $invoice = $event->invoice ?? null;
        if (!is_object($invoice)) {
            return;
        }

        $companyUuid = (string) ($invoice->company_uuid ?? '');
        $this->directory->flag(function ($ledger) use ($invoice): void {
            $this->flagger->fromInvoiceEvent($ledger, $invoice, 'invoice');
        }, $companyUuid);
    }
}
