<?php

/*
 * Characterization tests for the SyncEngine method extraction (acceptRemoteChanges,
 * reconcileRows, process, paymentsTouchingInvoice, storePaymentLink) and the
 * QuickBooksClient helpers it relies on. They pin observable outcomes: ledger rows,
 * links, pending rows, QuickBooks calls, and batch counters.
 */

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Support\Facades\Http;

function secLedger(): SyncLedger
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

function secCustomer(string $uuid, array $overrides = []): array
{
    return array_merge(['uuid' => $uuid, 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'], $overrides);
}

function secInvoice(string $uuid, array $overrides = []): array
{
    return array_merge([
        'uuid'          => $uuid,
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

function secWallet(string $uuid, array $overrides = []): array
{
    return array_merge([
        'uuid'         => $uuid,
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_' . $uuid,
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'USD',
        'status'       => 'active',
        'balance'      => 0,
    ], $overrides);
}

function secLink(string $type, string $uuid, string $qboId, array $extra = []): array
{
    return array_merge([
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => $type,
        'local_uuid'   => $uuid,
        'qbo_entity'   => ['customer' => 'Customer', 'invoice' => 'Invoice', 'wallet' => 'Account', 'payment' => 'Payment'][$type],
        'qbo_id'       => $qboId,
        'sync_token'   => '0',
    ], $extra);
}

function secPending(string $type, string $uuid, array $extra = []): array
{
    return array_merge(['company_uuid' => 'company-uuid', 'local_type' => $type, 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0], $extra);
}

function secRemoteInvoice(string $id, array $overrides = []): array
{
    return array_merge([
        'Id'          => $id,
        'SyncToken'   => '1',
        'DocNumber'   => 'INV-1',
        'TotalAmt'    => 10,
        'TxnDate'     => '2026-09-01',
        'DueDate'     => '2026-09-15',
        'CustomerRef' => ['value' => 'qbo-customer'],
        'Line'        => [[
            'Amount'              => '10.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Delivery',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ], $overrides);
}

/**
 * @param array<int, array{0: string, 1: string}> $applied invoice id and amount pairs
 */
function secPayment(string $id, array $applied, string $date = '2026-09-10'): array
{
    $lines = [];
    $total = 0;
    foreach ($applied as [$invoiceId, $amount]) {
        $lines[] = ['Amount' => $amount, 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]];
        $total += (int) round(((float) $amount) * 100);
    }

    return ['Id' => $id, 'SyncToken' => '1', 'TotalAmt' => number_format($total / 100, 2, '.', ''), 'TxnDate' => $date, 'Line' => $lines];
}

function secQuickBooksPrimary(array $overrides = []): array
{
    return qbSettings(array_merge([
        'customer_conflict' => 'quickbooks',
        'invoice_conflict'  => 'quickbooks',
        'payment_conflict'  => 'quickbooks',
        'wallet_conflict'   => 'quickbooks',
    ], $overrides));
}

/**
 * @return array<int, array<string, mixed>>
 */
function secLinksOfType(SyncLedger $ledger, string $type): array
{
    return array_values(array_filter($ledger->links, static fn (array $link): bool => ($link['local_type'] ?? '') === $type));
}

/**
 * A FakeQuickBooks that records each batch query it is asked to run.
 */
function secRecordingClient(): FakeQuickBooks
{
    return new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $queries = [];

        public function batch(array $connection, array $items): array
        {
            foreach ($items as $item) {
                if (isset($item['query']) === true) {
                    $this->queries[] = (string) $item['query'];
                }
            }

            return parent::batch($connection, $items);
        }
    };
}

// ---------------------------------------------------------------------------
// acceptRemoteChanges
// ---------------------------------------------------------------------------

test('sec remote changes are ignored without a usable connection', function () {
    $cases = [
        'missing'      => static function (SyncLedger $ledger): void {
            unset($ledger->connections['company-uuid']);
        },
        'no realm'     => static function (SyncLedger $ledger): void {
            $ledger->connections['company-uuid']['realm_id'] = '';
        },
        'needs reauth' => static function (SyncLedger $ledger): void {
            $ledger->connections['company-uuid']['needs_reauth'] = true;
        },
    ];
    foreach ($cases as $name => $mutate) {
        [$engine, $client]           = qbEngine();
        $ledger                      = secLedger();
        $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada']);
        $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
        $client->customers['qbo-1']  = ['Id' => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada'];
        $mutate($ledger);
        $before = [$ledger->customers, $ledger->links, $ledger->pending, $ledger->attempts, $ledger->batches];

        $engine->acceptRemoteChanges($ledger, 'company-uuid', [
            ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ], secQuickBooksPrimary(), time());

        expect($client->calls)->toBe([], $name)
            ->and([$ledger->customers, $ledger->links, $ledger->pending, $ledger->attempts, $ledger->batches])->toBe($before, $name);
    }
});

test('sec a remote customer update copies name email phone notes and address and nothing else', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada', 'phone' => '111']);
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1', 'SyncToken' => '4', 'DisplayName' => '  Remote Ada  ',
        'PrimaryEmailAddr' => ['Address' => 'remote@example.test'],
        'PrimaryPhone'     => ['FreeFormNumber' => '555-0100'],
        'Notes'            => 'Back door',
        'BillAddr'         => ['Line1' => '1 Main St', 'City' => 'Springfield', 'CountrySubDivisionCode' => 'IL', 'PostalCode' => '62701', 'Country' => 'US'],
    ];
    $linksBefore = $ledger->links;

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($ledger->customers['cust-1'])->toBe([
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Remote Ada',
        'email'        => 'remote@example.test',
        'phone'        => '555-0100',
        'notes'        => 'Back door',
        'address'      => ['line1' => '1 Main St', 'line2' => null, 'city' => 'Springfield', 'state' => 'IL', 'postal_code' => '62701', 'country' => 'US'],
    ])
        ->and($client->calls)->toBe(['getCustomer:qbo-1'])
        // Only the stored SyncToken moves to the version just applied.
        ->and($ledger->links)->toBe([array_merge($linksBefore[0], ['sync_token' => '4'])])
        ->and($ledger->pending)->toBe([])
        ->and(array_column($ledger->attempts, 'outcome', 'local_uuid'))->toBe(['cust-1' => 'updated'])
        ->and(array_column($ledger->batches, 'direction'))->toBe(['inbound']);
});

test('sec an empty remote customer field keeps fleetbase without a baseline or a quickbooks change', function () {
    // No stored token (a first link), or the token stored (QuickBooks did not change the customer).
    $kept = secCustomer('cust-1', ['name' => 'Local Ada', 'phone' => '111', 'notes' => 'Keep']);
    foreach (['', '1'] as $stored) {
        [$engine, $client]           = qbEngine();
        $ledger                      = secLedger();
        $ledger->customers['cust-1'] = $kept;
        $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1', ['sync_token' => $stored]);
        $client->customers['qbo-1']  = [
            'Id'               => 'qbo-1',
            'SyncToken'        => '1',
            'DisplayName'      => '   ',
            'Notes'            => '',
            'PrimaryEmailAddr' => ['Address' => ''],
        ];

        $engine->acceptRemoteChanges($ledger, 'company-uuid', [
            ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ], secQuickBooksPrimary(), 1_000);

        expect($ledger->customers['cust-1'])->toBe($kept);
    }
});

test('sec an empty remote customer field clears fleetbase when quickbooks changed it since a baseline', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada', 'phone' => '111', 'notes' => 'Keep']);
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = [
        'Id'               => 'qbo-1',
        'SyncToken'        => '1',
        'DisplayName'      => '   ',
        'Notes'            => '',
        'PrimaryEmailAddr' => ['Address' => ''],
    ];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    // A blank QuickBooks name is never copied; the emptied email, phone and notes are.
    $cleared = ['name' => 'Local Ada', 'email' => null, 'phone' => null, 'notes' => null];
    expect($ledger->customers['cust-1'])->toBe(secCustomer('cust-1', $cleared));
});

test('sec deletes unknown entities empty ids unlinked ids and kinds quickbooks does not supply are not read', function () {
    $cases = [
        'delete operation'      => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Delete']], secQuickBooksPrimary()],
        'lower case delete'     => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'delete']], secQuickBooksPrimary()],
        'unknown entity'        => [[['entity' => 'Vendor', 'id' => 'qbo-1', 'operation' => 'Update'], ['entity' => 'Item', 'id' => 'qbo-1', 'operation' => 'Update']], secQuickBooksPrimary()],
        'empty id'              => [[['entity' => 'Customer', 'id' => '', 'operation' => 'Update']], secQuickBooksPrimary()],
        'unlinked id'           => [[['entity' => 'Customer', 'id' => 'qbo-other', 'operation' => 'Update']], secQuickBooksPrimary()],
        'not an array'          => [['Customer:qbo-1'], secQuickBooksPrimary()],
        'customer disabled'     => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update']], secQuickBooksPrimary(['customer_enabled' => false])],
        'customer disabled str' => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update']], secQuickBooksPrimary(['customer_enabled' => 'false'])],
        'outbound direction'    => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update']], secQuickBooksPrimary(['customer_direction' => 'outbound'])],
        'off direction'         => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update']], secQuickBooksPrimary(['customer_direction' => 'off'])],
        'fleetbase primary'     => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update']], qbSettings()],
        'other kind is primary' => [[['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update']], qbSettings(['invoice_conflict' => 'quickbooks'])],
    ];
    foreach ($cases as $name => [$entities, $settings]) {
        [$engine, $client]           = qbEngine();
        $ledger                      = secLedger();
        $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada']);
        $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
        $client->customers['qbo-1']  = ['Id' => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada'];

        $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, $settings, 1_000);

        expect($client->calls)->toBe([], $name)
            ->and($ledger->customers['cust-1']['name'])->toBe('Local Ada', $name);
    }
});

test('sec inbound direction copies a remote customer even when fleetbase wins conflicts', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = ['Id' => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada'];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
    ], qbSettings(['customer_direction' => 'inbound']), 1_000);

    expect($client->calls)->toBe(['getCustomer:qbo-1'])
        ->and($ledger->customers['cust-1']['name'])->toBe('Remote Ada');
});

test('sec the same remote id twice in one delivery is read and applied once', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
    $client->customers['qbo-1']  = ['Id' => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada'];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Create'],
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Delete'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getCustomer:qbo-1'])
        ->and($ledger->customers['cust-1']['name'])->toBe('Remote Ada');
});

test('sec a linked remote whose fleetbase row is missing is read but nothing is created', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = secLedger();
    $ledger->links[]                   = secLink('customer', 'cust-gone', 'qbo-1');
    $ledger->links[]                   = secLink('invoice', 'inv-gone', 'qb-1');
    $ledger->links[]                   = secLink('wallet', 'wal-gone', 'acct-1');
    $client->customers['qbo-1']        = ['Id' => 'qbo-1', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada'];
    $client->invoices['qb-1']          = secRemoteInvoice('qb-1');
    $client->accounts['acct-1']        = ['Id' => 'acct-1', 'SyncToken' => '1', 'Name' => 'Remote Float', 'Active' => true];
    $linksBefore                       = $ledger->links;

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ['entity' => 'Invoice', 'id' => 'qb-1', 'operation' => 'Update'],
        ['entity' => 'Account', 'id' => 'acct-1', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getCustomer:qbo-1', 'getInvoice:qb-1', 'getAccount:acct-1'])
        ->and($ledger->customers)->toBe([])
        ->and($ledger->invoices)->toBe([])
        ->and($ledger->wallets)->toBe([])
        ->and($ledger->links)->toBe($linksBefore)
        ->and($ledger->pending)->toBe([]);
});

test('sec a linked remote that quickbooks no longer returns changes nothing', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->customers['cust-2'] = secCustomer('cust-2', ['name' => 'Local Bea']);
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-1');
    $ledger->links[]             = secLink('customer', 'cust-2', 'qbo-2');
    $client->customers['qbo-2']  = ['Id' => 'qbo-2', 'SyncToken' => '1', 'DisplayName' => 'Remote Bea'];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
        ['entity' => 'Customer', 'id' => 'qbo-2', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['batch'])
        ->and($ledger->customers['cust-1']['name'])->toBe('Local Ada')
        ->and($ledger->customers['cust-2']['name'])->toBe('Remote Bea');
});

test('sec a remote invoice update copies dates note lines and totals and marks it for replacement', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $client->invoices['qb-1']    = secRemoteInvoice('qb-1', [
        'DocNumber'   => 'QB-77',
        'TxnDate'     => '2026-10-01',
        'DueDate'     => '2026-10-31',
        'PrivateNote' => 'Gate code 9',
        'TotalAmt'    => 25,
        'Line'        => [
            ['Amount' => '15.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery', 'SalesItemLineDetail' => ['Qty' => 3, 'UnitPrice' => '5.00']],
            ['Amount' => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Wait time', 'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00']],
            ['Amount' => '25.00', 'DetailType' => 'SubTotalLineDetail'],
        ],
    ]);

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Invoice', 'id' => 'qb-1', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    $invoice = $ledger->invoices['inv-1'];
    expect($client->calls)->toBe(['getInvoice:qb-1'])
        ->and($invoice['number'])->toBe('INV-1')
        ->and($invoice['date'])->toBe('2026-10-01')
        ->and($invoice['due_date'])->toBe('2026-10-31')
        ->and($invoice['notes'])->toBe('Gate code 9')
        ->and($invoice['items'])->toBe([
            InvoiceMapper::item('Delivery', 3, 500, 1500),
            InvoiceMapper::item('Wait time', 1, 1000, 1000),
        ])
        ->and($invoice['items_from_quickbooks'])->toBeTrue()
        ->and($invoice['total'])->toBe(2500)
        ->and($invoice['tax'])->toBe(0)
        ->and($invoice['replace_from_quickbooks'])->toBeTrue()
        ->and($invoice['status'])->toBe('sent')
        ->and(array_key_exists('amount_paid', $invoice))->toBeFalse();
});

test('sec a remote invoice number and customer are both copied under a quickbooks reference', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->customers['cust-2'] = secCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-customer');
    $ledger->links[]             = secLink('customer', 'cust-2', 'qbo-bea');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->invoices['inv-2']   = secInvoice('inv-2', ['number' => 'INV-2']);
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]             = secLink('invoice', 'inv-2', 'qb-2');
    $client->invoices['qb-1']    = secRemoteInvoice('qb-1', ['DocNumber' => 'QB-1', 'CustomerRef' => ['value' => 'qbo-bea']]);
    $client->invoices['qb-2']    = secRemoteInvoice('qb-2', ['DocNumber' => 'INV-2', 'CustomerRef' => ['value' => 'qbo-bea']]);

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Invoice', 'id' => 'qb-1', 'operation' => 'Update'],
        ['entity' => 'Invoice', 'id' => 'qb-2', 'operation' => 'Update'],
    ], secQuickBooksPrimary(['invoice_reference' => 'quickbooks']), 1_000);

    expect($client->calls)->toBe(['batch'])
        ->and($ledger->invoices['inv-1']['number'])->toBe('QB-1')
        ->and($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2')
        ->and($ledger->invoices['inv-1']['replace_from_quickbooks'])->toBeTrue()
        ->and($ledger->invoices['inv-2']['number'])->toBe('INV-2')
        ->and($ledger->invoices['inv-2']['customer_uuid'])->toBe('cust-2')
        ->and($ledger->invoices['inv-2']['replace_from_quickbooks'])->toBeTrue();
});

test('sec a remote invoice update recomputes paid and partial status from the stored amount paid', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-a']   = secInvoice('inv-a', ['status' => 'paid', 'amount_paid' => 1000]);
    $ledger->invoices['inv-b']   = secInvoice('inv-b', ['status' => 'sent', 'amount_paid' => 2500]);
    $ledger->invoices['inv-c']   = secInvoice('inv-c', ['status' => 'partial', 'amount_paid' => 0]);
    $ledger->invoices['inv-d']   = secInvoice('inv-d', ['status' => 'void', 'amount_paid' => 2500]);
    $remote                      = ['TotalAmt' => 25, 'Line' => [['Amount' => '25.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Delivery', 'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '25.00']]]];
    $entities                    = [];
    foreach (['a', 'b', 'c', 'd'] as $key) {
        $ledger->links[]                    = secLink('invoice', 'inv-' . $key, 'qb-' . $key);
        $client->invoices['qb-' . $key]     = secRemoteInvoice('qb-' . $key, $remote);
        $entities[]                         = ['entity' => 'Invoice', 'id' => 'qb-' . $key, 'operation' => 'Update'];
    }

    $engine->acceptRemoteChanges($ledger, 'company-uuid', $entities, secQuickBooksPrimary(), 1_000);

    expect($ledger->invoices['inv-a']['status'])->toBe('partial')
        ->and($ledger->invoices['inv-a']['total'])->toBe(2500)
        ->and($ledger->invoices['inv-b']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-c']['status'])->toBe('sent')
        ->and($ledger->invoices['inv-d']['status'])->toBe('void')
        ->and($ledger->invoices['inv-d']['total'])->toBe(2500);
});

test('sec a remote payment on a legacy invoice keyed link copies amount date and status without moving the link', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]             = secLink('payment', 'inv-1', 'pay-1');
    $client->payments['pay-1']   = secPayment('pay-1', [['qb-1', '4.00']], '2026-09-10');
    $linksBefore                 = $ledger->links;

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-1', 'operation' => 'Create'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getPayment:pay-1'])
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(400)
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('')
        ->and($ledger->invoices['inv-1']['payment_from_quickbooks'])->toBeTrue()
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        // The link stays keyed by the invoice; only its SyncToken moves to the payment just applied.
        ->and($ledger->links)->toBe([
            $linksBefore[0],
            $linksBefore[1],
            array_merge($linksBefore[2], ['invoice_tokens' => '{"inv-1":"1"}', 'sync_token' => '1']),
        ])
        ->and($ledger->pending)->toBe([]);
});

test('sec a remote payment keyed by its own id finds its invoice through the linked line', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->links[]             = secLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->invoices['inv-2']   = secInvoice('inv-2', ['number' => 'INV-2']);
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]             = secLink('invoice', 'inv-2', 'qb-2');
    $ledger->links[]             = secLink('payment', 'pay-7', 'pay-7', ['invoice_uuid' => 'inv-2']);
    $client->payments['pay-7']   = secPayment('pay-7', [['qb-2', '10.00']], '2026-09-20');

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-7', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getPayment:pay-7'])
        ->and($ledger->invoices['inv-2']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-2']['paid_at'])->toBe('2026-09-20')
        ->and($ledger->invoices['inv-2']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1'])->toBe(secInvoice('inv-1'));
});

test('sec a remote payment that touches no linked invoice changes nothing', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]             = secLink('payment', 'pay-7', 'pay-7');
    $client->payments['pay-7']   = secPayment('pay-7', [['qb-elsewhere', '10.00']]);
    $before                      = $ledger->invoices;

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-7', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getPayment:pay-7'])
        ->and($ledger->invoices)->toBe($before);
});

test('sec a remote payment sums other payments linked to the invoice and skips unrelated links', function () {
    $client                      = secRecordingClient();
    [$engine, $client]           = qbEngine($client);
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->invoices['inv-2']   = secInvoice('inv-2', ['number' => 'INV-2']);
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]             = secLink('invoice', 'inv-2', 'qb-2');
    $ledger->links[]             = secLink('payment', 'pay-a', 'pay-a');
    $ledger->links[]             = secLink('payment', 'pay-c', 'pay-c');
    $ledger->links[]             = secLink('payment', 'pay-d', 'pay-d');
    $ledger->links[]             = secLink('payment', 'inv-2', 'pay-z');
    $client->payments['pay-a']   = secPayment('pay-a', [['qb-1', '3.00']], '2026-09-05');
    $client->payments['pay-c']   = secPayment('pay-c', [['qb-1', '2.00']], '2026-09-12');
    $client->payments['pay-d']   = secPayment('pay-d', [['qb-2', '9.00']], '2026-09-30');
    $client->payments['pay-z']   = secPayment('pay-z', [['qb-1', '50.00']], '2026-09-30');

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-a', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getPayment:pay-a', 'batch'])
        ->and($client->queries)->toHaveCount(1)
        ->and($client->queries[0])->toContain("'pay-c'")
        ->and($client->queries[0])->toContain("'pay-d'")
        ->and($client->queries[0])->not->toContain("'pay-z'")
        ->and($client->queries[0])->not->toContain("'pay-a'")
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(500)
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('')
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and(array_key_exists('amount_paid', $ledger->invoices['inv-2']))->toBeFalse();
});

test('sec two payments in one delivery are read together and both count toward the invoice', function () {
    $client                      = secRecordingClient();
    [$engine, $client]           = qbEngine($client);
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1');
    $ledger->invoices['inv-1']   = secInvoice('inv-1');
    $ledger->links[]             = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]             = secLink('payment', 'pay-a', 'pay-a');
    $ledger->links[]             = secLink('payment', 'pay-b', 'pay-b');
    $client->payments['pay-a']   = secPayment('pay-a', [['qb-1', '3.00']], '2026-09-05');
    $client->payments['pay-b']   = secPayment('pay-b', [['qb-1', '7.00'], ['qb-9', '1.00']], '2026-09-12');

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-a', 'operation' => 'Update'],
        ['entity' => 'Payment', 'id' => 'pay-b', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['batch'])
        ->and($client->queries)->toHaveCount(1)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('2026-09-12')
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid');
});

test('sec an account update without a baseline copies name currency and status, not an empty description', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = secLedger();
    $ledger->wallets['wal-1']   = secWallet('wal-1');
    $ledger->wallets['wal-2']   = secWallet('wal-2', ['balance' => 500]);
    $ledger->links[]            = secLink('wallet', 'wal-1', 'acct-1', ['sync_token' => '']);
    $ledger->links[]            = secLink('wallet', 'wal-2', 'acct-2', ['sync_token' => '']);
    $client->accounts['acct-1'] = ['Id' => 'acct-1', 'SyncToken' => '1', 'Name' => ' Ops Float ', 'Description' => '  ', 'CurrencyRef' => ['value' => 'cad'], 'Active' => false, 'AcctNum' => '4100'];
    $client->accounts['acct-2'] = ['Id' => 'acct-2', 'SyncToken' => '1', 'Name' => 'Reserve', 'Description' => 'Remote text', 'Active' => false, 'AcctNum' => '4200'];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Account', 'id' => 'acct-1', 'operation' => 'Update'],
        ['entity' => 'Account', 'id' => 'acct-2', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['batch'])
        ->and($ledger->wallets['wal-1'])->toBe(secWallet('wal-1', ['name' => 'Ops Float', 'currency' => 'CAD', 'status' => 'closed']))
        ->and($ledger->wallets['wal-2'])->toBe(secWallet('wal-2', ['balance' => 500, 'name' => 'Reserve', 'description' => 'Remote text']));
});

test('sec a quickbooks wallet reference also copies the account number into meta', function () {
    [$engine, $client]          = qbEngine();
    $ledger                     = secLedger();
    $ledger->wallets['wal-1']   = secWallet('wal-1', ['meta' => 'not-an-array']);
    $ledger->links[]            = secLink('wallet', 'wal-1', 'acct-1');
    $client->accounts['acct-1'] = ['Id' => 'acct-1', 'SyncToken' => '1', 'Name' => 'Ops', 'Active' => true, 'AcctNum' => ' 4100 '];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Account', 'id' => 'acct-1', 'operation' => 'Update'],
    ], secQuickBooksPrimary(['wallet_reference' => 'quickbooks']), 1_000);

    expect($client->calls)->toBe(['getAccount:acct-1'])
        ->and($ledger->wallets['wal-1']['acct_num'])->toBe('4100')
        ->and($ledger->wallets['wal-1']['meta'])->toBe(['quickbooks_acct_num' => '4100'])
        ->and($ledger->wallets['wal-1']['status'])->toBe('active')
        ->and($ledger->wallets['wal-1']['name'])->toBe('Ops');
});

test('sec a mixed delivery reads each entity type once in first seen order and applies every kind', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = secLedger();
    $ledger->customers['cust-1']       = secCustomer('cust-1', ['name' => 'Local Ada']);
    $ledger->links[]                   = secLink('customer', 'cust-1', 'qbo-customer');
    $ledger->invoices['inv-1']         = secInvoice('inv-1');
    $ledger->links[]                   = secLink('invoice', 'inv-1', 'qb-1');
    $ledger->links[]                   = secLink('payment', 'pay-1', 'pay-1');
    $ledger->wallets['wal-1']          = secWallet('wal-1');
    $ledger->links[]                   = secLink('wallet', 'wal-1', 'acct-1');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '1', 'DisplayName' => 'Remote Ada'];
    $client->invoices['qb-1']          = secRemoteInvoice('qb-1', ['DueDate' => '2026-09-30']);
    $client->payments['pay-1']         = secPayment('pay-1', [['qb-1', '10.00']], '2026-09-11');
    $client->accounts['acct-1']        = ['Id' => 'acct-1', 'SyncToken' => '1', 'Name' => 'Remote Float'];

    $engine->acceptRemoteChanges($ledger, 'company-uuid', [
        ['entity' => 'Payment', 'id' => 'pay-1', 'operation' => 'Create'],
        ['entity' => 'Customer', 'id' => 'qbo-customer', 'operation' => 'Update'],
        ['entity' => 'Account', 'id' => 'acct-1', 'operation' => 'Update'],
        ['entity' => 'Invoice', 'id' => 'qb-1', 'operation' => 'Update'],
        ['entity' => 'Customer', 'id' => 'qbo-missing', 'operation' => 'Update'],
    ], secQuickBooksPrimary(), 1_000);

    expect($client->calls)->toBe(['getPayment:pay-1', 'getCustomer:qbo-customer', 'getAccount:acct-1', 'getInvoice:qb-1'])
        ->and($ledger->customers['cust-1']['name'])->toBe('Remote Ada')
        ->and($ledger->wallets['wal-1']['name'])->toBe('Remote Float')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-09-30')
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(1000)
        ->and($ledger->invoices['inv-1']['status'])->toBe('paid')
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('2026-09-11');
});

// ---------------------------------------------------------------------------
// reconcile / reconcileRows
// ---------------------------------------------------------------------------

function secReconcileLedger(FakeQuickBooks $client): SyncLedger
{
    $ledger                            = secLedger();
    $ledger->customers['cust-1']       = secCustomer('cust-1');
    $ledger->links[]                   = secLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];

    return $ledger;
}

test('sec reconcile works only due pending invoices when any exist and does not scan the rest', function () {
    [$engine, $client]         = qbEngine();
    $ledger                    = secReconcileLedger($client);
    $ledger->invoices['inv-1'] = secInvoice('inv-1', ['number' => 'INV-1']);
    $ledger->invoices['inv-2'] = secInvoice('inv-2', ['number' => 'INV-2']);
    $ledger->invoices['inv-3'] = secInvoice('inv-3', ['number' => 'INV-3']);
    $ledger->pending[]         = secPending('customer', 'cust-1');
    $ledger->pending[]         = secPending('invoice', 'inv-3', ['next_attempt_at' => 5_000]);
    $ledger->pending[]         = secPending('invoice', 'inv-2', ['next_attempt_at' => 900]);
    $ledger->pending[]         = secPending('invoice', 'inv-1', ['status' => 'done']);
    $ledger->pending[]         = secPending('invoice', 'inv-1', ['company_uuid' => 'other-company']);

    $batch = $engine->reconcile($ledger, 'company-uuid', qbSettings(), 1_000);

    expect($batch['created'])->toBe(1)
        ->and($batch['trigger'])->toBe('manual')
        ->and($batch['failed'])->toBe(0)
        ->and(array_count_values($client->calls)['createInvoice'] ?? 0)->toBe(1)
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-2'))->not->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-3'))->toBeNull()
        ->and(array_filter($ledger->pending, static fn (array $row): bool => ($row['reason'] ?? '') === 'reconcile'))->toBe([]);
});

test('sec reconcile pending rows honor batch size before the catalog', function () {
    [$engine, $client]         = qbEngine();
    $ledger                    = secReconcileLedger($client);
    foreach (['inv-1', 'inv-2', 'inv-3'] as $index => $uuid) {
        $ledger->invoices[$uuid] = secInvoice($uuid, ['number' => 'INV-' . ($index + 1)]);
        $ledger->pending[]       = secPending('invoice', $uuid);
    }

    $batch = $engine->reconcile($ledger, 'company-uuid', qbSettings(['batch_size' => 2]), 1_000);

    expect($batch['created'])->toBe(2)
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1'))->not->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-2'))->not->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-3'))->toBeNull()
        ->and(count($ledger->pending))->toBe(3);
});

test('sec reconcile without pending scans non-draft invoices then linked drafts and queues the overflow', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = secReconcileLedger($client);
    $ledger->invoices['inv-a']         = secInvoice('inv-a', ['number' => 'INV-A']);
    $ledger->invoices['inv-draft']     = secInvoice('inv-draft', ['number' => 'INV-D', 'status' => 'draft']);
    $ledger->invoices['inv-ld']        = secInvoice('inv-ld', ['number' => 'INV-LD', 'status' => 'draft']);
    $ledger->invoices['inv-b']         = secInvoice('inv-b', ['number' => 'INV-B']);
    $ledger->invoices['inv-other']     = secInvoice('inv-other', ['number' => 'INV-O', 'company_uuid' => 'other-company']);
    $ledger->links[]                   = secLink('invoice', 'inv-ld', 'qb-ld');
    $ledger->links[]                   = secLink('invoice', 'inv-gone', 'qb-gone');
    $client->invoices['qb-ld']         = secRemoteInvoice('qb-ld', ['DocNumber' => 'INV-LD']);

    $batch = $engine->reconcile($ledger, 'company-uuid', qbSettings(['batch_size' => 1]), 1_000);

    $queued = array_map(
        static fn (array $row): array => [$row['local_type'], $row['local_uuid'], $row['reason'] ?? null, $row['status']],
        $ledger->pending
    );
    expect($batch['created'])->toBe(1)
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-a'))->not->toBeNull()
        ->and($queued)->toBe([
            ['invoice', 'inv-b', 'reconcile', 'pending'],
            ['invoice', 'inv-ld', 'reconcile', 'pending'],
        ]);
});

test('sec reconcile within batch size queues nothing and never sends unlinked drafts or other companies', function () {
    [$engine, $client]             = qbEngine();
    $ledger                        = secReconcileLedger($client);
    $ledger->invoices['inv-a']     = secInvoice('inv-a', ['number' => 'INV-A']);
    $ledger->invoices['inv-draft'] = secInvoice('inv-draft', ['number' => 'INV-D', 'status' => 'draft']);
    $ledger->invoices['inv-other'] = secInvoice('inv-other', ['number' => 'INV-O', 'company_uuid' => 'other-company']);

    $batch = $engine->reconcile($ledger, 'company-uuid', qbSettings(), 1_000);

    expect($batch['created'])->toBe(1)
        ->and(array_count_values($client->calls)['createInvoice'] ?? 0)->toBe(1)
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-a'))->not->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-draft'))->toBeNull()
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-other'))->toBeNull()
        ->and($ledger->pending)->toBe([]);
});

// ---------------------------------------------------------------------------
// process
// ---------------------------------------------------------------------------

test('sec every outbound entry point records one finished batch with the same shape', function () {
    $run = static function (string $mode): array {
        [$engine, $client]                                           = qbEngine();
        $ledger                                                      = secLedger();
        $ledger->customers['cust-1']                                 = secCustomer('cust-1');
        $ledger->pending[]                                           = secPending('customer', 'cust-1');
        $ledger->remoteInvoices[]                                    = ['Id' => 'qb-unlinked', 'DocNumber' => 'X'];
        $ledger->connections['company-uuid']['last_rate_limit_wait'] = 120;
        $ledger->connections['company-uuid']['rate_limited_until']   = 500;
        $batch                                                       = match ($mode) {
            'scheduled' => $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000),
            'now'       => $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000, true),
            'catalog'   => $engine->syncEntities($ledger, 'company-uuid', [secPending('customer', 'cust-1')], qbSettings(), 1_000),
        };

        return [$batch, $ledger, $client];
    };

    foreach (['scheduled', 'now', 'catalog'] as $mode) {
        [$batch, $ledger, $client] = $run($mode);
        expect($batch)->toBe([
            'company_uuid' => 'company-uuid',
            'trigger'      => $mode,
            'direction'    => 'outbound',
            'status'       => 'finished',
            'created'      => 1,
            'updated'      => 0,
            'aligned'      => 0,
            'voided'       => 0,
            'unmatched'    => 0,
            'failed'       => 0,
            'skipped'      => 0,
            'started_at'   => 1_000,
            'finished_at'  => 1_000,
        ], $mode)
            ->and($ledger->batches)->toBe([$batch], $mode)
            ->and($ledger->connections['company-uuid']['last_rate_limit_wait'])->toBeNull()
            ->and($ledger->connections['company-uuid']['last_batch_at'] ?? null)->toBe($mode === 'catalog' ? null : 1_000, $mode)
            ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('1', $mode);
    }
});

test('sec a block of one type is buffered and a lone row of another type is sent directly', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secLedger();
    $ledger->customers['cust-1'] = secCustomer('cust-1', ['name' => 'Ada', 'email' => 'ada@example.test']);
    $ledger->customers['cust-2'] = secCustomer('cust-2', ['name' => 'Bea', 'email' => 'bea@example.test']);
    $ledger->wallets['wal-1']    = secWallet('wal-1');

    $batch = $engine->syncEntities($ledger, 'company-uuid', [
        secPending('customer', 'cust-1'),
        secPending('wallet', 'wal-1'),
        secPending('customer', 'cust-2'),
    ], qbSettings(), 1_000);

    $counts = array_count_values($client->calls);
    expect($batch['created'])->toBe(3)
        ->and($batch['failed'])->toBe(0)
        ->and($counts['createCustomer'] ?? 0)->toBe(2)
        ->and($counts['createAccount'] ?? 0)->toBe(1)
        ->and(array_search('createAccount', $client->calls, true))->toBeGreaterThan(array_search('createCustomer', $client->calls, true))
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('1')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-2')['qbo_id'])->toBe('2')
        ->and($ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-1')['qbo_id'])->toBe('acct-1');
});

test('sec a row is not sent once the connection is rate limited', function () {
    [$engine, $client]                                           = qbEngine();
    $ledger                                                      = secLedger();
    $ledger->customers['cust-1']                                 = secCustomer('cust-1');
    $ledger->connections['company-uuid']['rate_limited_until']   = 2_000;
    $ledger->connections['company-uuid']['last_rate_limit_wait'] = 60;

    $batch = $engine->syncEntities($ledger, 'company-uuid', [secPending('customer', 'cust-1')], qbSettings(), 1_000);

    expect($client->calls)->toBe([])
        ->and($batch['status'])->toBe('finished')
        ->and($batch['created'])->toBe(0)
        ->and($ledger->batches)->toHaveCount(1)
        ->and($ledger->connections['company-uuid']['last_rate_limit_wait'])->toBe(60);
});

// ---------------------------------------------------------------------------
// storePaymentLink
// ---------------------------------------------------------------------------

function secPaidInvoiceLedger(FakeQuickBooks $client, array $invoice = []): SyncLedger
{
    $ledger                            = secLedger();
    $ledger->customers['cust-1']       = secCustomer('cust-1');
    $ledger->links[]                   = secLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = secInvoice('inv-1', array_merge(['status' => 'paid', 'amount_paid' => 1000, 'paid_at' => '2026-09-10'], $invoice));
    $ledger->links[]                   = secLink('invoice', 'inv-1', 'qb-1');
    $client->invoices['qb-1']          = secRemoteInvoice('qb-1', ['Balance' => 10]);
    $ledger->pending[]                 = secPending('invoice', 'inv-1');

    return $ledger;
}

test('sec a new payment is stored under its quickbooks id with the invoice kept on the link', function () {
    [$engine, $client] = qbEngine();
    $ledger            = secPaidInvoiceLedger($client);

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000);

    expect(array_count_values($client->calls)['createPayment'] ?? 0)->toBe(1)
        ->and(secLinksOfType($ledger, 'payment'))->toBe([[
            'company_uuid' => 'company-uuid',
            'realm_id'     => 'realm-1',
            'local_type'   => 'payment',
            'local_uuid'   => 'pay-1',
            'qbo_entity'   => 'Payment',
            'qbo_id'       => 'pay-1',
            'sync_token'   => '0',
            'invoice_uuid' => 'inv-1',
            // The payment token inv-1 synced, see PaymentTokens.
            'invoice_tokens' => '{"inv-1":"0"}',
        ]])
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-1'))->toBeNull();
});

test('sec a new payment that does not list the invoice stays keyed by the invoice', function () {
    $client = new class extends FakeQuickBooks {
        public function createPayment(array $connection, array $payload): array
        {
            $created = parent::createPayment($connection, $payload);
            unset($created['Line']);

            return $created;
        }
    };
    [$engine, $client] = qbEngine($client);
    $ledger            = secPaidInvoiceLedger($client);

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000);

    expect(secLinksOfType($ledger, 'payment'))->toBe([[
        'company_uuid'   => 'company-uuid',
        'realm_id'       => 'realm-1',
        'local_type'     => 'payment',
        'local_uuid'     => 'inv-1',
        'qbo_entity'     => 'Payment',
        'qbo_id'         => 'pay-1',
        'sync_token'     => '0',
        'invoice_tokens' => '{"inv-1":"0"}',
    ]]);
});

test('sec a legacy invoice keyed payment link is rekeyed in place to the payment id', function () {
    [$engine, $client]         = qbEngine();
    $ledger                    = secPaidInvoiceLedger($client);
    $ledger->links[]           = secLink('payment', 'inv-1', 'pay-9', ['sync_token' => '3', 'extra' => 'kept']);
    $client->payments['pay-9'] = secPayment('pay-9', [['qb-1', '10.00']], '2026-09-10');
    $index                     = array_key_last($ledger->links);

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000);

    expect($client->calls)->not->toContain('createPayment')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and(secLinksOfType($ledger, 'payment'))->toHaveCount(1)
        ->and($ledger->links[$index])->toBe([
            'company_uuid'   => 'company-uuid',
            'realm_id'       => 'realm-1',
            'local_type'     => 'payment',
            'local_uuid'     => 'pay-9',
            'qbo_entity'     => 'Payment',
            'qbo_id'         => 'pay-9',
            'sync_token'     => '1',
            'extra'          => 'kept',
            'invoice_uuid'   => 'inv-1',
            'invoice_tokens' => '{"inv-1":"1"}',
        ])
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-1'))->toBeNull()
        ->and($ledger->linkForRemote('realm-1', 'Payment', 'pay-9')['local_uuid'])->toBe('pay-9');
});

test('sec a legacy link is forgotten when the payment id link already exists and its prior invoice is kept', function () {
    foreach (['inv-old' => 'inv-old', '' => 'inv-1', 'pay-9' => 'inv-1'] as $prior => $expected) {
        [$engine, $client]         = qbEngine();
        $ledger                    = secPaidInvoiceLedger($client);
        $ledger->links[]           = secLink('payment', 'inv-1', 'pay-9');
        $ledger->links[]           = secLink('payment', 'pay-9', 'pay-9', ['invoice_uuid' => $prior]);
        $client->payments['pay-9'] = secPayment('pay-9', [['qb-1', '10.00']], '2026-09-10');

        $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000);

        $links = secLinksOfType($ledger, 'payment');
        expect($links)->toHaveCount(1, (string) $prior)
            ->and($links[0]['local_uuid'])->toBe('pay-9', (string) $prior)
            ->and($links[0]['sync_token'])->toBe('1', (string) $prior)
            ->and($links[0]['invoice_uuid'])->toBe($expected, (string) $prior)
            ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'inv-1'))->toBeNull()
            ->and($client->calls)->not->toContain('createPayment');
    }
});

test('sec payments created in a block are stored under their payment ids', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = secPaidInvoiceLedger($client);
    $ledger->invoices['inv-2']   = secInvoice('inv-2', ['number' => 'INV-2', 'status' => 'paid', 'amount_paid' => 1000, 'paid_at' => '2026-09-10']);
    $ledger->links[]             = secLink('invoice', 'inv-2', 'qb-2');
    $client->invoices['qb-2']    = secRemoteInvoice('qb-2', ['DocNumber' => 'INV-2', 'Balance' => 10]);
    $ledger->pending[]           = secPending('invoice', 'inv-2');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_000);

    $links = [];
    foreach (secLinksOfType($ledger, 'payment') as $link) {
        $links[$link['local_uuid']] = [$link['qbo_id'], $link['invoice_uuid'] ?? null];
    }
    ksort($links);
    expect($batch['failed'])->toBe(0)
        ->and(array_count_values($client->calls)['createPayment'] ?? 0)->toBe(2)
        ->and($client->calls)->toContain('batch')
        ->and($links)->toBe([
            'pay-1' => ['pay-1', 'inv-1'],
            'pay-2' => ['pay-2', 'inv-2'],
        ]);
});

// ---------------------------------------------------------------------------
// QuickBooksClient helpers split by the refactor
// ---------------------------------------------------------------------------

function secDocNumberClient(string $latest): FakeQuickBooks
{
    $client = new class extends FakeQuickBooks {
        public string $latest = '';

        protected function latestInvoiceDocNumber(array $connection): string
        {
            $this->calls[] = 'latestInvoiceDocNumber';

            return $this->latest;
        }
    };
    $client->latest = $latest;

    return $client;
}

// Each case is its own QuickBooks company: numbers handed out in one realm are a floor for the next run there.
test('sec invoice numbers for a block skip taken numbers and stop at the check budget', function () {
    $client = secDocNumberClient('INV-009');
    foreach (['INV-010', 'INV-012'] as $doc) {
        $client->invoices[$doc] = ['Id' => $doc, 'DocNumber' => $doc];
    }
    expect($client->nextInvoiceDocNumbers(['realm_id' => 'realm-1'], 3))->toBe(['INV-011', 'INV-013', 'INV-014'])
        ->and($client->calls)->toBe(['latestInvoiceDocNumber', 'batch']);

    $client = secDocNumberClient('INV-009');
    for ($i = 10; $i <= 15; $i++) {
        $doc                    = sprintf('INV-%03d', $i);
        $client->invoices[$doc] = ['Id' => $doc, 'DocNumber' => $doc];
    }
    expect($client->nextInvoiceDocNumbers(['realm_id' => 'realm-2'], 2))->toBe(['INV-016'])
        ->and($client->calls)->toBe(['latestInvoiceDocNumber', 'batch']);

    $client = secDocNumberClient('');
    expect($client->nextInvoiceDocNumbers(['realm_id' => 'realm-3'], 2))->toBe(['1', '2'])
        ->and($client->nextInvoiceDocNumbers(['realm_id' => 'realm-3'], 0))->toBe([]);

    $client                      = secDocNumberClient('INV-009');
    $client->invoices['INV-010'] = ['Id' => 'INV-010', 'DocNumber' => 'INV-010'];
    expect($client->nextInvoiceDocNumbers(['realm_id' => 'realm-4'], 1))->toBe(['INV-011'])
        ->and($client->calls)->toBe(['latestInvoiceDocNumber', 'findInvoiceByDocNumber', 'findInvoiceByDocNumber']);
});

test('sec a block create with only one confirmed number creates one invoice and fails the other', function () {
    $client                   = secDocNumberClient('INV-009');
    $client->customTxnNumbers = true;
    for ($i = 10; $i <= 15; $i++) {
        $doc                    = sprintf('INV-%03d', $i);
        $client->invoices[$doc] = ['Id' => $doc, 'SyncToken' => '0', 'DocNumber' => $doc, 'TotalAmt' => 1];
    }
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = secLedger();
    $ledger->customers['cust-1']       = secCustomer('cust-1');
    $ledger->links[]                   = secLink('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = secInvoice('inv-1', ['number' => 'LOCAL-1']);
    $ledger->invoices['inv-2']         = secInvoice('inv-2', ['number' => 'LOCAL-2']);
    $ledger->pending[]                 = secPending('invoice', 'inv-1');
    $ledger->pending[]                 = secPending('invoice', 'inv-2');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['invoice_reference' => 'quickbooks']), 1_000);

    expect($batch['created'])->toBe(1)
        ->and($batch['failed'])->toBe(1)
        ->and(array_count_values($client->calls)['latestInvoiceDocNumber'] ?? 0)->toBe(1)
        ->and($ledger->invoices['inv-1']['number'])->toBe('INV-016')
        ->and($ledger->invoices['inv-2']['number'])->toBe('LOCAL-2')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-2'))->toBeNull();
});

function secPaymentLookupClient(): FakeQuickBooks
{
    return new class extends FakeQuickBooks {
        /** @var array<string, array<string, mixed>>|null */
        public ?array $failBatch = null;

        public function findPaymentsForCustomers(array $connection, array $customerIds, bool $asBatch = false): array
        {
            return QuickBooksClient::findPaymentsForCustomers($connection, $customerIds, $asBatch);
        }

        public function batch(array $connection, array $items): array
        {
            if ($this->failBatch !== null) {
                $this->calls[] = 'batch';
                $results       = [];
                foreach ($items as $item) {
                    $results[(string) $item['bId']] = $this->failBatch;
                }

                return $results;
            }

            return parent::batch($connection, $items);
        }
    };
}

test('sec targeted payment lookups trim ids read linked payments and fail loudly on a bad batch item', function () {
    $client                     = secPaymentLookupClient();
    $client->invoices['qb-1']   = ['Id' => 'qb-1', 'LinkedTxn' => [['TxnId' => 'pay-1', 'TxnType' => 'Payment'], ['TxnId' => 'est-1', 'TxnType' => 'Estimate']]];
    $client->payments['pay-1']  = secPayment('pay-1', [['qb-1', '10.00']]);
    $connection                 = ['realm_id' => 'realm-1'];

    expect($client->findPaymentsForCustomers($connection, [' qb-1 ' => 'cust-1', '' => 'cust-2']))->toBe([$client->payments['pay-1']])
        ->and($client->calls)->toBe(['getInvoice:qb-1', 'getPayment:pay-1']);

    $client->calls             = [];
    $client->invoices['qb-2']  = ['Id' => 'qb-2', 'LinkedTxn' => [['TxnId' => 'pay-2', 'TxnType' => 'Payment'], ['TxnId' => 'pay-1', 'TxnType' => 'Payment']]];
    $client->payments['pay-2'] = secPayment('pay-2', [['qb-2', '5.00']]);
    $found                     = $client->findPaymentsForCustomers($connection, ['qb-1' => 'cust-1', 'qb-2' => 'cust-1', 'qb-none' => 'cust-1']);
    expect(array_column($found, 'Id'))->toBe(['pay-1', 'pay-2'])
        ->and($client->calls)->toBe(['batch', 'batch']);

    $client->calls            = [];
    $client->invoices['qb-3'] = ['Id' => 'qb-3'];
    expect($client->findPaymentsForCustomers($connection, ['qb-3' => 'cust-1', 'qb-none' => 'cust-1']))->toBe([])
        ->and($client->calls)->toBe(['batch'])
        ->and($client->findPaymentsForCustomers($connection, ['' => 'cust-1']))->toBe([]);

    $client->failBatch = ['ok' => false, 'status' => 503];
    expect(fn () => $client->findPaymentsForCustomers($connection, ['qb-1' => 'cust-1', 'qb-2' => 'cust-1']))
        ->toThrow(QuickBooksException::class, 'quickbooks.messages.invoice-lookup-failed');
    $thrown = null;
    try {
        $client->findPaymentsForCustomers($connection, ['qb-1' => 'cust-1', 'qb-2' => 'cust-1']);
    } catch (QuickBooksException $exception) {
        $thrown = $exception;
    }
    expect($thrown)->toBeInstanceOf(QuickBooksException::class)
        ->and($thrown->status)->toBe(503);
    $client->failBatch = ['ok' => false, 'status' => 500, 'error' => 'Remote said no'];
    expect(fn () => $client->findPaymentsForCustomers($connection, ['qb-1' => 'cust-1', 'qb-2' => 'cust-1']))
        ->toThrow(QuickBooksException::class, 'Remote said no');
});

test('sec batch item results map faults queries entity bodies and missing items', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'BatchItemResponse' => [
                ['bId' => 'entity', 'Customer' => ['Id' => '1', 'SyncToken' => '0', 'DisplayName' => 'Ada']],
                ['bId' => 'fault-message', 'Fault' => ['Error' => [['Message' => 'Only a message']]]],
                ['bId' => 'fault-empty', 'Fault' => ['Error' => [['Detail' => '  ', 'Message' => 'Ignored']]]],
                [
                    'bId'   => 'fault-coded',
                    'Fault' => ['Error' => [['Message' => 'Duplicate Name Exists Error', 'code' => '6240']]],
                ],
                ['bId' => 'query-list', 'QueryResponse' => ['startPosition' => 1, 'maxResults' => 2, 'Invoice' => [['Id' => 'a'], 'junk', ['Id' => 'b']]]],
                ['bId' => 'query-single', 'QueryResponse' => ['Invoice' => ['Id' => 'solo', 'DocNumber' => 'S-1']]],
                ['bId' => 'query-empty', 'QueryResponse' => []],
                ['bId' => 'no-entity', 'time' => '2026-10-01', 'List' => [['Id' => 'x']]],
                ['bId' => 'unknown', 'Customer' => ['Id' => '99']],
                'not-a-row',
            ],
        ], 200),
    ]);

    $items = [];
    foreach (['entity', 'fault-message', 'fault-empty', 'fault-coded', 'no-entity', 'missing'] as $bId) {
        $items[] = ['bId' => $bId, 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => $bId]];
    }
    foreach (['query-list', 'query-single', 'query-empty'] as $bId) {
        $items[] = ['bId' => $bId, 'query' => 'select * from Invoice'];
    }

    $results = (new QuickBooksClient())->batch(['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'access_token' => 'token', 'environment' => 'sandbox'], $items);

    $ok   = ['ok' => true, 'body' => [], 'rows' => [], 'error' => null, 'status' => 200, 'halt' => false];
    $fail = ['ok' => false, 'body' => [], 'rows' => [], 'error' => null, 'status' => 400, 'halt' => false];
    expect(array_keys($results))->toBe([
        'entity', 'fault-message', 'fault-empty', 'fault-coded', 'no-entity',
        'missing', 'query-list', 'query-single', 'query-empty',
    ])
        ->and($results['entity'])->toBe(array_merge($ok, ['body' => ['Id' => '1', 'SyncToken' => '0', 'DisplayName' => 'Ada']]))
        ->and($results['fault-message'])->toBe(array_merge($fail, ['error' => 'Only a message']))
        ->and($results['fault-empty'])->toBe(array_merge($fail, ['error' => 'quickbooks.messages.item-rejected']))
        ->and($results['fault-coded'])
        ->toBe(array_merge($fail, ['error' => 'Duplicate Name Exists Error', 'code' => '6240']))
        ->and($results['no-entity'])->toBe(array_merge($fail, ['error' => 'quickbooks.messages.batch-item-no-entity']))
        ->and($results['missing'])->toBe(array_merge($fail, ['error' => 'quickbooks.messages.batch-item-missing']))
        ->and($results['query-list'])->toBe(array_merge($ok, ['rows' => [['Id' => 'a'], ['Id' => 'b']]]))
        ->and($results['query-single'])->toBe(array_merge($ok, ['rows' => [['Id' => 'solo', 'DocNumber' => 'S-1']]]))
        ->and($results['query-empty'])->toBe($ok);
});
