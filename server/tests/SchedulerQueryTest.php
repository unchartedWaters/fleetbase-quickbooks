<?php

use Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\ConnectionTokens;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\TokenRefresher;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\CountingDirectory;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->sqtCache   = Cache::getFacadeRoot();
    $this->sqtDefault = config('database.default');
    $this->sqtSqlite  = config('database.connections.sqlite');
    Cache::swap(new Repository(new ArrayStore()));
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::purge('sqlite');
});

afterEach(function () {
    DB::purge('sqlite');
    config()->set('database.default', $this->sqtDefault);
    config()->set('database.connections.sqlite', $this->sqtSqlite);
    Cache::swap($this->sqtCache);
    CountingDirectory::$connectionReads = 0;
    CountingDirectory::$connections     = [];
});

test('the schedule reads each connection once per tick', function () {
    $schema = DB::connection('sqlite')->getSchemaBuilder();
    $schema->create('quickbooks_connections', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('realm_id')->nullable();
        $table->text('access_token')->nullable();
        $table->text('refresh_token')->nullable();
        $table->boolean('needs_reauth')->default(false);
        $table->timestamp('token_expires_at')->nullable();
        $table->timestamp('rate_limited_until')->nullable();
        $table->timestamp('last_batch_at')->nullable();
        $table->timestamps();
    });
    $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('local_type');
        $table->char('local_uuid', 36);
        $table->string('status')->default('pending');
        $table->timestamp('next_attempt_at')->nullable();
        $table->timestamps();
    });
    foreach (['org-a', 'org-b'] as $companyUuid) {
        $row = [
            'company_uuid'     => $companyUuid,
            'realm_id'         => 'realm-' . $companyUuid,
            'needs_reauth'     => false,
            'token_expires_at' => now()->addDay(),
            'last_batch_at'    => now()->subHour(),
        ];
        DB::table('quickbooks_connections')->insert($row + ['uuid' => 'conn-' . $companyUuid]);
        CountingDirectory::$connections[$companyUuid] = array_merge($row, [
            'token_expires_at' => $row['token_expires_at']->getTimestamp(),
            'last_batch_at'    => $row['last_batch_at']->getTimestamp(),
        ]);
    }
    $store = new MemorySettingsStore();

    $store->rows[SettingsKeys::adminSync()] = ['interval_minutes' => 5, 'customer_enabled' => false];

    $directory = new CountingDirectory();
    $settings  = new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher());
    $client    = new FakeQuickBooks();
    $tokens    = new ConnectionTokens(new TokenRefresher($client), $settings, $store, $directory);
    $engine    = new Fleetbase\Quickbooks\Services\SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );
    $command = new SyncQuickbooks();
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

    $result = $command->handle(new BatchRunner($engine, $directory, $settings, $store, $tokens));
    $asked  = array_count_values($store->asked);

    expect($result)->toBe(0)
        ->and(CountingDirectory::$connectionReads)->toBeLessThanOrEqual(2)
        ->and($asked[SettingsKeys::adminSync()] ?? 0)->toBe(1)
        ->and($asked[SettingsKeys::companySync('org-a')] ?? 0)->toBe(1)
        ->and($asked[SettingsKeys::companySync('org-b')] ?? 0)->toBe(1);
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
