<?php

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\MemorySettingsStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Runs one webhook delivery that deletes a linked customer, invoice, wallet and payment in
 * QuickBooks against an in-memory database, and returns what is left in Fleetbase.
 *
 * @param array<string, mixed> $sync          admin sync settings for the delivery
 * @param int                  $walletBalance minor units held in the linked wallet
 *
 * @return array<string, bool>
 */
function rdgDeliver(array $sync, int $walletBalance = 0): array
{
    $defaultConnection = config('database.default');
    $sqliteConnection  = config('database.connections.sqlite');
    $ledgerConnection  = config('fleetbase.connection.db');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('fleetbase.connection.db', 'sqlite');
    DB::purge('sqlite');
    $schema = DB::connection('sqlite')->getSchemaBuilder();

    try {
        $schema->create('quickbooks_links', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('realm_id');
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('qbo_entity');
            $table->string('qbo_id');
            $table->string('sync_token')->default('0');
            $table->timestamps();
        });
        $schema->create('quickbooks_pending_syncs', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36);
            $table->string('local_type');
            $table->char('local_uuid', 36);
            $table->string('reason')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('ledger_invoices', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->integer('total_amount')->default(0);
            $table->integer('amount_paid')->default(0);
            $table->integer('tax')->default(0);
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('ledger_wallets', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->integer('balance')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        $now = now();
        DB::table('contacts')->insert(['uuid' => 'cust-1', 'company_uuid' => 'company-a', 'name' => 'Ada', 'type' => 'customer', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('ledger_invoices')->insert([
            ['uuid' => 'inv-1', 'company_uuid' => 'company-a', 'total_amount' => 1000, 'amount_paid' => 0, 'tax' => 0, 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'inv-paid', 'company_uuid' => 'company-a', 'total_amount' => 2500, 'amount_paid' => 2500, 'tax' => 0, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('ledger_wallets')->insert(['uuid' => 'wal-1', 'company_uuid' => 'company-a', 'name' => 'Operating', 'status' => 'active', 'balance' => $walletBalance, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('quickbooks_links')->insert([
            ['uuid' => 'link-cust', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-1', 'qbo_entity' => 'Customer', 'qbo_id' => '1', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-1', 'qbo_entity' => 'Invoice', 'qbo_id' => '8', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-inv-paid', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'invoice', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Invoice', 'qbo_id' => '10', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-pay', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'payment', 'local_uuid' => 'inv-paid', 'qbo_entity' => 'Payment', 'qbo_id' => '4', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'link-wal', 'company_uuid' => 'company-a', 'realm_id' => 'realm-1', 'local_type' => 'wallet', 'local_uuid' => 'wal-1', 'qbo_entity' => 'Account', 'qbo_id' => '7', 'sync_token' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $store                                  = new MemorySettingsStore();
        $store->rows[SettingsKeys::adminSync()] = $sync;
        $listener                               = new EnqueueWebhookSync(new Fleetbase\Quickbooks\Services\SettingsService(new CredentialResolver(), new SyncSettingsResolver(), new SecretCipher()));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'customer', '1', 'delete', 'cust-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'invoice', '8', 'delete', 'inv-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'wallet', '7', 'delete', 'wal-1'));
        $listener->handle(new QuickBooksEntityChanged('company-a', 'realm-1', 'payment', '4', 'delete', 'inv-paid'));
        $listener->flush($store);

        return [
            'customer_removed' => DB::table('contacts')->where('uuid', 'cust-1')->whereNotNull('deleted_at')->exists(),
            'invoice_voided'   => (string) DB::table('ledger_invoices')->where('uuid', 'inv-1')->value('status') === 'void',
            'payment_unmarked' => (string) DB::table('ledger_invoices')->where('uuid', 'inv-paid')->value('status') === 'sent',
            'wallet_closed'    => DB::table('ledger_wallets')->where('uuid', 'wal-1')->whereNotNull('deleted_at')->exists(),
            'links_kept'       => DB::table('quickbooks_links')->count() === 5,
            'wallet_link_kept' => DB::table('quickbooks_links')->where('uuid', 'link-wal')->exists(),
        ];
    } finally {
        DB::purge('sqlite');
        config()->set('database.default', $defaultConnection);
        config()->set('database.connections.sqlite', $sqliteConnection);
        config()->set('fleetbase.connection.db', $ledgerConnection);
    }
}

/**
 * @return array<string, mixed>
 */
function rdgSync(string $direction, string $conflict, array $extra = []): array
{
    $sync = [];
    foreach (['customer', 'invoice', 'payment', 'wallet'] as $type) {
        $sync[$type . '_direction'] = $direction;
        $sync[$type . '_conflict']  = $conflict;
    }

    // Wallets default to off for a fresh install, so these tests switch them on explicitly.
    return array_merge($sync, ['wallet_enabled' => true], $extra);
}

test('a quickbooks delete is applied when quickbooks is primary and the direction takes quickbooks changes', function () {
    $both    = rdgDeliver(rdgSync('both', 'quickbooks'));
    $inbound = rdgDeliver(rdgSync('inbound', 'fleetbase'));

    expect($both['customer_removed'])->toBeTrue()
        ->and($both['invoice_voided'])->toBeTrue()
        ->and($both['payment_unmarked'])->toBeTrue()
        ->and($both['wallet_closed'])->toBeTrue()
        ->and($both['links_kept'])->toBeFalse()
        ->and($inbound['customer_removed'])->toBeTrue()
        ->and($inbound['invoice_voided'])->toBeTrue()
        ->and($inbound['payment_unmarked'])->toBeTrue()
        ->and($inbound['wallet_closed'])->toBeTrue();
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete is ignored for a type that only syncs to quickbooks', function () {
    expect(rdgDeliver(rdgSync('outbound', 'quickbooks')))->toBe([
        'customer_removed' => false,
        'invoice_voided'   => false,
        'payment_unmarked' => false,
        'wallet_closed'    => false,
        'links_kept'       => true,
        'wallet_link_kept' => true,
    ]);
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete is ignored for a type that is switched off', function () {
    $off = ['customer_enabled' => false, 'invoice_enabled' => false, 'payment_enabled' => false, 'wallet_enabled' => false];

    expect(rdgDeliver(rdgSync('both', 'quickbooks', $off)))->toBe([
        'customer_removed' => false,
        'invoice_voided'   => false,
        'payment_unmarked' => false,
        'wallet_closed'    => false,
        'links_kept'       => true,
        'wallet_link_kept' => true,
    ]);
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete is ignored when fleetbase is primary', function () {
    expect(rdgDeliver(rdgSync('both', 'fleetbase')))->toBe([
        'customer_removed' => false,
        'invoice_voided'   => false,
        'payment_unmarked' => false,
        'wallet_closed'    => false,
        'links_kept'       => true,
        'wallet_link_kept' => true,
    ]);
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete is ignored for a type that is switched off while other types still apply', function () {
    $result = rdgDeliver(rdgSync('both', 'quickbooks', ['wallet_enabled' => false, 'customer_enabled' => false]));

    expect($result['wallet_closed'])->toBeFalse()
        ->and($result['customer_removed'])->toBeFalse()
        ->and($result['invoice_voided'])->toBeTrue()
        ->and($result['payment_unmarked'])->toBeTrue();
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('each type is gated on its own settings', function () {
    $result = rdgDeliver(array_merge(rdgSync('both', 'quickbooks'), [
        'wallet_conflict'    => 'fleetbase',
        'customer_direction' => 'outbound',
    ]));

    expect($result['wallet_closed'])->toBeFalse()
        ->and($result['customer_removed'])->toBeFalse()
        ->and($result['invoice_voided'])->toBeTrue()
        ->and($result['payment_unmarked'])->toBeTrue();
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete does not close a wallet that still holds a balance', function () {
    $result = rdgDeliver(rdgSync('both', 'quickbooks'), 2500);

    expect($result['wallet_closed'])->toBeFalse()
        ->and($result['wallet_link_kept'])->toBeTrue()
        ->and($result['invoice_voided'])->toBeTrue()
        ->and($result['customer_removed'])->toBeTrue();
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete closes a wallet whose balance is zero and drops its link', function () {
    $result = rdgDeliver(rdgSync('both', 'quickbooks'), 0);

    expect($result['wallet_closed'])->toBeTrue()
        ->and($result['wallet_link_kept'])->toBeFalse();
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');

test('a quickbooks delete does not close a wallet with a negative balance', function () {
    expect(rdgDeliver(rdgSync('both', 'quickbooks'), -300)['wallet_closed'])->toBeFalse();
})->skip(in_array('sqlite', PDO::getAvailableDrivers(), true) === false, 'PDO SQLite is unavailable.');
