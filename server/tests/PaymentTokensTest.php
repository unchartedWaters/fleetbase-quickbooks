<?php

use Fleetbase\Quickbooks\Support\PaymentTokens;

test('payment tokens drop the invoices a payment no longer pays', function () {
    $link = [PaymentTokens::COLUMN => '{"inv-1":"1","inv-2":"1"}'];

    expect(PaymentTokens::with($link, 'inv-1', '2', ['inv-2']))->toBe('{"inv-1":"2"}')
        ->and(PaymentTokens::with($link, 'inv-1', '2'))->toBe('{"inv-1":"2","inv-2":"1"}');
});

test('payment tokens merge what a run changed into the value another run stored meanwhile', function () {
    $base = '{"inv-1":"1","inv-3":"1","inv-4":"1"}';
    // This run moved inv-1 on and dropped inv-3; another run added inv-2 and dropped inv-4.
    $ours   = '{"inv-1":"2","inv-4":"1"}';
    $theirs = '{"inv-1":"1","inv-2":"3","inv-3":"1"}';

    expect(PaymentTokens::merge($base, $ours, $theirs))->toBe('{"inv-1":"2","inv-2":"3"}')
        ->and(PaymentTokens::merge(null, '{"inv-1":"2"}', null))->toBe('{"inv-1":"2"}')
        ->and(PaymentTokens::merge($base, $base, $theirs))->toBe($theirs);
});
