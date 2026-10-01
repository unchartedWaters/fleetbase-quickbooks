<?php

use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;

test('voiding an invoice marks it voided and a second void is rejected', function () {
    $client                    = new FakeQuickBooks();
    $client->invoices['inv-1'] = [
        'Id'        => 'inv-1',
        'SyncToken' => '0',
        'DocNumber' => 'INV-1',
    ];

    $voided = $client->voidInvoice([], 'inv-1', '0');

    expect($voided)->toBe(['Id' => 'inv-1', 'SyncToken' => '1'])
        ->and($client->invoices['inv-1']['voided'])->toBeTrue()
        ->and($client->invoices['inv-1']['SyncToken'])->toBe('1')
        ->and($client->invoices['inv-1']['DocNumber'])->toBe('INV-1')
        ->and($client->calls)->toContain('voidInvoice');

    $rejected = null;
    expect(function () use ($client, &$rejected) {
        try {
            $client->voidInvoice([], 'inv-1', '1');
        } catch (QuickBooksException $exception) {
            $rejected = $exception;
            throw $exception;
        }
    })->toThrow(QuickBooksException::class, 'already voided');

    expect($rejected)->toBeInstanceOf(QuickBooksException::class)
        ->and($rejected->status)->toBe(400)
        ->and($rejected->getMessage())->toContain('already voided');
});

test('voiding an invoice that was not stored still records it as voided', function () {
    $client = new FakeQuickBooks();

    $voided = $client->voidInvoice([], 'inv-missing', '2');

    expect($voided)->toBe(['Id' => 'inv-missing', 'SyncToken' => '3'])
        ->and($client->invoices['inv-missing']['voided'])->toBeTrue()
        ->and($client->invoices['inv-missing']['SyncToken'])->toBe('3');

    expect(fn () => $client->voidInvoice([], 'inv-missing', '3'))
        ->toThrow(QuickBooksException::class, 'already voided');
});
