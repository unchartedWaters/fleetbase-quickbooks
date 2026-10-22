<?php

use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function snapshotWithDatabase(callable $callback): void
{
    $default = config('database.default');
    $sqlite  = config('database.connections.sqlite');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::purge('sqlite');
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap(DB::connection('sqlite')->getSchemaBuilder());

    try {
        $callback();
    } finally {
        DB::purge('sqlite');
        Schema::swap($originalSchema);
        config()->set('database.default', $default);
        config()->set('database.connections.sqlite', $sqlite);
    }
}

function snapshotMigration(): object
{
    $files = glob(dirname(__DIR__) . '/migrations/*_snapshot_quickbooks_organization_sync.php');
    expect($files)->toHaveCount(1);

    return require $files[0];
}

function snapshotTables(): void
{
    Schema::create('settings', function (Blueprint $table) {
        $table->increments('id');
        $table->string('key')->nullable()->unique();
        $table->json('value')->nullable();
    });
    Schema::create('quickbooks_connections', function (Blueprint $table) {
        $table->char('uuid', 36)->primary();
        $table->char('company_uuid', 36);
        $table->string('realm_id')->nullable();
    });
}

/** @param array<string, mixed> $value */
function snapshotPut(string $key, array $value): void
{
    DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value)]);
}

/** @return array<string, mixed>|null */
function snapshotGet(string $key): ?array
{
    $raw = DB::table('settings')->where('key', $key)->value('value');

    return is_string($raw) === true ? json_decode($raw, true) : null;
}

function snapshotConnect(string $uuid, ?string $realm): void
{
    DB::table('quickbooks_connections')->insert(['uuid' => 'c-' . $uuid, 'company_uuid' => $uuid, 'realm_id' => $realm]);
}

test('the migration is a no-op before the settings table exists', function () {
    snapshotWithDatabase(function () {
        snapshotMigration()->up();

        expect(Schema::hasTable('settings'))->toBeFalse();
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('the migration gives connected organizations and legacy rows a complete copy of their current settings', function () {
    snapshotWithDatabase(function () {
        snapshotTables();
        snapshotPut(SettingsKeys::adminSync(), qbSettings(['interval_minutes' => 15, 'wallet_enabled' => false, 'override' => true]));
        snapshotPut(SettingsKeys::adminAuth(), ['client_id' => 'global-client-id']);
        snapshotConnect('org-connected', 'realm-1');
        snapshotConnect('org-partial', 'realm-2');
        snapshotConnect('org-blank', '');
        snapshotConnect('org-null', null);
        snapshotPut(SettingsKeys::companySync('org-partial'), orgRow(['interval_minutes' => 45]));
        snapshotPut(SettingsKeys::companySync('org-inherit'), orgRow([], false));
        snapshotPut(SettingsKeys::companySync('org-stale'), orgRow(['interval_minutes' => 99], false));
        snapshotPut(SettingsKeys::companyAuth('org-auth-only'), ['client_id' => 'company-client']);
        $authBefore = DB::table('settings')->orderBy('key')->where('key', 'like', '%.quickbooks.auth')->get()->toArray();

        snapshotMigration()->up();

        $connected = snapshotGet(SettingsKeys::companySync('org-connected'));
        $partial   = snapshotGet(SettingsKeys::companySync('org-partial'));
        $inherit   = snapshotGet(SettingsKeys::companySync('org-inherit'));
        $stale     = snapshotGet(SettingsKeys::companySync('org-stale'));
        expect(SettingsStore::isCompleteCopy($connected ?? []))->toBeTrue()
            ->and($connected['interval_minutes'])->toBe(15)
            ->and($connected['wallet_enabled'])->toBeFalse()
            ->and($connected)->not->toHaveKey('override')
            ->and(array_diff(array_keys($connected), [...SettingsKeys::SYNC_FIELDS, SettingsKeys::ORGANIZATION_MARKER]))->toBe([])
            ->and($partial['interval_minutes'])->toBe(45)
            ->and($partial['wallet_enabled'])->toBeFalse()
            ->and(SettingsStore::isCompleteCopy($partial))->toBeTrue()
            ->and($inherit['interval_minutes'])->toBe(15)
            ->and(SettingsStore::isCompleteCopy($inherit))->toBeTrue()
            ->and($stale['interval_minutes'])->toBe(15)
            ->and(snapshotGet(SettingsKeys::companySync('org-blank')))->toBeNull()
            ->and(snapshotGet(SettingsKeys::companySync('org-null')))->toBeNull()
            ->and(snapshotGet(SettingsKeys::companySync('org-auth-only')))->toBeNull()
            ->and(DB::table('settings')->orderBy('key')->where('key', 'like', '%.quickbooks.auth')->get()->toArray())->toEqual($authBefore)
            ->and(snapshotGet(SettingsKeys::adminSync())['override'])->toBeTrue();
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('the migration keeps fleetbase primary for organizations that had it before quickbooks became the'
. ' default', function () {
    snapshotWithDatabase(function () {
        snapshotTables();
        snapshotConnect('org-default', 'realm-1');
        snapshotConnect('org-chosen', 'realm-2');
        $chosenRow = orgRow(['invoice_conflict' => 'quickbooks', 'invoice_reference' => 'quickbooks']);
        snapshotPut(SettingsKeys::companySync('org-chosen'), $chosenRow);
        $before = config('quickbooks.sync');
        // Read from the config source: requiring the file calls env(), which this test environment cannot load.
        $source  = (string) file_get_contents(dirname(__DIR__) . '/config/quickbooks.php');
        $shipped = qbSettings();
        foreach (['customer', 'invoice', 'payment', 'wallet'] as $type) {
            foreach (['conflict', 'reference'] as $field) {
                $key = $type . '_' . $field;
                expect(preg_match("/'" . $key . "'\\s*=>\\s*'([a-z]+)'/", $source, $match))->toBe(1);
                $shipped[$key] = $match[1];
            }
        }
        config()->set('quickbooks.sync', $shipped);

        try {
            snapshotMigration()->up();
        } finally {
            $packaged = config('quickbooks.sync');
            config()->set('quickbooks.sync', $before);
        }

        $default = snapshotGet(SettingsKeys::companySync('org-default'));
        $chosen  = snapshotGet(SettingsKeys::companySync('org-chosen'));
        foreach (['customer', 'invoice', 'payment', 'wallet'] as $type) {
            expect($packaged[$type . '_conflict'])->toBe('quickbooks')
                ->and($packaged[$type . '_reference'])->toBe('quickbooks')
                ->and($default[$type . '_conflict'])->toBe('fleetbase')
                ->and($default[$type . '_reference'])->toBe('fleetbase');
        }
        expect($chosen['invoice_conflict'])->toBe('quickbooks')
            ->and($chosen['invoice_reference'])->toBe('quickbooks')
            ->and($chosen['customer_conflict'])->toBe('fleetbase');
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('the migration skips complete copies, is idempotent, and later global edits do not change a migrated organization', function () {
    snapshotWithDatabase(function () {
        snapshotTables();
        snapshotPut(SettingsKeys::adminSync(), qbSettings(['interval_minutes' => 15]));
        snapshotConnect('org-copy', 'realm-1');
        snapshotConnect('org-new', 'realm-2');
        $own = array_merge(SettingsStore::onlySyncFields(qbSettings(['interval_minutes' => 3])), ['scope' => 'organization']);
        snapshotPut(SettingsKeys::companySync('org-copy'), $own);
        $migration = snapshotMigration();

        $migration->up();
        $first = DB::table('settings')->orderBy('key')->get()->toArray();
        $migration->up();
        $migration->down();
        $second = DB::table('settings')->orderBy('key')->get()->toArray();

        DB::table('settings')->where('key', SettingsKeys::adminSync())->update(['value' => json_encode(qbSettings(['interval_minutes' => 60]))]);
        $migration->up();

        expect($second)->toEqual($first)
            ->and(snapshotGet(SettingsKeys::companySync('org-copy')))->toBe($own)
            ->and(snapshotGet(SettingsKeys::companySync('org-new'))['interval_minutes'])->toBe(15);
    });
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
