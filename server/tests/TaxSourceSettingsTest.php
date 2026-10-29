<?php

use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SettingsValidator;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;

/*
 * Tax is its own sync category: one choice of who works out invoice tax, QuickBooks (the default)
 * or Fleetbase. It is a sync option like the others, copied to each organization.
 */

test('tax is worked out by QuickBooks unless the settings chose Fleetbase', function () {
    $resolver = new SyncSettingsResolver();
    $template = qbSettings();
    unset($template['tax_source']);

    $none     = $resolver->resolve([], $template, []);
    $fleet    = $resolver->resolve(['tax_source' => 'fleetbase'], $template, []);
    $unknown  = $resolver->resolve(['tax_source' => 'both'], $template, []);
    $configed = $resolver->resolve([], $template, ['tax_source' => 'fleetbase']);

    expect(SettingsKeys::SYNC_FIELDS)->toContain('tax_source')
        ->and((string) file_get_contents(__DIR__ . '/../config/quickbooks.php'))
        ->toMatch("/'tax_source'\s+=> 'quickbooks'/")
        ->and($none['tax_source'])->toBe('quickbooks')
        ->and($none['sources']['tax_source'])->toBe('default')
        ->and($fleet['tax_source'])->toBe('fleetbase')
        ->and($fleet['sources']['tax_source'])->toBe('admin')
        ->and($unknown['tax_source'])->toBe('quickbooks')
        ->and($configed['tax_source'])->toBe('fleetbase');
});

test('the tax source must be QuickBooks or Fleetbase', function () {
    $validator = new SettingsValidator();

    expect($validator->syncErrors(qbSettings(['tax_source' => 'quickbooks'])))->not->toHaveKey('tax_source')
        ->and($validator->syncErrors(qbSettings(['tax_source' => 'fleetbase'])))->not->toHaveKey('tax_source')
        ->and($validator->syncErrors(qbSettings(['tax_source' => 'agency']))['tax_source'] ?? null)
        ->toBe('quickbooks.validation.tax-source');
});

test('Fleetbase can work out tax whichever way invoices sync', function () {
    $validator = new SettingsValidator();

    // With invoices paused or only from QuickBooks, Fleetbase tax means QuickBooks tax is never
    // copied onto the Fleetbase lines.
    $inbound  = $validator->syncErrors(qbSettings(['tax_source' => 'fleetbase', 'invoice_direction' => 'inbound']));
    $off      = $validator->syncErrors(qbSettings(['tax_source' => 'fleetbase', 'invoice_enabled' => false]));
    $outbound = $validator->syncErrors(qbSettings(['tax_source' => 'fleetbase', 'invoice_direction' => 'outbound']));

    expect($inbound)->not->toHaveKey('tax_source')
        ->and($off)->not->toHaveKey('tax_source')
        ->and($outbound)->not->toHaveKey('tax_source');
});

test('an organization copy saved before the tax source existed is still its own copy', function () {
    $row = array_merge(
        SettingsStore::onlySyncFields(qbSettings(['interval_minutes' => 7])),
        [SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE]
    );
    unset($row['tax_source']);

    $resolved = (new SyncSettingsResolver())->resolve(
        SettingsStore::syncFromRow($row),
        qbSettings(['interval_minutes' => 9, 'tax_source' => 'fleetbase']),
        []
    );

    expect(SettingsStore::isCompleteCopy($row))->toBeTrue()
        ->and($resolved['interval_minutes'])->toBe(7)
        // The copy predates the choice, when QuickBooks always worked out tax; the template's
        // choice does not reach it.
        ->and($resolved['tax_source'])->toBe('quickbooks');
});

test('an older organization row that overrides some fields does not take the template tax choice', function () {
    $row = [
        SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE,
        'override'                        => true,
        'interval_minutes'                => 7,
    ];

    $resolved = (new SyncSettingsResolver())->resolve(
        SettingsStore::syncFromRow($row),
        qbSettings(['interval_minutes' => 9, 'tax_source' => 'fleetbase']),
        []
    );

    expect($resolved['interval_minutes'])->toBe(7)
        ->and($resolved['tax_source'])->toBe('quickbooks');
});
