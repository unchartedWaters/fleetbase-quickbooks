<?php

use Composer\Semver\Semver;
use Fleetbase\Quickbooks\Tests\Support\DocsSource;

// Fleetbase raises its own fleetbase/* requirements with each release; a range that
// excludes the next patch release stops `composer require` and the Docker start-up.
test('the Fleetbase package ranges accept the next releases of the same series', function (
    string $package,
    string $current,
    array $later,
    array $outside,
) {
    $composer   = json_decode(DocsSource::text('composer.json'), true);
    $constraint = $composer['require'][$package];

    expect(Semver::satisfies($current, $constraint))->toBeTrue();
    foreach ($later as $version) {
        expect(Semver::satisfies($version, $constraint))->toBeTrue();
    }
    foreach ($outside as $version) {
        expect(Semver::satisfies($version, $constraint))->toBeFalse();
    }
})->with([
    'ledger'   => ['fleetbase/ledger-api', '0.0.13', ['0.0.14', '0.0.30'], ['0.0.12', '0.1.0']],
    'fleetops' => ['fleetbase/fleetops-api', '0.6.73', ['0.6.74', '0.6.99'], ['0.6.72', '0.7.0']],
    'core'     => ['fleetbase/core-api', '1.6.0', ['1.6.68', '1.7.0'], ['1.5.9', '2.0.0']],
]);

// A package the engine imports at runtime must come with it; a devDependency is only there for its own tests.
test('every package the engine imports is a runtime dependency', function () {
    $package  = json_decode(DocsSource::text('package.json'), true);
    $declared = array_merge(array_keys($package['dependencies'] ?? []), array_keys($package['peerDependencies'] ?? []));
    $files    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DocsSource::path('addon')));
    $imported = [];
    foreach ($files as $file) {
        if ($file->isFile() === false || $file->getExtension() !== 'js') {
            continue;
        }
        preg_match_all("/from\\s+'([^'.][^']*)'/", (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $spec) {
            $parts = explode('/', $spec);
            $name  = str_starts_with($spec, '@') === true ? $parts[0] . '/' . ($parts[1] ?? '') : $parts[0];
            if (str_starts_with($name, '@ember/') === false) {
                $imported[$name] = true;
            }
        }
    }

    expect(array_keys($imported))->toContain('ember-concurrency')
        ->and(array_values(array_diff(array_keys($imported), $declared)))->toBe([]);
});
