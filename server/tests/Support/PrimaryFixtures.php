<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;

/**
 * Records for the QuickBooks-as-Primary change detection tests: one company, one linked
 * customer and one linked invoice, with the shipped sync defaults (QuickBooks Primary, Both).
 */
class PrimaryFixtures
{
    /** The Activity note when QuickBooks, as Primary, replaced a Fleetbase change. */
    public const REPLACED = 'QuickBooks, as Primary, replaced a change made in Fleetbase because the record was'
        . ' also changed in QuickBooks.';

    /**
     * The Activity notes of a run, as an en-us reader sees them.
     *
     * @return array<int, string>
     */
    public static function notes(SyncLedger $ledger): array
    {
        return \qbTexts(array_values(array_filter(array_column($ledger->attempts, 'error'))));
    }

    /**
     * The SyncToken a link stores now.
     */
    public static function token(SyncLedger $ledger, string $type, string $uuid): string
    {
        return (string) ($ledger->link('company-uuid', 'realm-1', $type, $uuid)['sync_token'] ?? '');
    }

    /**
     * A payment link stored under the payment id, for invoice inv-1.
     *
     * @return array<string, mixed>
     */
    public static function paymentLink(string $id, string $token): array
    {
        return array_merge(self::link('payment', $id, $id, $token), ['invoice_uuid' => 'inv-1']);
    }

    /**
     * Invoice fields after Fleetbase added a "Detention 2h" line of 5.00 to the 10.00 delivery.
     *
     * @return array<string, mixed>
     */
    public static function withDetention(): array
    {
        return ['total' => 1500, 'items' => [self::invoice()['items'][0], self::detention()]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detention(): array
    {
        return ['description' => 'Detention 2h', 'quantity' => 1, 'unit_price' => 500, 'amount' => 500];
    }

    /**
     * A linked customer whose Fleetbase phone is $localPhone and QuickBooks phone $remotePhone.
     *
     * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
     */
    public static function linkedCustomer(
        string $storedToken,
        string $remoteToken,
        string $localPhone = '555-1111',
        string $remotePhone = '555-0000',
    ): array {
        [$engine, $client]                 = \qbEngine();
        $ledger                            = self::ledger();
        $ledger->customers['cust-1']       = self::customer(['phone' => $localPhone]);
        $client->customers['qbo-customer'] = self::remoteCustomer($remoteToken, [
            'PrimaryPhone' => ['FreeFormNumber' => $remotePhone],
        ]);
        $ledger->links[] = self::link('customer', 'cust-1', 'qbo-customer', $storedToken);

        return [$engine, $client, $ledger];
    }

    /**
     * The shipped defaults of config/quickbooks.php for every type, wallets on.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function shippedSettings(array $overrides = []): array
    {
        $settings = ['interval_minutes' => 1, 'wallet_enabled' => true];
        foreach (['customer', 'invoice', 'payment', 'wallet'] as $type) {
            $settings[$type . '_conflict']  = 'quickbooks';
            $settings[$type . '_reference'] = 'quickbooks';
            $settings[$type . '_direction'] = 'both';
        }

        return \qbSettings(array_merge($settings, $overrides));
    }

    public static function ledger(): SyncLedger
    {
        $ledger                              = new SyncLedger();
        $ledger->connections['company-uuid'] = [
            'company_uuid'    => 'company-uuid',
            'realm_id'        => 'realm-1',
            'needs_reauth'    => false,
            'home_currency'   => 'USD',
            'default_item_id' => 'item-1',
        ];

        return $ledger;
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(string $type, string $uuid, string $qboId, string $token): array
    {
        $entities = ['customer' => 'Customer', 'invoice' => 'Invoice', 'wallet' => 'Account', 'payment' => 'Payment'];

        return [
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'local_type'   => $type,
            'local_uuid'   => $uuid,
            'qbo_entity'   => $entities[$type],
            'qbo_id'       => $qboId,
            'sync_token'   => $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pending(string $type, string $uuid, string $reason = 'saved'): array
    {
        return [
            'company_uuid' => 'company-uuid',
            'local_type'   => $type,
            'local_uuid'   => $uuid,
            'status'       => 'pending',
            'attempts'     => 0,
            'reason'       => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function customer(array $overrides = []): array
    {
        return array_merge([
            'uuid'         => 'cust-1',
            'company_uuid' => 'company-uuid',
            'name'         => 'Ada',
            'email'        => 'ada@example.test',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function remoteCustomer(string $token, array $overrides = []): array
    {
        return array_merge([
            'Id'               => 'qbo-customer',
            'SyncToken'        => $token,
            'DisplayName'      => 'Ada',
            'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function invoice(array $overrides = []): array
    {
        return array_merge([
            'uuid'          => 'inv-1',
            'company_uuid'  => 'company-uuid',
            'customer_uuid' => 'cust-1',
            'number'        => 'INV-1',
            'date'          => '2026-09-01',
            'due_date'      => '2026-09-15',
            'currency'      => 'USD',
            'tax'           => 0,
            'total'         => 1000,
            'status'        => 'sent',
            'items'         => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function remoteInvoice(string $token, array $overrides = []): array
    {
        return array_merge([
            'Id'          => 'qb-1',
            'SyncToken'   => $token,
            'DocNumber'   => 'INV-1',
            'CustomerRef' => ['value' => 'qbo-customer'],
            'TotalAmt'    => '10.00',
            'TxnDate'     => '2026-09-01',
            'DueDate'     => '2026-09-15',
            'Line'        => [[
                'Amount'              => '10.00',
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => 'Delivery',
                'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
            ]],
        ], $overrides);
    }

    /**
     * A payment of $amount applied to invoice qb-1.
     *
     * @return array<string, mixed>
     */
    public static function remotePayment(string $id, string $token, string $amount): array
    {
        return [
            'Id'          => $id,
            'SyncToken'   => $token,
            'CustomerRef' => ['value' => 'qbo-customer'],
            'TotalAmt'    => $amount,
            'TxnDate'     => '2026-09-05',
            'Line'        => [['Amount' => $amount, 'LinkedTxn' => [['TxnId' => 'qb-1', 'TxnType' => 'Invoice']]]],
        ];
    }

    /**
     * A linked customer and a linked invoice, both in QuickBooks, with the invoice queued.
     * The invoice link stores $invoiceToken.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remoteInvoice
     *
     * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
     */
    public static function linkedInvoice(string $invoiceToken, array $invoice = [], array $remoteInvoice = []): array
    {
        [$engine, $client]                 = \qbEngine();
        $ledger                            = self::ledger();
        $ledger->customers['cust-1']       = self::customer();
        $client->customers['qbo-customer'] = self::remoteCustomer('0');
        $ledger->links[]                   = self::link('customer', 'cust-1', 'qbo-customer', '0');
        $ledger->invoices['inv-1']         = self::invoice($invoice);
        $client->invoices['qb-1']          = self::remoteInvoice($invoiceToken, $remoteInvoice);
        $ledger->links[]                   = self::link('invoice', 'inv-1', 'qb-1', $invoiceToken);
        $ledger->pending[]                 = self::pending('invoice', 'inv-1');

        return [$engine, $client, $ledger];
    }

    /**
     * A linked wallet whose description Fleetbase changed to "Fuel float"; the QuickBooks account
     * still says "Float". The link stores $storedToken and the account has $remoteToken.
     *
     * @return array{0: SyncEngine, 1: FakeQuickBooks, 2: SyncLedger}
     */
    public static function linkedWallet(string $storedToken, string $remoteToken): array
    {
        [$engine, $client]        = \qbEngine();
        $ledger                   = self::ledger();
        $ledger->wallets['wal-1'] = [
            'uuid'         => 'wal-1',
            'company_uuid' => 'company-uuid',
            'public_id'    => 'wallet_wal-1',
            'name'         => 'Operating',
            'description'  => 'Fuel float',
            'currency'     => 'USD',
            'status'       => 'active',
        ];
        $client->accounts['acct-9'] = [
            'Id'          => 'acct-9',
            'SyncToken'   => $remoteToken,
            'Name'        => 'Operating',
            'Description' => 'Float',
            'AccountType' => 'Other Current Asset',
            'Active'      => true,
            'CurrencyRef' => ['value' => 'USD'],
        ];
        $ledger->links[]   = self::link('wallet', 'wal-1', 'acct-9', $storedToken);
        $ledger->pending[] = self::pending('wallet', 'wal-1');

        return [$engine, $client, $ledger];
    }

    /**
     * Apply a QuickBooks webhook for one record, as ApplyRemoteChange does.
     *
     * @param array<string, mixed> $settings
     */
    public static function webhook(
        SyncEngine $engine,
        SyncLedger $ledger,
        string $entity,
        string $id,
        array $settings = [],
    ): void {
        $settings = $settings === [] ? self::shippedSettings() : $settings;
        $entities = [['entity' => $entity, 'id' => $id, 'operation' => 'Update']];
        $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, $settings, time());
    }

    /**
     * How many times the fake QuickBooks was called with $call.
     */
    public static function count(FakeQuickBooks $client, string $call): int
    {
        return count(array_keys($client->calls, $call, true));
    }

    /**
     * Run the queue again, as a later sync does.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function runAgain(SyncEngine $engine, SyncLedger $ledger, array $settings, int $when): array
    {
        $ledger->connections['company-uuid']['last_batch_at'] = null;
        foreach (array_keys($ledger->pending) as $index) {
            $ledger->pending[$index]['status'] = 'pending';
        }

        return $engine->runScheduled($ledger, 'company-uuid', $settings, $when);
    }
}
