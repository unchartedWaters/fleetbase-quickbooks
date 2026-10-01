<?php

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;

test('a late connection write keeps the live row and only its own columns', function () {
    $stale = [
        'company_uuid'         => 'company-uuid',
        'realm_id'             => 'old-realm',
        'access_token'         => 'stale-access',
        'refresh_token'        => 'stale-refresh',
        'token_expires_at'     => 1790000000,
        'rate_limited_until'   => 1790000100,
        'last_rate_limit_wait' => 12,
        'last_batch_at'        => null,
        'home_currency'        => 'USD',
        'default_item_id'      => 'item-1',
        'needs_reauth'         => true,
    ];

    $engine = FleetbaseDirectory::connectionColumns($stale, 'old-realm', FleetbaseDirectory::CONNECTION_ENGINE_FIELDS);
    $tokens = FleetbaseDirectory::connectionColumns($stale, 'old-realm', FleetbaseDirectory::CONNECTION_TOKEN_FIELDS);
    $sparse = FleetbaseDirectory::connectionColumns(
        ['realm_id' => 'old-realm', 'home_currency' => 'USD'],
        'old-realm',
        FleetbaseDirectory::CONNECTION_ENGINE_FIELDS
    );

    expect($engine)->toBeArray()
        ->and(array_keys($engine))->toBe(FleetbaseDirectory::CONNECTION_ENGINE_FIELDS)
        ->and($engine)->not->toHaveKey('access_token')
        ->and($engine)->not->toHaveKey('refresh_token')
        ->and($engine)->not->toHaveKey('token_expires_at')
        ->and($engine)->not->toHaveKey('realm_id')
        ->and($engine['rate_limited_until'])->toBe(1790000100)
        ->and($engine['last_batch_at'])->toBeNull()
        ->and($engine['last_rate_limit_wait'])->toBe(12)
        ->and($engine['home_currency'])->toBe('USD')
        ->and($engine['default_item_id'])->toBe('item-1')
        ->and($engine['needs_reauth'])->toBeTrue()
        ->and(array_keys($tokens))->toBe(FleetbaseDirectory::CONNECTION_TOKEN_FIELDS)
        ->and($tokens['access_token'])->toBe('stale-access')
        ->and($tokens['refresh_token'])->toBe('stale-refresh')
        ->and($tokens['token_expires_at'])->toBe(1790000000)
        ->and($tokens['needs_reauth'])->toBeTrue()
        ->and($tokens)->not->toHaveKey('home_currency')
        ->and(array_keys($sparse))->toBe(['home_currency'])
        ->and(FleetbaseDirectory::connectionColumns($stale, '', FleetbaseDirectory::CONNECTION_ENGINE_FIELDS))->toBeArray()
        ->and(FleetbaseDirectory::connectionColumns($stale, 'new-realm', FleetbaseDirectory::CONNECTION_ENGINE_FIELDS))->toBeNull()
        ->and(FleetbaseDirectory::connectionColumns($stale, 'new-realm', FleetbaseDirectory::CONNECTION_TOKEN_FIELDS))->toBeNull()
        ->and(FleetbaseDirectory::connectionColumns($stale, null, FleetbaseDirectory::CONNECTION_ENGINE_FIELDS))->toBeNull()
        ->and(FleetbaseDirectory::connectionColumns($stale, null, FleetbaseDirectory::CONNECTION_TOKEN_FIELDS))->toBeNull()
        ->and(FleetbaseDirectory::connectionColumns(['home_currency' => 'USD'], 'old-realm', FleetbaseDirectory::CONNECTION_ENGINE_FIELDS))->toBeNull();
});

test('a refused refresh does not write tokens over a newer rotation', function () {
    $refused = [
        'company_uuid'  => 'company-uuid',
        'realm_id'      => 'realm-1',
        'access_token'  => 'old-access',
        'refresh_token' => 'old-refresh',
        'needs_reauth'  => true,
    ];
    $columns = FleetbaseDirectory::connectionColumns($refused, 'realm-1', FleetbaseDirectory::CONNECTION_TOKEN_FIELDS);

    $same = FleetbaseDirectory::reauthColumns($refused, $columns, 'old-refresh', 'old-access');
    $newer = FleetbaseDirectory::reauthColumns($refused, $columns, 'new-refresh', 'new-access');

    expect($same)->toBe(['needs_reauth' => true])
        ->and($newer)->toBe([]);
});

test('an unchanged invoice field is not written back over a later edit', function () {
    $loaded = ['number' => 'INV-1', 'notes' => 'Original', 'date' => '2026-09-01'];
    $same   = FleetbaseDirectory::changedColumns($loaded, $loaded, ['number', 'notes', 'date']);
    $renamed = FleetbaseDirectory::changedColumns(
        ['number' => 'QB-9', 'notes' => 'Original', 'date' => '2026-09-01'],
        $loaded,
        ['number', 'notes', 'date']
    );

    expect($same)->toBe([])
        ->and($renamed)->toBe(['number' => 'QB-9']);
});

test('ledger writes skip a company whose stored connection is missing or on another realm', function () {
    $ledger = ['company_uuid' => 'company-uuid', 'realm_id' => 'old-realm'];
    $same   = ['company_uuid' => 'company-uuid', 'realm_id' => 'old-realm'];
    $other  = ['company_uuid' => 'company-uuid', 'realm_id' => 'new-realm'];
    $empty  = ['company_uuid' => 'company-uuid', 'realm_id' => ''];

    expect(FleetbaseDirectory::connectionStillCurrent(null, $ledger))->toBeFalse()
        ->and(FleetbaseDirectory::connectionStillCurrent($other, $ledger))->toBeFalse()
        ->and(FleetbaseDirectory::connectionStillCurrent($other, null))->toBeFalse()
        ->and(FleetbaseDirectory::connectionStillCurrent($same, $ledger))->toBeTrue()
        ->and(FleetbaseDirectory::connectionStillCurrent($empty, $ledger))->toBeTrue()
        ->and(FleetbaseDirectory::connectionStillCurrent($empty, null))->toBeTrue()
        ->and(FleetbaseDirectory::connectionStillCurrent(['company_uuid' => 'company-uuid'], $ledger))->toBeTrue();
});
