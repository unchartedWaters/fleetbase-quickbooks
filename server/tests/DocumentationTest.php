<?php

use Fleetbase\Quickbooks\Providers\QuickbooksServiceProvider;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Tests\Support\MarkdownLinks;

// The required items are read from the code, so a new setting, route or command fails here
// until README.md, docs/SETUP.md or CONTRIBUTING.md explains it.

function docsRoot(): string
{
    return dirname(__DIR__, 2);
}

function docsText(string $file): string
{
    return (string) file_get_contents(docsRoot() . '/' . $file);
}

/**
 * @param array<int, string> $needed
 *
 * @return array<int, string> the needed items the file does not mention
 */
function docsMissing(string $file, array $needed): array
{
    $text = docsText($file);

    return array_values(array_filter(array_unique($needed), fn (string $item): bool => str_contains($text, $item) === false));
}

/**
 * Reads server/config/quickbooks.php as text, because its env() calls need the host app.
 *
 * @return array<string, string> the source of each leaf value by dotted key
 */
function docsConfigLeaves(): array
{
    $leaves = [];
    $path   = [];
    foreach (explode("\n", docsText('server/config/quickbooks.php')) as $line) {
        if (preg_match('/^( +)\'([a-z_]+)\'\s*=>\s*(.*?),?\s*$/', $line, $match) !== 1) {
            continue;
        }
        $path = array_slice($path, 0, intdiv(strlen($match[1]), 4) - 1);
        if ($match[3] === '[') {
            $path[] = $match[2];
            continue;
        }
        $leaves[implode('.', array_merge($path, [$match[2]]))] = $match[3];
    }

    return $leaves;
}

test('the setup guide names every environment variable the server reads', function () {
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(docsRoot() . '/server', FilesystemIterator::SKIP_DOTS));
    $names   = [];
    foreach ($sources as $source) {
        if (str_contains($source->getPathname(), '/server/tests/') === true || $source->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/\b(?:env|getenv)\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', (string) file_get_contents($source->getPathname()), $matches);
        $names = array_merge($names, $matches[1]);
    }

    expect($names)->toContain('QUICKBOOKS_ENVIRONMENT')
        ->and(docsMissing('docs/SETUP.md', $names))->toBe([]);
});

test('the setup guide names every config key that is not a stored sync setting', function () {
    $needed = [];
    foreach (docsConfigLeaves() as $key => $value) {
        // A key read from an environment variable is documented by that variable.
        $fromEnv = str_starts_with($value, 'env(') === true;
        $stored  = str_starts_with($key, 'sync.') === true && in_array(substr($key, 5), SettingsKeys::SYNC_FIELDS, true) === true;
        if ($fromEnv === false && $stored === false && str_starts_with($key, 'api.routing.') === false) {
            $needed[] = '`quickbooks.' . $key . '`';
        }
    }

    expect($needed)->toContain('`quickbooks.sync.claim_seconds`')
        ->and($needed)->toContain('`quickbooks.retention.pending_days`')
        ->and($needed)->not->toContain('`quickbooks.environment`')
        ->and(docsMissing('docs/SETUP.md', $needed))->toBe([]);
});

test('the setup guide gives the full path of every route Intuit calls without a session', function () {
    $routes = docsText('server/src/routes.php');
    $public = substr($routes, 0, (int) strpos($routes, "'fleetbase.protected'"));
    preg_match_all('/\$router->(?:get|post|put|patch|delete|any)\(\s*\'([^\']+)\'/', $public, $matches);
    $config = docsConfigLeaves();
    $base   = '/' . trim($config['api.routing.prefix'], '\'') . '/' . trim($config['api.routing.internal_prefix'], '\'') . '/';
    $paths  = array_map(fn (string $uri): string => $base . ltrim($uri, '/'), $matches[1]);

    expect($paths)->toContain('/quickbooks/int/v1/oauth/callback')
        ->and($paths)->toContain('/quickbooks/int/v1/webhooks')
        ->and(docsMissing('docs/SETUP.md', $paths))->toBe([]);
});

test('the setup guide names every console command and the install commands', function () {
    $needed = [];
    foreach ((new ReflectionClass(QuickbooksServiceProvider::class))->getDefaultProperties()['commands'] as $command) {
        $needed[] = '`' . strtok((string) (new ReflectionClass($command))->getDefaultProperties()['signature'], ' ') . '`';
    }
    if ((glob(docsRoot() . '/server/migrations/*.php') ?: []) !== []) {
        $needed[] = '`php artisan migrate`';
    }
    if ((glob(docsRoot() . '/server/src/Auth/Schemas/*.php') ?: []) !== []) {
        $needed[] = '`php artisan fleetbase:create-permissions`';
    }

    expect($needed)->toContain('`quickbooks:sync`')
        ->and(docsMissing('docs/SETUP.md', $needed))->toBe([]);
});

test('the docs give the package names and the versions the host needs', function () {
    $composer = json_decode(docsText('composer.json'), true);
    $package  = json_decode(docsText('package.json'), true);
    $needed   = ['`' . $composer['name'] . '`', '`' . $package['name'] . '`', 'PHP `' . $composer['require']['php'] . '`', 'Node `' . $package['engines']['node'] . '`'];
    foreach ($composer['require'] as $name => $version) {
        if (str_starts_with($name, 'fleetbase/') === true) {
            $needed[] = '`' . $name . '` `' . $version . '`';
        }
    }

    expect(docsMissing('docs/SETUP.md', $needed))->toBe([])
        ->and(docsMissing('README.md', ['flb install ' . $package['name']]))->toBe([]);
});

test('the README links the setup guide and the guide links the Intuit developer portal', function () {
    expect(docsMissing('README.md', ['](docs/SETUP.md']))->toBe([])
        ->and(docsMissing('docs/SETUP.md', ['](https://developer.intuit.com']))->toBe([]);
});

test('the contributing guide names every sandbox test variable', function () {
    preg_match('/const SANDBOX_VARIABLES = \[([^\]]*)\]/', docsText('server/tests/SandboxLiveTest.php'), $match);
    preg_match_all('/\'([A-Z0-9_]+)\'/', $match[1] ?? '', $names);

    expect($names[1])->toContain('QB_SANDBOX_REFRESH_TOKEN')
        ->and(docsMissing('CONTRIBUTING.md', $names[1]))->toBe([]);
});

test('links, images and anchors in the docs point at something that exists', function (string $file) {
    expect(MarkdownLinks::broken(docsRoot() . '/' . $file))->toBe([]);
})->with(['README.md', 'docs/SETUP.md', 'CONTRIBUTING.md']);

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
