<?php

use Fleetbase\Quickbooks\Tests\Support\DocsSource;
use Symfony\Component\Yaml\Yaml;

test('a tag release publishes only after the main-branch check and both test workflows', function () {
    $deploy = Yaml::parse(DocsSource::text('.github/workflows/deploy.yml'));
    $jobs   = $deploy['jobs'];
    $check  = implode("\n", array_column($jobs['check']['steps'], 'run'));

    expect($jobs['php_tests']['uses'])->toBe('./.github/workflows/server.yml')
        ->and($jobs['ember_tests']['uses'])->toBe('./.github/workflows/ember.yml')
        ->and($jobs['ember_tests']['with']['test_only'])->toBeTrue()
        ->and($jobs['check']['steps'][0]['with']['fetch-depth'])->toBe(0)
        ->and($check)->toContain('git merge-base --is-ancestor HEAD origin/main');
    foreach (['npmjs', 'registry_publish'] as $job) {
        expect($jobs[$job]['needs'])->toBe(['check', 'php_tests', 'ember_tests']);
    }
});

test('the test workflows can be called by the release and keep their own triggers', function (string $file) {
    $workflow = Yaml::parse(DocsSource::text('.github/workflows/' . $file));
    $on       = $workflow['on'];

    expect($on)->toHaveKeys(['push', 'pull_request', 'workflow_call'])
        ->and($on['push']['branches'])->toBe(['main', 'develop'])
        ->and($workflow['permissions'])->toBe(['contents' => 'read']);
})->with(['server.yml', 'ember.yml']);

test('the GitHub Packages publish skips when the release calls the Ember tests', function () {
    $ember   = Yaml::parse(DocsSource::text('.github/workflows/ember.yml'));
    $publish = $ember['jobs']['github_publish'];

    expect($publish['if'])->toContain('!inputs.test_only')
        ->and($publish['permissions'])->toBe(['contents' => 'read', 'packages' => 'write']);
});

test('release workflows grant least privilege and pin the registry tools', function () {
    $release = Yaml::parse(DocsSource::text('.github/workflows/create-release.yml'));
    $deploy  = Yaml::parse(DocsSource::text('.github/workflows/deploy.yml'));
    $install = '';
    foreach ($deploy['jobs']['registry_publish']['steps'] as $step) {
        if (str_starts_with($step['run'] ?? '', 'npm i -g ') === true) {
            $install = $step['run'];
        }
    }

    expect($release['permissions'])->toBe(['contents' => 'read'])
        ->and($release['jobs']['create']['permissions'])->toBe(['contents' => 'write'])
        ->and($deploy['permissions'])->toBe(['contents' => 'read'])
        ->and($install)->toMatch('/^npm i -g @fleetbase\/cli@\d+\.\d+\.\d+ npm-cli-login@\d+\.\d+\.\d+$/');
});

test('the GitHub Release and the GitHub Packages publish'
. ' also run only for a tag on main', function (string $file, string $job) {
    $workflow = Yaml::parse(DocsSource::text('.github/workflows/' . $file));
    $steps    = $workflow['jobs'][$job]['steps'];
    $runs     = implode("\n", array_column($steps, 'run'));

    expect($steps[0]['with']['fetch-depth'] ?? null)->toBe(0)
        ->and($runs)->toContain('git merge-base --is-ancestor HEAD origin/main');
})->with([
    ['create-release.yml', 'create'],
    ['ember.yml', 'github_publish'],
]);
