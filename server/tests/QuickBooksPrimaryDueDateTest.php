<?php

use Fleetbase\Quickbooks\Tests\Support\DueDateQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Fleetbase\Quickbooks\Tests\Support\PrimaryFixtures as Primary;

/*
 * QuickBooks always holds an invoice due date. Under QuickBooks as Primary in two-way sync, an
 * empty Fleetbase due date is therefore not a Fleetbase clear: it takes the QuickBooks due date,
 * and no update sends an empty DueDate.
 */

test('a fleetbase invoice created without a due date is not updated on each webhook echo', function () {
    $client            = new DueDateQuickBooks();
    [$engine, $ledger] = Primary::newInvoice($client, ['due_date' => null]);
    $settings          = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $id = Primary::qboId($ledger, 'invoice', 'inv-1');

    for ($cycle = 1; $cycle <= 3; $cycle++) {
        Primary::webhook($engine, $ledger, 'Invoice', $id);
        $ledger->pending = [Primary::pending('invoice', 'inv-1', 'webhook')];
        Primary::runAgain($engine, $ledger, $settings, time() + 120 * $cycle);
    }

    expect(Primary::count($client, 'updateInvoice'))->toBe(0)
        ->and($client->invoices[$id]['DueDate'])->toBe('2026-09-01')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-09-01');
});

test('an empty fleetbase due date takes the due date quickbooks gave the new invoice', function () {
    $client            = new FakeQuickBooks();
    [$engine, $ledger] = Primary::newInvoice($client, ['due_date' => null]);
    $settings          = Primary::shippedSettings();
    $engine->runScheduled($ledger, 'company-uuid', $settings, time());
    $id                               = Primary::qboId($ledger, 'invoice', 'inv-1');
    $client->invoices[$id]['DueDate'] = '2026-10-01';

    Primary::webhook($engine, $ledger, 'Invoice', $id);
    $ledger->pending = [Primary::pending('invoice', 'inv-1', 'webhook')];
    Primary::runAgain($engine, $ledger, $settings, time() + 120);

    expect(Primary::count($client, 'updateInvoice'))->toBe(0)
        ->and($client->invoices[$id]['DueDate'])->toBe('2026-10-01')
        ->and($ledger->invoices['inv-1']['due_date'])->toBe('2026-10-01');
});

test('a fleetbase line edit on an invoice without a due date does not send an empty due date', function () {
    $invoice                    = Primary::withDetention() + ['due_date' => null];
    [$engine, $client, $ledger] = Primary::linkedInvoice('1', $invoice, [], new DueDateQuickBooks());

    $engine->runScheduled($ledger, 'company-uuid', Primary::shippedSettings(), time());

    expect(Primary::count($client, 'updateInvoice'))->toBe(1)
        ->and($client->invoiceUpdates[0])->not->toHaveKey('DueDate')
        ->and($client->invoices['qb-1']['DueDate'])->toBe('2026-09-15')
        ->and(array_column($client->invoices['qb-1']['Line'], 'Description'))->toContain('Detention 2h');
});
