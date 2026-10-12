<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Support\Facades\Http;

function grantCredentials(): array
{
    return ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.test/cb', 'environment' => 'sandbox'];
}

test('a real invalid_grant from the token endpoint asks for a reconnect', function () {
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token invalid.'], 400),
    ]);

    $result = (new TokenRefresher(new QuickBooksClient()))->refresh(['refresh_token' => 'dead'], grantCredentials());

    expect($result['needs_reauth'])->toBeTrue()
        ->and($result)->not->toHaveKey('refresh_error');
});

test('a real invalid_client from the token endpoint keeps the credentials message', function () {
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $result = (new TokenRefresher(new QuickBooksClient()))->refresh(['refresh_token' => 'x'], grantCredentials());

    expect($result)->not->toHaveKey('needs_reauth')
        ->and($result['refresh_error'])->toBe(TokenRefresher::CREDENTIALS_MESSAGE);
});

test('the oauth error code reaches the exception message but the description does not', function () {
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'secret detail'], 400),
    ]);

    try {
        (new QuickBooksClient())->refresh(grantCredentials(), 'dead');
        $this->fail('expected exception');
    } catch (QuickBooksException $exception) {
        expect($exception->getMessage())->toBe('QuickBooks request failed with status 400: invalid_grant');
    }
});
