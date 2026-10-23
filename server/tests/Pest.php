<?php

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\Translation;
use Fleetbase\Quickbooks\Support\WalletMapper;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Yaml\Yaml;

uses()->beforeEach(function () {
    Illuminate\Support\Facades\Http::swap(new Factory());
    // The bootstrap cache is one array store for the whole process; a test starts with it empty.
    try {
        Illuminate\Support\Facades\Cache::flush();
    } catch (Throwable) {
        // No cache bound: nothing to clear.
    }
    // Tests repeat manual actions for one organization in one shared cache. The cooldown
    // tests turn it on themselves.
    config()->set('quickbooks.manual_cooldown_seconds', 0);
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
    if ($status === 400 && $detail !== '') {
        return Translation::encode('quickbooks.messages.request-rejected', ['detail' => $detail]);
    }

    return $detail === ''
        ? Translation::encode('quickbooks.messages.request-failed', ['status' => (string) $status])
        : Translation::encode(
            'quickbooks.messages.request-failed-detail',
            ['status' => (string) $status, 'detail' => $detail]
        );
}

function qbTranslationKeys(): array
{
    $keys    = [];
    $flatten = function (array $tree, string $prefix) use (&$flatten, &$keys): void {
        foreach ($tree as $name => $value) {
            if (is_array($value) === true) {
                $flatten($value, $prefix . $name . '.');
            } else {
                $keys[$prefix . $name] = (string) $value;
            }
        }
    };
    $flatten(Yaml::parseFile(dirname(__DIR__, 2) . '/translations/en-us.yaml'), '');

    return $keys;
}

/**
 * Adds a second translations file for one test, so locale selection has something to choose.
 */
function qbWithExtraLocale(callable $body): void
{
    $file  = dirname(__DIR__, 2) . '/translations/zz-zz.yaml';
    $reset = static function (): void {
        $class = new ReflectionClass(Translation::class);
        $class->setStaticPropertyValue('catalogs', []);
        $class->setStaticPropertyValue('locales', null);
    };
    file_put_contents($file, implode("\n", [
        'quickbooks:',
        '  messages:',
        '    sync-failed: Synchro échouée.',
        '    reauth: Reconnectez QuickBooks.',
        '',
    ]));
    $reset();

    try {
        $body();
    } finally {
        unlink($file);
        $reset();
    }
}
