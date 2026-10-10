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
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

function accessStatus(callable $action): int
{
    try {
        $action();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return 200;
}

/** The organization operator role can view settings but is not an installation administrator. */
function accessOrganizationOperator(): Authorizer
{
    $permissions = [];
    foreach ((new Quickbooks())->policies as $policy) {
        if (($policy['name'] ?? '') === 'QuickbooksOperator') {
            $permissions = $policy['permissions'];
        }
    }

    return new Authorizer(static fn (string $permission): bool => in_array($permission, $permissions, true));
}

function accessSettingsController(MemorySettingsStore $store, Authorizer $authorizer): SettingController
{
    return new SettingController(
        $authorizer,
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
}

/** @return array<string, mixed> */
function accessSavePayload(): array
{
    return [
        'scope' => 'admin',
        'auth'  => [
            'client_id'     => 'attacker-id',
            'client_secret' => 'attacker-secret',
            'environment'   => 'sandbox',
        ],
        'sync'  => qbSettings(['customer_conflict' => 'quickbooks', 'customer_reference' => 'quickbooks']),
    ];
}

test('an organization operator cannot save the install-wide settings', function () {
    session(['company' => 'company-uuid']);
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = ['client_id' => 'install-id', 'client_secret' => 'sealed', 'environment' => 'production'];
    $store->rows[SettingsKeys::adminSync()] = qbSettings();
    $before                                 = $store->rows;
    $controller                             = accessSettingsController($store, accessOrganizationOperator());

    try {
        expect(accessStatus(fn () => $controller->save(InstallAdminRequest::create('/settings', 'POST', accessSavePayload(), false))))->toBe(403)
            ->and($store->rows)->toBe($before);
    } finally {
        session(['company' => null]);
    }
});

test('a request without a signed in user cannot save the install-wide settings', function () {
    session(['company' => 'company-uuid']);
    $store      = new MemorySettingsStore();
    $controller = accessSettingsController($store, new Authorizer(static fn () => true));

    try {
        expect(accessStatus(fn () => $controller->save(Illuminate\Http\Request::create('/settings', 'POST', accessSavePayload()))))->toBe(403)
            ->and($store->rows)->toBe([]);
    } finally {
        session(['company' => null]);
    }
});

test('an installation administrator can save the install-wide settings and is told so', function () {
    session(['company' => 'company-uuid']);
    $store      = new MemorySettingsStore();
    $controller = accessSettingsController($store, new Authorizer(static fn () => false));

    try {
        $response = $controller->save(InstallAdminRequest::create('/settings', 'POST', accessSavePayload(), true));
        $data     = $response->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and($data['can_edit'])->toBeTrue()
            ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('attacker-id')
            ->and($store->rows[SettingsKeys::adminSync()]['customer_conflict'])->toBe('quickbooks');
    } finally {
        session(['company' => null]);
    }
});

test('settings show tells the console whether the caller can edit and never returns a secret', function () {
    session(['company' => 'company-uuid']);
    $cipher                                 = new SecretCipher();
    $store                                  = new MemorySettingsStore();
    $sealedSecret                           = $cipher->encrypt('super-secret-value');
    $sealedVerifier                         = $cipher->encrypt('verifier-token-value');
    $store->rows[SettingsKeys::adminAuth()] = [
        'client_id'        => 'install-id',
        'client_secret'    => $sealedSecret,
        'webhook_verifier' => $sealedVerifier,
        'environment'      => 'production',
        'redirect_uri'     => 'https://example.com/quickbooks/int/v1/oauth/callback',
    ];
    $controller = accessSettingsController($store, accessOrganizationOperator());

    try {
        $operatorView = $controller->show(InstallAdminRequest::create('/settings', 'GET', ['scope' => 'admin'], false));
        $adminView    = $controller->show(InstallAdminRequest::create('/settings', 'GET', ['scope' => 'admin'], true));

        foreach ([$operatorView, $adminView] as $view) {
            $content = (string) $view->getContent();
            expect($view->getStatusCode())->toBe(200)
                ->and($content)->not->toContain('super-secret-value')
                ->and($content)->not->toContain('verifier-token-value')
                ->and($content)->not->toContain($sealedSecret)
                ->and($content)->not->toContain($sealedVerifier)
                ->and($view->getData(true)['auth'])->not->toHaveKey('client_secret')
                ->and($view->getData(true)['auth'])->not->toHaveKey('webhook_verifier')
                ->and($view->getData(true)['auth']['client_secret_set'])->toBeTrue()
                ->and($view->getData(true)['company_auth'])->not->toHaveKey('client_secret');
        }

        expect($operatorView->getData(true)['can_edit'])->toBeFalse()
            ->and($adminView->getData(true)['can_edit'])->toBeTrue();
    } finally {
        session(['company' => null]);
    }
});

test('the connection test is for installation administrators and does not read the request path', function () {
    session(['company' => 'company-uuid']);
    $controller = new ConnectionController(
        accessOrganizationOperator(),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        new MemorySettingsStore(),
        new ConnectionProbe(new QuickBooksClient())
    );

    try {
        // The path says nothing about a test. The action itself is the admin-only call.
        expect(accessStatus(fn () => $controller->test(InstallAdminRequest::create('/quickbooks/int/v1/connection', 'POST', [], false))))->toBe(403);
    } finally {
        session(['company' => null]);
    }
});

test('the authorizer takes the request from the caller and never searches the call stack', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Support/Authorizer.php');

    expect($source)->not->toContain('debug_backtrace')
        ->and($source)->not->toContain('connection/test');

    $granting = new Authorizer(static fn (): bool => true);
    $denying  = new Authorizer(static fn (): bool => false);
    $member   = InstallAdminRequest::create('/anywhere', 'GET', [], false);
    $admin    = InstallAdminRequest::create('/anywhere', 'GET', [], true);

    expect(accessStatus(fn () => $granting->check('quickbooks view connection', $member)))->toBe(200)
        ->and(accessStatus(fn () => $denying->check('quickbooks view connection', $member)))->toBe(403)
        ->and(accessStatus(fn () => $denying->check('quickbooks view connection', $admin)))->toBe(200)
        ->and(accessStatus(fn () => $granting->checkInstallationAdmin($member)))->toBe(403)
        ->and(accessStatus(fn () => $granting->checkInstallationAdmin($admin)))->toBe(200)
        ->and(accessStatus(fn () => $granting->checkInstallationAdmin()))->toBe(403)
        ->and($granting->isInstallationAdmin($admin))->toBeTrue()
        ->and($granting->isInstallationAdmin($member))->toBeFalse()
        ->and($granting->isInstallationAdmin())->toBeFalse();
});

test('connect disconnect and sync stay on the per organization permissions', function () {
    $operator = accessOrganizationOperator();
    $member   = InstallAdminRequest::create('/anywhere', 'POST', [], false);

    expect(accessStatus(fn () => $operator->check('quickbooks connect connection', $member)))->toBe(200)
        ->and(accessStatus(fn () => $operator->check('quickbooks disconnect connection', $member)))->toBe(200)
        ->and(accessStatus(fn () => $operator->check('quickbooks reconcile sync', $member)))->toBe(200);
});

test('the permission schema no longer offers update settings, which nothing checks', function () {
    $schema   = new Quickbooks();
    $settings = [];
    foreach ($schema->resources as $resource) {
        if ($resource['name'] === 'settings') {
            $settings = $resource;
        }
    }
    $granted = [];
    foreach ($schema->policies as $policy) {
        $granted = array_merge($granted, $policy['permissions']);
    }

    expect($settings['remove_actions'])->toContain('update')
        ->and($granted)->not->toContain('quickbooks update settings')
        ->and($granted)->toContain('quickbooks view settings');
});
