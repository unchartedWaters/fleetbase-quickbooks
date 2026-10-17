<?php

use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Tests\Support\DocsSource;
use Fleetbase\Quickbooks\Tests\Support\MarkdownLinks;

// The required items are read from the code, so a new setting, route or command fails here
// until README.md, docs/SETUP.md or CONTRIBUTING.md explains it.

test('the setup guide names every environment variable the server reads', function () {
    $names = DocsSource::environmentVariables();

    expect($names)->toContain('QUICKBOOKS_ENVIRONMENT')
        ->and($names)->toContain('QUICKBOOKS_SKIP_MIGRATE')
        ->and($names)->not->toContain('PACKAGE')
        ->and(DocsSource::missing('docs/SETUP.md', $names))->toBe([]);
});

test('the setup guide names every config key that is not a stored sync setting', function () {
    $needed = [];
    foreach (DocsSource::configLeaves() as $key => $value) {
        // A key read from an environment variable is documented by that variable.
        $fromEnv = str_starts_with($value, 'env(') === true;
        $stored  = str_starts_with($key, 'sync.') === true
            && in_array(substr($key, 5), SettingsKeys::SYNC_FIELDS, true) === true;
        if ($fromEnv === false && $stored === false && str_starts_with($key, 'api.routing.') === false) {
            $needed[] = '`quickbooks.' . $key . '`';
        }
    }

    expect($needed)->toContain('`quickbooks.sync.claim_seconds`')
        ->and($needed)->toContain('`quickbooks.retention.pending_days`')
        ->and($needed)->not->toContain('`quickbooks.environment`')
        ->and(DocsSource::missing('docs/SETUP.md', $needed))->toBe([])
        // The guide also says how to set them, since the package does not publish its config file.
        ->and(DocsSource::missing('docs/SETUP.md', ['`config/quickbooks.php`']))->toBe([]);
});

test('the setup guide gives the full path of every route Intuit calls without a session', function () {
    $routes = DocsSource::text('server/src/routes.php');
    $public = substr($routes, 0, (int) strpos($routes, "'fleetbase.protected'"));
    preg_match_all('/\$router->(?:get|post|put|patch|delete|any)\(\s*\'([^\']+)\'/', $public, $matches);
    $config = DocsSource::configLeaves();
    $base   = '/' . trim($config['api.routing.prefix'], '\'')
        . '/' . trim($config['api.routing.internal_prefix'], '\'') . '/';
    $paths  = array_map(fn (string $uri): string => $base . ltrim($uri, '/'), $matches[1]);

    expect($paths)->toContain('/quickbooks/int/v1/oauth/callback')
        ->and($paths)->toContain('/quickbooks/int/v1/webhooks')
        ->and(DocsSource::missing('docs/SETUP.md', $paths))->toBe([]);
});

test('the setup guide names every console command and the install commands', function () {
    $needed = [];
    foreach ((new ReflectionClass(QuickbooksServiceProvider::class))->getDefaultProperties()['commands'] as $command) {
        $signature = (string) (new ReflectionClass($command))->getDefaultProperties()['signature'];
        $needed[]  = '`' . strtok($signature, ' ') . '`';
    }
    if (DocsSource::hasFiles('server/migrations/*.php') === true) {
        $needed[] = '`php artisan migrate`';
    }
    if (DocsSource::hasFiles('server/src/Auth/Schemas/*.php') === true) {
        $needed[] = '`php artisan fleetbase:create-permissions`';
    }

    expect($needed)->toContain('`quickbooks:sync`')
        ->and(DocsSource::missing('docs/SETUP.md', $needed))->toBe([]);
});

test('the docs give the package names and the versions the host needs', function () {
    $composer = json_decode(DocsSource::text('composer.json'), true);
    $package  = json_decode(DocsSource::text('package.json'), true);
    $needed   = [
        '`' . $composer['name'] . '`',
        '`' . $package['name'] . '`',
        'PHP `' . $composer['require']['php'] . '`',
        'Node `' . $package['engines']['node'] . '`',
    ];
    foreach ($composer['require'] as $name => $version) {
        if (str_starts_with($name, 'fleetbase/') === true) {
            $needed[] = '`' . $name . '` `' . $version . '`';
        }
    }

    expect(DocsSource::missing('docs/SETUP.md', $needed))->toBe([])
        ->and(DocsSource::missing('README.md', ['flb install ' . $package['name']]))->toBe([]);
});

test('the README links the setup guide and the guide links the Intuit developer portal', function () {
    expect(DocsSource::missing('README.md', ['](docs/SETUP.md']))->toBe([])
        ->and(DocsSource::missing('docs/SETUP.md', ['](https://developer.intuit.com']))->toBe([]);
});

test('the contributing guide names every sandbox test variable', function () {
    $source = DocsSource::text('server/tests/SandboxLiveTest.php');
    preg_match('/const SANDBOX_VARIABLES = \[([^\]]*)\]/', $source, $match);
    preg_match_all('/\'([A-Z0-9_]+)\'/', $match[1] ?? '', $names);

    expect($names[1])->toContain('QB_SANDBOX_REFRESH_TOKEN')
        ->and(DocsSource::missing('CONTRIBUTING.md', $names[1]))->toBe([]);
});

test('links, images and anchors in the docs point at something that exists', function (string $file) {
    expect(MarkdownLinks::broken(DocsSource::path($file)))->toBe([]);
})->with(['README.md', 'docs/SETUP.md', 'CONTRIBUTING.md']);

// Markdown is wrapped at 80 columns, so a needed phrase can break across lines.
test('a needed phrase is found when a line wrap splits it', function () {
    $text = "This package needs `fleetbase/fleetops-api`\n`^0.6.73`, and   `fleetbase/ledger-api` `^0.0.13`.";

    expect(DocsSource::absentFrom($text, [
        '`fleetbase/fleetops-api` `^0.6.73`',
        '`fleetbase/ledger-api` `^0.0.13`',
        '`fleetbase/core-api` `^1.6`',
    ]))->toBe(['`fleetbase/core-api` `^1.6`']);
});

test('the link check reports a missing file, heading or reference', function () {
    $file = sys_get_temp_dir() . '/' . uniqid('qbdocs', true) . '.md';
    file_put_contents($file, implode("\n", [
        '# Set `up` here',
        '## Set up here',
        '[ok](#set-up-here) [ok too](#set-up-here-1) [web](https://example.com/none)',
        '[gone](no-such-file.md) [bad](#nowhere) ![shot][Missing Label] `[code][skipped]`',
        '[kept][kept]',
        '',
        'A stray ` backtick.',
        '',
        '[after](stray.md)',
        '',
        '[kept]: #set-up-here',
    ]));

    try {
        $broken = MarkdownLinks::broken($file);
        sort($broken);

        expect(MarkdownLinks::anchors($file))->toBe(['set-up-here', 'set-up-here-1'])
            ->and($broken)->toBe([
                'missing file no-such-file.md',
                'missing file stray.md',
                'missing heading #nowhere',
                'no definition for [missing label]',
            ]);
    } finally {
        unlink($file);
    }
});
