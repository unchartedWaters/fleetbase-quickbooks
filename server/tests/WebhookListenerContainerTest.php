<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

function webhookListenerQueued(EnqueueWebhookSync $listener): array
{
    $queued = new ReflectionProperty(EnqueueWebhookSync::class, 'queued');
    $queued->setAccessible(true);

    return $queued->getValue($listener);
}

// Octane serves each request from a copy of the container, but the event dispatcher keeps
// the container it was built with. WebhookController flushes the listener it resolves from
// the request's container, so a change must be queued on that same instance.
test('a webhook change is queued on the listener of the container serving the request', function () {
    $base = Container::getInstance();
    $base->forgetInstance(EnqueueWebhookSync::class);
    $base->singleton(EnqueueWebhookSync::class, static fn () => new EnqueueWebhookSync(
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher())
    ));
    $events = new Dispatcher($base);
    EnqueueWebhookSync::listen($events);
    $booted = $base->make(EnqueueWebhookSync::class);

    // Each request starts with its own listener, as WebhookController's flush leaves it.
    $request = clone $base;
    $request->forgetInstance(EnqueueWebhookSync::class);
    Container::setInstance($request);

    try {
        $events->dispatch(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '90', 'update', 'cust-1'));

        $queued = webhookListenerQueued($request->make(EnqueueWebhookSync::class));
        expect($queued)->toHaveKey('company-a')
            ->and($queued['company-a'])->toHaveCount(1)
            ->and(webhookListenerQueued($booted))->toBe([]);
    } finally {
        Container::setInstance($base);
        $base->forgetInstance(EnqueueWebhookSync::class);
    }
});
