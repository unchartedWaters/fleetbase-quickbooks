<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Fleetbase\TestSupport\LoggerManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Runs $work against an in-memory database with the tables disconnect touches.
 *
 * @param callable(): void $work
 */
function revokeWithDatabase(callable $work): void
{
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('sqlite');
    $schema = DB::connection('sqlite')->getSchemaBuilder();
    $schema->create('quickbooks_connections', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36)->index();
        $table->string('realm_id')->nullable();
        $table->text('access_token')->nullable();
        $table->text('refresh_token')->nullable();
        $table->timestamp('token_expires_at')->nullable();
        $table->string('environment')->default('sandbox');
        $table->boolean('needs_reauth')->default(false);
        $table->string('home_currency', 3)->nullable();
        $table->timestamp('last_batch_at')->nullable();
        $table->timestamps();
    });
    $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36)->index();
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->string('reason')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('next_attempt_at')->nullable();
        $table->timestamps();
    });
    $schema->create('quickbooks_links', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36)->index();
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->timestamps();
    });

    try {
        $work();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        session(['company' => null]);
    }
}

function revokeController(MemorySettingsStore $store): ConnectionController
{
    return new ConnectionController(
        new Authorizer(static fn () => true),
        new OAuthFlow(new QuickBooksClient()),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store,
        new ConnectionProbe(new QuickBooksClient()),
        new QuickBooksClient()
    );
}

function revokeStore(bool $withCredentials = true): MemorySettingsStore
{
    $store = new MemorySettingsStore();
    if ($withCredentials === true) {
        $store->rows[SettingsKeys::adminAuth()] = [
            'client_id'     => 'client-id',
            'client_secret' => (new SecretCipher())->encrypt('client-secret'),
            'environment'   => 'sandbox',
        ];
    }

    return $store;
}

function revokeSeed(): void
{
    foreach (['company-uuid' => 'refresh-token-one', 'other-company' => 'refresh-token-other'] as $company => $token) {
        $connection = new Connection();
        $connection->fill([
            'uuid'          => 'conn-' . $company,
            'company_uuid'  => $company,
            'realm_id'      => '9341453000000001',
            'refresh_token' => $token,
            'environment'   => 'sandbox',
            'needs_reauth'  => false,
        ]);
        $connection->save();
        foreach (['pending', 'failed'] as $status) {
            $row = new PendingSync();
            $row->fill([
                'uuid'         => 'pending-' . $company . '-' . $status,
                'company_uuid' => $company,
                'local_type'   => 'invoice',
                'local_uuid'   => 'invoice-' . $company . '-' . $status,
                'status'       => $status,
            ]);
            $row->save();
        }
        DB::table('quickbooks_links')->insert([
            'uuid'         => 'link-' . $company,
            'company_uuid' => $company,
            'local_type'   => 'customer',
            'local_uuid'   => 'customer-' . $company,
        ]);
    }
}

test('the client revokes a refresh token with basic auth and a json body', function () {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['developer.api.intuit.com/*' => Http::response('', 200)]);

    (new QuickBooksClient())->revoke([
        'client_id'     => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri'  => 'https://example.test/callback',
        'environment'   => 'sandbox',
    ], 'refresh-token-one');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);
    [$request] = $recorded[0];

    expect($request->url())->toBe('https://developer.api.intuit.com/v2/oauth2/tokens/revoke')
        ->and($request->method())->toBe('POST')
        ->and($request->header('Authorization'))->toBe(['Basic ' . base64_encode('client-id:client-secret')])
        ->and($request->data())->toBe(['token' => 'refresh-token-one'])
        ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue();
});

test('a revoke that Intuit refuses or cannot answer raises a QuickBooksException without the token', function () {
    $credentials = ['client_id' => 'client-id', 'client_secret' => 'client-secret', 'redirect_uri' => '', 'environment' => 'sandbox'];

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['developer.api.intuit.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    expect(fn () => (new QuickBooksClient())->revoke($credentials, 'refresh-token-one'))->toThrow(QuickBooksException::class);

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out refresh-token-one');
    });

    try {
        (new QuickBooksClient())->revoke($credentials, 'refresh-token-one');
        expect(false)->toBeTrue();
    } catch (QuickBooksException $exception) {
        expect($exception->status)->toBe(0)
            ->and($exception->getMessage())->toBe(QuickBooksClient::TRANSPORT_MESSAGE);
    }
});

test('disconnect revokes the refresh token then removes the connection and this company pending rows only', function () {
    revokeWithDatabase(function () {
        revokeSeed();
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::fake(['developer.api.intuit.com/*' => Http::response('', 200)]);
        session(['company' => 'company-uuid']);

        $response = revokeController(revokeStore())->disconnect(Request::create('/disconnect', 'POST'));

        $recorded = Http::recorded();
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe(['disconnected' => true])
            ->and($recorded)->toHaveCount(1);
        [$request] = $recorded[0];

        expect($request->url())->toBe('https://developer.api.intuit.com/v2/oauth2/tokens/revoke')
            ->and($request->data())->toBe(['token' => 'refresh-token-one'])
            ->and($request->header('Authorization'))->toBe(['Basic ' . base64_encode('client-id:client-secret')])
            ->and(Connection::query()->pluck('company_uuid')->all())->toBe(['other-company'])
            ->and(PendingSync::query()->orderBy('uuid')->pluck('uuid')->all())->toBe([
                'pending-company-uuid-failed',
                'pending-other-company-failed',
                'pending-other-company-pending',
            ])
            ->and(DB::table('quickbooks_links')->orderBy('uuid')->pluck('uuid')->all())->toBe(['link-company-uuid', 'link-other-company']);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('disconnect still completes when Intuit fails or times out and logs no token', function () {
    foreach ([
        static fn () => Http::response(['Fault' => ['Error' => [['Message' => 'boom']]]], 500),
        static function () {
            throw new ConnectionException('cURL error 28: Operation timed out refresh-token-one client-secret');
        },
    ] as $answer) {
        revokeWithDatabase(function () use ($answer) {
            revokeSeed();
            LoggerManager::$records = [];
            Http::swap(new Illuminate\Http\Client\Factory());
            Http::fake(['developer.api.intuit.com/*' => $answer]);
            session(['company' => 'company-uuid']);

            $response = revokeController(revokeStore())->disconnect(Request::create('/disconnect', 'POST'));
            $logged   = json_encode(LoggerManager::$records);

            expect($response->getStatusCode())->toBe(200)
                ->and(Connection::query()->pluck('company_uuid')->all())->toBe(['other-company'])
                ->and(PendingSync::query()->where('company_uuid', 'company-uuid')->where('status', 'pending')->count())->toBe(0)
                ->and(LoggerManager::$records)->toHaveCount(1)
                ->and(LoggerManager::$records[0]['level'])->toBe('warning')
                ->and($logged)->not->toContain('refresh-token-one')
                ->and($logged)->not->toContain('client-secret')
                ->and($logged)->not->toContain('client-id');
        });
    }
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('disconnect does not call Intuit when the app credentials or the refresh token are missing', function () {
    revokeWithDatabase(function () {
        revokeSeed();
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::fake();
        session(['company' => 'company-uuid']);

        $response = revokeController(revokeStore(false))->disconnect(Request::create('/disconnect', 'POST'));

        expect($response->getStatusCode())->toBe(200)
            ->and(Http::recorded())->toHaveCount(0)
            ->and(Connection::query()->pluck('company_uuid')->all())->toBe(['other-company']);
    });

    revokeWithDatabase(function () {
        $connection = new Connection();
        $connection->fill(['uuid' => 'conn-1', 'company_uuid' => 'company-uuid', 'realm_id' => '9341453000000001', 'environment' => 'sandbox', 'needs_reauth' => false]);
        $connection->save();
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::fake();
        session(['company' => 'company-uuid']);

        $response = revokeController(revokeStore())->disconnect(Request::create('/disconnect', 'POST'));

        expect($response->getStatusCode())->toBe(200)
            ->and(Http::recorded())->toHaveCount(0)
            ->and(Connection::query()->count())->toBe(0);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
