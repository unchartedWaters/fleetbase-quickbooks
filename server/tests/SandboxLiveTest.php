<?php

/*
 * Live tests against a real QuickBooks sandbox company. They run only through
 * `composer test:sandbox`, which sets QB_SANDBOX_RUN=1, and need these variables:
 * QB_SANDBOX_CLIENT_ID, QB_SANDBOX_CLIENT_SECRET, QB_SANDBOX_REFRESH_TOKEN, QB_SANDBOX_REALM_ID.
 * Without QB_SANDBOX_RUN every test here is skipped, so the default suite and CI never call
 * Intuit. With it, a missing variable fails every test and names the variable, so an
 * incomplete setup never passes as all-skipped.
 *
 * Each run creates its own customers, invoices, payments and accounts, named with a run tag,
 * in the sandbox company. Intuit rotates refresh tokens about once a day; when the stored one
 * stops working the run fails with invalid_grant and a new one is needed from Intuit's
 * OAuth 2.0 Playground. Tokens stay in memory and are never printed. QuickBooks works out
 * invoice tax, so totals are checked against the tax it reports. Automated sales tax is not tested.
 */

// Keep secrets out of stack traces however php.ini is set.
ini_set('zend.exception_ignore_args', '1');

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\InvoiceTax;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
        sandboxNoteRotation($tokens['refresh_token']);
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
 * The suite cannot change QB_SANDBOX_REFRESH_TOKEN, so a rotated token is only reported, never shown.
 */
function sandboxNoteRotation(string $refreshToken): void
{
    if ($refreshToken !== '' && $refreshToken !== (string) getenv('QB_SANDBOX_REFRESH_TOKEN')) {
        $variable = 'QB_SANDBOX_REFRESH_TOKEN';
        fwrite(STDERR, "Intuit rotated the sandbox refresh token. Update {$variable} from the OAuth 2.0 Playground"
            . " before the old one stops working.\n");
    }
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

test('the app refreshes with the newest refresh token and keeps the one Intuit returns', function () {
    // Uses the newest token this run holds, the way the app does, so the stored variable is not spent twice.
    $connection = sandboxConnection();
    $refreshed  = (new TokenRefresher(new QuickBooksClient()))->refresh($connection, sandboxCredentials());

    expect($refreshed['needs_reauth'])->toBeFalse()
        ->and($refreshed)->not->toHaveKey('refresh_error')
        ->and((string) $refreshed['refresh_token'])->not->toBe('')
        ->and((string) $refreshed['access_token'])->not->toBe('')
        ->and($refreshed['token_expires_at'])->toBeGreaterThan(time());

    sandboxNoteRotation((string) $refreshed['refresh_token']);

    $info = (new QuickBooksClient())->companyInfo($refreshed);
    expect((string) ($info['CompanyName'] ?? ''))->not->toBe('');
})->group('sandbox');

test('a refresh token Intuit does not recognize asks the user to reconnect', function () {
    $connection = array_merge(sandboxConnection(), ['refresh_token' => 'not-a-real-refresh-token']);
    $refreshed  = (new TokenRefresher(new QuickBooksClient()))->refresh($connection, sandboxCredentials());

    expect($refreshed['needs_reauth'])->toBeTrue()
        ->and($refreshed)->not->toHaveKey('refresh_error')
        // Compared as booleans so a failure never prints a live token.
        ->and($refreshed['access_token'] === $connection['access_token'])->toBeTrue()
        ->and($refreshed['refresh_token'] === 'not-a-real-refresh-token')->toBeTrue();
})->group('sandbox');

test('starting a connection sends the browser to Intuit sign-in with the request intact', function () {
    session(['company' => 'sandbox-company', 'user' => 'sandbox-user']);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'client_id'                 => sandboxCredentials()['client_id'],
        'client_secret'             => (new SecretCipher())->encrypt(sandboxCredentials()['client_secret']),
        'environment'               => 'sandbox',
        'public_oauth_redirect_url' => sandboxCredentials()['redirect_uri'],
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store,
        new ConnectionProbe(new QuickBooksClient())
    );
    // Connect needs a console address to return to after the Intuit sign-in.
    $consoleHost = config('quickbooks.console_host');
    config()->set('quickbooks.console_host', 'https://console.example.com');

    try {
        $started = $controller->start(Request::create('/oauth/start', 'POST', ['company_uuid' => 'sandbox-company']));
    } finally {
        config()->set('quickbooks.console_host', $consoleHost);
        session(['company' => null, 'user' => null]);
    }

    expect($started->getStatusCode())->toBe(200);
    $url = (string) ($started->getData(true)['url'] ?? '');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $sent);

    expect(parse_url($url, PHP_URL_HOST))->toBe('appcenter.intuit.com')
        ->and($sent['client_id'] ?? null)->toBe(sandboxCredentials()['client_id'])
        ->and($sent['redirect_uri'] ?? null)->toBe(sandboxCredentials()['redirect_uri'])
        ->and($sent['scope'] ?? null)->toBe('com.intuit.quickbooks.accounting')
        ->and($sent['response_type'] ?? null)->toBe('code')
        ->and($sent['code_challenge'] ?? '')->not->toBe('')
        ->and($sent['code_challenge_method'] ?? null)->toBe('S256')
        ->and($sent['state'] ?? null)->toBe($started->getData(true)['state'] ?? '');

    // Intuit checks the app only after someone signs in, so this follows the link as far as its sign-in page.
    try {
        $page = Http::withOptions(['allow_redirects' => ['max' => 10, 'track_redirects' => true]])->get($url);
    } catch (Throwable $exception) {
        // A new exception with no previous one, so the link and its client id stay out of the output.
        $hosts = 'appcenter.intuit.com and accounts.intuit.com';
        $why   = 'Intuit sign-in could not be reached (' . get_class($exception) . ').';
        $why .= " Check that the environment allows {$hosts}.";
        throw new RuntimeException($why);
    }
    $history = $page->toPsrResponse()->getHeader('X-Guzzle-Redirect-History');
    $landed  = (string) end($history);
    parse_str((string) parse_url($landed, PHP_URL_QUERY), $signIn);
    $back = (string) ($signIn['redirect_uri'] ?? '');
    parse_str((string) parse_url($back, PHP_URL_QUERY), $resumed);

    expect($page->status())->toBe(200)
        ->and(parse_url($landed, PHP_URL_HOST))->toBe('accounts.intuit.com')
        ->and(parse_url($landed, PHP_URL_PATH))->toBe('/app/sign-in')
        ->and(parse_url($back, PHP_URL_HOST))->toBe('appcenter.intuit.com');
    $carried   = ['client_id', 'redirect_uri', 'scope', 'response_type', 'state', 'code_challenge'];
    $carried[] = 'code_challenge_method';
    foreach ($carried as $key) {
        expect($resumed[$key] ?? null)->toBe($sent[$key] ?? null);
    }
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
        'tax'           => 0,
        'total'         => 2000,
        'amount_paid'   => 2000,
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

    // QuickBooks works out the tax; Fleetbase takes it, so the payment of 20.00 leaves the tax owing.
    $tax = sandboxCents($remoteInvoice['TxnTaxDetail']['TotalTax'] ?? 0);

    expect($remoteCustomer['DisplayName'] ?? null)->toBe('Fleetbase Test Engine ' . $tag)
        ->and(sandboxCents($remoteInvoice['TotalAmt'] ?? null))->toBe(2000 + $tax)
        ->and(sandboxCents($remoteInvoice['Balance'] ?? null))->toBe($tax)
        ->and($ledger->invoices['inv-e2e']['tax'])->toBe($tax)
        ->and($ledger->invoices['inv-e2e']['total'])->toBe(2000 + $tax)
        ->and($remoteInvoice['CustomerRef']['value'] ?? null)->toBe($links['customer:cust-e2e'])
        ->and($remoteAccount['Name'] ?? null)->toBe('Fleetbase Test Engine Wallet ' . $tag);

    $paymentIds = array_values(array_map(
        fn (array $link): string => (string) $link['qbo_id'],
        array_filter($ledger->links, fn (array $link): bool => ($link['local_type'] ?? '') === 'payment')
    ));

    expect($paymentIds)->toHaveCount(1);

    $remotePayment = $client->getPayment($connection, $paymentIds[0]);
    $linkedTxnIds  = array_column(array_merge(...array_map(fn (array $line): array => $line['LinkedTxn'] ?? [], $remotePayment['Line'] ?? [])), 'TxnId');

    expect(sandboxCents($remotePayment['TotalAmt'] ?? null))->toBe(2000)
        ->and($remotePayment['CustomerRef']['value'] ?? null)->toBe($links['customer:cust-e2e'])
        ->and($linkedTxnIds)->toContain($links['invoice:inv-e2e']);
})->group('sandbox');

test('QuickBooks works out the tax of the lines Fleetbase sends and Fleetbase copies it', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    if ($client->salesTax($connection)['mode'] !== InvoiceTax::US) {
        test()->markTestSkipped('The sandbox company does not use US sales tax.');
    }
    $tag     = sandboxTag();
    $company = 'sandbox-company';
    $income  = $client->batch($connection, [[
        'bId'   => 'income',
        'query' => "select * from Account where AccountType = 'Income' maxresults 1000",
    ]]);
    $account = QuickBooksClient::serviceIncomeAccount($income['income']['rows'] ?? []);
    $item    = $client->batch($connection, [[
        'bId'       => 'item',
        'operation' => 'create',
        'entity'    => 'Item',
        'payload'   => [
            'Name'             => 'Fleetbase Test Taxable ' . $tag,
            'Type'             => 'Service',
            'Taxable'          => true,
            'IncomeAccountRef' => ['value' => (string) $account],
        ],
    ]]);
    $itemId = (string) ($item['item']['body']['Id'] ?? '');

    expect($account)->not->toBeNull()
        ->and($itemId)->not->toBe('');

    $backoff                               = new BackoffPolicy(static fn (int $wait): int => $wait);
    $engine                                = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        $backoff
    );
    $ledger                                = new SyncLedger();
    $ledger->connections[$company]         = $connection + ['default_item_id' => $itemId];
    $ledger->customers['cust-tax']         = [
        'uuid'         => 'cust-tax',
        'company_uuid' => $company,
        'name'         => 'Fleetbase Test Tax ' . $tag,
        'email'        => 'fbqb-tax-' . $tag . '@example.com',
    ];
    $ledger->invoices['inv-tax']           = [
        'uuid'          => 'inv-tax',
        'company_uuid'  => $company,
        'customer_uuid' => 'cust-tax',
        'number'        => 'FT' . $tag,
        'date'          => gmdate('Y-m-d'),
        'due_date'      => gmdate('Y-m-d', time() + 14 * 86400),
        'currency'      => $client->homeCurrency($connection),
        'tax'           => 0,
        'total'         => 3000,
        'status'        => 'sent',
        'items'         => [
            ['description' => 'Delivery', 'quantity' => 2, 'unit_price' => 1000, 'amount' => 2000],
            ['description' => 'Wait time', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000],
        ],
    ];
    $pending = fn (string $type, string $uuid): array => [
        'company_uuid' => $company, 'local_type' => $type, 'local_uuid' => $uuid,
        'status'       => 'pending', 'attempts' => 0,
    ];
    foreach (['customer' => 'cust-tax', 'invoice' => 'inv-tax'] as $type => $uuid) {
        $ledger->pending[] = $pending($type, $uuid);
    }
    $settings = qbSettings(['interval_minutes' => 1]);

    $first  = $engine->runScheduled($ledger, $company, $settings, time(), true);
    $errors = array_values(array_filter(array_column($ledger->attempts, 'error')));
    $link   = $ledger->link($company, (string) $connection['realm_id'], 'invoice', 'inv-tax');
    $remote = $client->getInvoice($connection, (string) ($link['qbo_id'] ?? ''));
    $tax    = sandboxCents($remote['TxnTaxDetail']['TotalTax'] ?? 0);
    $items  = $ledger->invoices['inv-tax']['items'];

    expect($errors)->toBe([])
        ->and($first['failed'] ?? 0)->toBe(0)
        ->and($remote['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'] ?? null)->toBe('TAX')
        ->and($remote['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? null)->not->toBeNull()
        ->and($tax)->toBeGreaterThan(0)
        ->and(sandboxCents($remote['TotalAmt'] ?? null))->toBe(3000 + $tax)
        ->and($ledger->invoices['inv-tax']['tax'])->toBe($tax)
        ->and($ledger->invoices['inv-tax']['total'])->toBe(3000 + $tax)
        ->and(array_sum(array_column($items, 'tax_amount')))->toBe($tax)
        ->and((float) $items[0]['tax_rate'])->toBeGreaterThan(0.0);

    // A Fleetbase edit is sent without tax; QuickBooks works it out again, and the next sync is aligned.
    $ledger->invoices['inv-tax']['items'][1]['amount']     = 1500;
    $ledger->invoices['inv-tax']['items'][1]['unit_price'] = 1500;
    $ledger->connections[$company]['last_batch_at']        = null;
    $ledger->pending                                       = [$pending('invoice', 'inv-tax')];

    $second = $engine->runScheduled($ledger, $company, $settings, time() + 5, true);
    $remote = $client->getInvoice($connection, (string) $link['qbo_id']);
    $tax    = sandboxCents($remote['TxnTaxDetail']['TotalTax'] ?? 0);

    expect($second['updated'] ?? 0)->toBe(1)
        ->and(sandboxCents($remote['TotalAmt'] ?? null))->toBe(3500 + $tax)
        ->and($ledger->invoices['inv-tax']['tax'])->toBe($tax)
        ->and($ledger->invoices['inv-tax']['total'])->toBe(3500 + $tax);

    $ledger->connections[$company]['last_batch_at'] = null;
    $ledger->pending[0]['status']                   = 'pending';

    $third = $engine->runScheduled($ledger, $company, $settings, time() + 10, true);

    expect($third['aligned'] ?? 0)->toBe(1)
        ->and($third['updated'] ?? 0)->toBe(0);
})->group('sandbox');

test('products are created, adopted, updated, made inactive and brought back, and invoice lines use them', function () {
    $client     = new QuickBooksClient();
    $connection = sandboxConnection();
    $engine     = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );
    $ledger     = new SyncLedger();
    $tag        = sandboxTag();
    $company    = 'sandbox-company';
    $serviceId  = sandboxServiceItem($client, $connection);
    $currency   = $client->homeCurrency($connection);
    $settings   = qbSettings(['interval_minutes' => 1]);
    $pending    = static fn (string $type, string $uuid): array => [
        'company_uuid' => $company, 'local_type' => $type, 'local_uuid' => $uuid,
        'status'       => 'pending', 'attempts' => 0,
    ];
    $run        = static function (int $at) use ($engine, $ledger, $company, $settings): void {
        $engine->runScheduled($ledger, $company, $settings, $at, true);
        $failed = array_filter($ledger->attempts, static fn (array $row): bool => $row['outcome'] === 'failed');

        expect(array_values(array_column($failed, 'error')))->toBe([]);
    };
    $itemOf     = static function (string $name) use ($ledger, $company): string {
        $realm = (string) $ledger->connections[$company]['realm_id'];
        $link  = $ledger->link($company, $realm, 'item', ProductMapper::itemUuid($company, $name));

        return (string) ($link['qbo_id'] ?? '');
    };

    // A QuickBooks product of the same name in another case is taken over, never written.
    $account  = (string) ($client->item($connection, $serviceId)['IncomeAccountRef']['value'] ?? '');
    $existing = $client->createItem($connection, [
        'Name'             => 'FB Adopt ' . $tag,
        'Type'             => 'Service',
        'IncomeAccountRef' => ['value' => $account],
    ]);
    $product  = static fn (string $uuid, string $name, array $more = []): array => array_merge([
        'uuid'        => $uuid, 'company_uuid' => $company, 'public_id' => $uuid, 'name' => $name,
        'description' => 'Sandbox', 'sku' => 'S' . $tag, 'price' => 1200, 'currency' => $currency,
        'is_service'  => false, 'status' => 'published',
        'deleted_at'  => null,
    ], $more);

    $ledger->connections[$company]  = $connection;
    $ledger->products['prd-mug']    = $product('prd-mug', 'FB Mug ' . $tag);
    $ledger->products['prd-adopt']  = $product('prd-adopt', 'fb adopt ' . $tag, ['sku' => '']);
    $ledger->customers['cust-prd']  = [
        'uuid'  => 'cust-prd', 'company_uuid' => $company, 'name' => 'Fleetbase Test Products ' . $tag,
        'email' => 'fbqb-products-' . $tag . '@example.com',
    ];
    $ledger->invoices['inv-prd']    = [
        'uuid'          => 'inv-prd',
        'company_uuid'  => $company,
        'customer_uuid' => 'cust-prd',
        'number'        => 'FP' . $tag,
        'date'          => gmdate('Y-m-d'),
        'due_date'      => gmdate('Y-m-d', time() + 14 * 86400),
        'currency'      => $currency,
        'tax'           => 0,
        'total'         => 2300,
        'status'        => 'sent',
        'items'         => [
            ['description' => 'FB Mug ' . $tag, 'quantity' => 1, 'unit_price' => 1200, 'amount' => 1200],
            ['description' => 'fb adopt ' . $tag, 'quantity' => 1, 'unit_price' => 500, 'amount' => 500],
            ['description' => 'Base Fee', 'quantity' => 1, 'unit_price' => 400, 'amount' => 400],
            ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 200, 'amount' => 200],
        ],
    ];
    foreach (['product' => 'prd-mug', 'customer' => 'cust-prd', 'invoice' => 'inv-prd'] as $type => $uuid) {
        $ledger->pending[] = $pending($type, $uuid);
    }
    $ledger->pending[] = $pending('product', 'prd-adopt');
    $run(time());

    $mugId   = $itemOf('FB Mug ' . $tag);
    $mug     = $client->item($connection, $mugId);
    $invoice = $client->getInvoice($connection, (string) $ledger->link(
        $company,
        (string) $connection['realm_id'],
        'invoice',
        'inv-prd'
    )['qbo_id']);
    $lineItems = [];
    foreach ($invoice['Line'] ?? [] as $line) {
        if (($line['DetailType'] ?? '') === 'SalesItemLineDetail') {
            $lineItems[] = (string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? '');
        }
    }

    expect($mug['Name'] ?? null)->toBe('FB Mug ' . $tag)
        ->and($mug['Type'] ?? null)->toBe('NonInventory')
        ->and(sandboxCents($mug['UnitPrice'] ?? null))->toBe(1200)
        ->and($mug['Sku'] ?? null)->toBe('S' . $tag)
        ->and($itemOf('fb adopt ' . $tag))->toBe((string) $existing['Id'])
        ->and($client->item($connection, (string) $existing['Id'])['SyncToken'] ?? null)->toBe($existing['SyncToken'])
        ->and($itemOf('Base Fee'))->not->toBe('')
        ->and($lineItems)->toBe([$mugId, (string) $existing['Id'], $itemOf('Base Fee'), $serviceId]);

    // A change of price and of case is sent; the product keeps its QuickBooks id.
    $ledger->products['prd-mug'] = $product('prd-mug', 'FB MUG ' . $tag, ['price' => 1500]);
    $ledger->pending[]           = $pending('product', 'prd-mug');
    $run(time() + 120);
    $mug = $client->item($connection, $mugId);

    expect($mug['Name'] ?? null)->toBe('FB MUG ' . $tag)
        ->and(sandboxCents($mug['UnitPrice'] ?? null))->toBe(1500);

    // The last product of the name gone makes it inactive; brought back, it has its name again.
    $ledger->products['prd-mug']['deleted_at'] = gmdate('Y-m-d H:i:s');
    $ledger->pending[]                         = $pending('product', 'prd-mug');
    $run(time() + 240);
    $gone = $client->item($connection, $mugId);

    expect($gone['Active'] ?? null)->toBeFalse();

    $ledger->products['prd-mug']['deleted_at'] = null;
    $ledger->pending[]                         = $pending('product', 'prd-mug');
    $run(time() + 360);
    $back = $client->item($connection, $mugId);

    expect($back['Active'] ?? null)->toBeTrue()
        ->and($back['Name'] ?? null)->toBe('FB MUG ' . $tag)
        ->and($itemOf('FB Mug ' . $tag))->toBe($mugId);
})->group('sandbox');
