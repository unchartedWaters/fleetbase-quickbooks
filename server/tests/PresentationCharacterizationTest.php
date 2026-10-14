<?php

use Fleetbase\Quickbooks\Http\Controllers\ConnectionController;
use Fleetbase\Quickbooks\Http\Controllers\SettingController;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Support\Translation;
use Fleetbase\Quickbooks\Tests\Support\InstallAdminRequest;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Characterization tests: they pin the current presentation and merge outputs of
 * SettingController, ConnectionController::batches and InvoiceMapper so a refactor
 * can be shown to keep them.
 */

const PCT_ORIGIN_KEYS = ['app.url', 'fleetbase.url', 'quickbooks.console_host', 'fleetbase.console.host'];

/**
 * @param array<string, string|null> $config
 */
function pctOriginFor(array $config): string
{
    $previous = [];
    foreach (PCT_ORIGIN_KEYS as $key) {
        $previous[$key] = config($key);
        config()->set($key, $config[$key] ?? null);
    }

    try {
        $url  = SettingController::internalOAuthRedirectUrl();
        $hook = SettingController::internalWebhookReceiverUrl();
    } finally {
        foreach ($previous as $key => $value) {
            config()->set($key, $value);
        }
    }

    $suffix = '/quickbooks/int/v1/oauth/callback';
    expect(str_ends_with($url, $suffix))->toBeTrue()
        ->and($hook)->toBe(substr($url, 0, -strlen($suffix)) . '/quickbooks/int/v1/webhooks');

    return substr($url, 0, -strlen($suffix));
}

function pctSync(array $overrides = []): array
{
    return array_merge([
        'enabled'                 => true,
        'interval_minutes'        => 5,
        'periodic_interval_hours' => 24,
        'batch_size'              => 100,
        'retry_limit'             => 5,
        'default_backoff_seconds' => 30,
        'customer_conflict'       => 'fleetbase',
        'customer_reference'      => 'fleetbase',
        'customer_direction'      => 'both',
        'invoice_conflict'        => 'fleetbase',
        'invoice_reference'       => 'fleetbase',
        'invoice_direction'       => 'both',
        'payment_conflict'        => 'fleetbase',
        'payment_reference'       => 'fleetbase',
        'payment_direction'       => 'both',
        'wallet_conflict'         => 'fleetbase',
        'wallet_reference'        => 'fleetbase',
        'wallet_direction'        => 'both',
        'customer_enabled'        => true,
        'invoice_enabled'         => true,
        'payment_enabled'         => true,
        'wallet_enabled'          => true,
    ], $overrides);
}

function pctSettingController(MemorySettingsStore $store): SettingController
{
    return new SettingController(
        new Authorizer(static fn () => true),
        new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
        $store
    );
}

/**
 * @param array<string, mixed> $auth
 * @param array<string, mixed> $sync
 */
function pctSave(SettingController $controller, array $auth, array $sync): Illuminate\Http\JsonResponse
{
    return $controller->save(InstallAdminRequest::create('/settings', 'POST', [
        'scope'        => 'admin',
        'company_uuid' => 'company-uuid',
        'auth'         => $auth,
        'sync'         => $sync,
    ]));
}

test('configured origin prefers the first non-loopback api url and keeps its scheme and port', function () {
    expect(pctOriginFor([
        'app.url'                 => 'http://api.one.test:8080/',
        'fleetbase.url'           => 'https://api.two.test:9443',
        'quickbooks.console_host' => 'https://console.example.test:4200',
    ]))->toBe('http://api.one.test:8080')
        ->and(pctOriginFor([
            'app.url'       => 'http://127.0.0.1:8000',
            'fleetbase.url' => 'https://api.two.test:9443',
        ]))->toBe('https://api.two.test:9443')
        ->and(pctOriginFor(['app.url' => 'https://api.example.test:443']))->toBe('https://api.example.test')
        ->and(pctOriginFor(['app.url' => 'http://api.example.test:80/path']))->toBe('http://api.example.test')
        ->and(pctOriginFor(['app.url' => 'http://api.example.test:443']))->toBe('http://api.example.test:443')
        ->and(pctOriginFor(['app.url' => 'api.example.test']))->toBe('https://api.example.test')
        ->and(pctOriginFor(['app.url' => 'HTTP://Api.Example.test:8000']))->toBe('http://Api.Example.test:8000')
        ->and(pctOriginFor(['app.url' => 'http://[2001:db8::1]:8080']))->toBe('http://[2001:db8::1]:8080');
});

test('configured origin falls back to the console host with the api scheme and api port', function () {
    // Every api url is loopback, so a non-loopback console host is used. Its own port is dropped.
    expect(pctOriginFor([
        'app.url'                 => 'http://localhost',
        'fleetbase.url'           => 'https://127.0.0.1:9000',
        'quickbooks.console_host' => 'https://console.example.test:4200',
    ]))->toBe('http://console.example.test:9000')
        // quickbooks.console_host wins over fleetbase.console.host when both are usable.
        ->and(pctOriginFor([
            'app.url'                 => 'http://localhost:8000',
            'quickbooks.console_host' => 'https://first.example.test',
            'fleetbase.console.host'  => 'https://second.example.test',
        ]))->toBe('http://first.example.test:8000')
        // A loopback quickbooks.console_host yields to a non-loopback fleetbase.console.host.
        ->and(pctOriginFor([
            'app.url'                 => 'http://localhost:8000',
            'quickbooks.console_host' => 'http://localhost:4200',
            'fleetbase.console.host'  => 'second.example.test',
        ]))->toBe('http://second.example.test:8000');
});

test('configured origin uses the first api url, then the first console host, when everything is loopback', function () {
    expect(pctOriginFor([
        'app.url'                 => 'http://localhost:8000',
        'fleetbase.url'           => 'http://127.0.0.1:9000',
        'quickbooks.console_host' => 'http://localhost:4200',
    ]))->toBe('http://localhost:8000')
        ->and(pctOriginFor([
            'app.url'                => 'https://[::1]',
            'fleetbase.console.host' => 'http://localhost:4200',
        ]))->toBe('https://[::1]');
});

test('configured origin without an api url keeps the console scheme and drops its port', function () {
    expect(pctOriginFor(['quickbooks.console_host' => 'http://console.example.test:4200']))->toBe('http://console.example.test')
        ->and(pctOriginFor(['fleetbase.console.host' => 'console.example.test:4200']))->toBe('https://console.example.test')
        ->and(pctOriginFor([
            'quickbooks.console_host' => 'http://localhost:4200',
            'fleetbase.console.host'  => 'http://127.0.0.1:4300',
        ]))->toBe('http://localhost')
        // The fleetbase.io default is not an origin, so the computed URLs stay path-only.
        ->and(pctOriginFor(['fleetbase.console.host' => 'https://fleetbase.io']))->toBe('')
        ->and(pctOriginFor(['quickbooks.console_host' => 'fleetbase.io', 'fleetbase.console.host' => 'http://localhost:4200']))->toBe('http://localhost')
        ->and(pctOriginFor(['app.url' => '   ', 'fleetbase.url' => '']))->toBe('');
});

test('save merges a missing redirect from the stored row and recomputes an unusable one', function () {
    $store           = new MemorySettingsStore();
    $previousCompany = session('company');
    $previousAppUrl  = config('app.url');
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://api.example.test');
    $controller = pctSettingController($store);
    $callback   = 'https://api.example.test/quickbooks/int/v1/oauth/callback';

    try {
        // A public IP literal keeps the public https check off DNS.
        $store->rows[SettingsKeys::adminAuth()] = [
            'client_id'     => 'stored-id',
            'client_secret' => 'stored-secret',
            'redirect_uri'  => 'https://8.8.8.8/callback',
            'environment'   => 'sandbox',
        ];
        $kept = pctSave($controller, ['client_id' => 'stored-id'], pctSync());

        expect($kept->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe('https://8.8.8.8/callback')
            ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('stored-id')
            ->and($store->rows[SettingsKeys::adminAuth()]['environment'])->toBe('sandbox')
            ->and((new SecretCipher())->decrypt($store->rows[SettingsKeys::adminAuth()]['client_secret']))->toBe('stored-secret');

        $store->rows[SettingsKeys::adminAuth()]['redirect_uri']  = 'http://localhost:8000/callback';
        $recomputed                                              = pctSave($controller, ['client_id' => 'stored-id'], pctSync());

        expect($recomputed->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe($callback);

        $store->rows[SettingsKeys::adminAuth()]['redirect_uri']  = 'https://8.8.8.8/callback';
        $replaced                                                = pctSave($controller, ['redirect_uri' => 'https://console.example.test:4200/cb'], pctSync());

        expect($replaced->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe($callback);
    } finally {
        session(['company' => $previousCompany]);
        config()->set('app.url', $previousAppUrl);
    }
});

test('save validates the merged auth so a blank secret uses the stored one and no stored secret is rejected', function () {
    $store           = new MemorySettingsStore();
    $previousCompany = session('company');
    $previousAppUrl  = config('app.url');
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://api.example.test');
    $controller = pctSettingController($store);

    try {
        $rejected = pctSave($controller, [
            'client_id'     => 'id',
            'client_secret' => '',
            'redirect_uri'  => 'https://example.com/callback',
            'environment'   => 'production',
        ], pctSync());
        $body = $rejected->getData(true);

        expect($rejected->getStatusCode())->toBe(422)
            ->and($body['message'])->toBe('Some QuickBooks settings are missing or invalid.')
            ->and(array_keys($body['errors']))->toBe(['client_secret'])
            ->and($store->rows)->not->toHaveKey(SettingsKeys::adminAuth())
            ->and($store->rows)->not->toHaveKey(SettingsKeys::adminSync());

        $missing = pctSave($controller, ['client_secret' => ['not', 'a', 'string']], ['interval_minutes' => 0]);
        expect($missing->getStatusCode())->toBe(422)
            ->and(array_keys($missing->getData(true)['errors']))->toBe([
                'client_id',
                'environment',
                'client_secret',
                'interval_minutes',
                'periodic_interval_hours',
                'retry_limit',
                'default_backoff_seconds',
                'customer_conflict',
                'customer_reference',
                'invoice_conflict',
                'invoice_reference',
                'payment_conflict',
                'payment_reference',
                'wallet_conflict',
                'wallet_reference',
            ]);

        $store->rows[SettingsKeys::adminAuth()] = [
            'client_id'     => 'stored-id',
            'client_secret' => 'stored-secret',
            'environment'   => 'production',
        ];
        $accepted = pctSave($controller, ['client_secret' => '   '], pctSync());

        expect($accepted->getStatusCode())->toBe(200)
            ->and($store->rows[SettingsKeys::adminAuth()]['client_id'])->toBe('stored-id')
            ->and($store->rows[SettingsKeys::adminAuth()]['redirect_uri'])->toBe('https://api.example.test/quickbooks/int/v1/oauth/callback')
            ->and((new SecretCipher())->decrypt($store->rows[SettingsKeys::adminAuth()]['client_secret']))->toBe('stored-secret');
    } finally {
        session(['company' => $previousCompany]);
        config()->set('app.url', $previousAppUrl);
    }
});

test('save merges sync onto the stored allowlisted keys and normalizes every direction', function () {
    $store           = new MemorySettingsStore();
    $previousCompany = session('company');
    $previousAppUrl  = config('app.url');
    session(['company' => 'company-uuid']);
    config()->set('app.url', 'https://api.example.test');
    $controller = pctSettingController($store);

    try {
        $existing = pctSync([
            'interval_minutes'   => 9,
            'customer_direction' => 'inbound',
            'invoice_direction'  => 'off',
            'junk'               => 'dropped',
            'override'           => true,
        ]);
        unset($existing['payment_direction']);
        $store->rows[SettingsKeys::adminSync()] = $existing;

        $saved = pctSave($controller, [
            'client_id'     => 'id',
            'client_secret' => 'secret',
            'redirect_uri'  => 'https://example.com/callback',
            'environment'   => 'sandbox',
        ], [
            'retry_limit'                 => 7,
            'wallet_direction'            => '',
            'sources'                     => ['retry_limit' => 'admin'],
            'override'                    => false,
            'public_webhook_receiver_url' => 'https://public.example.test/hook',
            'webhook_url'                 => 'https://public.example.test/hook',
            'other'                       => 'dropped',
        ]);
        $stored = $store->rows[SettingsKeys::adminSync()];

        $expected = pctSync([
            'interval_minutes'   => 9,
            'retry_limit'        => 7,
            'customer_direction' => 'inbound',
            'invoice_direction'  => 'both',
            'payment_direction'  => 'both',
            'wallet_direction'   => 'both',
        ]);
        ksort($expected);
        ksort($stored);

        expect($saved->getStatusCode())->toBe(200)
            ->and($stored)->toBe($expected);
    } finally {
        session(['company' => $previousCompany]);
        config()->set('app.url', $previousAppUrl);
    }
});

/**
 * @param callable(): void $body
 */
function pctWithBatchTables(callable $body): void
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
    $previousCompany = session('company');
    session(['company' => 'company-uuid']);
    $schema = DB::connection('sqlite')->getSchemaBuilder();

    try {
        $schema->create('quickbooks_sync_batches', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->index();
            $table->string('trigger');
            $table->string('direction')->default('outbound');
            $table->string('status');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('aligned_count')->default(0);
            $table->unsignedInteger('voided_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('linked_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        $schema->create('quickbooks_sync_attempts', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('batch_uuid', 36)->nullable()->index();
            $table->char('company_uuid', 36)->index();
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('outcome');
            $table->text('error')->nullable();
            $table->json('diff')->nullable();
            $table->timestamps();
        });

        $body();
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        session(['company' => $previousCompany]);
    }
}

/**
 * @param array<string, mixed> $attributes
 */
function pctBatch(array $attributes): void
{
    $batch = new SyncBatch();
    $batch->fill(array_merge([
        'company_uuid' => 'company-uuid',
        'trigger'      => 'now',
        'direction'    => 'outbound',
        'status'       => 'finished',
    ], $attributes));
    $batch->save();
}

function pctAttempt(string $uuid, ?string $batchUuid, ?string $error, string $createdAt): void
{
    $attempt = new SyncAttempt();
    $attempt->fill([
        'uuid'         => $uuid,
        'batch_uuid'   => $batchUuid,
        'company_uuid' => 'company-uuid',
        'local_type'   => 'invoice',
        'local_uuid'   => 'local-' . $uuid,
        'outcome'      => 'failed',
        'error'        => $error,
        'created_at'   => Carbon::parse($createdAt),
        'updated_at'   => Carbon::parse($createdAt),
    ]);
    $attempt->save();
}

test('sync activity presents each batch with paired counts, its first three distinct errors and iso times', function () {
    pctWithBatchTables(function () {
        pctBatch([
            'uuid'            => 'batch-errors',
            'trigger'         => 'scheduled',
            'direction'       => 'inbound',
            'status'          => 'failed',
            'created_count'   => 1,
            'updated_count'   => 2,
            'aligned_count'   => 3,
            'voided_count'    => 4,
            'unmatched_count' => 5,
            'failed_count'    => 6,
            'linked_count'    => 7,
            'skipped_count'   => 8,
            'started_at'      => Carbon::parse('2026-03-01 10:00:00'),
            'finished_at'     => Carbon::parse('2026-03-01 10:05:00'),
            'created_at'      => Carbon::parse('2026-03-01 10:00:00'),
            'updated_at'      => Carbon::parse('2026-03-01 10:05:00'),
        ]);
        pctBatch([
            'uuid'         => 'batch-counted',
            'failed_count' => 2,
            'created_at'   => Carbon::parse('2026-02-01 00:00:00'),
            'updated_at'   => Carbon::parse('2026-02-01 00:00:00'),
        ]);
        pctBatch([
            'uuid'       => 'batch-clean',
            'trigger'    => 'import',
            'status'     => 'running',
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        pctAttempt('a1', 'batch-errors', '  first  ', '2026-03-01 10:01:00');
        pctAttempt('a2', 'batch-errors', 'second', '2026-03-01 10:02:00');
        pctAttempt('a3', 'batch-errors', 'first', '2026-03-01 10:03:00');
        pctAttempt('a4', 'batch-errors', '   ', '2026-03-01 10:03:30');
        pctAttempt('a5', 'batch-errors', null, '2026-03-01 10:03:40');
        pctAttempt('a6', 'batch-errors', 'third', '2026-03-01 10:04:00');
        pctAttempt('a7', 'batch-errors', 'fourth', '2026-03-01 10:04:30');
        pctAttempt('a8', null, 'no batch', '2026-03-01 10:04:40');
        pctAttempt('a9', 'batch-clean', '   ', '2026-01-01 00:01:00');

        $controller = new ConnectionController(
            new Authorizer(static fn () => true),
            new OAuthFlow(new QuickBooksClient()),
            new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
            new MemorySettingsStore(),
            new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
        );
        $body = $controller->batches(Request::create('/batches', 'GET'))->getData(true);

        expect($body['meta'])->toBe([
            'current_page' => 1,
            'last_page'    => 1,
            'per_page'     => 25,
            'total'        => 3,
        ])
            ->and(array_column($body['batches'], 'uuid'))->toBe(['batch-errors', 'batch-counted', 'batch-clean'])
            ->and($body['batches'][0])->toBe([
                'uuid'            => 'batch-errors',
                'trigger'         => 'scheduled',
                'direction'       => 'inbound',
                'status'          => 'failed',
                'created'         => 1,
                'created_count'   => 1,
                'updated'         => 2,
                'updated_count'   => 2,
                'aligned'         => 3,
                'aligned_count'   => 3,
                'voided'          => 4,
                'voided_count'    => 4,
                'unmatched'       => 5,
                'unmatched_count' => 5,
                'failed'          => 6,
                'failed_count'    => 6,
                'linked'          => 7,
                'linked_count'    => 7,
                'skipped'         => 8,
                'skipped_count'   => 8,
                'error'           => 'first; second; third',
                'started_at'      => Carbon::parse('2026-03-01 10:00:00')->toIso8601String(),
                'finished_at'     => Carbon::parse('2026-03-01 10:05:00')->toIso8601String(),
                'created_at'      => Carbon::parse('2026-03-01 10:00:00')->toIso8601String(),
            ])
            ->and($body['batches'][1]['error'])->toBe('2 failed.')
            ->and($body['batches'][1]['failed'])->toBe(2)
            ->and($body['batches'][1]['created'])->toBe(0)
            ->and($body['batches'][1]['started_at'])->toBeNull()
            ->and($body['batches'][1]['finished_at'])->toBeNull()
            ->and($body['batches'][1]['created_at'])->toBe(Carbon::parse('2026-02-01 00:00:00')->toIso8601String())
            ->and($body['batches'][2]['error'])->toBeNull()
            ->and($body['batches'][2]['trigger'])->toBe('import')
            ->and($body['batches'][2]['direction'])->toBe('outbound')
            ->and($body['batches'][2]['status'])->toBe('running');
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('sync activity renders stored message keys for the reader and keeps older english rows', function () {
    pctWithBatchTables(function () {
        pctBatch([
            'uuid'         => 'batch-keys',
            'status'       => 'failed',
            'failed_count' => 4,
            'created_at'   => Carbon::parse('2026-03-01 10:00:00'),
            'updated_at'   => Carbon::parse('2026-03-01 10:00:00'),
        ]);
        $currency = Translation::encode(
            'quickbooks.messages.wallet-currency-mismatch',
            ['currency' => 'EUR', 'home' => 'USD']
        );
        pctAttempt('k1', 'batch-keys', 'quickbooks.messages.sync-failed', '2026-03-01 10:01:00');
        pctAttempt('k2', 'batch-keys', 'Sync failed.', '2026-03-01 10:02:00');
        pctAttempt('k3', 'batch-keys', $currency, '2026-03-01 10:03:00');
        pctAttempt('k4', 'batch-keys', 'Duplicate Name Exists Error', '2026-03-01 10:04:00');

        $controller = new ConnectionController(
            new Authorizer(static fn () => true),
            new OAuthFlow(new QuickBooksClient()),
            new SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()),
            new MemorySettingsStore(),
            new Fleetbase\Quickbooks\Services\ConnectionProbe(new QuickBooksClient())
        );
        $body = $controller->batches(Request::create('/batches', 'GET'))->getData(true);

        expect($body['batches'][0]['error'])->toBe('Sync failed.; Wallet currency EUR does not match the QuickBooks '
            . 'home currency USD.; Duplicate Name Exists Error');
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('toQuickBooks keeps its field order, line ids, quantity default and empty-value rules', function () {
    $mapper  = new InvoiceMapper();
    $payload = $mapper->toQuickBooks([
        'number'   => 'INV-7',
        'date'     => '2026-04-01',
        'due_date' => '2026-04-30',
        'notes'    => 'Thanks',
        'currency' => 'CAD',
        'tax'      => 25,
        'items'    => [
            ['description' => 'Kept id', 'amount' => 1000, 'qbo_line_id' => '  7  '],
            ['description' => 'Blank id', 'quantity' => 3, 'amount' => 1000, 'qbo_line_id' => '   '],
            ['amount' => '250'],
        ],
    ], 'cust-1', 'item-9');

    expect(array_keys($payload))->toBe(['CustomerRef', 'Line', 'DocNumber', 'TxnDate', 'DueDate', 'PrivateNote', 'CurrencyRef'])
        ->and($payload['CustomerRef'])->toBe(['value' => 'cust-1'])
        ->and($payload['DocNumber'])->toBe('INV-7')
        ->and($payload['TxnDate'])->toBe('2026-04-01')
        ->and($payload['DueDate'])->toBe('2026-04-30')
        ->and($payload['PrivateNote'])->toBe('Thanks')
        ->and($payload['CurrencyRef'])->toBe(['value' => 'CAD'])
        ->and($payload['Line'])->toBe([
            [
                'Amount'              => '10.00',
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => 'Kept id',
                'SalesItemLineDetail' => ['ItemRef' => ['value' => 'item-9'], 'Qty' => 1, 'UnitPrice' => '10.00'],
                'Id'                  => '7',
            ],
            [
                'Amount'              => '10.00',
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => 'Blank id',
                'SalesItemLineDetail' => ['ItemRef' => ['value' => 'item-9'], 'Qty' => 3, 'UnitPrice' => $payload['Line'][1]['SalesItemLineDetail']['UnitPrice']],
            ],
            [
                'Amount'              => '2.50',
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => '',
                'SalesItemLineDetail' => ['ItemRef' => ['value' => 'item-9'], 'Qty' => 1, 'UnitPrice' => '2.50'],
            ],
            [
                'Amount'              => '0.25',
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => InvoiceMapper::TAX_LINE_DESCRIPTION,
                'SalesItemLineDetail' => ['ItemRef' => ['value' => 'item-9'], 'Qty' => 1, 'UnitPrice' => '0.25'],
            ],
        ]);

    $bare = $mapper->toQuickBooks(['number' => '0', 'tax' => -5, 'items' => 'not-a-list', 'currency' => ''], 'cust-1', 'item-9');
    expect($bare)->toBe(['CustomerRef' => ['value' => 'cust-1'], 'Line' => []]);

    $cleared = $mapper->toQuickBooks(['date' => '', 'notes' => null], 'cust-1', 'item-9', true);
    expect($cleared)->toBe([
        'CustomerRef' => ['value' => 'cust-1'],
        'Line'        => [],
        'TxnDate'     => '',
        'DueDate'     => '',
        'PrivateNote' => '',
    ]);
});

function pctSalesLine(?string $id, string $amount, string $description = 'Line', string $item = 'item-r'): array
{
    $line = [
        'Amount'              => $amount,
        'DetailType'          => 'SalesItemLineDetail',
        'Description'         => $description,
        'SalesItemLineDetail' => ['ItemRef' => ['value' => $item], 'Qty' => 1, 'UnitPrice' => $amount],
    ];
    if ($id !== null) {
        $line = ['Id' => $id] + $line;
    }

    return $line;
}

test('withExistingLineIds pairs kept ids first, fills the rest in order and keeps tax, discount and subtotal lines', function () {
    $mapper   = new InvoiceMapper();
    $discount = ['Id' => 'd1', 'Amount' => '1.00', 'DetailType' => 'DiscountLineDetail', 'DiscountLineDetail' => ['PercentBased' => false]];
    $subtotal = ['Amount' => '20.00', 'DetailType' => 'SubTotalLineDetail', 'SubTotalLineDetail' => []];
    $remote   = ['Line' => [
        pctSalesLine('r1', '1.00'),
        pctSalesLine('r2', '2.00'),
        pctSalesLine('t9', '0.50', InvoiceMapper::TAX_LINE_DESCRIPTION),
        $discount,
        $subtotal,
        ['Amount' => '9.99', 'DetailType' => 'DescriptionOnly'],
    ]];
    $payload = [
        'CustomerRef' => ['value' => 'cust-1'],
        'Line'        => [
            pctSalesLine('r2', '5.00', 'Has r2', 'item-l'),
            pctSalesLine(null, '6.00', 'No id', 'item-l'),
            pctSalesLine(null, '7.00', 'Third', 'item-l'),
            pctSalesLine(null, '0.75', InvoiceMapper::TAX_LINE_DESCRIPTION, 'item-l'),
            'not-a-line',
        ],
        'DocNumber' => 'INV-1',
    ];

    $merged = $mapper->withExistingLineIds($payload, $remote);

    expect(array_keys($merged))->toBe(['CustomerRef', 'Line', 'DocNumber'])
        ->and($merged['DocNumber'])->toBe('INV-1')
        ->and(array_slice($merged['Line'], 0, 6))->toBe([
            pctSalesLine('r2', '5.00', 'Has r2', 'item-l'),
            pctSalesLine(null, '6.00', 'No id', 'item-l') + ['Id' => 'r1'],
            pctSalesLine(null, '7.00', 'Third', 'item-l'),
            pctSalesLine(null, '0.75', InvoiceMapper::TAX_LINE_DESCRIPTION, 'item-l') + ['Id' => 't9'],
            'not-a-line',
            $discount,
        ])
        // QuickBooks sends the subtotal detail as {}; it must go back as {}, not [], or the update is refused.
        ->and(json_encode(array_slice($merged['Line'], 6)))->toBe('[{"Amount":"20.00","DetailType":"SubTotalLineDetail","SubTotalLineDetail":{}}]');
});

test('withExistingLineIds sends an empty discount detail back as an object', function () {
    $remote = ['Line' => [['Amount' => '1.00', 'DetailType' => 'DiscountLineDetail', 'DiscountLineDetail' => []]]];

    $merged = (new InvoiceMapper())->withExistingLineIds(['Line' => []], $remote);

    expect(json_encode($merged['Line']))->toBe('[{"Amount":"1.00","DetailType":"DiscountLineDetail","DiscountLineDetail":{}}]');
});

test('withExistingLineIds zeroes a cleared tax line and appends unpaired remote sales lines', function () {
    $mapper = new InvoiceMapper();
    $remote = ['Line' => [
        pctSalesLine('r1', '1.00', 'One', 'item-first'),
        pctSalesLine('t9', '0.50', InvoiceMapper::TAX_LINE_DESCRIPTION),
        pctSalesLine('r2', '2.00', 'Two'),
        pctSalesLine('r3', '3.00', 'Three'),
    ]];

    // A wanted id that QuickBooks no longer has takes the first unused remote line.
    $merged = $mapper->withExistingLineIds(['Line' => [pctSalesLine('gone', '5.00', 'Local', 'item-local')]], $remote);
    expect($merged)->toBe(['Line' => [
        pctSalesLine('r1', '5.00', 'Local', 'item-local'),
        [
            'Id'                  => 't9',
            'Amount'              => '0.00',
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => InvoiceMapper::TAX_LINE_DESCRIPTION,
            'SalesItemLineDetail' => ['ItemRef' => ['value' => 'item-local'], 'Qty' => 1, 'UnitPrice' => '0.00'],
        ],
        pctSalesLine('r2', '2.00', 'Two'),
        pctSalesLine('r3', '3.00', 'Three'),
    ]]);

    // With no local sales line the zero tax line takes the first remote item ref.
    $noLocal = $mapper->withExistingLineIds([], $remote);
    expect($noLocal['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => 'item-first'])
        ->and(array_column($noLocal['Line'], 'Id'))->toBe(['t9', 'r1', 'r2', 'r3']);

    // A remote tax line without an item ref and no other ref falls back to item 1.
    $bareTax = $mapper->withExistingLineIds(['Line' => []], ['Line' => [[
        'Id'          => 't1',
        'Amount'      => '0.10',
        'DetailType'  => 'SalesItemLineDetail',
        'Description' => InvoiceMapper::TAX_LINE_DESCRIPTION,
    ]]]);
    expect($bareTax['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => '1']);
});

test('withExistingLineIds pairs a blank remote id without copying it and does not resend that line', function () {
    $mapper = new InvoiceMapper();
    $remote = ['Line' => [
        pctSalesLine(null, '1.00', 'No remote id'),
        pctSalesLine('  ', '2.00', 'Blank remote id'),
        pctSalesLine('r3', '3.00', 'Three'),
    ]];

    $merged = $mapper->withExistingLineIds(['Line' => [
        pctSalesLine(null, '5.00', 'A', 'item-l'),
        pctSalesLine(null, '6.00', 'B', 'item-l'),
    ]], $remote);

    expect($merged['Line'])->toBe([
        pctSalesLine(null, '5.00', 'A', 'item-l'),
        pctSalesLine(null, '6.00', 'B', 'item-l'),
        pctSalesLine('r3', '3.00', 'Three'),
    ]);

    // No remote lines leaves the payload lines unchanged.
    expect($mapper->withExistingLineIds(['Line' => [pctSalesLine('x', '1.00')]], []))->toBe(['Line' => [pctSalesLine('x', '1.00')]]);
});
