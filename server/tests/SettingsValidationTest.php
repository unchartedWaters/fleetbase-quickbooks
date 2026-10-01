<?php

use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SettingsValidator;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;

function validAuth(array $overrides = []): array
{
    return array_merge([
        'client_id'     => 'client-id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ], $overrides);
}

test('organization settings are used and a stored system row is unused', function () {
    $resolver = new SyncSettingsResolver();
    $defaults = qbSettings();
    $admin    = qbSettings(['interval_minutes' => 9, 'invoice_reference' => 'fleetbase']);
    $company  = qbSettings(['override' => false, 'interval_minutes' => 2, 'invoice_reference' => 'quickbooks']);

    $resolved = $resolver->resolve($company, $admin, $defaults);

    expect($resolved)->not->toHaveKey('override')
        ->and($resolved['interval_minutes'])->toBe(2)
        ->and($resolved['sources']['interval_minutes'])->toBe('company')
        ->and($resolved['invoice_reference'])->toBe('quickbooks')
        ->and($resolved['sources']['invoice_reference'])->toBe('company');
});

test('a stored report conflict resolves to fleetbase', function () {
    $resolver = new SyncSettingsResolver();
    $resolved = $resolver->resolve(
        qbSettings([
            'override'          => true,
            'customer_conflict' => 'report',
            'invoice_conflict'  => 'report',
            'payment_conflict'  => 'report',
            'wallet_conflict'   => 'report',
        ]),
        qbSettings(['customer_conflict' => 'quickbooks']),
        qbSettings()
    );

    expect($resolved['customer_conflict'])->toBe('fleetbase')
        ->and($resolved['sources']['customer_conflict'])->toBe('company')
        ->and($resolved['invoice_conflict'])->toBe('fleetbase')
        ->and($resolved['payment_conflict'])->toBe('fleetbase')
        ->and($resolved['wallet_conflict'])->toBe('fleetbase');

    $companyChoice = $resolver->resolve(
        qbSettings(['override' => false, 'customer_conflict' => 'quickbooks']),
        qbSettings(['customer_conflict' => 'report']),
        qbSettings(['customer_conflict' => 'report'])
    );

    expect($companyChoice['customer_conflict'])->toBe('quickbooks')
        ->and($companyChoice['sources']['customer_conflict'])->toBe('company')
        ->and($companyChoice)->not->toHaveKey('override');
});

test('a missing or off direction resolves to both and system settings are unused', function () {
    $resolver = new SyncSettingsResolver();
    $defaults = qbSettings();
    unset($defaults['customer_direction']);
    $adminWithout = qbSettings();
    unset($adminWithout['customer_direction']);
    $companyWithout = qbSettings(['override' => true, 'customer_enabled' => false]);
    unset($companyWithout['customer_direction']);

    $missing = $resolver->resolve($companyWithout, $adminWithout, $defaults);

    expect($missing['customer_direction'])->toBe('both')
        ->and($missing['sources']['customer_direction'])->toBe('default')
        ->and($missing['customer_enabled'])->toBeFalse()
        ->and($missing['sources']['customer_enabled'])->toBe('company')
        ->and($missing)->not->toHaveKey('override')
        ->and($missing['sources'])->toHaveKey('invoice_direction')
        ->and($missing['sources'])->toHaveKey('payment_direction')
        ->and($missing['sources'])->toHaveKey('wallet_direction')
        ->and($missing['sources'])->toHaveKey('periodic_interval_hours');

    $companyDirection = $resolver->resolve(
        qbSettings(['override' => false, 'invoice_direction' => 'inbound', 'periodic_interval_hours' => 2]),
        qbSettings(['invoice_direction' => 'outbound', 'periodic_interval_hours' => 48]),
        qbSettings()
    );

    expect($companyDirection['invoice_direction'])->toBe('inbound')
        ->and($companyDirection['sources']['invoice_direction'])->toBe('company')
        ->and($companyDirection['periodic_interval_hours'])->toBe(2)
        ->and($companyDirection['sources']['periodic_interval_hours'])->toBe('company');

    $off = $resolver->resolve(
        qbSettings(['payment_direction' => 'off', 'periodic_interval_hours' => 6, 'payment_enabled' => false]),
        qbSettings(['payment_direction' => 'inbound', 'periodic_interval_hours' => 48]),
        qbSettings()
    );

    expect($off['payment_direction'])->toBe('both')
        ->and($off['sources']['payment_direction'])->toBe('company')
        ->and($off['payment_enabled'])->toBeFalse()
        ->and($off['sources']['payment_enabled'])->toBe('company')
        ->and($off['periodic_interval_hours'])->toBe(6)
        ->and($off['sources']['periodic_interval_hours'])->toBe('company');
});

test('a missing entity enable flag resolves to true from the default', function () {
    $resolver = new SyncSettingsResolver();
    $company  = qbSettings();
    $defaults = qbSettings();
    foreach (['customer_enabled', 'invoice_enabled', 'payment_enabled', 'wallet_enabled'] as $field) {
        unset($company[$field], $defaults[$field]);
    }

    $resolved = $resolver->resolve($company, [], $defaults);

    expect($resolved['customer_enabled'])->toBeTrue()
        ->and($resolved['sources']['customer_enabled'])->toBe('default')
        ->and($resolved['invoice_enabled'])->toBeTrue()
        ->and($resolved['sources']['invoice_enabled'])->toBe('default')
        ->and($resolved['payment_enabled'])->toBeTrue()
        ->and($resolved['sources']['payment_enabled'])->toBe('default')
        ->and($resolved['wallet_enabled'])->toBeTrue()
        ->and($resolved['sources']['wallet_enabled'])->toBe('default');
});

test('direction and the customer catalog interval are validated', function () {
    $validator = new SettingsValidator();

    $invalid = $validator->errors(validAuth(), qbSettings([
        'customer_direction'      => 'sideways',
        'invoice_direction'       => 'sideways',
        'payment_direction'       => 'sideways',
        'wallet_direction'        => 'sideways',
        'periodic_interval_hours' => 0,
    ]));

    expect($invalid['customer_direction'])->toBe('Choose Both, Outbound, or Inbound for Customers.')
        ->and($invalid['invoice_direction'])->toBe('Choose Both, Outbound, or Inbound for Invoices.')
        ->and($invalid['payment_direction'])->toBe('Choose Both, Outbound, or Inbound for Payments.')
        ->and($invalid['wallet_direction'])->toBe('Choose Both, Outbound, or Inbound for Accounts / Wallets.')
        ->and($invalid['periodic_interval_hours'])->toBe('Enter a whole number of hours, at least 1.')
        ->and($invalid)->not->toHaveKey('customer_enabled');

    expect($validator->errors(validAuth(), qbSettings([
        'customer_direction'      => 'both',
        'invoice_direction'       => 'outbound',
        'payment_direction'       => 'inbound',
        'wallet_direction'        => 'off',
        'customer_enabled'        => false,
        'periodic_interval_hours' => 24,
    ])))->toBe([]);

    $disabled = qbSettings(['invoice_enabled' => false]);
    unset($disabled['invoice_direction'], $disabled['invoice_conflict'], $disabled['invoice_reference']);
    expect($validator->errors(validAuth(), $disabled))->toBe([]);
});

test('settings are rejected when required values are missing or out of range', function () {
    $validator = new SettingsValidator();

    expect($validator->errors([], []))->toHaveKey('client_id')
        ->and($validator->errors([], []))->toHaveKey('redirect_uri');

    $errors = $validator->errors(validAuth([
        'redirect_uri'  => 'not-a-url',
        'client_secret' => '',
    ]), qbSettings([
        'interval_minutes'        => 0,
        'default_backoff_seconds' => 4,
    ]));

    expect($errors)->toHaveKey('redirect_uri')
        ->and($errors)->toHaveKey('client_secret')
        ->and($errors)->toHaveKey('interval_minutes')
        ->and($errors)->toHaveKey('default_backoff_seconds')
        ->and($errors)->not->toHaveKey('client_id');

    expect($validator->errors(validAuth(), qbSettings()))->toBe([]);
    expect($validator->errors(validAuth(), qbSettings([
        'customer_conflict' => 'report',
        'invoice_conflict'  => 'report',
        'payment_conflict'  => 'report',
        'wallet_conflict'   => 'report',
    ])))->toBe([]);

    expect($validator->errors(validAuth([
        'redirect_uri' => 'http://10.30.0.34:8000/quickbooks/int/v1/oauth/callback',
    ]), qbSettings()))->not->toHaveKey('redirect_uri');
});

test('a blank client secret is not configured and interval minutes stay out of auth', function () {
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::companyAuth('company-uuid')] = [
        'client_secret'    => '',
        'interval_minutes' => '5',
    ];

    expect($store->companyAuth('company-uuid'))->toBe([])
        ->and($store->normalizeAuth([
            'client_id'        => 'id',
            'client_secret'    => '   ',
            'interval_minutes' => '5',
            'redirect_uri'     => 'https://example.test/callback',
            'environment'      => 'sandbox',
        ]))->toBe([
            'client_id'    => 'id',
            'redirect_uri' => 'https://example.test/callback',
            'environment'  => 'sandbox',
        ])
        ->and($store->normalizeAuth([
            'client_secret'    => 'kept-secret',
            'interval_minutes' => '5',
        ]))->toBe(['client_secret' => 'kept-secret']);

    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $leftover = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($leftover['company_auth'])->not->toHaveKey('interval_minutes')
            ->and($leftover['company_auth'])->not->toHaveKey('client_secret')
            ->and($leftover['company_auth']['client_secret_set'])->toBeFalse()
            ->and($leftover['auth']['client_secret_set'])->toBeFalse();

        $store->rows[SettingsKeys::companyAuth('company-uuid')] = [
            'client_secret'    => 'kept-secret',
            'interval_minutes' => '5',
        ];
        $response = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => [
                'client_id'        => 'client-id',
                'client_secret'    => '',
                'redirect_uri'     => 'https://example.test/callback',
                'environment'      => 'sandbox',
                'interval_minutes' => '5',
            ],
            'sync' => qbSettings(['override' => true, 'customer_enabled' => false, 'customer_direction' => 'off']),
        ]));
        $stored     = $store->rows[SettingsKeys::companyAuth('company-uuid')];
        $storedSync = $store->rows[SettingsKeys::companySync('company-uuid')];
        $shown      = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and($stored)->not->toHaveKey('interval_minutes')
            ->and($stored['client_secret'])->toBe('kept-secret')
            ->and($storedSync)->not->toHaveKey('override')
            ->and($storedSync['customer_enabled'])->toBeFalse()
            ->and($storedSync['customer_direction'])->toBe('both')
            ->and($shown['company_auth'])->not->toHaveKey('interval_minutes')
            ->and($shown['company_auth']['client_secret_set'])->toBeTrue()
            ->and($shown['company_auth'])->not->toHaveKey('client_secret')
            ->and($shown['sync'])->not->toHaveKey('override')
            ->and($shown['sync']['customer_direction'])->toBe('both')
            ->and($shown['sync']['customer_enabled'])->toBeFalse()
            ->and($shown['company_sync']['customer_enabled'])->toBeFalse();
    } finally {
        session(['company' => null]);
    }
});

test('company connect uses that company and ignores a stored system secret', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = validAuth(['client_secret' => 'admin-secret', 'client_id' => 'admin-id']);

    session(['company' => 'company-uuid']);
    $settings   = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        $settings,
        $store
    );

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);
        $credentials = $settings->credentialsFor($store, 'company-uuid');

        expect($shown['auth']['client_secret_set'])->toBeFalse()
            ->and($shown['auth']['client_id'])->toBe('')
            ->and($shown['auth']['sources']['client_secret'])->not->toBe('admin')
            ->and($shown['company_auth']['client_secret_set'])->toBeFalse()
            ->and($credentials['client_id'])->toBe('')
            ->and($credentials['client_secret'])->toBe('');

        $saved = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(['client_secret' => 'company-secret']),
            'sync'         => qbSettings(),
        ]));
        $connected = $settings->credentialsFor($store, 'company-uuid');

        expect($saved->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::companySync('company-uuid')])->not->toHaveKey('override')
            ->and($connected['client_id'])->toBe('client-id')
            ->and($connected['client_secret'])->toBe('company-secret')
            ->and($store->adminAuth()['client_secret'])->toBe('admin-secret');

        config()->set('quickbooks.client_secret', 'env-secret');
        config()->set('quickbooks.client_id', 'env-id');
        $store->rows[SettingsKeys::companyAuth('company-uuid')] = [];
        $fromEnv                                                = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($fromEnv['auth']['client_id'])->toBe('')
            ->and($fromEnv['auth']['client_secret_set'])->toBeFalse()
            ->and($fromEnv['auth']['sources']['client_id'])->toBe('none')
            ->and($fromEnv['auth']['sources']['client_secret'])->toBe('none')
            ->and($fromEnv['company_auth']['client_secret_set'])->toBeFalse();
    } finally {
        session(['company' => null]);
        config()->set('quickbooks.client_secret', null);
        config()->set('quickbooks.client_id', null);
    }
});

test('an admin scope request is not an organization settings screen', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('quickbooks.client_id', 'env-client');
    config()->set('quickbooks.client_secret', 'env-secret');
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $request = Request::create('/settings', 'GET', [
        'scope'        => 'admin',
        'company_uuid' => 'company-uuid',
    ]);

    try {
        $status = 200;
        try {
            $controller->show($request);
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $status = $exception->getStatusCode();
        }

        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($status)->toBe(404)
            ->and($store->adminAuth())->toBe([])
            ->and($shown['auth']['client_id'])->toBe('')
            ->and($shown['auth']['client_secret_set'])->toBeFalse()
            ->and($shown['auth'])->not->toHaveKey('client_secret')
            ->and($shown['auth']['sources']['client_id'])->toBe('none')
            ->and($shown['auth']['sources']['client_secret'])->toBe('none');
    } finally {
        session(['company' => null]);
        config()->set('quickbooks.client_id', null);
        config()->set('quickbooks.client_secret', null);
    }
});

test('webhook and oauth urls are computed and client copies are not stored', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://api.example.test/');
    config()->set('quickbooks.console_host', null);
    config()->set('fleetbase.console.host', null);
    config()->set('fleetbase.url', null);
    $store->rows[SettingsKeys::adminAuth()] = validAuth([
        'webhook_url' => 'https://admin.example.test/quickbooks/int/v1/webhooks',
    ]);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $webhook = 'https://api.example.test/quickbooks/int/v1/webhooks';
    $oauth   = 'https://api.example.test/quickbooks/int/v1/oauth/callback';

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($shown['internal_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['internal_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['public_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['internal_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['internal_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['public_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['public_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['webhook_url'])->toBe($webhook)
            ->and($shown['auth']['sources'] ?? [])->not->toHaveKey('webhook_url')
            ->and($shown['company_auth'])->not->toHaveKey('public_receiver_url')
            ->and($shown['company_auth'])->not->toHaveKey('webhook_url')
            ->and($shown['company_auth'])->not->toHaveKey('internal_webhook_receiver_url')
            ->and($shown['company_auth'])->not->toHaveKey('public_oauth_redirect_url');

        config()->set('app.url', '   ');
        $pathOnly = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);
        expect($pathOnly['internal_webhook_receiver_url'])->toBe('/quickbooks/int/v1/webhooks')
            ->and($pathOnly['public_webhook_receiver_url'])->toBe('/quickbooks/int/v1/webhooks')
            ->and($pathOnly['internal_oauth_redirect_url'])->toBe('/quickbooks/int/v1/oauth/callback')
            ->and($pathOnly['public_oauth_redirect_url'])->toBe('/quickbooks/int/v1/oauth/callback')
            ->and($pathOnly['auth']['internal_webhook_receiver_url'])->toBe('/quickbooks/int/v1/webhooks')
            ->and($pathOnly['auth']['public_oauth_redirect_url'])->toBe('/quickbooks/int/v1/oauth/callback');

        config()->set('fleetbase.console.host', 'https://console.example.test');
        config()->set('fleetbase.url', 'https://api.core.test/');
        $fromCore = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);
        expect($fromCore['internal_webhook_receiver_url'])->toBe('https://api.core.test/quickbooks/int/v1/webhooks')
            ->and($fromCore['public_oauth_redirect_url'])->toBe('https://api.core.test/quickbooks/int/v1/oauth/callback')
            ->and($fromCore['auth']['internal_webhook_receiver_url'])->toBe('https://api.core.test/quickbooks/int/v1/webhooks')
            ->and($fromCore['auth']['public_oauth_redirect_url'])->toBe('https://api.core.test/quickbooks/int/v1/oauth/callback');

        config()->set('app.url', 'https://api.example.test');
        expect(SettingController::publicReceiverUrl())->toBe($webhook)
            ->and(SettingController::internalOAuthRedirectUrl())->toBe($oauth);

        config()->set('app.url', '   ');
        config()->set('fleetbase.url', null);
        config()->set('fleetbase.console.host', null);
        config()->set('quickbooks.console_host', null);
        expect(SettingController::publicReceiverUrl())->toBe('/quickbooks/int/v1/webhooks');

        $saved = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth([
                'webhook_url'                     => 'not-a-url',
                'public_receiver_url'             => 'https://quickbooks-proxy.example.com/quickbooks/int/v1/webhooks',
                'internal_webhook_receiver_url'   => 'https://client.example.test/quickbooks/int/v1/webhooks',
                'public_webhook_receiver_url'     => 'https://public.example.test/quickbooks/int/v1/webhooks',
                'internal_oauth_redirect_url'     => 'https://client.example.test/quickbooks/int/v1/oauth/callback',
                'public_oauth_redirect_url'       => 'https://public.example.test/quickbooks/int/v1/oauth/callback',
            ]),
            'sync' => qbSettings([
                'internal_webhook_receiver_url' => 'https://client.example.test/quickbooks/int/v1/webhooks',
                'public_oauth_redirect_url'     => 'https://public.example.test/quickbooks/int/v1/oauth/callback',
            ]),
        ]));
        $stored     = $store->rows[SettingsKeys::companyAuth('company-uuid')];
        $storedSync = $store->rows[SettingsKeys::companySync('company-uuid')];
        $body       = $saved->getData(true);

        expect($saved->getStatusCode())->toBe(200)
            ->and($stored)->not->toHaveKey('webhook_url')
            ->and($stored)->not->toHaveKey('public_receiver_url')
            ->and($stored)->not->toHaveKey('internal_webhook_receiver_url')
            ->and($stored)->not->toHaveKey('internal_oauth_redirect_url')
            ->and($stored['public_webhook_receiver_url'])->toBe('https://public.example.test/quickbooks/int/v1/webhooks')
            ->and($stored['public_oauth_redirect_url'])->toBe('https://public.example.test/quickbooks/int/v1/oauth/callback')
            ->and($storedSync)->not->toHaveKey('internal_webhook_receiver_url')
            ->and($storedSync)->not->toHaveKey('public_webhook_receiver_url')
            ->and($storedSync)->not->toHaveKey('public_oauth_redirect_url')
            ->and($body['internal_webhook_receiver_url'])->toBe('/quickbooks/int/v1/webhooks')
            ->and($body['public_webhook_receiver_url'])->toBe('https://public.example.test/quickbooks/int/v1/webhooks')
            ->and($body['internal_oauth_redirect_url'])->toBe('/quickbooks/int/v1/oauth/callback')
            ->and($body['public_oauth_redirect_url'])->toBe('https://public.example.test/quickbooks/int/v1/oauth/callback')
            ->and($body['auth']['internal_webhook_receiver_url'])->toBe('/quickbooks/int/v1/webhooks')
            ->and($body['auth']['public_webhook_receiver_url'])->toBe('https://public.example.test/quickbooks/int/v1/webhooks')
            ->and($body['auth']['internal_oauth_redirect_url'])->toBe('/quickbooks/int/v1/oauth/callback')
            ->and($body['auth']['public_oauth_redirect_url'])->toBe('https://public.example.test/quickbooks/int/v1/oauth/callback');

        $cleared = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth([
                'public_webhook_receiver_url' => '   ',
                'public_oauth_redirect_url'   => '',
            ]),
            'sync' => qbSettings(),
        ]));
        $clearedStored = $store->rows[SettingsKeys::companyAuth('company-uuid')];
        $clearedBody   = $cleared->getData(true);
        expect($cleared->getStatusCode())->toBe(200)
            ->and($clearedStored)->not->toHaveKey('public_webhook_receiver_url')
            ->and($clearedStored)->not->toHaveKey('public_oauth_redirect_url')
            ->and($clearedBody['public_webhook_receiver_url'])->toBe('/quickbooks/int/v1/webhooks')
            ->and($clearedBody['auth']['public_oauth_redirect_url'])->toBe('/quickbooks/int/v1/oauth/callback');
    } finally {
        session(['company' => null]);
        config()->set('app.url', null);
        config()->set('fleetbase.url', null);
        config()->set('fleetbase.console.host', null);
        config()->set('quickbooks.console_host', null);
    }
});

test('a company save with override on does not require batch size', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $sync = qbSettings(['override' => true]);
    unset($sync['batch_size']);

    try {
        $saved = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(),
            'sync'         => $sync,
        ]));
        $stored = $store->rows[SettingsKeys::companySync('company-uuid')];

        expect($saved->getStatusCode())->toBe(200)
            ->and($stored)->not->toHaveKey('batch_size')
            ->and($stored)->not->toHaveKey('override');

        $store->rows[SettingsKeys::companySync('company-uuid')] = qbSettings([
            'override'   => true,
            'batch_size' => 40,
        ]);
        $kept = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(),
            'sync'         => $sync,
        ]));

        expect($kept->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::companySync('company-uuid')]['batch_size'])->toBe(40);
    } finally {
        session(['company' => null]);
    }
});

test('a blank path-only loopback or port 4200 redirect is saved as the api callback', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://api.example.test');
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $callback = 'https://api.example.test/quickbooks/int/v1/oauth/callback';

    try {
        foreach ([
            '',
            '/quickbooks/int/v1/oauth/callback',
            'http://localhost:8000/callback',
            'http://127.0.0.1/callback',
            'http://[::1]/callback',
            'https://console.example.test:4200/callback',
        ] as $redirect) {
            $saved = $controller->save(Request::create('/settings', 'POST', [
                'scope'        => 'company',
                'company_uuid' => 'company-uuid',
                'auth'         => validAuth(['redirect_uri' => $redirect]),
                'sync'         => qbSettings(),
            ]));

            expect($saved->getStatusCode())->toBe(200)
                ->and($store->rows[SettingsKeys::companyAuth('company-uuid')]['redirect_uri'])->toBe($callback);
        }

        $kept = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(['redirect_uri' => 'https://example.test/callback']),
            'sync'         => qbSettings(),
        ]));

        expect($kept->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::companyAuth('company-uuid')]['redirect_uri'])->toBe('https://example.test/callback');
    } finally {
        session(['company' => null]);
        config()->set('app.url', null);
    }
});

test('a loopback app url yields to a configured non-loopback api host', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'http://localhost:8000');
    config()->set('fleetbase.url', 'https://api.core.test/');
    config()->set('fleetbase.console.host', 'https://console.example.test');
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($shown['auth']['public_receiver_url'])->toBe('https://api.core.test/quickbooks/int/v1/webhooks')
            ->and($shown['auth']['webhook_url'])->toBe('https://api.core.test/quickbooks/int/v1/webhooks')
            ->and($shown['auth']['internal_webhook_receiver_url'])->toBe('https://api.core.test/quickbooks/int/v1/webhooks')
            ->and($shown['auth']['public_webhook_receiver_url'])->toBe('https://api.core.test/quickbooks/int/v1/webhooks')
            ->and($shown['auth']['internal_oauth_redirect_url'])->toBe('https://api.core.test/quickbooks/int/v1/oauth/callback')
            ->and($shown['auth']['public_oauth_redirect_url'])->toBe('https://api.core.test/quickbooks/int/v1/oauth/callback');
    } finally {
        session(['company' => null]);
        config()->set('app.url', null);
        config()->set('fleetbase.url', null);
        config()->set('fleetbase.console.host', null);
    }
});

test('a loopback app url uses the non-loopback host and the api port', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'http://localhost:8000');
    config()->set('fleetbase.url', null);
    config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
    config()->set('fleetbase.console.host', 'http://10.30.0.34:4200/');
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $webhook = 'http://10.30.0.34:8000/quickbooks/int/v1/webhooks';
    $oauth   = 'http://10.30.0.34:8000/quickbooks/int/v1/oauth/callback';

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($shown['internal_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['internal_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['public_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['internal_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['internal_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['public_oauth_redirect_url'])->toBe($oauth)
            ->and(SettingController::publicReceiverUrl())->toBe($webhook)
            ->and(SettingController::internalOAuthRedirectUrl())->toBe($oauth);

        config()->set('quickbooks.console_host', null);
        config()->set('fleetbase.console.host', 'fleetbase.io');
        $kept = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($kept['internal_webhook_receiver_url'])->toBe('http://localhost:8000/quickbooks/int/v1/webhooks')
            ->and($kept['internal_oauth_redirect_url'])->toBe('http://localhost:8000/quickbooks/int/v1/oauth/callback');

        config()->set('app.url', 'http://localhost');
        config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
        config()->set('fleetbase.console.host', 'http://10.30.0.34:4200/');
        $withoutApiPort = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($withoutApiPort['internal_webhook_receiver_url'])->toBe('http://10.30.0.34/quickbooks/int/v1/webhooks')
            ->and($withoutApiPort['public_webhook_receiver_url'])->toBe('http://10.30.0.34/quickbooks/int/v1/webhooks')
            ->and($withoutApiPort['internal_oauth_redirect_url'])->toBe('http://10.30.0.34/quickbooks/int/v1/oauth/callback')
            ->and($withoutApiPort['public_oauth_redirect_url'])->toBe('http://10.30.0.34/quickbooks/int/v1/oauth/callback');
    } finally {
        session(['company' => null]);
        config()->set('app.url', null);
        config()->set('fleetbase.url', null);
        config()->set('fleetbase.console.host', null);
        config()->set('quickbooks.console_host', null);
    }
});

test('a loopback https app url keeps that scheme on the console host', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://localhost:8000');
    config()->set('fleetbase.url', null);
    config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
    config()->set('fleetbase.console.host', 'http://10.30.0.34:4200');
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $webhook = 'https://10.30.0.34:8000/quickbooks/int/v1/webhooks';
    $oauth   = 'https://10.30.0.34:8000/quickbooks/int/v1/oauth/callback';

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($shown['internal_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['internal_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['public_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['internal_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['internal_oauth_redirect_url'])->toBe($oauth)
            ->and($shown['auth']['public_oauth_redirect_url'])->toBe($oauth);
    } finally {
        session(['company' => null]);
        config()->set('app.url', null);
        config()->set('fleetbase.url', null);
        config()->set('fleetbase.console.host', null);
        config()->set('quickbooks.console_host', null);
    }
});

test('a new organization defaults to production and a stored company sandbox stays stored', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('quickbooks.environment', null);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($shown['auth']['environment'])->toBe('production')
            ->and($shown['auth']['sources']['environment'])->toBe('env')
            ->and($store->adminAuth())->toBe([]);

        $store->rows[SettingsKeys::adminAuth()] = validAuth(['environment' => 'sandbox']);
        $unusedSystem                           = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($unusedSystem['auth']['environment'])->toBe('production')
            ->and($unusedSystem['auth']['sources']['environment'])->toBe('env');

        $store->rows[SettingsKeys::companyAuth('company-uuid')] = validAuth(['environment' => 'sandbox']);
        $saved                                                  = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'company',
            'company_uuid' => 'company-uuid',
            'auth'         => [
                'client_id'     => 'client-id',
                'redirect_uri'  => 'https://example.test/callback',
                'client_secret' => 'secret',
            ],
            'sync' => qbSettings(),
        ]));

        expect($saved->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::companyAuth('company-uuid')]['environment'])->toBe('sandbox')
            ->and($store->rows[SettingsKeys::adminAuth()]['environment'])->toBe('sandbox')
            ->and($store->rows[SettingsKeys::companySync('company-uuid')])->not->toHaveKey('override');

        $configSource = file_get_contents(dirname(__DIR__) . '/config/quickbooks.php');
        expect($configSource)->toContain("env('QUICKBOOKS_ENVIRONMENT', 'production')");
    } finally {
        session(['company' => null]);
        config()->set('quickbooks.environment', null);
    }
});
