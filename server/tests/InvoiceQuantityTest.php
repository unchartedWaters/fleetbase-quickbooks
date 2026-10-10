<?php

use Fleetbase\Quickbooks\Support\InvoiceMapper;

/**
 * @return array<string, mixed>
 */
function quantityRemote(mixed $qty, string $amount, ?string $unitPrice): array
{
    $detail = ['Qty' => $qty];
    if ($unitPrice !== null) {
        $detail['UnitPrice'] = $unitPrice;
    }

    return [
        'TotalAmt' => $amount,
        'Line'     => [['Amount' => $amount, 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Labor', 'SalesItemLineDetail' => $detail]],
    ];
}

test('a whole quantity from quickbooks keeps its quantity and unit price', function () {
    $mapped = (new InvoiceMapper())->fromQuickBooks(quantityRemote(3, '30.00', '10.00'));

    expect($mapped['items'])->toBe([['description' => 'Labor', 'quantity' => 3, 'unit_price' => 1000, 'amount' => 3000]]);
});

test('a whole quantity sent as a float or decimal string is still whole', function () {
    $mapper = new InvoiceMapper();

    expect($mapper->fromQuickBooks(quantityRemote(2.0, '20.00', '10.00'))['items'][0]['quantity'])->toBe(2)
        ->and($mapper->fromQuickBooks(quantityRemote('2.00', '20.00', '10.00'))['items'][0]['quantity'])->toBe(2);
});

test('a fractional quantity from quickbooks becomes one unit at the line amount', function () {
    $mapper = new InvoiceMapper();

    foreach ([1.5, '1.5', 0.5] as $qty) {
        $mapped = $mapper->fromQuickBooks(quantityRemote($qty, '15.00', '10.00'));

        expect($mapped['items'])->toBe([['description' => 'Labor', 'quantity' => 1, 'unit_price' => 1500, 'amount' => 1500]])
            ->and($mapped['items'][0]['quantity'] * $mapped['items'][0]['unit_price'])->toBe($mapped['items'][0]['amount'])
            ->and($mapped['total'])->toBe(1500);
    }
});

test('a fractional quantity without a unit price keeps the line amount', function () {
    $mapped = (new InvoiceMapper())->fromQuickBooks(quantityRemote(2.5, '25.00', null));

    expect($mapped['items'][0]['quantity'])->toBe(1)
        ->and($mapped['items'][0]['unit_price'])->toBe(2500)
        ->and($mapped['items'][0]['amount'])->toBe(2500);
});

test('a missing quantity is one', function () {
    $remote = quantityRemote(1, '10.00', '10.00');
    unset($remote['Line'][0]['SalesItemLineDetail']['Qty']);

    expect((new InvoiceMapper())->fromQuickBooks($remote)['items'][0]['quantity'])->toBe(1);
});

test('an outgoing line keeps the amount for the quantity it was given', function () {
    $payload = (new InvoiceMapper())->toQuickBooks([
        'currency' => 'USD',
        'items'    => [['description' => 'Labor', 'quantity' => 3, 'unit_price' => 1000, 'amount' => 3000]],
    ], 'cust-1', 'item-1');
    $detail = $payload['Line'][0]['SalesItemLineDetail'];

    expect($payload['Line'][0]['Amount'])->toBe('30.00')
        ->and($detail['Qty'])->toBe(3)
        ->and($detail['UnitPrice'])->toBe('10.00');
});
