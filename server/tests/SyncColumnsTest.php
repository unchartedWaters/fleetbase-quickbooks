<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\SyncColumns;
use Fleetbase\Quickbooks\Support\SyncColumnsTransformer;
use Fleetbase\Quickbooks\Tests\Support\ImportDatabase;
use Fleetbase\Quickbooks\Tests\Support\SyncColumnsDatabase as Fixture;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/*
 * The QuickBooks columns on the Fleet-Ops Customers and Ledger Invoices tables: the console sorts and
 * searches through the plugin's own tables and reads the values from each row's meta.
 */

beforeEach(function () {
    Container::getInstance()->instance(Authorizer::class, new Authorizer(static fn (): bool => true));
});

afterEach(function () {
    Container::getInstance()->forgetInstance(Authorizer::class);
});

test('customers sort by their QuickBooks ID and ignore another organization\'s link', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        ImportDatabase::contact('c-2', 'Two');
        ImportDatabase::contact('c-3', 'Three');
        Fixture::link('c-1', 'B-2');
        Fixture::link('c-2', 'A-1');
        Fixture::link('c-3', 'Z-9', 'customer', 'company-2');
        // The same uuid linked as an invoice is not the customer's link.
        Fixture::link('c-3', 'Y-8', 'invoice');

        expect(Fixture::sortedUuids(Contact::class, 'quickbooks_id'))->toBe(['c-3', 'c-2', 'c-1'])
            ->and(Fixture::sortedUuids(Contact::class, '-quickbooks_id'))->toBe(['c-1', 'c-2', 'c-3'])
            ->and(Fixture::sortedUuids(Customer::class, '-quickbooks_id'))->toBe(['c-1', 'c-2', 'c-3']);
    } finally {
        $restore();
    }
});

test('customers sort by when their QuickBooks link last changed', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        ImportDatabase::contact('c-2', 'Two');
        Fixture::link('c-1', 'A-1', 'customer', 'company-uuid', '2026-11-22 08:00:00');
        Fixture::link('c-2', 'A-2', 'customer', 'company-uuid', '2026-11-21 08:00:00');

        expect(Fixture::sortedUuids(Contact::class, 'quickbooks_synced_at'))->toBe(['c-2', 'c-1'])
            ->and(Fixture::sortedUuids(Contact::class, '-quickbooks_synced_at'))->toBe(['c-1', 'c-2']);
    } finally {
        $restore();
    }
});

test('invoices sort by their QuickBooks ID from invoice links only', function () {
    $restore = Fixture::open();
    try {
        Fixture::invoice('i-1', 'INV-1');
        Fixture::invoice('i-2', 'INV-2');
        Fixture::link('i-1', 'B-2', 'invoice');
        Fixture::link('i-2', 'A-1', 'invoice');
        Fixture::link('i-1', 'A-0', 'customer');

        expect(Fixture::sortedUuids(Invoice::class, 'quickbooks_id'))->toBe(['i-2', 'i-1']);
    } finally {
        $restore();
    }
});

test('search finds a customer or an invoice by its QuickBooks ID inside the same organization', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        ImportDatabase::contact('c-2', 'Two');
        ImportDatabase::contact('c-3', 'Three', ['company_uuid' => 'company-2']);
        Fixture::invoice('i-1', 'INV-1');
        Fixture::link('c-1', 'QB-77');
        Fixture::link('c-2', 'QB-88');
        // A link of another organization that points at c-3 by uuid only.
        Fixture::link('c-3', 'QB-77', 'customer', 'company-uuid');
        Fixture::link('i-1', 'QB-55', 'invoice');

        $customers = Contact::query()->search('qb-77')->orderBy('uuid')->pluck('uuid')->all();
        $invoices  = Invoice::query()->search('QB-55')->pluck('uuid')->all();

        expect($customers)->toBe(['c-1'])
            ->and($invoices)->toBe(['i-1'])
            ->and(Contact::query()->search('QB-55')->count())->toBe(0);
    } finally {
        $restore();
    }
});

test('customer rows carry their sync status, QuickBooks ID and last sync time in meta', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-synced', 'A', ['meta' => json_encode(['vip' => true])]);
        ImportDatabase::contact('c-waiting', 'B');
        ImportDatabase::contact('c-failed', 'C');
        ImportDatabase::contact('c-retried', 'D');
        ImportDatabase::contact('c-new', 'E');
        Fixture::link('c-synced', 'QB-1');
        Fixture::link('c-waiting', 'QB-2');
        Fixture::link('c-failed', 'QB-3');
        Fixture::pending('c-waiting', 'pending');
        Fixture::pending('c-failed', 'failed', 'customer', '2026-11-21 10:00:00');
        // Failed first, then sent: the later done row wins.
        Fixture::pending('c-retried', 'failed', 'customer', '2026-11-20 10:00:00');
        Fixture::pending('c-retried', 'done', 'customer', '2026-11-21 10:00:00');
        Fixture::link('c-retried', 'QB-4');

        $rows = Fixture::decorated(
            Contact::query()->orderBy('uuid')->get()->all(),
            Fixture::request('fleet-ops/int/v1/contacts')
        );
        $byUuid = array_column($rows, 'meta', 'uuid');

        expect($byUuid['c-synced']['quickbooks'])
            ->toBe(['status' => 'synced', 'id' => 'QB-1', 'synced_at' => '2026-11-20T10:00:00+00:00'])
            ->and($byUuid['c-synced']['vip'])->toBeTrue()
            ->and($byUuid['c-waiting']['quickbooks']['status'])->toBe('waiting')
            ->and($byUuid['c-failed']['quickbooks']['status'])->toBe('failed')
            ->and($byUuid['c-retried']['quickbooks']['status'])->toBe('synced')
            ->and($byUuid['c-new']['quickbooks'])->toBe(['status' => 'not_synced', 'id' => null, 'synced_at' => null]);
    } finally {
        $restore();
    }
});

test('invoice rows are decorated from invoice links', function () {
    $restore = Fixture::open();
    try {
        Fixture::invoice('i-1', 'INV-1');
        Fixture::link('i-1', 'QB-9', 'invoice');
        Fixture::link('i-1', 'QB-1', 'customer');

        $rows = Fixture::decorated(Invoice::query()->get()->all(), Fixture::request('ledger/int/v1/invoices'));

        expect($rows[0]['meta']['quickbooks']['id'])->toBe('QB-9')
            ->and($rows[0]['meta']['quickbooks']['status'])->toBe('synced');
    } finally {
        $restore();
    }
});

test('a list is decorated with a fixed number of queries', function () {
    $restore = Fixture::open();
    try {
        foreach (range(1, 12) as $number) {
            ImportDatabase::contact('c-' . $number, 'Customer ' . $number);
            Fixture::link('c-' . $number, 'QB-' . $number);
        }
        $models = Contact::query()->get()->all();
        DB::connection('sqlite')->enableQueryLog();

        Fixture::decorated($models, Fixture::request('fleet-ops/int/v1/contacts'));

        expect(DB::connection('sqlite')->getQueryLog())->toHaveCount(2);
    } finally {
        $restore();
    }
});

test('a link of another organization never decorates a row', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        Fixture::link('c-1', 'QB-1', 'customer', 'company-2');
        Fixture::pending('c-1', 'failed', 'customer', '2026-11-21 10:00:00', 'company-2');

        $rows = Fixture::decorated(Contact::query()->get()->all(), Fixture::request('fleet-ops/int/v1/contacts'));

        expect($rows[0]['meta']['quickbooks'])->toBe(['status' => 'not_synced', 'id' => null, 'synced_at' => null]);
    } finally {
        $restore();
    }
});

test('rows are left alone outside their own list, for plain contacts and for people without access', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        ImportDatabase::contact('c-2', 'Vendor contact', ['type' => 'contact']);
        Fixture::link('c-1', 'QB-1');
        $models = Contact::query()->orderBy('uuid')->get()->all();

        // A customer nested in another resource, such as an order, costs no queries.
        $nested = Fixture::decorated($models, Fixture::request('fleet-ops/int/v1/orders'));
        expect($nested[0]['meta'])->toBe([]);

        $own = Fixture::decorated($models, Fixture::request('fleet-ops/int/v1/contacts/{id}'));
        expect($own[0]['meta']['quickbooks']['id'])->toBe('QB-1')
            ->and($own[1]['meta'])->toBe([]);

        Container::getInstance()->instance(Authorizer::class, new Authorizer(static fn (): bool => false));
        $denied = Fixture::decorated($models, Fixture::request('fleet-ops/int/v1/contacts'));
        expect($denied[0]['meta'])->toBe([]);
    } finally {
        $restore();
    }
});

test('the transformer serves only the console, not the public API, webhooks or broadcasts', function () {
    $restore = Fixture::open();
    try {
        $registry      = ResourceTransformerRegistry::instance();
        $registrations = array_values(array_filter(
            $registry->all(),
            static fn (array $row): bool => $row['id'] === SyncColumnsTransformer::class
        ));

        expect($registrations)->toHaveCount(1)
            ->and($registrations[0]['only'])->toBe('internal')
            ->and($registrations[0]['contexts'])->toBe(['http'])
            ->and($registrations[0]['targets'])->toContain(Contact::class, Invoice::class);
    } finally {
        $restore();
    }
});

test('the sync summary is dropped from a record\'s meta when it is saved', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        Fixture::invoice('i-1', 'INV-1');
        $contact       = Contact::query()->find('c-1');
        $contact->meta = ['vip' => true, 'quickbooks' => ['status' => 'synced']];
        $invoice       = Invoice::query()->find('i-1');
        $invoice->meta = ['quickbooks' => ['status' => 'synced'], 'po' => '7'];
        $plain         = Contact::query()->find('c-1');
        $plain->meta   = ['vip' => true];

        // What Eloquent fires before it writes the row.
        $events = Contact::getEventDispatcher();
        $events->dispatch('eloquent.saving: ' . Contact::class, $contact);
        $events->dispatch('eloquent.saving: ' . Invoice::class, $invoice);
        $events->dispatch('eloquent.saving: ' . Contact::class, $plain);

        expect($contact->meta)->toBe(['vip' => true])
            ->and($invoice->meta)->toBe(['po' => '7'])
            ->and($plain->meta)->toBe(['vip' => true]);
    } finally {
        $restore();
    }
});

test('the registered models are the ones the sync links', function () {
    expect(array_keys(SyncColumns::MODELS))->toBe([Contact::class, Customer::class, Invoice::class]);
});

test('the QuickBooks ID filters customers and invoices inside the same organization', function () {
    $restore = Fixture::open();
    try {
        ImportDatabase::contact('c-1', 'One');
        ImportDatabase::contact('c-2', 'Two');
        ImportDatabase::contact('c-3', 'Three', ['company_uuid' => 'company-2']);
        Fixture::invoice('i-1', 'INV-1');
        Fixture::invoice('i-2', 'INV-2');
        Fixture::link('c-1', 'QB-77');
        Fixture::link('c-2', 'QB-88');
        Fixture::link('c-3', 'QB-77', 'customer', 'company-uuid');
        Fixture::link('i-1', 'QB-55', 'invoice');
        Fixture::link('i-2', 'QB-66', 'customer');

        expect(Fixture::filtered('contacts', 'qb-7'))->toBe(['c-1'])
            ->and(Fixture::filtered('invoices', '55'))->toBe(['i-1'])
            ->and(Fixture::filtered('invoices', 'QB-66'))->toBe([]);
    } finally {
        $restore();
    }
});
