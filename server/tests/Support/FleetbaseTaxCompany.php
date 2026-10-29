<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\InvoiceTax;

/**
 * Companies for the tests of tax worked out by Fleetbase: one Fleetbase invoice inv-1 for
 * customer cust-1 (QuickBooks qbo-customer), queued.
 */
class FleetbaseTaxCompany
{
    /**
     * A US company taxing at 8% (rate code 2), with a taxable customer and service item. The
     * Fleetbase invoice has one 10.00 line taxed 15% in Fleetbase.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
     */
    public static function us(array $invoice = [], ?FakeQuickBooks $client = null): array
    {
        [$engine, $client, $ledger] = self::company(['Taxable' => true], ['Taxable' => true], $invoice, $client);
        $client->salesTax           = ['mode' => InvoiceTax::US, 'automated' => false, 'rate' => '2'];
        $client->taxRates           = ['2' => '8'];

        return [$engine, $client, $ledger];
    }

    /**
     * A Canadian company: code 4 is GST 5% + PST 7% (the service item's), code 5 GST only and code
     * 6 exempt. The Fleetbase invoice has one 100.00 line taxed 12% in Fleetbase.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
     */
    public static function canada(array $invoice = []): array
    {
        [$engine, $client, $ledger] = self::company(['SalesTaxCodeRef' => ['value' => '4']], [], array_merge([
            'tax'   => 1200,
            'total' => 11200,
            'items' => [self::line(10000, '12.00', 1200)],
        ], $invoice));
        $client->salesTax    = [
            'mode'      => InvoiceTax::GLOBAL,
            'automated' => false,
            'rate'      => null,
            'codes'     => ['4' => ['gst', 'pst'], '5' => ['gst'], '6' => []],
            'compound'  => [],
        ];
        $client->taxRateList = [
            'gst' => ['percent' => '5', 'read_only' => false],
            'pst' => ['percent' => '7', 'read_only' => false],
        ];

        return [$engine, $client, $ledger];
    }

    /**
     * Link inv-1 to QuickBooks invoice qb-1 at SyncToken $token, the link storing $stored (else the
     * same token). A $marked link was first sent while Fleetbase worked out tax.
     *
     * @param array<string, mixed> $remote
     */
    public static function link(
        FakeQuickBooks $client,
        SyncLedger $ledger,
        string $token,
        array $remote,
        bool $marked,
        ?string $stored = null,
    ): void {
        $client->invoices['qb-1'] = array_merge([
            'Id'          => 'qb-1',
            'SyncToken'   => $token,
            'DocNumber'   => 'INV-1',
            'CustomerRef' => ['value' => 'qbo-customer'],
            'TxnDate'     => '2026-09-01',
            'DueDate'     => '2026-09-15',
        ], $remote);
        $link = PrimaryFixtures::link('invoice', 'inv-1', 'qb-1', $stored ?? $token);
        if ($marked === true) {
            $link['tax_source'] = 'fleetbase';
        }
        $ledger->links[] = $link;
        foreach ($ledger->invoices['inv-1']['items'] as $index => $item) {
            $ledger->invoices['inv-1']['items'][$index]['qbo_line'] = [
                'realm' => 'realm-1', 'invoice' => 'qb-1', 'id' => (string) ($index + 1),
            ];
        }
    }

    /**
     * A one-unit Fleetbase "Delivery" line (item-a) of $cents taxed at $rate.
     *
     * @return array<string, mixed>
     */
    public static function line(int $cents, string $rate, int $tax): array
    {
        return array_merge(InvoiceMapper::item('Delivery', 1, $cents, $cents, $rate, $tax), ['uuid' => 'item-a']);
    }

    /**
     * Canadian sales tax settings: code 4 GST + PST, 5 GST, 6 exempt, 7 GST + QST and 8 a
     * compound GST + PST.
     *
     * @return array<string, mixed>
     */
    public static function gstPst(): array
    {
        return [
            'mode'      => InvoiceTax::GLOBAL,
            'automated' => false,
            'rate'      => null,
            'codes'     => [
                '4' => ['gst', 'pst'], '5' => ['gst'], '6' => [], '7' => ['gst', 'qst'], '8' => ['gst', 'pst'],
            ],
            'compound'  => ['8'],
        ];
    }

    /**
     * GST 5%, PST 7% and QST 9.975%.
     *
     * @return array<string, array{percent: string, read_only: bool}>
     */
    public static function rates(): array
    {
        return [
            'gst' => ['percent' => '5', 'read_only' => false],
            'pst' => ['percent' => '7', 'read_only' => false],
            'qst' => ['percent' => '9.975', 'read_only' => false],
        ];
    }

    /**
     * Sync settings with Fleetbase working out tax.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function settings(array $overrides = []): array
    {
        return \qbSettings(array_merge(['interval_minutes' => 1, 'tax_source' => 'fleetbase'], $overrides));
    }

    /**
     * A second queued invoice inv-2 (INV-2) like inv-1, so a run sends invoices in a batch.
     */
    public static function second(SyncLedger $ledger): void
    {
        $invoice           = $ledger->invoices['inv-1'];
        $invoice['uuid']   = 'inv-2';
        $invoice['number'] = 'INV-2';
        foreach ($invoice['items'] as $index => $item) {
            $invoice['items'][$index]['uuid'] = 'item-b' . $index;
        }
        $ledger->invoices['inv-2'] = $invoice;
        $ledger->pending[]         = PrimaryFixtures::pending('invoice', 'inv-2');
    }

    /**
     * Run the invoices again, as a later scheduled sync.
     *
     * @param array<string, mixed> $settings
     * @param array<int, string>   $uuids
     *
     * @return array<string, mixed>
     */
    public static function again(
        SyncEngine $engine,
        SyncLedger $ledger,
        array $settings,
        int $when,
        array $uuids = ['inv-1'],
    ): array {
        $ledger->connections['company-uuid']['last_batch_at'] = null;
        $ledger->pending                                      = array_map(
            static fn (string $uuid): array => PrimaryFixtures::pending('invoice', $uuid),
            $uuids
        );

        return $engine->runScheduled($ledger, 'company-uuid', $settings, $when);
    }

    /**
     * The invoice link as the ledger holds it.
     *
     * @return array<string, mixed>
     */
    public static function invoiceLink(SyncLedger $ledger): array
    {
        return $ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1') ?? [];
    }

    /**
     * @param array<string, mixed> $item     the QuickBooks service item's fields
     * @param array<string, mixed> $customer the QuickBooks customer's fields
     * @param array<string, mixed> $invoice
     *
     * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
     */
    private static function company(array $item, array $customer, array $invoice, ?FakeQuickBooks $client = null): array
    {
        [$engine, $client]                 = \qbEngine($client);
        $client->assignLineIds             = true;
        $client->items['item-1']           = array_merge([
            'Id'               => 'item-1',
            'Name'             => 'Fleetbase service',
            'IncomeAccountRef' => ['value' => '1', 'name' => 'Services'],
        ], $item);
        $client->customers['qbo-customer'] = array_merge(PrimaryFixtures::remoteCustomer('0'), $customer);
        $ledger                            = PrimaryFixtures::ledger();
        $ledger->customers['cust-1']       = PrimaryFixtures::customer();
        $ledger->links[]                   = PrimaryFixtures::link('customer', 'cust-1', 'qbo-customer', '0');
        $ledger->invoices['inv-1']         = PrimaryFixtures::invoice(array_merge([
            'tax'   => 150,
            'total' => 1150,
            'items' => [self::line(1000, '15.00', 150)],
        ], $invoice));
        $ledger->pending[]                 = PrimaryFixtures::pending('invoice', 'inv-1');

        return [$engine, $client, $ledger];
    }
}
