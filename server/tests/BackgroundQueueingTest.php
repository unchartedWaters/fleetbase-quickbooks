<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Jobs\QueueCompanyScope;
use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\CapturedJobs;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function bgDirectory(): FleetbaseDirectory
{
    return new class extends FleetbaseDirectory {
        /** @var array<int, array{0: string, 1: array<string, bool>}> */
        public array $queued = [];

        public function queueInScope(string $companyUuid, array $enabled): void
        {
            $this->queued[] = [$companyUuid, $enabled];
        }
    };
}

function bgSettingController(MemorySettingsStore $store): SettingController
{
    return new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
}

/**
 * Runs a callback against an in-memory connections table.
 */
function bgWithConnections(callable $callback): void
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
        $table->string('default_item_id')->nullable();
        $table->timestamp('customer_import_start')->nullable();
        $table->timestamp('rate_limited_until')->nullable();
        $table->integer('last_rate_limit_wait')->nullable();
        $table->timestamp('last_batch_at')->nullable();
        $table->timestamps();
    });

    try {
        $callback();
    } finally {
        $schema->dropIfExists('quickbooks_connections');
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
    }
}

function bgConnection(string $companyUuid, ?string $realm, array $extra = []): void
{
    $connection = new Connection();
    $connection->forceFill(array_merge(['uuid' => 'conn-' . $companyUuid, 'company_uuid' => $companyUuid, 'realm_id' => $realm], $extra));
    $connection->save();
}

test('the per-organization queue job pages the catalog through the directory', function () {
    $directory = bgDirectory();
    $job       = new QueueCompanyScope('company-a', ['wallet' => true]);

    $job->handle($directory);

    expect($directory->queued)->toBe([['company-a', ['wallet' => true]]]);
});

test('the connect queue job starts the sync after queueing and a plain one does not', function () {
    $directory = bgDirectory();

    $jobs = CapturedJobs::capture(function () use ($directory): void {
        (new QueueCompanyScope('company-a', ['invoice' => true], true))->handle($directory);
        (new QueueCompanyScope('company-b', ['invoice' => true]))->handle($directory);
    });

    expect($directory->queued)->toHaveCount(2)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0])->toBeInstanceOf(SyncCompanyBatch::class)
        ->and($jobs[0]->companyUuid)->toBe('company-a')
        ->and($jobs[0]->trigger)->toBe('now');
});

test('a global save queues nothing for any organization', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false]);
    session(['company' => 'company-uuid']);
    $controller = bgSettingController($store);

    try {
        $jobs = CapturedJobs::capture(function () use ($controller): void {
            $saved = $controller->save(InstallAdminRequest::create('/settings', 'POST', [
                'scope' => 'admin',
                'auth'  => ['client_id' => 'client-id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.com/callback', 'environment' => 'sandbox'],
                'sync'  => qbSettings(['wallet_enabled' => true]),
            ]));
            expect($saved->getStatusCode())->toBe(200);
        });

        expect($jobs)->toBe([])
            ->and(CapturedJobs::queued($jobs))->toBe([]);
    } finally {
        session(['company' => null]);
    }
});

test('an organization save dispatches a queue job instead of queueing inline', function () {
    $store                                  = new MemorySettingsStore();
    $store->rows[SettingsKeys::adminSync()] = qbSettings(['wallet_enabled' => false]);
    session(['company' => 'company-a']);
    $controller = bgSettingController($store);

    try {
        $jobs = CapturedJobs::capture(function () use ($controller): void {
            $controller->save(Request::create('/settings', 'POST', [
                'scope' => 'company',
                'sync'  => ['wallet_enabled' => true],
            ]));
        });

        expect(CapturedJobs::queued($jobs))->toBe([['company-a', ['wallet' => true]]]);
    } finally {
        session(['company' => null]);
    }
});

test('reconnecting a different realm clears the import cursor and the rate limit state', function () {
    bgWithConnections(function () {
        bgConnection('company-a', 'realm-old', [
            'customer_import_start' => '2026-01-01 00:00:00',
            'rate_limited_until'    => '2026-01-02 00:00:00',
            'last_rate_limit_wait'  => 60,
            'last_batch_at'         => '2026-01-03 00:00:00',
        ]);
        $controller = bgPersistController();

        $controller->persistForTest(['company_uuid' => 'company-a', 'realm_id' => 'realm-new', 'environment' => 'sandbox', 'needs_reauth' => false]);

        $row = Connection::query()->where('company_uuid', 'company-a')->first();
        expect($row->realm_id)->toBe('realm-new')
            ->and($row->customer_import_start)->toBeNull()
            ->and($row->rate_limited_until)->toBeNull()
            ->and($row->last_rate_limit_wait)->toBeNull()
            ->and($row->last_batch_at)->toBeNull();
    });
});

test('reconnecting the same realm keeps the import cursor and the rate limit state', function () {
    bgWithConnections(function () {
        bgConnection('company-a', 'realm-1', [
            'customer_import_start' => '2026-01-01 00:00:00',
            'rate_limited_until'    => '2026-01-02 00:00:00',
            'last_rate_limit_wait'  => 60,
            'last_batch_at'         => '2026-01-03 00:00:00',
        ]);
        $controller = bgPersistController();

        $controller->persistForTest(['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'environment' => 'sandbox', 'needs_reauth' => false]);

        $row = Connection::query()->where('company_uuid', 'company-a')->first();
        expect($row->customer_import_start)->not->toBeNull()
            ->and($row->rate_limited_until)->not->toBeNull()
            ->and($row->last_rate_limit_wait)->toBe(60)
            ->and($row->last_batch_at)->not->toBeNull();
    });
});

test('reconnecting clears the reconnect notice so a new failure after it notifies again', function () {
    Illuminate\Support\Facades\Notification::fake();
    Illuminate\Support\Facades\Cache::forget('quickbooks.reauth-notified.company-a');
    $owner       = new Fleetbase\Models\User();
    $owner->uuid = 'owner-uuid';
    $directory   = new class($owner) extends FleetbaseDirectory {
        public function __construct(private Fleetbase\Models\User $owner)
        {
        }

        /** @param array<string, mixed> $connection */
        public function tell(array $connection): void
        {
            $this->notifyReauth($connection);
        }

        protected function reauthRecipients(string $companyUuid): array
        {
            return [$this->owner];
        }
    };
    $failed = ['company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'needs_reauth' => true];

    bgWithConnections(function () use ($directory, $failed) {
        bgConnection('company-a', 'realm-1', ['needs_reauth' => true]);
        $directory->tell($failed);

        bgPersistController()->persistForTest([
            'company_uuid' => 'company-a',
            'realm_id'     => 'realm-1',
            'environment'  => 'sandbox',
            'needs_reauth' => false,
        ]);

        expect(Illuminate\Support\Facades\Cache::has('quickbooks.reauth-notified.company-a'))->toBeFalse();
        $directory->tell($failed);
    });

    Illuminate\Support\Facades\Notification::assertSentToTimes(
        $owner,
        Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth::class,
        2
    );
});

function bgPersistController(): ConnectionController
{
    return new class(new Authorizer(static fn () => true), new OAuthFlow(new QuickBooksClient()), new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()), new MemorySettingsStore(), new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())) extends ConnectionController {
        /** @param array<string, mixed> $connection */
        public function persistForTest(array $connection): void
        {
            $this->persist($connection);
        }
    };
}
