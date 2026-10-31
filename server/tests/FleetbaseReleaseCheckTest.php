<?php

use Fleetbase\Quickbooks\ReleaseCheck\ReleaseChecks;
use Fleetbase\Quickbooks\Tests\Support\DocsSource;
use Fleetbase\Quickbooks\Tests\Support\ReleaseWorkflow;

require_once DocsSource::path('scripts/fleetbase-release/ReleaseChecks.php');

test('the release check polls for new Fleetbase releases and can be run by hand', function () {
    $on = ReleaseWorkflow::parsed()['on'];

    expect($on['schedule'][0]['cron'])->toMatch('/^\d+ \S+ \* \* \*$/')
        ->and($on['workflow_dispatch']['inputs'])->toHaveKeys(['fleetbase_version', 'force'])
        ->and($on['workflow_dispatch']['inputs']['force']['type'])->toBe('boolean');
});

test('a pull request that changes the check runs it before merge', function () {
    $pull = ReleaseWorkflow::parsed()['on']['pull_request'];

    expect($pull['branches'])->toBe(['main'])
        ->and($pull['paths'])->toContain('.github/workflows/fleetbase-release.yml')
        ->and($pull['paths'])->toContain('scripts/fleetbase-release/**')
        ->and($pull['paths'])->toContain('docker/ensure-quickbooks-extension.sh');
});

test('the release check reads the repository only and uses no secrets', function () {
    $workflow = ReleaseWorkflow::parsed();
    $granted  = [];
    foreach ($workflow['jobs'] as $name => $job) {
        if (isset($job['permissions']) === true) {
            $granted[$name] = $job['permissions'];
        }
    }

    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($granted)->toBe([])
        ->and(ReleaseWorkflow::text())->not->toContain('secrets.');
});

test('dispatch inputs reach scripts only through the environment', function () {
    foreach (ReleaseWorkflow::steps() as $step) {
        expect($step['run'] ?? '')->not->toContain('inputs.')
            ->and($step['run'] ?? '')->not->toContain('github.event.');
    }
});

test('checkouts keep no token and third-party actions are pinned to a commit', function () {
    foreach (ReleaseWorkflow::steps() as $step) {
        $uses = (string) ($step['uses'] ?? '');
        if (str_starts_with($uses, 'actions/checkout@') === true) {
            expect($step['with']['persist-credentials'] ?? null)->toBeFalse();
        }
        if ($uses !== '' && str_starts_with($uses, 'actions/') === false) {
            expect($uses)->toMatch('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/');
        }
    }
});

test('every job has a time limit', function () {
    foreach (ReleaseWorkflow::parsed()['jobs'] as $job) {
        expect($job['timeout-minutes'] ?? null)->toBeInt()->toBeLessThanOrEqual(60);
    }
});

test('the release is found by tag, its API image is used by digest, and both are checked', function () {
    $resolve = ReleaseWorkflow::job('resolve');
    $script  = ReleaseWorkflow::runs('resolve');
    $stack   = ReleaseWorkflow::runs('stack');

    expect($resolve['outputs'])->toHaveKeys(['version', 'digest', 'run', 'marker'])
        ->and($script)->toContain('releases/latest')
        ->and($script)->toContain('^v[0-9]+\.[0-9]+\.[0-9]+$')
        ->and($script)->toContain('^sha256:[0-9a-f]{64}$')
        ->and($stack)->toContain('fleetbase/fleetbase-api@${DIGEST}');
});

test('the stack installs the plugin as documented and checks it in order', function () {
    $runs  = ReleaseWorkflow::runs('stack');
    $order = [
        'ensure-quickbooks-extension.sh',
        './deploy.sh',
        'verify-install.php',
        'smoke-sync.php',
        'octane:frankenphp',
        'http-smoke.php',
        'queue:work',
        'browser-check.mjs',
    ];
    $positions = array_map(static fn (string $needle): int|false => strpos($runs, $needle), $order);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(array_values(array_unique($positions)))
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and($runs)->toContain('/fleetbase/packages/quickbooks:ro')
        ->and($runs)->toContain('QUICKBOOKS_SKIP_MIGRATE=1');
});

test('the console is built at the release tag with the packed engine', function () {
    $console  = ReleaseWorkflow::job('console');
    $runs     = ReleaseWorkflow::runs('console');
    $checkout = array_values(array_filter(
        $console['steps'],
        static fn (array $step): bool => ($step['with']['repository'] ?? '') === 'fleetbase/fleetbase'
    ));

    $build = array_values(array_filter(
        $console['steps'],
        static fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'pnpm build')
    ));

    expect($checkout)->toHaveCount(1)
        ->and($checkout[0]['with']['ref'])->toBe('${{ needs.resolve.outputs.version }}')
        ->and($build[0]['env']['EXTENSIONS'] ?? '')->toContain('@unchartedwaters/quickbooks-engine')
        ->and($build[0]['env']['API_HOST'] ?? '')->toBe('http://localhost:8000')
        ->and($runs)->toContain('npm pack')
        ->and($runs)->toContain('pnpm add')
        ->and($runs)->toContain('pnpm build --environment production')
        ->and($runs)->toContain('engines-dist/@unchartedwaters/quickbooks-engine');
});

test('a release is checked once: the marker is saved after the checks, never from a pull request', function () {
    $record = ReleaseWorkflow::job('record');
    $uses   = array_column($record['steps'], 'uses');

    expect($record['needs'])->toBe(['resolve', 'console', 'stack'])
        ->and($record['if'])->toContain("github.event_name != 'pull_request'")
        ->and($record['if'])->toContain("needs.resolve.outputs.run == 'true'")
        ->and($record['if'])->toContain('always()')
        ->and($uses)->toContain('actions/cache/save@v6');
    foreach (['console', 'stack'] as $job) {
        expect(ReleaseWorkflow::job($job)['if'])->toContain("needs.resolve.outputs.run == 'true'");
    }
});

test('the class name scan finds the Fleetbase classes the extension names in strings', function () {
    $dir = sys_get_temp_dir() . '/qb-release-scan-' . uniqid();
    mkdir($dir . '/Nested', 0777, true);
    file_put_contents($dir . '/A.php', "<?php\n\$a = 'Fleetbase\\\\Ledger\\\\Models\\\\Invoice';\n"
        . "\$b = 'Fleetbase\\\\Quickbooks\\\\Models\\\\Link';\n");
    file_put_contents($dir . '/Nested/B.php', "<?php\n"
        . "Event::listen('Fleetbase\\\\Ledger\\\\Events\\\\InvoicePaid', \$x);\n"
        . "\$c = \"Fleetbase\\\\FleetOps\\\\Models\\\\Contact\";\n\$d = 'Fleetbase\\\\Ledger\\\\Models\\\\Invoice';\n");
    file_put_contents($dir . '/notes.txt', "'Fleetbase\\\\Ignored\\\\Thing'");

    expect(ReleaseChecks::externalClassNames($dir))->toBe([
        'Fleetbase\\FleetOps\\Models\\Contact',
        'Fleetbase\\Ledger\\Events\\InvoicePaid',
        'Fleetbase\\Ledger\\Models\\Invoice',
    ]);
});

test('the class name scan of the real sources includes every model the provider observes', function () {
    $names = ReleaseChecks::externalClassNames(DocsSource::path('server/src'));

    expect($names)->toContain('Fleetbase\\FleetOps\\Models\\Contact')
        ->and($names)->toContain('Fleetbase\\FleetOps\\Models\\Customer')
        ->and($names)->toContain('Fleetbase\\FleetOps\\Models\\Place')
        ->and($names)->toContain('Fleetbase\\Ledger\\Models\\Invoice')
        ->and($names)->toContain('Fleetbase\\Ledger\\Models\\InvoiceItem')
        ->and($names)->toContain('Fleetbase\\Ledger\\Models\\Wallet')
        ->and($names)->toContain('Fleetbase\\Storefront\\Models\\Product')
        ->and($names)->toContain('Fleetbase\\Ledger\\Events\\InvoiceCreated');
});

test('the route count matches the routes the routes file declares', function () {
    $routes = "\$router->get('a', []);\n\$x = \$router->post('b', []);\n// \$router->get('comment', []);\n"
        . "\$router->group([], function (\$router) {});\n\$router->prefix('p');\n";

    expect(ReleaseChecks::declaredRouteCount($routes))->toBe(2)
        ->and(ReleaseChecks::declaredRouteCount(DocsSource::text('server/src/routes.php')))->toBe(14);
});

test('migration names come from the migration files', function () {
    $names = ReleaseChecks::migrationNames(DocsSource::path('server/migrations'));

    expect($names)->toContain('2026_09_25_000000_create_quickbooks_tables')
        ->and(count($names))->toBe(count(DocsSource::files('server/migrations/*.php')));
});

test('the permissions to expect are the ones the policies grant', function () {
    $schema = new Fleetbase\Quickbooks\Auth\Schemas\Quickbooks();
    $names  = ReleaseChecks::policyPermissions($schema);

    expect($names)->toContain('quickbooks see extension')
        ->and($names)->toContain('quickbooks * connection')
        ->and($names)->toBe(array_values(array_unique($names)));
});

test('missing lists what is expected but absent, in order', function () {
    expect(ReleaseChecks::missing(['a', 'b', 'c'], ['c', 'a']))->toBe(['b'])
        ->and(ReleaseChecks::missing([], ['a']))->toBe([]);
});

test('the contributing guide explains the release check and every variable its scripts read', function () {
    $names = [];
    foreach (DocsSource::files('scripts/fleetbase-release/*') as $file) {
        $source = (string) file_get_contents($file);
        preg_match_all('/(?:getenv\(|\$env\(|process\.env\.)\'?([A-Z][A-Z0-9_]+)/', $source, $found);
        $names = array_merge($names, $found[1]);
    }
    $names = array_values(array_unique($names));

    expect($names)->toContain('RELEASE_CHECK_PASSWORD')
        ->and($names)->toContain('FLEETBASE_API_PATH')
        ->and(DocsSource::missing('CONTRIBUTING.md', array_merge(
            ['.github/workflows/fleetbase-release.yml', '`force`', '`fleetbase_version`'],
            array_map(static fn (string $name): string => '`' . $name . '`', $names)
        )))->toBe([]);
});
