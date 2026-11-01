<?php

use Fleetbase\Quickbooks\ReleaseCheck\ReleaseChecks;
use Fleetbase\Quickbooks\Tests\Support\DocsSource;
use Fleetbase\Quickbooks\Tests\Support\ReleaseDecision;
use Fleetbase\Quickbooks\Tests\Support\ReleaseWorkflow;

require_once DocsSource::path('scripts/fleetbase-release/ReleaseChecks.php');

test('the release check polls for new Fleetbase releases and can be run by hand', function () {
    $on = ReleaseWorkflow::parsed()['on'];

    expect($on['schedule'][0]['cron'])->toMatch('/^\d+ \S+ \* \* \*$/')
        ->and(array_keys($on['workflow_dispatch']['inputs']))->toBe(['fleetbase_version']);
});

test('a release of this extension is checked against the newest Fleetbase, and pull requests are not', function () {
    $on = ReleaseWorkflow::parsed()['on'];

    expect($on)->not->toHaveKey('pull_request')
        ->and($on['push'])->toBe(['tags' => ['v*']])
        ->and(ReleaseWorkflow::runs('resolve'))->toContain('bash scripts/fleetbase-release/decide.sh');
});

test('the release check reads the repository only and uses no secrets', function () {
    $workflow = ReleaseWorkflow::parsed();
    $granted  = [];
    foreach ($workflow['jobs'] as $name => $job) {
        if (isset($job['permissions']) === true) {
            $granted[$name] = $job['permissions'];
        }
    }

    // Only resolve reads the Actions artifacts, to see which Fleetbase versions were checked.
    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($granted)->toBe(['resolve' => ['contents' => 'read', 'actions' => 'read']])
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

test('the long steps time out before their job, so a hung check still keeps its results', function () {
    $long = ['pnpm build', './deploy.sh', 'octane:frankenphp', 'queue:work', 'browser-check.mjs'];
    foreach (['console', 'stack'] as $name) {
        $job = ReleaseWorkflow::job($name);
        foreach ($job['steps'] as $step) {
            $run = (string) ($step['run'] ?? '');
            foreach ($long as $needle) {
                if (str_contains($run, $needle) === true) {
                    expect($step['timeout-minutes'] ?? null)->toBeInt()->toBeLessThan($job['timeout-minutes']);
                }
            }
        }
    }
});

test('the release is found by tag, its API image is used by digest, and both are checked', function () {
    $resolve = ReleaseWorkflow::job('resolve');
    $script  = ReleaseWorkflow::runs('resolve');
    $stack   = ReleaseWorkflow::runs('stack');

    expect(array_keys($resolve['outputs']))->toBe(['version', 'digest', 'published', 'run'])
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
        ->and($runs)->toContain('npm pack')
        ->and($runs)->toContain('package:/fleetbase/packages/quickbooks:ro')
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

test('a run that checked a Fleetbase version keeps its results, which mark the version checked', function () {
    $name    = 'fleetbase-${{ needs.resolve.outputs.version }}-results';
    $uploads = static fn (string $job): array => array_values(array_filter(
        ReleaseWorkflow::job($job)['steps'],
        static fn (array $step): bool => ($step['uses'] ?? '') === 'actions/upload-artifact@v7'
            && ($step['with']['name'] ?? '') === $name
    ));
    $stack   = $uploads('stack');
    $console = $uploads('console');

    // The stack uploads its results whether the checks passed or failed; the console uploads
    // them only when its build failed, because the stack does not run then. A cancelled run
    // uploads nothing, so the schedule tries again.
    expect(ReleaseWorkflow::parsed()['jobs'])->not->toHaveKey('record')
        ->and($stack)->toHaveCount(1)
        ->and($stack[0]['if'])->toBe('success() || failure()')
        ->and($stack[0]['with']['retention-days'])->toBe(90)
        ->and($stack[0]['with']['overwrite'])->toBeTrue()
        ->and($console)->toHaveCount(1)
        ->and($console[0]['if'])->toBe('failure()')
        ->and($console[0]['with']['retention-days'])->toBe(90)
        ->and($console[0]['with']['overwrite'])->toBeTrue()
        ->and(ReleaseWorkflow::text())->not->toContain('actions/cache')
        ->and(ReleaseWorkflow::text())->not->toContain('console-screenshots');
    foreach (['console', 'stack'] as $job) {
        expect(ReleaseWorkflow::job($job)['if'])->toBe("needs.resolve.outputs.run == 'true'");
    }
});

dataset('release decisions', function () {
    $digest  = 'sha256:' . str_repeat('a', 64);
    $none    = '{"artifacts": []}';
    $checked = '{"artifacts": [{"expired": false}]}';
    $expired = '{"artifacts": [{"expired": true}]}';

    return [
        'schedule: new release, image ready' => ['schedule', 1, $digest, $none, 'true', 'gh'],
        'schedule: 13 days old'              => ['schedule', 13, $digest, $none, 'true', 'gh'],
        'schedule: already checked'          => ['schedule', 1, $digest, $checked, 'false', 'already checked'],
        'schedule: only expired results'     => ['schedule', 1, $digest, $expired, 'true', 'gh'],
        'schedule: 14 days old'              => ['schedule', 14, $digest, $expired, 'false', 'no new release'],
        'schedule: image not out yet, waits' => ['schedule', 0, '', $none, 'false', 'not on Docker Hub yet'],
        'by hand: checked again'             => ['workflow_dispatch', 300, $digest, $checked, 'true', ''],
        'by hand: image missing, fails'      => ['workflow_dispatch', 0, '', $none, 'error', 'is not on Docker Hub.'],
        'release tag: checked again'         => ['push', 300, $digest, $checked, 'true', ''],
    ];
});

// The last value is "gh" when the artifacts are looked up, otherwise a part of the message.
test('the schedule checks only a new Fleetbase release, once', function (
    string $event,
    int $daysAgo,
    string $digest,
    string $artifacts,
    string $expected,
    string $says,
) {
    $decision = ReleaseDecision::run($event, $daysAgo, $digest, $artifacts);
    $lookedUp = $event === 'schedule' && $daysAgo < 14;

    expect($decision['result'])->toBe($expected)
        ->and($decision['gh'] !== '')->toBe($lookedUp);
    if ($lookedUp === true) {
        expect($decision['gh'])->toContain('repos/owner/repo/actions/artifacts?name=fleetbase-v9.9.9-results');
    }
    if ($says !== 'gh' && $says !== '') {
        expect($decision['log'])->toContain($says);
    }
})->with('release decisions');

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
            ['.github/workflows/fleetbase-release.yml', '`fleetbase_version`', 'fleetbase-<version>-results'],
            array_map(static fn (string $name): string => '`' . $name . '`', $names)
        )))->toBe([]);
});
