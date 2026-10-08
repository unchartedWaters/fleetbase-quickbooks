<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
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

        $listener = Container::getInstance()->make(FlagCustomerListener::class);
        if ($listener instanceof FlagCustomerListener === true) {
            $listener->handle((object) ['customer' => $customer]);
        }
    }
}
