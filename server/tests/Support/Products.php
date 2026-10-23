<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\ProductMapper;

/**
 * Ledger rows for the product sync tests: one company on realm-1, its "Fleetbase service" item
 * item-1 posting to income account 79.
 */
class Products
{
    public const COMPANY = 'company-uuid';

    public static function ledger(FakeQuickBooks $client): SyncLedger
    {
        $ledger                             = new SyncLedger();
        $ledger->connections[self::COMPANY] = [
            'company_uuid'    => self::COMPANY,
            'realm_id'        => 'realm-1',
            'needs_reauth'    => false,
            'home_currency'   => 'USD',
            'default_item_id' => 'item-1',
        ];
        $client->items['item-1'] = [
            'Id'               => 'item-1',
            'SyncToken'        => '0',
            'Name'             => 'Fleetbase service',
            'Type'             => 'NonInventory',
            'Active'           => true,
            'Taxable'          => true,
            'IncomeAccountRef' => ['value' => '79', 'name' => 'Sales'],
        ];

        return $ledger;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function product(string $uuid, string $name, array $overrides = []): array
    {
        return array_merge([
            'uuid'         => $uuid,
            'company_uuid' => self::COMPANY,
            'public_id'    => 'product_' . $uuid,
            'name'         => $name,
            'description'  => '',
            'sku'          => '',
            'price'        => 1200,
            'currency'     => 'USD',
            'is_service'   => false,
            'status'       => 'published',
            'deleted_at'   => null,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pending(string $type, string $uuid): array
    {
        return [
            'company_uuid' => self::COMPANY,
            'local_type'   => $type,
            'local_uuid'   => $uuid,
            'status'       => 'pending',
            'attempts'     => 0,
        ];
    }

    /**
     * The pending row of a product name, as a rename or a delete queues it.
     *
     * @return array<string, mixed>
     */
    public static function namePending(string $name): array
    {
        return self::pending('item', ProductMapper::itemUuid(self::COMPANY, $name));
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(string $name, string $qboId, ?string $state = null): array
    {
        return [
            'company_uuid' => self::COMPANY,
            'realm_id'     => 'realm-1',
            'local_type'   => 'item',
            'local_uuid'   => ProductMapper::itemUuid(self::COMPANY, $name),
            'qbo_entity'   => 'Item',
            'qbo_id'       => $qboId,
            'sync_token'   => '0',
            'name'         => ProductMapper::name($name),
            'state'        => $state,
        ];
    }

    /**
     * A QuickBooks product.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function item(string $id, string $name, array $overrides = []): array
    {
        return array_merge([
            'Id'               => $id,
            'SyncToken'        => '0',
            'Name'             => $name,
            'Type'             => 'NonInventory',
            'Active'           => true,
            'UnitPrice'        => '12.00',
            'IncomeAccountRef' => ['value' => '79'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function linkOf(SyncLedger $ledger, string $name): ?array
    {
        return $ledger->link(self::COMPANY, 'realm-1', 'item', ProductMapper::itemUuid(self::COMPANY, $name));
    }

    /**
     * A ledger with customer cust-1 linked to qbo-customer and invoice inv-1 pending, with these
     * lines of one unit each (description => cents).
     *
     * @param array<string, int> $lines
     */
    public static function invoiceLedger(FakeQuickBooks $client, array $lines): SyncLedger
    {
        $ledger                      = self::ledger($client);
        $ledger->customers['cust-1'] = [
            'uuid'         => 'cust-1',
            'company_uuid' => self::COMPANY,
            'name'         => 'Ada',
            'email'        => 'ada@example.test',
        ];
        $ledger->links[]             = [
            'company_uuid' => self::COMPANY,
            'realm_id'     => 'realm-1',
            'local_type'   => 'customer',
            'local_uuid'   => 'cust-1',
            'qbo_entity'   => 'Customer',
            'qbo_id'       => 'qbo-customer',
            'sync_token'   => '0',
        ];
        $client->customers['qbo-customer'] = [
            'Id'          => 'qbo-customer',
            'SyncToken'   => '0',
            'DisplayName' => 'Ada',
            'Taxable'     => true,
        ];
        $items                             = [];
        foreach ($lines as $description => $cents) {
            $items[] = ['description' => $description, 'quantity' => 1, 'unit_price' => $cents, 'amount' => $cents];
        }
        $ledger->invoices['inv-1'] = [
            'uuid'          => 'inv-1',
            'company_uuid'  => self::COMPANY,
            'customer_uuid' => 'cust-1',
            'number'        => 'INV-1',
            'date'          => '2026-09-01',
            'due_date'      => '2026-09-15',
            'currency'      => 'USD',
            'tax'           => 0,
            'total'         => array_sum($lines),
            'status'        => 'sent',
            'items'         => $items,
        ];
        $ledger->pending[] = self::pending('invoice', 'inv-1');

        return $ledger;
    }

    /**
     * QuickBooks invoice qb-1, linked to inv-1, with these sales lines (description, amount,
     * product), line ids 1, 2, ... recorded on the Fleetbase lines in order.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $lines
     */
    public static function remoteInvoice(FakeQuickBooks $client, SyncLedger $ledger, array $lines): void
    {
        $remote = [];
        foreach ($lines as $index => [$description, $amount, $item]) {
            $id                                     = (string) ($index + 1);
            $line                                   = InvoiceLines::sales($id, $description, $amount);
            $line['SalesItemLineDetail']['ItemRef'] = ['value' => $item, 'name' => $description];
            $remote[]                               = $line;
            self::recordLineId($ledger, $index, $id);
        }
        $client->invoices['qb-1'] = [
            'Id'        => 'qb-1',
            'SyncToken' => '1',
            'DocNumber' => 'INV-1',
            'TotalAmt'  => number_format(array_sum(array_map('floatval', array_column($lines, 1))), 2, '.', ''),
            'TxnDate'   => '2026-09-01',
            'DueDate'   => '2026-09-15',
            'Line'      => $remote,
        ];
        $ledger->links[] = [
            'local_type' => 'invoice',
            'local_uuid' => 'inv-1',
            'qbo_entity' => 'Invoice',
            'qbo_id'     => 'qb-1',
        ] + self::link('x', 'x');
    }

    private static function recordLineId(SyncLedger $ledger, int $index, string $id): void
    {
        $ledger->invoices['inv-1']['items'][$index]['qbo_line_id'] = $id;
    }

    /**
     * The QuickBooks product of each line of a QuickBooks invoice.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<int, string>
     */
    public static function lineItems(array $invoice): array
    {
        $ids = [];
        foreach ($invoice['Line'] ?? [] as $line) {
            if (($line['DetailType'] ?? '') === 'SalesItemLineDetail') {
                $ids[] = (string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? '');
            }
        }

        return $ids;
    }
}
