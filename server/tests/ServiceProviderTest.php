<?php

use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;

test('the extension does not inherit the core observer list', function () {
    $defaults = (new ReflectionClass(QuickbooksServiceProvider::class))->getDefaultProperties();

    expect($defaults['observers'])->toBe([])
        ->and($defaults['commands'])->toBe([
            Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks::class,
            Fleetbase\Quickbooks\Console\Commands\PruneQuickbooks::class,
        ]);
});
