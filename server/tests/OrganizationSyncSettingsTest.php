<?php

use Fleetbase\Quickbooks\Services\OrganizationSyncSettings;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;

function orgSyncStore(): MemorySettingsStore
{
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = ['client_id' => 'global-client-id', 'client_secret' => (new SecretCipher())->encrypt('global-secret-value')];
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 15, 'wallet_enabled' => false]);

    return $store;
}

function orgSyncSettings(MemorySettingsStore $store): OrganizationSyncSettings
{
    return new OrganizationSyncSettings(new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()), $store);
}

test('saving an override replaces the stored one with exactly the non-empty sync fields sent', function () {
    $store = orgSyncStore();
    $store->putCompanySync('org-a', ['interval_minutes' => 30, 'retry_limit' => 2]);

    $errors = orgSyncSettings($store)->save('org-a', true, ['retry_limit' => 7, 'batch_size' => '', 'interval_minutes' => null, 'client_id' => 'nope']);

    expect($errors)->toBe([])
        ->and($store->companySync('org-a'))->toBe(['retry_limit' => 7]);
});

test('saving without an override clears it so the organization inherits the global values', function () {
    $store = orgSyncStore();
    $store->putCompanySync('org-a', ['interval_minutes' => 30]);
    $sync = orgSyncSettings($store);

    expect($sync->save('org-a', false, ['interval_minutes' => 99]))->toBe([])
        ->and($store->companySync('org-a'))->toBe([])
        ->and($sync->effective('org-a')['interval_minutes'])->toBe(15);
});

test('an invalid override writes nothing and reports only the fields the override owns', function () {
    $store                                  = orgSyncStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['invoice_conflict' => 'report']);
    $sync                                   = orgSyncSettings($store);

    $ownErrors = $sync->save('org-a', true, ['interval_minutes' => 0]);
    $inherited = $sync->save('org-a', true, ['retry_limit' => 3]);

    expect(array_keys($ownErrors))->toBe(['interval_minutes'])
        ->and($inherited)->toBe([])
        ->and($store->companySync('org-a'))->toBe(['retry_limit' => 3]);
});

test('an enabled flag in an override must be boolean-like', function () {
    $store = orgSyncStore();

    $errors = orgSyncSettings($store)->save('org-a', true, ['invoice_enabled' => 'sometimes']);

    expect($errors)->toHaveKey('invoice_enabled')
        ->and($store->companySync('org-a'))->toBe([]);
});

test('a direction the override sets to off or blank is stored as both', function () {
    $store = orgSyncStore();

    orgSyncSettings($store)->save('org-a', true, ['customer_direction' => 'off', 'invoice_direction' => 'outbound']);

    expect($store->companySync('org-a'))->toBe(['customer_direction' => 'both', 'invoice_direction' => 'outbound']);
});

test('the organization view carries sync options only and never a global setting', function () {
    $store = orgSyncStore();
    $sync  = orgSyncSettings($store);
    $sync->save('org-a', true, ['interval_minutes' => 45]);

    $view = $sync->view('org-a', true);

    expect(array_keys($view))->toBe(['can_edit', 'override', 'sync', 'global_sync', 'company_sync', 'credentials_configured'])
        ->and($view['can_edit'])->toBeTrue()
        ->and($view['override'])->toBeTrue()
        ->and($view['sync']['interval_minutes'])->toBe(45)
        ->and($view['global_sync']['interval_minutes'])->toBe(15)
        ->and($view['company_sync'])->toBe(['interval_minutes' => 45])
        ->and($view['credentials_configured'])->toBeTrue()
        ->and(array_diff(array_keys($view['sync']), SettingsKeys::SYNC_FIELDS))->toBe([])
        ->and(array_diff(array_keys($view['global_sync']), SettingsKeys::SYNC_FIELDS))->toBe([]);
});

test('an override that matches the global values still counts as an override in the view', function () {
    $store = orgSyncStore();
    $sync  = orgSyncSettings($store);
    $sync->save('org-a', true, []);

    $view = $sync->view('org-a', false);

    expect($view['override'])->toBeTrue()
        ->and($view['can_edit'])->toBeFalse()
        ->and($view['company_sync'])->toBe([]);
});

test('read-only, public URL and global keys are never stored in an override or shown in the organization view', function () {
    $store = orgSyncStore();
    $sync  = orgSyncSettings($store);

    $sync->save('org-a', true, [
        'interval_minutes'              => 45,
        'override'                      => false,
        'sources'                       => ['interval_minutes' => 'company'],
        'public_receiver_url'           => 'https://evil.example/hook',
        'webhook_url'                   => 'https://evil.example/hook',
        'internal_webhook_receiver_url' => 'https://evil.example/hook',
        'internal_oauth_redirect_url'   => 'https://evil.example/cb',
        'public_webhook_receiver_url'   => 'https://evil.example/hook',
        'public_oauth_redirect_url'     => 'https://evil.example/cb',
        'client_id'                     => 'other-app',
        'environment'                   => 'sandbox',
    ]);
    $view = $sync->view('org-a', true);

    expect($store->companySync('org-a'))->toBe(['interval_minutes' => 45])
        ->and($view['company_sync'])->toBe(['interval_minutes' => 45])
        ->and(array_diff(array_keys($store->rows[SettingsKeys::companySync('org-a')]), [...SettingsKeys::SYNC_FIELDS, SettingsKeys::ORGANIZATION_MARKER, 'override']))->toBe([]);
});
