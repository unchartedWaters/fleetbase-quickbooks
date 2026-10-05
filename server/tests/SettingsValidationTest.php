<?php

use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SettingsValidator;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function validAuth(array $overrides = []): array
{
    return array_merge([
        'client_id'     => 'client-id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ], $overrides);
}

test('global settings are used and an organization row is unused', function () {
    $resolver = new SyncSettingsResolver();
    $defaults = qbSettings();
    $admin    = qbSettings(['interval_minutes' => 9, 'invoice_reference' => 'fleetbase']);
    $company  = qbSettings(['override' => false, 'interval_minutes' => 2, 'invoice_reference' => 'quickbooks']);

    $resolved = $resolver->resolve($company, $admin, $defaults);

    expect($resolved)->not->toHaveKey('override')
        ->and($resolved['interval_minutes'])->toBe(9)
        ->and($resolved['sources']['interval_minutes'])->toBe('admin')
        ->and($resolved['invoice_reference'])->toBe('fleetbase')
        ->and($resolved['sources']['invoice_reference'])->toBe('admin');
});

test('a stored report conflict does not write and cannot be saved', function () {
    $resolver = new SyncSettingsResolver();
    $resolved = $resolver->resolve(
        qbSettings(['customer_conflict' => 'quickbooks']),
        qbSettings([
            'override'          => true,
            'customer_conflict' => 'report',
            'invoice_conflict'  => 'report',
            'payment_conflict'  => 'report',
            'wallet_conflict'   => 'report',
        ]),
        qbSettings()
    );

    expect($resolved['customer_conflict'])->toBe('report')
        ->and($resolved['sources']['customer_conflict'])->toBe('admin')
        ->and($resolved['customer_direction'])->toBe('off')
        ->and($resolved['customer_enabled'])->toBeTrue()
        ->and($resolved['sources']['customer_enabled'])->toBe('admin')
        ->and($resolved['invoice_conflict'])->toBe('report')
        ->and($resolved['invoice_direction'])->toBe('off')
        ->and($resolved['invoice_enabled'])->toBeTrue()
        ->and($resolved['payment_conflict'])->toBe('report')
        ->and($resolved['payment_direction'])->toBe('off')
        ->and($resolved['payment_enabled'])->toBeTrue()
        ->and($resolved['wallet_conflict'])->toBe('report')
        ->and($resolved['wallet_direction'])->toBe('off')
        ->and($resolved['wallet_enabled'])->toBeTrue();

    $globalChoice = $resolver->resolve(
        qbSettings(['customer_conflict' => 'report']),
        qbSettings(['override' => false, 'customer_conflict' => 'quickbooks']),
        qbSettings(['customer_conflict' => 'report'])
    );

    expect($globalChoice['customer_conflict'])->toBe('quickbooks')
        ->and($globalChoice['sources']['customer_conflict'])->toBe('admin')
        ->and($globalChoice['customer_direction'])->toBe('both')
        ->and($globalChoice['customer_enabled'])->toBeTrue()
        ->and($globalChoice)->not->toHaveKey('override');

    $validator = new SettingsValidator();
    $rejected  = $validator->errors(validAuth(), qbSettings([
        'customer_conflict' => 'report',
        'invoice_conflict'  => 'report',
        'payment_conflict'  => 'report',
        'wallet_conflict'   => 'report',
    ]));

    expect($rejected['customer_conflict'])->toBe('Choose Fleetbase or QuickBooks as Primary for Customers.')
        ->and($rejected['invoice_conflict'])->toBe('Choose Fleetbase or QuickBooks as Primary for Invoices.')
        ->and($rejected['payment_conflict'])->toBe('Choose Fleetbase or QuickBooks as Primary for Payments.')
        ->and($rejected['wallet_conflict'])->toBe('Choose Fleetbase or QuickBooks as Primary for Accounts / Wallets.');

    $disabled = qbSettings(['customer_enabled' => false, 'customer_conflict' => 'report']);
    expect($validator->errors(validAuth(), $disabled))->not->toHaveKey('customer_conflict');
});

test('a missing or off direction resolves to both and an organization row is unused', function () {
    $resolver = new SyncSettingsResolver();
    $defaults = qbSettings();
    unset($defaults['customer_direction']);
    $adminWithout = qbSettings(['override' => true, 'customer_enabled' => false]);
    unset($adminWithout['customer_direction']);
    $companyWithout = qbSettings();
    unset($companyWithout['customer_direction']);

    $missing = $resolver->resolve($companyWithout, $adminWithout, $defaults);

    expect($missing['customer_direction'])->toBe('both')
        ->and($missing['sources']['customer_direction'])->toBe('default')
        ->and($missing['customer_enabled'])->toBeFalse()
        ->and($missing['sources']['customer_enabled'])->toBe('admin')
        ->and($missing)->not->toHaveKey('override')
        ->and($missing['sources'])->toHaveKey('invoice_direction')
        ->and($missing['sources'])->toHaveKey('payment_direction')
        ->and($missing['sources'])->toHaveKey('wallet_direction')
        ->and($missing['sources'])->toHaveKey('periodic_interval_hours');

    $globalDirection = $resolver->resolve(
        qbSettings(['invoice_direction' => 'outbound', 'periodic_interval_hours' => 48]),
        qbSettings(['override' => false, 'invoice_direction' => 'inbound', 'periodic_interval_hours' => 2]),
        qbSettings()
    );

    expect($globalDirection['invoice_direction'])->toBe('inbound')
        ->and($globalDirection['sources']['invoice_direction'])->toBe('admin')
        ->and($globalDirection['periodic_interval_hours'])->toBe(2)
        ->and($globalDirection['sources']['periodic_interval_hours'])->toBe('admin');

    $off = $resolver->resolve(
        qbSettings(['payment_direction' => 'inbound', 'periodic_interval_hours' => 48]),
        qbSettings(['payment_direction' => 'off', 'periodic_interval_hours' => 6, 'payment_enabled' => false]),
        qbSettings()
    );

    expect($off['payment_direction'])->toBe('both')
        ->and($off['sources']['payment_direction'])->toBe('admin')
        ->and($off['payment_enabled'])->toBeFalse()
        ->and($off['sources']['payment_enabled'])->toBe('admin')
        ->and($off['periodic_interval_hours'])->toBe(6)
        ->and($off['sources']['periodic_interval_hours'])->toBe('admin');
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
        'customer_conflict' => 'fleetbase',
        'invoice_conflict'  => 'quickbooks',
        'payment_conflict'  => 'fleetbase',
        'wallet_conflict'   => 'quickbooks',
    ])))->toBe([]);

    expect($validator->errors(validAuth([
        'redirect_uri' => 'http://10.30.0.34:8000/quickbooks/int/v1/oauth/callback',
    ]), qbSettings()))->not->toHaveKey('redirect_uri');
});

test('a blank client secret is not configured and interval minutes stay out of auth', function () {
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()]                 = [
        'client_secret'    => '',
        'interval_minutes' => '5',
    ];

    expect($store->adminAuth())->toBe([])
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
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($leftover['company_auth'])->not->toHaveKey('interval_minutes')
            ->and($leftover['company_auth'])->not->toHaveKey('client_secret')
            ->and($leftover['company_auth']['client_secret_set'])->toBeFalse()
            ->and($leftover['auth']['client_secret_set'])->toBeFalse();

        $store->rows[SettingsKeys::adminAuth()] = [
            'client_secret'    => 'kept-secret',
            'interval_minutes' => '5',
        ];
        $response = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'admin',
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
        $stored     = $store->rows[SettingsKeys::adminAuth()];
        $storedSync = $store->rows[SettingsKeys::adminSync()];
        $shown      = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->not->toContain('kept-secret')
            ->and($stored)->not->toHaveKey('interval_minutes')
            ->and($stored['client_secret'])->not->toBe('kept-secret')
            ->and((new SecretCipher())->decrypt($stored['client_secret']))->toBe('kept-secret')
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

test('admin connection uses the global secret and ignores an organization row', function () {
    $store                                                    = new MemorySettingsStore();
    $cipher                                                   = new SecretCipher();
    $companySecret                                            = $cipher->encrypt('company-secret');
    $adminSecret                                              = $cipher->encrypt('admin-secret');
    $store->rows[SettingsKeys::companyAuth('company-uuid')]   = validAuth(['client_secret' => $companySecret, 'client_id' => 'company-id']);
    $store->rows[SettingsKeys::adminAuth()]                   = validAuth(['client_secret' => $adminSecret, 'client_id' => 'admin-id']);

    session(['company' => 'company-uuid']);
    $settings   = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        $settings,
        $store
    );

    try {
        $shown = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);
        $credentials = $settings->credentialsFor($store, 'company-uuid');

        expect($shown['auth']['client_secret_set'])->toBeTrue()
            ->and($shown['auth']['client_id'])->toBe('admin-id')
            ->and($shown['auth']['sources']['client_id'])->toBe('admin')
            ->and($credentials['client_id'])->toBe('admin-id')
            ->and($credentials['client_secret'])->toBe('admin-secret')
            ->and($store->companyAuth('company-uuid')['client_secret'])->toBe($companySecret);

        $saved = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(['client_id' => 'saved-id', 'client_secret' => 'saved-secret']),
            'sync'         => qbSettings(),
        ]));
        $connected = $settings->credentialsFor($store, 'other-company');

        expect($saved->getStatusCode())->toBe(200)
            ->and($store->adminSync())->not->toHaveKey('override')
            ->and($connected['client_id'])->toBe('saved-id')
            ->and($connected['client_secret'])->toBe('saved-secret')
            ->and($store->companyAuth('company-uuid')['client_id'])->toBe('company-id');
    } finally {
        session(['company' => null]);
    }
});

test('a company scope request is not the install settings screen', function () {
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
        'scope'        => 'company',
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
            'scope'        => 'admin',
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
            'scope'        => 'admin',
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
            'scope'        => 'admin',
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
            'scope'        => 'admin',
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
            'scope'        => 'admin',
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
        $stored     = $store->rows[SettingsKeys::adminAuth()];
        $storedSync = $store->rows[SettingsKeys::adminSync()];
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
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth([
                'public_webhook_receiver_url' => '   ',
                'public_oauth_redirect_url'   => '',
            ]),
            'sync' => qbSettings(),
        ]));
        $clearedStored = $store->rows[SettingsKeys::adminAuth()];
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

test('batch size must be from 1 to 100 when it is sent', function () {
    $validator = new SettingsValidator();
    $sync      = qbSettings();
    unset($sync['batch_size']);

    expect($validator->errors(validAuth(), $sync))->not->toHaveKey('batch_size')
        ->and($validator->errors(validAuth(), qbSettings(['batch_size' => 1])))->not->toHaveKey('batch_size')
        ->and($validator->errors(validAuth(), qbSettings(['batch_size' => 100])))->not->toHaveKey('batch_size')
        ->and($validator->errors(validAuth(), qbSettings(['batch_size' => '40'])))->not->toHaveKey('batch_size');

    expect($validator->errors(validAuth(), qbSettings(['batch_size' => 0]))['batch_size'])->toBe('Enter a whole number from 1 to 100.')
        ->and($validator->errors(validAuth(), qbSettings(['batch_size' => 101]))['batch_size'])->toBe('Enter a whole number from 1 to 100.')
        ->and($validator->errors(validAuth(), qbSettings(['batch_size' => '101'])))->toHaveKey('batch_size');

    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['batch_size' => 40]);
    $controller                             = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $saved = $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => validAuth(),
            'sync'  => qbSettings(['batch_size' => 101]),
        ]));

        expect($saved->getStatusCode())->toBe(422)
            ->and($saved->getData(true)['errors'])->toHaveKey('batch_size')
            ->and($store->rows[SettingsKeys::adminSync()]['batch_size'])->toBe(40);
    } finally {
        session(['company' => null]);
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
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(),
            'sync'         => $sync,
        ]));
        $stored = $store->rows[SettingsKeys::adminSync()];

        expect($saved->getStatusCode())->toBe(200)
            ->and($stored)->not->toHaveKey('batch_size')
            ->and($stored)->not->toHaveKey('override');

        $store->rows[SettingsKeys::adminSync()] = qbSettings([
            'override'   => true,
            'batch_size' => 40,
        ]);
        $kept = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(),
            'sync'         => $sync,
        ]));

        expect($kept->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminSync()]['batch_size'])->toBe(40);
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
                'scope'        => 'admin',
                'company_uuid' => 'company-uuid',
                'auth'         => validAuth(['redirect_uri' => $redirect]),
                'sync'         => qbSettings(),
            ]));

            expect($saved->getStatusCode())->toBe(200)
                ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe($callback);
        }

        $kept = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
            'auth'         => validAuth(['redirect_uri' => 'https://example.test/callback']),
            'sync'         => qbSettings(),
        ]));

        expect($kept->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe('https://example.test/callback');
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
            'scope'        => 'admin',
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
            'scope'        => 'admin',
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
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($kept['internal_webhook_receiver_url'])->toBe('http://localhost:8000/quickbooks/int/v1/webhooks')
            ->and($kept['internal_oauth_redirect_url'])->toBe('http://localhost:8000/quickbooks/int/v1/oauth/callback');

        config()->set('app.url', 'http://localhost');
        config()->set('quickbooks.console_host', 'http://10.30.0.34:4200');
        config()->set('fleetbase.console.host', 'http://10.30.0.34:4200/');
        $withoutApiPort = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'admin',
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
            'scope'        => 'admin',
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

test('a new install defaults to production and a stored sandbox stays stored', function () {
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
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($shown['auth']['environment'])->toBe('production')
            ->and($shown['auth']['sources']['environment'])->toBe('env')
            ->and($store->adminAuth())->toBe([]);

        $store->rows[SettingsKeys::adminAuth()] = validAuth(['environment' => 'sandbox']);
        $unusedSystem                           = $controller->show(Request::create('/settings', 'GET', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
        ]))->getData(true);

        expect($unusedSystem['auth']['environment'])->toBe('sandbox')
            ->and($unusedSystem['auth']['sources']['environment'])->toBe('admin');

        $store->rows[SettingsKeys::adminAuth()]                 = validAuth(['environment' => 'sandbox']);
        $saved                                                  = $controller->save(Request::create('/settings', 'POST', [
            'scope'        => 'admin',
            'company_uuid' => 'company-uuid',
            'auth'         => [
                'client_id'     => 'client-id',
                'redirect_uri'  => 'https://example.test/callback',
                'client_secret' => 'secret',
            ],
            'sync' => qbSettings(),
        ]));

        expect($saved->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['environment'])->toBe('sandbox')
            ->and($store->rows[SettingsKeys::adminAuth()]['environment'])->toBe('sandbox')
            ->and($store->rows[SettingsKeys::adminSync()])->not->toHaveKey('override');

        $configSource = file_get_contents(dirname(__DIR__) . '/config/quickbooks.php');
        expect($configSource)->toContain("env('QUICKBOOKS_ENVIRONMENT', 'production')");
    } finally {
        session(['company' => null]);
        config()->set('quickbooks.environment', null);
    }
});

test('a public oauth or webhook url must be https and not an internal address', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://api.example.test');
    config()->set('fleetbase.url', null);
    config()->set('fleetbase.console.host', null);
    config()->set('quickbooks.console_host', null);
    Http::fake();
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
    $callback = 'https://api.example.test/quickbooks/int/v1/oauth/callback';
    $webhook  = 'https://api.example.test/quickbooks/int/v1/webhooks';

    try {
        foreach ([
            'http://public.example.test/callback',
            'https://10.0.0.5/callback',
            'https://192.168.1.9/callback',
            'https://172.16.5.5/callback',
            'https://127.0.0.2/callback',
            'https://169.254.169.254/callback',
            'https://[fe80::1]/callback',
            'https://[fd00::1]/callback',
            'https://localhost/callback',
        ] as $redirect) {
            $saved = $controller->save(Request::create('/settings', 'POST', [
                'scope' => 'admin',
                'auth'  => validAuth([
                    'redirect_uri'                => $redirect,
                    'public_oauth_redirect_url'   => $redirect,
                    'public_webhook_receiver_url' => $redirect,
                ]),
                'sync' => qbSettings(),
            ]));
            $stored = $store->rows[SettingsKeys::adminAuth()];
            $body   = $saved->getData(true);

            expect($saved->getStatusCode())->toBe(200)
                ->and($stored['redirect_uri'])->toBe($callback)
                ->and($stored)->not->toHaveKey('public_oauth_redirect_url')
                ->and($stored)->not->toHaveKey('public_webhook_receiver_url')
                ->and($body['public_oauth_redirect_url'])->toBe($callback)
                ->and($body['public_webhook_receiver_url'])->toBe($webhook);
        }

        Http::assertNothingSent();

        $public = 'https://public.example.test/quickbooks/int/v1/oauth/callback';
        $hook   = 'https://public.example.test/quickbooks/int/v1/webhooks';
        $kept   = $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => validAuth([
                'redirect_uri'                => $public,
                'public_oauth_redirect_url'   => $public,
                'public_webhook_receiver_url' => $hook,
            ]),
            'sync' => qbSettings(),
        ]));
        $keptBody = $kept->getData(true);

        expect($kept->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe($public)
            ->and($store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url'])->toBe($public)
            ->and($store->rows[SettingsKeys::adminAuth()]['public_webhook_receiver_url'])->toBe($hook)
            ->and($keptBody['public_oauth_redirect_url'])->toBe($public)
            ->and($keptBody['public_webhook_receiver_url'])->toBe($hook);

        $store->rows[SettingsKeys::adminAuth()]['public_oauth_redirect_url']   = 'https://10.1.1.1/callback';
        $store->rows[SettingsKeys::adminAuth()]['public_webhook_receiver_url'] = 'http://192.168.0.8/hook';
        $shown                                                                 = $controller->show(Request::create('/settings', 'GET', [
            'scope' => 'admin',
        ]))->getData(true);

        expect($shown['public_oauth_redirect_url'])->toBe($callback)
            ->and($shown['public_webhook_receiver_url'])->toBe($webhook)
            ->and($shown['auth']['public_oauth_redirect_url'])->toBe($callback)
            ->and($shown['auth']['public_webhook_receiver_url'])->toBe($webhook);
    } finally {
        session(['company' => null]);
        config()->set('app.url', null);
    }
});

test('sync settings drop keys that are not on the allowlist', function () {
    $store = new MemorySettingsStore();
    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $saved = $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => validAuth(),
            'sync'  => qbSettings([
                'injected'      => 'nope',
                'client_secret' => 'secret',
            ]),
        ]));
        $stored = $store->rows[SettingsKeys::adminSync()];

        expect($saved->getStatusCode())->toBe(200)
            ->and($stored)->not->toHaveKey('injected')
            ->and($stored)->not->toHaveKey('client_secret')
            ->and($stored['interval_minutes'])->toBe(5)
            ->and($saved->getData(true)['company_sync'])->not->toHaveKey('injected');

        $store->rows[SettingsKeys::adminSync()] = qbSettings(['injected' => 'still-nope']);
        $shown                                  = $controller->show(Request::create('/settings', 'GET', [
            'scope' => 'admin',
        ]))->getData(true);

        expect($shown['company_sync'])->not->toHaveKey('injected')
            ->and($shown['company_sync']['interval_minutes'])->toBe(5);
    } finally {
        session(['company' => null]);
    }
});

test('saving queues an entity only when its switch is turned on', function () {
    $store     = new MemorySettingsStore();
    $directory = new class extends FleetbaseDirectory {
        /** @var array<int, array{0: string, 1: array<string, bool>}> */
        public array $queued = [];

        public function queueInScope(string $companyUuid, array $enabled): void
        {
            $this->queued[] = [$companyUuid, $enabled];
        }
    };
    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store,
        $directory
    );
    $store->rows[SettingsKeys::adminSync()] = qbSettings([
        'customer_enabled' => false,
        'invoice_enabled'  => false,
        'wallet_enabled'   => true,
        'payment_enabled'  => false,
    ]);

    try {
        $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => validAuth(),
            'sync'  => qbSettings([
                'customer_enabled' => true,
                'invoice_enabled'  => true,
                'wallet_enabled'   => true,
                'payment_enabled'  => true,
            ]),
        ]));
        $stayed = $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => validAuth(),
            'sync'  => qbSettings([
                'customer_enabled' => true,
                'invoice_enabled'  => true,
                'wallet_enabled'   => false,
                'payment_enabled'  => true,
            ]),
        ]));

        expect($directory->queued)->toBe([
            ['company-uuid', ['customer' => true, 'invoice' => true]],
        ])
            ->and($stayed->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminSync()]['wallet_enabled'])->toBeFalse();
    } finally {
        session(['company' => null]);
    }
});
