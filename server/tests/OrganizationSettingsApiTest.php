<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Jobs\QueueGlobalScope;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\CapturedJobs;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

function orgApiStatus(callable $action): int
{
    try {
        $action();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return 200;
}

/** Permissions of a schema policy. A "quickbooks * settings" grant covers every action the schema offers. */
function orgApiAuthorizer(string $policyName): Authorizer
{
    $schema  = new Quickbooks();
    $granted = [];
    foreach ($schema->policies as $policy) {
        if ($policy['name'] === $policyName) {
            $granted = $policy['permissions'];
        }
    }
    $offered = [];
    foreach ($schema->resources as $resource) {
        if ($resource['name'] === 'settings') {
            $offered = array_values(array_diff(['view', 'create', 'update', 'delete', 'list'], $resource['remove_actions'] ?? []));
        }
    }

    return new Authorizer(static function (string $permission) use ($granted, $offered): bool {
        if (in_array($permission, $granted, true) === true) {
            return true;
        }
        foreach ($offered as $action) {
            if ($permission === 'quickbooks ' . $action . ' settings' && in_array('quickbooks * settings', $granted, true) === true) {
                return true;
            }
        }

        return false;
    });
}

function orgApiController(MemorySettingsStore $store, Authorizer $authorizer): SettingController
{
    return new SettingController(
        $authorizer,
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
}

function orgApiStore(): MemorySettingsStore
{
    $cipher                                 = new SecretCipher();
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = [
        'client_id'        => 'global-client-id',
        'client_secret'    => $cipher->encrypt('global-secret-value'),
        'webhook_verifier' => $cipher->encrypt('global-verifier-value'),
        'environment'      => 'production',
        'redirect_uri'     => 'https://example.com/quickbooks/int/v1/oauth/callback',
    ];
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 15, 'wallet_enabled' => false]);

    return $store;
}

/** @param array<string, mixed> $body */
function orgApiPost(array $body, bool $admin = false): Illuminate\Http\Request
{
    return InstallAdminRequest::create('/settings', 'POST', $body, $admin);
}

function orgApiGet(array $query = [], bool $admin = false): Illuminate\Http\Request
{
    return InstallAdminRequest::create('/settings', 'GET', $query, $admin);
}

beforeEach(fn () => session(['company' => 'company-a']));
afterEach(fn () => session(['company' => null]));

test('the company view carries sync options only and never a global setting', function () {
    $store = orgApiStore();
    $store->putCompanySync('company-a', ['interval_minutes' => 30]);
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));

    foreach ([orgApiGet(), orgApiGet(['scope' => 'company'])] as $request) {
        $response = $controller->show($request);
        $json     = (string) $response->getContent();
        $data     = $response->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and(array_keys($data))->toBe(['can_edit', 'override', 'sync', 'global_sync', 'company_sync', 'credentials_configured'])
            ->and($data['can_edit'])->toBeFalse()
            ->and($data['override'])->toBeTrue()
            ->and($data['credentials_configured'])->toBeTrue()
            ->and($data['company_sync'])->toBe(['interval_minutes' => 30])
            ->and($data['global_sync']['interval_minutes'])->toBe(15)
            ->and($data['sync']['interval_minutes'])->toBe(30);

        foreach (['client_id', 'client_secret', 'webhook_verifier', 'environment', 'redirect_uri', 'auth', 'sources'] as $key) {
            expect($json)->not->toContain('"' . $key);
            foreach (['sync', 'global_sync', 'company_sync'] as $bag) {
                expect($data[$bag])->not->toHaveKey($key);
            }
        }
        expect($json)->not->toContain('_url')
            ->and($json)->not->toContain('client_secret_set')
            ->and($json)->not->toContain('global-client-id')
            ->and($json)->not->toContain('global-secret-value')
            ->and($json)->not->toContain('global-verifier-value')
            ->and($json)->not->toContain($store->rows[SettingsKeys::adminAuth()]['client_secret'])
            ->and($json)->not->toContain($store->rows[SettingsKeys::adminAuth()]['webhook_verifier']);
    }
});

test('an organization with no override inherits the global sync options exactly', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));
    $data       = $controller->show(orgApiGet())->getData(true);

    expect($data['override'])->toBeFalse()
        ->and($data['company_sync'])->toBe([])
        ->and($data['sync'])->toBe($data['global_sync'])
        ->and($data['sync']['interval_minutes'])->toBe(15);
});

test('credentials_configured is false until the global client id and secret are both set', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = ['client_id' => 'only-an-id'];
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));

    expect($controller->show(orgApiGet())->getData(true)['credentials_configured'])->toBeFalse();
});

test('a user without view settings cannot read the company view', function () {
    $controller = orgApiController(orgApiStore(), new Authorizer(static fn (): bool => false));

    expect(orgApiStatus(fn () => $controller->show(orgApiGet())))->toBe(403);
});

test('the schema offers update on settings and only the administrator policy grants it', function () {
    $settings = [];
    foreach ((new Quickbooks())->resources as $resource) {
        if ($resource['name'] === 'settings') {
            $settings = $resource;
        }
    }
    $operator = orgApiAuthorizer('QuickbooksOperator');
    $admin    = orgApiAuthorizer('QuickbooksAdministrator');

    expect($settings['remove_actions'])->not->toContain('update')
        ->and(orgApiStatus(fn () => $admin->check('quickbooks update settings')))->toBe(200)
        ->and(orgApiStatus(fn () => $operator->check('quickbooks update settings')))->toBe(403)
        ->and(orgApiStatus(fn () => $operator->check('quickbooks view settings')))->toBe(200);
});

test('a QuickbooksOperator cannot save an override and nothing is written', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));

    expect(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 30]]))))->toBe(403)
        ->and(orgApiStatus(fn () => $controller->save(orgApiPost(['override' => false]))))->toBe(403)
        ->and($store->rows)->toBe($before);
});

test('a QuickbooksAdministrator saves an override that writes only this organizations sync row', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost([
        'scope'        => 'company',
        'company_uuid' => 'company-a',
        'override'     => true,
        'sync'         => [
            'interval_minutes'            => 30,
            'customer_enabled'            => false,
            'client_id'                   => 'smuggled',
            'webhook_url'                 => 'https://evil.test',
            'public_webhook_receiver_url' => 'https://evil.test',
        ],
    ]));
    $data = $response->getData(true);

    $written = array_diff_key($store->rows, $before);
    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($written))->toBe([SettingsKeys::companySync('company-a')])
        ->and(array_diff_key($store->rows, $written))->toBe($before)
        ->and($store->rows[SettingsKeys::companySync('company-a')])->toBe([
            'interval_minutes'                => 30,
            'customer_enabled'                => false,
            SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE,
            'override'                        => true,
        ])
        ->and($data['override'])->toBeTrue()
        ->and($data['can_edit'])->toBeTrue()
        ->and($data['company_sync'])->toBe(['interval_minutes' => 30, 'customer_enabled' => false])
        ->and($data['sync']['interval_minutes'])->toBe(30)
        ->and($data['sync']['customer_enabled'])->toBeFalse()
        ->and($data['global_sync']['interval_minutes'])->toBe(15)
        ->and((string) $response->getContent())->not->toContain('global-client-id');
});

test('an installation administrator can save an override without a role', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['retry_limit' => 9]], true));

    expect($response->getStatusCode())->toBe(200)
        ->and($store->companySync('company-a'))->toBe(['retry_limit' => 9]);
});

test('a company save with an auth key or an unknown key is refused and writes nothing', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    foreach ([
        ['auth' => ['client_id' => 'x']],
        ['auth' => []],
        ['environment' => 'sandbox'],
        ['client_secret' => 'x'],
    ] as $extra) {
        $response = $controller->save(orgApiPost(array_merge(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 30]], $extra)));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['message'])->toContain('Admin')
            ->and($store->rows)->toBe($before);
    }
});

test('a company save without the override flag is refused and writes nothing', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    expect($controller->save(orgApiPost(['scope' => 'company', 'sync' => ['interval_minutes' => 30]]))->getStatusCode())->toBe(422)
        ->and($store->rows)->toBe($before);
});

test('an invalid override is refused with sync errors and writes nothing', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 0, 'batch_size' => 500]]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors'])->toHaveKeys(['interval_minutes', 'batch_size'])
        ->and($response->getData(true)['errors'])->not->toHaveKey('client_id')
        ->and($store->rows)->toBe($before);
});

test('company credentials are never validated at company scope', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings();
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 20]]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['credentials_configured'])->toBeFalse();
});

test('override false clears the override and the organization inherits again', function () {
    $store = orgApiStore();
    $store->putCompanySync('company-a', ['interval_minutes' => 30]);
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'override' => false, 'sync' => ['interval_minutes' => 99]]));
    $data     = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($store->rows[SettingsKeys::companySync('company-a')])->toBe([
            SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE,
            'override'                        => false,
        ])
        ->and($data['override'])->toBeFalse()
        ->and($data['company_sync'])->toBe([])
        ->and($data['sync']['interval_minutes'])->toBe(15);
});

test('a later override replaces the existing one', function () {
    $store = orgApiStore();
    $store->putCompanySync('company-a', ['interval_minutes' => 30]);
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['retry_limit' => 7, 'batch_size' => '', 'periodic_interval_hours' => null]]));

    expect($store->companySync('company-a'))->toBe(['retry_limit' => 7]);
});

test('an organization that overrides one field follows later global changes to the others', function () {
    $store = orgApiStore();
    $org   = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));
    $org->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 45]]));

    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 15, 'wallet_enabled' => false, 'retry_limit' => 9]);
    $data                                   = $org->show(orgApiGet())->getData(true);

    expect($store->companySync('company-a'))->toBe(['interval_minutes' => 45])
        ->and($data['sync']['interval_minutes'])->toBe(45)
        ->and($data['sync']['retry_limit'])->toBe(9);
});

test('an inherited legacy conflict value does not block an override that leaves it alone', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['invoice_conflict' => 'report']);
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $ok  = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 20]]));
    $bad = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['invoice_enabled' => true]]));

    expect($ok->getStatusCode())->toBe(200)
        ->and($store->companySync('company-a'))->toBe(['interval_minutes' => 20])
        ->and($bad->getStatusCode())->toBe(422)
        ->and($bad->getData(true)['errors'])->toHaveKey('invoice_conflict');
});

test('an enabled flag in an override must be boolean-like', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    foreach (['maybe', ['x'], 2] as $value) {
        $response = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['customer_enabled' => $value]]));
        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['errors'])->toHaveKey('customer_enabled');
    }
    foreach ([true, false, 1, 0, 'true', 'false', '1', '0'] as $value) {
        $response = $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['customer_enabled' => $value]]));
        expect($response->getStatusCode())->toBe(200);
    }
});

test('a scope that is not a string is refused', function () {
    $controller = orgApiController(orgApiStore(), orgApiAuthorizer('QuickbooksAdministrator'));

    expect(orgApiStatus(fn () => $controller->show(orgApiGet(['scope' => ['company']]))))->toBe(422)
        ->and(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => ['company'], 'override' => true, 'sync' => []]))))->toBe(422);
});

test('a company uuid naming another organization is refused for both scopes', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    expect(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => 'company', 'company_uuid' => 'company-b', 'override' => true, 'sync' => ['retry_limit' => 7]]))))->toBe(403)
        ->and(orgApiStatus(fn () => $controller->show(orgApiGet(['scope' => 'company', 'company_uuid' => 'company-b']))))->toBe(403)
        ->and($store->rows)->toBe($before);
});

test('an unknown scope is refused', function () {
    $controller = orgApiController(orgApiStore(), orgApiAuthorizer('QuickbooksAdministrator'));

    expect(orgApiStatus(fn () => $controller->show(orgApiGet(['scope' => 'system']))))->toBe(422);
});

test('the admin scope is for installation administrators only', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));
    $body       = ['scope' => 'admin', 'auth' => ['client_id' => 'hijack'], 'sync' => qbSettings()];

    expect(orgApiStatus(fn () => $controller->show(orgApiGet(['scope' => 'admin']))))->toBe(403)
        ->and(orgApiStatus(fn () => $controller->save(orgApiPost($body))))->toBe(403)
        ->and($store->rows)->toBe($before);

    $shown = $controller->show(orgApiGet(['scope' => 'admin'], true))->getData(true);

    expect($shown['can_edit'])->toBeTrue()
        ->and($shown['auth']['client_id'])->toBe('global-client-id')
        ->and($shown['auth']['client_secret_set'])->toBeTrue()
        ->and($shown)->toHaveKeys(['sync', 'internal_webhook_receiver_url', 'public_oauth_redirect_url']);
});

test('the admin scope saves the global rows and never an organization row', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $response = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id'],
        'sync'  => qbSettings(['interval_minutes' => 20]),
    ], true));

    expect($response->getStatusCode())->toBe(200)
        ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('new-global-id')
        ->and($store->rows[SettingsKeys::adminSync()]['interval_minutes'])->toBe(20)
        ->and(array_key_exists(SettingsKeys::companySync('company-a'), $store->rows))->toBeFalse()
        ->and(array_key_exists(SettingsKeys::companyAuth('company-a'), $store->rows))->toBeFalse();
});

test('queueing diffs effective settings, so first saving an override equal to inherited on values queues nothing', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['customer_enabled' => true, 'invoice_enabled' => true, 'wallet_enabled' => true]);
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $jobs = CapturedJobs::capture(function () use ($controller): void {
        $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => [
            'customer_enabled' => true,
            'invoice_enabled'  => true,
            'wallet_enabled'   => true,
        ]]));
    });

    expect($jobs)->toBe([]);
});

test('an override that turns an inherited off entity on queues only that entity, and leaving it queues nothing', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false]);
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $jobs = CapturedJobs::capture(function () use ($controller): void {
        $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['wallet_enabled' => true]]));
        $controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['wallet_enabled' => true]]));
        $controller->save(orgApiPost(['scope' => 'company', 'override' => false]));
    });

    expect(CapturedJobs::queued($jobs))->toBe([['company-a', ['wallet' => true]]]);
});

test('a global save queues by this organizations effective settings, not the raw global row', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false]);
    $store->putCompanySync('company-a', ['wallet_enabled' => true]);
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $jobs = CapturedJobs::capture(function () use ($controller): void {
        $controller->save(orgApiPost(['scope' => 'admin', 'auth' => [], 'sync' => qbSettings(['wallet_enabled' => true])], true));
    });

    // Nothing is queued for the admin organization here. One job decides per organization, each by its own override.
    expect(CapturedJobs::queued($jobs))->toBe([])
        ->and(array_values(array_filter($jobs, fn ($job) => $job instanceof QueueGlobalScope)))->toHaveCount(1);
});
