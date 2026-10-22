<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;

function orgResolutionSettings(): SettingsService
{
    return new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
}

function orgResolutionStore(array $global = [], array $orgs = []): MemorySettingsStore
{
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = array_merge(['override' => true], $global);
    // Each organization holds a complete copy: the template at this moment with its own fields over it.
    $template = SettingsStore::onlySyncFields(orgResolutionSettings()->resolveSync([], $store->adminSync(), $store->defaultSync()));
    foreach ($orgs as $uuid => $fields) {
        $store->putCompanySync($uuid, array_merge($template, $fields));
    }

    return $store;
}

function orgResolve(MemorySettingsStore $store, string $company): array
{
    return orgResolutionSettings()->resolveSync($store->companySync($company), $store->adminSync(), $store->defaultSync());
}

function orgResolutionPrivate(object $target, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$args);
}

test('an organization copy field wins over the global row', function () {
    $store = orgResolutionStore(['interval_minutes' => 10, 'batch_size' => 50], ['org-a' => ['interval_minutes' => 3]]);

    $resolved = orgResolve($store, 'org-a');

    expect($resolved['interval_minutes'])->toBe(3)
        ->and($resolved['batch_size'])->toBe(50);
});

test('a legacy partial override still inherits the fields it leaves out until it is migrated', function () {
    $store                                           = orgResolutionStore(['interval_minutes' => 10, 'batch_size' => 50, 'customer_conflict' => 'quickbooks', 'customer_direction' => 'inbound']);
    $store->rows[SettingsKeys::companySync('org-a')] = orgRow(['interval_minutes' => null, 'batch_size' => '', 'customer_conflict' => '', 'customer_direction' => null, 'retry_limit' => 9]);
    $store->rows[SettingsKeys::companySync('org-b')] = orgRow(['retry_limit' => 9], false);

    $resolved = orgResolve($store, 'org-a');

    expect($resolved['interval_minutes'])->toBe(10)
        ->and($resolved['batch_size'])->toBe(50)
        ->and($resolved['customer_conflict'])->toBe('quickbooks')
        ->and($resolved['customer_direction'])->toBe('inbound')
        ->and($resolved['retry_limit'])->toBe(9)
        ->and(orgResolve($store, 'org-b')['retry_limit'])->toBe(5);
});

test('an organization copy false stays false over a global true', function () {
    $store = orgResolutionStore(['customer_enabled' => true, 'wallet_enabled' => false], ['org-a' => ['customer_enabled' => false, 'wallet_enabled' => true]]);

    $resolved = orgResolve($store, 'org-a');

    expect($resolved['customer_enabled'])->toBeFalse()
        ->and($resolved['wallet_enabled'])->toBeTrue();
});

test('an organization without a copy gets exactly the template result', function () {
    $store    = orgResolutionStore(['interval_minutes' => 10, 'invoice_enabled' => false], ['org-a' => ['interval_minutes' => 3]]);
    $template = orgResolutionSettings()->resolveSync([], $store->adminSync(), $store->defaultSync());

    expect(orgResolve($store, 'org-b'))->toBe($template)
        ->and($template['interval_minutes'])->toBe(10);
});

test('changing every global sync field leaves an organization copy unchanged', function () {
    $store  = orgResolutionStore(['interval_minutes' => 10, 'customer_conflict' => 'quickbooks'], ['org-a' => []]);
    $before = orgResolve($store, 'org-a');

    $store->rows[SettingsKeys::adminSync()] = qbSettings([
        'enabled'                 => false,
        'interval_minutes'        => 77,
        'periodic_interval_hours' => 3,
        'batch_size'              => 9,
        'retry_limit'             => 2,
        'default_backoff_seconds' => 99,
        'customer_conflict'       => 'fleetbase',
        'customer_reference'      => 'quickbooks',
        'customer_direction'      => 'inbound',
        'invoice_enabled'         => false,
        'payment_direction'       => 'outbound',
        'wallet_enabled'          => false,
    ]);

    expect(orgResolve($store, 'org-a'))->toBe($before)
        ->and(orgResolve($store, 'org-b')['interval_minutes'])->toBe(77);
});

test('one organization copy never changes another organization result', function () {
    $store = orgResolutionStore(['interval_minutes' => 10], [
        'org-a' => ['interval_minutes' => 3, 'customer_enabled' => false],
        'org-b' => ['interval_minutes' => 20],
    ]);

    $a = orgResolve($store, 'org-a');
    $b = orgResolve($store, 'org-b');

    expect($a['interval_minutes'])->toBe(3)
        ->and($b['interval_minutes'])->toBe(20)
        ->and($b['customer_enabled'])->toBeTrue();
});

test('the batch runner schedules each organization with its own copy', function () {
    $store  = orgResolutionStore(['interval_minutes' => 10], ['org-a' => ['interval_minutes' => 3]]);
    $runner = (new ReflectionClass(BatchRunner::class))->newInstanceWithoutConstructor();
    foreach (['settings' => orgResolutionSettings(), 'store' => $store] as $property => $value) {
        $reflection = new ReflectionProperty(BatchRunner::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($runner, $value);
    }

    expect($runner->deferSeconds('org-a'))->toBe(180)
        ->and($runner->deferSeconds('org-b'))->toBe(600);

    // A later global edit moves an organization without a copy, never one with a copy.
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['interval_minutes' => 20]);

    expect($runner->deferSeconds('org-a'))->toBe(180)
        ->and($runner->deferSeconds('org-b'))->toBe(1200);
});

test('the webhook flush uses the copy of the company it is flushing', function () {
    $dispatcher = new class implements Dispatcher {
        public array $jobs = [];

        public function dispatch($command)
        {
            $this->jobs[] = $command;

            return $command;
        }

        public function dispatchSync($command, $handler = null)
        {
            return $command;
        }

        public function dispatchNow($command, $handler = null)
        {
            return $command;
        }

        public function hasCommandHandler($command)
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return false;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    };
    $container = Container::getInstance();
    $previous  = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
    $container->instance(Dispatcher::class, $dispatcher);

    try {
        $store    = orgResolutionStore(['customer_enabled' => true], ['org-a' => ['customer_enabled' => false]]);
        $listener = new EnqueueWebhookSync(orgResolutionSettings());
        $listener->handle(new QuickBooksEntityChanged('org-a', 'realm-a', 'customer', '1', 'create', 'cust-a'));
        $listener->handle(new QuickBooksEntityChanged('org-b', 'realm-b', 'customer', '2', 'create', 'cust-b'));
        $listener->flush($store);
    } finally {
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }

    $batches = array_values(array_filter($dispatcher->jobs, static fn ($job) => $job instanceof SyncWebhookBatch));
    expect($batches)->toHaveCount(1)
        ->and($batches[0]->companyUuid)->toBe('org-b');
});

test('connect copies customers per the organization copy', function () {
    $store      = orgResolutionStore(['customer_enabled' => true], ['org-a' => ['customer_enabled' => false]]);
    $controller = (new ReflectionClass(ConnectionController::class))->newInstanceWithoutConstructor();
    foreach (['settings' => orgResolutionSettings(), 'store' => $store] as $property => $value) {
        $reflection = new ReflectionProperty(ConnectionController::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($controller, $value);
    }

    expect(orgResolutionPrivate($controller, 'customersEnabled', ['org-a']))->toBeFalse()
        ->and(orgResolutionPrivate($controller, 'customersEnabled', ['org-b']))->toBeTrue();
});

test('the customer import reads the organization copy for its company', function () {
    $store = orgResolutionStore(['customer_enabled' => true, 'batch_size' => 80], ['org-a' => ['customer_enabled' => false, 'batch_size' => 7]]);
    $a     = new ImportCustomers('org-a');
    $b     = new ImportCustomers('org-b');

    expect(orgResolutionPrivate($a, 'customersDisabled', [orgResolutionSettings(), $store]))->toBeTrue()
        ->and(orgResolutionPrivate($b, 'customersDisabled', [orgResolutionSettings(), $store]))->toBeFalse()
        ->and(orgResolutionPrivate($a, 'pageSize', [orgResolutionSettings(), $store]))->toBe(7)
        ->and(orgResolutionPrivate($b, 'pageSize', [orgResolutionSettings(), $store]))->toBe(80);
});

test('quickbooks is primary for every record type when nothing chose otherwise', function () {
    $resolved = (new SyncSettingsResolver())->resolve([], [], []);
    $legacy   = (new SyncSettingsResolver())->resolve([], [], [
        'invoice_conflict'  => 'fleetbase',
        'invoice_reference' => 'fleetbase',
    ]);

    foreach (['customer', 'invoice', 'payment', 'wallet'] as $type) {
        expect($resolved[$type . '_conflict'])->toBe('quickbooks')
            ->and($resolved[$type . '_reference'])->toBe('quickbooks');
    }
    expect($legacy['invoice_conflict'])->toBe('fleetbase')
        ->and($legacy['invoice_reference'])->toBe('fleetbase');
});
