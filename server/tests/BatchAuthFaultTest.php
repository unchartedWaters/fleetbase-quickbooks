<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function batchAuthConnection(): array
{
    return ['company_uuid' => 'company-uuid', 'realm_id' => '9341453000000001', 'access_token' => 'token', 'environment' => 'sandbox'];
}

/**
 * @return array<string, mixed>
 */
function batchAuthFault(string $bId, string $type = 'AUTHENTICATION', string $code = '3200'): array
{
    return [
        'bId'   => $bId,
        'Fault' => ['type' => $type, 'Error' => [['Message' => 'message=AuthenticationFailed; errorCode=003200', 'Detail' => 'Token expired', 'code' => $code]]],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function batchAuthItems(): array
{
    return [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ];
}

test('a batch whose every item failed authentication is a 401 so the token is refreshed and the batch retried', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['sandbox-quickbooks.api.intuit.com/*' => Http::response(['BatchItemResponse' => [batchAuthFault('a'), batchAuthFault('b')]], 200)]);

    try {
        (new QuickBooksClient())->batch(batchAuthConnection(), batchAuthItems());
        expect(false)->toBeTrue();
    } catch (QuickBooksException $exception) {
        expect($exception->isUnauthorized())->toBeTrue()
            ->and($exception->getMessage())->not->toContain('Token expired');
    }
});

test('the authentication fault code alone is enough to treat the item as unauthorized', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['sandbox-quickbooks.api.intuit.com/*' => Http::response(['BatchItemResponse' => [batchAuthFault('a', 'SERVICE'), batchAuthFault('b', 'SERVICE')]], 200)]);

    expect(fn () => (new QuickBooksClient())->batch(batchAuthConnection(), batchAuthItems()))
        ->toThrow(QuickBooksException::class);
});

test('a batch where only some items failed authentication is not retried, so the other writes are not repeated', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['sandbox-quickbooks.api.intuit.com/*' => Http::response(['BatchItemResponse' => [
        ['bId' => 'a', 'Customer' => ['Id' => '9', 'SyncToken' => '0', 'DisplayName' => 'Ada']],
        batchAuthFault('b'),
    ]], 200)]);

    $results = (new QuickBooksClient())->batch(batchAuthConnection(), batchAuthItems());

    expect($results['a']['ok'])->toBeTrue()
        ->and($results['b']['ok'])->toBeFalse();
});

test('ordinary validation faults on every item are still item errors, not a 401', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['sandbox-quickbooks.api.intuit.com/*' => Http::response(['BatchItemResponse' => [
        batchAuthFault('a', 'ValidationFault', '6240'),
        batchAuthFault('b', 'ValidationFault', '6240'),
    ]], 200)]);

    $results = (new QuickBooksClient())->batch(batchAuthConnection(), batchAuthItems());

    expect($results['a']['ok'])->toBeFalse()
        ->and($results['a']['status'])->toBe(400)
        ->and($results['b']['ok'])->toBeFalse();
});
