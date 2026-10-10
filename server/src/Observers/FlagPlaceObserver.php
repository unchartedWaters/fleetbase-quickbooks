<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Support\FlagGuard;
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

        // A failure here must never fail the place save.
        FlagGuard::run(function () use ($place): void {
            $listener = Container::getInstance()->make(FlagCustomerListener::class);
            if ($listener instanceof FlagCustomerListener === false) {
                return;
            }

            // The customer lookup is skipped for an organization without a QuickBooks connection.
            $companyUuid = trim((string) ($place->company_uuid ?? ''));
            if ($companyUuid !== '' && $listener->tracks($companyUuid) === false) {
                return;
            }

            foreach ($this->customers($place) as $customer) {
                $listener->handle((object) ['customer' => $customer]);
            }
        }, 'place', ['company_uuid' => (string) ($place->company_uuid ?? ''), 'uuid' => (string) ($place->uuid ?? '')]);
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
