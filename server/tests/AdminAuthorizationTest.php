<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\InstallationAdmin;
use Fleetbase\Quickbooks\Support\PublicHttps;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

function installUser(bool $admin): object
{
    return new class($admin) {
        public function __construct(private bool $admin)
        {
        }

        public function isAdmin(): bool
        {
            return $this->admin;
        }
    };
}

function installRequest(string $path, string $method, bool $admin, array $input = []): Request
{
    $request = Request::create($path, $method, array_merge(['company_uuid' => 'company-uuid'], $input));
    $request->setUserResolver(static fn () => installUser($admin));

    return $request;
}

function installStatus(callable $action): int
{
    try {
        $action();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return 200;
}

function installAuthorizer(): Authorizer
{
    return new Authorizer(static fn () => true);
}

function installSettingsController(MemorySettingsStore $store, ?Authorizer $authorizer = null): SettingController
{
    return new SettingController(
        $authorizer ?? installAuthorizer(),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
}

function installConnectionController(?Authorizer $authorizer = null): ConnectionController
{
    return new ConnectionController(
        $authorizer ?? installAuthorizer(),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new ConnectionProbe(new QuickBooksClient())
    );
}

test('an installation administrator is user isAdmin and not an organization role', function () {
    $admin = new class {
        public string $type = 'admin';

        public function isAdmin(): bool
        {
            return $this->type === 'admin';
        }
    };
    $member = new class {
        public string $type = 'user';

        public function isAdmin(): bool
        {
            return false;
        }

        public function hasRole(string $role): bool
        {
            return $role === 'Administrator';
        }
    };

    expect(InstallationAdmin::isInstallationAdmin($admin))->toBeTrue()
        ->and(InstallationAdmin::isInstallationAdmin($member))->toBeFalse()
        ->and(InstallationAdmin::isInstallationAdmin(null))->toBeFalse();
});

test('install routes require fleetbase admin guard and the callback stays public', function () {
    $routes      = (string) file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $adminAt     = strpos($routes, 'Fleetbase\\Http\\Middleware\\AdminGuard::class');
    $protectedAt = strpos($routes, "'middleware' => ['fleetbase.protected']");

    expect($adminAt)->not->toBeFalse()
        ->and($protectedAt)->not->toBeFalse()
        ->and($protectedAt)->toBeLessThan($adminAt);

    foreach ([
        "get('settings'",
        "post('settings'",
        "post('oauth/start'",
        "post('oauth/complete'",
        "post('disconnect'",
        "post('import'",
        "post('reconcile'",
        "post('sync'",
        "post('connection/test'",
    ] as $route) {
        expect(strpos($routes, $route))->toBeGreaterThan($adminAt);
    }

    foreach ([
        "get('connection'",
        "get('batches'",
        "get('summary'",
        "get('v1/oauth/callback'",
        "post('v1/webhooks'",
    ] as $route) {
        expect(strpos($routes, $route))->toBeLessThan($adminAt);
    }
});

test('a non-admin with a quickbooks permission cannot read or write install settings', function () {
    session(['company' => 'company-uuid']);
    $store      = new MemorySettingsStore();
    $controller = installSettingsController($store);

    try {
        expect(installStatus(fn () => $controller->show(installRequest('/settings', 'GET', false, ['scope' => 'admin']))))->toBe(403)
            ->and($store->asked)->toBe([])
            ->and(installStatus(fn () => $controller->save(installRequest('/settings', 'POST', false, [
                'scope' => 'admin',
                'auth'  => ['client_id' => 'id', 'client_secret' => 'secret'],
                'sync'  => ['batch_size' => 10],
            ]))))->toBe(403)
            ->and($store->rows)->toBe([]);

        $shown = $controller->show(installRequest('/settings', 'GET', true, ['scope' => 'admin']));
        expect($shown->getStatusCode())->toBe(200)
            ->and($store->asked)->not->toBe([]);
    } finally {
        session(['company' => null]);
    }
});

test('a non-admin cannot connect disconnect sync import reconcile or test the install connection', function () {
    session(['company' => 'company-uuid']);
    $controller = installConnectionController();
    $actions    = [
        ['/quickbooks/int/v1/oauth/start', 'POST', 'start'],
        ['/quickbooks/int/v1/oauth/complete', 'POST', 'complete'],
        ['/quickbooks/int/v1/disconnect', 'POST', 'disconnect'],
        ['/quickbooks/int/v1/import', 'POST', 'import'],
        ['/quickbooks/int/v1/reconcile', 'POST', 'reconcile'],
        ['/quickbooks/int/v1/sync', 'POST', 'sync'],
        ['/quickbooks/int/v1/connection/test', 'POST', 'test'],
    ];

    try {
        foreach ($actions as [$path, $method, $action]) {
            $status = installStatus(fn () => $controller->{$action}(installRequest($path, $method, false)));
            expect($status)->toBe(403);
        }

        $authorizer = installAuthorizer();
        $view       = static function (string $permission, Request $request) use ($authorizer): int {
            $call = static function (string $permission, Request $request) use ($authorizer): void {
                $authorizer->check($permission);
            };

            return installStatus(static fn () => $call($permission, $request));
        };

        expect($view('quickbooks view connection', installRequest('/quickbooks/int/v1/connection', 'GET', false)))->toBe(200)
            ->and($view('quickbooks view sync', installRequest('/quickbooks/int/v1/summary', 'GET', false)))->toBe(200)
            ->and($view('quickbooks view settings', installRequest('/quickbooks/int/v1/settings', 'GET', false)))->toBe(403);
    } finally {
        session(['company' => null]);
    }
});

test('the redirect sent to intuit must be public https', function () {
    expect(PublicHttps::isPublicHttpsUrl('https://public.example.test/quickbooks/int/v1/oauth/callback'))->toBeTrue();

    foreach ([
        'http://public.example.test/callback',
        'https://10.0.0.5/callback',
        'https://192.168.1.9/callback',
        'https://172.16.5.5/callback',
        'https://127.0.0.2/callback',
        'https://169.254.169.254/callback',
        'https://localhost/callback',
        'https://127.0.0.1/callback',
        'https://[::1]/callback',
        'https://[fe80::1]/callback',
        'https://[fd00::1]/callback',
        'https://user:secret@public.example.test/callback',
        '/quickbooks/int/v1/oauth/callback',
    ] as $url) {
        expect(PublicHttps::isPublicHttpsUrl($url))->toBeFalse();
    }
});
