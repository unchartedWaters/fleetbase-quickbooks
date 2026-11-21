<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's writes of customer billing places: the place each customer bills to,
 * filled in or created from the QuickBooks billing address. Split from PersistsRecords to keep
 * each file readable.
 */
trait PersistsCustomerPlaces
{
    /**
     * @param array{connection: \Illuminate\Database\Connection, table: string}|null $store
     * @param array<string, mixed>                                                   $customer
     */
    private function customerPlaceUuid(?array $store, bool $canPlace, array $customer, string $uuid): ?string
    {
        $address = $customer['address'] ?? null;
        if (
            $canPlace === false
            || $store === null
            || is_array($address) === false
            || $this->billingAddressHasContent($address) === false
        ) {
            return null;
        }

        return $this->insertCustomerPlace($store, (string) $customer['company_uuid'], $uuid, $address);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function fillMissingPlaceUuids(array &$rows): void
    {
        $withPlace = false;
        foreach ($rows as $row) {
            if (array_key_exists('place_uuid', $row) === true) {
                $withPlace = true;
                break;
            }
        }
        if ($withPlace === false) {
            return;
        }
        foreach ($rows as $index => $row) {
            if (array_key_exists('place_uuid', $row) === false) {
                $rows[$index]['place_uuid'] = null;
            }
        }
    }

    /**
     * @param iterable<Model> $customers
     *
     * @return array<int, string>
     */
    private function customerPlaceIds(iterable $customers): array
    {
        $ids = [];
        foreach ($customers as $customer) {
            $placeId = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
            if ($placeId !== '') {
                $ids[$placeId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<int, string> $uuids
     *
     * @return array<string, object>
     */
    private function placesByUuid(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $store = $this->placeTable();
        if ($store === null) {
            return [];
        }

        $places = [];
        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach ($store['connection']->table($store['table'])->whereIn('uuid', $chunk)->get() as $place) {
                $places[(string) $place->uuid] = $place;
            }
        }

        return $places;
    }

    /**
     * The billing address to write. An address with no content is written only to empty one the
     * customer had: QuickBooks, as Primary, emptied it.
     *
     * @param array<string, mixed>      $customer
     * @param array<string, mixed>|null $loaded
     *
     * @return array<string, mixed>|null
     */
    private function changedBillingAddress(array $customer, ?array $loaded): ?array
    {
        if (isset($customer['address']) === false || is_array($customer['address']) === false) {
            return null;
        }

        $previous = $loaded['address'] ?? null;
        if ($previous === $customer['address']) {
            return null;
        }
        if ($this->billingAddressHasContent($customer['address']) === false) {
            $emptied = is_array($previous) === true && $this->billingAddressHasContent($previous) === true;

            return $emptied === true ? $customer['address'] : null;
        }

        return $customer['address'];
    }

    /**
     * @param array<string, mixed> $address
     */
    private function billingAddressHasContent(array $address): bool
    {
        foreach (['line1', 'line2', 'city', 'state', 'postal_code', 'country'] as $field) {
            if (($address[$field] ?? null) !== null && $address[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function writeBillingAddress(Model $customer, array $address): void
    {
        $store = $this->placeTable();
        if ($store === null) {
            return;
        }

        $columns               = (new CustomerMapper())->placeColumns($address);
        $columns['updated_at'] = Carbon::now()->toDateTimeString();
        $placeUuid             = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
        if ($placeUuid !== '') {
            $updated = $store['connection']->table($store['table'])->where('uuid', $placeUuid)->update($columns);
            if ($updated > 0) {
                return;
            }
        }
        // An emptied address empties the place it had; no place is made for no address.
        if ($this->billingAddressHasContent($address) === false) {
            return;
        }

        $created = $this->insertCustomerPlace(
            $store,
            (string) $customer->company_uuid,
            (string) $customer->uuid,
            $address
        );
        if ($created === null || $this->customerColumnExists($customer::class, 'place_uuid') === false) {
            return;
        }

        $customer->place_uuid = $created;
        $customer->save();
    }

    /**
     * @param array{connection: \Illuminate\Database\Connection, table: string} $store
     * @param array<string, mixed>                                              $address
     */
    private function insertCustomerPlace(array $store, string $companyUuid, string $ownerUuid, array $address): ?string
    {
        $now  = Carbon::now()->toDateTimeString();
        $uuid = (string) Str::uuid();
        $row  = array_merge((new CustomerMapper())->placeColumns($address), [
            'uuid'         => $uuid,
            'public_id'    => 'place_' . strtolower(Str::random(10)),
            'company_uuid' => $companyUuid,
            'owner_uuid'   => $ownerUuid,
            'owner_type'   => 'Fleetbase\\FleetOps\\Models\\Customer',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $connection = $store['connection'];
        if (
            $connection->getDriverName() === 'mysql'
            && $connection->getSchemaBuilder()->hasColumn($store['table'], 'location') === true
        ) {
            $row['location'] = $connection->raw("(ST_PointFromText('POINT(0 0)', 0, 'axis-order=long-lat'))");
        }
        $connection->table($store['table'])->insert($row);

        return $uuid;
    }

    /**
     * @return array{connection: \Illuminate\Database\Connection, table: string}|null
     */
    private function placeTable(): ?array
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Place';
        if (class_exists($class) === false) {
            return null;
        }

        try {
            $model      = new $class();
            $table      = $model->getTable();
            $connection = $model->getConnection();
            if ($connection->getSchemaBuilder()->hasTable($table) === false) {
                return null;
            }

            return ['connection' => $connection, 'table' => $table];
        } catch (\Throwable) {
            return null;
        }
    }
}
