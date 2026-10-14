<?php

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
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
    $store->putCompanySync('company-a', qbSettings(['interval_minutes' => 30]));
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));

    foreach ([orgApiGet(), orgApiGet(['scope' => 'company'])] as $request) {
        $response = $controller->show($request);
        $json     = (string) $response->getContent();
        $data     = $response->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and(array_keys($data))->toBe(['can_edit', 'sync', 'has_copy', 'credentials_configured'])
            ->and($data['can_edit'])->toBeFalse()
            ->and($data['has_copy'])->toBeTrue()
            ->and($data['credentials_configured'])->toBeTrue()
            ->and($data['sync']['interval_minutes'])->toBe(30)
            ->and(array_diff(array_keys($data['sync']), SettingsKeys::SYNC_FIELDS))->toBe([]);

        foreach (['client_id', 'client_secret', 'webhook_verifier', 'environment', 'redirect_uri', 'auth', 'sources', 'override', 'global_sync', 'company_sync'] as $key) {
            expect($json)->not->toContain('"' . $key)
                ->and($data['sync'])->not->toHaveKey($key);
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

test('an organization without a copy reads the current template and has_copy is false', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));
    $data       = $controller->show(orgApiGet())->getData(true);

    expect($data['has_copy'])->toBeFalse()
        ->and($data['sync']['interval_minutes'])->toBe(15)
        ->and($data['sync']['wallet_enabled'])->toBeFalse()
        ->and(array_key_exists(SettingsKeys::companySync('company-a'), $store->rows))->toBeFalse();
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

test('a QuickbooksOperator cannot save or reset and nothing is written', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksOperator'));

    expect(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['interval_minutes' => 30]]))))->toBe(403)
        ->and(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => 'company', 'reset' => true]))))->toBe(403)
        ->and($store->rows)->toBe($before);
});

test('a QuickbooksAdministrator saves a complete copy that writes only this organizations sync row', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost([
        'scope'        => 'company',
        'company_uuid' => 'company-a',
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
    $row     = $store->rows[SettingsKeys::companySync('company-a')];
    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($written))->toBe([SettingsKeys::companySync('company-a')])
        ->and(array_diff_key($store->rows, $written))->toBe($before)
        ->and(array_diff(array_keys($row), [...SettingsKeys::SYNC_FIELDS, SettingsKeys::ORGANIZATION_MARKER]))->toBe([])
        ->and(array_diff(SettingsKeys::SYNC_FIELDS, array_keys($row)))->toBe([])
        ->and($row['interval_minutes'])->toBe(30)
        ->and($row['customer_enabled'])->toBeFalse()
        ->and($row['retry_limit'])->toBe(5)
        ->and($data['has_copy'])->toBeTrue()
        ->and($data['can_edit'])->toBeTrue()
        ->and($data['sync']['interval_minutes'])->toBe(30)
        ->and($data['sync']['customer_enabled'])->toBeFalse()
        ->and((string) $response->getContent())->not->toContain('global-client-id');
});

test('an installation administrator can save without a role', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['retry_limit' => 9]], true));

    expect($response->getStatusCode())->toBe(200)
        ->and($store->companySync('company-a')['retry_limit'])->toBe(9);
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
        $response = $controller->save(orgApiPost(array_merge(['scope' => 'company', 'sync' => ['interval_minutes' => 30]], $extra)));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['message'])->toBe('An organization can only change its sync options. '
                . 'Global QuickBooks settings are changed in Admin → QuickBooks → Settings.')
            ->and($store->rows)->toBe($before);
    }
});

test('a company save with an override key is refused and writes nothing', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    expect($controller->save(orgApiPost(['scope' => 'company', 'override' => true, 'sync' => ['interval_minutes' => 30]]))->getStatusCode())->toBe(422)
        ->and($store->rows)->toBe($before);
});

test('an invalid save is refused with sync errors and writes nothing', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['interval_minutes' => 0, 'batch_size' => 500]]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors'])->toHaveKeys(['interval_minutes', 'batch_size'])
        ->and($response->getData(true)['errors'])->not->toHaveKey('client_id')
        ->and($response->getData(true)['message'])->toBe('Some QuickBooks settings are missing or invalid.')
        ->and($response->getData(true)['errors']['interval_minutes'])
        ->toBe('Enter a whole number of minutes, at least 1.')
        ->and($response->getData(true)['errors']['batch_size'])->toBe('Enter a whole number from 1 to 100.')
        ->and($store->rows)->toBe($before);
});

test('company credentials are never validated at company scope', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings();
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['interval_minutes' => 20]]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['credentials_configured'])->toBeFalse();
});

test('reset copies the current template into the organization and keeps it separate afterwards', function () {
    $store = orgApiStore();
    $store->putCompanySync('company-a', qbSettings(['interval_minutes' => 30]));
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $response                               = $controller->save(orgApiPost(['scope' => 'company', 'reset' => true]));
    $data                                   = $response->getData(true);
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 99]);
    $later                                  = $controller->show(orgApiGet())->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($data))->toBe(['can_edit', 'sync', 'has_copy', 'credentials_configured'])
        ->and($data['has_copy'])->toBeTrue()
        ->and($data['sync']['interval_minutes'])->toBe(15)
        ->and($later['sync']['interval_minutes'])->toBe(15)
        ->and(SettingsStore::isCompleteCopy($store->rows[SettingsKeys::companySync('company-a')]))->toBeTrue();
});

test('a later save keeps the fields it leaves out', function () {
    $store = orgApiStore();
    $store->putCompanySync('company-a', qbSettings(['interval_minutes' => 30]));
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['retry_limit' => 7, 'batch_size' => '', 'periodic_interval_hours' => null]]));
    $row = $store->companySync('company-a');

    expect($row['retry_limit'])->toBe(7)
        ->and($row['interval_minutes'])->toBe(30)
        ->and($row['batch_size'])->toBe(100)
        ->and($row['periodic_interval_hours'])->toBe(24);
});

test('a global edit after the organization saved leaves its settings unchanged', function () {
    $store = orgApiStore();
    $org   = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));
    $org->save(orgApiPost(['scope' => 'company', 'sync' => ['interval_minutes' => 45]]));
    $copy = $store->rows[SettingsKeys::companySync('company-a')];

    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 1, 'wallet_enabled' => true, 'retry_limit' => 9, 'customer_conflict' => 'quickbooks']);
    $data                                   = $org->show(orgApiGet())->getData(true);

    expect($store->rows[SettingsKeys::companySync('company-a')])->toBe($copy)
        ->and($data['sync']['interval_minutes'])->toBe(45)
        ->and($data['sync']['retry_limit'])->toBe(5)
        ->and($data['sync']['wallet_enabled'])->toBeFalse()
        ->and($data['sync']['customer_conflict'])->toBe('fleetbase');
});

test('the full set is validated, so a template with a legacy report conflict needs fixing first', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['invoice_conflict' => 'report']);
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $blocked = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['interval_minutes' => 20]]));

    expect($blocked->getStatusCode())->toBe(422)
        ->and($blocked->getData(true)['errors'])->toHaveKey('invoice_conflict')
        ->and(array_key_exists(SettingsKeys::companySync('company-a'), $store->rows))->toBeFalse();

    $fixed = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['invoice_conflict' => 'fleetbase']]));
    expect($fixed->getStatusCode())->toBe(200)
        ->and($store->companySync('company-a')['interval_minutes'])->toBe(5)
        ->and($store->companySync('company-a')['invoice_conflict'])->toBe('fleetbase');
});

test('an enabled flag in a save must be boolean-like', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    foreach (['maybe', ['x'], 2] as $value) {
        $response = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['customer_enabled' => $value]]));
        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['errors'])->toHaveKey('customer_enabled');
    }
    foreach ([true, false, 1, 0, 'true', 'false', '1', '0'] as $value) {
        $response = $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['customer_enabled' => $value]]));
        expect($response->getStatusCode())->toBe(200);
    }
});

test('a scope that is not a string is refused', function () {
    $controller = orgApiController(orgApiStore(), orgApiAuthorizer('QuickbooksAdministrator'));

    expect(orgApiStatus(fn () => $controller->show(orgApiGet(['scope' => ['company']]))))->toBe(422)
        ->and(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => ['company'], 'sync' => []]))))->toBe(422);
});

test('a company uuid naming another organization is refused for both scopes', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    expect(orgApiStatus(fn () => $controller->save(orgApiPost(['scope' => 'company', 'company_uuid' => 'company-b', 'sync' => ['retry_limit' => 7]]))))->toBe(403)
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

test('the admin view shows only the last four characters of a long saved secret', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $response = $controller->show(orgApiGet(['scope' => 'admin'], true));
    $shown    = $response->getData(true);

    expect($shown['auth']['client_secret_hint'])->toBe('alue')
        ->and($shown['auth']['webhook_verifier_hint'])->toBe('alue')
        ->and($response->getContent())->not->toContain('global-secret-value')
        ->and($response->getContent())->not->toContain('global-verifier-value');

    $store->rows[SettingsKeys::adminAuth()]['client_secret']    = (new SecretCipher())->encrypt('short-secret');
    $store->rows[SettingsKeys::adminAuth()]['webhook_verifier'] = '';

    $short = $controller->show(orgApiGet(['scope' => 'admin'], true))->getData(true);

    // A short value would give away too much of itself, so it gets no hint.
    expect($short['auth']['client_secret_set'])->toBeTrue()
        ->and($short['auth']['client_secret_hint'])->toBeNull()
        ->and($short['auth']['webhook_verifier_hint'])->toBeNull();

    $operator = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));
    $company  = $operator->show(orgApiGet())->getContent();

    expect($company)->not->toContain('_hint');
});

test('the admin scope saves the global rows and never an organization row', function () {
    $store      = orgApiStore();
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $response = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id', 'client_secret' => 'new-global-secret', 'webhook_verifier' => 'new-global-verifier'],
        'sync'  => qbSettings(['interval_minutes' => 20]),
    ], true));

    expect($response->getStatusCode())->toBe(200)
        ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('new-global-id')
        ->and($store->rows[SettingsKeys::adminSync()]['interval_minutes'])->toBe(20)
        ->and(array_key_exists(SettingsKeys::companySync('company-a'), $store->rows))->toBeFalse()
        ->and(array_key_exists(SettingsKeys::companyAuth('company-a'), $store->rows))->toBeFalse();
});

test('a changed Client ID needs its Client secret, and an unchanged one keeps the saved secret', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $refused = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id', 'client_secret' => ''],
        'sync'  => qbSettings(),
    ], true));

    expect($refused->getStatusCode())->toBe(422)
        ->and(array_keys($refused->getData(true)['errors']))->toBe(['client_secret', 'webhook_verifier'])
        ->and($store->rows)->toBe($before);

    $kept = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'global-client-id', 'client_secret' => ''],
        'sync'  => qbSettings(),
    ], true));

    expect($kept->getStatusCode())->toBe(200)
        ->and((new SecretCipher())->decrypt($store->rows[SettingsKeys::adminAuth()]['client_secret']))
        ->toBe('global-secret-value');

    // A blank Client ID reports only its own error, not one for the saved secret.
    $blank = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => '', 'client_secret' => ''],
        'sync'  => qbSettings(),
    ], true));

    expect($blank->getStatusCode())->toBe(422)
        ->and(array_keys($blank->getData(true)['errors']))->toBe(['client_id']);
});

test('a changed Client ID needs its webhook verifier too, when one is saved', function () {
    $store      = orgApiStore();
    $before     = $store->rows;
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $refused = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id', 'client_secret' => 'new-secret-value', 'webhook_verifier' => ''],
        'sync'  => qbSettings(),
    ], true));

    expect($refused->getStatusCode())->toBe(422)
        ->and(array_keys($refused->getData(true)['errors']))->toBe(['webhook_verifier'])
        ->and($refused->getData(true)['errors']['webhook_verifier'])->toContain('Verifier')
        ->and($store->rows)->toBe($before);

    $saved = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id', 'client_secret' => 'new-secret-value', 'webhook_verifier' => 'new-verifier-value'],
        'sync'  => qbSettings(),
    ], true));

    expect($saved->getStatusCode())->toBe(200)
        ->and((new SecretCipher())->decrypt($store->rows[SettingsKeys::adminAuth()]['webhook_verifier']))
        ->toBe('new-verifier-value');

    // A verifier of spaces is blank.
    $spaces = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'third-id', 'client_secret' => 'third-secret-value', 'webhook_verifier' => '   '],
        'sync'  => qbSettings(),
    ], true));

    expect($spaces->getStatusCode())->toBe(422)
        ->and(array_keys($spaces->getData(true)['errors']))->toBe(['webhook_verifier']);

    // An unchanged Client ID keeps the saved verifier.
    $kept = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id', 'client_secret' => '', 'webhook_verifier' => ''],
        'sync'  => qbSettings(),
    ], true));

    expect($kept->getStatusCode())->toBe(200)
        ->and((new SecretCipher())->decrypt($store->rows[SettingsKeys::adminAuth()]['webhook_verifier']))
        ->toBe('new-verifier-value');
});

test('a changed Client ID needs no webhook verifier when none is saved', function () {
    $store                                                      = orgApiStore();
    $store->rows[SettingsKeys::adminAuth()]['webhook_verifier'] = '';
    $controller                                                 = orgApiController($store, new Authorizer(static fn (): bool => false));

    $saved = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'new-global-id', 'client_secret' => 'new-secret-value'],
        'sync'  => qbSettings(),
    ], true));

    expect($saved->getStatusCode())->toBe(200)
        ->and($store->rows[SettingsKeys::adminAuth()])->not->toHaveKey('webhook_verifier');
});

test('a secret saved before any Client ID is kept when a Client ID is first entered', function () {
    $store = orgApiStore();
    unset($store->rows[SettingsKeys::adminAuth()]['client_id']);
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));

    $saved = $controller->save(orgApiPost([
        'scope' => 'admin',
        'auth'  => ['client_id' => 'first-id', 'client_secret' => ''],
        'sync'  => qbSettings(),
    ], true));

    expect($saved->getStatusCode())->toBe(200)
        ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('first-id')
        ->and((new SecretCipher())->decrypt($store->rows[SettingsKeys::adminAuth()]['client_secret']))
        ->toBe('global-secret-value');
});

test('queueing diffs effective settings, so first saving values equal to the template queues nothing', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['customer_enabled' => true, 'invoice_enabled' => true, 'wallet_enabled' => true]);
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $jobs = CapturedJobs::capture(function () use ($controller): void {
        $controller->save(orgApiPost(['scope' => 'company', 'sync' => [
            'customer_enabled' => true,
            'invoice_enabled'  => true,
            'wallet_enabled'   => true,
        ]]));
    });

    expect($jobs)->toBe([]);
});

test('turning an entity on queues only that entity, and saving it again or resetting to a template with it on queues nothing', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false]);
    $controller                             = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $jobs = CapturedJobs::capture(function () use ($controller): void {
        $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['wallet_enabled' => true]]));
        $controller->save(orgApiPost(['scope' => 'company', 'sync' => ['wallet_enabled' => true]]));
        $controller->save(orgApiPost(['scope' => 'company', 'reset' => true]));
    });

    expect(CapturedJobs::queued($jobs))->toBe([['company-a', ['wallet' => true]]]);
});

test('a reset that turns an entity on queues it', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => true]);
    $store->putCompanySync('company-a', qbSettings(['wallet_enabled' => false]));
    $controller = orgApiController($store, orgApiAuthorizer('QuickbooksAdministrator'));

    $jobs = CapturedJobs::capture(fn () => $controller->save(orgApiPost(['scope' => 'company', 'reset' => true])));

    expect(CapturedJobs::queued($jobs))->toBe([['company-a', ['wallet' => true]]]);
});

test('a global save leaves an organization copy unchanged and queues nothing', function () {
    $store                                  = orgApiStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false]);
    $store->putCompanySync('company-a', qbSettings(['wallet_enabled' => false]));
    $controller = orgApiController($store, new Authorizer(static fn (): bool => false));
    $copy       = $store->rows[SettingsKeys::companySync('company-a')];

    $jobs = CapturedJobs::capture(function () use ($controller): void {
        $controller->save(orgApiPost(['scope' => 'admin', 'auth' => [], 'sync' => qbSettings(['wallet_enabled' => true, 'interval_minutes' => 2])], true));
    });

    expect($jobs)->toBe([])
        ->and($store->rows[SettingsKeys::companySync('company-a')])->toBe($copy);
});
