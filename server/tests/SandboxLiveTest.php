<?php

/*
 * Live tests against a real QuickBooks sandbox company. They run only through
 * `composer test:sandbox`, which sets QB_SANDBOX_RUN=1, and only when these are set:
 * QB_SANDBOX_CLIENT_ID, QB_SANDBOX_CLIENT_SECRET, QB_SANDBOX_REFRESH_TOKEN, QB_SANDBOX_REALM_ID.
 * Otherwise every test here is skipped, so the default suite and CI never call Intuit.
 *
 * Each run creates its own customers, invoices, payments and accounts, named with a run tag,
 * in the sandbox company. Intuit rotates refresh tokens about once a day; when the stored one
 * stops working the run fails with invalid_grant and a new one is needed from Intuit's
 * OAuth 2.0 Playground. Tokens stay in memory and are never printed. The sandbox company must
 * not use automated sales tax, or the invoice totals asserted here will include tax.
 */

// Keep secrets out of stack traces however php.ini is set.
ini_set('zend.exception_ignore_args', '1');

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\WalletMapper;

const SANDBOX_VARIABLES = ['QB_SANDBOX_CLIENT_ID', 'QB_SANDBOX_CLIENT_SECRET', 'QB_SANDBOX_REFRESH_TOKEN', 'QB_SANDBOX_REALM_ID'];

beforeEach(function () {
    if (getenv('QB_SANDBOX_RUN') !== '1') {
        $this->markTestSkipped('QuickBooks sandbox tests run only through composer test:sandbox.');
    }
    $missing = array_values(array_filter(SANDBOX_VARIABLES, fn (string $name): bool => trim((string) getenv($name)) === ''));
    if ($missing !== []) {
        $this->fail('Missing sandbox variables: ' . implode(', ', $missing));
    }
});

/**
 * The sandbox connection, with an access token from one refresh per test run.
 *
 * @return array<string, mixed>
 */
function sandboxConnection(): array
{
    static $connection = null;
    static $failure    = null;
    if ($failure !== null) {
        throw new RuntimeException($failure);
    }
    if ($connection === null) {
        $stored = (string) getenv('QB_SANDBOX_REFRESH_TOKEN');
        try {
            $tokens = (new QuickBooksClient())->refresh(sandboxCredentials(), $stored);
        } catch (Throwable $exception) {
            // A new exception with no previous one, so no stack trace argument can carry a secret.
            $failure = $exception instanceof QuickBooksException
                ? 'Sandbox token refresh failed (' . $exception->getMessage() . '). If it says invalid_grant, get a new refresh token from the OAuth 2.0 Playground.'
                : 'Sandbox token refresh could not reach Intuit (' . get_class($exception) . '). Check that the environment allows oauth.platform.intuit.com.';

            throw new RuntimeException($failure);
        }
        if ($tokens['refresh_token'] !== '' && $tokens['refresh_token'] !== $stored) {
            fwrite(STDERR, "Intuit rotated the sandbox refresh token. Update QB_SANDBOX_REFRESH_TOKEN from the OAuth 2.0 Playground before the old one stops working.\n");
        }
        $connection = [
            'company_uuid'  => 'sandbox-company',
            'realm_id'      => trim((string) getenv('QB_SANDBOX_REALM_ID')),
            'environment'   => 'sandbox',
            'needs_reauth'  => false,
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
        ];
    }

    return $connection;
}

/**
 * @return array{client_id: string, client_secret: string, redirect_uri: string, environment: string}
 */
function sandboxCredentials(): array
{
    return [
        'client_id'     => trim((string) getenv('QB_SANDBOX_CLIENT_ID')),
        'client_secret' => trim((string) getenv('QB_SANDBOX_CLIENT_SECRET')),
        'redirect_uri'  => 'https://developer.intuit.com/v2/OAuth2Playground/RedirectUrl',
        'environment'   => 'sandbox',
    ];
}

function sandboxCents(mixed $amount): int
{
    return (int) round(((float) $amount) * 100);
}

/**
 * The Fleetbase service item. A brand-new sandbox company may have no Income account
 * for it to post to, so one is added first when needed.
 *
 * @param array<string, mixed> $connection
 */
function sandboxServiceItem(QuickBooksClient $client, array $connection): string
{
    $itemId = $client->ensureServiceItem($connection);
    if ($itemId === '') {
        $client->createAccount($connection, ['Name' => 'Fleetbase Test Income', 'AccountType' => 'Income']);
        $itemId = $client->ensureServiceItem($connection);
    }

    return $itemId;
}

/**
 * A run-unique account number within the 7 characters QuickBooks allows.
 */
function sandboxAcctNum(string $prefix): string
{
    return $prefix . substr(sandboxTag(), -6);
}

/**
 * A short tag unique to this run, so names never collide with an earlier run.
 */
function sandboxTag(): string
{
    static $tag = null;
    $tag ??= gmdate('mdHis') . substr(bin2hex(random_bytes(2)), 0, 3);

    return $tag;
}

test('the refresh token yields an access token for the sandbox company', function () {
    $connection = sandboxConnection();

    expect($connection['access_token'] !== '')->toBeTrue()
        ->and($connection['refresh_token'] !== '')->toBeTrue()
        ->and($connection['expires_in'])->toBeGreaterThan(0);
})->group('sandbox');

test('company info and preferences are read from the sandbox', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $company    = $client->companyInfo($connection);

    expect((string) ($company['CompanyName'] ?? ''))->not->toBe('')
        ->and((string) $client->homeCurrency($connection))->toMatch('/^[A-Z]{3}$/')
        ->and($client->multiCurrencyEnabled($connection))->toBeBool()
        ->and($client->customTxnNumbers($connection))->toBeBool();
})->group('sandbox');

test('a mapped customer is created, found, read and updated', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $name       = "Fleetbase Test O'Brien " . sandboxTag();
    $email      = 'fbqb-' . sandboxTag() . '@example.com';
    $payload    = (new CustomerMapper())->toQuickBooks([
        'name'    => $name,
        'email'   => $email,
        'phone'   => '+1 555 0100',
        'notes'   => 'Created by the Fleetbase QuickBooks sandbox tests.',
        'address' => ['line1' => '1 Main St', 'line2' => null, 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
    ]);

    $created = $client->createCustomer($connection, $payload);

    expect((string) ($created['Id'] ?? ''))->not->toBe('')
        ->and($created['DisplayName'])->toBe($name)
        ->and($created['PrimaryEmailAddr']['Address'])->toBe($email)
        ->and($created['BillAddr']['City'])->toBe('Austin');

    expect($client->findCustomerByDisplayName($connection, $name)['Id'] ?? null)->toBe($created['Id'])
        ->and($client->findCustomerByEmail($connection, $email)['Id'] ?? null)->toBe($created['Id'])
        ->and($client->getCustomer($connection, (string) $created['Id'])['DisplayName'] ?? null)->toBe($name);

    $renamed = $name . ' Updated';
    $updated = $client->updateCustomer($connection, (string) $created['Id'], (string) $created['SyncToken'], array_merge($payload, ['DisplayName' => $renamed]));

    expect($updated['DisplayName'])->toBe($renamed)
        ->and($updated['SyncToken'])->not->toBe($created['SyncToken']);
})->group('sandbox');

test('an update with a stale sync token is refused as a conflict', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $created    = $client->createCustomer($connection, ['DisplayName' => 'Fleetbase Test Stale ' . sandboxTag()]);
    $client->updateCustomer($connection, (string) $created['Id'], (string) $created['SyncToken'], ['DisplayName' => 'Fleetbase Test Stale A ' . sandboxTag()]);

    try {
        $client->updateCustomer($connection, (string) $created['Id'], (string) $created['SyncToken'], ['DisplayName' => 'Fleetbase Test Stale B ' . sandboxTag()]);
        $this->fail('QuickBooks accepted an update with a stale SyncToken.');
    } catch (QuickBooksException $exception) {
        expect($exception->status)->toBe(400)
            ->and($exception->faultCode)->toBe('5010')
            ->and($exception->isNotFound())->toBeFalse();
    }
})->group('sandbox');

test('reads of missing records come back empty instead of failing', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();

    expect($client->getCustomer($connection, '999999999'))->toBeNull()
        ->and($client->getInvoice($connection, '999999999'))->toBeNull()
        ->and($client->getPayment($connection, '999999999'))->toBeNull()
        ->and($client->getAccount($connection, '999999999'))->toBeNull()
        ->and($client->findInvoiceByDocNumber($connection, 'NOPE-' . sandboxTag()))->toBeNull();
})->group('sandbox');

test('a refused access token is reported as unauthorized', function () {
    $connection = array_merge(sandboxConnection(), ['access_token' => 'not-a-real-token']);

    try {
        (new QuickBooksClient())->companyInfo($connection);
        $this->fail('QuickBooks accepted a fake access token.');
    } catch (QuickBooksException $exception) {
        expect($exception->isUnauthorized())->toBeTrue();
    }
})->group('sandbox');

test('a mapped invoice is created, found, paid, updated and voided', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $itemId     = sandboxServiceItem($client, $connection);
    $customer   = $client->createCustomer($connection, ['DisplayName' => 'Fleetbase Test Invoice ' . sandboxTag()]);
    $number     = 'FB' . sandboxTag();
    $mapper     = new InvoiceMapper();
    $invoice    = [
        'number'   => $number,
        'date'     => gmdate('Y-m-d'),
        'due_date' => gmdate('Y-m-d', time() + 14 * 86400),
        'notes'    => 'Sandbox test invoice',
        'currency' => $client->homeCurrency($connection),
        'tax'      => 0,
        'items'    => [
            ['description' => 'Delivery', 'quantity' => 3, 'amount' => 1000],
            ['description' => 'Fuel surcharge', 'quantity' => 1, 'amount' => 250],
        ],
    ];

    expect($itemId)->not->toBe('')
        ->and($client->ensureServiceItem($connection))->toBe($itemId);

    $created = $client->createInvoice($connection, $mapper->toQuickBooks($invoice, (string) $customer['Id'], $itemId));

    expect((string) ($created['Id'] ?? ''))->not->toBe('')
        ->and(sandboxCents($created['TotalAmt']))->toBe(1250)
        ->and($client->getInvoice($connection, (string) $created['Id'])['Id'] ?? null)->toBe($created['Id']);

    $local = $mapper->fromQuickBooks($created);
    expect($local['total'] ?? null)->toBe(1250);

    if ($client->customTxnNumbers($connection) === true) {
        expect($created['DocNumber'])->toBe($number)
            ->and($client->findInvoiceByDocNumber($connection, $number)['Id'] ?? null)->toBe($created['Id']);
    } else {
        expect((string) ($created['DocNumber'] ?? ''))->not->toBe('');
    }

    $payment = $client->createPayment($connection, $mapper->payment((string) $customer['Id'], (string) $created['Id'], 500, gmdate('Y-m-d')));

    expect((string) ($payment['Id'] ?? ''))->not->toBe('')
        ->and(sandboxCents($payment['TotalAmt']))->toBe(500)
        ->and($client->getPayment($connection, (string) $payment['Id'])['Id'] ?? null)->toBe($payment['Id'])
        ->and($client->findPaymentForInvoice($connection, (string) $customer['Id'], (string) $created['Id'])['Id'] ?? null)->toBe($payment['Id'])
        ->and(array_column($client->findPaymentsForCustomers($connection, [(string) $customer['Id']]), 'Id'))->toContain($payment['Id'])
        ->and(sandboxCents($client->getInvoice($connection, (string) $created['Id'])['Balance'] ?? null))->toBe(750);

    $remote                        = $client->getInvoice($connection, (string) $created['Id']);
    $changed                       = $invoice;
    $changed['items'][0]['amount'] = 1500;
    $update                        = $mapper->withExistingLineIds($mapper->toQuickBooks($changed, (string) $customer['Id'], $itemId), $remote);
    $updated                       = $client->updateInvoice($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $update);

    expect(sandboxCents($updated['TotalAmt']))->toBe(1750)
        ->and(count(array_filter($updated['Line'], fn (array $line): bool => ($line['DetailType'] ?? '') === 'SalesItemLineDetail')))->toBe(2);

    $other  = $client->createInvoice($connection, $mapper->toQuickBooks(array_merge($invoice, ['number' => $number . 'V']), (string) $customer['Id'], $itemId));
    $voided = $client->voidInvoice($connection, (string) $other['Id'], (string) $other['SyncToken']);

    expect(sandboxCents($client->getInvoice($connection, (string) $voided['Id'])['TotalAmt'] ?? null))->toBe(0);
})->group('sandbox');

test('a mapped wallet account is created, found and updated', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $mapper     = new WalletMapper();
    $wallet     = [
        'public_id'   => sandboxAcctNum('l'),
        'name'        => 'Fleetbase Test Wallet ' . sandboxTag(),
        'description' => 'Sandbox test wallet',
        'currency'    => $client->homeCurrency($connection),
        'status'      => 'active',
    ];

    $created = $client->createAccount($connection, $mapper->toQuickBooks($wallet));

    expect((string) ($created['Id'] ?? ''))->not->toBe('')
        ->and($created['AccountType'])->toBe('Other Current Asset')
        ->and(array_column($client->findAccountsByName($connection, $wallet['name']), 'Id'))->toContain($created['Id']);

    // QuickBooks keeps AcctNum only when the company has account numbers turned on.
    if ((string) ($created['AcctNum'] ?? '') !== '') {
        expect($client->findAccountByAcctNum($connection, $wallet['public_id'])['Id'] ?? null)->toBe($created['Id']);
    }

    $renamed = array_merge($wallet, ['name' => $wallet['name'] . ' Renamed']);
    $updated = $client->updateAccount($connection, (string) $created['Id'], (string) $created['SyncToken'], $mapper->toQuickBooks($renamed));

    expect($updated['Name'])->toBe($renamed['name'])
        ->and($mapper->matches($renamed, $mapper->toQuickBooks($renamed), $updated))->toBeTrue();
})->group('sandbox');

test('a batch mixes writes and queries and reports each item', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $results    = $client->batch($connection, [
        ['bId' => 'new', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Fleetbase Test Batch ' . sandboxTag()]],
        ['bId' => 'dup', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Fleetbase Test Batch ' . sandboxTag()]],
        ['bId' => 'q', 'query' => "select * from Customer where DisplayName = 'Fleetbase Test Batch " . sandboxTag() . "'"],
    ]);

    expect($results['new']['ok'])->toBeTrue()
        ->and((string) ($results['new']['body']['Id'] ?? ''))->not->toBe('')
        ->and($results['dup']['ok'])->toBeFalse()
        ->and((string) $results['dup']['error'])->not->toBe('')
        ->and($results['q']['ok'])->toBeTrue();
})->group('sandbox');

test('the sync engine pushes a customer, invoice, payment and wallet end to end', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $engine     = new SyncEngine($client, new CustomerMapper(), new InvoiceMapper(), new WalletMapper(), new BackoffPolicy(static fn (int $wait): int => $wait));
    $ledger     = new SyncLedger();
    $tag        = sandboxTag();
    $company    = 'sandbox-company';
    sandboxServiceItem($client, $connection);

    $ledger->connections[$company] = $connection;
    $ledger->customers['cust-e2e'] = ['uuid' => 'cust-e2e', 'company_uuid' => $company, 'name' => 'Fleetbase Test Engine ' . $tag, 'email' => 'fbqb-engine-' . $tag . '@example.com'];
    $ledger->invoices['inv-e2e']   = [
        'uuid'          => 'inv-e2e',
        'company_uuid'  => $company,
        'customer_uuid' => 'cust-e2e',
        'number'        => 'FE' . $tag,
        'date'          => gmdate('Y-m-d'),
        'due_date'      => gmdate('Y-m-d', time() + 14 * 86400),
        'currency'      => $client->homeCurrency($connection),
        'tax'           => 100,
        'total'         => 2100,
        'amount_paid'   => 2100,
        'status'        => 'paid',
        'items'         => [['description' => 'Delivery', 'quantity' => 2, 'unit_price' => 1000, 'amount' => 2000]],
    ];
    $ledger->wallets['wal-e2e'] = [
        'uuid'         => 'wal-e2e',
        'company_uuid' => $company,
        'public_id'    => sandboxAcctNum('e'),
        'name'         => 'Fleetbase Test Engine Wallet ' . $tag,
        'description'  => 'Sandbox engine wallet',
        'currency'     => $client->homeCurrency($connection),
        'status'       => 'active',
    ];
    foreach (['customer' => 'cust-e2e', 'invoice' => 'inv-e2e', 'wallet' => 'wal-e2e'] as $type => $uuid) {
        $ledger->pending[] = ['company_uuid' => $company, 'local_type' => $type, 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
    }

    $batch  = $engine->runScheduled($ledger, $company, qbSettings(['interval_minutes' => 1]), time(), true);
    $errors = array_values(array_filter(array_column($ledger->attempts, 'error')));

    expect($errors)->toBe([])
        ->and($batch['failed'] ?? 0)->toBe(0);

    $links = [];
    foreach ($ledger->links as $link) {
        $links[$link['local_type'] . ':' . $link['local_uuid']] = (string) $link['qbo_id'];
    }

    expect(array_keys($links))->toContain('customer:cust-e2e', 'invoice:inv-e2e', 'wallet:wal-e2e');

    $remoteCustomer = $client->getCustomer($connection, $links['customer:cust-e2e']);
    $remoteInvoice  = $client->getInvoice($connection, $links['invoice:inv-e2e']);
    $remoteAccount  = $client->getAccount($connection, $links['wallet:wal-e2e']);

    expect($remoteCustomer['DisplayName'] ?? null)->toBe('Fleetbase Test Engine ' . $tag)
        ->and(sandboxCents($remoteInvoice['TotalAmt'] ?? null))->toBe(2100)
        ->and(sandboxCents($remoteInvoice['Balance'] ?? null))->toBe(0)
        ->and($remoteInvoice['CustomerRef']['value'] ?? null)->toBe($links['customer:cust-e2e'])
        ->and($remoteAccount['Name'] ?? null)->toBe('Fleetbase Test Engine Wallet ' . $tag);
})->group('sandbox');
