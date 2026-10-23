<?php

use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks as Primary in two-way sync: the follow-up that sends what a first link left in
 * Fleetbase and QuickBooks lacks, in the same run, and the links a match stores before it.
 */

test('a first-link follow-up does not run once quickbooks asks to sign in again', function () {
    [$engine, $client, $ledger]           = Primary::linkedCustomer('', '3', '555-0000', '555-0000');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->customers['cust-2']          = Primary::customer([
        'uuid'  => 'cust-2',
        'name'  => 'Bo',
        'email' => 'bo@example.test',
    ]);
    $ledger->pending      = [Primary::pending('customer', 'cust-1'), Primary::pending('customer', 'cust-2')];
    $client->failWith     = new QuickBooksException(401, qbRequestFailed(401));
    $client->failOnCreate = 1;

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue()
        ->and($client->calls)->not->toContain('updateCustomer')
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('~3');
});

test('a follow-up halted by a rate limit keeps the first pass of the records it did not reach', function () {
    $client = new class extends FakeQuickBooks {
        public function updateCustomer(array $connection, string $id, string $syncToken, array $payload): array
        {
            $this->calls[] = 'updateCustomer';
            throw new QuickBooksException(429, 'quickbooks.messages.rate-limited', '30');
        }
    };
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = Primary::ledger();
    $client->customers['qbo-customer'] = Primary::remoteCustomer('3');
    $client->customers['qbo-bo']       = Primary::remoteCustomer('5', [
        'Id'               => 'qbo-bo',
        'DisplayName'      => 'Bo',
        'PrimaryEmailAddr' => ['Address' => 'bo@example.test'],
    ]);
    $ledger->customers['cust-1'] = Primary::customer(['notes' => 'Back door']);
    $ledger->customers['cust-2'] = Primary::customer([
        'uuid'  => 'cust-2',
        'name'  => 'Bo',
        'email' => 'bo@example.test',
        'notes' => 'Side gate',
    ]);
    $ledger->links   = [
        Primary::link('customer', 'cust-1', 'qbo-customer', ''),
        Primary::link('customer', 'cust-2', 'qbo-bo', ''),
    ];
    $ledger->pending = [Primary::pending('customer', 'cust-1'), Primary::pending('customer', 'cust-2')];

    $batch = $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect(Primary::count($client, 'updateCustomer'))->toBe(1)
        ->and(array_column($ledger->attempts, 'local_uuid'))->toContain('cust-2')
        ->and($batch['aligned'])->toBe(2)
        ->and($batch['failed'])->toBe(1)
        ->and(Primary::token($ledger, 'customer', 'cust-1'))->toBe('~3')
        ->and(Primary::token($ledger, 'customer', 'cust-2'))->toBe('~5');
});

test('a first-pass note moves onto the follow-up row, so the record is reported once', function () {
    [$engine, $client, $ledger]           = Primary::linkedCustomer('', '3');
    $ledger->customers['cust-1']['notes'] = 'Back door';
    $ledger->pending[]                    = Primary::pending('customer', 'cust-1');

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect(array_column($ledger->attempts, 'outcome'))->toBe(['updated'])
        ->and(Primary::notes($ledger))->toHaveCount(1)
        ->and($ledger->customers['cust-1']['phone'])->toBe('555-0000')
        ->and($client->customers['qbo-customer']['Notes'])->toBe('Back door');
});

test('a first link on a paid invoice sends what quickbooks lacks once the payment is in', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice(
        '',
        ['status' => 'paid', 'amount_paid' => 1000, 'notes' => 'Call first'],
        ['SyncToken' => '3']
    );
    $client->paymentsBumpInvoices = true;
    $settings                     = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->invoices['qb-1']['PrivateNote'] ?? null)->toBe('Call first')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe((string) $client->invoices['qb-1']['SyncToken']);
});

test('a quickbooks edit made while a first-linked invoice is paid is not taken for the payment', function () {
    [$engine, $client, $ledger] = Primary::linkedInvoice(
        '',
        ['status' => 'paid', 'amount_paid' => 1000, 'notes' => 'Call first'],
        ['SyncToken' => '3']
    );
    $client->paymentsBumpInvoices = false;
    $client->beforeCreatePayment  = static function (FakeQuickBooks $fake): void {
        $fake->invoices['qb-1']['DueDate']   = '2026-10-01';
        $fake->invoices['qb-1']['SyncToken'] = (string) ((int) $fake->invoices['qb-1']['SyncToken'] + 1);
    };
    $settings = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $client->beforeCreatePayment = null;
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect($client->invoices['qb-1']['DueDate'])->toBe('2026-10-01')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-10-01')
        ->and($client->invoices['qb-1']['PrivateNote'] ?? null)->toBe('Call first');
});

test('an invoice matched by number whose update fails stores no token', function () {
    $client                     = new FakeQuickBooks();
    $client->failInvoiceUpdates = true;
    [$engine, $ledger]          = Primary::newInvoice($client, ['notes' => 'Call first']);
    $client->invoices['qb-1']   = Primary::remoteInvoice('4');
    $client->invoiceByDoc       = null;

    $settings = Primary::shippedSettings(['invoice_conflict' => 'fleetbase']);
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect(Primary::qboId($ledger, 'invoice', 'inv-1'))->toBe('qb-1')
        ->and($client->calls)->toContain('updateInvoice')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('');
});

test('an invoice that followed its quickbooks customer stays in sync when that link is read on demand', function () {
    [$engine, $client, $ledger]              = Primary::linkedInvoice('1');
    $client->invoices['qb-1']['SyncToken']   = '2';
    $client->invoices['qb-1']['CustomerRef'] = ['value' => 'qbo-x'];
    $ledger->customers['cust-2']             = Primary::customer(['uuid' => 'cust-2', 'name' => 'Bo']);
    $client->customers['qbo-x']              = Primary::remoteCustomer('0', [
        'Id'               => 'qbo-x',
        'DisplayName'      => 'Bo',
        'PrimaryEmailAddr' => ['Address' => 'bo@example.test'],
    ]);
    $ledger->invoices['inv-1']['customer_uuid'] = 'cust-2';
    $customerLink                               = Primary::link('customer', 'cust-2', 'qbo-x', '0');
    $ledger->customerLinkReader                 = static fn (string $realm, string $id): ?array => $id === 'qbo-x'
        ? $customerLink
        : null;

    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    $link = $ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1');
    expect($ledger->invoices['inv-1']['customer_uuid'])->toBe('cust-2')
        ->and($link['sync_token'])->toBe('2')
        ->and($link['kept_customer'] ?? null)->toBeNull()
        ->and($client->calls)->not->toContain('updateInvoice');
});
