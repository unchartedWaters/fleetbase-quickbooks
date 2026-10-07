<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

class FlagInvoiceObserver
{
    public function updated(object $invoice): void
    {
        if (method_exists($invoice, 'wasChanged') === false) {
            return;
        }

        $watched = ['status', 'tax', 'total_amount', 'date', 'due_date', 'notes', 'number', 'amount_paid', 'paid_at', 'customer_uuid', 'currency'];
        if ($invoice->wasChanged($watched) === false) {
            return;
        }

        $this->flag($invoice);
    }

    /**
     * Soft deletes do not fire `updated`. Flagging lets the engine void the QuickBooks copy.
     */
    public function deleted(object $invoice): void
    {
        $this->flag($invoice);
    }

    private function flag(object $invoice): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        $listener = Container::getInstance()->make(FlagInvoiceListener::class);
        if ($listener instanceof FlagInvoiceListener === true) {
            $listener->handle((object) ['invoice' => $invoice]);
        }
    }
}
