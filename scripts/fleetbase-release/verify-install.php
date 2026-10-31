<?php

declare(strict_types=1);

/*
 * Run inside the Fleetbase API container after deploy.sh. Checks that Fleetbase loaded the
 * extension: provider, migrations, tables, permissions, routes, commands, schedule, and that
 * every Fleetbase class or event the extension names in a string exists in this release.
 * smoke-sync.php shows the observers fire on the real models.
 * Prints one line per check and exits 1 if any failed.
 */

use Fleetbase\Quickbooks\Auth\Schemas\Quickbooks as QuickbooksSchema;
use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Fleetbase\Quickbooks\ReleaseCheck\ReleaseChecks;
use Fleetbase\Quickbooks\ReleaseCheck\Report;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$app     = require __DIR__ . '/boot.php';
$package = dirname(__DIR__, 2);
require_once __DIR__ . '/ReleaseChecks.php';
require_once __DIR__ . '/Report.php';

$report = new Report();
$check  = $report->check(...);

$provider = $app->getProvider(QuickbooksServiceProvider::class);
$check('service provider loaded', $provider === null ? ['not registered'] : []);

$check('migrations ran', ReleaseChecks::missing(
    ReleaseChecks::migrationNames($package . '/server/migrations'),
    DB::table('migrations')->pluck('migration')->all()
));

$tables = [
    'quickbooks_connections',
    'quickbooks_links',
    'quickbooks_pending_syncs',
    'quickbooks_sync_attempts',
    'quickbooks_sync_batches',
];
$check('tables exist', array_values(array_filter(
    $tables,
    static fn (string $table): bool => Schema::hasTable($table) === false
)));

$schema = new QuickbooksSchema();
$check('permissions created', ReleaseChecks::missing(
    ReleaseChecks::policyPermissions($schema),
    DB::table('permissions')->where('name', 'like', 'quickbooks %')->pluck('name')->all()
));
$check('policies created', ReleaseChecks::missing(
    array_column($schema->policies, 'name'),
    DB::table('policies')->where('name', 'like', 'Quickbooks%')->pluck('name')->all()
));
$check('roles created', ReleaseChecks::missing(
    array_column($schema->roles ?? [], 'name'),
    DB::table('roles')->where('name', 'like', 'QuickBooks%')->pluck('name')->all()
));

$routes = array_values(array_filter(
    $app['router']->getRoutes()->getRoutes(),
    static fn ($route): bool => str_starts_with((string) $route->getActionName(), 'Fleetbase\\Quickbooks\\')
));
$declared = ReleaseChecks::declaredRouteCount((string) file_get_contents($package . '/server/src/routes.php'));
$check('routes registered', count($routes) === $declared ? [] : [count($routes) . ' of ' . $declared]);

$check('commands registered', ReleaseChecks::missing(
    ['quickbooks:sync', 'quickbooks:prune'],
    array_keys(Artisan::all())
));

Artisan::call('schedule:list');
$schedule = Artisan::output();
$check('scheduled', array_values(array_filter(
    ['quickbooks:sync', 'quickbooks:prune'],
    static fn (string $command): bool => str_contains($schedule, $command) === false
)));

$check('Fleetbase classes the extension names exist', array_values(array_filter(
    ReleaseChecks::externalClassNames($package . '/server/src'),
    static fn (string $name): bool => class_exists($name) === false
        && interface_exists($name) === false
)));

exit($report->exitCode());
