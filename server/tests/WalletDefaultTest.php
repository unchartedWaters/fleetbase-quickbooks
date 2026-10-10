<?php

use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;

/**
 * @return array<string, mixed>
 */
function walletConfigDefaults(): array
{
    $config = require __DIR__ . '/../config/quickbooks.php';

    return $config['sync'];
}

function walletQueueDirectory(): FleetbaseDirectory
{
    return new class extends FleetbaseDirectory {
        /** @var array<int, array{0: string, 1: array<string, bool>}> */
        public array $queued = [];

        public function queueInScope(string $companyUuid, array $enabled): void
        {
            $this->queued[] = [$companyUuid, $enabled];
        }
    };
}

test('the shipped default leaves wallet sync off and the other entities on', function () {
    $defaults = walletConfigDefaults();

    expect($defaults['wallet_enabled'])->toBeFalse()
        ->and($defaults['customer_enabled'])->toBeTrue()
        ->and($defaults['invoice_enabled'])->toBeTrue()
        ->and($defaults['payment_enabled'])->toBeTrue();
});

test('a fresh install resolves wallet sync to off from the default', function () {
    $resolved = (new SyncSettingsResolver())->resolve([], [], walletConfigDefaults());

    expect($resolved['wallet_enabled'])->toBeFalse()
        ->and($resolved['sources']['wallet_enabled'])->toBe('default')
        ->and($resolved['customer_enabled'])->toBeTrue()
        ->and($resolved['invoice_enabled'])->toBeTrue();
});

test('a stored wallet switch is kept whichever way it was set', function () {
    $resolver = new SyncSettingsResolver();
    $on       = $resolver->resolve([], ['wallet_enabled' => true], walletConfigDefaults());
    $off      = $resolver->resolve([], ['wallet_enabled' => false], ['wallet_enabled' => true]);

    expect($on['wallet_enabled'])->toBeTrue()
        ->and($on['sources']['wallet_enabled'])->toBe('admin')
        ->and($off['wallet_enabled'])->toBeFalse()
        ->and($off['sources']['wallet_enabled'])->toBe('admin');
});

test('connecting a fresh install does not queue wallets', function () {
    $directory = walletQueueDirectory();
    $resolved  = (new SyncSettingsResolver())->resolve([], [], walletConfigDefaults());

    (new SyncFlagger())->queueEnabled($directory, 'company-uuid', $resolved);

    expect($directory->queued)->toBe([
        ['company-uuid', ['customer' => true, 'invoice' => true, 'wallet' => false]],
    ]);
});

test('turning wallet sync on for the first time queues the wallets', function () {
    $store                                  = new MemorySettingsStore();
    $directory                              = walletQueueDirectory();
    $store->rows[SettingsKeys::adminSync()] = ['interval_minutes' => 5];
    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store,
        $directory
    );

    try {
        $saved = $controller->save(InstallAdminRequest::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => validAuth(),
            'sync'  => qbSettings(['wallet_enabled' => true]),
        ]));

        expect($saved->getStatusCode())->toBe(200)
            ->and($directory->queued)->toBe([['company-uuid', ['wallet' => true]]]);
    } finally {
        session(['company' => null]);
    }
});
