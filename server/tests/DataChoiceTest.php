<?php

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\CompanyReset;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Tests\Support\ChoiceController;
use Fleetbase\Quickbooks\Tests\Support\DataChoiceDatabase;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Fleetbase\Quickbooks\Tests\Support\TestCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const QB_SQLITE_SKIP = 'PDO SQLite is unavailable.';

test('a code is random, six characters, case-insensitive and used up by the right answer', function () {
    $reset = new CompanyReset(new MemorySettingsStore());
    $code  = $reset->issueCode('company-uuid', 'user-uuid');

    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/')
        ->and($reset->consumeCode('company-uuid', 'other-user', $code))->toBeFalse()
        ->and($reset->consumeCode('other-company', 'user-uuid', $code))->toBeFalse()
        ->and($reset->consumeCode('company-uuid', 'user-uuid', 'WRONG1'))->toBeFalse()
        ->and($reset->consumeCode('company-uuid', 'user-uuid', ' ' . strtolower($code) . ' '))->toBeTrue()
        ->and($reset->consumeCode('company-uuid', 'user-uuid', $code))->toBeFalse();

    $first  = $reset->issueCode('company-uuid', 'user-uuid');
    $second = $reset->issueCode('company-uuid', 'user-uuid');
    expect($reset->consumeCode('company-uuid', 'user-uuid', $second))->toBeTrue();
    expect($first)->toMatch('/^[A-Z2-9]{6}$/');
});

test('a code is refused for a request without a signed-in user', function () {
    $reset = new CompanyReset(new MemorySettingsStore());
    $reset->issueCode('company-uuid', '');

    expect($reset->consumeCode('company-uuid', '', ''))->toBeFalse();
});

test('the import marker names QuickBooks, the company and the customer', function () {
    expect(CompanyReset::importMarker('realm-1', '42'))->toBe([
        'quickbooks_import' => ['source' => 'quickbooks', 'realm_id' => 'realm-1', 'qbo_id' => '42'],
    ]);
});

test('link counts are per kind for this organization and count the imported customers', function () {
    DataChoiceDatabase::with(function () {
        DataChoiceDatabase::seed();
        $counts = DataChoiceDatabase::reset(new MemorySettingsStore())->linkCounts('company-uuid');

        expect($counts)->toBe([
            'customers' => 2,
            'invoices'  => 1,
            'wallets'   => 1,
            'products'  => 1,
            'total'     => 5,
            'imported'  => 1,
        ]);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('clearing removes this organization sync data at each level and only customers marked as imported', function (
    string $level,
    bool $settingsReset,
    int $customersLeft,
) {
    DataChoiceDatabase::with(function () use ($level, $settingsReset, $customersLeft) {
        DataChoiceDatabase::seed();
        $store                                                   = new MemorySettingsStore();
        $store->rows[SettingsKeys::companySync('company-uuid')]  = ['customer_direction' => 'both'];
        $store->rows[SettingsKeys::companySync('other-company')] = ['customer_direction' => 'both'];

        DataChoiceDatabase::reset($store)->clear('company-uuid', $level, false);

        $rows  = DataChoiceDatabase::rows('company-uuid');
        $other = DataChoiceDatabase::rows('other-company');
        expect($rows['quickbooks_connections'])->toBe(0)
            ->and($rows['quickbooks_links'])->toBe(0)
            ->and($rows['quickbooks_pending_syncs'])->toBe(0)
            ->and($rows['quickbooks_sync_batches'])->toBe(0)
            ->and($rows['quickbooks_sync_attempts'])->toBe(0)
            ->and($rows['test_customers'])->toBe($customersLeft)
            ->and($rows['test_places'])->toBe($customersLeft)
            ->and($other)->toBe([
                'quickbooks_connections'   => 1,
                'quickbooks_links'         => 5,
                'quickbooks_pending_syncs' => 1,
                'quickbooks_sync_batches'  => 1,
                'quickbooks_sync_attempts' => 1,
                'test_customers'           => 1,
                'test_places'              => 1,
            ])
            ->and($store->rows[SettingsKeys::companyLastRealm('company-uuid')])->toBe([])
            ->and($store->rows[SettingsKeys::companySync('other-company')])->toBe(['customer_direction' => 'both'])
            ->and($store->rows[SettingsKeys::companySync('company-uuid')] ?? null)
            ->toBe($settingsReset === true ? [] : ['customer_direction' => 'both']);
        if ($customersLeft === 1) {
            expect(TestCustomer::query()->where('company_uuid', 'company-uuid')->value('uuid'))->toBe('native-1');
        }
    });
})->with([
    'sync data only'          => [CompanyReset::SYNC, false, 2],
    'plus the sync settings'  => [CompanyReset::SETTINGS, true, 2],
    'plus imported customers' => [CompanyReset::EVERYTHING, true, 1],
])->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('clearing while a switch is under way keeps the connection row', function () {
    DataChoiceDatabase::with(function () {
        DataChoiceDatabase::seed();

        DataChoiceDatabase::reset(new MemorySettingsStore())->clear('company-uuid', CompanyReset::SYNC, true);

        expect(DataChoiceDatabase::rows('company-uuid')['quickbooks_connections'])->toBe(1)
            ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_links'])->toBe(0);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('disconnect with a level needs the code shown to the user and then clears the data', function () {
    DataChoiceDatabase::with(function () {
        DataChoiceDatabase::seed();
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::fake(['developer.api.intuit.com/*' => Http::response('', 200)]);
        session(['company' => 'company-uuid', 'user' => 'user-uuid']);
        $store      = new MemorySettingsStore();
        $controller = ChoiceController::make($store);

        $refused = $controller->disconnectWith('everything', 'ABC234');
        expect($refused->getStatusCode())->toBe(422)
            ->and(DataChoiceDatabase::rows('company-uuid'))->toBe([
                'quickbooks_connections'   => 1,
                'quickbooks_links'         => 5,
                'quickbooks_pending_syncs' => 1,
                'quickbooks_sync_batches'  => 1,
                'quickbooks_sync_attempts' => 1,
                'test_customers'           => 2,
                'test_places'              => 2,
            ])
            ->and(Http::recorded())->toHaveCount(0);

        $code = $controller->resetCode(Request::create('/reset-code', 'POST'))->getData(true)['code'];
        $done = $controller->disconnectWith('everything', $code);

        expect($done->getStatusCode())->toBe(200)
            ->and(DataChoiceDatabase::rows('company-uuid'))->toBe([
                'quickbooks_connections'   => 0,
                'quickbooks_links'         => 0,
                'quickbooks_pending_syncs' => 0,
                'quickbooks_sync_batches'  => 0,
                'quickbooks_sync_attempts' => 0,
                'test_customers'           => 1,
                'test_places'              => 1,
            ])
            ->and(DataChoiceDatabase::rows('other-company')['quickbooks_links'])->toBe(5)
            ->and($controller->disconnectWith('sync', $code)->getStatusCode())->toBe(422);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('a plain disconnect keeps the links, and an operator cannot ask for a level', function () {
    DataChoiceDatabase::with(function () {
        DataChoiceDatabase::seed();
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::fake(['developer.api.intuit.com/*' => Http::response('', 200)]);
        session(['company' => 'company-uuid', 'user' => 'user-uuid']);

        $operator = ChoiceController::make(new MemorySettingsStore(), false);
        $code     = (new CompanyReset(new MemorySettingsStore()))->issueCode('company-uuid', 'user-uuid');
        $refused  = $operator->disconnectWith('sync', $code);
        expect($refused->getStatusCode())->toBe(422)
            ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_links'])->toBe(5);

        $kept = $operator->disconnect(Request::create('/disconnect', 'POST'));
        expect($kept->getStatusCode())->toBe(200)
            ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_connections'])->toBe(0)
            ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_links'])->toBe(5);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('another company or the other environment leaves links behind, the same company does not', function (
    string $realm,
    string $environment,
    bool $leaves,
) {
    DataChoiceDatabase::with(function () use ($realm, $environment, $leaves) {
        DataChoiceDatabase::seed();
        Connection::query()->where('company_uuid', 'company-uuid')->update(['environment' => $environment]);
        session(['company' => 'company-uuid', 'user' => 'user-uuid']);

        expect(ChoiceController::make(new MemorySettingsStore())->leaves('company-uuid', $realm))->toBe($leaves);
    });
})->with([
    'same company and environment'    => ['9341453000000001', 'sandbox', false],
    'another company'                 => ['9341453000000009', 'sandbox', true],
    'same company, other environment' => ['9341453000000001', 'production', true],
])->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('an environment change with nothing linked leaves nothing behind', function () {
    DataChoiceDatabase::with(function () {
        DataChoiceDatabase::seed();
        DB::table('quickbooks_links')->delete();
        Connection::query()->where('company_uuid', 'company-uuid')->update(['environment' => 'production']);

        $controller = ChoiceController::make(new MemorySettingsStore());

        expect($controller->leaves('company-uuid', '9341453000000001'))->toBeFalse();
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);

test('connecting another company asks first, keeps the handle and clears only on the right code', function () {
    DataChoiceDatabase::with(function () {
        DataChoiceDatabase::seed();
        session(['company' => 'company-uuid', 'user' => 'user-uuid']);
        $previous = qbRememberConfig(['app.url', 'fleetbase.url', 'fleetbase.console.host', 'quickbooks.console_host']);
        config()->set('app.url', 'https://example.com');
        config()->set('fleetbase.url', null);
        config()->set('quickbooks.console_host', 'https://fleet.example.com');
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::fake([
            'oauth.platform.intuit.com/*'         => Http::response([
                'access_token'  => 'a',
                'refresh_token' => 'r',
                'expires_in'    => 3600,
            ]),
            'sandbox-quickbooks.api.intuit.com/*' => Http::response([
                'CompanyInfo'   => ['CompanyName' => 'Other', 'Country' => 'US'],
                'Preferences'   => ['CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]],
                'QueryResponse' => ['Item' => [], 'Account' => [['Id' => '79', 'AccountType' => 'Income']]],
            ]),
        ]);
        $controller = ChoiceController::make(new MemorySettingsStore());

        try {
            $state   = (string) $controller->start(Request::create('/oauth/start', 'POST'))->getData(true)['state'];
            $asked   = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'state' => $state, 'code' => 'code', 'realmId' => '9341453000000009',
            ]));
            $answer    = $asked->getData(true);
            $exchanged = Http::recorded(fn ($request) => str_contains($request->url(), 'oauth.platform.intuit.com'));

            expect($asked->getStatusCode())->toBe(200)
                ->and($answer['connected'])->toBeFalse()
                ->and($answer['choice_required'])->toBeTrue()
                ->and($answer['code_rejected'])->toBeFalse()
                ->and($answer['code'])->toMatch('/^[A-Z2-9]{6}$/')
                ->and($answer['linked']['total'])->toBe(5)
                ->and($answer['linked']['imported'])->toBe(1)
                ->and($exchanged)->toHaveCount(0)
                ->and($controller->saved)->toBeNull()
                ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_links'])->toBe(5);

            $wrong = $controller->complete(Request::create('/oauth/complete', 'POST', [
                'handle' => $answer['handle'], 'level' => 'sync', 'confirm' => 'ABC234',
            ]))->getData(true);
            expect($wrong['choice_required'])->toBeTrue()
                ->and($wrong['code_rejected'])->toBeTrue()
                ->and($wrong['handle'])->toBe($answer['handle'])
                ->and($controller->saved)->toBeNull()
                ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_links'])->toBe(5);

            $jobs = [];
            $done = null;
            $jobs = qbCaptureDispatches(function () use ($controller, $wrong, &$done): void {
                $done = $controller->complete(Request::create('/oauth/complete', 'POST', [
                    'handle' => $wrong['handle'], 'level' => 'sync', 'confirm' => $wrong['code'],
                ]));
            });

            expect($done->getStatusCode())->toBe(200)
                ->and($done->getData(true))->toBe(['connected' => true])
                ->and($controller->saved['realm_id'])->toBe('9341453000000009')
                ->and(DataChoiceDatabase::rows('company-uuid')['quickbooks_links'])->toBe(0)
                ->and(DataChoiceDatabase::rows('company-uuid')['test_customers'])->toBe(2)
                ->and(DataChoiceDatabase::rows('other-company')['quickbooks_links'])->toBe(5)
                ->and($jobs)->not->toBe([]);
        } finally {
            qbRestoreConfig($previous);
            session(['company' => null, 'user' => null]);
        }
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, QB_SQLITE_SKIP);
