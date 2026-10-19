<?php

use Fleetbase\Quickbooks\Support\WebhookPayload;

test('a legacy merge also reports the merged-away id and the record it went into', function () {
    $time = '2026-10-19T10:00:00Z';
    $body = json_encode(['eventNotifications' => [[
        'realmId'         => 'realm-1',
        'dataChangeEvent' => ['entities' => [
            [
                'name'        => 'Customer',
                'id'          => '2',
                'operation'   => 'Merge',
                'lastUpdated' => $time,
                'deletedId'   => '1',
            ],
        ]],
    ]]]);

    expect(WebhookPayload::changes((string) $body))->toBe([
        ['realm' => 'realm-1', 'name' => 'Customer', 'id' => '2', 'operation' => 'Merge', 'time' => $time],
        [
            'realm'       => 'realm-1',
            'name'        => 'Customer',
            'id'          => '1',
            'operation'   => 'MergedAway',
            'time'        => $time,
            'merged_into' => '2',
        ],
    ]);
});

test('a cloudevents merge reads deletedId from data in either spelling', function (string $key) {
    $time = '2026-10-19T10:00:00Z';
    $body = json_encode([[
        'specversion'     => '1.0',
        'id'              => 'evt-1',
        'type'            => 'qbo.customer.merged.v1',
        'time'            => $time,
        'intuitentityid'  => '2',
        'intuitaccountid' => 'realm-1',
        'data'            => [$key => '1'],
    ]]);

    expect(WebhookPayload::changes((string) $body))->toBe([
        ['realm' => 'realm-1', 'name' => 'customer', 'id' => '2', 'operation' => 'Merge', 'time' => $time],
        [
            'realm'       => 'realm-1',
            'name'        => 'customer',
            'id'          => '1',
            'operation'   => 'MergedAway',
            'time'        => $time,
            'merged_into' => '2',
        ],
    ]);
})->with(['deletedId', 'deletedid']);

test('a merge without a usable merged-away id adds nothing', function (mixed $deleted) {
    $body = json_encode(['eventNotifications' => [[
        'realmId'         => 'realm-1',
        'dataChangeEvent' => ['entities' => [
            ['name' => 'Customer', 'id' => '2', 'operation' => 'Merge', 'deletedId' => $deleted],
        ]],
    ]]]);

    expect(WebhookPayload::changes((string) $body))->toBe([
        ['realm' => 'realm-1', 'name' => 'Customer', 'id' => '2', 'operation' => 'Merge'],
    ]);
})->with([[''], [null], [['1']], ['2']]);

test('deletedId on an update is not read as a merge', function () {
    $body = json_encode(['eventNotifications' => [[
        'realmId'         => 'realm-1',
        'dataChangeEvent' => ['entities' => [
            ['name' => 'Customer', 'id' => '2', 'operation' => 'Update', 'deletedId' => '1'],
        ]],
    ]]]);

    expect(WebhookPayload::changes((string) $body))->toHaveCount(1);
});
