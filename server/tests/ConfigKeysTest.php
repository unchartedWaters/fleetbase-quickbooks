<?php

use Fleetbase\Quickbooks\Services\SettingsStore;

test('the config file advertises no credential, verifier or version keys', function () {
    $source = (string) file_get_contents(__DIR__ . '/../config/quickbooks.php');

    expect($source)->not->toContain("'client_id'")
        ->and($source)->not->toContain("'client_secret'")
        ->and($source)->not->toContain("'redirect_uri'")
        ->and($source)->not->toContain("'webhook_verifier'")
        ->and($source)->not->toContain("'version'")
        ->and($source)->toContain("'environment'");
});

test('envAuth carries only the environment', function () {
    config()->set('quickbooks.environment', 'sandbox');

    $auth = (new SettingsStore())->envAuth();

    expect(array_keys($auth))->toBe(['environment'])
        ->and($auth['environment'])->toBe('sandbox')
        ->and(method_exists(SettingsStore::class, 'envWebhookVerifier'))->toBeFalse();
});
