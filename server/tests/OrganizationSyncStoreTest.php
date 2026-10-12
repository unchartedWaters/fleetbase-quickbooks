<?php

use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;

function orgCopy(array $fields = []): array
{
    return array_merge(SettingsStore::onlySyncFields(qbSettings()), $fields, [SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE]);
}

test('a complete organization copy is read only when it carries the organization marker', function () {
    $store                                           = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('org-a')] = orgCopy(['interval_minutes' => 7]);
    $unmarked                                        = orgCopy(['interval_minutes' => 9]);
    unset($unmarked[SettingsKeys::ORGANIZATION_MARKER]);
    $store->rows[SettingsKeys::companySync('org-b')] = $unmarked;

    expect($store->companySync('org-a')['interval_minutes'])->toBe(7)
        ->and($store->hasCompanyCopy('org-a'))->toBeTrue()
        ->and($store->companySync('org-b'))->toBe([])
        ->and($store->hasCompanyCopy('org-b'))->toBeFalse()
        ->and($store->companySync('org-c'))->toBe([])
        ->and($store->hasCompanyCopy('org-c'))->toBeFalse();
});

test('a row missing a field or holding a null or empty one is not a complete copy', function () {
    $missing = orgCopy();
    unset($missing['retry_limit']);

    expect(SettingsStore::isCompleteCopy(orgCopy()))->toBeTrue()
        ->and(SettingsStore::isCompleteCopy(orgCopy(['customer_enabled' => false])))->toBeTrue()
        ->and(SettingsStore::isCompleteCopy($missing))->toBeFalse()
        ->and(SettingsStore::isCompleteCopy(orgCopy(['batch_size' => null])))->toBeFalse()
        ->and(SettingsStore::isCompleteCopy(orgCopy(['customer_conflict' => ''])))->toBeFalse();
});

test('a legacy row reads exactly as before: override=true keeps its fields, override=false and no row read as none', function () {
    $store                                           = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('org-a')] = orgRow(['interval_minutes' => 7]);
    // A row written by an older build that saved organization rows has no marker and is ignored.
    $store->rows[SettingsKeys::companySync('org-b')] = ['interval_minutes' => 9, 'override' => true];
    $store->rows[SettingsKeys::companySync('org-d')] = orgRow(['interval_minutes' => 11], false);

    expect($store->companySync('org-a'))->toBe(['interval_minutes' => 7])
        ->and($store->hasCompanyCopy('org-a'))->toBeFalse()
        ->and($store->companySync('org-b'))->toBe([])
        ->and($store->companySync('org-d'))->toBe([]);
});

test('putCompanySync writes the marker without an override flag, only that organization row, keeping sync keys only', function () {
    $store = new MemorySettingsStore();
    $store->putCompanySync('org-a', array_merge(qbSettings(['interval_minutes' => 7]), ['client_id' => 'nope', 'public_webhook_receiver_url' => 'https://x.example', 'override' => true, 'sources' => ['x']]));

    $row = $store->rows[SettingsKeys::companySync('org-a')];
    expect(array_keys($store->rows))->toBe([SettingsKeys::companySync('org-a')])
        ->and(array_diff(array_keys($row), [...SettingsKeys::SYNC_FIELDS, SettingsKeys::ORGANIZATION_MARKER]))->toBe([])
        ->and($row)->not->toHaveKey('override')
        ->and($store->hasCompanyCopy('org-a'))->toBeTrue()
        ->and($store->companySync('org-a')['interval_minutes'])->toBe(7);
});

test('the store no longer offers clearCompanySync', function () {
    expect(method_exists(SettingsStore::class, 'clearCompanySync'))->toBeFalse();
});
