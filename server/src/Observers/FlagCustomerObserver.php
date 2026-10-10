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
        if (SyncSuppressor::paused() === true || method_exists($customer, 'wasChanged') === false || $customer->wasChanged(self::WATCHED) === false) {
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
