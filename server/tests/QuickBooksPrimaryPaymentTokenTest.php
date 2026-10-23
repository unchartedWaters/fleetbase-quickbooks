<?php

use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * A payment Fleetbase sends raises the SyncToken of the QuickBooks invoice it pays by one.
 * Only that one step is taken for the payment: anything more is a QuickBooks change.
 */

test('a quickbooks edit made while fleetbase sends a payment is not taken for the payment', function () {
    [$engine, $client, $ledger]   = Primary::linkedInvoice('1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->paymentsBumpInvoices = true;
    $client->beforeCreatePayment  = static function (FakeQuickBooks $fake): void {
        $fake->invoices['qb-1']['DueDate']   = '2026-10-31';
        $fake->invoices['qb-1']['SyncToken'] = (string) ((int) $fake->invoices['qb-1']['SyncToken'] + 1);
    };

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->invoices['qb-1']['SyncToken'])->toBe('3')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('1');

    $client->beforeCreatePayment = null;
    Primary::runAgain($engine, $ledger, Primary::shippedSettings(), time() + 120);

    expect($ledger->invoices['inv-1']['due_date'])->toBe('2026-10-31')
        ->and($client->invoices['qb-1']['DueDate'])->toBe('2026-10-31');
});

test('a payment batch that stops part way still keeps the invoice tokens of the payments sent', function () {
    $paid                         = ['status' => 'paid', 'amount_paid' => 1000];
    [$engine, $client, $ledger]   = Primary::linkedInvoice('1', $paid);
    $ledger->invoices['inv-2']    = Primary::invoice(['uuid' => 'inv-2', 'number' => 'INV-2'] + $paid);
    $client->invoices['qb-2']     = Primary::remoteInvoice('4', ['Id' => 'qb-2', 'DocNumber' => 'INV-2']);
    $ledger->links[]              = Primary::link('invoice', 'inv-2', 'qb-2', '4');
    $ledger->pending[]            = Primary::pending('invoice', 'inv-2');
    $client->paymentsBumpInvoices = true;
    $client->beforeCreatePayment  = static function (FakeQuickBooks $fake, array $payload): void {
        if (($payload['Line'][0]['LinkedTxn'][0]['TxnId'] ?? '') === 'qb-2') {
            throw new QuickBooksException(429, 'quickbooks.messages.rate-limited');
        }
    };

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect($client->calls)->toContain('batch')
        ->and($client->invoices['qb-1']['SyncToken'])->toBe('2')
        ->and(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('2');
});

test('a quickbooks edit made while a payment is sent is kept when the payment leaves the invoice token', function () {
    [$engine, $client, $ledger]   = Primary::linkedInvoice('1', ['status' => 'paid', 'amount_paid' => 1000]);
    $client->paymentsBumpInvoices = false;
    $client->beforeCreatePayment  = static function (FakeQuickBooks $fake): void {
        $fake->invoices['qb-1']['DueDate']   = '2026-10-31';
        $fake->invoices['qb-1']['SyncToken'] = (string) ((int) $fake->invoices['qb-1']['SyncToken'] + 1);
    };
    $settings = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect(Primary::token($ledger, 'invoice', 'inv-1'))->toBe('1');

    $client->beforeCreatePayment = null;
    Primary::webhook($engine, $ledger, 'Invoice', 'qb-1');

    expect($ledger->invoices['inv-1']['due_date'])->toBe('2026-10-31');

    $ledger->invoices['inv-1'] = array_merge($ledger->invoices['inv-1'], Primary::withDetention());
    Primary::runAgain($engine, $ledger, $settings, time() + 600);

    expect($client->calls)->toContain('updateInvoice')
        ->and($client->invoices['qb-1']['DueDate'])->toBe('2026-10-31')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-10-31');
});
