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
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

function whrBody(string $lastUpdated = ''): string
{
    $extra = $lastUpdated === '' ? '' : ',"lastUpdated":"' . $lastUpdated . '"';

    return '{"eventNotifications":[{"realmId":"realm-whr","dataChangeEvent":{"entities":[{"name":"Customer","id":"77","operation":"Create"' . $extra . '}]}}]}';
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

test('a successfully processed delivery is still rejected when the same body arrives again', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);

    whrWithEvents(function (array &$seen) use ($controller, $body) {
        $first  = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));
        $replay = $controller->handle(whrRequest($body, whrSign($body, 'verifier-token')));

        expect($first->getStatusCode())->toBe(200)
            ->and($replay->getStatusCode())->toBe(401)
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

test('a delivery older than ten minutes is rejected by default and one inside the window is accepted', function () {
    $stale = whrBody(whrStamp(700));
    $fresh = whrBody(whrStamp(500));

    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.max_age_seconds');
    config()->set('quickbooks.webhook.max_age_seconds', null);

    try {
        whrWithEvents(function (array &$seen) use ($controller, $stale, $fresh) {
            $old     = $controller->handle(whrRequest($stale, whrSign($stale, 'verifier-token')));
            $current = $controller->handle(whrRequest($fresh, whrSign($fresh, 'verifier-token')));

            expect($old->getStatusCode())->toBe(401)
                ->and($current->getStatusCode())->toBe(200)
                ->and($seen)->toHaveCount(1);
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
                ->and($replay->getStatusCode())->toBe(401)
                ->and($seen)->toHaveCount(1);
        });

        config()->set('quickbooks.webhook.max_age_seconds', 120);
        whrWithEvents(function (array &$seen) use ($controller) {
            $tooOld = whrBody(whrStamp(300));
            $inside = whrBody(whrStamp(60));

            $rejected = $controller->handle(whrRequest($tooOld, whrSign($tooOld, 'verifier-token')));
            $accepted = $controller->handle(whrRequest($inside, whrSign($inside, 'verifier-token')));

            expect($rejected->getStatusCode())->toBe(401)
                ->and($accepted->getStatusCode())->toBe(200)
                ->and($seen)->toHaveCount(1);
        });
    } finally {
        config()->set('quickbooks.webhook.max_age_seconds', $previous);
    }
});

test('the replay key is stored for the configured maximum age', function () {
    $body       = whrBody();
    $controller = whrController(whrSettings(), whrStore(), ['realm-whr' => 'company-a']);
    $previous   = config('quickbooks.webhook.max_age_seconds');
    $ttls       = [];

    try {
        config()->set('quickbooks.webhook.max_age_seconds', 1234);
        whrWithEvents(function (array &$seen) use ($controller, $body, &$ttls) {
            $real = Cache::getFacadeRoot();
            Cache::swap(new class($real, $ttls) {
                public function __construct(private object $inner, private array &$ttls)
                {
                }

                public function add(string $key, mixed $value, mixed $ttl = null): bool
                {
                    $this->ttls[] = $ttl;

                    return $this->inner->add($key, $value, $ttl);
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
    }

    expect($ttls)->toBe([1234]);
});
