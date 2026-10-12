<?php

use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Jobs\QueueCompanyScope;
use Fleetbase\Quickbooks\Jobs\QueueGlobalScope;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\CapturedJobs;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;

/**
 * The shipped entity switches, read from the config source. Requiring the file would call env(),
 * which needs phpoption/phpoption, a package this test environment does not install.
 *
 * @return array<string, mixed>
 */
function walletConfigDefaults(): array
{
    $source   = (string) file_get_contents(__DIR__ . '/../config/quickbooks.php');
    $defaults = qbSettings();
    foreach (['customer', 'invoice', 'payment', 'wallet'] as $entity) {
        $key = $entity . '_enabled';
        expect(preg_match("/'" . $key . "'\\s*=>\\s*(true|false)\\b/", $source, $match))->toBe(1);
        $defaults[$key] = $match[1] === 'true';
    }

    return $defaults;
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
    $resolved = (new SyncSettingsResolver())->resolve([], [], walletConfigDefaults());

    expect((new SyncFlagger())->enabledEntities($resolved))->toBe(['customer' => true, 'invoice' => true, 'wallet' => false]);
});

test('turning wallet sync on for the first time queues the wallets', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = ['interval_minutes' => 5];
    session(['company' => 'company-uuid']);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );

    try {
        $saved = null;
        $jobs  = CapturedJobs::capture(function () use ($controller, &$saved): void {
            $saved = $controller->save(InstallAdminRequest::create('/settings', 'POST', [
                'scope' => 'admin',
                'auth'  => validAuth(),
                'sync'  => qbSettings(['wallet_enabled' => true]),
            ]));
        });

        // A global save queues in the background, for every connected organization.
        $global = array_values(array_filter($jobs, fn ($job) => $job instanceof QueueGlobalScope));
        expect($saved->getStatusCode())->toBe(200)
            ->and($global)->toHaveCount(1)
            ->and(QueueCompanyScope::turnedOn($global[0]->before, $global[0]->after))->toBe(['wallet' => true]);
    } finally {
        session(['company' => null]);
    }
});
