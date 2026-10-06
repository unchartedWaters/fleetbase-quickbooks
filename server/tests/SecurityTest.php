<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\CustomerImporter;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function securitySettingController(MemorySettingsStore $store, ?callable $checker = null): SettingController
{
    return new SettingController(
        new Authorizer($checker ?? static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
}

function securityConnectionController(OAuthFlow $flow): ConnectionController
{
    return new ConnectionController(
        new Authorizer(static fn () => true),
        $flow,
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new ConnectionProbe(new QuickBooksClient())
    );
}

function securityRequest(string $method, array $input, bool $isAdmin): Request
{
    $request = Request::create('/settings', $method, $input);
    $request->setUserResolver(static fn () => new class($isAdmin) {
        public function __construct(private bool $admin)
        {
        }

        public function isAdmin(): bool
        {
            return $this->admin;
        }
    });

    return $request;
}

function securityStatus(callable $action): int
{
    try {
        $action();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return 200;
}

test('install settings are the admin scope and an organization scope is not a settings screen', function () {
    session(['company' => 'company-uuid']);
    $store               = new MemorySettingsStore();
    $operatorPermissions = [];
    foreach ((new Quickbooks())->policies as $policy) {
        if (($policy['name'] ?? '') === 'QuickbooksOperator') {
            $operatorPermissions = $policy['permissions'];
        }
    }
    $operator = securitySettingController(
        $store,
        static fn (string $permission): bool => in_array($permission, $operatorPermissions, true)
    );
    $denied      = securitySettingController($store, static fn (): bool => false);
    $companySave = [
        'scope' => 'company',
        'auth'  => ['client_id' => 'x'],
        'sync'  => ['override' => true],
    ];

    try {
        expect($operatorPermissions)->toContain('quickbooks update settings')
            ->and(securityStatus(fn () => $operator->show(securityRequest('GET', ['scope' => 'company'], true))))->toBe(404)
            ->and(securityStatus(fn () => $operator->save(securityRequest('POST', $companySave, false))))->toBe(404)
            ->and(securityStatus(fn () => $denied->save(securityRequest('POST', $companySave, false))))->toBe(403)
            ->and($store->rows)->toBe([])
            ->and($store->adminAuth())->toBe([])
            ->and(securityStatus(fn () => $denied->show(securityRequest('GET', ['scope' => 'admin'], false))))->toBe(403)
            ->and(securityStatus(fn () => $operator->show(securityRequest('GET', ['scope' => 'admin'], false))))->toBe(200)
            ->and(securityStatus(fn () => $denied->show(securityRequest('GET', ['scope' => 'admin'], true))))->toBe(200);
    } finally {
        session(['company' => null]);
    }
});

test('a request without a session organization is refused', function () {
    session(['company' => null]);
    $controller = securitySettingController(new MemorySettingsStore());

    expect(securityStatus(fn () => $controller->show(Request::create('/settings', 'GET', ['company_uuid' => 'company-uuid']))))->toBe(403);
});

test('saving settings keeps stored auth fields that were not sent', function () {
    session(['company' => 'company-uuid']);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'client_id'     => 'old-id',
        'client_secret' => 'kept-secret',
        'redirect_uri'  => 'https://example.com/callback',
        'environment'   => 'sandbox',
    ];

    try {
        $response = securitySettingController($store)->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => ['client_id' => 'new-id', 'client_secret' => ''],
            'sync'  => qbSettings(['override' => true]),
        ]));
        $stored = $store->rows[SettingsKeys::adminAuth()];

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->not->toContain('kept-secret')
            ->and($stored['client_id'])->toBe('new-id')
            ->and($stored['client_secret'])->not->toBe('kept-secret')
            ->and((new SecretCipher())->decrypt($stored['client_secret']))->toBe('kept-secret')
            ->and($stored['redirect_uri'])->toBe('https://example.com/callback')
            ->and($stored['environment'])->toBe('sandbox');
    } finally {
        session(['company' => null]);
    }
});

test('an authorization started by another organization cannot be completed by the victim and the victim cannot burn it', function () {
    config()->set('quickbooks.console_host', 'https://console.example.test');
    $flow  = new OAuthFlow(new QuickBooksClient());
    $begun = $flow->begin('attacker-company', 'attacker-user', [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ]);
    $controller = securityConnectionController($flow);

    // The victim's browser arrives with the attacker's state and the victim's code.
    $redirect = $controller->callback(Request::create('/oauth/callback', 'GET', [
        'state'   => $begun['state'],
        'code'    => 'victim-code',
        'realmId' => 'victim-realm',
    ]));
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);
    $handle = (string) $query['oauth_state'];

    session(['company' => 'victim-company', 'user' => 'victim-user']);
    try {
        $refused = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => $handle]));
        expect($refused->getStatusCode())->toBe(422)
            ->and($refused->getData(true)['message'])->toContain('different user or organization')
            ->and($refused->getData(true)['message'])->not->toContain('cURL');
    } finally {
        session(['company' => null, 'user' => null]);
    }

    // A wrong user must not consume the handle or its PKCE verifier.
    $pending = Cache::get('quickbooks.oauth-state.' . $handle);
    expect($pending)->toBeArray()
        ->and($pending['company_uuid'])->toBe('attacker-company')
        ->and($pending['user_uuid'])->toBe('attacker-user')
        ->and($pending['code'])->toBe('victim-code')
        ->and($pending['code_verifier'])->toBeString()
        ->and($pending['code_verifier'])->not->toBe('');

    session(['company' => 'attacker-company', 'user' => 'attacker-user']);
    try {
        $replay = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => $begun['state']]));
        expect($replay->getStatusCode())->toBe(422)
            ->and($replay->getData(true)['message'])->toBe('QuickBooks authorization state is invalid or expired.');
    } finally {
        session(['company' => null, 'user' => null]);
    }
});

test('a cancelled authorization forgets the state and says it was cancelled', function () {
    config()->set('quickbooks.console_host', 'https://console.example.test');
    $flow  = new OAuthFlow(new QuickBooksClient());
    $begun = $flow->begin('company-uuid', 'user-uuid', [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ]);

    $redirect = securityConnectionController($flow)->callback(Request::create('/oauth/callback', 'GET', [
        'state' => $begun['state'],
        'error' => 'access_denied',
    ]));

    expect($redirect->getTargetUrl())->toBe('https://console.example.test/quickbooks?error=cancelled')
        ->and(fn () => $flow->receive($begun['state'], 'code', 'realm'))->toThrow(QuickBooksException::class);
});

test('an intuit error sends only a fixed code back to the console', function () {
    config()->set('quickbooks.console_host', 'https://console.example.test');

    $redirect = securityConnectionController(new OAuthFlow(new QuickBooksClient()))
        ->callback(Request::create('/oauth/callback', 'GET', [
            'state'             => 'bogus',
            'error'             => 'Your account was locked, call 555-0100',
            'error_description' => '<b>Call support</b>',
        ]));

    expect($redirect->getTargetUrl())->toBe('https://console.example.test/quickbooks?error=failed');
});

test('the quickbooks console host is used before fleetbase.console.host', function () {
    config()->set('fleetbase.console.host', 'https://other.example.test');
    config()->set('quickbooks.console_host', 'http://localhost:4200');

    $redirect = securityConnectionController(new OAuthFlow(new QuickBooksClient()))
        ->callback(Request::create('/oauth/callback', 'GET', ['state' => 'bogus']));

    expect($redirect->getTargetUrl())->toBe('http://localhost:4200/quickbooks?error=state');
});

test('fleetbase.console.host is used when the quickbooks console host is unset', function () {
    config()->set('quickbooks.console_host', null);
    config()->set('fleetbase.console.host', 'console.example.test');

    $redirect = securityConnectionController(new OAuthFlow(new QuickBooksClient()))
        ->callback(Request::create('/oauth/callback', 'GET', ['state' => 'bogus']));

    expect($redirect->getTargetUrl())->toBe('https://console.example.test/quickbooks?error=state');
});

test('a console host keeps its http scheme', function () {
    config()->set('quickbooks.console_host', 'http://10.30.0.34:4200/');

    $redirect = securityConnectionController(new OAuthFlow(new QuickBooksClient()))
        ->callback(Request::create('/oauth/callback', 'GET', ['state' => 'bogus']));

    expect($redirect->getTargetUrl())->toBe('http://10.30.0.34:4200/quickbooks?error=state');
});

test('the callback refuses to redirect when only the fleetbase.io default host is known', function () {
    config()->set('quickbooks.console_host', null);
    config()->set('fleetbase.console.host', 'fleetbase.io');

    $response = securityConnectionController(new OAuthFlow(new QuickBooksClient()))
        ->callback(Request::create('/oauth/callback', 'GET', ['state' => 'bogus']));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe(['message' => 'Set CONSOLE_HOST so QuickBooks can return to the console.']);
});

test('string booleans in sync settings are read as booleans', function () {
    $resolver = new SyncSettingsResolver();

    $off      = $resolver->resolve(['enabled' => true], ['override' => true, 'enabled' => 'false'], ['enabled' => true]);
    $fallback = $resolver->resolve(['enabled' => '0'], ['override' => true, 'enabled' => 'nonsense'], ['enabled' => true]);

    expect($off['enabled'])->toBeFalse()
        ->and($off['sources']['enabled'])->toBe('admin')
        ->and($off)->not->toHaveKey('override')
        ->and($fallback['enabled'])->toBeTrue()
        ->and($fallback['sources']['enabled'])->toBe('default');
});

test('the reauth notification carries only the organization and realm', function () {
    $notification = new QuickbooksNeedsReauth('company-uuid', 'realm-1');

    expect($notification->toArray(new stdClass()))->toBe([
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'message'      => 'QuickBooks needs to be reconnected before sync can continue.',
    ])
        ->and($notification->via(new stdClass()))->toBe($notification->notificationOptions);
});

test('the global verifier accepts a realm and another organization verifier is rejected', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store                                               = webhookAdminStore($settings, 'verifier-a');
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'client_secret'    => 'secret-a',
            'webhook_verifier' => 'verifier-a',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'client_secret'    => 'secret-b',
            'webhook_verifier' => 'verifier-b',
        ], []);
        $controller = webhookController($settings, $store, [
            'realm-1' => 'company-a',
            'realm-2' => 'company-b',
        ]);

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-a')));
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($events)->toHaveCount(1)
            ->and($events[0]->companyUuid)->toBe('company-a')
            ->and($events[0]->realmId)->toBe('realm-1');

        withWebhookDispatcher(function ($dispatcher) use ($controller, $body) {
            $rejected       = null;
            $rejectedEvents = webhookSeenEvents(function () use ($controller, $body, &$rejected) {
                $rejected = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-b')));
            });

            expect($rejected->getStatusCode())->toBe(401)
                ->and($rejectedEvents)->toBe([])
                ->and($dispatcher->jobs)->toBe([]);
        });
    });
});

test('a failed webhook check does not decrypt every company client secret', function () {
    $cipher = new class extends SecretCipher {
        /** @var array<int, string> */
        public array $decrypted = [];

        public function decrypt(string $payload): string
        {
            $this->decrypted[] = $payload;

            return parent::decrypt($payload);
        }
    };
    $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), $cipher);
    $body     = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

    withoutWebhookVerifier(function () use ($cipher, $settings, $body) {
        $store                                               = new MemorySettingsStore();
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'client_secret'    => 'secret-a',
            'webhook_verifier' => 'verifier-a',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'client_secret'    => 'secret-b',
            'webhook_verifier' => 'verifier-b',
        ], []);
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth([
            'client_secret'    => 'admin-secret',
            'webhook_verifier' => 'admin-verifier',
        ], []);
        $store->asked = [];
        $controller   = webhookController($settings, $store, ['realm-1' => 'company-a']);

        withWebhookDispatcher(function ($dispatcher) use ($cipher, $controller, $store, $body) {
            $response = null;
            $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
                $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-b')));
            });
            $company = $store->rows[SettingsKeys::companyAuth('company-a')];
            $other   = $store->rows[SettingsKeys::companyAuth('company-b')];
            $admin   = $store->rows[SettingsKeys::adminAuth()];

            expect($response->getStatusCode())->toBe(401)
                ->and($events)->toBe([])
                ->and($dispatcher->jobs)->toBe([])
                ->and($cipher->decrypted)->toContain($admin['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($company['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($company['client_secret'])
                ->and($cipher->decrypted)->not->toContain($other['client_secret'])
                ->and($cipher->decrypted)->not->toContain($other['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($admin['client_secret'])
                ->and($store->asked)->toBe([SettingsKeys::adminAuth()]);
        });
    });
});

if (!function_exists('qbEnsureCustomerTable')) {
    function qbEnsureCustomerTable(): void
    {
        $config = config();
        if (is_object($config) && method_exists($config, 'set')) {
            $config->set('fleetbase.connection.db', 'sqlite');
        }
        $database = app('db');
        if (!is_object($database) || !method_exists($database, 'connection')) {
            return;
        }
        $schema = $database->connection('sqlite')->getSchemaBuilder();
        if ($schema->hasTable('contacts')) {
            return;
        }
        $schema->create('contacts', function ($table): void {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('internal_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta')->nullable();
            $table->string('slug')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

test('imported contacts get a fresh uuid rather than one built from the quickbooks id', function () {
    $previous = config('fleetbase.connection.db');
    qbEnsureCustomerTable();
    $client                = new FakeQuickBooks();
    $client->customerPages = [1 => [['Id' => '1', 'DisplayName' => 'Ada', 'SyncToken' => '0']]];
    $ledger                = new SyncLedger();
    $connection            = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1'];

    (new CustomerImporter($client))->import($ledger, $connection, [], true);
    $uuid = (string) array_key_first($ledger->customers);

    expect(Str::isUuid($uuid))->toBeTrue();
    $config = config();
    if (is_object($config) && method_exists($config, 'set')) {
        $config->set('fleetbase.connection.db', $previous);
    }
});
