<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('global credentials are used and an organization row does not replace them', function () {
    $resolved = (new CredentialResolver())->resolve(
        ['client_id' => 'company-id', 'client_secret' => 'company-secret', 'redirect_uri' => 'https://company.example.test/callback', 'environment' => 'sandbox', 'webhook_verifier' => 'company-verifier'],
        ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'environment' => '', 'webhook_verifier' => ''],
        ['client_id' => 'env-id', 'client_secret' => 'env-secret', 'redirect_uri' => 'https://example.test/callback', 'environment' => 'sandbox', 'webhook_verifier' => 'env-verifier']
    );

    expect($resolved['client_id'])->toBe('')
        ->and($resolved['sources']['client_id'])->toBe('none')
        ->and($resolved['client_secret'])->toBe('')
        ->and($resolved['sources']['client_secret'])->toBe('none')
        ->and($resolved['redirect_uri'])->toBe('')
        ->and($resolved['sources']['redirect_uri'])->toBe('none')
        ->and($resolved['webhook_verifier'])->toBe('')
        ->and($resolved['sources']['webhook_verifier'])->toBe('none')
        ->and($resolved['environment'])->toBe('sandbox')
        ->and($resolved['sources']['environment'])->toBe('env');
});

test('global credentials resolve from the system row when there is no session', function () {
    expect(SettingsKeys::companyAuth('company-uuid'))->toBe('company.company-uuid.quickbooks.auth')
        ->and(SettingsKeys::adminAuth())->toBe('system.quickbooks.auth');

    $resolved = (new CredentialResolver())->resolve(
        ['client_id' => 'from-company-row'],
        ['client_id' => 'from-system-row'],
        ['client_id' => 'from-env']
    );

    expect($resolved['client_id'])->toBe('from-system-row')
        ->and($resolved['sources']['client_id'])->toBe('admin');
});

test('the client secret is encrypted at rest and is not returned by the settings api', function () {
    $cipher   = new SecretCipher();
    $settings = new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), $cipher);
    $stored   = $settings->storeAuth(['client_id' => 'id', 'client_secret' => 'plain-secret'], []);

    expect($stored['client_secret'])->not->toBe('plain-secret')
        ->and($cipher->decrypt($stored['client_secret']))->toBe('plain-secret');

    $browser = $settings->forBrowser($stored);
    expect($browser)->not->toHaveKey('client_secret')
        ->and($browser['client_secret_set'])->toBeTrue();

    $request = Request::create('/settings', 'POST', [
        'company_uuid' => 'company-uuid',
        'auth'         => ['client_id' => 'id', 'client_secret' => 'plain-secret'],
    ]);
    $controller = new SettingController(
        new Authorizer(static fn () => false),
        $settings,
        new SettingsStore()
    );

    expect(fn () => $controller->save($request))->toThrow(HttpException::class);
});

test('secrets saved with the old cipher still open and a missing app key is refused', function () {
    $cipher = new SecretCipher();

    // Build a payload the way the old AES-256-CBC cipher did.
    $appKey = (string) config('app.key');
    $key    = substr(hash('sha256', base64_decode(substr($appKey, 7), true), true), 0, 32);
    $iv     = random_bytes(16);
    $legacy = base64_encode($iv . openssl_encrypt('old-secret', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));

    $settings = new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), $cipher);
    $upgraded = $settings->storeAuth(['client_secret' => ''], ['client_secret' => $legacy]);
    $kept     = $settings->storeAuth(['client_secret' => ''], ['client_secret' => $upgraded['client_secret']]);

    expect($cipher->decrypt($legacy))->toBe('old-secret')
        ->and($cipher->isLegacyCiphertext($legacy))->toBeTrue()
        ->and($cipher->decrypt($cipher->encrypt('new-secret')))->toBe('new-secret')
        ->and($cipher->isLegacyCiphertext($cipher->encrypt('kept-secret')))->toBeFalse()
        ->and($upgraded['client_secret'])->not->toBe($legacy)
        ->and($cipher->isLegacyCiphertext($upgraded['client_secret']))->toBeFalse()
        ->and($cipher->decrypt($upgraded['client_secret']))->toBe('old-secret')
        ->and($kept['client_secret'])->toBe($upgraded['client_secret'])
        ->and(fn () => $cipher->decrypt('not-a-secret'))->toThrow(RuntimeException::class);

    config()->set('app.key', '');
    try {
        expect(fn () => $cipher->encrypt('x'))->toThrow(RuntimeException::class);
    } finally {
        config()->set('app.key', $appKey);
    }
});

test('a long base64 secret is not legacy ciphertext', function () {
    $cipher  = new SecretCipher();
    $plain   = 'client-secret-value-not-ciphertext';
    $encoded = base64_encode($plain);

    expect(strlen($plain))->toBeGreaterThan(17)
        ->and($cipher->isLegacyCiphertext($encoded))->toBeFalse()
        ->and($cipher->upgrade($encoded))->toBe($encoded)
        ->and(fn () => $cipher->decrypt($encoded))->toThrow(function (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Unable to decrypt QuickBooks secret.');
        });
});

test('saving a connection re-encrypts a legacy token and leaves a crypt token alone', function () {
    $cipher = new SecretCipher();
    $key    = substr(hash('sha256', base64_decode(substr((string) config('app.key'), 7), true), true), 0, 32);
    $iv     = random_bytes(16);
    $legacy = base64_encode($iv . openssl_encrypt('old-access', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
    $crypt  = $cipher->encrypt('refresh-plain');

    $connection = new Fleetbase\Quickbooks\Models\Connection();
    $connection->setRawAttributes([
        'access_token'  => $legacy,
        'refresh_token' => $crypt,
    ]);

    try {
        $connection->save();
    } catch (Throwable) {
        // The row is not written in this harness. The upgrade runs before the query.
    }

    $stored = $connection->getAttributes();

    expect($stored['access_token'])->not->toBe($legacy)
        ->and($cipher->isLegacyCiphertext($stored['access_token']))->toBeFalse()
        ->and($cipher->decrypt($stored['access_token']))->toBe('old-access')
        ->and($stored['refresh_token'])->toBe($crypt)
        ->and($connection->access_token)->toBe('old-access')
        ->and($connection->refresh_token)->toBe('refresh-plain');
});

test('an unrecognized token is not a live secret and plaintext is encrypted on the next save', function () {
    $cipher                                 = new SecretCipher();
    $settings                               = new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), $cipher);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'client_id'        => 'id',
        'client_secret'    => 'plain-secret',
        'webhook_verifier' => 'plain-verifier',
        'redirect_uri'     => 'https://example.test/callback',
        'environment'      => 'sandbox',
    ];
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        $settings,
        $store
    );

    session(['company' => 'company-uuid']);
    try {
        $response = $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => [
                'client_id'        => 'id',
                'client_secret'    => '',
                'webhook_verifier' => '',
                'redirect_uri'     => 'https://example.test/callback',
                'environment'      => 'sandbox',
            ],
            'sync' => qbSettings(),
        ]));
        $stored = $store->rows[SettingsKeys::adminAuth()];
        $body   = $response->getContent();

        expect($response->getStatusCode())->toBe(200)
            ->and($body)->not->toContain('plain-secret')
            ->and($body)->not->toContain('plain-verifier')
            ->and($body)->not->toContain('access_token')
            ->and($body)->not->toContain('refresh_token')
            ->and($stored['client_secret'])->not->toBe('plain-secret')
            ->and($cipher->decrypt($stored['client_secret']))->toBe('plain-secret')
            ->and($stored['webhook_verifier'])->not->toBe('plain-verifier')
            ->and($cipher->decrypt($stored['webhook_verifier']))->toBe('plain-verifier')
            ->and($settings->credentialsFor($store, 'company-uuid')['client_secret'])->toBe('plain-secret');
    } finally {
        session(['company' => null]);
    }

    $connection = new Fleetbase\Quickbooks\Models\Connection();
    $connection->setRawAttributes([
        'access_token'  => 'plain-access',
        'refresh_token' => 'plain-refresh',
    ]);

    expect($connection->access_token)->toBeNull()
        ->and($connection->refresh_token)->toBeNull();

    try {
        $connection->save();
    } catch (Throwable) {
        // The row is not written in this harness. The upgrade runs before the query.
    }

    $tokens = $connection->getAttributes();

    expect($tokens['access_token'])->not->toBe('plain-access')
        ->and($cipher->decrypt($tokens['access_token']))->toBe('plain-access')
        ->and($tokens['refresh_token'])->not->toBe('plain-refresh')
        ->and($cipher->decrypt($tokens['refresh_token']))->toBe('plain-refresh')
        ->and($connection->access_token)->toBe('plain-access')
        ->and($connection->refresh_token)->toBe('plain-refresh');
});

test('a failed decrypt or unrecognized secret is missing and legacy ciphertext still opens', function () {
    $cipher      = new SecretCipher();
    $settings    = new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), $cipher);
    $payload     = $cipher->encrypt('real-secret');
    $json        = json_decode(base64_decode($payload), true);
    $json['mac'] = str_repeat('ab', 32);
    $tampered    = base64_encode((string) json_encode($json));
    $appKey      = (string) config('app.key');
    $key         = substr(hash('sha256', base64_decode(substr($appKey, 7), true), true), 0, 32);
    $iv          = random_bytes(16);
    $legacy      = base64_encode($iv . openssl_encrypt('old-secret', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));

    $unrecognized = $settings->resolveAuth([], [
        'client_secret'    => 'not-a-secret',
        'webhook_verifier' => $tampered,
    ], []);
    $opened = $settings->resolveAuth([], [
        'client_secret'    => $legacy,
        'webhook_verifier' => $cipher->encrypt('verifier-token'),
    ], []);
    $connection = new Fleetbase\Quickbooks\Models\Connection();
    $connection->setRawAttributes([
        'access_token'  => 'not-a-token',
        'refresh_token' => $legacy,
    ]);

    expect($unrecognized['client_secret'])->toBe('')
        ->and($unrecognized['client_secret'])->not->toBe('not-a-secret')
        ->and($unrecognized['webhook_verifier'])->toBe('')
        ->and($unrecognized['webhook_verifier'])->not->toBe($tampered)
        ->and($opened['client_secret'])->toBe('old-secret')
        ->and($opened['webhook_verifier'])->toBe('verifier-token')
        ->and($connection->access_token)->toBeNull()
        ->and($connection->refresh_token)->toBe('old-secret');
});

test('a controller action without quickbooks update settings returns 403', function () {
    $request    = Request::create('/settings', 'POST', []);
    $controller = new SettingController(
        new Authorizer(static fn () => false),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new SettingsStore()
    );

    try {
        $controller->save($request);
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

test('disconnect without quickbooks disconnect connection returns 403', function () {
    $checked    = [];
    $controller = new ConnectionController(
        new Authorizer(function (string $permission) use (&$checked): bool {
            $checked[] = $permission;

            return false;
        }),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new SettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        $controller->disconnect(Request::create('/disconnect', 'POST', ['company_uuid' => 'company-uuid']));
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and($checked)->toBe(['quickbooks disconnect connection']);
    }
});

test('disconnect deletes only the signed-in company connection and does not call Intuit', function () {
    $routes       = (string) file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $protectedAt  = strpos($routes, "'middleware' => ['fleetbase.protected']");
    $disconnectAt = strpos($routes, "\$router->post('disconnect', [ConnectionController::class, 'disconnect']);");
    expect($protectedAt)->not->toBeFalse()
        ->and($disconnectAt)->toBeGreaterThan($protectedAt);

    $connection = new class(new class extends PDO {
        public function __construct()
        {
        }
    }, 'testing', '', ['name' => 'testing']) extends Connection {
        /** @var array<int, array{query: string, bindings: array<int, mixed>}> */
        public array $deletes = [];

        public function delete($query, $bindings = [])
        {
            $this->deletes[] = ['query' => $query, 'bindings' => $bindings];

            return 1;
        }
    };

    $resolver = new ConnectionResolver(['testing' => $connection]);
    $resolver->setDefaultConnection('testing');
    $previous = Model::getConnectionResolver();
    Model::setConnectionResolver($resolver);

    $checked    = [];
    $controller = new ConnectionController(
        new Authorizer(function (string $permission) use (&$checked): bool {
            $checked[] = $permission;

            return true;
        }),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new SettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    session(['company' => 'company-uuid']);
    Http::fake();

    try {
        try {
            $controller->disconnect(Request::create('/disconnect', 'POST', ['company_uuid' => 'other-company']));
            expect(false)->toBeTrue();
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }

        expect($connection->deletes)->toBe([]);

        $response = $controller->disconnect(Request::create('/disconnect', 'POST', ['company_uuid' => 'company-uuid']));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe(['disconnected' => true])
            ->and($checked)->toBe(['quickbooks disconnect connection', 'quickbooks disconnect connection'])
            ->and($connection->deletes)->toHaveCount(1)
            ->and($connection->deletes[0]['query'])->toContain('quickbooks_connections')
            ->and($connection->deletes[0]['bindings'])->toBe([])
            ->and(Http::recorded())->toHaveCount(0);
    } finally {
        if ($previous === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($previous);
        }
        session(['company' => null]);
    }
});

test('viewing a connection without permission returns 403', function () {
    $controller = new ConnectionController(
        new Authorizer(static fn () => false),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new SettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        $controller->show(Request::create('/connection', 'GET', ['company_uuid' => 'company-uuid']));
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

test('a request naming another company is rejected with 403', function () {
    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new SettingsStore()
    );

    try {
        $controller->show(Request::create('/settings', 'GET', ['company_uuid' => 'someone-elses-company']));
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    } finally {
        session(['company' => null]);
    }
});

test('the session company is used when the request sends no company uuid', function () {
    session(['company' => 'company-uuid']);
    $store      = new MemorySettingsStore();
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $controller->show(Request::create('/settings', 'GET'));
        expect($store->asked)->toContain(SettingsKeys::adminSync())
            ->and($store->asked)->toContain(SettingsKeys::adminAuth());
    } finally {
        session(['company' => null]);
    }
});

test('oauth start refuses a private callback and sends a public https redirect', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig([
        'app.url',
        'fleetbase.url',
        'fleetbase.console.host',
        'quickbooks.console_host',
        'quickbooks.redirect_uri',
        'quickbooks.client_id',
        'quickbooks.client_secret',
    ]);
    config()->set('app.url', 'http://localhost:8000');
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', 'http://10.30.0.34:4200');
    config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
    config()->set('quickbooks.redirect_uri', '');
    config()->set('quickbooks.client_id', '');
    config()->set('quickbooks.client_secret', '');
    $computed                                               = 'http://10.30.0.34:8000/quickbooks/int/v1/oauth/callback';
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()]                 = [
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        $store,
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        expect(SettingController::internalOAuthRedirectUrl())->toBe($computed);

        $start = static fn (): JsonResponse => $controller->start(Request::create('/oauth/start', 'POST', [
            'company_uuid' => 'company-uuid',
        ]));

        foreach ([
            null,
            'https://example.test/callback',
            'http://10.30.0.34:4200/quickbooks',
        ] as $redirect) {
            if ($redirect === null) {
                unset($store->rows[SettingsKeys::adminAuth()]['redirect_uri']);
            } else {
                $store->rows[SettingsKeys::adminAuth()]['redirect_uri'] = $redirect;
            }
            unset($store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url']);
            $refused = $start();
            expect($refused->getStatusCode())->toBe(422)
                ->and($refused->getContent())->not->toContain('10.30.0.34')
                ->and($refused->getContent())->not->toContain('localhost');
        }

        foreach ([
            'http://public.example.test/callback',
            'https://10.0.0.5/callback',
            'https://192.168.1.9/callback',
            'https://172.16.5.5/callback',
            'https://127.0.0.2/callback',
            'https://localhost/callback',
            'not-a-url',
        ] as $redirect) {
            $store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url'] = $redirect;
            $refused                                                             = $start();
            expect($refused->getStatusCode())->toBe(422)
                ->and($refused->getContent())->not->toContain($redirect);
        }

        $store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url'] = 'https://public.example.test/quickbooks/int/v1/oauth/callback';
        $public                                                              = qbOAuthRedirect($start());
        expect($public)->toBe('https://public.example.test/quickbooks/int/v1/oauth/callback');

        config()->set('app.url', 'https://api.example.test');
        unset($store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url']);
        expect(qbOAuthRedirect($start()))->toBe('https://api.example.test/quickbooks/int/v1/oauth/callback');
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('a path-only computed callback uses a full public oauth url and otherwise stays internal', function () {
    session(['company' => 'company-uuid']);
    $previous = qbRememberConfig([
        'app.url',
        'fleetbase.url',
        'fleetbase.console.host',
        'quickbooks.console_host',
        'quickbooks.redirect_uri',
    ]);
    config()->set('app.url', null);
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', null);
    config()->set('quickbooks.console_host', null);
    config()->set('quickbooks.redirect_uri', '');
    $public                                                 = 'https://books.example.test/quickbooks/int/v1/oauth/callback';
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()]                 = [
        'client_id'                  => 'client-id',
        'client_secret'              => (new SecretCipher())->encrypt('secret'),
        'redirect_uri'               => $public,
        'public_oauth_redirect_url'  => $public,
        'environment'                => 'sandbox',
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        $store,
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        expect(SettingController::internalOAuthRedirectUrl())->toBe('/quickbooks/int/v1/oauth/callback');

        $kept = qbOAuthRedirect($controller->start(Request::create('/oauth/start', 'POST', [
            'company_uuid' => 'company-uuid',
        ])));
        expect($kept)->toBe($public);

        unset($store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url']);
        $redirectOnly = $controller->start(Request::create('/oauth/start', 'POST', [
            'company_uuid' => 'company-uuid',
        ]));
        expect($redirectOnly->getStatusCode())->toBe(422);

        $store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url']                 = 'not-a-url';
        $invalid                                                                             = $controller->start(Request::create('/oauth/start', 'POST', [
            'company_uuid' => 'company-uuid',
        ]));
        expect($invalid->getStatusCode())->toBe(422);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null]);
    }
});

test('oauth complete exchanges the code with the same computed callback', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig([
        'app.url',
        'fleetbase.url',
        'fleetbase.console.host',
        'quickbooks.console_host',
        'quickbooks.redirect_uri',
    ]);
    config()->set('app.url', 'https://api.example.test');
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', 'http://10.30.0.34:4200');
    config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
    config()->set('quickbooks.redirect_uri', '');
    $computed = 'https://api.example.test/quickbooks/int/v1/oauth/callback';
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token'  => 'access',
            'refresh_token' => 'refresh',
            'expires_in'    => 3600,
        ], 200),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'CompanyInfo'   => ['CompanyName' => 'unchartedWaters', 'Country' => 'US'],
            'Preferences'   => ['CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]],
            'Item'          => ['Id' => '7'],
            'QueryResponse' => ['Item' => [], 'Account' => [['Id' => '79', 'AccountType' => 'Income']]],
        ], 200),
    ]);
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()]                 = [
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
    ];
    $controller = new class(new Authorizer(static fn () => true), new OAuthFlow(new QuickBooksClient()), new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()), $store, new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())) extends ConnectionController {
        /** @var array<string, mixed> */
        public array $saved = [];

        public bool $stored = false;

        protected function persist(array $connection): void
        {
            $this->saved  = $connection;
            $this->stored = true;
        }

        protected function connectionIsStored(string $companyUuid): bool
        {
            return $this->stored;
        }
    };

    try {
        $started = $controller->start(Request::create('/oauth/start', 'POST', [
            'company_uuid' => 'company-uuid',
        ]));
        expect(qbOAuthRedirect($started))->toBe($computed);
        $state = (string) $started->getData(true)['state'];

        $callback = $controller->callback(Request::create('/oauth/callback', 'GET', [
            'state'   => $state,
            'code'    => 'code',
            'realmId' => 'realm-1',
        ]));
        expect($callback->getTargetUrl())->toStartWith('http://10.30.0.34:4200/quickbooks?oauth_state=');
        parse_str((string) parse_url($callback->getTargetUrl(), PHP_URL_QUERY), $query);

        $completed = null;
        $jobs      = qbCaptureDispatches(function () use ($controller, $query, &$completed): void {
            $completed = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'state' => (string) $query['oauth_state'],
            ]));
        });
        $exchanges = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));
        parse_str($exchanges[0][0]->body(), $form);
        $syncs = array_values(array_filter($jobs, fn ($job) => $job instanceof SyncCompanyBatch));

        expect($completed->getStatusCode())->toBe(200)
            ->and($completed->getData(true))->toBe(['connected' => true])
            ->and($form['redirect_uri'])->toBe($computed)
            ->and($controller->saved['realm_id'])->toBe('realm-1')
            ->and($syncs)->toHaveCount(1)
            ->and($syncs[0]->companyUuid)->toBe('company-uuid')
            ->and($syncs[0]->trigger)->toBe('now')
            ->and(array_filter($jobs, fn ($job) => $job instanceof ImportCustomers))->toBe([]);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('the oauth callback route stays a public get', function () {
    $routes      = (string) file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $callbackAt  = strpos($routes, "\$router->get('v1/oauth/callback', [ConnectionController::class, 'callback']);");
    $protectedAt = strpos($routes, "'middleware' => ['fleetbase.protected']");

    expect($callbackAt)->not->toBeFalse()
        ->and($protectedAt)->not->toBeFalse()
        ->and($callbackAt)->toBeLessThan($protectedAt);
});

test('oauth start rejects missing client id or redirect uri', function () {
    session(['company' => 'company-uuid']);
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    $response = $controller->start(Request::create('/oauth/start', 'POST', [
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('Client ID')
        ->and($response->getData(true)['message'])->toContain('Client secret');
    session(['company' => null]);
});

test('the oauth callback rejects an unknown state and sends the browser back to the console', function () {
    config()->set('quickbooks.console_host', 'https://console.example.test');
    $controller = new ConnectionController(
        new Authorizer(static fn () => false),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new SettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    $response = $controller->callback(Request::create('/oauth/callback', 'GET', ['state' => 'bogus', 'code' => 'code', 'realmId' => 'realm']));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe('https://console.example.test/quickbooks?error=state');
});

test('the oauth callback only keeps the code and the user who started the flow completes it', function () {
    config()->set('quickbooks.console_host', 'https://console.example.test');
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token'  => 'access',
            'refresh_token' => 'refresh',
            'expires_in'    => 3600,
        ], 200),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'CompanyInfo'   => ['CompanyName' => 'unchartedWaters', 'Country' => 'US'],
            'Preferences'   => ['CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]],
            'Item'          => ['Id' => '7'],
            'QueryResponse' => ['Item' => [], 'Account' => [['Id' => '79', 'AccountType' => 'Income']]],
        ], 200),
    ]);

    $flow  = new OAuthFlow(new QuickBooksClient());
    $begun = $flow->begin('company-uuid', 'user-uuid', [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ]);

    $controller = new class(new Authorizer(static fn () => true), $flow, new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()), new MemorySettingsStore(), new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())) extends ConnectionController {
        /** @var array<string, mixed> */
        public array $saved = [];

        public bool $stored = false;

        protected function persist(array $connection): void
        {
            $this->saved  = $connection;
            $this->stored = true;
        }

        protected function connectionIsStored(string $companyUuid): bool
        {
            return $this->stored;
        }
    };

    $callback = Request::create('/oauth/callback', 'GET', [
        'state'   => $begun['state'],
        'code'    => 'code',
        'realmId' => 'realm-1',
    ]);
    $response = $controller->callback($callback);

    expect($controller->saved)->toBe([])
        ->and($response->getTargetUrl())->toStartWith('https://console.example.test/quickbooks?oauth_state=');

    parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
    $handle = (string) $query['oauth_state'];
    expect($handle)->not->toBe($begun['state'])
        ->and($controller->callback($callback)->getTargetUrl())->toBe('https://console.example.test/quickbooks?error=state');

    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    try {
        $jobs = qbCaptureDispatches(function () use ($controller, $handle): void {
            $completed = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => $handle]));

            expect($completed->getStatusCode())->toBe(200)
                ->and($completed->getData(true))->toBe(['connected' => true])
                ->and($controller->saved['company_uuid'])->toBe('company-uuid')
                ->and($controller->saved['realm_id'])->toBe('realm-1')
                ->and($controller->saved['refresh_token'])->toBe('refresh')
                ->and($controller->saved)->not->toHaveKey('import_customers');

            // A reload repeats complete(); it reports connected without a second code exchange, save, or sync.
            $controller->saved = [];
            $repeated          = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => $handle]));
            $exchanges         = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));

            expect($repeated->getStatusCode())->toBe(200)
                ->and($repeated->getData(true))->toBe(['connected' => true])
                ->and($controller->saved)->toBe([])
                ->and($exchanges)->toHaveCount(1);

            $controller->stored = false;
            $disconnected       = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => $handle]));
            expect($disconnected->getStatusCode())->toBe(422)
                ->and($disconnected->getData(true)['message'])->toBe('QuickBooks is not connected. Connect again from Connection.');
        });
        $syncs = array_values(array_filter($jobs, fn ($job) => $job instanceof SyncCompanyBatch));

        expect($syncs)->toHaveCount(1)
            ->and($syncs[0]->trigger)->toBe('now')
            ->and(array_filter($jobs, fn ($job) => $job instanceof ImportCustomers))->toBe([]);
    } finally {
        session(['company' => null, 'user' => null]);
    }
});

test('oauth state is validated before the code exchange', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token'  => 'access',
            'refresh_token' => 'refresh',
            'expires_in'    => 3600,
        ], 200),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'CompanyInfo'   => ['CompanyName' => 'unchartedWaters', 'Country' => 'US'],
            'Preferences'   => ['CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]],
            'Item'          => ['Id' => '7'],
            'QueryResponse' => ['Item' => [], 'Account' => [['Id' => '79', 'AccountType' => 'Income']]],
        ], 200),
    ]);

    $flow        = new OAuthFlow(new QuickBooksClient());
    $credentials = [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ];
    $begun = $flow->begin('company-uuid', 'user-uuid', $credentials);

    expect(fn () => $flow->complete('foreign-state', 'company-uuid', 'user-uuid', $credentials))
        ->toThrow(QuickBooksException::class);

    Cache::put('quickbooks.oauth-state.' . $begun['state'], [
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
        'code'         => 'code',
        'realm_id'     => 'realm',
        'expires_at'   => time() - 10,
    ], 60);

    expect(fn () => $flow->complete($begun['state'], 'company-uuid', 'user-uuid', $credentials))
        ->toThrow(QuickBooksException::class);

    $again      = $flow->begin('company-uuid', 'user-uuid', $credentials);
    $handle     = $flow->receive($again['state'], 'code', 'realm-1');
    $connection = $flow->complete($handle, 'company-uuid', 'user-uuid', $credentials);

    expect($connection['refresh_token'])->toBe('refresh')
        ->and($connection['home_currency'])->toBe('USD')
        ->and($connection['default_item_id'])->toBe('7')
        ->and($connection)->not->toHaveKey('import_customers');
});

test('completing a handle again is allowed only for the user who completed it and does not exchange again', function () {
    $client = new class extends QuickBooksClient {
        public int $exchanges = 0;

        public ?string $challenge = null;

        public ?string $verifier = null;

        public function authorizationUrl(array $credentials, string $state, ?string $codeChallenge = null): string
        {
            $this->challenge = $codeChallenge;

            return 'https://appcenter.intuit.com/connect/oauth2?state=' . $state;
        }

        public function exchangeCode(array $credentials, string $code, ?string $codeVerifier = null): array
        {
            $this->exchanges++;
            $this->verifier = $codeVerifier;

            return ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600];
        }

        public function homeCurrency(array $connection): ?string
        {
            return 'USD';
        }

        public function ensureServiceItem(array $connection): string
        {
            return '7';
        }
    };
    $flow        = new OAuthFlow($client);
    $credentials = [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ];
    $begun  = $flow->begin('company-uuid', 'user-uuid', $credentials);
    $handle = $flow->receive($begun['state'], 'code', 'realm-1');

    expect(fn () => $flow->complete($handle, 'company-uuid', 'other-user', $credentials))
        ->toThrow(QuickBooksException::class, 'different user or organization')
        ->and($client->exchanges)->toBe(0);

    $connection = $flow->complete($handle, 'company-uuid', 'user-uuid', $credentials);
    $challenge  = rtrim(strtr(base64_encode(hash('sha256', (string) $client->verifier, true)), '+/', '-_'), '=');

    expect($connection)->toBeArray()
        ->and($flow->complete($handle, 'company-uuid', 'user-uuid', $credentials))->toBeNull()
        ->and($client->exchanges)->toBe(1)
        ->and(preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', (string) $client->verifier))->toBe(1)
        ->and($client->challenge)->toBe($challenge)
        ->and($client->challenge)->not->toContain('=')
        ->and(fn () => $flow->complete($handle, 'company-uuid', 'other-user', $credentials))
        ->toThrow(QuickBooksException::class, 'different user or organization')
        ->and(fn () => $flow->complete($handle, 'other-company', 'user-uuid', $credentials))
        ->toThrow(QuickBooksException::class, 'different user or organization')
        ->and($client->exchanges)->toBe(1);
});

test('a failed token exchange and an unreachable probe do not return transport text', function () {
    $curl   = 'cURL error 28: Operation timed out after 10000 milliseconds';
    $client = new class($curl) extends QuickBooksClient {
        public function __construct(private string $transport)
        {
        }

        public function authorizationUrl(array $credentials, string $state, ?string $codeChallenge = null): string
        {
            return 'https://appcenter.intuit.com/connect/oauth2?state=' . $state;
        }

        public function exchangeCode(array $credentials, string $code, ?string $codeVerifier = null): array
        {
            throw new QuickBooksException(0, $this->transport);
        }

        public function companyInfo(array $connection): array
        {
            throw new QuickBooksException(0, $this->transport);
        }
    };
    $credentials = [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ];
    $flow   = new OAuthFlow($client);
    $begun  = $flow->begin('company-uuid', 'user-uuid', $credentials);
    $handle = $flow->receive($begun['state'], 'code', 'realm-1');

    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    try {
        $response = (new ConnectionController(
            new Authorizer(static fn () => true),
            $flow,
            new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
            new MemorySettingsStore(),
            new Fleetbase\Quickbooks\Services\ConnectionProbe($client)
        ))->complete(Request::create('/oauth/complete', 'POST', ['state' => $handle]));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['message'])->toBe('QuickBooks could not finish connecting. Connect again from Connection.')
            ->and(json_encode($response->getData(true)))->not->toContain('cURL')
            ->and(json_encode($response->getData(true)))->not->toContain('timed out');
    } finally {
        session(['company' => null, 'user' => null]);
    }

    $unreachable = (new Fleetbase\Quickbooks\Services\ConnectionProbe($client))->probe([
        'realm_id'     => 'realm',
        'access_token' => 'token',
    ]);
    $composed = (new Fleetbase\Quickbooks\Services\ConnectionProbe(new class extends QuickBooksClient {
        public function companyInfo(array $connection): array
        {
            throw new QuickBooksException(401, 'QuickBooks request failed with status 401');
        }
    }))->probe([
        'realm_id'     => 'realm',
        'access_token' => 'token',
    ]);
    $curlStatus = (new Fleetbase\Quickbooks\Services\ConnectionProbe(new class extends QuickBooksClient {
        public function companyInfo(array $connection): array
        {
            throw new QuickBooksException(503, 'cURL error 7: Failed to connect to sandbox-quickbooks.api.intuit.com port 443');
        }
    }))->probe([
        'realm_id'     => 'realm',
        'access_token' => 'token',
    ]);

    expect($unreachable['ok'])->toBeFalse()
        ->and($unreachable['message'])->toBe('QuickBooks could not be reached. Try again in a few minutes.')
        ->and($composed['message'])->toBe('QuickBooks request failed with status 401')
        ->and($curlStatus['message'])->toBe('QuickBooks could not be reached. Try again in a few minutes.');
});

test('completing with the original state instead of the handle says the link is not valid', function () {
    $flow        = new OAuthFlow(new FakeQuickBooks());
    $credentials = [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ];
    $begun = $flow->begin('company-uuid', 'user-uuid', $credentials);

    expect(fn () => $flow->complete($begun['state'], 'company-uuid', 'user-uuid', $credentials))
        ->toThrow(QuickBooksException::class, 'This QuickBooks authorization link is not valid. Connect again from Connection.');
});

test('a refresh persists the rotated refresh token', function () {
    $client = new FakeQuickBooks();
    $client = new class extends QuickBooksClient {
        public function refresh(array $credentials, string $refreshToken): array
        {
            return [
                'access_token'  => 'new-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in'    => 3600,
            ];
        }
    };

    $refreshed = (new TokenRefresher($client))->refresh([
        'refresh_token' => 'old-refresh',
        'needs_reauth'  => false,
    ], [
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.test', 'environment' => 'sandbox',
    ]);

    expect($refreshed['refresh_token'])->toBe('rotated-refresh')
        ->and($refreshed['needs_reauth'])->toBeFalse();
});

test('a failed refresh sets needs reauth and stops that company from syncing', function () {
    $client = new class extends QuickBooksClient {
        public function refresh(array $credentials, string $refreshToken): array
        {
            throw new QuickBooksException(401, 'invalid_grant');
        }
    };

    $refreshed = (new TokenRefresher($client))->refresh([
        'company_uuid'  => 'company-uuid',
        'realm_id'      => 'realm',
        'refresh_token' => 'old',
    ], [
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.test', 'environment' => 'sandbox',
    ]);

    expect($refreshed['needs_reauth'])->toBeTrue();

    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = $refreshed;
    $ledger->pending[]                   = [
        'company_uuid' => 'company-uuid',
        'local_type'   => 'customer',
        'local_uuid'   => 'cust-1',
        'status'       => 'pending',
        'attempts'     => 0,
    ];
    [$engine] = qbEngine();
    $batch    = $engine->runScheduled($ledger, 'company-uuid', qbSettings(), time());

    expect($batch['status'])->toBe('skipped');
});

test('a link whose realm does not match the active connection is not used for an update', function () {
    [$engine, $client]                   = qbEngine();
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'new-realm',
        'needs_reauth' => false,
    ];
    $ledger->customers['cust-1'] = [
        'uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test',
    ];
    $ledger->links[] = [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'old-realm',
        'local_type'   => 'customer',
        'local_uuid'   => 'cust-1',
        'qbo_entity'   => 'Customer',
        'qbo_id'       => 'old-id',
        'sync_token'   => '3',
    ];
    $ledger->pending[] = [
        'company_uuid' => 'company-uuid', 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'status' => 'pending', 'attempts' => 0,
    ];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('createCustomer')
        ->and($client->calls)->not->toContain('getCustomer:old-id')
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('the permission schema uses the quickbooks prefix and three word permissions', function () {
    $schema = new Quickbooks();

    expect($schema->name)->toBe('quickbooks')
        ->and($schema->policyName)->toBe('Quickbooks')
        ->and($schema->guards)->toBe(['sanctum'])
        ->and(array_column($schema->resources, 'name'))->toBe(['connection', 'settings', 'sync'])
        ->and(array_column($schema->policies, 'name'))->toBe(['QuickbooksAdministrator', 'QuickbooksOperator'])
        ->and(array_column($schema->roles, 'name'))->toBe(['QuickBooks Administrator', 'QuickBooks Operator']);

    $permissions = array_merge(...array_column($schema->policies, 'permissions'));
    foreach ($permissions as $permission) {
        expect(count(explode(' ', $permission)))->toBe(3);
    }
});

test('summary last sync is the latest finished batch and stays empty when every batch was skipped', function () {
    session(['company' => 'company-uuid']);
    $connection = new class(new class extends PDO {
        public function __construct()
        {
        }
    }, 'testing', '', ['name' => 'testing']) extends Connection {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            if (str_contains(strtolower($query), 'count(')) {
                return [['aggregate' => 0]];
            }

            if (!str_contains($query, 'quickbooks_sync_batches')) {
                return [];
            }

            $finishedOnly = str_contains($query, 'status') && in_array('finished', $bindings, true);
            $companyUuid  = $bindings[0] ?? null;
            $rows         = array_values(array_filter(
                $this->rows,
                static function (array $row) use ($companyUuid, $finishedOnly): bool {
                    if (($row['company_uuid'] ?? null) !== $companyUuid) {
                        return false;
                    }

                    return !$finishedOnly || ($row['status'] ?? null) === 'finished';
                }
            ));

            if (preg_match('/order by\s+["`]?finished_at["`]?\s+desc/i', $query) === 1) {
                usort(
                    $rows,
                    static fn (array $left, array $right): int => strcmp((string) $right['finished_at'], (string) $left['finished_at'])
                );
            }

            if (preg_match('/\blimit\s+1\b/i', $query) === 1) {
                return array_slice($rows, 0, 1);
            }

            return $rows;
        }
    };

    $resolver = new ConnectionResolver(['testing' => $connection]);
    $resolver->setDefaultConnection('testing');
    $previous = Model::getConnectionResolver();
    Model::setConnectionResolver($resolver);

    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );
    $request = Request::create('/summary', 'GET', ['company_uuid' => 'company-uuid']);

    try {
        $connection->rows = [
            qbSummaryBatch('ran-older', 'company-uuid', 'scheduled', 'finished', '2026-09-01 10:00:00', 1),
            qbSummaryBatch('refused-import', 'company-uuid', 'import', 'skipped', '2026-09-27 16:00:00', 0),
            qbSummaryBatch('not-connected', 'company-uuid', 'now', 'skipped', '2026-09-26 16:00:00', 0),
            qbSummaryBatch('ran-latest', 'company-uuid', 'now', 'finished', '2026-09-10 15:00:00', 4, 2),
            qbSummaryBatch('other-company', 'other-company', 'scheduled', 'finished', '2026-09-25 15:00:00', 9),
        ];
        $ran = $controller->summary($request)->getData(true);

        expect($ran['credentials_configured'])->toBeFalse()
            ->and($ran['last_sync']['status'])->toBe('finished')
            ->and($ran['last_sync']['trigger'])->toBe('now')
            ->and($ran['last_sync']['created_count'])->toBe(4)
            ->and($ran['last_sync']['updated_count'])->toBe(2)
            ->and($ran['last_sync']['finished_at'])->toContain('2026-09-10');

        $connection->rows = [
            qbSummaryBatch('refused-import', 'company-uuid', 'import', 'skipped', '2026-09-27 16:00:00', 0),
            qbSummaryBatch('not-connected', 'company-uuid', 'now', 'skipped', '2026-09-26 16:00:00', 0),
            qbSummaryBatch('other-company', 'other-company', 'scheduled', 'finished', '2026-09-28 15:00:00', 9),
        ];
        $none = $controller->summary($request)->getData(true);

        expect($none['last_sync'])->toBeNull()
            ->and($none['credentials_configured'])->toBeFalse();
    } finally {
        if ($previous === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($previous);
        }
        session(['company' => null]);
    }
});

/**
 * @param array<int, string> $keys
 *
 * @return array<string, mixed>
 */
function qbRememberConfig(array $keys): array
{
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = config($key);
    }

    return $previous;
}

/**
 * @param array<string, mixed> $previous
 */
function qbRestoreConfig(array $previous): void
{
    foreach ($previous as $key => $value) {
        config()->set($key, $value);
    }
}

function qbOAuthRedirect(JsonResponse $response): string
{
    expect($response->getStatusCode())->toBe(200);
    $url = (string) ($response->getData(true)['url'] ?? '');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['redirect_uri'] ?? '');
}

/**
 * @return array<string, mixed>
 */
/**
 * @return array<int, object>
 */
function qbCaptureDispatches(callable $callback): array
{
    $dispatcher = new class implements Dispatcher {
        /** @var array<int, object> */
        public array $jobs = [];

        public function dispatch($command)
        {
            $this->jobs[] = $command;

            return $command;
        }

        public function dispatchSync($command, $handler = null)
        {
            return $command;
        }

        public function dispatchNow($command, $handler = null)
        {
            return $command;
        }

        public function hasCommandHandler($command)
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return false;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    };
    $container          = Container::getInstance();
    $previousDispatcher = $container->bound(Dispatcher::class) ? $container->make(Dispatcher::class) : null;
    $container->instance(Dispatcher::class, $dispatcher);

    try {
        $callback();
    } finally {
        if ($previousDispatcher !== null) {
            $container->instance(Dispatcher::class, $previousDispatcher);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }

    return $dispatcher->jobs;
}

function qbSummaryBatch(string $uuid, string $companyUuid, string $trigger, string $status, string $finishedAt, int $created, int $updated = 0): array
{
    return [
        'uuid'          => $uuid,
        'company_uuid'  => $companyUuid,
        'trigger'       => $trigger,
        'direction'     => $trigger === 'import' ? 'inbound' : 'outbound',
        'status'        => $status,
        'created_count' => $created,
        'updated_count' => $updated,
        'failed_count'  => 0,
        'finished_at'   => $finishedAt,
    ];
}
