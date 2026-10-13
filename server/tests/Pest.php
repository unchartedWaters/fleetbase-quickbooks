<?php

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\Translation;
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

/**
 * An organization sync row as written by a PR #10 era build: marker plus the override flag.
 *
 * @param array<string, mixed> $fields
 *
 * @return array<string, mixed>
 */
function orgRow(array $fields, bool $override = true): array
{
    return array_merge($fields, ['scope' => 'organization', 'override' => $override]);
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

/**
 * Stored and thrown server text is a translation key; this is what an en-us reader sees.
 */
function qbText(?string $stored): ?string
{
    return Translation::render($stored, 'en-us');
}

/**
 * @param array<string, string> $errors
 *
 * @return array<string, string>
 */
function qbTexts(array $errors): array
{
    return array_map(static fn (string $error): string => (string) qbText($error), $errors);
}

/**
 * The message QuickBooksClient throws for a failed request.
 */
function qbRequestFailed(int $status, string $detail = ''): string
{
    return $detail === ''
        ? Translation::encode('quickbooks.messages.request-failed', ['status' => (string) $status])
        : Translation::encode(
            'quickbooks.messages.request-failed-detail',
            ['status' => (string) $status, 'detail' => $detail]
        );
}
