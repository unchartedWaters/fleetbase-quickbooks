<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\LedgerPaymentDates;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\SafeLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * FleetbaseDirectory's reads of Fleetbase records: customers, invoices and their lines, wallets
 * and Storefront products. It shares the directory's state, so it lives here only to keep
 * FleetbaseDirectory.php readable.
 */
trait ReadsRecords
{
    /**
     * Products matter to invoice, product and product name rows only.
     */
    private function needsProducts(SyncLedger $ledger): bool
    {
        foreach ($ledger->pending as $row) {
            if (in_array($row['local_type'] ?? '', ['invoice', 'product', 'item'], true) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The company's QuickBooks product links: one per product name, so few rows. Every one is
     * loaded, so an invoice line knows which QuickBooks products Fleetbase manages.
     */
    private function readItemLinks(SyncLedger $ledger, string $companyUuid): void
    {
        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid)->where('local_type', 'item');
        foreach ($query->get() as $link) {
            if ($link instanceof Link) {
                $this->rememberLoadedLink($ledger, $link->toArray());
            }
        }
    }

    /**
     * The Storefront products this run needs: those of the pending product rows (deleted or a
     * draft too), and every live product sharing a name with them, with a pending product name
     * row or with an invoice line. Names are compared as QuickBooks compares them
     * (ProductMapper::key), so the company's live product names are read first. Without
     * Storefront there are no products; a failed read marks them unavailable, so no product
     * counts as gone.
     */
    private function readProducts(SyncLedger $ledger, string $companyUuid): void
    {
        $keys       = [];
        $productIds = $this->pendingProductIds($ledger, $companyUuid, $keys);
        $keys += $this->invoiceLineKeys($ledger);
        $class      = self::productClass();
        if (($productIds === [] && $keys === []) || $class === null) {
            return;
        }

        try {
            $this->loadProducts($ledger, $class, $companyUuid, $productIds, $keys);
        } catch (\Throwable $exception) {
            if ($this->isAbsentTable($exception) === true) {
                // Storefront is installed but its tables are not there: no products.
                $ledger->products        = [];
                $ledger->productsMissing = true;

                return;
            }
            $ledger->productsUnavailable = true;
            SafeLog::warning('QuickBooks could not read Storefront products; product rows are tried again later.', [
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * The product uuids of the pending product rows. The name of each pending product name row
     * is added to $keys.
     *
     * @param array<string, true> $keys
     *
     * @return array<int, string>
     */
    private function pendingProductIds(SyncLedger $ledger, string $companyUuid, array &$keys): array
    {
        $productIds = [];
        foreach ($ledger->pending as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if (($row['local_type'] ?? '') === 'product') {
                $productIds[] = $uuid;
            } elseif (($row['local_type'] ?? '') === 'item') {
                $link = $ledger->link(
                    $companyUuid,
                    (string) ($ledger->connections[$companyUuid]['realm_id'] ?? ''),
                    'item',
                    $uuid
                );
                $name = is_array($link) === true ? trim((string) ($link['name'] ?? '')) : '';
                if ($name !== '') {
                    $keys[ProductMapper::key($name)] = true;
                }
            }
        }

        return $productIds;
    }

    /**
     * The name keys of every invoice line: its product name, or else its description.
     *
     * @return array<string, true>
     */
    private function invoiceLineKeys(SyncLedger $ledger): array
    {
        $keys = [];
        foreach ($ledger->invoices as $invoice) {
            foreach (is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [] as $item) {
                $description = is_array($item) === true ? trim((string) ($item['description'] ?? '')) : '';
                $named       = is_array($item) === true ? ProductMapper::lineProduct($item) : null;
                if ($named !== null || $description !== '') {
                    $keys[ProductMapper::key($named ?? $description)] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Read the pending products, then every live product whose name key is in $keys.
     *
     * @param class-string<Model> $class
     * @param array<int, string>  $productIds
     * @param array<string, true> $keys
     */
    private function loadProducts(
        SyncLedger $ledger,
        string $class,
        string $companyUuid,
        array $productIds,
        array $keys,
    ): void {
        $pendingIds = array_values(array_unique($productIds));
        foreach ($this->productRows($class, $companyUuid, $pendingIds, true) as $product) {
            $ledger->products[$product['uuid']]                          = $product;
            $keys[ProductMapper::key((string) ($product['name'] ?? ''))] = true;
        }
        $live = [];
        foreach ($this->productQuery($class, $companyUuid, false)->get(['uuid', 'name', 'status']) as $row) {
            $row = (array) $row;
            $key = ProductMapper::key((string) ($row['name'] ?? ''));
            if (isset($keys[$key]) === true && strtolower(trim((string) ($row['status'] ?? ''))) !== 'draft') {
                $live[] = (string) ($row['uuid'] ?? '');
            }
        }
        foreach ($this->productRows($class, $companyUuid, array_values(array_unique($live)), false) as $product) {
            $ledger->products[$product['uuid']] = $product;
        }
    }

    /**
     * The Storefront product model, or null without Storefront.
     *
     * @return class-string<Model>|null
     */
    private static function productClass(): ?string
    {
        $class = self::PRODUCT_CLASS;
        if (class_exists($class) === false || is_subclass_of($class, Model::class) === false) {
            return null;
        }

        return $class;
    }

    /**
     * The company's products, on Storefront's own database connection.
     *
     * @param class-string<Model> $class
     */
    private function productQuery(
        string $class,
        string $companyUuid,
        bool $withTrashed,
    ): \Illuminate\Database\Query\Builder {
        $query       = (new $class())->newQuery();
        $softDeletes = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true);
        if ($withTrashed === true && $softDeletes === true) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
        }

        return $query->toBase()->where('company_uuid', $companyUuid);
    }

    /**
     * @param class-string<Model> $class
     * @param array<int, string>  $uuids
     *
     * @return array<int, array<string, mixed>>
     */
    private function productRows(string $class, string $companyUuid, array $uuids, bool $withTrashed): array
    {
        $rows  = [];
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => $uuid !== ''));
        foreach (array_chunk($uuids, 500) as $chunk) {
            $query = $this->productQuery($class, $companyUuid, $withTrashed)->whereIn('uuid', $chunk);
            foreach ($query->get(self::PRODUCT_COLUMNS) as $row) {
                $row    = (array) $row;
                $rows[] = [
                    'uuid'         => (string) ($row['uuid'] ?? ''),
                    'company_uuid' => (string) ($row['company_uuid'] ?? ''),
                    'public_id'    => (string) ($row['public_id'] ?? ''),
                    'name'         => (string) ($row['name'] ?? ''),
                    'description'  => (string) ($row['description'] ?? ''),
                    'sku'          => (string) ($row['sku'] ?? ''),
                    'price'        => (int) ($row['price'] ?? 0),
                    'currency'     => (string) ($row['currency'] ?? ''),
                    'is_service'   => (bool) ($row['is_service'] ?? false),
                    'status'       => (string) ($row['status'] ?? ''),
                    'deleted_at'   => $row['deleted_at'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Deleted customers are only read when flagged, so the engine can make the QuickBooks copy inactive.
     *
     * @param array<int, string>|null $onlyUuids    null loads every customer of the company
     * @param array<int, string>      $flaggedUuids deleted customers to read too
     *
     * @return array<string, array<string, mixed>>
     */
    private function readCustomers(string $companyUuid, ?array $onlyUuids = null, array $flaggedUuids = []): array
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        $query = $this->withFlaggedTrashed($class::query(), $flaggedUuids)
            ->where('company_uuid', $companyUuid)
            ->where('type', 'customer');
        if ($onlyUuids !== null) {
            $query->whereIn('uuid', $onlyUuids);
        }

        $models = $query->get();
        $places = $this->placesByUuid($this->customerPlaceIds($models));
        $mapper = new CustomerMapper();
        $rows   = [];
        /** @var Model $customer */
        foreach ($models as $customer) {
            $row = [
                'uuid'         => (string) $customer->uuid,
                'company_uuid' => $companyUuid,
                'type'         => 'customer',
                'name'         => $customer->name,
                'email'        => $customer->email,
                'phone'        => $customer->phone,
                'notes'        => $customer->notes,
            ];
            if (empty($customer->deleted_at) === false) {
                $row['deleted_at'] = $customer->deleted_at;
            }
            $placeUuid = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
            if ($placeUuid !== '' && isset($places[$placeUuid]) === true) {
                $address = $mapper->addressFromPlace($places[$placeUuid]);
                if ($address !== null) {
                    $row['address'] = $address;
                }
            }
            $rows[(string) $customer->uuid] = $row;
        }

        return $rows;
    }

    /**
     * Deleted invoices are only read when flagged, so the engine can void them once.
     *
     * @param array<int, string>|null $onlyUuids
     * @param array<int, string>      $flaggedUuids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readInvoices(string $companyUuid, ?array $onlyUuids, array $flaggedUuids): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        // The invoice model also eager-loads customer, template, and order.trackingNumber.
        // This read copies line items only; those three relations stay unloaded. Deleted items
        // that carry meta are read too: one holding a QuickBooks line id is a line Fleetbase removed.
        $this->lineMeta = [];
        $withItems      = static function ($items): void {
            $items->withTrashed()->where(static function ($scope): void {
                $scope->whereNull('deleted_at')->orWhereNotNull('meta->' . self::LINE_META);
            });
        };
        $query = $class::query()->where('company_uuid', $companyUuid)->withOnly(['items' => $withItems]);
        if ($onlyUuids !== null) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->whereIn('uuid', $onlyUuids);
        } elseif ($flaggedUuids !== []) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->where(function ($scope) use ($flaggedUuids): void {
                $scope->whereNull('deleted_at')->orWhereIn('uuid', $flaggedUuids);
            });
        }

        $rows          = [];
        $dates         = new LedgerPaymentDates();
        $connection    = (new $class())->getConnection();
        /** @var Model $invoice */
        foreach ($query->get() as $invoice) {
            [$items, $removed]             = $this->readInvoiceItems($invoice);
            $rows[(string) $invoice->uuid] = [
                'uuid'          => (string) $invoice->uuid,
                'company_uuid'  => $companyUuid,
                'customer_uuid' => (string) $invoice->customer_uuid,
                'customer_type' => (string) $invoice->customer_type,
                'number'        => $invoice->number,
                'date'          => $this->dateString($invoice->date),
                'due_date'      => $this->dateString($invoice->due_date),
                'notes'         => $invoice->notes,
                'currency'      => $invoice->currency,
                'tax'           => (int) $invoice->tax,
                'total'         => (int) $invoice->total_amount,
                'amount_paid'   => (int) $invoice->amount_paid,
                'status'        => (string) $invoice->status,
                'paid_at'       => $dates->day($connection, $companyUuid, $invoice->paid_at),
                'deleted_at'    => $invoice->deleted_at,
                'items'         => $items,
            ];
            if ($removed !== []) {
                $rows[(string) $invoice->uuid]['removed_lines'] = $removed;
            }
        }

        return $this->withRecordedPaymentDays($rows, $dates, $connection, $companyUuid);
    }

    /**
     * Ledger sets no paid date on a part-paid invoice. The day of its last recorded payment
     * dates the QuickBooks payment instead, and stays the same until another payment is made.
     *
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function withRecordedPaymentDays(
        array $rows,
        LedgerPaymentDates $dates,
        ConnectionInterface $connection,
        string $companyUuid,
    ): array {
        $undated = [];
        foreach ($rows as $uuid => $row) {
            if ($row['paid_at'] === '' && $row['amount_paid'] > 0) {
                $undated[] = (string) $uuid;
            }
        }
        foreach ($dates->lastPaymentDays($connection, $companyUuid, $undated) as $uuid => $day) {
            if (isset($rows[$uuid]) === true) {
                $rows[$uuid]['payment_date'] = $day;
            }
        }
        // A new QuickBooks payment carries the reference and method of the last Ledger payment.
        $paid    = array_keys(array_filter($rows, static fn (array $row): bool => $row['amount_paid'] > 0));
        $details = $dates->lastPaymentDetails($connection, $companyUuid, array_map('strval', $paid));
        foreach ($details as $uuid => $detail) {
            if (isset($rows[$uuid]) === true) {
                $rows[$uuid]['payment_reference'] = $detail['reference'];
                $rows[$uuid]['payment_method']    = $detail['method'];
            }
        }

        return $rows;
    }

    /**
     * Live items with their uuid and stored QuickBooks line, and the stored lines of deleted items.
     *
     * @return array{
     *     0: array<int, array<string, mixed>>,
     *     1: array<int, array{
     *         uuid: string, realm: string, invoice: string, id: string, description: string, amount: int
     *     }>
     * }
     */
    private function readInvoiceItems(Model $invoice): array
    {
        $items   = [];
        $removed = [];
        $known   = ['live' => [], 'trashed' => []];
        /** @var iterable<Model> $lines */
        $lines = $invoice->items;
        foreach ($lines as $item) {
            $uuid   = (string) $item->uuid;
            $meta   = is_array($item->meta) === true ? $item->meta : null;
            $stored = self::storedLine($meta);
            if (empty($item->deleted_at) === false) {
                $known['trashed'][$uuid] = $meta;
                if ($stored !== null) {
                    $removed[] = ['uuid' => $uuid] + $stored + [
                        'description' => (string) $item->description,
                        'amount'      => (int) $item->amount,
                    ];
                }
                continue;
            }
            $known['live'][$uuid] = $meta;
            $row                  = [
                'description' => $item->description,
                'quantity'    => (int) $item->quantity,
                'unit_price'  => (int) $item->unit_price,
                'amount'      => (int) $item->amount,
                'tax_rate'    => InvoiceMapper::taxRate($item->tax_rate),
                'tax_amount'  => (int) $item->tax_amount,
                'uuid'        => $uuid,
            ];
            if ($stored !== null) {
                $row['qbo_line'] = $stored;
            }
            $product = ProductMapper::metaProduct($meta);
            if ($product !== null) {
                $row['product'] = $product;
            }
            $items[] = $row;
        }
        $this->lineMeta[(string) $invoice->uuid] = $known;

        return [$items, $removed];
    }

    /**
     * @param array<string, mixed>|null $meta
     *
     * @return array{realm: string, invoice: string, id: string}|null
     */
    private static function storedLine(?array $meta): ?array
    {
        $line = $meta[self::LINE_META] ?? null;
        if (is_array($line) === false) {
            return null;
        }
        $stored = [];
        foreach (['realm', 'invoice', 'id'] as $key) {
            $value = $line[$key] ?? null;
            if ((is_string($value) === false && is_int($value) === false) || trim((string) $value) === '') {
                return null;
            }
            $stored[$key] = trim((string) $value);
        }

        return ['realm' => $stored['realm'], 'invoice' => $stored['invoice'], 'id' => $stored['id']];
    }

    /**
     * Item meta writes for one saved invoice: the QuickBooks line of each item after a sync matched
     * or pushed it, and no stored line on items whose line is gone from QuickBooks.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private function lineMetaRows(array $invoice): array
    {
        $known = $this->lineMeta[(string) ($invoice['uuid'] ?? '')] ?? null;
        if ($known === null) {
            return [];
        }
        $rows = [];
        $map  = is_array($invoice['line_map'] ?? null) === true ? $invoice['line_map'] : null;
        if (self::replacesLines($invoice) === true) {
            foreach ($known['live'] as $itemUuid => $meta) {
                $rows[] = self::withoutStoredLine((string) $itemUuid, $meta);
            }
        } elseif ($map !== null) {
            $items = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
            $rows  = self::mappedLineRows($items, $map, $known['live']);
        }
        foreach (is_array($invoice['cleared_lines'] ?? null) === true ? $invoice['cleared_lines'] : [] as $itemUuid) {
            if (array_key_exists((string) $itemUuid, $known['trashed']) === true) {
                $rows[] = self::withoutStoredLine((string) $itemUuid, $known['trashed'][(string) $itemUuid]);
            }
        }

        return array_values(array_filter($rows));
    }

    /**
     * Line tax writes for an invoice whose line taxes QuickBooks worked out (SyncEngine::copyQuickBooksTax).
     * Lines QuickBooks replaced are written whole instead (replaceInvoiceLines).
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private function lineTaxRows(array $invoice): array
    {
        $live = $this->lineMeta[(string) ($invoice['uuid'] ?? '')]['live'] ?? null;
        $copy = empty($invoice['line_taxes_from_quickbooks']) === false && self::replacesLines($invoice) === false;
        if ($copy === false || is_array($live) === false) {
            return [];
        }
        $rows = [];
        foreach (is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [] as $item) {
            $itemUuid = is_array($item) === true ? (string) ($item['uuid'] ?? '') : '';
            if ($itemUuid === '' || array_key_exists($itemUuid, $live) === false) {
                continue;
            }
            $rows[] = ['uuid' => $itemUuid, 'columns' => [
                'tax_rate'   => InvoiceMapper::taxRate($item['tax_rate'] ?? 0),
                'tax_amount' => (int) ($item['tax_amount'] ?? 0),
            ]];
        }

        return $rows;
    }

    /**
     * One write per item: the columns of rows for the same item are merged.
     *
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $rows
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private static function rowsByUuid(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $columns              = $row['columns'] + ($merged[$row['uuid']]['columns'] ?? []);
            $merged[$row['uuid']] = ['uuid' => $row['uuid'], 'columns' => $columns];
        }

        return array_values($merged);
    }

    /**
     * Meta writes for live items whose QuickBooks line is new or changed.
     *
     * @param array<mixed>                             $items
     * @param array<mixed>                             $map
     * @param array<string, array<string, mixed>|null> $live
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private static function mappedLineRows(array $items, array $map, array $live): array
    {
        $rows = [];
        foreach ($items as $item) {
            $itemUuid     = is_array($item) === true ? (string) ($item['uuid'] ?? '') : '';
            $lineId       = is_array($item) === true ? trim((string) ($item['qbo_line_id'] ?? '')) : '';
            if ($lineId === '' || array_key_exists($itemUuid, $live) === false) {
                continue;
            }
            $meta  = $live[$itemUuid] ?? [];
            $value = [
                'realm'   => (string) ($map['realm'] ?? ''),
                'invoice' => (string) ($map['invoice'] ?? ''),
                'id'      => $lineId,
            ];
            // A json column can return keys in another order, so compare the normalised line.
            if (self::storedLine($meta) === $value) {
                continue;
            }
            $meta[self::LINE_META] = $value;
            $rows[]                = ['uuid' => $itemUuid, 'columns' => ['meta' => json_encode($meta)]];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $meta
     *
     * @return array{uuid: string, columns: array<string, mixed>}|null
     */
    private static function withoutStoredLine(string $itemUuid, ?array $meta): ?array
    {
        if (is_array($meta) === false || array_key_exists(self::LINE_META, $meta) === false) {
            return null;
        }
        unset($meta[self::LINE_META]);

        return ['uuid' => $itemUuid, 'columns' => ['meta' => $meta === [] ? null : json_encode($meta)]];
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private static function replacesLines(array $invoice): bool
    {
        return empty($invoice['replace_from_quickbooks']) === false
            && empty($invoice['items_from_quickbooks']) === false
            && is_array($invoice['items'] ?? null) === true;
    }

    /**
     * @param array<int, string>|null $onlyUuids
     * @param array<int, string>      $flaggedUuids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readWallets(string $companyUuid, ?array $onlyUuids = null, array $flaggedUuids = []): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        $query = $this->withFlaggedTrashed($class::query(), $flaggedUuids)->where('company_uuid', $companyUuid);
        if ($onlyUuids !== null) {
            $query->whereIn('uuid', $onlyUuids);
        }

        $rows = [];
        /** @var Model $wallet */
        foreach ($query->get() as $wallet) {
            $rows[(string) $wallet->uuid] = [
                'uuid'         => (string) $wallet->uuid,
                'company_uuid' => $companyUuid,
                'public_id'    => (string) $wallet->public_id,
                'name'         => $wallet->name,
                'description'  => $wallet->description,
                'currency'     => (string) $wallet->currency,
                'status'       => (string) $wallet->status,
                'meta'         => $wallet->meta,
                // Read only: QuickBooks never changes a wallet balance, and it is not saved back.
                'balance'      => (int) ($wallet->balance ?? 0),
            ];
            if (empty($wallet->deleted_at) === false) {
                $rows[(string) $wallet->uuid]['deleted_at'] = $wallet->deleted_at;
            }
        }

        return $rows;
    }

    /**
     * Also reads the soft-deleted rows among these uuids, so a flagged delete reaches QuickBooks.
     *
     * @param array<int, string> $flaggedUuids
     */
    private function withFlaggedTrashed(
        \Illuminate\Database\Eloquent\Builder $query,
        array $flaggedUuids,
    ): \Illuminate\Database\Eloquent\Builder {
        $flaggedUuids = array_values(array_filter($flaggedUuids, static fn (string $uuid): bool => $uuid !== ''));
        if ($flaggedUuids === []) {
            return $query;
        }

        return $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->where(function ($scope) use ($flaggedUuids): void {
                $scope->whereNull('deleted_at')->orWhereIn('uuid', $flaggedUuids);
            });
    }

    private function dateString(mixed $value): string
    {
        if (is_object($value) === true && method_exists($value, 'toDateString') === true) {
            return (string) $value->toDateString();
        }

        return $value === null ? '' : (string) $value;
    }
}
