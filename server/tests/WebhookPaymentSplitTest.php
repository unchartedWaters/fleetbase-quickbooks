<?php

use Fleetbase\Quickbooks\Support\WebhookPaymentReads;
use Fleetbase\Quickbooks\Support\WebhookPaymentTargets;

test('keyed payments are deferred and nothing is read when no quickbooks reader is given', function () {
    $reads = new WebhookPaymentReads(null);

    $result = $reads->remoteInvoicesForKeyed('company-a', ['realm-1' => ['4', '5', '4'], 'realm-2' => ['9']], true);

    expect($result)->toBe([[], []])
        ->and($reads->deferred)->toBe(['realm-1|4' => true, 'realm-1|5' => true, 'realm-2|9' => true])
        ->and($reads->reads)->toBe([]);
});

test('a reader result marks found and missing payments and only missing ones go to the stored lookup on delete', function () {
    $calls  = [];
    $reader = function (string $companyUuid, string $realmId, array $ids) use (&$calls): array {
        $calls[] = [$companyUuid, $realmId, $ids];

        return ['4' => ['11', '12'], '5' => null, '6' => []];
    };

    $deleting = new WebhookPaymentReads($reader);
    $onDelete = $deleting->remoteInvoicesForKeyed('company-a', ['realm-1' => ['4', '5', '6', '7', '4']], true);
    $updating = new WebhookPaymentReads($reader);
    $onUpdate = $updating->remoteInvoicesForKeyed('company-a', ['realm-1' => ['5']], false);

    expect($calls)->toBe([['company-a', 'realm-1', ['4', '5', '6', '7']], ['company-a', 'realm-1', ['5']]])
        ->and($onDelete)->toBe([['realm-1|4' => ['11', '12']], ['realm-1' => ['5']]])
        ->and($deleting->reads)->toBe(['realm-1|4' => 'found', 'realm-1|5' => 'missing', 'realm-1|6' => 'found'])
        ->and($deleting->deferred)->toBe([])
        ->and($onUpdate)->toBe([[], []])
        ->and($updating->reads)->toBe(['realm-1|5' => 'missing']);
});

test('direct links resolve only invoices that are linked in the realm or on file', function () {
    $resolved = (new WebhookPaymentTargets())->resolvePaymentTargets(
        ['realm-1|4' => ['inv-linked', 'inv-on-file', 'inv-unknown'], 'realm-2|5' => ['inv-linked']],
        [],
        [],
        ['by_uuid' => ['realm-1|inv-linked' => true], 'by_qbo' => []],
        ['inv-on-file' => true]
    );

    expect($resolved)->toBe([
        'realm-1|4' => ['invoice' => 'inv-linked', 'invoices' => ['inv-linked', 'inv-on-file'], 'keyed_by_payment' => false, 'quickbooks_invoices' => []],
    ]);
});

test('a keyed payment applied to several invoices resolves each linked one and keeps the quickbooks ids', function () {
    $resolved = (new WebhookPaymentTargets())->resolvePaymentTargets(
        [],
        ['realm-1|4' => ['11', '12', '13', '11']],
        [],
        ['by_uuid' => [], 'by_qbo' => ['realm-1|11' => 'inv-a', 'realm-1|12' => 'inv-b', 'realm-2|13' => 'inv-c']],
        []
    );

    expect($resolved)->toBe([
        'realm-1|4' => ['invoice' => 'inv-a', 'invoices' => ['inv-a', 'inv-b'], 'keyed_by_payment' => true, 'quickbooks_invoices' => ['11', '12', '13', '11']],
    ]);
});

test('the stored invoice map is a fallback used only for payments nothing else resolved', function () {
    $resolved = (new WebhookPaymentTargets())->resolvePaymentTargets(
        [],
        ['realm-1|4' => ['11']],
        ['realm-1|4' => ['inv-stale'], 'realm-1|5' => ['inv-stored', 'inv-on-file', 'inv-gone']],
        ['by_uuid' => ['realm-1|inv-stale' => true, 'realm-1|inv-stored' => true], 'by_qbo' => ['realm-1|11' => 'inv-remote']],
        ['inv-on-file' => true]
    );

    expect($resolved)->toBe([
        'realm-1|4' => ['invoice' => 'inv-remote', 'invoices' => ['inv-remote'], 'keyed_by_payment' => true, 'quickbooks_invoices' => ['11']],
        'realm-1|5' => ['invoice' => 'inv-stored', 'invoices' => ['inv-stored', 'inv-on-file'], 'keyed_by_payment' => true, 'quickbooks_invoices' => []],
    ]);
});
