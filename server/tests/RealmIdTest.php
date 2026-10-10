<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** @return array{client_id: string, client_secret: string, redirect_uri: string, environment: string} */
function realmCredentials(): array
{
    return [
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ];
}

/**
 * An OAuth client with no network. $firstCall is thrown by the first Intuit call for the realm,
 * $secondCall by the service item call.
 */
function realmClient(?Throwable $firstCall = null, ?Throwable $secondCall = null): QuickBooksClient
{
    return new class($firstCall, $secondCall) extends QuickBooksClient {
        public int $exchanges = 0;

        public function __construct(private ?Throwable $firstCall, private ?Throwable $secondCall)
        {
        }

        public function authorizationUrl(array $credentials, string $state, ?string $codeChallenge = null): string
        {
            return 'https://appcenter.intuit.com/connect/oauth2?state=' . $state;
        }

        public function exchangeCode(array $credentials, string $code, ?string $codeVerifier = null): array
        {
            $this->exchanges++;

            return ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600];
        }

        public function homeCurrency(array $connection): ?string
        {
            if ($this->firstCall !== null) {
                throw $this->firstCall;
            }

            return 'USD';
        }

        public function ensureServiceItem(array $connection): string
        {
            if ($this->secondCall !== null) {
                throw $this->secondCall;
            }

            return '7';
        }
    };
}

test('receive accepts a numeric realm of 6 to 20 digits', function () {
    $flow = new OAuthFlow(realmClient());

    foreach (['123456', '9341453000000001', '12345678901234567890'] as $realm) {
        $begun  = $flow->begin('company-uuid', 'user-uuid', realmCredentials());
        $handle = $flow->receive($begun['state'], 'code', $realm);

        expect($handle)->toBeString()
            ->and($flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials())['realm_id'])->toBe($realm);
    }
});

test('receive refuses a realm that is not 6 to 20 digits and burns the state', function () {
    $flow = new OAuthFlow(realmClient());

    foreach (['', '12345', '123456789012345678901', 'realm-1', '12345678/../../x', '123456 ', "123456\n", '１２３４５６７', '123456?x=1', '123456%2F1'] as $realm) {
        $begun = $flow->begin('company-uuid', 'user-uuid', realmCredentials());

        expect(fn () => $flow->receive($begun['state'], 'code', $realm))
            ->toThrow(QuickBooksException::class, 'QuickBooks authorization state is invalid or expired.')
            // The state was pulled, so the same state cannot be tried again with a good realm.
            ->and(fn () => $flow->receive($begun['state'], 'code', '9341453000000001'))
            ->toThrow(QuickBooksException::class);
    }
});

test('the oauth callback sends a bad realm back to the console as a state error', function () {
    $flow       = new OAuthFlow(realmClient());
    $controller = new ConnectionController(
        new Authorizer(static fn () => true),
        $flow,
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new ConnectionProbe(new QuickBooksClient())
    );
    $begun    = $flow->begin('company-uuid', 'user-uuid', realmCredentials());
    $response = $controller->callback(Request::create('/oauth/callback', 'GET', [
        'state'   => $begun['state'],
        'code'    => 'code',
        'realmId' => '1/../../admin',
    ]));

    expect($response->getTargetUrl())->toContain('error=state')
        ->and($response->getTargetUrl())->not->toContain('oauth_state=');
});

test('the realm is url encoded when the API url is built', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        '*' => Http::response(['CompanyInfo' => ['CompanyName' => 'Acme'], 'Preferences' => []], 200),
    ]);
    $connection = [
        'realm_id'     => '123/../456?x=1#y',
        'access_token' => 'token',
        'environment'  => 'sandbox',
    ];
    $client = new QuickBooksClient();

    $client->companyInfo($connection);
    $client->homeCurrency($connection);

    $urls = [];
    foreach (Http::recorded() as [$request]) {
        $urls[] = $request->url();
    }

    expect($urls)->toHaveCount(2);
    foreach ($urls as $url) {
        expect($url)->toStartWith('https://sandbox-quickbooks.api.intuit.com/v3/company/123%2F..%2F456%3Fx%3D1%23y/')
            ->and(substr_count($url, '?'))->toBe(1);
    }
});

test('a first call that answers 401 403 or 404 means the token is not for the realm and nothing is stored', function () {
    foreach ([401, 403, 404] as $status) {
        $client = realmClient(new QuickBooksException($status, 'QuickBooks request failed with status ' . $status . ': secret detail'));
        $flow   = new OAuthFlow($client);
        $begun  = $flow->begin('company-uuid', 'user-uuid', realmCredentials());
        $handle = $flow->receive($begun['state'], 'code', '9341453000000001');

        expect(fn () => $flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials()))
            ->toThrow(QuickBooksException::class, 'QuickBooks could not finish connecting. Connect again from Quickbooks Setup.');

        // The handle is spent and is not marked done, so a reload cannot report a connection.
        try {
            $flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials());
            expect(false)->toBeTrue();
        } catch (QuickBooksException $exception) {
            expect($exception->status)->toBe(400)
                ->and($exception->getMessage())->not->toContain('secret detail');
        }
    }
});

test('the generic connect failure message never carries the Intuit detail', function () {
    $flow   = new OAuthFlow(realmClient(new QuickBooksException(403, 'QuickBooks request failed with status 403: Forbidden realm 9341453000000001')));
    $begun  = $flow->begin('company-uuid', 'user-uuid', realmCredentials());
    $handle = $flow->receive($begun['state'], 'code', '9341453000000001');

    try {
        $flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials());
        expect(false)->toBeTrue();
    } catch (QuickBooksException $exception) {
        expect($exception->getMessage())->toBe('QuickBooks could not finish connecting. Connect again from Quickbooks Setup.')
            ->and($exception->status)->toBe(403);
    }
});

test('other first call failures are tolerated and the connection is still returned', function () {
    foreach ([0, 400, 429, 500, 503] as $status) {
        $flow   = new OAuthFlow(realmClient(new QuickBooksException($status, 'QuickBooks request failed')));
        $begun  = $flow->begin('company-uuid', 'user-uuid', realmCredentials());
        $handle = $flow->receive($begun['state'], 'code', '9341453000000001');

        $connection = $flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials());

        expect($connection)->toBeArray()
            ->and($connection['realm_id'])->toBe('9341453000000001')
            ->and($connection['home_currency'])->toBeNull()
            ->and($connection['default_item_id'])->toBeNull()
            ->and($flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials()))->toBeNull();
    }
});

test('a service item failure after a good first call is tolerated even when it is a 401', function () {
    $flow   = new OAuthFlow(realmClient(null, new QuickBooksException(401, 'QuickBooks request failed with status 401')));
    $begun  = $flow->begin('company-uuid', 'user-uuid', realmCredentials());
    $handle = $flow->receive($begun['state'], 'code', '9341453000000001');

    $connection = $flow->complete($handle, 'company-uuid', 'user-uuid', realmCredentials());

    expect($connection)->toBeArray()
        ->and($connection['home_currency'])->toBeNull()
        ->and($connection['default_item_id'])->toBeNull();
});
