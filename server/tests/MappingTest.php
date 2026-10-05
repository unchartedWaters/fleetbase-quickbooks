<?php

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\NextDocNumber;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\WalletMapper;

test('the next invoice number keeps the prefix and increments the trailing digits', function () {
    expect(NextDocNumber::after('1041'))->toBe('1042')
        ->and(NextDocNumber::after('INV-009'))->toBe('INV-010')
        ->and(NextDocNumber::after('OPEN'))->toBeNull();
});

test('customer invoice and payment payloads match the quickbooks shape', function () {
    $customers = new CustomerMapper();
    $invoices  = new InvoiceMapper();
    $customer  = $customers->toQuickBooks([
        'name'    => 'Ada Lovelace',
        'email'   => 'ada@example.test',
        'phone'   => '555-0100',
        'notes'   => 'Priority',
        'address' => ['line1' => '1 Analytical Engine', 'city' => 'London', 'postal_code' => 'SW1'],
    ]);
    $invoice = $invoices->toQuickBooks([
        'number'   => 'INV-1',
        'date'     => '2026-09-01',
        'due_date' => '2026-09-15',
        'notes'    => 'Net 14',
        'currency' => 'USD',
        'tax'      => 150,
        'items'    => [
            ['description' => 'Delivery', 'quantity' => 2, 'unit_price' => 1000, 'amount' => 2000],
        ],
    ], 'cust-9', 'item-1');
    $payment = $invoices->payment('cust-9', 'inv-9', 2150);

    expect($customer['DisplayName'])->toBe('Ada Lovelace')
        ->and($customer['PrimaryEmailAddr']['Address'])->toBe('ada@example.test')
        ->and($customer['BillAddr']['Line1'])->toBe('1 Analytical Engine')
        ->and($invoice['CustomerRef']['value'])->toBe('cust-9')
        ->and($invoice['DocNumber'])->toBe('INV-1')
        ->and($invoice['Line'][0]['SalesItemLineDetail']['ItemRef']['value'])->toBe('item-1')
        ->and($invoice['Line'][1]['Description'])->toBe(InvoiceMapper::TAX_LINE_DESCRIPTION)
        ->and($payment['Line'][0]['LinkedTxn'][0])->toBe(['TxnId' => 'inv-9', 'TxnType' => 'Invoice']);
});

test('a wallet maps to a quickbooks chart of accounts entry', function () {
    $mapper = new WalletMapper();
    $wallet = $mapper->toQuickBooks([
        'public_id'   => 'wallet_abc123',
        'name'        => 'Operating',
        'description' => 'Company float',
        'currency'    => 'usd',
        'status'      => 'active',
    ]);

    expect($wallet['Name'])->toBe('Operating')
        ->and($wallet)->not->toHaveKey('AcctNum')
        ->and($wallet['AccountType'])->toBe('Other Current Asset')
        ->and($wallet['CurrencyRef']['value'])->toBe('USD')
        ->and($wallet['Active'])->toBeTrue()
        ->and($wallet['Description'])->toBe('Company float');

    $short = $mapper->toQuickBooks(['public_id' => 'W12345', 'name' => 'Short']);
    expect($short['AcctNum'])->toBe('W12345');

    $closed = $mapper->toQuickBooks(['name' => 'Closed', 'status' => 'closed']);
    expect($closed['Active'])->toBeFalse();
});

test('a scheduled batch creates a linked quickbooks account for a wallet', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = connectedLedger();
    $ledger->wallets['wal-1'] = [
        'uuid'         => 'wal-1',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_1',
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'USD',
        'status'       => 'active',
    ];
    $ledger->pending[] = pending('wallet', 'wal-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $link  = $ledger->link('company-uuid', 'realm-1', 'wallet', 'wal-1');

    expect($batch['created'])->toBe(1)
        ->and($client->calls)->toContain('createAccount')
        ->and($client->calls)->not->toContain('findAccountByAcctNum')
        ->and($link['qbo_entity'])->toBe('Account')
        ->and($client->accounts[$link['qbo_id']])->not->toHaveKey('AcctNum');
});

test('cents convert to a decimal string and quickbooks major units convert back to minor units', function () {
    expect(Amounts::centsToDecimal(199))->toBe('1.99')
        ->and(Amounts::toMinorUnits('1.99'))->toBe(199)
        ->and(Amounts::toMinorUnits(10))->toBe(1000)
        ->and(Amounts::toMinorUnits('10.5'))->toBe(1050)
        ->and(Amounts::toMinorUnits('-10.00'))->toBe(-1000);

    $payload = (new InvoiceMapper())->toQuickBooks([
        'items' => [['description' => 'Stop', 'quantity' => 1, 'unit_price' => 199, 'amount' => 199]],
    ], 'cust', 'item');

    expect($payload['Line'][0]['Amount'])->toBe('1.99');
});

test('an uneven quantity sends a unit price that rounds half-up back to the line amount', function () {
    $invoices = new InvoiceMapper();
    $omitted  = $invoices->toQuickBooks([
        'items' => [
            ['description' => 'Delivery', 'quantity' => 3, 'amount' => 1000],
        ],
    ], 'cust-9', 'item-1');
    $stored = $invoices->toQuickBooks([
        'items' => [
            ['description' => 'Delivery', 'quantity' => 3, 'unit_price' => 333, 'amount' => 1000],
        ],
    ], 'cust-9', 'item-1');
    $even = $invoices->toQuickBooks([
        'items' => [
            ['description' => 'Delivery', 'quantity' => 2, 'unit_price' => 1000, 'amount' => 2000],
        ],
    ], 'cust-9', 'item-1');

    expect($omitted['Line'][0]['Amount'])->toBe('10.00')
        ->and($omitted['Line'][0]['SalesItemLineDetail']['UnitPrice'])->toBe('3.333')
        ->and($stored['Line'][0]['Amount'])->toBe('10.00')
        ->and($stored['Line'][0]['SalesItemLineDetail']['UnitPrice'])->toBe('3.333')
        ->and($even['Line'][0]['Amount'])->toBe('20.00')
        ->and($even['Line'][0]['SalesItemLineDetail']['UnitPrice'])->toBe('10.00');
});

test('a product line described as Tax is not stored as the tax amount', function () {
    $mapped = (new InvoiceMapper())->fromQuickBooks([
        'TotalAmt' => '10.00',
        'Line'     => [[
            'Amount'              => '10.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Tax',
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => '10.00'],
        ]],
    ]);

    expect($mapped['tax'])->toBe(0)
        ->and($mapped['items'][0]['description'])->toBe('Tax')
        ->and($mapped['items'][0]['amount'])->toBe(1000);
});

test('a storefront customer a fleet ops contact and a company all map to a quickbooks customer', function () {
    $mapper     = new CustomerMapper();
    $storefront = $mapper->fromParty(['given_name' => 'Grace', 'family_name' => 'Hopper', 'email' => 'grace@example.test', 'phone' => '555']);
    $contact    = $mapper->fromParty(['name' => 'Fleet Contact', 'email' => 'contact@example.test', 'phone' => '444']);
    $company    = $mapper->fromParty(['name' => 'unchartedWaters', 'email' => 'ops@example.test']);

    expect($mapper->toQuickBooks($storefront)['DisplayName'])->toBe('Grace Hopper')
        ->and($mapper->toQuickBooks($contact)['DisplayName'])->toBe('Fleet Contact')
        ->and($mapper->toQuickBooks($company)['DisplayName'])->toBe('unchartedWaters');
});

test('an invoice whose customer has no link syncs the customer first', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = connectedLedger();
    $ledger->customers['cust-1'] = ['uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'];
    $ledger->invoices['inv-1']   = invoiceRow('inv-1', 'cust-1');
    $ledger->pending[]           = pending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('createInvoice')
        ->and(array_search('createCustomer', $client->calls, true))->toBeLessThan(array_search('createInvoice', $client->calls, true))
        ->and($batch['created'])->toBe(1);
});

test('an invoice reports an error when its customer cannot be synced', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new QuickBooksException(500, 'customer failed');
    $ledger                      = connectedLedger();
    $ledger->customers['cust-1'] = ['uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'];
    $ledger->invoices['inv-1']   = invoiceRow('inv-1', 'cust-1');
    $ledger->pending[]           = pending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['failed'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice');
});

test('a foreign currency invoice is skipped with a recorded reason', function () {
    [$engine, $client]                                    = qbEngine();
    $ledger                                               = connectedLedger();
    $ledger->connections['company-uuid']['home_currency'] = 'USD';
    $ledger->invoices['inv-1']                            = invoiceRow('inv-1', 'cust-1', ['currency' => 'EUR']);
    $ledger->links[]                                      = customerLink('cust-1');
    $ledger->pending[]                                    = pending('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $attempt = $ledger->attempts[0];

    expect($attempt['error'])->toContain('EUR')
        ->and($client->calls)->not->toContain('createInvoice');
});

test('use the quickbooks invoice copies the total and understood line items', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = connectedLedger();
    $ledger->customers['cust-1']       = ['uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'];
    $ledger->links[]                   = customerLink('cust-1');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = invoiceRow('inv-1', 'cust-1', ['total' => 1000]);
    $client->invoices['qb-1']          = [
        'Id'        => 'qb-1',
        'SyncToken' => '2',
        'DocNumber' => 'INV-1',
        'TotalAmt'  => 25,
        'TxnDate'   => '2026-09-01',
        'DueDate'   => '2026-09-15',
        'Line'      => [[
            'Amount'              => '25.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => 'Evening delivery',
            'SalesItemLineDetail' => ['Qty' => 2, 'UnitPrice' => '12.50'],
        ]],
    ];
    $ledger->links[]   = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1',
        'qbo_entity'   => 'Invoice', 'qbo_id' => 'qb-1', 'sync_token' => '2',
    ];
    $ledger->pending[] = pending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'invoice_conflict'  => 'quickbooks',
        'invoice_reference' => 'fleetbase',
    ]), time());

    expect($batch['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['total'])->toBe(2500)
        ->and($ledger->invoices['inv-1']['items'][0]['description'])->toBe('Evening delivery')
        ->and($ledger->invoices['inv-1']['items'][0]['quantity'])->toBe(2)
        ->and($ledger->invoices['inv-1']['items'][0]['amount'])->toBe(2500);
});

test('quickbooks wallet identifier copies the account number onto the wallet', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = connectedLedger();
    $ledger->wallets['wal-1'] = [
        'uuid'         => 'wal-1',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_1',
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'USD',
        'status'       => 'active',
    ];
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'AcctNum'     => '4000',
        'Description' => 'Float',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'USD'],
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-1',
        'qbo_entity'   => 'Account', 'qbo_id' => 'acct-9', 'sync_token' => '1',
    ];
    $ledger->pending[] = pending('wallet', 'wal-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'wallet_reference' => 'quickbooks',
    ]), time());

    expect($ledger->wallets['wal-1']['acct_num'])->toBe('4000')
        ->and($ledger->wallets['wal-1']['meta']['quickbooks_acct_num'])->toBe('4000')
        ->and($client->calls)->not->toContain('updateAccount');
});

test('use the quickbooks account copies wallet details and leaves the fleetbase id in place', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = connectedLedger();
    $ledger->wallets['wal-1'] = [
        'uuid'         => 'wal-1',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_1',
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'USD',
        'status'       => 'active',
    ];
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'QB Operating',
        'AcctNum'     => '4000',
        'Description' => 'QB float',
        'Active'      => false,
        'CurrencyRef' => ['value' => 'EUR'],
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-1',
        'qbo_entity'   => 'Account', 'qbo_id' => 'acct-9', 'sync_token' => '1',
    ];
    $ledger->pending[] = pending('wallet', 'wal-1');
    $settings          = qbSettings([
        'interval_minutes' => 1,
        'wallet_reference' => 'fleetbase',
        'wallet_conflict'  => 'quickbooks',
    ]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($ledger->wallets['wal-1']['name'])->toBe('QB Operating')
        ->and($ledger->wallets['wal-1']['description'])->toBe('QB float')
        ->and($ledger->wallets['wal-1']['currency'])->toBe('EUR')
        ->and($ledger->wallets['wal-1']['status'])->toBe('closed')
        ->and($ledger->wallets['wal-1']['public_id'])->toBe('wallet_1')
        ->and($ledger->wallets['wal-1']['acct_num'] ?? null)->toBeNull()
        ->and($ledger->wallets['wal-1']['meta']['quickbooks_acct_num'] ?? null)->toBeNull()
        ->and($client->calls)->not->toContain('updateAccount');

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($ledger->wallets['wal-1']['acct_num'] ?? null)->toBeNull()
        ->and($client->calls)->not->toContain('updateAccount');
});

test('quickbooks primary copies the linked account currency onto the wallet', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = connectedLedger();
    $ledger->wallets['wal-1'] = [
        'uuid'         => 'wal-1',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_1',
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'USD',
        'status'       => 'active',
    ];
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'Description' => 'Float',
        'Active'      => true,
        'CurrencyRef' => ['value' => 'EUR'],
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-1',
        'qbo_entity'   => 'Account', 'qbo_id' => 'acct-9', 'sync_token' => '1',
    ];
    $ledger->pending[] = pending('wallet', 'wal-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'wallet_conflict'  => 'quickbooks',
        'wallet_reference' => 'quickbooks',
    ]), time());

    expect($batch['failed'])->toBe(0)
        ->and($ledger->wallets['wal-1']['currency'])->toBe('EUR')
        ->and($client->calls)->not->toContain('createAccount')
        ->and($ledger->attempts[0]['error'])->toBeNull();
});

test('quickbooks primary uses the home currency when the account has no currency', function () {
    [$engine, $client]        = qbEngine();
    $ledger                   = connectedLedger();
    $ledger->wallets['wal-1'] = [
        'uuid'         => 'wal-1',
        'company_uuid' => 'company-uuid',
        'public_id'    => 'wallet_1',
        'name'         => 'Operating',
        'description'  => 'Float',
        'currency'     => 'EUR',
        'status'       => 'active',
    ];
    $client->accounts['acct-9'] = [
        'Id'          => 'acct-9',
        'SyncToken'   => '1',
        'Name'        => 'Operating',
        'Description' => 'Float',
        'Active'      => true,
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-1',
        'qbo_entity'   => 'Account', 'qbo_id' => 'acct-9', 'sync_token' => '1',
    ];
    $ledger->pending[] = pending('wallet', 'wal-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes' => 1,
        'wallet_conflict'  => 'quickbooks',
        'wallet_reference' => 'quickbooks',
    ]), time());

    expect($batch['failed'])->toBe(0)
        ->and($ledger->connections['company-uuid']['home_currency'])->toBe('USD')
        ->and($ledger->wallets['wal-1']['currency'])->toBe('USD')
        ->and($client->calls)->not->toContain('createAccount')
        ->and($ledger->attempts[0]['error'])->toBeNull();
});

test('quickbooks customer name is kept when a remote customer already exists', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = connectedLedger();
    $ledger->customers['cust-1'] = [
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Fleet Ada',
        'email'        => 'ada@example.test',
    ];
    $client->customers['qbo-9'] = [
        'Id'               => 'qbo-9',
        'SyncToken'        => '0',
        'DisplayName'      => 'QuickBooks Ada',
        'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
    ];
    $ledger->pending[] = pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'   => 1,
        'customer_reference' => 'quickbooks',
    ]), time());

    expect($client->calls)->not->toContain('createCustomer')
        ->and($ledger->customers['cust-1']['name'])->toBe('QuickBooks Ada')
        ->and($ledger->link('company-uuid', 'realm-1', 'customer', 'cust-1')['qbo_id'])->toBe('qbo-9');
});

test('a retry after a lost link adopts the existing record by doc number', function () {
    [$engine, $client]    = qbEngine();
    $client->invoiceByDoc = [
        'Id'          => 'existing-9',
        'SyncToken'   => '4',
        'DocNumber'   => 'INV-1',
        'TotalAmt'    => 10,
        'TxnDate'     => '2026-09-01',
        'DueDate'     => '2026-09-15',
        'CustomerRef' => ['value' => 'qbo-customer'],
    ];
    $client->invoices['existing-9'] = $client->invoiceByDoc;
    $ledger                         = connectedLedger();
    $ledger->invoices['inv-1']      = invoiceRow('inv-1', 'cust-1');
    $ledger->links[]                = customerLink('cust-1');
    $ledger->pending[]              = pending('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $link  = $ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1');

    expect($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($link['qbo_id'])->toBe('existing-9');
});

function connectedLedger(): SyncLedger
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

function invoiceRow(string $uuid, string $customerUuid, array $overrides = []): array
{
    return array_merge([
        'uuid'          => $uuid,
        'company_uuid'  => 'company-uuid',
        'customer_uuid' => $customerUuid,
        'number'        => 'INV-1',
        'date'          => '2026-09-01',
        'due_date'      => '2026-09-15',
        'currency'      => 'USD',
        'tax'           => 0,
        'total'         => 1000,
        'status'        => 'sent',
        'items'         => [
            ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000],
        ],
    ], $overrides);
}

function pending(string $type, string $uuid): array
{
    return [
        'company_uuid' => 'company-uuid',
        'local_type'   => $type,
        'local_uuid'   => $uuid,
        'status'       => 'pending',
        'attempts'     => 0,
    ];
}

function customerLink(string $uuid): array
{
    return [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => 'customer',
        'local_uuid'   => $uuid,
        'qbo_entity'   => 'Customer',
        'qbo_id'       => 'qbo-customer',
        'sync_token'   => '0',
    ];
}
