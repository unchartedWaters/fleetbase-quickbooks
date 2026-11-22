<?php

use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\LastRealmController;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Intuit issues separate keys for sandbox (Development) and production, so the environment is the organization's
// choice when it connects, and every later call uses the keys of the environment the connection was made in.

function envStore(bool $sandbox = true, bool $production = true): MemorySettingsStore
{
    $cipher = new SecretCipher();
    $store  = new MemorySettingsStore();
    $rows   = [];
    if ($sandbox === true) {
        $rows += ['sandbox_client_id' => 'dev-id', 'sandbox_client_secret' => $cipher->encrypt('dev-secret')];
    }
    if ($production === true) {
        $rows += ['production_client_id' => 'live-id', 'production_client_secret' => $cipher->encrypt('live-secret')];
    }
    $store->rows[SettingsKeys::adminAuth()] = $rows;

    return $store;
}

/**
 * @param callable(): void $body
 */
function envWithConsole(callable $body): void
{
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);
    $previous = qbRememberConfig(['app.url', 'fleetbase.url', 'fleetbase.console.host', 'quickbooks.console_host']);
    config()->set('app.url', 'https://example.com');
    config()->set('fleetbase.url', null);
    config()->set('quickbooks.console_host', 'https://console.example.com');
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'oauth.platform.intuit.com/*'  => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600]),
        '*quickbooks.api.intuit.com/*' => Http::response([
            'CompanyInfo'   => ['CompanyName' => 'Co', 'Country' => 'US'],
            'Preferences'   => ['CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]],
            'QueryResponse' => ['Item' => [], 'Account' => [['Id' => '79', 'AccountType' => 'Income']]],
        ]),
    ]);

    try {
        $body();
    } finally {
        qbRestoreConfig($previous);
        session(['company' => null, 'user' => null]);
    }
}

function envClientId(JsonResponse $response): string
{
    parse_str((string) parse_url((string) $response->getData(true)['url'], PHP_URL_QUERY), $query);

    return (string) ($query['client_id'] ?? '');
}

test('an administrator picks the environment, and the sign-in uses that environment\'s keys', function () {
    envWithConsole(function () {
        $controller = LastRealmController::make(true, null, envStore());
        $start      = static fn (array $input): JsonResponse => $controller->start(Request::create('/oauth/start', 'POST', $input));

        expect(envClientId($start(['environment' => 'sandbox'])))->toBe('dev-id');
        Cache::flush();
        expect(envClientId($start(['environment' => 'production'])))->toBe('live-id');
        // Without a choice, and nothing connected before, the first environment with keys is offered.
        Cache::flush();
        expect(envClientId($start([])))->toBe('live-id');
        // An unknown value is no choice.
        Cache::flush();
        expect(envClientId($start(['environment' => 'staging'])))->toBe('live-id');
    });
});

test('the sign-in is refused for an environment whose keys are not saved', function () {
    envWithConsole(function () {
        $controller = LastRealmController::make(true, null, envStore(true, false));
        $refused    = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'production']));

        expect($refused->getStatusCode())->toBe(422)
            ->and($refused->getData(true))->not->toHaveKey('url')
            ->and($refused->getData(true)['message'])->toBe(Fleetbase\Quickbooks\Support\Translation::get('quickbooks.messages.not-set-up'));
    });
});

test('completing a sign-in uses the keys and API of the environment it started in', function () {
    foreach (['sandbox' => ['dev-id:dev-secret', 'sandbox-quickbooks.api.intuit.com'], 'production' => ['live-id:live-secret', 'quickbooks.api.intuit.com']] as $environment => [$basic, $host]) {
        envWithConsole(function () use ($environment, $basic, $host) {
            $controller = LastRealmController::make(true, null, envStore());
            $state      = (string) $controller->start(Request::create('/oauth/start', 'POST', ['environment' => $environment]))->getData(true)['state'];
            // A different environment named while completing changes nothing: the flow remembers its own.
            $other     = $environment === 'sandbox' ? 'production' : 'sandbox';
            $completed = null;
            qbCaptureDispatches(function () use ($controller, $state, $other, &$completed): void {
                $completed = $controller->complete(Request::create('/oauth/complete', 'POST', [
                    'state'       => $state,
                    'code'        => 'code',
                    'realmId'     => '9341453000000001',
                    'environment' => $other,
                ]));
            });
            $exchange = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'))->first()[0];
            $api      = Http::recorded(fn ($request) => str_contains($request->url(), 'quickbooks.api.intuit.com'))->first()[0];

            expect($completed->getStatusCode())->toBe(200)
                ->and($exchange->header('Authorization'))->toBe(['Basic ' . base64_encode($basic)])
                ->and(parse_url($api->url(), PHP_URL_HOST))->toBe($host)
                ->and($controller->saved['environment'])->toBe($environment);
        });
    }
});

test('an operator reconnects the environment the organization already uses and cannot pick another', function () {
    envWithConsole(function () {
        $controller = LastRealmController::make(false, '9341453000000001', envStore());
        $started    = $controller->start(Request::create('/oauth/start', 'POST', ['environment' => 'production']));

        // The organization last connected in sandbox, so that is the app the sign-in is for.
        expect(envClientId($started))->toBe('dev-id');
        $state = (string) $started->getData(true)['state'];
        $done  = null;
        qbCaptureDispatches(function () use ($controller, $state, &$done): void {
            $done = $controller->complete(Request::create('/oauth/complete', 'POST', ['state' => $state, 'code' => 'c', 'realmId' => '9341453000000001']));
        });

        expect($done->getStatusCode())->toBe(200)
            ->and($controller->saved['environment'])->toBe('sandbox');
    });
});

test('the keys of each environment are used for its own tokens, and sandbox is the default for an unnamed one', function () {
    $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $store    = envStore();
    $only     = envStore(false, true);

    expect($settings->credentialsFor($store, 'sandbox')['client_id'])->toBe('dev-id')
        ->and($settings->credentialsFor($store, 'sandbox')['environment'])->toBe('sandbox')
        ->and($settings->credentialsFor($store, 'production')['client_secret'])->toBe('live-secret')
        ->and($settings->credentialsFor($store, 'production')['environment'])->toBe('production')
        ->and($settings->credentialsFor($store, '')['client_id'])->toBe('dev-id')
        ->and($settings->credentialsFor($only, 'sandbox')['client_id'])->toBe('')
        ->and($settings->configuredEnvironments($store))->toBe(['production', 'sandbox'])
        ->and($settings->configuredEnvironments($only))->toBe(['production'])
        ->and($settings->configuredEnvironments(new MemorySettingsStore()))->toBe([]);
});

test('a token is refreshed with the keys of the environment its connection was made in', function () {
    $previous = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
    $client = new class extends FakeQuickBooks {
        /** @var array<int, string> */
        public array $clientIds = [];

        public function refresh(array $credentials, string $refreshToken): array
        {
            $this->clientIds[] = $credentials['client_id'];

            return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600];
        }
    };

    try {
        foreach (['sandbox' => 'dev-id', 'production' => 'live-id'] as $environment => $clientId) {
            $directory                                      = new FleetbaseDirectory();
            $directory->memory                              = new SyncLedger();
            $directory->memory->connections['company-uuid'] = [
                'company_uuid'     => 'company-uuid',
                'realm_id'         => 'realm-1',
                'environment'      => $environment,
                'access_token'     => 'old-access',
                'refresh_token'    => 'old-refresh',
                'token_expires_at' => null,
                'needs_reauth'     => false,
            ];
            $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
            $tokens   = new ConnectionTokens(new TokenRefresher($client), $settings, envStore(), $directory);

            $refreshed = $tokens->refreshNow($directory->memory->connections['company-uuid']);

            expect($refreshed['access_token'])->toBe('new-access')
                ->and(end($client->clientIds))->toBe($clientId);
        }
    } finally {
        Cache::swap($previous);
    }
});

test('the OAuth state remembers its environment and nothing else is needed to find it again', function () {
    $previous = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));

    try {
        $flow  = new OAuthFlow(new QuickBooksClient());
        $begun = $flow->begin('company-uuid', 'user-uuid', [
            'client_id'     => 'live-id',
            'client_secret' => 'live-secret',
            'redirect_uri'  => 'https://console.example.com/quickbooks',
            'environment'   => 'production',
        ]);

        expect($flow->environmentOf($begun['state']))->toBe('production')
            // No such flow: the answer is harmless, because the flow itself refuses an unknown state.
            ->and($flow->environmentOf('no-such-state'))->toBe('production');
    } finally {
        Cache::swap($previous);
    }
});
