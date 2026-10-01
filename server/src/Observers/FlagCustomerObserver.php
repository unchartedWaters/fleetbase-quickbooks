<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

class FlagCustomerObserver
{
    private const WATCHED = ['name', 'email', 'phone', 'notes'];

    public function saved(object $customer): void
    {
        if (SyncSuppressor::paused() || !method_exists($customer, 'wasChanged') || !$customer->wasChanged(self::WATCHED)) {
            return;
        }

        $listener = Container::getInstance()->make(FlagCustomerListener::class);
        if ($listener instanceof FlagCustomerListener) {
            $listener->handle((object) ['customer' => $customer]);
        }
    }
}
