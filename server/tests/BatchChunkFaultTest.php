<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\ChunkFaultQuickBooks;
use Illuminate\Support\Facades\Http;

$chunkFaultConnection = [
    'company_uuid' => 'company-uuid',
    'realm_id'     => 'realm-1',
    'access_token' => 'token',
    'environment'  => 'sandbox',
];

test('a later chunk that fails with any status keeps the earlier chunk results and fails the rest with that status', function (int $status) use ($chunkFaultConnection) {
    $calls = 0;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$calls, $status) {
        $calls++;
        if ($calls > 1) {
            $fault = ['Fault' => ['Error' => [['Message' => 'Unavailable']]]];

            return Http::response($fault, $status, ['Retry-After' => '120']);
        }
        $rows = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => 'q-' . $item['bId'], 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $items = [];
    for ($index = 0; $index < 70; $index++) {
        $items[] = ['bId' => 'c' . $index, 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Customer ' . $index]];
    }

    $results = (new QuickBooksClient())->batch($chunkFaultConnection, $items);

    $accepted = array_filter($results, static fn (array $result): bool => $result['ok'] === true);
    $refused  = array_filter($results, static fn (array $result): bool => $result['ok'] === false);
    expect($calls)->toBe(2)
        ->and($results)->toHaveCount(70)
        ->and($accepted)->toHaveCount(30)
        ->and($results['c29']['body']['Id'])->toBe('q-c29')
        ->and($refused)->toHaveCount(40)
        ->and(array_unique(array_column($refused, 'status')))->toBe([$status])
        ->and(array_unique(array_column($refused, 'halt')))->toBe([false]);
})->with([503, 429, 500]);

test('a later chunk lost to a transport error keeps the earlier chunk results', function () use ($chunkFaultConnection) {
    $calls = 0;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$calls) {
        $calls++;
        if ($calls > 1) {
            throw new Illuminate\Http\Client\ConnectionException('timed out');
        }
        $rows = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => 'q-' . $item['bId'], 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $items = [];
    for ($index = 0; $index < 40; $index++) {
        $items[] = ['bId' => 'c' . $index, 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Customer ' . $index]];
    }

    $results = (new QuickBooksClient())->batch($chunkFaultConnection, $items);

    expect($results)->toHaveCount(40)
        ->and($results['c0']['ok'])->toBeTrue()
        ->and($results['c39']['ok'])->toBeFalse()
        ->and($results['c39']['status'])->toBe(0)
        ->and($results['c39']['error'])->toBe(QuickBooksClient::TRANSPORT_MESSAGE);
});

test('a failure of the first chunk still throws so nothing is recorded as written', function () use ($chunkFaultConnection) {
    Http::fake(['*' => Http::response(['Fault' => ['Error' => [['Message' => 'Unavailable']]]], 503)]);
    $items = [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ];

    expect(fn () => (new QuickBooksClient())->batch($chunkFaultConnection, $items))
        ->toThrow(Fleetbase\Quickbooks\Support\QuickBooksException::class);
});

test('a later chunk of a read-only batch that fails is still thrown, because reading again repeats no write', function () use ($chunkFaultConnection) {
    $calls = 0;
    Http::fake(function (Illuminate\Http\Client\Request $request) use (&$calls) {
        $calls++;
        if ($calls > 1) {
            return Http::response(['Fault' => ['Error' => [['Message' => 'Throttled']]]], 429);
        }
        $rows = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['bId' => $item['bId'], 'QueryResponse' => ['Customer' => []]];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $items = [];
    for ($index = 0; $index < 40; $index++) {
        $items[] = ['bId' => 'q' . $index, 'query' => "select * from Customer where Id = '" . $index . "'"];
    }

    expect(fn () => (new QuickBooksClient())->batch($chunkFaultConnection, $items))
        ->toThrow(Fleetbase\Quickbooks\Support\QuickBooksException::class);
});

test('with quickbooks numbering a failed second chunk keeps the first chunk links and the next run creates only the rest', function (int $status) {
    $client                              = new ChunkFaultQuickBooks();
    $client->failWriteChunk              = 2;
    $client->failStatus                  = $status;
    [$engine]                            = qbEngine($client);
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid'    => 'company-uuid',
        'realm_id'        => 'realm-1',
        'access_token'    => 'token',
        'environment'     => 'sandbox',
        'needs_reauth'    => false,
        'home_currency'   => 'USD',
        'default_item_id' => 'item-1',
    ];
    $ledger->customers['cust-1'] = ['uuid' => 'cust-1', 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'];
    $ledger->links[]             = [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => 'customer',
        'local_uuid'   => 'cust-1',
        'qbo_entity'   => 'Customer',
        'qbo_id'       => 'qbo-customer',
        'sync_token'   => '0',
    ];
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    for ($index = 1; $index <= 40; $index++) {
        $ledger->invoices['inv-' . $index] = [
            'uuid'          => 'inv-' . $index,
            'company_uuid'  => 'company-uuid',
            'customer_uuid' => 'cust-1',
            'number'        => 'FB-' . $index,
            'date'          => '2026-09-01',
            'due_date'      => '2026-09-15',
            'currency'      => 'USD',
            'tax'           => 0,
            'total'         => 1000,
            'status'        => 'sent',
            'items'         => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]],
        ];
        $ledger->pending[] = ['company_uuid' => 'company-uuid', 'local_type' => 'invoice', 'local_uuid' => 'inv-' . $index, 'status' => 'pending', 'attempts' => 0];
    }
    $settings     = qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks', 'payment_enabled' => false, 'wallet_enabled' => false]);
    $now          = time();
    $invoiceLinks = static fn (): int => count(array_filter($ledger->links, static fn (array $link): bool => $link['local_type'] === 'invoice'));

    $engine->runScheduled($ledger, 'company-uuid', $settings, $now, true);

    expect($client->invoiceCreates)->toBe(30)
        ->and($invoiceLinks())->toBe(30);
    if ($status === 429) {
        expect((int) ($ledger->connections['company-uuid']['rate_limited_until'] ?? 0))->toBeGreaterThan($now);
    }

    $ledger->connections['company-uuid']['rate_limited_until'] = null;
    foreach ($ledger->pending as $index => $row) {
        $ledger->pending[$index]['next_attempt_at'] = null;
    }
    $engine->runScheduled($ledger, 'company-uuid', $settings, $now + 7200, true);

    expect($client->invoiceCreates)->toBe(40)
        ->and($client->invoices)->toHaveCount(40)
        ->and($invoiceLinks())->toBe(40);
})->with([503, 429]);

test('a batch item that fails with a quickbooks server fault or a stale sync token is marked as worth retrying', function () use ($chunkFaultConnection) {
    Http::fake(function () {
        return Http::response(['BatchItemResponse' => [
            ['bId' => 'server', 'Fault' => ['type' => 'SystemFault', 'Error' => [['Message' => 'Internal error', 'code' => '10000']]]],
            ['bId' => 'stale', 'Fault' => ['type' => 'ValidationFault', 'Error' => [['Message' => 'Stale Object Error', 'code' => '5010']]]],
            ['bId' => 'name', 'Fault' => ['type' => 'ValidationFault', 'Error' => [['Message' => 'Duplicate Name Exists Error', 'code' => '6240']]]],
        ]], 200);
    });
    $items = [];
    foreach (['server', 'stale', 'name'] as $bId) {
        $items[] = ['bId' => $bId, 'operation' => 'update', 'entity' => 'Customer', 'payload' => ['Id' => '1', 'SyncToken' => '0']];
    }

    $results = (new QuickBooksClient())->batch($chunkFaultConnection, $items);
    $transient = fn (string $bId) => (new QuickBooksException($results[$bId]['status'], 'x'))->isTransient();

    expect($transient('server'))->toBeTrue()
        ->and($transient('stale'))->toBeTrue()
        ->and($transient('name'))->toBeFalse()
        ->and($results['name']['code'] ?? '')->toBe('6240');
});
