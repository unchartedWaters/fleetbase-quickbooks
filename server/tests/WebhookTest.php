<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Http\Controllers\WebhookController;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Support\WebhookSignature;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

function webhookSettings(): SettingsService
{
    return new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
}

/**
 * @return array<string, mixed>
 */
function webhookSyncSettings(array $overrides = []): array
{
    $settings = webhookSettings()->resolveSync(array_merge(['override' => true], $overrides), [], []);
    unset($settings['sources']);

    return $settings;
}

function webhookSignature(string $body, string $verifier): string
{
    return base64_encode(hash_hmac('sha256', $body, $verifier, true));
}

function webhookRequest(string $body, ?string $signature): Request
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT'  => 'application/json',
    ];
    if ($signature !== null) {
        $server['HTTP_INTUIT_SIGNATURE'] = $signature;
    }

    return Request::create('/quickbooks/int/v1/webhooks', 'POST', [], [], [], $server, $body);
}

/**
 * @param array<string, string|array<int, string>> $realms
 */
function webhookController(SettingsService $settings, MemorySettingsStore $store, array $realms = []): WebhookController
{
    return new class($settings, $store, new WebhookSignature(), $realms) extends WebhookController {
        /**
         * @param array<string, string|array<int, string>> $realms
         */
        public function __construct(
            SettingsService $settings,
            SettingsStore $store,
            WebhookSignature $signature,
            private array $realms,
        ) {
            parent::__construct($settings, $store, $signature);
        }

        /**
         * @return array<int, Connection>
         */
        protected function connectionsForRealm(string $realmId): array
        {
            $companies = $this->realms[$realmId] ?? [];
            if (is_string($companies) === true) {
                $companies = [$companies];
            }

            $connections = [];
            foreach ($companies as $companyUuid) {
                if ($companyUuid === '') {
                    continue;
                }
                $connection               = new Connection();
                $connection->company_uuid = $companyUuid;
                $connection->realm_id     = $realmId;
                $connections[]            = $connection;
            }

            return $connections;
        }

        /**
         * These controller tests exercise webhook parsing and dispatch. Link-query
         * behavior has its own database-backed tests in WebhookEventTest.
         *
         * @param array<int, string> $quickbooksIds
         *
         * @return array<string, string>
         */
        protected function localUuids(string $companyUuid, string $realmId, array $quickbooksIds): array
        {
            return [];
        }

        protected function linksOnRealm(string $realmId, array $quickbooksIds): array
        {
            return [];
        }
    };
}

function webhookAdminStore(SettingsService $settings, string $verifier): MemorySettingsStore
{
    $store = new MemorySettingsStore();
    if ($verifier !== '') {
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth([
            'webhook_verifier' => $verifier,
        ], []);
    }

    return $store;
}

/**
 * @template T
 *
 * @param callable(object): T $callback
 *
 * @return T
 */
/**
 * @param callable(): mixed $callback
 *
 * @return array<int, QuickBooksEntityChanged>
 */
function webhookSeenEvents(callable $callback): array
{
    $seen       = [];
    $container  = Container::getInstance();
    $previous   = $container->bound('events') === true ? $container->make('events') : null;
    $dispatcher = new Illuminate\Events\Dispatcher($container);
    $dispatcher->listen(QuickBooksEntityChanged::class, function (QuickBooksEntityChanged $event) use (&$seen): void {
        $seen[] = $event;
    });
    $container->instance('events', $dispatcher);
    try {
        $callback();
    } finally {
        if ($previous !== null) {
            $container->instance('events', $previous);
        }
    }

    return $seen;
}

function withWebhookDispatcher(callable $callback): mixed
{
    $dispatcher = new class implements Dispatcher {
        /** @var array<int, mixed> */
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
        return $callback($dispatcher);
    } finally {
        if ($previous !== null) {
            $container->instance(Dispatcher::class, $previous);
        } else {
            $container->forgetInstance(Dispatcher::class);
        }
    }
}

/**
 * @template T
 *
 * @param callable(): T $callback
 *
 * @return T
 */
function withoutWebhookVerifier(callable $callback): mixed
{
    if (class_exists(Cache::class) === true) {
        Cache::flush();
    }
    $previous  = getenv('QUICKBOOKS_WEBHOOK_VERIFIER');
    $hadEnv    = array_key_exists('QUICKBOOKS_WEBHOOK_VERIFIER', $_ENV);
    $hadServer = array_key_exists('QUICKBOOKS_WEBHOOK_VERIFIER', $_SERVER);
    $env       = $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'] ?? null;
    $server    = $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] ?? null;
    $config    = config('quickbooks.webhook_verifier');
    putenv('QUICKBOOKS_WEBHOOK_VERIFIER');
    unset($_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'], $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER']);
    config()->set('quickbooks.webhook_verifier', null);
    try {
        return $callback();
    } finally {
        if ($previous === false) {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER');
        } else {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER=' . $previous);
        }
        if ($hadEnv === true) {
            $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'] = $env;
        }
        if ($hadServer === true) {
            $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] = $server;
        }
        config()->set('quickbooks.webhook_verifier', $config);
    }
}

test('a missing or bad signature returns 401 and does not dispatch', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store      = webhookAdminStore($settings, 'verifier-token');
        $controller = webhookController($settings, $store);

        withWebhookDispatcher(function ($dispatcher) use ($controller, $settings, $body) {
            $missing      = $controller->handle(webhookRequest($body, null));
            $bad          = $controller->handle(webhookRequest($body, webhookSignature($body, 'other-token')));
            $unconfigured = webhookController($settings, new MemorySettingsStore())->handle(
                webhookRequest($body, webhookSignature($body, 'verifier-token'))
            );

            expect($missing->getStatusCode())->toBe(401)
                ->and($bad->getStatusCode())->toBe(401)
                ->and($unconfigured->getStatusCode())->toBe(401)
                ->and($dispatcher->jobs)->toBe([]);
        });
    });
});

test('a valid signature returns 200 and enqueues one job per known realm', function () {
    $settings = webhookSettings();
    $body     = <<<'JSON'
{ "eventNotifications": [
  { "realmId": "realm-1", "dataChangeEvent": { "entities": [
    { "name": "Customer", "id": 55, "operation": "Create" },
    { "name": "Vendor", "id": "9", "operation": "Create" },
    { "name": "Invoice", "id": "8", "operation": "Update" }
  ] } },
  { "realmId": "realm-unknown", "dataChangeEvent": { "entities": [
    { "name": "Payment", "id": "3", "operation": "Create" }
  ] } },
  { "realmId": "realm-1", "dataChangeEvent": { "entities": [
    { "name": "Account", "id": "7", "operation": "Merge" }
  ] } },
  { "realmId": "realm-2", "dataChangeEvent": { "entities": [
    { "name": "Payment", "id": "4", "operation": "Void" },
    { "name": "Item", "id": "2", "operation": "Update" }
  ] } }
] }
JSON;

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store = webhookAdminStore($settings, 'verifier-token');
        expect($store->rows[SettingsKeys::adminAuth()]['webhook_verifier'])->not->toBe('verifier-token');
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-token',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-token',
        ], []);
        $controller = webhookController($settings, $store, [
            'realm-1' => 'company-a',
            'realm-2' => 'company-b',
        ]);

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-token')));
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($events)->toHaveCount(4)
            ->and($events[0])->toBeInstanceOf(QuickBooksEntityChanged::class)
            ->and($events[0]->companyUuid)->toBe('company-a')
            ->and($events[0]->realmId)->toBe('realm-1')
            ->and($events[0]->entityType)->toBe('customer')
            ->and($events[0]->quickbooksId)->toBe('55')
            ->and($events[0]->operation)->toBe('create')
            ->and($events[1]->entityType)->toBe('invoice')
            ->and($events[1]->quickbooksId)->toBe('8')
            ->and($events[1]->operation)->toBe('update')
            ->and($events[2]->entityType)->toBe('wallet')
            ->and($events[2]->quickbooksId)->toBe('7')
            ->and($events[2]->operation)->toBe('update')
            ->and($events[3]->companyUuid)->toBe('company-b')
            ->and($events[3]->realmId)->toBe('realm-2')
            ->and($events[3]->entityType)->toBe('payment')
            ->and($events[3]->quickbooksId)->toBe('4')
            ->and($events[3]->operation)->toBe('delete');
    });
});

test('one delivery for a shared realm dispatches once per company', function () {
    $settings = webhookSettings();
    $body     = <<<'JSON'
{ "eventNotifications": [
  { "realmId": "realm-shared", "dataChangeEvent": { "entities": [
    { "name": "Customer", "id": "55", "operation": "Create" }
  ] } },
  { "realmId": "realm-unknown", "dataChangeEvent": { "entities": [
    { "name": "Customer", "id": "55", "operation": "Create" }
  ] } }
] }
JSON;

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store                                               = webhookAdminStore($settings, 'verifier-token');
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-token',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-token',
        ], []);
        $controller = webhookController($settings, $store, [
            'realm-shared' => ['company-a', 'company-b'],
        ]);

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-token')));
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($events)->toHaveCount(2)
            ->and($events[0]->companyUuid)->toBe('company-a')
            ->and($events[1]->companyUuid)->toBe('company-b')
            ->and($events[0]->realmId)->toBe('realm-shared')
            ->and($events[1]->realmId)->toBe('realm-shared')
            ->and($events[0]->entityType)->toBe('customer')
            ->and($events[1]->entityType)->toBe('customer')
            ->and($events[0]->quickbooksId)->toBe('55')
            ->and($events[1]->quickbooksId)->toBe('55')
            ->and($events[0]->operation)->toBe('create')
            ->and($events[1]->operation)->toBe('create');
    });
});

test('hash_equals rejects a tampered body and a different-length signature', function () {
    $signatures = new WebhookSignature();
    $body       = '{"eventNotifications":[]}';
    $verifier   = 'verifier-token';
    $signed     = webhookSignature($body, $verifier);
    $flipped    = substr($signed, 0, -1) . ($signed[-1] === 'A' ? 'B' : 'A');

    expect($signatures->accepts($body, $signed, [$verifier]))->toBeTrue()
        ->and($signatures->accepts($body . ' ', $signed, [$verifier]))->toBeFalse()
        ->and($signatures->accepts($body, $flipped, [$verifier]))->toBeFalse()
        ->and($signatures->accepts($body, 'abc', [$verifier]))->toBeFalse()
        ->and($signatures->accepts($body, $signed, ['other-token', $verifier]))->toBeTrue();

    $settings = webhookSettings();
    withoutWebhookVerifier(function () use ($settings, $body, $signed) {
        $controller = webhookController($settings, webhookAdminStore($settings, 'verifier-token'));
        withWebhookDispatcher(function ($dispatcher) use ($controller, $body, $signed) {
            $tampered = $controller->handle(webhookRequest($body . ' ', $signed));

            expect($tampered->getStatusCode())->toBe(401)
                ->and($dispatcher->jobs)->toBe([]);
        });
    });
});

test('a realm with no connection is not applied, a valid signature is acknowledged and the signature is still required', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-missing","dataChangeEvent":{"entities":[{"name":"Customer","id":"9","operation":"Update"}]}}]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $controller = webhookController($settings, webhookAdminStore($settings, 'verifier-token'), []);
        withWebhookDispatcher(function ($dispatcher) use ($controller, $body) {
            $unsigned = $controller->handle(webhookRequest($body, null));
            $signed   = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-token')));

            $forged = $controller->handle(webhookRequest($body, webhookSignature($body, 'other-token')));

            // A genuine signature with no connection to apply it to is acknowledged
            // so Intuit stops retrying. A bad or missing signature is still refused.
            expect($unsigned->getStatusCode())->toBe(401)
                ->and($forged->getStatusCode())->toBe(401)
                ->and($signed->getStatusCode())->toBe(200)
                ->and($signed->getData(true))->toBe(['ok' => true])
                ->and($dispatcher->jobs)->toBe([]);
        });
    });
});

test('an organization webhook verifier is rejected when the global verifier is not stored', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store                                               = new MemorySettingsStore();
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'webhook_verifier' => 'company-verifier',
        ], []);
        $controller = webhookController($settings, $store, ['realm-1' => 'company-a']);

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'company-verifier')));
        });

        expect($response->getStatusCode())->toBe(401)
            ->and($events)->toBe([]);
    });
});

test('the global verifier is accepted and another organization verifier is rejected', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store                                               = webhookAdminStore($settings, 'verifier-a');
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'client_secret'    => 'secret-a',
            'webhook_verifier' => 'verifier-a',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'client_secret'    => 'secret-b',
            'webhook_verifier' => 'verifier-b',
        ], []);
        $controller = webhookController($settings, $store, ['realm-1' => 'company-a']);

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-a')));
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($events)->toHaveCount(1)
            ->and($events[0]->companyUuid)->toBe('company-a')
            ->and($events[0]->realmId)->toBe('realm-1')
            ->and($events[0]->quickbooksId)->toBe('1');

        withWebhookDispatcher(function ($dispatcher) use ($controller, $body) {
            $rejected       = null;
            $rejectedEvents = webhookSeenEvents(function () use ($controller, $body, &$rejected) {
                $rejected = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-b')));
            });

            expect($rejected->getStatusCode())->toBe(401)
                ->and($rejectedEvents)->toBe([])
                ->and($dispatcher->jobs)->toBe([]);
        });
    });
});

test('a shared realm delivers to every connection when the global verifier matches', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-shared","dataChangeEvent":{"entities":[{"name":"Customer","id":"55","operation":"Create"}]}}]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $store                                               = webhookAdminStore($settings, 'verifier-a');
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-a',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-b',
        ], []);
        $controller = webhookController($settings, $store, [
            'realm-shared' => ['company-a', 'company-b'],
        ]);

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-a')));
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($events)->toHaveCount(2)
            ->and($events[0]->companyUuid)->toBe('company-a')
            ->and($events[1]->companyUuid)->toBe('company-b')
            ->and($events[0]->realmId)->toBe('realm-shared')
            ->and($events[1]->realmId)->toBe('realm-shared')
            ->and($events[0]->entityType)->toBe('customer')
            ->and($events[1]->entityType)->toBe('customer')
            ->and($events[0]->quickbooksId)->toBe('55')
            ->and($events[1]->quickbooksId)->toBe('55')
            ->and($events[0]->operation)->toBe('create')
            ->and($events[1]->operation)->toBe('create');
    });
});

test('the global verifier authorizes every connection and an organization or env verifier does not', function () {
    $previous = getenv('QUICKBOOKS_WEBHOOK_VERIFIER');
    $config   = config('quickbooks.webhook_verifier');
    putenv('QUICKBOOKS_WEBHOOK_VERIFIER=env-verifier');
    $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER']    = 'env-verifier';
    $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] = 'env-verifier';
    config()->set('quickbooks.webhook_verifier', null);

    try {
        $settings = webhookSettings();
        $body     = <<<'JSON'
{ "eventNotifications": [
  { "realmId": "realm-1", "dataChangeEvent": { "entities": [
    { "name": "Customer", "id": "1", "operation": "Create" }
  ] } },
  { "realmId": "realm-2", "dataChangeEvent": { "entities": [
    { "name": "Invoice", "id": "2", "operation": "Update" }
  ] } }
] }
JSON;
        $store                                               = webhookAdminStore($settings, 'admin-verifier');
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'webhook_verifier' => 'verifier-a',
        ], []);
        $controller = webhookController($settings, $store, [
            'realm-1' => 'company-a',
            'realm-2' => 'company-b',
        ]);

        $adminResponse = null;
        $adminEvents   = webhookSeenEvents(function () use ($controller, $body, &$adminResponse) {
            $adminResponse = $controller->handle(webhookRequest($body, webhookSignature($body, 'admin-verifier')));
        });
        $envResponse = null;
        $envEvents   = webhookSeenEvents(function () use ($controller, $body, &$envResponse) {
            $envResponse = $controller->handle(webhookRequest($body, webhookSignature($body, 'env-verifier')));
        });
        $ownResponse = null;
        $ownEvents   = webhookSeenEvents(function () use ($controller, $body, &$ownResponse) {
            $ownResponse = $controller->handle(webhookRequest($body, webhookSignature($body, 'verifier-a')));
        });

        expect($adminResponse->getStatusCode())->toBe(200)
            ->and($adminEvents)->toHaveCount(2)
            ->and($adminEvents[0]->companyUuid)->toBe('company-a')
            ->and($adminEvents[0]->realmId)->toBe('realm-1')
            ->and($adminEvents[1]->companyUuid)->toBe('company-b')
            ->and($adminEvents[1]->realmId)->toBe('realm-2')
            ->and($envResponse->getStatusCode())->toBe(401)
            ->and($envEvents)->toBe([])
            ->and($ownResponse->getStatusCode())->toBe(401)
            ->and($ownEvents)->toBe([]);
    } finally {
        if ($previous === false) {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER');
            unset($_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'], $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER']);
        } else {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER=' . $previous);
            $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER']    = $previous;
            $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] = $previous;
        }
        config()->set('quickbooks.webhook_verifier', $config);
    }
});

test('a failed signature check does not decrypt every company client secret', function () {
    $cipher = new class extends SecretCipher {
        /** @var array<int, string> */
        public array $decrypted = [];

        public function decrypt(string $payload): string
        {
            $this->decrypted[] = $payload;

            return parent::decrypt($payload);
        }
    };
    $settings = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), $cipher);
    $body     = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

    withoutWebhookVerifier(function () use ($cipher, $settings, $body) {
        $store                                               = new MemorySettingsStore();
        $store->rows[SettingsKeys::companyAuth('company-a')] = $settings->storeAuth([
            'client_secret'    => 'secret-a',
            'webhook_verifier' => 'verifier-a',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-b')] = $settings->storeAuth([
            'client_secret'    => 'secret-b',
            'webhook_verifier' => 'verifier-b',
        ], []);
        $store->rows[SettingsKeys::companyAuth('company-c')] = $settings->storeAuth([
            'client_secret'    => 'secret-c',
            'webhook_verifier' => 'verifier-c',
        ], []);
        $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth([
            'client_secret'    => 'admin-secret',
            'webhook_verifier' => 'admin-verifier',
        ], []);
        $store->asked = [];
        $controller   = webhookController($settings, $store, [
            'realm-1' => ['company-a', 'company-b'],
        ]);

        withWebhookDispatcher(function ($dispatcher) use ($cipher, $controller, $store, $body) {
            $response = null;
            $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
                $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'not-a-verifier')));
            });
            $auth = [
                'a'     => $store->rows[SettingsKeys::companyAuth('company-a')],
                'b'     => $store->rows[SettingsKeys::companyAuth('company-b')],
                'c'     => $store->rows[SettingsKeys::companyAuth('company-c')],
                'admin' => $store->rows[SettingsKeys::adminAuth()],
            ];

            expect($response->getStatusCode())->toBe(401)
                ->and($events)->toBe([])
                ->and($dispatcher->jobs)->toBe([])
                ->and($cipher->decrypted)->toContain($auth['admin']['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($auth['a']['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($auth['b']['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($auth['a']['client_secret'])
                ->and($cipher->decrypted)->not->toContain($auth['b']['client_secret'])
                ->and($cipher->decrypted)->not->toContain($auth['c']['client_secret'])
                ->and($cipher->decrypted)->not->toContain($auth['c']['webhook_verifier'])
                ->and($cipher->decrypted)->not->toContain($auth['admin']['client_secret'])
                ->and($store->asked)->toContain(SettingsKeys::adminAuth())
                ->and($store->asked)->not->toContain(SettingsKeys::companyAuth('company-a'))
                ->and($store->asked)->not->toContain(SettingsKeys::companyAuth('company-b'))
                ->and($store->asked)->not->toContain(SettingsKeys::companyAuth('company-c'));
        });
    });
});

test('the env verifier does not authorize a connection that has no verifier of its own', function () {
    $previous = getenv('QUICKBOOKS_WEBHOOK_VERIFIER');
    $config   = config('quickbooks.webhook_verifier');
    putenv('QUICKBOOKS_WEBHOOK_VERIFIER=env-verifier');
    $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER']    = 'env-verifier';
    $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] = 'env-verifier';
    config()->set('quickbooks.webhook_verifier', null);

    try {
        $settings   = webhookSettings();
        $store      = webhookAdminStore($settings, 'admin-verifier');
        $controller = webhookController($settings, $store, ['realm-1' => 'company-a']);
        $body       = '{"eventNotifications":[{"realmId":"realm-1","dataChangeEvent":{"entities":[{"name":"Customer","id":"1","operation":"Create"}]}}]}';

        $response = null;
        $events   = webhookSeenEvents(function () use ($controller, $body, &$response) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'env-verifier')));
        });

        expect($response->getStatusCode())->toBe(401)
            ->and($events)->toBe([]);
    } finally {
        if ($previous === false) {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER');
            unset($_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'], $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER']);
        } else {
            putenv('QUICKBOOKS_WEBHOOK_VERIFIER=' . $previous);
            $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER']    = $previous;
            $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] = $previous;
        }
        config()->set('quickbooks.webhook_verifier', $config);
    }
});

test('a verifier carried in the body is not used to check the signature', function () {
    $settings = webhookSettings();
    $body     = '{"eventNotifications":[{"realmId":"realm-1","verifier":"from-the-body","dataChangeEvent":{"entities":[]}}]}';

    withoutWebhookVerifier(function () use ($settings, $body) {
        $controller = webhookController($settings, webhookAdminStore($settings, 'verifier-token'));
        withWebhookDispatcher(function ($dispatcher) use ($controller, $body) {
            $response = $controller->handle(webhookRequest($body, webhookSignature($body, 'from-the-body')));

            expect($response->getStatusCode())->toBe(401)
                ->and($dispatcher->jobs)->toBe([]);
        });
    });
});

test('a blank webhook verifier is not returned and storeAuth encrypts a new token and keeps the old one', function () {
    $cipher   = new SecretCipher();
    $settings = webhookSettings();
    $blank    = $settings->forBrowser(['client_id' => 'id', 'webhook_verifier' => '']);

    expect($blank)->not->toHaveKey('webhook_verifier')
        ->and($blank['webhook_verifier_set'])->toBeFalse();

    $stored  = $settings->storeAuth([
        'client_id'        => 'id',
        'client_secret'    => 'plain-secret',
        'webhook_verifier' => 'new-token',
    ], []);
    $browser = $settings->forBrowser($stored);

    expect($stored['webhook_verifier'])->not->toBe('new-token')
        ->and($cipher->decrypt($stored['webhook_verifier']))->toBe('new-token')
        ->and($browser)->not->toHaveKey('webhook_verifier')
        ->and($browser)->not->toHaveKey('client_secret')
        ->and($browser['webhook_verifier_set'])->toBeTrue()
        ->and(json_encode($browser))->not->toContain('new-token');

    $kept    = $settings->storeAuth(['webhook_verifier' => ''], $stored);
    $omitted = $settings->storeAuth(['client_id' => 'id'], $stored);

    expect($cipher->decrypt($kept['webhook_verifier']))->toBe('new-token')
        ->and($cipher->decrypt($kept['client_secret']))->toBe('plain-secret')
        ->and($cipher->decrypt($omitted['webhook_verifier']))->toBe('new-token');

    $resolved = $settings->resolveAuth([], ['webhook_verifier' => $stored['webhook_verifier']], []);
    $shown    = $settings->forBrowser($resolved);

    expect($resolved['webhook_verifier'])->toBe('new-token')
        ->and($shown)->not->toHaveKey('webhook_verifier')
        ->and($shown['webhook_verifier_set'])->toBeTrue();

    $plain = $settings->resolveAuth(['webhook_verifier' => 'company-verifier'], ['webhook_verifier' => 'admin-verifier'], []);
    expect($plain['webhook_verifier'])->toBe('')
        ->and($settings->forBrowser($plain))->not->toHaveKey('webhook_verifier')
        ->and($settings->resolveAuth(['webhook_verifier' => 'company-verifier'], [], [])['webhook_verifier'])->toBe('');

    $appKey   = (string) config('app.key');
    $key      = substr(hash('sha256', base64_decode(substr($appKey, 7), true), true), 0, 32);
    $iv       = random_bytes(16);
    $legacy   = base64_encode($iv . openssl_encrypt('old-verifier', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
    $upgraded = $settings->storeAuth(['webhook_verifier' => ''], ['webhook_verifier' => $legacy]);

    expect($cipher->decrypt($upgraded['webhook_verifier']))->toBe('old-verifier')
        ->and($cipher->isLegacyCiphertext($upgraded['webhook_verifier']))->toBeFalse()
        ->and($settings->storeAuth([], []))->not->toHaveKey('webhook_verifier');
});

test('normalizeAuth keeps a client secret result unchanged when webhook_verifier is absent', function () {
    $store = new MemorySettingsStore();

    expect($store->normalizeAuth([
        'client_id'        => 'id',
        'client_secret'    => 'secret',
        'redirect_uri'     => 'https://example.test/callback',
        'environment'      => 'sandbox',
    ]))->toBe([
        'client_id'     => 'id',
        'client_secret' => 'secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ])
        ->and($store->normalizeAuth([
            'client_secret'    => 'kept-secret',
            'webhook_verifier' => '   ',
            'interval_minutes' => '5',
        ]))->toBe(['client_secret' => 'kept-secret'])
        ->and($store->normalizeAuth([
            'webhook_verifier' => 'token',
        ]))->toBe(['webhook_verifier' => 'token']);
});

test('saving a blank webhook verifier keeps the stored ciphertext and does not persist the set flag', function () {
    $cipher                                                 = new SecretCipher();
    $settings                                               = webhookSettings();
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()]                 = $settings->storeAuth([
        'client_id'        => 'client-id',
        'client_secret'    => 'plain-secret',
        'redirect_uri'     => 'https://example.com/callback',
        'environment'      => 'sandbox',
        'webhook_verifier' => 'verifier-token',
    ], []);
    $controller = new SettingController(
        new Authorizer(static fn () => true),
        $settings,
        $store
    );

    session(['company' => 'company-uuid']);
    try {
        $response = $controller->save(Request::create('/settings', 'POST', [
            'scope' => 'admin',
            'auth'  => [
                'client_id'            => 'client-id',
                'client_secret'        => '',
                'redirect_uri'         => 'https://example.com/callback',
                'environment'          => 'sandbox',
                'webhook_verifier'     => '',
                'webhook_verifier_set' => true,
            ],
            'sync' => webhookSyncSettings(),
        ]));
        $stored = $store->rows[SettingsKeys::adminAuth()];

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->not->toContain('verifier-token')
            ->and($response->getContent())->not->toContain('plain-secret')
            ->and($stored)->not->toHaveKey('webhook_verifier_set')
            ->and($cipher->decrypt($stored['webhook_verifier']))->toBe('verifier-token')
            ->and($cipher->decrypt($stored['client_secret']))->toBe('plain-secret');
    } finally {
        session(['company' => null]);
    }
});

test('the remote change job passes the whole entity list to the engine once', function () {
    [$engine, $client]                                      = webhookEngine();
    $directory                                              = webhookDirectory();
    $settings                                               = webhookSettings();
    $store                                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()]                 = [
        'override'           => true,
        'batch_size'         => 7,
        'customer_direction' => 'off',
    ];
    $previous = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));

    try {
        $ignored = new ApplyRemoteChange('company-uuid', [
            ['entity' => 'Vendor', 'id' => '1', 'operation' => 'Create'],
        ]);
        $ignored->handle($engine, $directory, $settings, $store);

        $job = new ApplyRemoteChange('company-uuid', [
            ['entity' => 'Customer', 'id' => '1', 'operation' => 'Create'],
            ['entity' => 'Vendor', 'id' => '2', 'operation' => 'Create'],
            ['entity' => 'Invoice', 'id' => '3', 'operation' => 'Update'],
            ['entity' => 'Payment', 'id' => '4', 'operation' => 'Create'],
            ['entity' => 'Account', 'id' => 5, 'operation' => 'Delete'],
        ]);
        $job->handle($engine, $directory, $settings, $store);
        $again = BatchRunner::lock('company-uuid');

        expect($ignored->tries)->toBe(1)
            ->and($engine->calls)->toHaveCount(1)
            ->and($engine->calls[0]['companyUuid'])->toBe('company-uuid')
            ->and($engine->calls[0]['entities'])->toBe([
                ['entity' => 'Customer', 'id' => '1', 'operation' => 'Create'],
                ['entity' => 'Invoice', 'id' => '3', 'operation' => 'Update'],
                ['entity' => 'Payment', 'id' => '4', 'operation' => 'Create'],
                ['entity' => 'Account', 'id' => '5', 'operation' => 'Delete'],
            ])
            ->and($engine->calls[0]['settings']['batch_size'])->toBe(7)
            ->and($engine->calls[0]['settings']['customer_direction'])->toBe('both')
            ->and($directory->loadedWith)->toBe([
                ['entity' => 'Customer', 'id' => '1', 'operation' => 'Create'],
                ['entity' => 'Invoice', 'id' => '3', 'operation' => 'Update'],
                ['entity' => 'Payment', 'id' => '4', 'operation' => 'Create'],
                ['entity' => 'Account', 'id' => '5', 'operation' => 'Delete'],
            ])
            ->and($directory->saved)->toBe($engine->calls[0]['ledger'])
            ->and($client->calls)->toBe([])
            ->and($again)->not->toBeNull()
            ->and($again->get())->toBeTrue();
        $again->release();
    } finally {
        Cache::swap($previous);
    }
});

test('a busy lock is retried and an unavailable lock does not call the engine', function () {
    [$engine]  = webhookEngine();
    $directory = webhookDirectory();
    $settings  = webhookSettings();
    $store     = new MemorySettingsStore();
    $job       = new ApplyRemoteChange('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Create'],
    ]);
    $previous   = Cache::getFacadeRoot();
    $repository = new Repository(new ArrayStore());
    Cache::swap($repository);

    withWebhookDispatcher(function ($dispatcher) use ($engine, $directory, $settings, $store, $job, $repository) {
        $held = $repository->getStore()->lock('quickbooks.batch.company-uuid', BatchRunner::LOCK_SECONDS);
        expect($held->get())->toBeTrue();
        $job->handle($engine, $directory, $settings, $store);

        $exhausted = new ApplyRemoteChange('company-uuid', $job->entities, ApplyRemoteChange::LOCK_RETRY_LIMIT);
        $exhausted->handle($engine, $directory, $settings, $store);
        $held->release();

        Cache::swap(new class {
        });
        $job->handle($engine, $directory, $settings, $store);

        expect($engine->calls)->toBe([])
            ->and($directory->skipped)->toHaveCount(3)
            ->and($directory->skipped[0]['message'])->toBe('Another QuickBooks sync is already running.')
            ->and($directory->skipped[1]['message'])->toBe('Another QuickBooks sync is already running.')
            ->and($directory->skipped[2]['message'])->toBe(BatchRunner::LOCK_UNAVAILABLE)
            ->and($directory->saved)->toBeNull()
            ->and($dispatcher->jobs)->toHaveCount(1)
            ->and($dispatcher->jobs[0])->toBeInstanceOf(ApplyRemoteChange::class)
            ->and($dispatcher->jobs[0]->lockAttempt)->toBe(1)
            ->and($dispatcher->jobs[0]->delay)->toBe(ApplyRemoteChange::LOCK_RETRY_DELAY_SECONDS)
            ->and($dispatcher->jobs[0]->entities)->toBe($job->entities);
    });

    Cache::swap($previous);
});

test('quickbooks http in a remote change releases the company lock', function () {
    $client = new class extends FakeQuickBooks {
        public ?bool $heldDuringRead = null;

        public function getCustomer(array $connection, string $id): ?array
        {
            $this->heldDuringRead = BatchRunner::holds('company-uuid');

            return parent::getCustomer($connection, $id);
        }

        public function batch(array $connection, array $items): array
        {
            $this->heldDuringRead = BatchRunner::holds('company-uuid');

            return parent::batch($connection, $items);
        }
    };
    $engine = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );
    $directory = new class extends FleetbaseDirectory {
        public ?bool $heldDuringLoad = null;

        public ?bool $heldDuringSave = null;

        public function connection(string $companyUuid): ?array
        {
            return [
                'company_uuid' => $companyUuid,
                'realm_id'     => 'realm-1',
                'needs_reauth' => false,
            ];
        }

        public function loadLinked(string $companyUuid, array $entities): ?array
        {
            $this->heldDuringLoad                  = BatchRunner::holds($companyUuid);
            $ledger                                = new SyncLedger();
            $ledger->connections[$companyUuid]     = [
                'company_uuid'  => $companyUuid,
                'realm_id'      => 'realm-1',
                'needs_reauth'  => false,
                'home_currency' => 'USD',
            ];
            $ledger->customers['cust-1'] = [
                'uuid'         => 'cust-1',
                'company_uuid' => $companyUuid,
                'name'         => 'Local Ada',
                'email'        => 'ada@example.test',
            ];
            $ledger->links[] = [
                'company_uuid' => $companyUuid,
                'realm_id'     => 'realm-1',
                'local_type'   => 'customer',
                'local_uuid'   => 'cust-1',
                'qbo_entity'   => 'Customer',
                'qbo_id'       => 'qbo-1',
                'sync_token'   => '0',
            ];

            return ['ledger' => $ledger, 'connection' => $ledger->connections[$companyUuid]];
        }

        public function save(SyncLedger $ledger): void
        {
            $this->heldDuringSave = BatchRunner::holds('company-uuid');
        }
    };
    $settings                               = webhookSettings();
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = [
        'customer_conflict'  => 'quickbooks',
        'customer_direction' => 'both',
        'payment_conflict'   => 'quickbooks',
        'payment_direction'  => 'both',
    ];
    $previous                               = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));

    try {
        $client->customers['qbo-1'] = [
            'Id'               => 'qbo-1',
            'SyncToken'        => '1',
            'DisplayName'      => 'Remote Ada',
            'PrimaryEmailAddr' => ['Address' => 'ada@example.test'],
        ];
        $job = new ApplyRemoteChange('company-uuid', [
            ['entity' => 'Customer', 'id' => 'qbo-1', 'operation' => 'Update'],
            ['entity' => 'Payment', 'id' => 'pay-1', 'operation' => 'Update'],
        ]);
        $job->handle($engine, $directory, $settings, $store);
        $again = BatchRunner::lock('company-uuid');

        expect($directory->heldDuringLoad)->toBeTrue()
            ->and($client->heldDuringRead)->toBeFalse()
            ->and($directory->heldDuringSave)->toBeTrue()
            ->and(BatchRunner::holds('company-uuid'))->toBeFalse()
            ->and($again)->not->toBeNull()
            ->and($again->get())->toBeTrue();
        $again->release();
    } finally {
        Cache::swap($previous);
    }
});

/**
 * @return array{0: SyncEngine, 1: FakeQuickBooks}
 */
function webhookEngine(): array
{
    $client = new FakeQuickBooks();
    $engine = new class($client, new CustomerMapper(), new InvoiceMapper(), new WalletMapper(), new BackoffPolicy()) extends SyncEngine {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        /**
         * @param array<int, array<string, string>> $entities
         * @param array<string, mixed>              $settings
         */
        public function acceptRemoteChanges(SyncLedger $ledger, string $companyUuid, array $entities, array $settings, int $now): void
        {
            $this->calls[] = [
                'companyUuid' => $companyUuid,
                'entities'    => $entities,
                'settings'    => $settings,
                'now'         => $now,
                'ledger'      => $ledger,
            ];
        }
    };

    return [$engine, $client];
}

function webhookDirectory(): FleetbaseDirectory
{
    return new class extends FleetbaseDirectory {
        public ?SyncLedger $saved = null;

        /** @var array<int, array<string, string>> */
        public array $skipped = [];

        /** @var array<int, array<string, mixed>> */
        public array $loadedWith = [];

        public function connection(string $companyUuid): ?array
        {
            return [
                'company_uuid' => $companyUuid,
                'realm_id'     => 'realm-1',
                'needs_reauth' => false,
            ];
        }

        public function load(string $companyUuid): ?array
        {
            $ledger                            = new SyncLedger();
            $ledger->connections[$companyUuid] = [
                'company_uuid' => $companyUuid,
                'realm_id'     => 'realm-1',
            ];

            return [
                'ledger'     => $ledger,
                'connection' => $ledger->connections[$companyUuid],
                'customers'  => [],
            ];
        }

        public function loadLinked(string $companyUuid, array $entities): ?array
        {
            $this->loadedWith = $entities;

            return $this->load($companyUuid);
        }

        public function save(SyncLedger $ledger): void
        {
            $this->saved = $ledger;
        }

        public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
        {
            $this->skipped[] = [
                'company_uuid' => $companyUuid,
                'trigger'      => $trigger,
                'direction'    => $direction,
                'message'      => $message,
            ];
        }
    };
}
