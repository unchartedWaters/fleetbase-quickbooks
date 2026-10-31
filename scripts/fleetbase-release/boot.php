<?php

declare(strict_types=1);

/*
 * Boots the Fleetbase API that the release check installed this extension into, the way
 * artisan does, and returns the application. FLEETBASE_API_PATH overrides /fleetbase/api.
 */

$api = getenv('FLEETBASE_API_PATH');
$api = is_string($api) && $api !== '' ? $api : '/fleetbase/api';

require $api . '/vendor/autoload.php';

$app = require $api . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Laravel's handler prints an uncaught exception and the script would still exit 0. A check
// that stops on an exception has failed.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL stopped: ' . $error::class . ': ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, $error->getTraceAsString() . PHP_EOL);
    exit(1);
});

return $app;
