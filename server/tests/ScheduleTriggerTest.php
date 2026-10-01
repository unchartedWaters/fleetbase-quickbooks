<?php

use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->lockCache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
});

afterEach(function () {
    Cache::swap($this->lockCache);
});

test('the sync interval sends any due rows and 20 due rows may start early', function () {
    expect(QuickBooksClient::UPDATE_BATCH_SIZE)->toBe(20);
    $now = time();

    $one = scheduleTriggerRun($now - 400, 1);
    expect($one['due'])->toBeTrue()
        ->and($one['batch']['status'])->toBe('finished')
        ->and($one['batch']['trigger'])->toBe('scheduled')
        ->and($one['client']->calls)->toContain('createCustomer');

    $few = scheduleTriggerRun($now, 19);
    expect($few['due'])->toBeFalse()
        ->and($few['batch']['status'])->toBe('skipped')
        ->and($few['client']->calls)->toBe([]);

    $full = scheduleTriggerRun($now, 20);
    expect($full['due'])->toBeTrue()
        ->and($full['batch']['status'])->toBe('finished')
        ->and($full['batch']['trigger'])->toBe('scheduled')
        ->and($full['client']->heldLock)->toBeTrue()
        ->and($full['client']->calls)->not->toBe([]);

    $empty = scheduleTriggerRun($now - 400, 0);
    expect($empty['due'])->toBeFalse()
        ->and($empty['batch']['status'])->toBe('skipped')
        ->and($empty['client']->calls)->toBe([]);
});

test('sync now and reconcile still run before the interval elapses', function () {
    $now  = time();
    $sync = scheduleTriggerRun($now, 1, 'now');
    expect($sync['due'])->toBeFalse()
        ->and($sync['batch']['status'])->toBe('finished')
        ->and($sync['client']->calls)->toContain('createCustomer')
        ->and($sync['client']->heldLock)->toBeTrue();

    $reconcile = scheduleTriggerRun($now, 0, 'manual');
    expect($reconcile['batch']['status'])->toBe('finished')
        ->and($reconcile['batch']['trigger'])->toBe('manual');
});

/**
 * @return array{due: bool, batch: array<string, mixed>, client: FakeQuickBooks}
 */
function scheduleTriggerRun(int $lastBatchAt, int $pending, string $trigger = 'scheduled'): array
{
    $client = new class extends FakeQuickBooks {
        public bool $heldLock = false;

        public function createCustomer(array $connection, array $payload): array
        {
            $this->heldLock = $this->heldLock || BatchRunner::holds('company-uuid');

            return parent::createCustomer($connection, $payload);
        }

        public function batch(array $connection, array $items): array
        {
            $this->heldLock = $this->heldLock || BatchRunner::holds('company-uuid');

            return parent::batch($connection, $items);
        }
    };

    $directory                                      = new FleetbaseDirectory();
    $directory->memory                              = new SyncLedger();
    $now                                            = time();
    $directory->memory->connections['company-uuid'] = [
        'company_uuid'             => 'company-uuid',
        'realm_id'                 => 'realm-1',
        'access_token'             => 'access',
        'refresh_token'            => 'refresh',
        'token_expires_at'         => $now + 86400,
        'needs_reauth'             => false,
        'last_batch_at'            => $lastBatchAt,
        'last_customer_catalog_at' => $now,
        'home_currency'            => 'USD',
        'default_item_id'          => 'item-1',
    ];
    for ($index = 1; $index <= $pending; $index++) {
        $uuid                                  = 'cust-' . $index;
        $directory->memory->customers[$uuid]   = [
            'uuid'         => $uuid,
            'company_uuid' => 'company-uuid',
            'name'         => 'Customer ' . $index,
            'email'        => 'customer-' . $index . '@example.test',
        ];
        $directory->memory->pending[] = [
            'company_uuid'    => 'company-uuid',
            'local_type'      => 'customer',
            'local_uuid'      => $uuid,
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => null,
        ];
    }

    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::companySync('company-uuid')] = [
        'enabled'                 => true,
        'interval_minutes'        => 5,
        'batch_size'              => 7,
        'periodic_interval_hours' => 24,
    ];
    $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $tokens   = new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine   = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );
    $runner = new BatchRunner($engine, $directory, $settings, $store, $tokens);

    return [
        'due'    => $runner->isScheduledDue('company-uuid', $now),
        'batch'  => $runner->run('company-uuid', $trigger),
        'client' => $client,
    ];
}
