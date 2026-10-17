<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\Quickbooks\Observers\FlagCustomerObserver;
use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

// The Fleet-Ops console saves a new or edited customer as a Contact with type "customer".
// Eloquent fires model events for the class that was saved, so a Customer observer alone never sees it.
test('customer saves are observed on both the Customer and the Contact model', function () {
    $previous = Model::getEventDispatcher();
    $events   = new Dispatcher(Container::getInstance());
    Model::clearBootedModels();
    Model::setEventDispatcher($events);

    try {
        $provider = (new ReflectionClass(QuickbooksServiceProvider::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($provider, 'observeFleetbase'))->invoke($provider);

        // The models register listeners of their own when they boot, so look for this observer's.
        $registered = (new ReflectionProperty(Dispatcher::class, 'listeners'))->getValue($events);
        $observed   = static fn (string $event, string $model): bool => in_array(
            FlagCustomerObserver::class . '@' . $event,
            $registered['eloquent.' . $event . ': ' . $model] ?? [],
            true
        );

        expect($observed('saved', Customer::class))->toBeTrue()
            ->and($observed('saved', Contact::class))->toBeTrue()
            ->and($observed('created', Customer::class))->toBeTrue()
            ->and($observed('created', Contact::class))->toBeTrue();
    } finally {
        Customer::flushEventListeners();
        Contact::flushEventListeners();
        // observe() boots every observed model on the throwaway dispatcher, so boot them again later.
        Model::clearBootedModels();
        if ($previous !== null) {
            Model::setEventDispatcher($previous);
        } else {
            Model::unsetEventDispatcher();
        }
    }
});
