<?php

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Http\Client\Factory;

uses()->beforeEach(function () {
    Illuminate\Support\Facades\Http::swap(new Factory());
})->in(__DIR__);

function qbSettings(array $overrides = []): array
{
    return array_merge([
        'enabled'                 => true,
        'interval_minutes'        => 5,
        'periodic_interval_hours' => 24,
        'batch_size'              => 100,
        'retry_limit'             => 5,
        'default_backoff_seconds' => 30,
        'customer_conflict'       => 'fleetbase',
        'customer_reference'      => 'fleetbase',
        'customer_direction'      => 'both',
        'invoice_conflict'        => 'fleetbase',
        'invoice_reference'       => 'fleetbase',
        'invoice_direction'       => 'both',
        'payment_conflict'        => 'fleetbase',
        'payment_reference'       => 'fleetbase',
        'payment_direction'       => 'both',
        'wallet_conflict'         => 'fleetbase',
        'wallet_reference'        => 'fleetbase',
        'wallet_direction'        => 'both',
        'customer_enabled'        => true,
        'invoice_enabled'         => true,
        'payment_enabled'         => true,
        'wallet_enabled'          => true,
    ], $overrides);
}

function qbEngine(?FakeQuickBooks $client = null): array
{
    $client ??= new FakeQuickBooks();
    $engine = new SyncEngine(
        $client,
        new CustomerMapper(),
        new InvoiceMapper(),
        new WalletMapper(),
        new BackoffPolicy(static fn (int $wait): int => $wait)
    );

    return [$engine, $client];
}
