<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's writes of Fleetbase records: customers and their billing places, invoices,
 * their lines and amounts, and wallets. It shares the directory's state, so it lives here only
 * to keep FleetbaseDirectory.php readable.
 */
trait PersistsRecords
{
    /**
     * @param array<string, true> $skipped
     */
    private function persistCustomers(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false) {
            return;
        }

        $present = $this->existingCustomerUuids($class, $this->customerUuidsToPersist($ledger, $skipped));
        $this->insertCustomers($class, $this->saveExistingCustomers($class, $ledger, $skipped, $present));
    }

    /**
     * @param array<string, true> $skipped
     *
     * @return array<int, string>
     */
    private function customerUuidsToPersist(SyncLedger $ledger, array $skipped): array
    {
        $uuids = [];
        foreach ($ledger->customers as $customer) {
            $uuid = $this->persistableCustomerUuid($customer, $skipped);
            if ($uuid !== null) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, true>  $skipped
     */
    private function persistableCustomerUuid(array $customer, array $skipped): ?string
    {
        if (isset($skipped[(string) ($customer['company_uuid'] ?? '')]) === true) {
            return null;
        }
        $uuid = (string) ($customer['uuid'] ?? '');
        if ($uuid === '' || empty($customer['company_uuid']) === true) {
            return null;
        }

        return $uuid;
    }

    /**
     * @param array<string, true> $skipped
     * @param array<string, true> $present
     *
     * @return array<int, array<string, mixed>>
     */
    private function saveExistingCustomers(string $class, SyncLedger $ledger, array $skipped, array $present): array
    {
        $fresh = [];
        foreach ($ledger->customers as $customer) {
            $uuid = $this->persistableCustomerUuid($customer, $skipped);
            if ($uuid === null) {
                continue;
            }
            // Only fields the engine changed are written, so an edit during the batch stays.
            $loaded  = $this->loaded['customers'][$uuid] ?? null;
            $loaded  = is_array($loaded) === true ? $loaded : null;
            $values  = self::changedColumns($customer, $loaded, self::CUSTOMER_FIELDS);
            $address = $this->changedBillingAddress($customer, $loaded);
            if (isset($present[$uuid]) === true) {
                $this->updateExistingCustomer($class, $customer, $uuid, $values, $address);
                continue;
            }
            if ($values === [] && $address === null && $loaded !== null) {
                continue;
            }
            $fresh[] = $customer;
        }

        return $fresh;
    }

    /**
     * @param array<string, mixed>      $customer
     * @param array<string, mixed>      $values
     * @param array<string, mixed>|null $address
     */
    private function updateExistingCustomer(
        string $class,
        array $customer,
        string $uuid,
        array $values,
        ?array $address,
    ): void {
        if ($values === [] && $address === null) {
            return;
        }
        // A customer deleted while this run was busy is left deleted. Its uuid is taken,
        // so inserting it again would fail the whole save.
        /** @var Model|null $model */
        $model = $class::query()
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->where('uuid', $uuid)
            ->where('company_uuid', $customer['company_uuid'])
            ->first();
        if ($model === null || $model->getAttribute('deleted_at') !== null) {
            return;
        }
        if ($values !== []) {
            $model->fill($values);
            if ($model->isDirty() === true) {
                $model->save();
            }
        }
        if ($address !== null) {
            $this->writeBillingAddress($model, $address);
        }
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistInvoices(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return;
        }

        $metaRows = [];
        foreach ($ledger->invoices as $invoice) {
            $this->persistInvoice($class, $invoice, $skipped);
            if (isset($skipped[(string) ($invoice['company_uuid'] ?? '')]) === false) {
                $metaRows = array_merge($metaRows, $this->lineMetaRows($invoice), $this->lineTaxRows($invoice));
            }
        }
        $itemClass = 'Fleetbase\\Ledger\\Models\\InvoiceItem';
        if ($metaRows !== [] && class_exists($itemClass) === true) {
            $this->updateByUuid(new $itemClass(), self::rowsByUuid($metaRows), ['meta', 'tax_rate', 'tax_amount']);
        }
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $invoice
     * @param array<string, true>  $skipped
     */
    private function persistInvoice(string $class, array $invoice, array $skipped): void
    {
        if (isset($skipped[(string) ($invoice['company_uuid'] ?? '')]) === true || empty($invoice['uuid']) === true) {
            return;
        }
        $loaded = $this->loaded['invoices'][(string) $invoice['uuid']] ?? null;
        $loaded = is_array($loaded) === true ? $loaded : null;
        if ($this->invoiceNeedsWrite($invoice, $loaded) === false) {
            return;
        }
        $companyUuid = (string) ($invoice['company_uuid'] ?? '');
        $uuid        = (string) $invoice['uuid'];
        $updates     = $this->invoiceColumnUpdates($invoice, $loaded);
        if (empty($invoice['replace_from_quickbooks']) === false) {
            $updates = $this->applyQuickBooksInvoiceReplacement($invoice, $loaded, $uuid, $updates);
        }
        if (empty($invoice['payment_from_quickbooks']) === false) {
            $updates = $this->applyQuickBooksPayment($invoice, $loaded, $updates);
        }
        if ($updates === []) {
            return;
        }
        $updates['updated_at'] = Carbon::now()->toDateTimeString();
        // The invoice model eager-loads customer, items, template, and order. A table update writes the integers as stored.
        $this->invoiceTable($class, $companyUuid, $uuid)->update($updates);
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     *
     * @return array<string, mixed>
     */
    private function invoiceColumnUpdates(array $invoice, ?array $loaded): array
    {
        $updates = [];
        foreach (self::changedColumns($invoice, $loaded, ['number', 'notes', 'date', 'due_date', 'customer_uuid', 'currency']) as $field => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $updates[$field] = $value;
        }

        return $updates;
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     * @param array<string, mixed>      $updates
     *
     * @return array<string, mixed>
     */
    private function applyQuickBooksInvoiceReplacement(array $invoice, ?array $loaded, string $uuid, array $updates): array
    {
        $paid = array_key_exists('amount_paid', $invoice) === true
            ? (int) $invoice['amount_paid']
            : (int) (is_array($loaded) === true ? ($loaded['amount_paid'] ?? 0) : 0);
        $amounts = self::invoiceAmounts((int) ($invoice['total'] ?? 0), (int) ($invoice['tax'] ?? 0), $paid);
        foreach (['tax' => 'tax', 'total_amount' => 'total_amount', 'subtotal' => 'subtotal', 'balance' => 'balance'] as $column => $key) {
            $updates[$column] = $amounts[$key];
        }
        if (empty($invoice['status']) === false) {
            $updates['status'] = (string) $invoice['status'];
        }
        // An edit in QuickBooks can leave a paid invoice owing money. Only a fully paid one keeps a paid date.
        if (in_array((string) ($invoice['status'] ?? ''), ['partial', 'sent'], true) === true) {
            $updates['paid_at'] = null;
        }
        if (empty($invoice['items_from_quickbooks']) === false && is_array($invoice['items'] ?? null) === true) {
            $lineMap = is_array($invoice['line_map'] ?? null) === true ? $invoice['line_map'] : null;
            $this->replaceInvoiceLines($uuid, $invoice['items'], $lineMap);
        }

        return $updates;
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     * @param array<string, mixed>      $updates
     *
     * @return array<string, mixed>
     */
    private function applyQuickBooksPayment(array $invoice, ?array $loaded, array $updates): array
    {
        if (array_key_exists('amount_paid', $invoice) === true) {
            $updates['amount_paid'] = (int) $invoice['amount_paid'];
        }
        if (empty($invoice['paid_at']) === false) {
            $updates['paid_at'] = $invoice['paid_at'];
        }
        if (empty($invoice['status']) === false) {
            $updates['status'] = (string) $invoice['status'];
        }
        // Only a fully paid invoice keeps a paid date in Ledger.
        if (in_array((string) ($invoice['status'] ?? ''), ['partial', 'sent'], true) === true) {
            $updates['paid_at'] = null;
        }
        $updates['balance'] = self::invoiceAmounts(
            $this->storedInvoiceAmount($updates, $invoice, $loaded, 'total_amount', 'total'),
            $this->storedInvoiceAmount($updates, $invoice, $loaded, 'tax', 'tax'),
            $this->storedInvoiceAmount($updates, $invoice, $loaded, 'amount_paid', 'amount_paid')
        )['balance'];

        return $updates;
    }

    /**
     * @param array<string, mixed>      $updates
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     */
    private function storedInvoiceAmount(array $updates, array $invoice, ?array $loaded, string $updateKey, string $invoiceKey): int
    {
        if (array_key_exists($updateKey, $updates) === true) {
            return (int) $updates[$updateKey];
        }
        if ($invoiceKey === 'amount_paid') {
            return (int) (is_array($loaded) === true ? ($loaded['amount_paid'] ?? 0) : 0);
        }

        return (int) ($invoice[$invoiceKey] ?? (is_array($loaded) === true ? ($loaded[$invoiceKey] ?? 0) : 0));
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistWallets(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($class) === false) {
            return;
        }

        foreach ($ledger->wallets as $wallet) {
            if (isset($skipped[(string) ($wallet['company_uuid'] ?? '')]) === true) {
                continue;
            }
            if (empty($wallet['uuid']) === true) {
                continue;
            }
            $loaded = $this->loaded['wallets'][(string) $wallet['uuid']] ?? null;
            if (self::changedColumns($wallet, $loaded, ['name', 'description', 'currency', 'status', 'meta']) === [] && is_array($loaded) === true) {
                continue;
            }
            /** @var Model|null $model */
            $model = $class::query()->where('uuid', $wallet['uuid'])->where('company_uuid', $wallet['company_uuid'] ?? '')->first();
            if ($model === null) {
                continue;
            }
            $loaded = $this->loaded['wallets'][(string) $wallet['uuid']] ?? null;
            foreach (self::changedColumns($wallet, $loaded, ['name', 'description', 'currency', 'status', 'meta']) as $field => $value) {
                if (in_array($field, ['currency', 'status'], true) === true && ($value === '' || $value === null)) {
                    continue;
                }
                $model->{$field} = $value;
            }
            if ($model->isDirty() === true) {
                $model->save();
            }
        }
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     */
    private function invoiceNeedsWrite(array $invoice, ?array $loaded): bool
    {
        if (empty($invoice['replace_from_quickbooks']) === false || empty($invoice['payment_from_quickbooks']) === false) {
            return true;
        }

        return self::changedColumns($invoice, $loaded, ['number', 'notes', 'date', 'due_date', 'status', 'amount_paid', 'paid_at', 'tax', 'total', 'customer_uuid', 'currency']) !== []
            || is_array($loaded) === false;
    }

    /**
     * Minor units stored as signed integers. The Money cast strips a leading minus.
     *
     * @return array{tax: int, total_amount: int, subtotal: int, balance: int}
     */
    public static function invoiceAmounts(int $total, int $tax, int $amountPaid): array
    {
        return [
            'tax'          => $tax,
            'total_amount' => $total,
            'subtotal'     => $total - $tax,
            'balance'      => $total - $amountPaid,
        ];
    }

    /**
     * Write a signed minor-unit integer without the Money cast.
     */
    public static function writeSignedMinor(Model $model, string $field, int $minor): void
    {
        $attributes = $model->getAttributes();
        $current    = $attributes[$field] ?? null;
        if ($current === $minor || (is_string($current) === true && $current === (string) $minor)) {
            return;
        }

        $attributes[$field] = $minor;
        $model->setRawAttributes($attributes);
    }

    private static function minorAttribute(Model $model, string $field): int
    {
        $value = $model->getAttributes()[$field] ?? 0;
        if ($value === null || $value === '' || is_bool($value) === true) {
            return 0;
        }
        if (is_int($value) === true) {
            return $value;
        }
        if (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Replace every current line for one invoice with the inbound lines.
     * One delete of the current lines, then one insert. Integers are written
     * on the table so a negative amount does not pass through the Money cast.
     * With a line map, each new line stores the QuickBooks line it came from, and each keeps the
     * product it names.
     *
     * @param array<int, mixed>                            $items
     * @param array{realm?: string, invoice?: string}|null $lineMap
     */
    private function replaceInvoiceLines(string $invoiceUuid, array $items, ?array $lineMap = null): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\InvoiceItem';
        if (class_exists($class) === false || $invoiceUuid === '') {
            return;
        }

        $model = new $class();
        $now   = Carbon::now()->toDateTimeString();
        $model->getConnection()->table($model->getTable())
            ->where('invoice_uuid', $invoiceUuid)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        $rows = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }
            $row = [
                'uuid'         => (string) Str::uuid(),
                'invoice_uuid' => $invoiceUuid,
                'description'  => (string) ($item['description'] ?? ''),
                'quantity'     => (int) ($item['quantity'] ?? 1),
                'unit_price'   => (int) ($item['unit_price'] ?? 0),
                'amount'       => (int) ($item['amount'] ?? 0),
                'tax_rate'     => InvoiceMapper::taxRate($item['tax_rate'] ?? 0),
                'tax_amount'   => (int) ($item['tax_amount'] ?? 0),
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
            $row['meta'] = self::replacedLineMeta($item, $lineMap);
            $rows[]      = $row;
        }
        if ($rows === []) {
            return;
        }

        $model->getConnection()->table($model->getTable())->insert($rows);
    }

    /**
     * The meta of a line replaced from QuickBooks: the QuickBooks line it came from (with a line
     * map), and the product it named before the copy (InvoiceMapper::withLineProducts).
     *
     * @param array<string, mixed>                         $item
     * @param array{realm?: string, invoice?: string}|null $lineMap
     */
    private static function replacedLineMeta(array $item, ?array $lineMap): ?string
    {
        $meta   = [];
        $lineId = $lineMap !== null ? trim((string) ($item['qbo_line_id'] ?? '')) : '';
        if ($lineId !== '') {
            $meta[self::LINE_META] = [
                'realm'   => (string) ($lineMap['realm'] ?? ''),
                'invoice' => (string) ($lineMap['invoice'] ?? ''),
                'id'      => $lineId,
            ];
        }
        $product = ProductMapper::metaProduct(['product' => $item['product'] ?? null]);
        if ($product !== null) {
            $meta['product'] = $product;
        }

        return $meta === [] ? null : (string) json_encode($meta);
    }

    /**
     * @param class-string       $class
     * @param array<int, string> $uuids
     *
     * @return array<string, true>
     */
    private function existingCustomerUuids(string $class, array $uuids): array
    {
        $present = [];
        if ($uuids === []) {
            return $present;
        }

        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach ($class::query()->withoutGlobalScopes()->whereIn('uuid', $chunk)->pluck('uuid') as $uuid) {
                $present[(string) $uuid] = true;
            }
        }

        return $present;
    }

    /**
     * @param class-string                     $class
     * @param array<int, array<string, mixed>> $customers
     */
    private function insertCustomers(string $class, array $customers): void
    {
        if ($customers === []) {
            return;
        }

        $rows = $this->customerInsertRows($class, $customers);
        $this->fillMissingPlaceUuids($rows);
        foreach (array_chunk($rows, 200) as $chunk) {
            $class::query()->insert($chunk);
        }
    }

    /**
     * @param class-string                     $class
     * @param array<int, array<string, mixed>> $customers
     *
     * @return array<int, array<string, mixed>>
     */
    private function customerInsertRows(string $class, array $customers): array
    {
        $now      = Carbon::now()->toDateTimeString();
        $rows     = [];
        $store    = $this->placeTable();
        $canPlace = $store !== null && $this->customerColumnExists($class, 'place_uuid') === true;
        foreach ($customers as $customer) {
            $row = $this->customerInsertRow($customer, $now, $canPlace, $store);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>                                                   $customer
     * @param array{connection: \Illuminate\Database\Connection, table: string}|null $store
     *
     * @return array<string, mixed>|null
     */
    private function customerInsertRow(array $customer, string $now, bool $canPlace, ?array $store): ?array
    {
        $uuid = (string) ($customer['uuid'] ?? '');
        if ($uuid === '') {
            return null;
        }
        $name = (string) ($customer['name'] ?? '');
        $slug = $name === '' ? null : Str::slug($name);
        $meta = $customer['meta'] ?? null;
        if (is_array($meta) === true) {
            $encoded = json_encode($meta);
            $meta    = $encoded === false ? null : $encoded;
        }
        $row = [
            'uuid'         => $uuid,
            'public_id'    => 'contact_' . strtolower(Str::random(10)),
            'internal_id'  => (string) random_int(100000, 999999),
            'company_uuid' => $customer['company_uuid'],
            'name'         => $name !== '' ? $name : null,
            'email'        => $customer['email'] ?? null,
            'phone'        => $customer['phone'] ?? null,
            'type'         => 'customer',
            'notes'        => $customer['notes'] ?? null,
            'meta'         => $meta,
            'slug'         => $slug === '' ? null : $slug,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $placeUuid = $this->customerPlaceUuid($store, $canPlace, $customer, $uuid);
        if ($placeUuid !== null) {
            $row['place_uuid'] = $placeUuid;
        }

        return $row;
    }

    /**
     * @param array{connection: \Illuminate\Database\Connection, table: string}|null $store
     * @param array<string, mixed>                                                   $customer
     */
    private function customerPlaceUuid(?array $store, bool $canPlace, array $customer, string $uuid): ?string
    {
        $address = $customer['address'] ?? null;
        if ($canPlace === false || $store === null || is_array($address) === false || $this->billingAddressHasContent($address) === false) {
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
            $id = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
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

        $created = $this->insertCustomerPlace($store, (string) $customer->company_uuid, (string) $customer->uuid, $address);
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
        if ($connection->getDriverName() === 'mysql' && $connection->getSchemaBuilder()->hasColumn($store['table'], 'location') === true) {
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

    private function customerColumnExists(string $class, string $column): bool
    {
        if (class_exists($class) === false) {
            return false;
        }

        try {
            $model = new $class();

            return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
