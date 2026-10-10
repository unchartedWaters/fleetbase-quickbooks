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

/**
 * @return array<int, array<string, mixed>>
 */
function batchAuthManyItems(int $count): array
{
    $items = [];
    for ($index = 0; $index < $count; $index++) {
        $items[] = ['bId' => 'c' . $index, 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Customer ' . $index]];
    }

    return $items;
}

/**
 * @param array<string, mixed> $request
 *
 * @return array<int, array<string, mixed>>
 */
function batchAuthAccepted(Illuminate\Http\Client\Request $request): array
{
    $rows = [];
    foreach ($request->data()['BatchItemRequest'] ?? [] as $item) {
        $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => 'q-' . $item['bId'], 'SyncToken' => '0']];
    }

    return $rows;
}

foreach (['every item refused in the body' => 'faults', 'HTTP 401' => 'http'] as $batchAuthLabel => $batchAuthMode) {
    test('a later chunk refused with ' . $batchAuthLabel . ' keeps the earlier chunk results and sends nothing more', function () use ($batchAuthMode) {
        Http::swap(new Illuminate\Http\Client\Factory());
        $calls = 0;
        Http::fake(function (Illuminate\Http\Client\Request $request) use (&$calls, $batchAuthMode) {
            $calls++;
            if ($calls === 1) {
                return Http::response(['BatchItemResponse' => batchAuthAccepted($request)], 200);
            }
            if ($batchAuthMode === 'http') {
                return Http::response(['Fault' => ['Error' => [['Message' => 'AuthenticationFailed', 'code' => '3200']]]], 401);
            }
            $rows = [];
            foreach ($request->data()['BatchItemRequest'] as $item) {
                $rows[] = batchAuthFault($item['bId']);
            }

            return Http::response(['BatchItemResponse' => $rows], 200);
        });

        $results = (new QuickBooksClient())->batch(batchAuthConnection(), batchAuthManyItems(70));

        $accepted = array_filter($results, static fn (array $result): bool => $result['ok'] === true);
        $refused  = array_filter($results, static fn (array $result): bool => $result['ok'] === false);
        expect($calls)->toBe(2)
            ->and($results)->toHaveCount(70)
            ->and($accepted)->toHaveCount(30)
            ->and($results['c0']['body']['Id'])->toBe('q-c0')
            ->and($refused)->toHaveCount(40)
            // Not a halt: halting on 401 asks the user to reconnect without trying a refresh.
            ->and(array_unique(array_column($refused, 'halt')))->toBe([false]);
    });
}
