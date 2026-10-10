<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
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

/** @return array<int, string> */
function operatorPermissions(): array
{
    $schema = new Quickbooks();
    foreach ($schema->policies as $policy) {
        if (($policy['name'] ?? '') === 'QuickbooksOperator') {
            return $policy['permissions'];
        }
    }

    return [];
}

function operatorAuthorizer(): Authorizer
{
    $permissions = operatorPermissions();

    return new Authorizer(static fn (string $permission): bool => in_array($permission, $permissions, true));
}

function deniedAuthorizer(): Authorizer
{
    return new Authorizer(static fn (): bool => false);
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

test('operator routes stay signed in and the connection test stays on the admin guard', function () {
    $routes      = (string) file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $adminAt     = strpos($routes, 'Fleetbase\\Http\\Middleware\\AdminGuard::class');
    $protectedAt = strpos($routes, "'middleware' => ['fleetbase.protected']");

    expect($adminAt)->not->toBeFalse()
        ->and($protectedAt)->not->toBeFalse()
        ->and($protectedAt)->toBeLessThan($adminAt);

    foreach ([
        "get('connection'",
        "get('batches'",
        "get('summary'",
        "get('settings'",
        "post('settings'",
        "post('oauth/start'",
        "post('oauth/complete'",
        "post('disconnect'",
        "post('import'",
        "post('reconcile'",
        "post('sync'",
    ] as $route) {
        $at = strpos($routes, $route);
        expect($at)->toBeGreaterThan($protectedAt)
            ->and($at)->toBeLessThan($adminAt);
    }

    expect(strpos($routes, "post('connection/test'"))->toBeGreaterThan($adminAt);

    foreach ([
        "get('v1/oauth/callback'",
        "post('v1/webhooks'",
    ] as $route) {
        expect(strpos($routes, $route))->toBeLessThan($protectedAt);
    }
});

test('an operator can read install settings and a user without the permission cannot', function () {
    session(['company' => 'company-uuid']);
    $store    = new MemorySettingsStore();
    $operator = installSettingsController($store, operatorAuthorizer());
    $denied   = installSettingsController($store, deniedAuthorizer());
    $admin    = installSettingsController($store, deniedAuthorizer());

    try {
        expect(installStatus(fn () => $denied->show(installRequest('/settings', 'GET', false, ['scope' => 'admin']))))->toBe(403)
            ->and($store->asked)->toBe([])
            ->and(installStatus(fn () => $denied->save(installRequest('/settings', 'POST', false, [
                'scope' => 'admin',
                'auth'  => ['client_id' => 'id', 'client_secret' => 'secret'],
                'sync'  => ['batch_size' => 10],
            ]))))->toBe(403)
            ->and($store->rows)->toBe([]);

        $shown = $operator->show(installRequest('/settings', 'GET', false, ['scope' => 'admin']));
        expect($shown->getStatusCode())->toBe(200)
            ->and($store->asked)->not->toBe([]);

        $adminShown = $admin->show(installRequest('/settings', 'GET', true, ['scope' => 'admin']));
        expect($adminShown->getStatusCode())->toBe(200);
    } finally {
        session(['company' => null]);
    }
});

test('a user without a quickbooks permission cannot connect disconnect sync import reconcile or test', function () {
    session(['company' => 'company-uuid']);
    $controller = installConnectionController(deniedAuthorizer());
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
    } finally {
        session(['company' => null]);
    }
});

test('an operator can sync now and an installation admin still can', function () {
    $permissionStatus = static function (Authorizer $authorizer): callable {
        return static function (string $permission, Request $request) use ($authorizer): int {
            $call = static function (string $permission, Request $request) use ($authorizer): void {
                $authorizer->check($permission, $request);
            };

            return installStatus(static fn () => $call($permission, $request));
        };
    };
    $adminOnlyStatus = static function (Authorizer $authorizer, Request $request): int {
        return installStatus(static fn () => $authorizer->checkInstallationAdmin($request));
    };

    $operator = $permissionStatus(operatorAuthorizer());
    $admin    = $permissionStatus(deniedAuthorizer());
    $widget   = (string) file_get_contents(dirname(__DIR__, 2) . '/addon/utils/sync-access.js');

    expect(operatorPermissions())->toContain('quickbooks reconcile sync')
        ->and($widget)->toContain('quickbooks reconcile sync')
        ->and($operator('quickbooks reconcile sync', installRequest('/quickbooks/int/v1/sync', 'POST', false)))->toBe(200)
        ->and($operator('quickbooks connect connection', installRequest('/quickbooks/int/v1/oauth/start', 'POST', false)))->toBe(200)
        ->and($operator('quickbooks import-customers connection', installRequest('/quickbooks/int/v1/import', 'POST', false)))->toBe(200)
        ->and($operator('quickbooks view settings', installRequest('/quickbooks/int/v1/settings', 'GET', false)))->toBe(200)
        ->and($operator('quickbooks view connection', installRequest('/quickbooks/int/v1/connection', 'GET', false)))->toBe(200)
        ->and($operator('quickbooks update sync', installRequest('/quickbooks/int/v1/sync', 'POST', false)))->toBe(403)
        ->and($admin('quickbooks reconcile sync', installRequest('/quickbooks/int/v1/sync', 'POST', true)))->toBe(200)
        ->and($admin('quickbooks reconcile sync', installRequest('/quickbooks/int/v1/sync', 'POST', false)))->toBe(403)
        ->and($adminOnlyStatus(operatorAuthorizer(), installRequest('/quickbooks/int/v1/connection/test', 'POST', false)))->toBe(403)
        ->and($adminOnlyStatus(deniedAuthorizer(), installRequest('/quickbooks/int/v1/connection/test', 'POST', true)))->toBe(200);
});

test('the redirect sent to intuit must be public https', function () {
    expect(PublicHttps::isPublicHttpsUrl('https://example.com/quickbooks/int/v1/oauth/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://1.1.1.1/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://8.8.8.8/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[2606:4700:4700::1111]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[::ffff:8.8.8.8]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://100.128.0.1/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[2002:101:101::]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[2002:808:808::]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[2002:6480:1::]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[64:ff9b::101:101]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[64:ff9b::808:808]/callback'))->toBeTrue()
        ->and(PublicHttps::isPublicHttpsUrl('https://[64:ff9b::6480:1]/callback'))->toBeTrue();

    foreach ([
        'http://example.com/callback',
        'https://10.0.0.5/callback',
        'https://192.168.1.9/callback',
        'https://172.16.5.5/callback',
        'https://127.0.0.2/callback',
        'https://127.1/callback',
        'https://127.0.1/callback',
        'https://0177.0.0.1/callback',
        'https://2130706433/callback',
        'https://0x7f000001/callback',
        'https://127.000.000.001/callback',
        'https://0x7f.0.0.1/callback',
        'https://0.0.0.0/callback',
        'https://0.255.255.255/callback',
        'https://100.64.0.1/callback',
        'https://100.127.255.255/callback',
        'https://169.254.169.254/callback',
        'https://192.0.0.1/callback',
        'https://192.0.2.1/callback',
        'https://198.18.0.1/callback',
        'https://198.51.100.1/callback',
        'https://203.0.113.1/callback',
        'https://224.0.0.1/callback',
        'https://239.255.255.255/callback',
        'https://240.0.0.1/callback',
        'https://255.255.255.255/callback',
        'https://192.168.08.1/callback',
        'https://1.2.3.4.5/callback',
        'https://localhost/callback',
        'https://127.0.0.1/callback',
        'https://[::]/callback',
        'https://[::1]/callback',
        'https://[::7f00:1]/callback',
        'https://[fe80::1]/callback',
        'https://[fec0::1]/callback',
        'https://[fd00::1]/callback',
        'https://[ff02::1]/callback',
        'https://[2001:db8::1]/callback',
        'https://[::ffff:127.0.0.1]/callback',
        'https://[::ffff:7f00:1]/callback',
        'https://[::ffff:192.0.2.1]/callback',
        'https://[2002:7f00:1::]/callback',
        'https://[2002:a00:1::]/callback',
        'https://[2002:ac10:505::]/callback',
        'https://[2002:c0a8:1::]/callback',
        'https://[2002:a9fe:a9fe::]/callback',
        'https://[2002:6440:1::]/callback',
        'https://[2002:c000:201::]/callback',
        'https://[2002:c633:6401::]/callback',
        'https://[2002:cb00:7101::]/callback',
        'https://[2002:e000:1::]/callback',
        'https://[2002:0:1::]/callback',
        'https://[2002:c000:1::]/callback',
        'https://[2002:c612:1::]/callback',
        'https://[2002:f000:1::]/callback',
        'https://[64:ff9b::a9fe:a9fe]/callback',
        'https://[64:ff9b::a00:1]/callback',
        'https://[64:ff9b::c0a8:101]/callback',
        'https://[64:ff9b::7f00:1]/callback',
        'https://[64:ff9b::ac10:505]/callback',
        'https://[64:ff9b::6440:1]/callback',
        'https://[64:ff9b::c000:201]/callback',
        'https://[64:ff9b::c633:6401]/callback',
        'https://[64:ff9b::cb00:7101]/callback',
        'https://[64:ff9b::e000:1]/callback',
        'https://[64:ff9b::1]/callback',
        'https://[64:ff9b::c000:1]/callback',
        'https://[64:ff9b::c612:1]/callback',
        'https://[64:ff9b::f000:1]/callback',
        'https://127.0.0.1.nip.io/callback',
        'https://public.example.test/callback',
        'https://no-such-host.invalid/callback',
        'https://user:secret@example.com/callback',
        '/quickbooks/int/v1/oauth/callback',
    ] as $url) {
        expect(PublicHttps::isPublicHttpsUrl($url))->toBeFalse();
    }
});
