<?php

use Fleetbase\Quickbooks\Listeners\FlagProductListener;
use Fleetbase\Quickbooks\Observers\FlagProductObserver;
use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Fleetbase\Quickbooks\Tests\Support\ProductEvents;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/*
 * A Storefront product change queues the product; a rename or a delete also queues the name it
 * had, so its QuickBooks product can go inactive when nothing uses the name.
 */

afterEach(function () {
    Container::getInstance()->forgetInstance(FlagProductListener::class);
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

test('a change to what QuickBooks holds queues the product, and other changes do not', function () {
    foreach (['name', 'description', 'sku', 'price', 'currency', 'is_service', 'status'] as $field) {
        $written = ProductEvents::listener();
        (new FlagProductObserver())->saved(ProductEvents::record(['changed' => [$field]]));

        expect(ProductEvents::rows($written))->toBe(['product:p-1']);
    }

    $written = ProductEvents::listener();
    (new FlagProductObserver())->saved(ProductEvents::record(['changed' => ['meta']]));

    expect(ProductEvents::rows($written))->toBe([]);
});

test('a new product is queued', function () {
    $written = ProductEvents::listener();
    (new FlagProductObserver())->saved(ProductEvents::record(['created' => true]));

    expect(ProductEvents::rows($written))->toBe(['product:p-1']);
});

test('a rename queues the name the product had once the new one is saved', function () {
    $written = ProductEvents::listener();
    (new FlagProductObserver())->saved(ProductEvents::record([
        'changed'  => ['name'],
        'name'     => 'Cup',
        'original' => 'Mug',
    ]));

    expect(ProductEvents::rows($written))->toBe([
        'product:p-1',
        'item:' . ProductMapper::itemUuid('company-uuid', 'Mug'),
    ]);
});

test('a change of case only queues no other name', function () {
    $written = ProductEvents::listener();
    (new FlagProductObserver())->saved(ProductEvents::record([
        'changed'  => ['name'],
        'name'     => 'MUG',
        'original' => 'Mug',
    ]));

    expect(ProductEvents::rows($written))->toBe(['product:p-1']);
});

test('a delete queues the product and its name, and a restore the product', function () {
    $written = ProductEvents::listener();
    (new FlagProductObserver())->deleted(ProductEvents::record(['name' => 'Cup']));
    (new FlagProductObserver())->restored(ProductEvents::record(['name' => 'Cup']));

    expect(ProductEvents::rows($written))->toBe([
        'product:p-1',
        'item:' . ProductMapper::itemUuid('company-uuid', 'Cup'),
        'product:p-1',
    ]);
});

test('nothing is queued while syncing is paused', function () {
    $written = ProductEvents::listener();
    SyncSuppressor::pause();
    (new FlagProductObserver())->deleted(ProductEvents::record(['name' => 'Cup']));

    expect(ProductEvents::rows($written))->toBe([]);
});

test('Storefront products are observed when Storefront is installed', function () {
    $previous = Model::getEventDispatcher();
    $events   = new Dispatcher(Container::getInstance());
    Model::clearBootedModels();
    Model::setEventDispatcher($events);

    try {
        $provider = (new ReflectionClass(QuickbooksServiceProvider::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($provider, 'observeFleetbase'))->invoke($provider);
        $registered = (new ReflectionProperty(Dispatcher::class, 'listeners'))->getValue($events);
        $observed   = static fn (string $event): bool => in_array(
            FlagProductObserver::class . '@' . $event,
            $registered['eloquent.' . $event . ': ' . Product::class] ?? [],
            true
        );

        expect($observed('saved'))->toBeTrue()
            ->and($observed('deleted'))->toBeTrue()
            ->and($observed('restored'))->toBeTrue();
    } finally {
        Model::clearBootedModels();
        if ($previous !== null) {
            Model::setEventDispatcher($previous);
        } else {
            Model::unsetEventDispatcher();
        }
    }
});
