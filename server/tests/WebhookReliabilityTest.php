<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Http\Controllers\WebhookController;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WebhookSignature;
use Fleetbase\Quickbooks\Tests\Support\CacheTtlRecorder;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

function whrSettings(): SettingsService
{
    return new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
}

function whrSign(string $body, string $verifier): string
{
    return base64_encode(hash_hmac('sha256', $body, $verifier, true));
}

function whrRequest(string $body, ?string $signature): Request
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
 * @param array<string, string> $realms realm id => company uuid
 */
function whrController(SettingsService $settings, MemorySettingsStore $store, array $realms): WebhookController
{
    return new class($settings, $store, new WebhookSignature(), $realms) extends WebhookController {
        /**
         * @param array<string, string> $realms
         */
        public function __construct(
            SettingsService $settings,
            SettingsStore $store,
            WebhookSignature $signature,
            private array $realms,
        ) {
            parent::__construct($settings, $store, $signature);
        }

        protected function connectionsForRealm(string $realmId): array
        {
            $company = $this->realms[$realmId] ?? '';
            if ($company === '') {
                return [];
            }
            $connection               = new Connection();
            $connection->company_uuid = $company;
            $connection->realm_id     = $realmId;

            return [$connection];
        }

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

function whrStore(): MemorySettingsStore
{
    $settings                               = whrSettings();
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminAuth()] = $settings->storeAuth(['webhook_verifier' => 'verifier-token'], []);

    return $store;
}

/**
 * Runs the callback with a listener on the entity event and no env or config verifier.
 *
 * @param callable(array<int, QuickBooksEntityChanged>): mixed $callback
 */
function whrWithEvents(callable $callback, ?Closure $listener = null): mixed
{
    Cache::flush();
    $config = config('quickbooks.webhook_verifier');
    config()->set('quickbooks.webhook_verifier', null);
    $previous = app('events');
    $seen     = [];
    $bus      = new Illuminate\Events\Dispatcher(app());
    $bus->listen(QuickBooksEntityChanged::class, function (QuickBooksEntityChanged $event) use (&$seen, &$listener): void {
        if ($listener !== null) {
            $listener($event);
        }
        $seen[] = $event;
    });
    app()->instance('events', $bus);
    $swapped = function (?Closure $next) use (&$listener): void {
        $listener = $next;
    };

    try {
        return $callback($seen, $swapped);
    } finally {
        app()->instance('events', $previous);
        config()->set('quickbooks.webhook_verifier', $config);
        Cache::flush();
    }
}

function whrStamp(int $secondsAgo): string
{
    return (new DateTimeImmutable('@' . (time() - $secondsAgo)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sO');
}

function whrBody(string $lastUpdated = '', string $operation = 'Create'): string
{
    $extra = $lastUpdated === '' ? '' : ',"lastUpdated":"' . $lastUpdated . '"';

    return '{"eventNotifications":[{"realmId":"realm-whr","dataChangeEvent":{"entities":[{"name":"Customer","id":"77",'
        . '"operation":"' . $operation . '"' . $extra . '}]}}]}';
}

test('a delivery that fails while processing is not remembered as a replay', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen, Closure $setListener) use ($controller, $body) {
        $setListener(function (): void {
            throw new RuntimeException('queue is down');
        });

        $thrown = null;
        try {
            $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }
        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown->getMessage())->toBe('queue is down');

        // Intuit retries the identical body once the fault is gone.
        $setListener(null);
        $retry = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($retry->getStatusCode())->toBe(200)
            ->and($seen)->toHaveCount(1);
    });
});

test('a processed delivery is acknowledged and not dispatched again when the same body arrives', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $first  = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
        $replay = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($first->getStatusCode())->toBe(200)
            ->and($replay->getStatusCode())->toBe(200)
            ->and($seen)->toHaveCount(1);
    });
});

test('a validly signed delivery for a realm with no connection returns 200 and dispatches nothing', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), []);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $response = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe(['ok' => true])
            ->and($seen)->toBe([]);
    });
});

test('an invalid signature is still 401 whether or not the realm has a connection', function () {
    $body = whrBody();

    whrWithEvents(function (array &$seen) use ($body) {
        $connected = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
        $missing   = whrController(whrSettings(), whrStore(), []);

        $badConnected = $connected->handle(whrRequest($body, whrSign($body, 'other-token')));
        $badMissing   = $missing->handle(whrRequest($body, whrSign($body, 'other-token')));
        $unsigned     = $missing->handle(whrRequest($body, null));

        expect($badConnected->getStatusCode())->toBe(401)
            ->and($badMissing->getStatusCode())->toBe(401)
            ->and($unsigned->getStatusCode())->toBe(401)
            ->and($seen)->toBe([]);
    });
});

test('a delete older than ten minutes is acknowledged but not applied, one inside the window is', function () {
    $stale = whrBody(whrStamp(700), 'Delete');
    $fresh = whrBody(whrStamp(500), 'Delete');

    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.max_age_seconds');
    config()->set('quickbooks.webhook.max_age_seconds', null);

    try {
        whrWithEvents(function (array &$seen) use ($controller, $stale, $fresh) {
            $old     = $controller->handle(whrRequest($stale, whrSign($stale, 'verifier-token')));
            $current = $controller->handle(whrRequest($fresh, whrSign($fresh, 'verifier-token')));

            // The stale delete is passed on only to be noted in Activity.
            expect($old->getStatusCode())->toBe(200)
                ->and($current->getStatusCode())->toBe(200)
                ->and(array_map(fn (QuickBooksEntityChanged $event) => $event->operation, $seen))
                ->toBe(['stale-delete', 'delete']);
        });
    } finally {
        config()->set('quickbooks.webhook.max_age_seconds', $previous);
    }
});

test('the maximum delivery age is configurable and drives the replay cache lifetime', function () {
    $oldDelivery = whrBody(whrStamp(3000));
    $controller  = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous    = config('quickbooks.webhook.max_age_seconds');

    try {
        config()->set('quickbooks.webhook.max_age_seconds', 3600);
        whrWithEvents(function (array &$seen) use ($controller, $oldDelivery) {
            $accepted = $controller->handle(whrRequest($oldDelivery, whrSign($oldDelivery, 'verifier-token')));
            $replay   = $controller->handle(whrRequest($oldDelivery, whrSign($oldDelivery, 'verifier-token')));

            expect($accepted->getStatusCode())->toBe(200)
                ->and($replay->getStatusCode())->toBe(200)
                ->and($seen)->toHaveCount(1);
        });

        config()->set('quickbooks.webhook.max_age_seconds', 120);
        whrWithEvents(function (array &$seen) use ($controller) {
            $tooOld = whrBody(whrStamp(300), 'Delete');
            $inside = whrBody(whrStamp(60), 'Delete');

            $rejected = $controller->handle(whrRequest($tooOld, whrSign($tooOld, 'verifier-token')));
            $accepted = $controller->handle(whrRequest($inside, whrSign($inside, 'verifier-token')));

            expect($rejected->getStatusCode())->toBe(200)
                ->and($accepted->getStatusCode())->toBe(200)
                ->and(array_map(fn (QuickBooksEntityChanged $event) => $event->operation, $seen))
                ->toBe(['stale-delete', 'delete']);
        });
    } finally {
        config()->set('quickbooks.webhook.max_age_seconds', $previous);
    }
});

test('the replay key is held briefly while processing and for the stale replay window once processed', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.max_age_seconds');
    $window     = config('quickbooks.webhook.stale_replay_seconds');
    $ttls       = [];

    try {
        config()->set('quickbooks.webhook.max_age_seconds', 1234);
        config()->set('quickbooks.webhook.stale_replay_seconds', 5678);
        whrWithEvents(function (array &$seen) use ($controller, $body, &$ttls) {
            $real = Cache::getFacadeRoot();
            Cache::swap(new class($real, $ttls) {
                public function __construct(private object $inner, private array &$ttls)
                {
                }

                public function add(string $key, mixed $value, mixed $ttl = null): bool
                {
                    $this->ttls[] = ['add', $ttl];

                    return $this->inner->add($key, $value, $ttl);
                }

                public function put(string $key, mixed $value, mixed $ttl = null): bool
                {
                    $this->ttls[] = ['put', $ttl];

                    return $this->inner->put($key, $value, $ttl);
                }

                public function forget(string $key): bool
                {
                    return $this->inner->forget($key);
                }

                public function flush(): bool
                {
                    return $this->inner->flush();
                }
            });
            try {
                $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
            } finally {
                Cache::swap($real);
            }
        });
    } finally {
        config()->set('quickbooks.webhook.max_age_seconds', $previous);
        config()->set('quickbooks.webhook.stale_replay_seconds', $window);
    }

    // A worker that dies mid-request leaves only the short key, so Intuit's retry is processed.
    // A processed body is remembered for as long as a stale copy would still be read, so a
    // captured fresh body cannot be replayed once it turns stale.
    expect($ttls)->toBe([['add', 120], ['put', 5678]]);
});

test('a stale delivery for a realm with no connection is acknowledged like any stale delivery', function () {
    $stale      = whrBody(whrStamp(700));
    $controller = whrController(whrSettings(), whrStore(), []);

    whrWithEvents(function (array &$seen) use ($controller, $stale) {
        $response = $controller->handle(whrRequest($stale, whrSign($stale, 'verifier-token')));

        expect($response->getStatusCode())->toBe(200)
            ->and($seen)->toBe([]);
    });
});

test('a delivery that is still being processed gets 409 so Intuit retries it later', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen, Closure $setListener) use ($controller, $body) {
        $during = null;
        $setListener(function () use ($controller, $body, &$during): void {
            $during = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')))->getStatusCode();
        });

        $first = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($first->getStatusCode())->toBe(200)
            ->and($during)->toBe(409)
            ->and($seen)->toHaveCount(1);
    });
});

/**
 * One event in Intuit's CloudEvents webhook format.
 *
 * @return array<string, mixed>
 */
function whrCloudEvent(string $type, string $entityId, string $realmId, ?string $time = null): array
{
    $event = [
        'specversion'     => '1.0',
        'id'              => 'event-' . $type . '-' . $entityId,
        'source'          => 'intuit.test',
        'type'            => $type,
        'datacontenttype' => 'application/json',
        'intuitentityid'  => $entityId,
        'intuitaccountid' => $realmId,
    ];
    if ($time !== null) {
        $event['time'] = $time;
    }

    return $event;
}

test('a cloudevents delivery dispatches one event per in-scope entity on each connected realm', function () {
    $body = json_encode([
        whrCloudEvent('qbo.customer.created.v1', '55', 'realm-whr', whrStamp(0)),
        whrCloudEvent('qbo.vendor.created.v1', '9', 'realm-whr', whrStamp(0)),
        whrCloudEvent('qbo.invoice.updated.v1', '8', 'realm-whr', whrStamp(0)),
        whrCloudEvent('qbo.invoice.emailed.v1', '8', 'realm-whr', whrStamp(0)),
        whrCloudEvent('qbo.payment.created.v1', '3', 'realm-unknown', whrStamp(0)),
        whrCloudEvent('qbo.account.merged.v1', '7', 'realm-whr', whrStamp(0)),
        whrCloudEvent('qbo.payment.voided.v1', '4', 'realm-two', whrStamp(0)),
        whrCloudEvent('qbo.invoice.deleted.v1', '6', 'realm-two', whrStamp(0)),
    ]);
    $controller = whrController(whrSettings(), whrStore(), [
        'realm-whr' => 'company-a',
        'realm-two' => 'company-b',
    ]);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $response = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        $summary = array_map(
            fn (QuickBooksEntityChanged $event) => [
                $event->companyUuid,
                $event->realmId,
                $event->entityType,
                $event->quickbooksId,
                $event->operation,
            ],
            $seen
        );
        expect($response->getStatusCode())->toBe(200)
            ->and($summary)->toBe([
                ['company-a', 'realm-whr', 'customer', '55', 'create'],
                ['company-a', 'realm-whr', 'invoice', '8', 'update'],
                ['company-a', 'realm-whr', 'wallet', '7', 'update'],
                ['company-b', 'realm-two', 'payment', '4', 'delete'],
                ['company-b', 'realm-two', 'invoice', '6', 'delete'],
            ]);
    });
});

test('a stale cloudevents delete is acknowledged but not applied and a fresh one is applied', function () {
    $stale = json_encode([whrCloudEvent('qbo.customer.deleted.v1', '77', 'realm-whr', whrStamp(700))]);
    // Intuit sends fractional seconds with up to nine digits.
    $freshAt = gmdate('Y-m-d\TH:i:s', time() - 30) . '.179851517Z';
    $fresh   = json_encode([whrCloudEvent('qbo.customer.deleted.v1', '77', 'realm-whr', $freshAt)]);

    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.max_age_seconds');
    config()->set('quickbooks.webhook.max_age_seconds', null);

    try {
        whrWithEvents(function (array &$seen) use ($controller, $stale, $fresh) {
            $old     = $controller->handle(whrRequest($stale, whrSign($stale, 'verifier-token')));
            $current = $controller->handle(whrRequest($fresh, whrSign($fresh, 'verifier-token')));

            // The stale delete is passed on only to be noted in Activity.
            expect($old->getStatusCode())->toBe(200)
                ->and($current->getStatusCode())->toBe(200)
                ->and(array_map(fn (QuickBooksEntityChanged $event) => $event->operation, $seen))
                ->toBe(['stale-delete', 'delete']);
        });
    } finally {
        config()->set('quickbooks.webhook.max_age_seconds', $previous);
    }
});

test('a cloudevents delivery needs a valid signature and a signed body with no realm is acknowledged', function () {
    $body    = json_encode([whrCloudEvent('qbo.customer.created.v1', '77', 'realm-whr')]);
    $noRealm = json_encode([['specversion' => '1.0', 'type' => 'qbo.customer.created.v1', 'intuitentityid' => '77']]);

    whrWithEvents(function (array &$seen) use ($body, $noRealm) {
        $connected = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
        $missing   = whrController(whrSettings(), whrStore(), []);

        $badSignature = $connected->handle(whrRequest($body, whrSign($body, 'other-token')));
        $unsigned     = $connected->handle(whrRequest($body, null));
        $realmless    = $connected->handle(whrRequest($noRealm, whrSign($noRealm, 'verifier-token')));
        $empty        = $connected->handle(whrRequest('[]', whrSign('[]', 'verifier-token')));
        $notConnected = $missing->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($badSignature->getStatusCode())->toBe(401)
            ->and($unsigned->getStatusCode())->toBe(401)
            ->and($realmless->getStatusCode())->toBe(200)
            ->and($empty->getStatusCode())->toBe(200)
            ->and($notConnected->getStatusCode())->toBe(200)
            ->and($seen)->toBe([]);
    });
});

test('a stale delivery reads its updates, re-reads payment deletes, and applies no delete or merge', function () {
    $at     = whrStamp(700);
    $merged = whrCloudEvent('qbo.customer.merged.v1', '12', 'realm-whr', $at);

    $merged['data'] = ['deletedid' => '13'];

    $body = json_encode([
        whrCloudEvent('qbo.customer.created.v1', '55', 'realm-whr', $at),
        whrCloudEvent('qbo.invoice.updated.v1', '8', 'realm-whr', $at),
        whrCloudEvent('qbo.customer.deleted.v1', '9', 'realm-whr', $at),
        whrCloudEvent('qbo.payment.voided.v1', '4', 'realm-whr', $at),
        $merged,
    ]);
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $response = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
        $summary  = array_map(
            fn (QuickBooksEntityChanged $event) => [$event->entityType, $event->quickbooksId, $event->operation],
            $seen
        );

        expect($response->getStatusCode())->toBe(200)
            // A stale payment void only re-reads its invoices' balances, and a stale customer
            // delete is passed on only to be noted in Activity. The merge is still skipped.
            ->and($summary)->toBe([
                ['customer', '55', 'create'],
                ['invoice', '8', 'update'],
                ['customer', '9', 'stale-delete'],
                ['payment', '4', 'refresh'],
                ['customer', '12', 'update'],
            ]);
    });
});

test('a processed stale delivery is remembered for the stale replay window and not applied twice', function () {
    $stale      = whrBody(whrStamp(700), 'Update');
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.stale_replay_seconds');

    try {
        config()->set('quickbooks.webhook.stale_replay_seconds', 86400);
        $request = fn () => whrRequest($stale, whrSign($stale, 'verifier-token'));
        whrWithEvents(function (array &$seen) use ($controller, $request) {
            $ttls   = CacheTtlRecorder::during(fn () => $controller->handle($request()));
            $repeat = $controller->handle($request());

            expect($ttls)->toBe([['add', 120], ['put', 86400]])
                ->and($repeat->getStatusCode())->toBe(200)
                ->and($seen)->toHaveCount(1);
        });

        config()->set('quickbooks.webhook.stale_replay_seconds', null);
        whrWithEvents(function () use ($controller, $request) {
            $ttls = CacheTtlRecorder::during(fn () => $controller->handle($request()));

            expect($ttls)->toBe([['add', 120], ['put', 604800]]);
        });
    } finally {
        config()->set('quickbooks.webhook.stale_replay_seconds', $previous);
    }
});

test('a delivery older than the stale replay window is acknowledged but not applied', function () {
    $week       = whrBody(whrStamp(8 * 86400), 'Update');
    $hours      = whrBody(whrStamp(7200), 'Update');
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.stale_replay_seconds');

    try {
        whrWithEvents(function (array &$seen) use ($controller, $week, $hours) {
            config()->set('quickbooks.webhook.stale_replay_seconds', null);
            $old = $controller->handle(whrRequest($week, whrSign($week, 'verifier-token')));
            config()->set('quickbooks.webhook.stale_replay_seconds', 3600);
            $older = $controller->handle(whrRequest($hours, whrSign($hours, 'verifier-token')));
            // A window no longer than the maximum age turns stale processing off.
            config()->set('quickbooks.webhook.stale_replay_seconds', 0);
            $off = $controller->handle(whrRequest($hours, whrSign($hours, 'verifier-token')));

            expect($old->getStatusCode())->toBe(200)
                ->and($older->getStatusCode())->toBe(200)
                ->and($off->getStatusCode())->toBe(200)
                ->and($seen)->toBe([]);
        });
    } finally {
        config()->set('quickbooks.webhook.stale_replay_seconds', $previous);
    }
});

test('a signed delete with no timestamp is not applied from the payload', function () {
    $body       = whrBody('', 'Delete');
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $response = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
        Cache::flush();
        $replayed = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($response->getStatusCode())->toBe(200)
            ->and($replayed->getStatusCode())->toBe(200)
            ->and(array_map(static fn (QuickBooksEntityChanged $event): string => $event->operation, $seen))
            ->not->toContain('delete');
    });
});

test('an undated delivery is logged as undated, not as older than the maximum age', function () {
    $body       = whrBody('', 'Delete');
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $logged     = new class {
        /** @var array<int, string> */
        public array $messages = [];

        /**
         * @param array<int, mixed> $arguments
         */
        public function __call(string $method, array $arguments): void
        {
            $this->messages[] = (string) ($arguments[0] ?? '');
        }
    };
    $previousLog = Log::getFacadeRoot();
    Log::swap($logged);

    try {
        whrWithEvents(function (array &$seen) use ($controller, $body) {
            $response = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
            expect($response->getStatusCode())->toBe(200);
        });
    } finally {
        Log::swap($previousLog);
    }

    expect(implode("\n", $logged->messages))->toContain('has a change with no time')
        ->not->toContain('older than the maximum age');
});

test('an undated create is still read back from QuickBooks', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $response = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($response->getStatusCode())->toBe(200)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0]->operation)->toBe('create');
    });
});
