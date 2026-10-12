<?php

use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;

function orgRow(array $fields, bool $override = true): array
{
    return array_merge($fields, [SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE, 'override' => $override]);
}

test('an organization sync override is read only when it carries the organization marker and override is on', function () {
    $store                                            = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('org-a')]  = orgRow(['interval_minutes' => 7]);
    // A row written by an older build that saved organization rows has no marker and is ignored.
    $store->rows[SettingsKeys::companySync('org-b')] = ['interval_minutes' => 9, 'override' => true];
    $store->rows[SettingsKeys::companySync('org-d')] = orgRow(['interval_minutes' => 11], false);

    expect($store->companySync('org-a'))->toBe(['interval_minutes' => 7])
        ->and($store->companySync('org-b'))->toBe([])
        ->and($store->companySync('org-c'))->toBe([])
        ->and($store->companySync('org-d'))->toBe([]);
});

test('putCompanySync writes the marker and only that organization row, keeping sync keys only', function () {
    $store = new MemorySettingsStore();
    $store->putCompanySync('org-a', ['interval_minutes' => 7, 'client_id' => 'nope', 'public_webhook_receiver_url' => 'https://x.example']);

    expect(array_keys($store->rows))->toBe([SettingsKeys::companySync('org-a')])
        ->and($store->rows[SettingsKeys::companySync('org-a')])->toBe(orgRow(['interval_minutes' => 7]))
        ->and($store->companySync('org-a'))->toBe(['interval_minutes' => 7]);
});

test('clearCompanySync returns the organization to the global defaults', function () {
    $store = new MemorySettingsStore();
    $store->putCompanySync('org-a', ['interval_minutes' => 7]);
    $store->clearCompanySync('org-a');

    expect($store->rows[SettingsKeys::companySync('org-a')])->toBe(orgRow([], false))
        ->and($store->companySync('org-a'))->toBe([]);
});
