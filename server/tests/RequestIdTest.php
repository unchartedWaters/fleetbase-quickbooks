<?php

use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\QuickBooksHttpGate;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\CustomerCreateRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$requestIdConnection = [
    'company_uuid' => 'company-uuid',
    'realm_id'     => 'realm-1',
    'access_token' => 'token',
    'environment'  => 'sandbox',
];

test('single writes carry a requestid that is the same for the same request'
. ' and new for a changed one', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = $query;

        return Http::response(['Invoice' => ['Id' => '9', 'SyncToken' => '0']], 200);
    });
    $client = new QuickBooksClient();

    $other = array_merge($requestIdConnection, ['realm_id' => 'realm-2']);
    $client->withWriteKey(
        'invoice:a',
        fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-1', 'Line' => []])
    );
    $client->withWriteKey(
        'invoice:a',
        fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-1', 'Line' => []])
    );
    $client->withWriteKey(
        'invoice:a',
        fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-2', 'Line' => []])
    );
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

test('a batch with writes carries a requestid'
. ' and a read-only batch or a query does not', function () use ($requestIdConnection) {
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
        $items[] = [
            'bId'       => 'c' . $index,
            'operation' => 'create',
            'entity'    => 'Customer',
            'payload'   => ['DisplayName' => 'Customer ' . $index],
        ];
    }

    (new QuickBooksClient())->batch($requestIdConnection, $items);

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe('')
        ->and($ids[1])->not->toBe('')
        ->and($ids[1])->not->toBe($ids[0]);
});

test('two records with identical bodies get different requestids'
. ' and a write with no record key gets none', function () use ($requestIdConnection) {
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

test('requestids are at most 36 characters', function () use ($requestIdConnection) {
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
    $client->withWriteKey(
        'customer:uuid-a',
        fn () => $client->createCustomer($requestIdConnection, ['DisplayName' => 'Ada'])
    );

    expect($ids)->toHaveCount(2)
        ->and(strlen($ids[0]))->toBeLessThanOrEqual(36)
        ->and(strlen($ids[1]))->toBeLessThanOrEqual(36)
        ->and($ids[0])->toMatch('/^[a-f0-9-]+$/');
});

test('a batch keeps every requestid and bId pair within the 50 characters Intuit allows'
. ' and returns results under the caller bIds', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $wire   = array_column($request->data()['BatchItemRequest'] ?? [], 'bId');
        $sent[] = ['requestid' => $query['requestid'] ?? '', 'bIds' => $wire];
        $rows   = [];
        foreach ($wire as $index => $bId) {
            $rows[] = ['bId' => $bId, 'Customer' => ['Id' => (string) (90 + $index), 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $client = new QuickBooksClient();
    $items  = [
        [
            'bId'       => 'customer:46d5412d-a50d-46cc-a023-294340f62685',
            'operation' => 'create',
            'entity'    => 'Customer',
            'payload'   => ['DisplayName' => 'Northwind'],
        ],
        [
            'bId'       => 'customer:9b1f2c3d-1111-4222-8333-944455566677',
            'operation' => 'create',
            'entity'    => 'Customer',
            'payload'   => ['DisplayName' => 'Bluewater'],
        ],
    ];

    $results = $client->batch($requestIdConnection, $items);
    $client->batch($requestIdConnection, $items);

    expect($sent)->toHaveCount(2)
        ->and($sent[0]['requestid'])->not->toBe('')
        ->and($sent[1]['requestid'])->toBe($sent[0]['requestid'])
        ->and(array_keys($results))->toBe(array_column($items, 'bId'))
        ->and($results['customer:46d5412d-a50d-46cc-a023-294340f62685']['body']['Id'])->toBe('90')
        ->and($results['customer:9b1f2c3d-1111-4222-8333-944455566677']['body']['Id'])->toBe('91');
    foreach ($sent[0]['bIds'] as $bId) {
        expect(strlen($sent[0]['requestid'] . $bId))->toBeLessThanOrEqual(50);
    }
});

test('two records with identical batch bodies'
. ' still get different requestids', function () use ($requestIdConnection) {
    $ids = [];
    Http::fake(function (Request $request) use (&$ids) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $ids[] = $query['requestid'] ?? '';
        $rows  = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Account' => ['Id' => '7', 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $client = new QuickBooksClient();
    $body   = ['Name' => 'Personal Wallet', 'AccountType' => 'Other Current Asset'];

    foreach (['11111111-2222-4333-8444-555555555555', '66666666-7777-4888-9999-000000000000'] as $uuid) {
        $client->batch($requestIdConnection, [[
            'bId'       => 'wallet:' . $uuid,
            'operation' => 'create',
            'entity'    => 'Account',
            'payload'   => $body,
        ]]);
    }

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe('')
        ->and($ids[1])->not->toBe($ids[0]);
});

test('a batch fault row without a bId keeps Intuit\'s message', function () use ($requestIdConnection) {
    $detail = 'Business Validation Error: You cannot enter more than 50 characters in the request_id field.';
    Http::fake(function (Request $request) use ($detail) {
        $rows = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['Fault' => ['Error' => [[
                'Message' => 'A business validation error has occurred while processing your request',
                'Detail'  => $detail,
                'code'    => '6000',
            ]], 'type' => 'ValidationFault']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });

    $results = (new QuickBooksClient())->batch($requestIdConnection, [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ]);

    expect($results['a']['ok'])->toBeFalse()
        ->and($results['a']['error'])->toBe($detail)
        ->and($results['b']['error'])->toBe($detail)
        ->and($results['a']['code'] ?? null)->toBe('6000');
});

test('a batch refused with an authentication fault that names no item'
. ' is treated as a refused access token', function () use ($requestIdConnection) {
    Http::fake(fn () => Http::response(['BatchItemResponse' => [
        ['Fault' => ['Error' => [['Message' => 'AuthenticationFailed', 'code' => '3200']], 'type' => 'AUTHENTICATION']],
    ]], 200));

    expect(fn () => (new QuickBooksClient())->batch($requestIdConnection, [
        ['bId' => 'a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
        ['bId' => 'b', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Bea']],
    ]))->toThrow(Fleetbase\Quickbooks\Support\QuickBooksException::class);
});

test('the engine http gate passes the write key'
. ' to the client that sends the request', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = $query;

        return Http::response(['Invoice' => ['Id' => '9', 'SyncToken' => '0']], 200);
    });
    $engine = new SyncEngine(
        new QuickBooksClient(),
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(fn (int $wait): int => $wait)
    );
    $gate = new QuickBooksHttpGate(new QuickBooksClient(), $engine);

    $gate->withWriteKey('invoice:a', fn () => $gate->createInvoice($requestIdConnection, ['Line' => []]));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['requestid'] ?? '')->not->toBe('');
});

test('a single pending customer create carries a requestid', function () use ($requestIdConnection) {
    $posts = [];
    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'GET') {
            return Http::response(['QueryResponse' => []], 200);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $posts[] = ['path' => (string) parse_url($request->url(), PHP_URL_PATH), 'query' => $query];

        return Http::response(['Customer' => ['Id' => '77', 'SyncToken' => '0']], 200);
    });
    $engine = new SyncEngine(
        new QuickBooksClient(),
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(fn (int $wait): int => $wait)
    );
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = array_merge($requestIdConnection, [
        'needs_reauth'    => false,
        'home_currency'   => 'USD',
        'default_item_id' => 'item-1',
    ]);
    $ledger->customers['cust-1'] = [
        'uuid'         => 'cust-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ada',
        'email'        => 'ada@example.test',
    ];
    $ledger->pending[] = [
        'company_uuid' => 'company-uuid',
        'local_type'   => 'customer',
        'local_uuid'   => 'cust-1',
        'status'       => 'pending',
        'attempts'     => 0,
    ];

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(), 1_800_000_000);

    $creates = array_values(array_filter($posts, fn (array $post): bool => str_ends_with($post['path'], '/customer')));
    expect($creates)->toHaveCount(1)
        ->and($creates[0]['query']['requestid'] ?? '')->not->toBe('');
});

test('a retried write keeps its requestid on a later day, so a create lost before midnight'
. ' is not saved twice', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = $query['requestid'] ?? '';
        $rows   = [];
        foreach ($request->data()['BatchItemRequest'] ?? [] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => '5', 'SyncToken' => '0']];
        }

        return Http::response(['Invoice' => ['Id' => '9', 'SyncToken' => '0'], 'BatchItemResponse' => $rows], 200);
    });
    $client = new QuickBooksClient();

    // Fixed values: the id depends on the realm, the request, the record and the body only.
    $client->withWriteKey(
        'invoice:a',
        fn () => $client->createInvoice($requestIdConnection, ['DocNumber' => 'A-1', 'Line' => []])
    );
    $client->batch($requestIdConnection, [
        ['bId' => 'customer:a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'Ada']],
    ]);

    expect($sent)->toBe(['132717ef-a41a-009c-1c30-9bc218148d53', '3c268bf8-4175-13f0-9cd9-26f1385189fe']);
});

test('a create retried from the same pending row reuses its requestid,'
. ' and a create from a new pending row gets a new one', function () {
    $first   = CustomerCreateRun::requestIds(['cust-1' => 'row-1']);
    $retried = CustomerCreateRun::requestIds(['cust-1' => 'row-1']);
    // A record deleted in QuickBooks and created again is driven by a new pending row.
    $recreated = CustomerCreateRun::requestIds(['cust-1' => 'row-2']);

    expect($first)->toHaveCount(1)
        ->and($first[0])->not->toBe('')
        ->and($retried)->toBe($first)
        ->and($recreated)->toHaveCount(1)
        ->and($recreated[0])->not->toBe($first[0]);
});

test('a block of creates retried from the same pending rows reuses its requestid,'
. ' and one from new pending rows gets a new one', function () {
    $first     = CustomerCreateRun::requestIds(['cust-1' => 'row-1', 'cust-2' => 'row-2']);
    $retried   = CustomerCreateRun::requestIds(['cust-1' => 'row-1', 'cust-2' => 'row-2']);
    $recreated = CustomerCreateRun::requestIds(['cust-1' => 'row-1', 'cust-2' => 'row-3']);

    expect($first)->toHaveCount(1)
        ->and($first[0])->not->toBe('')
        ->and($retried)->toBe($first)
        ->and($recreated)->toHaveCount(1)
        ->and($recreated[0])->not->toBe($first[0]);
});

test('a batch item key changes the requestid and is not sent to quickbooks', function () use ($requestIdConnection) {
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $sent[] = ['id' => $query['requestid'] ?? '', 'items' => $request->data()['BatchItemRequest']];
        $rows   = [];
        foreach ($request->data()['BatchItemRequest'] as $item) {
            $rows[] = ['bId' => $item['bId'], 'Customer' => ['Id' => '5', 'SyncToken' => '0']];
        }

        return Http::response(['BatchItemResponse' => $rows], 200);
    });
    $client = new QuickBooksClient();
    $item   = [
        'bId' => 'customer:a', 'operation' => 'create', 'entity' => 'Customer', 'payload' => ['DisplayName' => 'A'],
    ];

    $client->batch($requestIdConnection, [$item + ['key' => 'customer:a:row-1']]);
    $client->batch($requestIdConnection, [$item + ['key' => 'customer:a:row-1']]);
    $client->batch($requestIdConnection, [$item + ['key' => 'customer:a:row-2']]);

    expect($sent[1]['id'])->toBe($sent[0]['id'])
        ->and($sent[2]['id'])->not->toBe($sent[0]['id'])
        ->and($sent[0]['items'])
        ->toBe([['bId' => 'customer:a', 'operation' => 'create', 'Customer' => ['DisplayName' => 'A']]]);
});
