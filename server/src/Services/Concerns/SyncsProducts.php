<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\ProductItems;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's product sync: Storefront products and Fleet-Ops fees to QuickBooks items, and
 * the item each invoice line uses. It shares the engine's state, so it lives here only to keep
 * SyncEngine.php readable.
 */
trait SyncsProducts
{
    /**
     * Storefront product rows and product name rows, as one block: each name is read and written
     * once, its first row gets the outcome and the others are aligned. Products follow invoices,
     * so while invoices are not sent to QuickBooks the rows are closed without a QuickBooks call
     * or an Activity row. True when the run must stop.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncProductRows(
        SyncLedger $ledger,
        array &$connection,
        array $rows,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): bool {
        $companyUuid = (string) $connection['company_uuid'];
        $keys        = [];
        $sends       = $this->sendsProducts($settings);
        foreach ($rows as $index => $row) {
            $keys[$index] = $sends === true ? $this->productRowKey($ledger, $connection, $row) : '';
        }
        try {
            $results = $this->productItemsFor($ledger, $connection, array_values(array_filter($keys, 'is_string')));
        } catch (QuickBooksException $exception) {
            return $this->productReadFailed(
                $ledger,
                $connection,
                $rows[0],
                $exception,
                $settings,
                $batch,
                $now,
                $trigger
            );
        }
        $connection = $ledger->connection($companyUuid) ?? $connection;
        $this->recordProductRows($ledger, $connection, $rows, $keys, $results, $settings, $batch, $now, $trigger);

        return false;
    }

    /**
     * The product item read failed. A 401 or rate limit stops the run; anything else is thrown.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $batch
     */
    private function productReadFailed(
        SyncLedger $ledger,
        array $connection,
        array $row,
        QuickBooksException $exception,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): bool {
        if ($exception->isUnauthorized() === true) {
            $this->tokenRejected($ledger, (string) $connection['company_uuid']);
            $batch['failed']++;

            return true;
        }
        if ($exception->isRateLimit() === true) {
            $this->failure($ledger, $connection, $row, $exception, $settings, $now);
            $this->recordRow($ledger, $connection, $row, 'failed', $batch, $trigger, $this->exceptionError($exception));

            return true;
        }

        throw $exception;
    }

    /**
     * Record each product row: the first row of a name gets its outcome, the others are aligned.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string|null>          $keys
     * @param array<string, array{
     *     id: string|null, item: array<string, mixed>|null, outcome: string, error: string|null,
     *     transient: bool, note: string|null
     * }> $results
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $batch
     */
    private function recordProductRows(
        SyncLedger $ledger,
        array $connection,
        array $rows,
        array $keys,
        array $results,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): void {
        $companyUuid = (string) $connection['company_uuid'];
        $seen        = [];
        foreach ($rows as $index => $row) {
            $key    = $keys[$index];
            $result = $key === null ? $this->productsUnavailable() : ($results[$key] ?? null);
            if ($key === '' || $result === null) {
                $ledger->updatePending(
                    $companyUuid,
                    (string) $row['local_type'],
                    (string) $row['local_uuid'],
                    ['status' => 'done']
                );
                continue;
            }
            if ($result['outcome'] === 'failed') {
                $error = (string) $result['error'];
                $this->failure(
                    $ledger,
                    $connection,
                    $row,
                    new QuickBooksException($result['transient'] === true ? 503 : 400, $error),
                    $settings,
                    $now
                );
                $this->recordRow($ledger, $connection, $row, 'failed', $batch, $trigger, $error);
                continue;
            }
            $outcome = isset($seen[$key]) === true ? 'aligned' : $result['outcome'];
            if (isset($seen[$key]) === false && ($result['note'] ?? null) !== null) {
                $this->rowNotes[(string) $row['local_type'] . ':' . (string) $row['local_uuid']] = $result['note'];
            }
            $seen[$key] = true;
            $this->recordRow($ledger, $connection, $row, $outcome, $batch, $trigger, null);
        }
    }

    /**
     * The result for a row whose product name could not be worked out.
     *
     * @return array{id: null, item: null, outcome: string, error: string, transient: bool, note: null}
     */
    private function productsUnavailable(): array
    {
        return [
            'id'        => null,
            'item'      => null,
            'outcome'   => 'failed',
            'error'     => Translation::encode('quickbooks.messages.products-unavailable'),
            'transient' => true,
            'note'      => null,
        ];
    }

    /**
     * QuickBooks products are written only while invoices are sent to QuickBooks.
     *
     * @param array<string, mixed> $settings
     */
    private function sendsProducts(array $settings): bool
    {
        return $this->entityPaused(
            $settings,
            'invoice'
        ) === false && $this->pushesRemote($this->direction($settings, 'invoice')) === true;
    }

    /**
     * The name a product row or a product name row is about: '' when there is nothing to do, null
     * when Storefront could not be read.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $row
     */
    private function productRowKey(SyncLedger $ledger, array $connection, array $row): ?string
    {
        $uuid = (string) ($row['local_uuid'] ?? '');
        if ((string) ($row['local_type'] ?? '') === 'product') {
            $product = $ledger->products[$uuid] ?? null;
            if (is_array($product) === false) {
                return $ledger->productsUnavailable === true ? null : '';
            }

            return ProductMapper::key((string) ($product['name'] ?? ''));
        }
        $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'item', $uuid);
        $name = is_array($link) === true ? trim((string) ($link['name'] ?? '')) : '';

        return $name === '' ? '' : ProductMapper::key($name);
    }

    /**
     * The QuickBooks product of each name, read and written once per run. A failed read of the
     * service item fails every name; a 401 or a rate limit is thrown.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $keys
     *
     * @return array<string, array{
     *     id: string|null, item: array<string, mixed>|null, outcome: string, error: string|null,
     *     transient: bool, note: string|null
     * }>
     */
    private function productItemsFor(SyncLedger $ledger, array $connection, array $keys): array
    {
        $keys    = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
        $missing = array_values(array_filter(
            $keys,
            fn (string $key): bool => isset($this->productResults[$key]) === false
        ));
        if ($missing !== []) {
            try {
                $serviceId   = $this->serviceItemId($ledger, $connection);
                $serviceItem = $serviceId === '' ? null : $this->taxItem($ledger, $connection, $serviceId, true);
                // A price is sent only in the home currency, which an older connection may not hold yet.
                $connection['home_currency'] = $this->homeCurrency($ledger, $connection);
                $prepared                    = (new ProductItems($this->client))->prepare(
                    $ledger,
                    $connection,
                    $missing,
                    $serviceId,
                    $serviceItem ?? [],
                    $this->lineProducts
                );
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                    throw $exception;
                }
                $failed   = [
                    'id'        => null,
                    'item'      => null,
                    'outcome'   => 'failed',
                    'error'     => $this->exceptionError($exception),
                    'transient' => $exception->isTransient(),
                    'note'      => null,
                ];
                $prepared = array_fill_keys($missing, $failed);
            }
            $this->managedItems = [];
            foreach ($prepared as $key => $result) {
                $this->productResults[$key]     = $result;
                $itemId                         = trim((string) ($result['item']['Id'] ?? ''));
                if ($itemId !== '' && is_array($result['item']) === true) {
                    $this->itemRows[$itemId] = $result['item'];
                }
            }
        }

        return array_intersect_key($this->productResults, array_flip($keys));
    }

    /**
     * The products of the lines of every invoice row, read and made together before the invoices
     * run, so each invoice does not make its own QuickBooks calls. A failure is left to the
     * invoices, which meet it again and handle it.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     */
    private function prepareLineProducts(SyncLedger $ledger, array $connection, array $rows, array $settings): void
    {
        if ($this->sendsProducts($settings) === false || empty($connection['needs_reauth']) === false) {
            return;
        }
        $companyUuid = (string) $connection['company_uuid'];
        $keys        = [];
        try {
            foreach ($rows as $row) {
                $invoice = ($row['local_type'] ?? '') === 'invoice'
                    ? ($ledger->invoices[(string) ($row['local_uuid'] ?? '')] ?? null)
                    : null;
                if (is_array($invoice) === false || self::voidedInvoice($invoice) === true) {
                    continue;
                }
                foreach (is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [] as $item) {
                    $key = is_array($item) === true ? $this->lineKey($ledger, $companyUuid, $item) : null;
                    if ($key !== null) {
                        $keys[] = $key;
                    }
                }
            }
            if ($keys !== []) {
                $this->productItemsFor($ledger, $connection, $keys);
            }
        } catch (QuickBooksException) {
            // Products that cannot be read, a 401 or a rate limit: the invoices handle it.
        }
    }

    /**
     * The QuickBooks product of each line of an invoice, by position: the product of a live
     * Storefront product of the line's name, else the product of its fee, else the service item.
     * A product QuickBooks has made inactive, or one that could not be made, leaves the line on
     * the service item with a note; a temporary failure fails the invoice so it is tried again.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    private function lineItemIds(SyncLedger $ledger, array $connection, string $uuid, string $serviceItemId): array
    {
        $companyUuid = (string) $connection['company_uuid'];
        $items       = $ledger->invoices[$uuid]['items'] ?? null;
        $items       = is_array($items) === true ? array_values($items) : [];
        $keys        = [];
        $names       = [];
        foreach ($items as $index => $item) {
            $item          = is_array($item) === true ? $item : [];
            $keys[$index]  = $this->lineKey($ledger, $companyUuid, $item);
            $names[$index] = ProductMapper::lineProduct($item)
                ?? ProductMapper::name(trim((string) ($item['description'] ?? '')));
        }
        $results = $this->productItemsFor($ledger, $connection, array_values(array_filter($keys, 'is_string')));
        $ids     = [];
        foreach ($keys as $index => $key) {
            $result = $key === null ? null : ($results[$key] ?? null);
            if ($result === null) {
                $ids[$index] = $serviceItemId;
                continue;
            }
            if ($result['outcome'] === 'failed' && $result['transient'] === true) {
                throw new QuickBooksException(503, (string) $result['error']);
            }
            $note = $result['outcome'] === 'failed'
                ? Translation::encode('quickbooks.messages.invoice-product-failed', ['name' => $names[$index]])
                : $result['note'];
            if ($result['id'] === null && $note !== null) {
                $this->rowNotes['invoice:' . $uuid] ??= $note;
            }
            $ids[$index] = $result['id'] ?? $serviceItemId;
        }

        return $ids;
    }

    /**
     * The QuickBooks product of each product lines name in this run, by its id: the product as a
     * line's meta names it (InvoiceMapper::withLineProducts).
     *
     * @return array<string, array{name: string}>
     */
    private function lineProductItems(): array
    {
        $items = [];
        foreach ($this->lineProducts as $key => $name) {
            $itemId = trim((string) ($this->productResults[$key]['id'] ?? ''));
            if ($itemId !== '') {
                $items[$itemId] = ['name' => $name];
            }
        }

        return $items;
    }

    /**
     * The product name a line goes by: the product its meta names, else a live Storefront
     * product's, else a fee's, else none (the service item). Without Storefront products to read,
     * a line that is not a fee and names no product fails, since it may be a product.
     *
     * @param array<string, mixed> $item
     */
    private function lineKey(SyncLedger $ledger, string $companyUuid, array $item): ?string
    {
        $named       = ProductMapper::lineProduct($item);
        $description = trim((string) ($item['description'] ?? ''));
        $key         = ProductMapper::key($named ?? $description);
        if ($key === ProductMapper::key(ProductMapper::SERVICE_ITEM_NAME) || ($named ?? $description) === '') {
            return null;
        }
        if ($named !== null) {
            if (isset($this->lineProducts[$key]) === false) {
                // A name settled earlier in the run without its lines is settled again with them.
                unset($this->productResults[$key]);
                $this->lineProducts[$key] = $named;
            }

            return $key;
        }
        if (isset($this->liveProductKeys[$companyUuid]) === false) {
            $this->liveProductKeys[$companyUuid] = [];
            foreach ($ledger->products as $product) {
                if (ProductItems::isLive($product, $companyUuid) === true) {
                    $this->liveProductKeys[$companyUuid][ProductMapper::key((string) $product['name'])] = true;
                }
            }
        }
        if (isset($this->liveProductKeys[$companyUuid][$key]) === true) {
            return $key;
        }
        $fee = ProductMapper::feeName($description);
        if ($fee !== null) {
            return ProductMapper::key($fee);
        }
        if ($ledger->productsUnavailable === true) {
            throw new QuickBooksException(503, Translation::encode('quickbooks.messages.products-unavailable'));
        }

        return null;
    }

    /**
     * The QuickBooks products Fleetbase puts on lines: its product links in this realm, each with
     * the name key it is for, and the service item (true). Read once per run, and again after
     * products are made.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, string|true>
     */
    private function managedItems(SyncLedger $ledger, array $connection, string $serviceItemId): array
    {
        $scope = (string) $connection['company_uuid'] . '|' . (string) $connection['realm_id'];
        if (isset($this->managedItems[$scope]) === false) {
            $this->managedItems[$scope] = [];
            foreach ($ledger->links as $link) {
                $ours = is_array($link) === true
                    && ($link['local_type'] ?? '') === 'item'
                    && (string) ($link['company_uuid'] ?? '') === (string) $connection['company_uuid']
                    && (string) ($link['realm_id'] ?? '') === (string) $connection['realm_id'];
                $itemId   = $ours === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
                if ($itemId !== '') {
                    $this->managedItems[$scope][$itemId] = ProductMapper::key((string) ($link['name'] ?? ''));
                }
            }
        }
        $managed = $this->managedItems[$scope];
        if ($serviceItemId !== '') {
            $managed[$serviceItemId] = true;
        }

        return $managed;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function serviceItemId(SyncLedger $ledger, array $connection): string
    {
        $companyUuid = (string) $connection['company_uuid'];
        $stored      = $ledger->connections[$companyUuid] ?? null;
        $itemId      = trim((string) (is_array($stored) === true ? ($stored['default_item_id'] ?? '') : ''));
        if ($itemId === '') {
            $itemId = trim((string) ($connection['default_item_id'] ?? ''));
        }
        if ($itemId !== '') {
            return $itemId;
        }
        if (
            $this->serviceItemByCompany !== null
            && array_key_exists($companyUuid, $this->serviceItemByCompany) === true
        ) {
            return $this->serviceItemByCompany[$companyUuid];
        }
        if (isset($this->serviceItemErrors[$companyUuid]) === true) {
            throw $this->serviceItemErrors[$companyUuid];
        }

        try {
            $itemId = $this->client->ensureServiceItem($connection);
        } catch (QuickBooksException $exception) {
            $remembered = $exception->isUnauthorized() === false && $exception->isRateLimit() === false;
            if ($this->serviceItemErrors !== null && $remembered === true) {
                $this->serviceItemErrors[$companyUuid] = $exception;
            }

            throw $exception;
        }
        if ($this->serviceItemByCompany !== null) {
            $this->serviceItemByCompany[$companyUuid] = $itemId;
        }
        if ($itemId !== '') {
            $ledger->connections[$companyUuid]['default_item_id'] = $itemId;
        }

        return $itemId;
    }

    /**
     * Fault 2500 (invalid reference) on an invoice can mean the stored service item was made
     * inactive in QuickBooks, which renames it "... (deleted)" and refuses it on new lines.
     * When the stored item is no longer active it is forgotten, so the next sync finds or creates
     * an active "Fleetbase service". A block checks once.
     *
     * @param array<string, mixed> $connection
     */
    private function forgetInactiveServiceItem(
        SyncLedger $ledger,
        array &$connection,
        string $type,
        ?string $faultCode,
    ): void {
        if ($type !== 'invoice' || $faultCode !== '2500' || $this->serviceItemChecked === true) {
            return;
        }
        if ($this->serviceItemChecked === false) {
            $this->serviceItemChecked = true;
        }
        $companyUuid = (string) $connection['company_uuid'];
        $stored      = $ledger->connections[$companyUuid] ?? null;
        $itemId      = trim((string) (is_array($stored) === true ? ($stored['default_item_id'] ?? '') : ''));
        if ($itemId === '') {
            $itemId = trim((string) ($connection['default_item_id'] ?? ''));
        }
        if ($itemId === '') {
            return;
        }
        try {
            if ($this->client->itemActive($connection, $itemId) === true) {
                return;
            }
        } catch (QuickBooksException) {
            return;
        }
        if (is_array($stored) === true) {
            $ledger->connections[$companyUuid]['default_item_id']      = null;
            $ledger->connections[$companyUuid]['default_item_account'] = null;
        }
        $connection['default_item_id']      = null;
        $connection['default_item_account'] = null;
        if ($this->serviceItemByCompany !== null) {
            unset($this->serviceItemByCompany[$companyUuid]);
        }
    }
}
