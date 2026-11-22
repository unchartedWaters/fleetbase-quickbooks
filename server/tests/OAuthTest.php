<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Jobs\QueueCompanyScope;
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
use Fleetbase\Quickbooks\Tests\Support\DetachedController;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\LastRealmController;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Fleetbase\Quickbooks\Tests\Support\NoLinkedData;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('credentials come from the system row, one set per environment', function () {
    expect(SettingsKeys::companyAuth('company-uuid'))->toBe('company.company-uuid.quickbooks.auth')
        ->and(SettingsKeys::adminAuth())->toBe('system.quickbooks.auth');

    $admin = [
        'sandbox_client_id'           => 'dev-id',
        'sandbox_client_secret'       => 'dev-secret',
        'sandbox_webhook_verifier'    => 'dev-verifier',
        'production_client_id'        => 'live-id',
        'production_client_secret'    => 'live-secret',
        'production_webhook_verifier' => 'live-verifier',
        // Nothing but the saved keys is read: an old unprefixed or organization value changes nothing.
        'client_id' => 'unprefixed-id',
    ];
    $sandbox    = (new CredentialResolver())->resolve($admin, 'sandbox');
    $production = (new CredentialResolver())->resolve($admin, 'production');
    $missing    = (new CredentialResolver())->resolve(['production_client_id' => 'only-live'], 'sandbox');

    expect($sandbox)->toBe([
        'client_id'        => 'dev-id',
        'client_secret'    => 'dev-secret',
        'redirect_uri'     => '',
        'environment'      => 'sandbox',
        'webhook_verifier' => 'dev-verifier',
    ])
        ->and($production['client_id'])->toBe('live-id')
        ->and($production['environment'])->toBe('production')
        ->and($production['webhook_verifier'])->toBe('live-verifier')
        ->and($missing['client_id'])->toBe('')
        ->and($missing['client_secret'])->toBe('')
        ->and(SettingsKeys::environment('production'))->toBe('production')
        ->and(SettingsKeys::environment('anything else'))->toBe('sandbox')
        ->and(SettingsKeys::environment(null))->toBe('sandbox');
});

test('the client secret is encrypted at rest and is not returned by the settings api', function () {
    $cipher   = new SecretCipher();
    $settings = new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), $cipher);
    $stored   = $settings->storeAuth(['production_client_id' => 'id', 'production_client_secret' => 'plain-secret'], []);

    expect($stored['production_client_secret'])->not->toBe('plain-secret')
        ->and($cipher->decrypt($stored['production_client_secret']))->toBe('plain-secret');

    $browser = $settings->forBrowser($stored);
    expect($browser)->not->toHaveKey('production_client_secret')
        ->and($browser['production_client_secret_set'])->toBeTrue()
        ->and($browser['sandbox_client_secret_set'])->toBeFalse();

    $request = Request::create('/settings', 'POST', [
        'company_uuid' => 'company-uuid',
        'auth'         => ['production_client_id' => 'id', 'production_client_secret' => 'plain-secret'],
    ]);
    $controller = new SettingController(
        new Authorizer(static fn () => false),
        $settings,
        new SettingsStore()
    );

    expect(fn () => $controller->save($request))->toThrow(HttpException::class);
});

test('an unrecognized token is not a live secret and a plaintext setting is encrypted on the next save', function () {
    $cipher                                 = new SecretCipher();
    $settings                               = new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), $cipher);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'        => 'id',
        'sandbox_client_secret'    => 'plain-secret',
        'sandbox_webhook_verifier' => 'plain-verifier',
        'redirect_uri'             => 'https://example.com/callback',
    ];
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        $settings,
        $store
    );

    session(['company' => 'company-uuid']);
    try {
        $response = $controller->save(InstallAdminRequest::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => [
                'sandbox_client_id'        => 'id',
                'sandbox_client_secret'    => '',
                'sandbox_webhook_verifier' => '',
                'redirect_uri'             => 'https://example.com/callback',
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
            ->and($stored['sandbox_client_secret'])->not->toBe('plain-secret')
            ->and($cipher->decrypt($stored['sandbox_client_secret']))->toBe('plain-secret')
            ->and($stored['sandbox_webhook_verifier'])->not->toBe('plain-verifier')
            ->and($cipher->decrypt($stored['sandbox_webhook_verifier']))->toBe('plain-verifier')
            ->and($settings->credentialsFor($store, 'sandbox')['client_secret'])->toBe('plain-secret');
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
});

test('a failed decrypt, an unrecognized secret or old AES ciphertext is missing', function () {
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

    $unrecognized                                  = new MemorySettingsStore();
    $unrecognized->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'        => 'id',
        'sandbox_client_secret'    => 'not-a-secret',
        'sandbox_webhook_verifier' => $tampered,
    ];
    $opened                                  = new MemorySettingsStore();
    $opened->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'        => 'id',
        'sandbox_client_secret'    => $legacy,
        'sandbox_webhook_verifier' => $cipher->encrypt('verifier-token'),
    ];
    $connection = new Fleetbase\Quickbooks\Models\Connection();
    $connection->setRawAttributes([
        'access_token'  => 'not-a-token',
        'refresh_token' => $legacy,
    ]);

    expect($settings->credentialsFor($unrecognized, 'sandbox')['client_secret'])->toBe('')
        ->and($settings->webhookVerifiersFor($unrecognized))->toBe([])
        ->and($settings->credentialsFor($opened, 'sandbox')['client_secret'])->toBe('')
        ->and($settings->webhookVerifiersFor($opened))->toBe(['verifier-token'])
        ->and($connection->access_token)->toBeNull()
        ->and($connection->refresh_token)->toBeNull();
});

test('saving settings without an installation administrator returns 403', function () {
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

test('disconnect deletes only the signed-in company connection and its pending rows and skips Intuit when there is nothing to revoke', function () {
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
            ->and($connection->deletes)->toHaveCount(2)
            ->and($connection->deletes[0]['query'])->toContain('quickbooks_connections')
            ->and($connection->deletes[0]['query'])->toContain('company_uuid')
            ->and($connection->deletes[0]['bindings'])->toBe(['company-uuid'])
            ->and($connection->deletes[1]['query'])->toContain('quickbooks_pending_syncs')
            ->and($connection->deletes[1]['bindings'])->toBe(['company-uuid', 'pending'])
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

test('oauth start sends the console QuickBooks page and refuses one that is not public https', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig([
        'app.url',
        'fleetbase.url',
        'fleetbase.console.host',
        'quickbooks.console_host',
    ]);
    config()->set('app.url', 'http://localhost:8000');
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', null);
    config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
        // Stored redirect and public URLs from an older build are never used.
        'redirect_uri'              => 'https://example.com/quickbooks/int/v1/oauth/callback',
        'public_oauth_redirect_url' => 'https://example.com/quickbooks/int/v1/oauth/callback',
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        $store,
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        $start = static fn (): JsonResponse => $controller->start(Request::create('/oauth/start', 'POST', [
            'environment'  => 'sandbox',
            'company_uuid' => 'company-uuid',
        ]));

        $refusedHosts = [
            'http://10.30.0.34:4200',
            'http://fleet.example.com',
            'https://10.0.0.5',
            'https://192.168.1.9',
            'https://127.0.0.1',
            'https://localhost',
            'https://[fd00::1]',
            'https://169.254.169.254',
        ];
        foreach ($refusedHosts as $consoleHost) {
            config()->set('quickbooks.console_host', $consoleHost);
            $refused = $start();
            expect($refused->getStatusCode())->toBe(422)
                ->and($refused->getData(true))->not->toHaveKey('url')
                ->and($refused->getData(true)['message'])->toBe('QuickBooks cannot be connected yet: Intuit needs a'
                    . ' public https:// address to send you back to. Ask an installation administrator to set'
                    . ' CONSOLE_HOST (or QUICKBOOKS_CONSOLE_HOST) on the Fleetbase API to the console\'s public'
                    . ' https:// address, then add the Public OAuth Redirect URL shown in Admin → QuickBooks →'
                    . ' Settings to the Intuit app.');
        }

        // The API's own address and port never become the redirect.
        config()->set('quickbooks.console_host', 'https://fleet.example.com:8443/');
        expect(qbOAuthRedirect($start()))->toBe('https://fleet.example.com:8443/quickbooks');
        Cache::flush();
        config()->set('quickbooks.console_host', 'fleet.example.com');
        expect(qbOAuthRedirect($start()))->toBe('https://fleet.example.com/quickbooks');
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('oauth start refuses before Intuit when no console host is configured', function () {
    session(['company' => 'company-uuid']);
    $previous = qbRememberConfig(['app.url', 'fleetbase.url', 'fleetbase.console.host', 'quickbooks.console_host']);
    config()->set('app.url', null);
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', 'fleetbase.io');
    config()->set('quickbooks.console_host', null);
    $public                                 = 'https://console.example.com/quickbooks';
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(
            new CredentialResolver(),
            new Fleetbase\Quickbooks\Support\SyncSettingsResolver(),
            new SecretCipher()
        ),
        $store,
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );
    $start = static fn (): JsonResponse => $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));

    try {
        $refused = $start();
        config()->set('fleetbase.console.host', 'console.example.com');
        $fleetbaseHost = $start();

        expect($refused->getStatusCode())->toBe(422)
            ->and($refused->getData(true))->not->toHaveKey('url')
            ->and($refused->getData(true)['message'])->toBe('QuickBooks cannot be connected yet: Fleetbase does not'
                . ' know the console address to return to after sign-in. Ask an installation administrator to set'
                . ' CONSOLE_HOST (or QUICKBOOKS_CONSOLE_HOST) on the Fleetbase API.')
            ->and($fleetbaseHost->getStatusCode())->toBe(200)
            ->and(qbOAuthRedirect($fleetbaseHost))->toBe($public);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null]);
    }
});

test('oauth complete exchanges the code with the console QuickBooks page as the redirect', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig([
        'app.url',
        'fleetbase.url',
        'fleetbase.console.host',
        'quickbooks.console_host',
    ]);
    // The API port must not leak into the redirect: Intuit returns to the console.
    config()->set('app.url', 'http://localhost:8000');
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', 'https://fleet.example.com');
    config()->set('quickbooks.console_host', 'https://fleet.example.com/');
    $computed = 'https://fleet.example.com/quickbooks';
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
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $controller = new class(new Authorizer(static fn () => true), new OAuthFlow(new QuickBooksClient()), new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()), $store, new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())) extends ConnectionController {
        use NoLinkedData;

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
            'environment'  => 'sandbox',
            'company_uuid' => 'company-uuid',
        ]));
        expect(qbOAuthRedirect($started))->toBe($computed);
        $state = (string) $started->getData(true)['state'];

        $completed = null;
        $jobs      = qbCaptureDispatches(function () use ($controller, $state, &$completed): void {
            $completed = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'state'   => $state,
                'code'    => 'code',
                'realmId' => '9341453000000001',
            ]));
        });
        $exchanges = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));
        parse_str($exchanges[0][0]->body(), $form);
        $syncs   = array_values(array_filter($jobs, fn ($job) => $job instanceof SyncCompanyBatch));
        $scopes  = array_values(array_filter($jobs, fn ($job) => $job instanceof QueueCompanyScope));
        $imports = array_values(array_filter($jobs, fn ($job) => $job instanceof ImportCustomers));

        expect($completed->getStatusCode())->toBe(200)
            ->and($completed->getData(true))->toBe(['connected' => true])
            ->and($form['redirect_uri'])->toBe($computed)
            ->and($controller->saved['realm_id'])->toBe('9341453000000001')
            // The catalog queueing and then the first sync run in one background job.
            ->and($syncs)->toBe([])
            ->and($scopes)->toHaveCount(1)
            ->and($scopes[0]->companyUuid)->toBe('company-uuid')
            ->and($scopes[0]->syncAfter)->toBeTrue()
            ->and($imports)->toHaveCount(1)
            ->and($imports[0]->companyUuid)->toBe('company-uuid')
            ->and($imports[0]->waitForLock)->toBeTrue()
            ->and($imports[0]->lockWaitSeconds)->toBe(0);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('oauth complete sends the redirect uri used at start even if the console host changed since', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig(['app.url', 'fleetbase.url', 'fleetbase.console.host', 'quickbooks.console_host']);
    config()->set('app.url', null);
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', null);
    config()->set('quickbooks.console_host', 'https://example.com');
    $atStart = 'https://example.com/quickbooks';
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token'  => 'a',
            'refresh_token' => 'r',
            'expires_in'    => 3600,
        ]),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([], 503),
    ]);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $authorizer = new Authorizer(static fn () => true);
    $flow       = new OAuthFlow(new QuickBooksClient());
    $settings   = new SettingsService(
        new CredentialResolver(),
        new Fleetbase\Quickbooks\Support\SyncSettingsResolver(),
        new SecretCipher()
    );
    $probe      = new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient());
    $controller = new class($authorizer, $flow, $settings, $store, $probe) extends ConnectionController {
        use NoLinkedData;

        public bool $stored = false;

        protected function persist(array $connection): void
        {
            $this->stored = true;
        }

        protected function connectionIsStored(string $companyUuid): bool
        {
            return $this->stored;
        }
    };

    try {
        $started = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        expect(qbOAuthRedirect($started))->toBe($atStart);
        $state = (string) $started->getData(true)['state'];

        // The console host changes while the user is at Intuit.
        config()->set('quickbooks.console_host', 'https://example.net');
        $completed = null;
        qbCaptureDispatches(function () use ($controller, $state, &$completed): void {
            $completed = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'state'   => $state,
                'code'    => 'code',
                'realmId' => '9341453000000001',
            ]));
        });
        $exchanges = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));
        parse_str($exchanges[0][0]->body(), $form);

        expect($completed->getStatusCode())->toBe(200)
            ->and($form['redirect_uri'])->toBe($atStart);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('an operator may only reconnect the QuickBooks company the organization already uses', function (
    bool $administrator,
    ?string $lastRealm,
    bool $startAllowed,
    bool $allowed,
) {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig(['app.url', 'fleetbase.url', 'fleetbase.console.host', 'quickbooks.console_host']);
    config()->set('app.url', 'https://example.com');
    config()->set('fleetbase.url', null);
    config()->set('quickbooks.console_host', 'https://fleet.example.com');
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token'  => 'a',
            'refresh_token' => 'r',
            'expires_in'    => 3600,
        ]),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([], 503),
    ]);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $controller = LastRealmController::make($administrator, $lastRealm, $store);

    try {
        $started = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        if ($startAllowed === false) {
            // A first connection the caller cannot make is refused before anyone signs in at Intuit.
            expect($started->getStatusCode())->toBe(403)
                ->and($started->getData(true))->not->toHaveKey('url')
                ->and($started->getData(true)['message'])->toBe(
                    'Only a QuickBooks Administrator can connect this organization to a new or different QuickBooks '
                    . 'company. You can connect again to the QuickBooks company it already uses.'
                );

            return;
        }
        $state     = (string) $started->getData(true)['state'];
        $completed = null;
        $jobs      = qbCaptureDispatches(function () use ($controller, $state, &$completed): void {
            $completed = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'state'   => $state,
                'code'    => 'code',
                'realmId' => '9341453000000001',
            ]));
        });
        $exchanges = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));

        if ($allowed === true) {
            expect($completed->getStatusCode())->toBe(200)
                ->and($controller->saved['realm_id'])->toBe('9341453000000001')
                ->and($jobs)->not->toBe([]);

            return;
        }
        expect($completed->getStatusCode())->toBe(403)
            ->and($completed->getData(true)['message'])->toBe(
                'Only a QuickBooks Administrator can connect this organization to a new or different QuickBooks '
                . 'company. You can connect again to the QuickBooks company it already uses.'
            )
            ->and($controller->saved)->toBeNull()
            ->and($jobs)->toBe([])
            ->and($exchanges)->toHaveCount(0);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
})->with([
    'operator, same company'           => [false, '9341453000000001', true, true],
    'operator, different company'      => [false, '9341453000000999', true, false],
    'operator, first connection'       => [false, null, false, false],
    'administrator, different company' => [true, '9341453000000999', true, true],
    'administrator, first connection'  => [true, null, true, true],
]);

test('oauth start refuses an operator whose organization never connected', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig(['app.url', 'fleetbase.url', 'fleetbase.console.host', 'quickbooks.console_host']);
    config()->set('app.url', 'https://example.com');
    config()->set('fleetbase.url', null);
    config()->set('quickbooks.console_host', 'https://console.example.com');
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];

    try {
        $refused = LastRealmController::make(false, null, $store)->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        $again   = LastRealmController::make(false, '9341453000000001', $store)->start(
            Request::create('/oauth/start', 'POST', ['environment' => 'sandbox'])
        );

        expect($refused->getStatusCode())->toBe(403)
            ->and($refused->getData(true))->not->toHaveKey('url')
            ->and($refused->getData(true)['message'])->toContain('Only a QuickBooks Administrator can connect')
            ->and($again->getStatusCode())->toBe(200)
            ->and((string) $again->getData(true)['url'])->toContain('appcenter.intuit.com');
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('oauth complete skips customer import when customers are turned off', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig([
        'app.url',
        'fleetbase.url',
        'fleetbase.console.host',
        'quickbooks.console_host',
    ]);
    config()->set('app.url', 'https://example.com');
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', 'https://console.example.test');
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
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $store->rows[SettingsKeys::adminSync()] = ['customer_enabled' => false];
    $controller                             = new class(new Authorizer(static fn () => true), new OAuthFlow(new QuickBooksClient()), new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()), $store, new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())) extends ConnectionController {
        use NoLinkedData;

        protected function persist(array $connection): void
        {
        }

        protected function connectionIsStored(string $companyUuid): bool
        {
            return false;
        }
    };

    try {
        $started = $controller->start(Request::create('/oauth/start', 'POST', [
            'environment'  => 'sandbox',
            'company_uuid' => 'company-uuid',
        ]));
        $state = (string) $started->getData(true)['state'];
        $jobs  = qbCaptureDispatches(function () use ($controller, $state): void {
            $completed = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'state'   => $state,
                'code'    => 'code',
                'realmId' => '9341453000000001',
            ]));
            expect($completed->getStatusCode())->toBe(200);
        });
        $syncs   = array_values(array_filter($jobs, fn ($job) => $job instanceof SyncCompanyBatch));
        $scopes  = array_values(array_filter($jobs, fn ($job) => $job instanceof QueueCompanyScope));
        $imports = array_values(array_filter($jobs, fn ($job) => $job instanceof ImportCustomers));

        expect($syncs)->toBe([])
            ->and($scopes)->toHaveCount(1)
            ->and($scopes[0]->syncAfter)->toBeTrue()
            ->and($imports)->toBe([]);
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
});

test('there is no public oauth callback route and oauth complete needs a signed-in console', function () {
    $routes      = (string) file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $config      = (string) file_get_contents(dirname(__DIR__) . '/config/quickbooks.php');
    $webhookAt   = strpos($routes, "\$router->post('v1/webhooks', [WebhookController::class, 'handle']);");
    $protectedAt = strpos($routes, "'middleware' => ['fleetbase.protected']");
    $completeAt  = strpos($routes, "\$router->post('oauth/complete', [ConnectionController::class, 'complete']);");

    // Intuit returns the browser to the console's QuickBooks page, which posts the code to oauth/complete.
    expect($routes)->not->toContain('oauth/callback')
        ->and($routes)->not->toContain('callbackThrottle')
        ->and($config)->not->toContain('callback_per_minute')
        ->and(method_exists(ConnectionController::class, 'callback'))->toBeFalse()
        ->and($webhookAt)->not->toBeFalse()
        ->and($protectedAt)->not->toBeFalse()
        ->and($webhookAt)->toBeLessThan($protectedAt)
        ->and($completeAt)->toBeGreaterThan($protectedAt)
        // Intuit may burst webhook notifications, so the webhook statement carries no middleware.
        ->and(substr_count($routes, '->middleware('))->toBe(0);
});

test('oauth start twice in a row answers 429 the second time', function () {
    session(['company' => 'company-uuid']);
    $public                                 = 'https://console.example.com/quickbooks';
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(
            new CredentialResolver(),
            new Fleetbase\Quickbooks\Support\SyncSettingsResolver(),
            new SecretCipher()
        ),
        $store,
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );
    $previous = [
        Cache::getFacadeRoot(), config('quickbooks.manual_cooldown_seconds'), config('quickbooks.console_host'),
    ];
    Cache::swap(new Repository(new ArrayStore()));
    config()->set('quickbooks.manual_cooldown_seconds', 30);
    config()->set('quickbooks.console_host', 'https://console.example.com');

    try {
        $first  = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        $second = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
    } finally {
        Cache::swap($previous[0]);
        config()->set('quickbooks.manual_cooldown_seconds', $previous[1]);
        config()->set('quickbooks.console_host', $previous[2]);
        session(['company' => null]);
    }

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(429)
        ->and($second->getData(true)['message'])->toBe('This was started a moment ago. Try again shortly.');
});

test('a cancelled or failed connection does not hold back the next Connect', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'sandbox_client_id'     => 'client-id',
        'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
    ];
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(
            new CredentialResolver(),
            new Fleetbase\Quickbooks\Support\SyncSettingsResolver(),
            new SecretCipher()
        ),
        $store,
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );
    $previous = [
        Cache::getFacadeRoot(), config('quickbooks.manual_cooldown_seconds'), config('quickbooks.console_host'),
    ];
    Cache::swap(new Repository(new ArrayStore()));
    config()->set('quickbooks.manual_cooldown_seconds', 30);
    config()->set('quickbooks.console_host', 'https://console.example.com');

    try {
        $started   = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        $cancelled = $controller->complete(Request::create('/oauth/complete', 'POST', [
            'state' => $started->getData(true)['state'],
            'error' => 'access_denied',
        ]));
        $afterCancel = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        $failed      = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => 'unknown']));
        $afterFail   = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
        $third       = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'sandbox']));
    } finally {
        Cache::swap($previous[0]);
        config()->set('quickbooks.manual_cooldown_seconds', $previous[1]);
        config()->set('quickbooks.console_host', $previous[2]);
        session(['company' => null, 'user' => null]);
    }

    expect($started->getStatusCode())->toBe(200)
        ->and($cancelled->getData(true))->toBe(['connected' => false])
        ->and($afterCancel->getStatusCode())->toBe(200)
        ->and($failed->getStatusCode())->toBe(422)
        ->and($afterFail->getStatusCode())->toBe(200)
        ->and($third->getStatusCode())->toBe(429);
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
        'environment'  => 'sandbox',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toContain('Client ID')
        ->and($response->getData(true)['message'])->toContain('Client secret');
    session(['company' => null]);
});

test('oauth complete refuses an unknown state', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    config()->set('quickbooks.console_host', 'https://console.example.test');
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
    );

    try {
        $response = $controller->complete(Request::create('/oauth/complete', 'POST', [
            'state'   => 'bogus',
            'code'    => 'code',
            'realmId' => '123456789',
        ]));
        $cancel = $controller->complete(Request::create('/oauth/complete', 'POST', [
            'state' => 'bogus',
            'error' => 'access_denied',
        ]));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true))->not->toHaveKey('connected')
            ->and($cancel->getStatusCode())->toBe(200)
            ->and($cancel->getData(true))->toBe(['connected' => false]);
    } finally {
        config()->set('quickbooks.console_host', null);
        session(['company' => null, 'user' => null]);
    }
});

test('only the user who started the flow can finish it, and a reload does not exchange again', function () {
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
        // complete() reads credentials from the empty store, so it uses the production default.
        // Without this, the test calls the real API, and a 401 there now fails the connection.
        'quickbooks.api.intuit.com/*' => Http::response([
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
        use NoLinkedData;

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

    $finish = static fn (): Request => Request::create('/oauth/complete', 'POST', [
        'state'   => $begun['state'],
        'code'    => 'code',
        'realmId' => '9341453000000001',
    ]);

    // Another user of the same organization cannot use the state, and it is not burned for its owner.
    session(['company' => 'company-uuid', 'user' => 'other-user']);
    $other = $controller->complete($finish());
    expect($other->getStatusCode())->toBe(422)
        ->and($controller->saved)->toBe([])
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com')))
        ->toHaveCount(0);

    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    try {
        $jobs = qbCaptureDispatches(function () use ($controller, $finish): void {
            $completed = $controller->complete($finish());

            expect($completed->getStatusCode())->toBe(200)
                ->and($completed->getData(true))->toBe(['connected' => true])
                ->and($controller->saved['company_uuid'])->toBe('company-uuid')
                ->and($controller->saved['realm_id'])->toBe('9341453000000001')
                ->and($controller->saved['refresh_token'])->toBe('refresh')
                ->and($controller->saved)->not->toHaveKey('import_customers');

            // A reload repeats complete(); it reports connected without a second code exchange, save, or sync.
            $controller->saved = [];
            $repeated          = $controller->complete($finish());
            $exchanges         = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));

            expect($repeated->getStatusCode())->toBe(200)
                ->and($repeated->getData(true))->toBe(['connected' => true])
                ->and($controller->saved)->toBe([])
                ->and($exchanges)->toHaveCount(1);

            $controller->stored = false;
            $disconnected       = $controller->complete($finish());
            expect($disconnected->getStatusCode())->toBe(422)
                ->and($disconnected->getData(true)['message'])->toBe('QuickBooks is not connected. '
                    . 'Connect again from QuickBooks Setup.');
        });
        $syncs   = array_values(array_filter($jobs, fn ($job) => $job instanceof SyncCompanyBatch));
        $scopes  = array_values(array_filter($jobs, fn ($job) => $job instanceof QueueCompanyScope));
        $imports = array_values(array_filter($jobs, fn ($job) => $job instanceof ImportCustomers));

        expect($syncs)->toBe([])
            ->and($scopes)->toHaveCount(1)
            ->and($scopes[0]->syncAfter)->toBeTrue()
            ->and($imports)->toHaveCount(1)
            ->and($imports[0]->companyUuid)->toBe('company-uuid')
            ->and($imports[0]->waitForLock)->toBeTrue();
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
            'Item'          => ['Id' => '7', 'IncomeAccountRef' => ['value' => '79', 'name' => 'Services']],
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
    $handle     = $flow->receive($again['state'], 'code', '9341453000000001');
    $connection = $flow->complete($handle, 'company-uuid', 'user-uuid', $credentials);

    expect($connection['refresh_token'])->toBe('refresh')
        ->and($connection['home_currency'])->toBe('USD')
        ->and($connection['default_item_id'])->toBe('7')
        // The connection card names the income account the service item posts to.
        ->and($connection['default_item_account'])->toBe('Services')
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

        public function item(array $connection, string $id): ?array
        {
            return null;
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
    $handle = $flow->receive($begun['state'], 'code', '9341453000000001');

    expect(fn () => $flow->complete($handle, 'company-uuid', 'other-user', $credentials))
        ->toThrow(QuickBooksException::class, 'quickbooks.messages.oauth-other-user')
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
        ->toThrow(QuickBooksException::class, 'quickbooks.messages.oauth-other-user')
        ->and(fn () => $flow->complete($handle, 'other-company', 'user-uuid', $credentials))
        ->toThrow(QuickBooksException::class, 'quickbooks.messages.oauth-other-user')
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
    $flow  = new OAuthFlow($client);
    $begun = $flow->begin('company-uuid', 'user-uuid', $credentials);

    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    try {
        $response = (new DetachedController(
            new Authorizer(static fn () => true),
            $flow,
            new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
            new MemorySettingsStore(),
            new Fleetbase\Quickbooks\Services\ConnectionProbe($client)
        ))->complete(Request::create('/oauth/complete', 'POST', [
            'state'   => $begun['state'],
            'code'    => 'code',
            'realmId' => '9341453000000001',
        ]));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['message'])->toBe('QuickBooks could not finish connecting. '
                . 'Connect again from QuickBooks Setup.')
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
            throw new QuickBooksException(401, qbRequestFailed(401));
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
        ->toThrow(QuickBooksException::class, 'quickbooks.messages.oauth-invalid-link');
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

    // Connecting a new or different QuickBooks company is for the Administrator (`quickbooks * connection`).
    expect($schema->resources[0]['actions'])->toContain('change-company')
        ->and($schema->policies[0]['permissions'])->toContain('quickbooks * connection')
        ->and($schema->policies[1]['permissions'])->not->toContain('quickbooks change-company connection')
        ->and($schema->policies[1]['permissions'])->not->toContain('quickbooks * connection');
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
            if (str_contains(strtolower($query), 'count(') === true) {
                // Three rows of this company could not be sent; none are waiting.
                $failed = in_array('failed', $bindings, true) === true && ($bindings[0] ?? null) === 'company-uuid';

                return [['aggregate' => $failed === true ? 3 : 0]];
            }

            if (str_contains($query, 'quickbooks_sync_batches') === false) {
                return [];
            }

            $finishedOnly = str_contains($query, 'status') === true && in_array('finished', $bindings, true) === true;
            $companyUuid  = $bindings[0] ?? null;
            $rows         = array_values(array_filter(
                $this->rows,
                static function (array $row) use ($companyUuid, $finishedOnly): bool {
                    if (($row['company_uuid'] ?? null) !== $companyUuid) {
                        return false;
                    }

                    return $finishedOnly === false || ($row['status'] ?? null) === 'finished';
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

    $store      = new MemorySettingsStore();
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()),
        $store,
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
            ->and($ran['queue'])->toBe(0)
            ->and($ran['failed'])->toBe(3)
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

        $previousUrl = config('app.url');
        config()->set('app.url', 'http://localhost:8000');
        $store->rows[SettingsKeys::adminAuth()] = [
            'sandbox_client_id'     => 'client-id',
            'sandbox_client_secret' => (new SecretCipher())->encrypt('secret'),
        ];
        try {
            $local = $controller->summary($request)->getData(true);
            expect($local['credentials_configured'])->toBeTrue()
                ->and($local)->not->toHaveKey('client_id')
                ->and($local)->not->toHaveKey('client_secret_set')
                ->and(json_encode($local))->not->toContain('client-id');
        } finally {
            config()->set('app.url', $previousUrl);
        }
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
    $previousDispatcher = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
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
