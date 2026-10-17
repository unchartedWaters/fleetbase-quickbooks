<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

class FlagCustomerObserver
{
    private const WATCHED = ['name', 'email', 'phone', 'notes', 'place_uuid'];

    public function saved(object $customer): void
    {
        if (method_exists($customer, 'wasChanged') === false || $customer->wasChanged(self::WATCHED) === false) {
            return;
        }

        $this->flag($customer);
    }

    /**
     * Eloquent records changes only on update, so saved() never sees a new customer.
     */
    public function created(object $customer): void
    {
        $this->flag($customer);
    }

    private function flag(object $customer): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }
        // This also observes Contact, which holds plain contacts as well as customers.
        if ((string) ($customer->type ?? 'customer') !== 'customer') {
            return;
        }

        // A failure here must never fail the customer save.
        FlagGuard::run(static function () use ($customer): void {
            $listener = Container::getInstance()->make(FlagCustomerListener::class);
            if ($listener instanceof FlagCustomerListener === true) {
                $listener->handle((object) ['customer' => $customer]);
            }
        }, 'customer', ['company_uuid' => (string) ($customer->company_uuid ?? ''), 'uuid' => (string) ($customer->uuid ?? '')]);
    }
}
