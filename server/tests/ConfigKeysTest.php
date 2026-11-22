<?php

test('the config file advertises no credential, verifier, environment or version keys', function () {
    $source = (string) file_get_contents(__DIR__ . '/../config/quickbooks.php');

    expect($source)->not->toContain("'client_id'")
        ->and($source)->not->toContain("'client_secret'")
        ->and($source)->not->toContain("'redirect_uri'")
        ->and($source)->not->toContain("'webhook_verifier'")
        ->and($source)->not->toContain("'version'")
        // Sandbox or production is chosen by each organization when it connects, not configured.
        ->and($source)->not->toContain("'environment'");
});
