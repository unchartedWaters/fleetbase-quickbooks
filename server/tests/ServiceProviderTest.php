<?php

use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;

test('the extension does not inherit the core observer list', function () {
    $defaults = (new ReflectionClass(QuickbooksServiceProvider::class))->getDefaultProperties();

    expect($defaults['observers'])->toBe([])
        ->and($defaults['commands'])->toBe([
            Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks::class,
            Fleetbase\Quickbooks\Console\Commands\PruneQuickbooks::class,
        ]);
});

test('the scheduled commands hold their overlap lock for minutes, not a day', function () {
    $container = Container::getInstance();
    $mutex     = new class implements EventMutex, SchedulingMutex {
        public function create($event, $time = null)
        {
            return true;
        }

        public function exists($event, $time = null)
        {
            return false;
        }

        public function forget($event)
        {
        }
    };
    $container->instance(EventMutex::class, $mutex);
    $container->instance(SchedulingMutex::class, $mutex);

    try {
        $schedule = new Schedule();
        QuickbooksServiceProvider::scheduleTasks($schedule);
        $events = [];
        foreach ($schedule->events() as $event) {
            $events[$event->description] = $event;
        }
    } finally {
        $container->forgetInstance(EventMutex::class);
        $container->forgetInstance(SchedulingMutex::class);
    }

    expect(array_keys($events))->toBe(['quickbooks-sync', 'quickbooks-prune'])
        ->and($events['quickbooks-sync']->withoutOverlapping)->toBeTrue()
        ->and($events['quickbooks-sync']->expiresAt)->toBe(10)
        ->and($events['quickbooks-prune']->withoutOverlapping)->toBeTrue()
        ->and($events['quickbooks-prune']->expiresAt)->toBe(120);
});
