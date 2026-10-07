<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

/**
 * Billing-address edits save the place row. The customer place_uuid stays the same,
 * so FlagCustomerObserver never sees them.
 */
class FlagPlaceObserver
{
    private const WATCHED = ['street1', 'street2', 'city', 'province', 'postal_code', 'country'];

    public function saved(object $place): void
    {
        if (SyncSuppressor::paused() === true || method_exists($place, 'wasChanged') === false || $place->wasChanged(self::WATCHED) === false) {
            return;
        }

        $listener = Container::getInstance()->make(FlagCustomerListener::class);
        if ($listener instanceof FlagCustomerListener === false) {
            return;
        }

        foreach ($this->customers($place) as $customer) {
            $listener->handle((object) ['customer' => $customer]);
        }
    }

    /**
     * Customers whose billing place is this row.
     *
     * @return iterable<int, object>
     */
    protected function customers(object $place): iterable
    {
        $placeUuid = trim((string) ($place->uuid ?? ''));
        if ($placeUuid === '') {
            return [];
        }

        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false) {
            return [];
        }

        $customers = $class::query()->where('place_uuid', $placeUuid)->get();
        if (is_iterable($customers) === false) {
            return [];
        }

        $matches = [];
        foreach ($customers as $customer) {
            if (is_object($customer) === true) {
                $matches[] = $customer;
            }
        }

        return $matches;
    }
}
