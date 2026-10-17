<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

// The Fleet-Ops console saves a new or edited customer as a Contact with type "customer".
// Eloquent fires saved events for the class that was saved, so a Customer observer alone never sees it.
test('customer saves are observed on both the Customer and the Contact model', function () {
    $previous = Model::getEventDispatcher();
    $events   = new Dispatcher(Container::getInstance());
    Model::setEventDispatcher($events);

    try {
        $provider = (new ReflectionClass(QuickbooksServiceProvider::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($provider, 'observeFleetbase'))->invoke($provider);

        expect($events->hasListeners('eloquent.saved: ' . Customer::class))->toBeTrue()
            ->and($events->hasListeners('eloquent.saved: ' . Contact::class))->toBeTrue();
    } finally {
        Customer::flushEventListeners();
        Contact::flushEventListeners();
        if ($previous !== null) {
            Model::setEventDispatcher($previous);
        } else {
            Model::unsetEventDispatcher();
        }
    }
});
