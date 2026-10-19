<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$requestIdConnection = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'access_token' => 'token', 'environment' => 'sandbox'];

test('single writes carry a requestid that is the same for the same request and new for a changed one', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = $query;

        return Http::response(['Invoice' => ['Id' => '9', 'SyncToken' => '0']], 200);
    });
    $client = new QuickBooksClient();

    $other = array_merge($requestIdConnection, ['realm_id' => 'realm-2']);
    $client->withWriteKey('invoice:a', fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-1', 'Line' => []]));
    $client->withWriteKey('invoice:a', fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-1', 'Line' => []]));
    $client->withWriteKey('invoice:a', fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-2', 'Line' => []]));
    $client->withWriteKey('invoice:a', fn () => $client->createInvoice($other, ['DocNumber' => 'A-1', 'Line' => []]));
    $client->withWriteKey('invoice:a', fn () => $client->voidInvoice($requestIdConnection, '9', '0'));

    expect($sent)->toHaveCount(5)
        ->and($sent[0]['requestid'] ?? '')->not->toBe('')
        ->and(strlen($sent[0]['requestid']))->toBeLessThanOrEqual(36)
        ->and($sent[0]['requestid'])->toMatch('/^[A-Za-z0-9-]+$/')
        ->and($sent[1]['requestid'])->toBe($sent[0]['requestid'])
        ->and($sent[2]['requestid'])->not->toBe($sent[0]['requestid'])
        ->and($sent[3]['requestid'])->not->toBe($sent[0]['requestid'])
        ->and($sent[4]['requestid'] ?? '')->not->toBe('')
        ->and($sent[0]['minorversion'])->toBe('75')
        ->and($sent[4]['operation'])->toBe('void');
});

test('a batch with writes carries a requestid and a read-only batch or a query does not', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = $query;
        $rows   = [];
        foreach ($request->data()['BatchItemRequest'] ?? [] as $item) {
            $rows[] = isset($item['Query']) === true
                ? ['bId' => $item['bId'], 'QueryResponse' => ['Customer' => []]]
                : ['bId' => $item['bId'], 'Customer' => ['Id' => 'q-' . $item['bId'], 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows, 'QueryResponse' => ['Customer' => []]], 200);
    });
    $client = new QuickBooksClient();
    $writes = [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ];
    $reads = [
        ['bId' => 'q1', 'query' => "select * from Customer where Id = '1'"],
        ['bId' => 'q2', 'query' => "select * from Customer where Id = '2'"],
    ];

    $client->batch($requestIdConnection, $writes);
    $client->batch($requestIdConnection, $writes);
    $client->batch($requestIdConnection, $reads);
    $client->queryCustomers($requestIdConnection, 1, 10);

    expect($sent)->toHaveCount(4)
        ->and($sent[0]['requestid'] ?? '')->not->toBe('')
        ->and($sent[1]['requestid'])->toBe($sent[0]['requestid'])
        ->and($sent[2])->not->toHaveKey('requestid')
        ->and($sent[3])->not->toHaveKey('requestid');
});

test('each chunk of a large write batch has its own requestid', function () use ($requestIdConnection) {
    $ids = [];
    Http::fake(function (Request $request) use (&$ids) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $ids[] = $query['requestid'] ?? '';
        $rows  = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => 'q-' . $item['bId'], 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $items = [];
    for ($index = 0; $index < 40; $index++) {
        $items[] = ['bId' => 'c' . $index, 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Customer ' . $index]];
    }

    (new QuickBooksClient())->batch($requestIdConnection, $items);

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe('')
        ->and($ids[1])->not->toBe('')
        ->and($ids[1])->not->toBe($ids[0]);
});

test('two records with identical bodies get different requestids and a write with no record key gets none', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = $query;

        return Http::response(['Invoice' => ['Id' => '9', 'SyncToken' => '0']], 200);
    });
    $client = new QuickBooksClient();
    $body   = ['CustomerRef' => ['value' => '7'], 'TxnDate' => '2026-10-19', 'Line' => []];

    $client->withWriteKey('invoice:uuid-a', fn () => $client->createInvoice($requestIdConnection, $body));
    $client->withWriteKey('invoice:uuid-b', fn () => $client->createInvoice($requestIdConnection, $body));
    $client->withWriteKey('invoice:uuid-a', fn () => $client->createInvoice($requestIdConnection, $body));
    $client->createInvoice($requestIdConnection, $body);

    expect($sent)->toHaveCount(4)
        ->and($sent[0]['requestid'] ?? '')->not->toBe('')
        ->and($sent[1]['requestid'] ?? '')->not->toBe($sent[0]['requestid'])
        ->and($sent[2]['requestid'] ?? '')->toBe($sent[0]['requestid'])
        ->and($sent[3])->not->toHaveKey('requestid');
});

test('requestids fit the 36 characters Intuit allows on a batch', function () use ($requestIdConnection) {
    $ids = [];
    Http::fake(function (Request $request) use (&$ids) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $ids[] = $query['requestid'] ?? '';
        $rows  = [];
        foreach ($request->data()['BatchItemRequest'] ?? [] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => 'q-' . $item['bId'], 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows, 'Customer' => ['Id' => '1', 'SyncToken' => '0']], 200);
    });
    $client = new QuickBooksClient();
    $client->batch($requestIdConnection, [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ]);
    $client->withWriteKey('customer:uuid-a', fn () => $client->createCustomer($requestIdConnection, ['DisplayName' => 'Ada']));

    expect($ids)->toHaveCount(2)
        ->and(strlen($ids[0]))->toBeLessThanOrEqual(36)
        ->and(strlen($ids[1]))->toBeLessThanOrEqual(36)
        ->and($ids[0])->toMatch('/^[a-f0-9-]+$/');
});
