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
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\LastRealmController;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
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
        ->and($resolved['sources'])->not->toHaveKey('redirect_uri')
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

test('reading a legacy secret re-encrypts it and a crypt payload stays put', function () {
    $cipher = new SecretCipher();
    $key    = substr(hash('sha256', base64_decode(substr((string) config('app.key'), 7), true), true), 0, 32);
    $iv     = random_bytes(16);
    $legacy = base64_encode($iv . openssl_encrypt('old-secret', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
    $crypt  = $cipher->encrypt('kept-secret');

    $opened = $cipher->read($legacy);

    expect($opened)->toBeArray()
        ->and($opened['plain'])->toBe('old-secret')
        ->and($opened['stored'])->not->toBe($legacy)
        ->and($cipher->isLegacyCiphertext($opened['stored']))->toBeFalse()
        ->and($cipher->decrypt($opened['stored']))->toBe('old-secret')
        ->and($cipher->read($crypt))->toBe(['plain' => 'kept-secret', 'stored' => $crypt])
        ->and($cipher->reveal($legacy))->toBe('old-secret');
});

test('reading a connection re-encrypts a legacy token without waiting for save', function () {
    $cipher = new SecretCipher();
    $key    = substr(hash('sha256', base64_decode(substr((string) config('app.key'), 7), true), true), 0, 32);
    $iv     = random_bytes(16);
    $legacy = base64_encode($iv . openssl_encrypt('old-access', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));

    $connection = new Fleetbase\Quickbooks\Models\Connection();
    $connection->setRawAttributes([
        'access_token' => $legacy,
    ]);

    expect($connection->access_token)->toBe('old-access')
        ->and($cipher->isLegacyCiphertext($connection->getAttributes()['access_token']))->toBeFalse()
        ->and($cipher->decrypt($connection->getAttributes()['access_token']))->toBe('old-access');
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
        'redirect_uri'     => 'https://example.com/callback',
        'environment'      => 'sandbox',
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
                'client_id'        => 'id',
                'client_secret'    => '',
                'webhook_verifier' => '',
                'redirect_uri'     => 'https://example.com/callback',
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
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
            'company_uuid' => 'company-uuid',
        ]));

        foreach ([
            'http://10.30.0.34:4200',
            'http://fleet.example.com',
            'https://10.0.0.5',
            'https://192.168.1.9',
            'https://127.0.0.1',
            'https://localhost',
            'https://[fd00::1]',
            'https://169.254.169.254',
        ] as $consoleHost) {
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
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
    $start = static fn (): JsonResponse => $controller->start(Request::create('/oauth/start', 'POST'));

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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
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
        $started = $controller->start(Request::create('/oauth/start', 'POST'));
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
    ];
    $controller = LastRealmController::make($administrator, $lastRealm, $store);

    try {
        $started = $controller->start(Request::create('/oauth/start', 'POST'));
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
    ];

    try {
        $refused = LastRealmController::make(false, null, $store)->start(Request::create('/oauth/start', 'POST'));
        $again   = LastRealmController::make(false, '9341453000000001', $store)->start(
            Request::create('/oauth/start', 'POST')
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
    ];
    $store->rows[SettingsKeys::adminSync()] = ['customer_enabled' => false];
    $controller                             = new class(new Authorizer(static fn () => true), new OAuthFlow(new QuickBooksClient()), new SettingsService(new CredentialResolver(), new Fleetbase\Quickbooks\Support\SyncSettingsResolver(), new SecretCipher()), $store, new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())) extends ConnectionController {
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
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
        $first  = $controller->start(Request::create('/oauth/start', 'POST'));
        $second = $controller->start(Request::create('/oauth/start', 'POST'));
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
        'client_id'     => 'client-id',
        'client_secret' => (new SecretCipher())->encrypt('secret'),
        'environment'   => 'sandbox',
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
        $started   = $controller->start(Request::create('/oauth/start', 'POST'));
        $cancelled = $controller->complete(Request::create('/oauth/complete', 'POST', [
            'state' => $started->getData(true)['state'],
            'error' => 'access_denied',
        ]));
        $afterCancel = $controller->start(Request::create('/oauth/start', 'POST'));
        $failed      = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => 'unknown']));
        $afterFail   = $controller->start(Request::create('/oauth/start', 'POST'));
        $third       = $controller->start(Request::create('/oauth/start', 'POST'));
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
        $response = (new ConnectionController(
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
            'client_id'     => 'client-id',
            'client_secret' => (new SecretCipher())->encrypt('secret'),
            'environment'   => 'sandbox',
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
