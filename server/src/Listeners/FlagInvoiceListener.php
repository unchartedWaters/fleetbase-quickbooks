<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class FlagInvoiceListener
{
    public function __construct(private FleetbaseDirectory $directory, private SyncFlagger $flagger)
    {
    }

    public function handle(object $event): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        $invoice = $event->invoice ?? null;
        if (is_object($invoice) === false) {
            return;
        }

        $companyUuid = (string) ($invoice->company_uuid ?? '');
        // The listener also runs from ledger events: a failure must not reach the code that raised them.
        FlagGuard::run(function () use ($invoice, $companyUuid): void {
            $this->directory->flag(function ($ledger) use ($invoice): void {
                $this->flagger->fromInvoiceEvent($ledger, $invoice, 'invoice');
            }, $companyUuid);
        }, 'invoice', ['company_uuid' => $companyUuid]);
    }
}
