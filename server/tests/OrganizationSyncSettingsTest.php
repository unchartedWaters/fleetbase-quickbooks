<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Services\OrganizationSyncSettings;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\CapturedJobs;
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

test('saving stores a complete row: the current effective settings with the submitted non-empty fields applied', function () {
    $store = orgSyncStore();
    $store->putCompanySync('org-a', qbSettings(['interval_minutes' => 30, 'retry_limit' => 2]));

    $errors = orgSyncSettings($store)->save('org-a', ['retry_limit' => 7, 'batch_size' => '', 'interval_minutes' => null, 'client_id' => 'nope']);
    $row    = $store->rows[SettingsKeys::companySync('org-a')];

    expect($errors)->toBe([])
        ->and(SettingsStore::isCompleteCopy($row))->toBeTrue()
        ->and($row['retry_limit'])->toBe(7)
        ->and($row['interval_minutes'])->toBe(30)
        ->and($row['batch_size'])->toBe(100)
        ->and($row)->not->toHaveKey('client_id')
        ->and($row)->not->toHaveKey('override');
});

test('a first save by an organization without a copy starts from the template', function () {
    $store = orgSyncStore();

    orgSyncSettings($store)->save('org-a', ['retry_limit' => 7]);
    $row = $store->rows[SettingsKeys::companySync('org-a')];

    expect($row['retry_limit'])->toBe(7)
        ->and($row['interval_minutes'])->toBe(15)
        ->and($row['wallet_enabled'])->toBeFalse();
});

test('saving over a legacy partial override keeps its fields and fills the rest from the template', function () {
    $store                                           = orgSyncStore();
    $store->rows[SettingsKeys::companySync('org-a')] = orgRow(['interval_minutes' => 45]);

    orgSyncSettings($store)->save('org-a', ['retry_limit' => 3]);
    $row = $store->rows[SettingsKeys::companySync('org-a')];

    expect(SettingsStore::isCompleteCopy($row))->toBeTrue()
        ->and($row['interval_minutes'])->toBe(45)
        ->and($row['retry_limit'])->toBe(3)
        ->and($row['wallet_enabled'])->toBeFalse();
});

test('reset replaces the organization settings with the current template as a one-time copy', function () {
    $store = orgSyncStore();
    $store->putCompanySync('org-a', qbSettings(['interval_minutes' => 30]));
    $sync = orgSyncSettings($store);

    $sync->reset('org-a');
    expect($sync->effective('org-a')['interval_minutes'])->toBe(15)
        ->and($store->hasCompanyCopy('org-a'))->toBeTrue();

    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 60]);
    expect($sync->effective('org-a')['interval_minutes'])->toBe(15);

    $sync->reset('org-a');
    expect($sync->effective('org-a')['interval_minutes'])->toBe(60);
});

test('an invalid save writes nothing and reports the full-set errors', function () {
    $store  = orgSyncStore();
    $before = $store->rows;

    $errors = orgSyncSettings($store)->save('org-a', ['interval_minutes' => 0, 'batch_size' => 500]);

    expect(array_keys($errors))->toBe(['interval_minutes', 'batch_size'])
        ->and($store->rows)->toBe($before);
});

test('an enabled flag in a save must be boolean-like', function () {
    $store = orgSyncStore();

    $errors = orgSyncSettings($store)->save('org-a', ['invoice_enabled' => 'sometimes']);

    expect($errors)->toHaveKey('invoice_enabled')
        ->and(array_key_exists(SettingsKeys::companySync('org-a'), $store->rows))->toBeFalse();
});

test('a direction the save sets to off or blank is stored as both', function () {
    $store = orgSyncStore();

    orgSyncSettings($store)->save('org-a', ['customer_direction' => 'off', 'invoice_direction' => 'outbound']);
    $row = $store->rows[SettingsKeys::companySync('org-a')];

    expect($row['customer_direction'])->toBe('both')
        ->and($row['invoice_direction'])->toBe('outbound');
});

test('ensureCopy stores the current effective settings once and keeps an existing copy', function () {
    $store = orgSyncStore();
    $sync  = orgSyncSettings($store);

    $sync->ensureCopy('org-a');
    $first                                  = $store->rows[SettingsKeys::companySync('org-a')];
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 60]);
    $sync->ensureCopy('org-a');

    expect(SettingsStore::isCompleteCopy($first))->toBeTrue()
        ->and($first['interval_minutes'])->toBe(15)
        ->and($store->rows[SettingsKeys::companySync('org-a')])->toBe($first);
});

test('ensureCopy keeps a legacy partial override by copying the effective settings', function () {
    $store                                           = orgSyncStore();
    $store->rows[SettingsKeys::companySync('org-a')] = orgRow(['interval_minutes' => 45]);

    orgSyncSettings($store)->ensureCopy('org-a');
    $row = $store->rows[SettingsKeys::companySync('org-a')];

    expect(SettingsStore::isCompleteCopy($row))->toBeTrue()
        ->and($row['interval_minutes'])->toBe(45)
        ->and($row['wallet_enabled'])->toBeFalse();
});

test('the organization view has exactly the contract keys and sync fields only', function () {
    $store = orgSyncStore();
    $sync  = orgSyncSettings($store);

    $without = $sync->view('org-a', false);
    $sync->save('org-a', ['interval_minutes' => 45]);
    $view = $sync->view('org-a', true);

    expect(array_keys($view))->toBe(['can_edit', 'sync', 'has_copy', 'credentials_configured'])
        ->and($without['has_copy'])->toBeFalse()
        ->and($without['can_edit'])->toBeFalse()
        ->and($view['has_copy'])->toBeTrue()
        ->and($view['can_edit'])->toBeTrue()
        ->and($view['sync']['interval_minutes'])->toBe(45)
        ->and($view['credentials_configured'])->toBeTrue()
        ->and(array_diff(array_keys($view['sync']), SettingsKeys::SYNC_FIELDS))->toBe([]);
});

test('read-only, public URL and global keys are never stored in an organization copy or shown in its view', function () {
    $store = orgSyncStore();
    $sync  = orgSyncSettings($store);

    $sync->save('org-a', [
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
    $row = $store->rows[SettingsKeys::companySync('org-a')];

    expect(array_diff(array_keys($row), [...SettingsKeys::SYNC_FIELDS, SettingsKeys::ORGANIZATION_MARKER]))->toBe([])
        ->and($row['interval_minutes'])->toBe(45)
        ->and(array_keys($store->rows))->toContain(SettingsKeys::companySync('org-a'))
        ->and(array_key_exists(SettingsKeys::companyAuth('org-a'), $store->rows))->toBeFalse()
        ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('global-client-id');
});

test('connecting gives the organization its own copy before it queues, and reconnecting keeps that copy', function () {
    $store                                  = orgSyncStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => true, 'interval_minutes' => 15]);
    $controller                             = (new ReflectionClass(ConnectionController::class))->newInstanceWithoutConstructor();
    foreach (['settings' => new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()), 'store' => $store] as $property => $value) {
        $reflection = new ReflectionProperty(ConnectionController::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($controller, $value);
    }
    $connect = new ReflectionMethod(ConnectionController::class, 'queueExistingRecords');
    $connect->setAccessible(true);

    $jobs                                   = CapturedJobs::capture(fn () => $connect->invoke($controller, 'org-a'));
    $copy                                   = $store->rows[SettingsKeys::companySync('org-a')];
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false, 'interval_minutes' => 99]);
    CapturedJobs::capture(fn () => $connect->invoke($controller, 'org-a'));

    expect(SettingsStore::isCompleteCopy($copy))->toBeTrue()
        ->and($copy['wallet_enabled'])->toBeTrue()
        ->and($copy['interval_minutes'])->toBe(15)
        ->and(CapturedJobs::queued($jobs))->toBe([['org-a', ['customer' => true, 'invoice' => true, 'wallet' => true]]])
        ->and($store->rows[SettingsKeys::companySync('org-a')])->toBe($copy);
});
