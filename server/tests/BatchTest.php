<?php

use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;

test('an event inserts one pending row and a second event does not insert another', function () {
    $ledger  = batchLedger();
    $flagger = new SyncFlagger();
    $invoice = (object) ['uuid' => 'inv-1', 'company_uuid' => 'company-uuid', 'status' => 'sent'];
    $flagger->fromInvoiceEvent($ledger, $invoice, 'created');
    $flagger->fromInvoiceEvent($ledger, $invoice, 'updated');

    expect($ledger->pending)->toHaveCount(1);
});

test('a wallet event inserts one pending row and a second event does not insert another', function () {
    $ledger  = batchLedger();
    $flagger = new SyncFlagger();
    $wallet  = (object) ['uuid' => 'wal-1', 'company_uuid' => 'company-uuid'];
    $flagger->fromWalletEvent($ledger, $wallet);
    $flagger->fromWalletEvent($ledger, $wallet);

    expect($ledger->pending)->toHaveCount(1)
        ->and($ledger->pending[0]['local_type'])->toBe('wallet');
});

test('a company is skipped when the interval has not elapsed the rate limit is active or it needs reauth and a stored enabled flag does not skip a due sync', function () {
    [$engine, $client] = qbEngine();
    $now               = time();
    $settings          = qbSettings();

    $waiting                                               = batchLedger();
    $waiting->connections['company-uuid']['last_batch_at'] = $now;
    expect($engine->runScheduled($waiting, 'company-uuid', $settings, $now)['status'])->toBe('skipped');

    $disabled                      = batchLedger();
    $disabled->customers['cust-1'] = customerRow('cust-1');
    $disabled->pending[]           = pendingRow('customer', 'cust-1');
    $ran                           = $engine->runScheduled($disabled, 'company-uuid', qbSettings(['enabled' => false]), $now);
    expect($ran['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer');

    $limited                                                    = batchLedger();
    $limited->connections['company-uuid']['rate_limited_until'] = $now + 30;
    expect($engine->runScheduled($limited, 'company-uuid', $settings, $now)['status'])->toBe('skipped');

    $reauth                                              = batchLedger();
    $reauth->connections['company-uuid']['needs_reauth'] = true;
    expect($engine->runScheduled($reauth, 'company-uuid', $settings, $now)['status'])->toBe('skipped');
});

test('a 429 with retry after pauses the company and does not consume the retry count', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new Fleetbase\Quickbooks\Support\QuickBooksException(429, 'slow down', '30');
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $now                         = time();

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), $now);

    expect($ledger->connections['company-uuid']['rate_limited_until'])->toBe($now + 30)
        ->and($ledger->pending[0]['attempts'])->toBe(0)
        ->and($ledger->pending[0]['status'])->toBe('pending');
});

test('a 429 without retry after pauses 60 seconds and a second 429 doubles it', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new Fleetbase\Quickbooks\Support\QuickBooksException(429, 'slow down', null);
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $now                         = time();
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), $now);

    expect($ledger->connections['company-uuid']['rate_limited_until'])->toBe($now + 60);

    $ledger->connections['company-uuid']['rate_limited_until'] = null;
    $ledger->connections['company-uuid']['last_batch_at']      = null;
    $ledger->pending[0]['status']                              = 'pending';
    $client->creates                                           = 0;
    $second                                                    = time();
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), $second);

    expect($ledger->connections['company-uuid']['last_rate_limit_wait'])->toBe(120)
        ->and($ledger->connections['company-uuid']['rate_limited_until'])->toBe($second + 120);
});

test('the rest of the chunk stays pending when a 429 arrives mid batch', function () {
    [$engine, $client]    = qbEngine();
    $client->failOnCreate = 2;
    $client->failWith     = new Fleetbase\Quickbooks\Support\QuickBooksException(429, 'slow down', '30');
    $ledger               = batchLedger();
    foreach (['cust-1', 'cust-2', 'cust-3'] as $uuid) {
        $ledger->customers[$uuid] = array_merge(customerRow($uuid), ['name' => 'Customer ' . $uuid, 'email' => $uuid . '@example.test']);
        $ledger->pending[]        = pendingRow('customer', $uuid);
    }

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $stillPending = array_values(array_filter($ledger->pending, fn ($row) => $row['status'] === 'pending'));

    expect($stillPending)->toHaveCount(2);
});

test('a 401 mid batch stops the batch keeps the rows pending without using attempts and marks the connection for reauth', function () {
    [$engine, $client]    = qbEngine();
    $client->failOnCreate = 2;
    $client->failWith     = new Fleetbase\Quickbooks\Support\QuickBooksException(401, 'QuickBooks request failed with status 401');
    $ledger               = batchLedger();
    foreach (['cust-1', 'cust-2', 'cust-3'] as $uuid) {
        $ledger->customers[$uuid] = array_merge(customerRow($uuid), ['name' => 'Customer ' . $uuid, 'email' => $uuid . '@example.test']);
        $ledger->pending[]        = pendingRow('customer', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $rows  = array_column($ledger->pending, null, 'local_uuid');

    expect($batch['created'])->toBe(1)
        ->and($batch['failed'])->toBe(1)
        ->and(array_count_values($client->calls)['createCustomer'])->toBe(2)
        ->and($rows['cust-1']['status'])->toBe('done')
        ->and($rows['cust-2']['status'])->toBe('pending')
        ->and($rows['cust-2']['attempts'])->toBe(0)
        ->and($rows['cust-2']['next_attempt_at'] ?? null)->toBeNull()
        ->and($rows['cust-3']['status'])->toBe('pending')
        ->and($rows['cust-3']['attempts'])->toBe(0)
        ->and($ledger->connections['company-uuid']['needs_reauth'])->toBeTrue()
        ->and(end($ledger->attempts)['error'])->toBe(Fleetbase\Quickbooks\Services\SyncEngine::TOKEN_REJECTED_MESSAGE);

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $calls                                                = count($client->calls);
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time() + 10);

    expect(count($client->calls))->toBe($calls);
});

test('a timeout or http 500 uses the default backoff and does not set a rate limit', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new Fleetbase\Quickbooks\Support\QuickBooksException(500, 'unavailable');
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $now                         = time();
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'default_backoff_seconds' => 30]), $now);

    expect($ledger->pending[0]['attempts'])->toBe(1)
        ->and($ledger->pending[0]['next_attempt_at'])->toBe($now + 30)
        ->and($ledger->connections['company-uuid']['rate_limited_until'] ?? null)->toBeNull();

    $ledger->pending[0]['next_attempt_at']                = null;
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $client->creates                                      = 0;
    $later                                                = $now + 100;
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'default_backoff_seconds' => 30]), $later);

    expect($ledger->pending[0]['attempts'])->toBe(2)
        ->and($ledger->pending[0]['next_attempt_at'])->toBe($later + 60);
});

test('retry delay doubles on each retry and the row fails only after those retries', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new Fleetbase\Quickbooks\Support\QuickBooksException(500, 'unavailable');
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $settings                    = qbSettings(['interval_minutes' => 1, 'retry_limit' => 3, 'default_backoff_seconds' => 30]);
    $now                         = time();

    $engine->runScheduled($ledger, 'company-uuid', $settings, $now);
    expect($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(1)
        ->and($ledger->pending[0]['next_attempt_at'])->toBe($now + 30);

    $second                                               = $now + 30;
    $ledger->pending[0]['next_attempt_at']                = null;
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $client->creates                                      = 0;
    $engine->runScheduled($ledger, 'company-uuid', $settings, $second);
    expect($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(2)
        ->and($ledger->pending[0]['next_attempt_at'])->toBe($second + 60);

    $third                                                = $second + 60;
    $ledger->pending[0]['next_attempt_at']                = null;
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $client->creates                                      = 0;
    $engine->runScheduled($ledger, 'company-uuid', $settings, $third);
    expect($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(3)
        ->and($ledger->pending[0]['next_attempt_at'])->toBe($third + 120);

    $fourth                                               = $third + 120;
    $ledger->pending[0]['next_attempt_at']                = null;
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $client->creates                                      = 0;
    $engine->runScheduled($ledger, 'company-uuid', $settings, $fourth);
    expect($ledger->pending[0]['status'])->toBe('failed')
        ->and($ledger->pending[0]['attempts'])->toBe(4);

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $calls                                                = count($client->calls);
    $engine->runScheduled($ledger, 'company-uuid', $settings, $fourth + 10);
    expect(count($client->calls))->toBe($calls);
});

test('one retry attempt schedules a retry after the first failure', function () {
    [$engine, $client]           = qbEngine();
    $client->failOnCreate        = 1;
    $client->failWith            = new Fleetbase\Quickbooks\Support\QuickBooksException(500, 'unavailable');
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $now                         = time();

    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'        => 1,
        'retry_limit'             => 1,
        'default_backoff_seconds' => 30,
    ]), $now);

    expect($ledger->pending[0]['status'])->toBe('pending')
        ->and($ledger->pending[0]['attempts'])->toBe(1)
        ->and($ledger->pending[0]['next_attempt_at'])->toBe($now + 30);
});

test('a row past the retry limit is marked failed and skipped later', function () {
    [$engine, $client]              = qbEngine();
    $client->failOnCreate           = 1;
    $client->failWith               = new Fleetbase\Quickbooks\Support\QuickBooksException(0, 'timeout');
    $ledger                         = batchLedger();
    $ledger->customers['cust-1']    = customerRow('cust-1');
    $ledger->pending[]              = pendingRow('customer', 'cust-1');
    $ledger->pending[0]['attempts'] = 3;
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'retry_limit' => 3]), time());

    expect($ledger->pending[0]['status'])->toBe('failed')
        ->and($ledger->pending[0]['attempts'])->toBe(4);

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $calls                                                = count($client->calls);
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'retry_limit' => 3]), time() + 10);

    expect(count($client->calls))->toBe($calls);
});

test('a batch creates updates voids and skips a matching invoice', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->links[]             = [
        'company_uuid' => 'company-uuid', 'realm_id' => 'realm-1', 'local_type' => 'customer', 'local_uuid' => 'cust-1',
        'qbo_entity'   => 'Customer', 'qbo_id' => 'qbo-customer', 'sync_token' => '0',
    ];
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];

    $ledger->invoices['missing']  = invoiceFixture('missing', 1000, 'sent', 'INV-NEW');
    $ledger->invoices['drift']    = invoiceFixture('drift', 2500, 'sent', 'INV-DRIFT');
    $ledger->invoices['voided']   = invoiceFixture('voided', 1000, 'cancelled', 'INV-VOID');
    $ledger->invoices['same']     = invoiceFixture('same', 1000, 'sent', 'INV-SAME');
    $client->invoices['drift-id'] = ['Id' => 'drift-id', 'SyncToken' => '1', 'DocNumber' => 'INV-DRIFT', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $client->invoices['void-id']  = ['Id' => 'void-id', 'SyncToken' => '1', 'DocNumber' => 'INV-VOID', 'TotalAmt' => 10];
    $client->invoices['same-id']  = ['Id' => 'same-id', 'SyncToken' => '1', 'DocNumber' => 'INV-SAME', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]              = linkRow('invoice', 'drift', 'drift-id');
    $ledger->links[]              = linkRow('invoice', 'voided', 'void-id');
    $ledger->links[]              = linkRow('invoice', 'same', 'same-id');
    foreach (['missing', 'drift', 'voided', 'same'] as $uuid) {
        $ledger->pending[] = pendingRow('invoice', $uuid);
    }

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($batch['created'])->toBe(1)
        ->and($batch['updated'])->toBe(1)
        ->and($batch['voided'])->toBe(1)
        ->and($batch['aligned'])->toBe(1)
        ->and($client->calls)->toContain('voidInvoice');
});

test('an unchanged second pass counts records as aligned and sends no writes', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $batch                                                = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time() + 10);

    expect($batch['aligned'])->toBe(1)
        ->and(array_count_values($client->calls)['createCustomer'] ?? 0)->toBe(1)
        ->and($client->calls)->not->toContain('updateCustomer');
});

test('a paid invoice creates a linked payment', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'paid', 'INV-PAID');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1]), time());

    expect($client->calls)->toContain('createPayment')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-1')['qbo_id'])->toBe('pay-1');
});

test('manual reconcile includes entities that were never flagged', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->invoices['draft']   = invoiceFixture('draft', 1000, 'draft', 'INV-D');
    $ledger->invoices['sent']    = invoiceFixture('sent', 1000, 'sent', 'INV-S');

    $first = $engine->reconcile($ledger, 'company-uuid', qbSettings(), time());

    expect($first['created'])->toBeGreaterThan(0)
        ->and($client->calls)->toContain('createCustomer')
        ->and($client->invoices)->toHaveCount(1);
});

test('a quickbooks invoice with no local invoice and no link is out of scope', function () {
    [$engine, $client]         = qbEngine();
    $ledger                    = batchLedger();
    $client->remoteInvoiceList = [['Id' => 'orphan', 'DocNumber' => 'QB-1'], ['Id' => 'linked', 'DocNumber' => 'QB-2']];
    $ledger->links[]           = linkRow('invoice', 'known', 'linked');
    $batch                     = $engine->reconcile($ledger, 'company-uuid', qbSettings(), time());

    expect($client->calls)->not->toContain('listInvoices')
        ->and($batch['unmatched'])->toBe(0)
        ->and($ledger->invoices)->not->toHaveKey('orphan')
        ->and($client->calls)->not->toContain('voidInvoice');
});

test('no connection skips and a stored enabled flag does not stop a due sync', function () {
    [$engine, $client] = qbEngine();
    $empty             = new SyncLedger();
    expect($engine->runScheduled($empty, 'company-uuid', qbSettings(), time())['status'])->toBe('skipped');

    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $ran                         = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['enabled' => false]), time());

    expect($ran['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer');
});

test('with custom transaction numbers on, a quickbooks primary queries the next invoice number before create', function () {
    [$engine, $client]                 = qbEngine();
    $client->customTxnNumbers          = true;
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $client->invoices['old']           = ['Id' => 'old', 'SyncToken' => '0', 'DocNumber' => 'INV-009', 'TotalAmt' => 1];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-9');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']), time());

    expect($client->calls)->toContain('nextInvoiceDocNumber')
        ->and($ledger->invoices['inv-1']['number'])->toBe('INV-010')
        ->and($client->invoices['inv-1']['DocNumber'])->toBe('INV-010');
});

test('with custom transaction numbers off, a quickbooks primary sends no number and keeps the one quickbooks assigns', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $client->invoices['old']           = ['Id' => 'old', 'SyncToken' => '0', 'DocNumber' => 'INV-009', 'TotalAmt' => 1];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-9');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']), time());

    expect($client->calls)->not->toContain('nextInvoiceDocNumber')
        ->and($ledger->invoices['inv-1']['number'])->toBe('INV-010')
        ->and($client->invoices['inv-1']['DocNumber'])->toBe('INV-010');
});

test('a missing linked invoice with a quickbooks primary is not created again', function () {
    [$engine, $client]                 = qbEngine();
    $client->customTxnNumbers          = true;
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $ledger->links[]                   = linkRow('invoice', 'inv-1', 'gone');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $client->invoices['old']           = ['Id' => 'old', 'SyncToken' => '0', 'DocNumber' => 'INV-009', 'TotalAmt' => 1];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-9');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']), time());

    expect($batch['created'])->toBe(0)
        ->and($batch['aligned'])->toBe(1)
        ->and($client->calls)->not->toContain('nextInvoiceDocNumber')
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($ledger->invoices['inv-1']['number'])->toBe('LOCAL-9')
        ->and($ledger->link('company-uuid', 'realm-1', 'invoice', 'inv-1')['qbo_id'])->toBe('gone');
});

test('a fleetbase identifier source sends the fleetbase invoice number', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $client->invoices['old']           = ['Id' => 'old', 'SyncToken' => '0', 'DocNumber' => 'INV-009', 'TotalAmt' => 1];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-9');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'fleetbase']), time());

    expect($client->calls)->not->toContain('nextInvoiceDocNumber')
        ->and($ledger->invoices['inv-1']['number'])->toBe('LOCAL-9')
        ->and($client->invoices['inv-1']['DocNumber'])->toBe('LOCAL-9');
});

test('a block of quickbooks invoice creates resolves doc numbers once and skips numbers already taken', function () {
    $client = new class extends FakeQuickBooks {
        protected function latestInvoiceDocNumber(array $connection): string
        {
            $this->calls[] = 'latestInvoiceDocNumber';

            return 'INV-009';
        }
    };
    $client->customTxnNumbers          = true;
    $client->invoices['old']           = ['Id' => 'old', 'SyncToken' => '0', 'DocNumber' => 'INV-009', 'TotalAmt' => 1];
    $client->invoices['taken']         = ['Id' => 'taken', 'SyncToken' => '0', 'DocNumber' => 'INV-010', 'TotalAmt' => 1];
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-1');
    $ledger->invoices['inv-2']         = invoiceFixture('inv-2', 1000, 'sent', 'LOCAL-2');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-2');

    $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']), time());

    $counts = array_count_values($client->calls);
    expect($counts['nextInvoiceDocNumber'] ?? 0)->toBe(0)
        ->and($counts['findInvoiceByDocNumber'] ?? 0)->toBe(0)
        ->and($counts['latestInvoiceDocNumber'] ?? 0)->toBe(1)
        ->and($client->invoices['inv-1']['DocNumber'])->toBe('INV-011')
        ->and($client->invoices['inv-2']['DocNumber'])->toBe('INV-012')
        ->and($ledger->invoices['inv-1']['number'])->toBe('INV-011')
        ->and($ledger->invoices['inv-2']['number'])->toBe('INV-012');
});

test('a block create fails when quickbooks does not confirm a free invoice number', function () {
    $client = new class extends FakeQuickBooks {
        protected function latestInvoiceDocNumber(array $connection): string
        {
            $this->calls[] = 'latestInvoiceDocNumber';

            return 'INV-009';
        }
    };
    $client->customTxnNumbers = true;
    for ($i = 10; $i <= 16; $i++) {
        $doc                    = sprintf('INV-%03d', $i);
        $client->invoices[$doc] = ['Id' => $doc, 'SyncToken' => '0', 'DocNumber' => $doc, 'TotalAmt' => 1];
    }
    [$engine, $client]                 = qbEngine($client);
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-1');
    $ledger->invoices['inv-2']         = invoiceFixture('inv-2', 1000, 'sent', 'LOCAL-2');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-2');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']), time());
    $rows  = array_column($ledger->pending, null, 'local_uuid');

    expect($batch['created'])->toBe(0)
        ->and($batch['failed'])->toBe(2)
        ->and($client->calls)->not->toContain('createInvoice')
        ->and($rows['inv-1']['status'])->not->toBe('done')
        ->and($rows['inv-2']['status'])->not->toBe('done')
        ->and($ledger->attempts[0]['error'])->toBe('QuickBooks did not confirm a free invoice number, so Fleetbase did not create this invoice.')
        ->and($ledger->invoices['inv-1']['number'])->toBe('LOCAL-1');
});

test('quickbooks invoice numbers replace the fleetbase number', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'sent', 'LOCAL-1');
    $client->invoices['qb-1']          = ['Id' => 'qb-1', 'SyncToken' => '1', 'DocNumber' => 'QB-100', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]                   = linkRow('invoice', 'inv-1', 'qb-1');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['interval_minutes' => 1, 'invoice_reference' => 'quickbooks']), time());

    expect($ledger->invoices['inv-1']['number'])->toBe('QB-100')
        ->and($batch['updated'])->toBe(1)
        ->and($client->calls)->not->toContain('updateInvoice');
});

test('a manual sync runs even when the interval has not elapsed', function () {
    [$engine, $client]                                    = qbEngine();
    $ledger                                               = batchLedger();
    $now                                                  = time();
    $ledger->connections['company-uuid']['last_batch_at'] = $now;
    $ledger->customers['cust-1']                          = customerRow('cust-1');
    $ledger->pending[]                                    = pendingRow('customer', 'cust-1');

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(), $now, true);

    expect($batch['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer');
});

test('sync now and reconcile still run when automatic sync is off', function () {
    [$engine, $client]           = qbEngine();
    $ledger                      = batchLedger();
    $ledger->customers['cust-1'] = customerRow('cust-1');
    $ledger->pending[]           = pendingRow('customer', 'cust-1');
    $settings                    = qbSettings(['enabled' => false]);

    $now = $engine->runScheduled($ledger, 'company-uuid', $settings, time(), true);

    expect($now['status'])->toBe('finished')
        ->and($client->calls)->toContain('createCustomer');

    $ledger->connections['company-uuid']['last_batch_at']  = null;
    $second                                                = $engine->reconcile($ledger, 'company-uuid', $settings, time());

    expect($second['status'])->toBe('finished');
});

test('a forced sync with no connection does not record a skipped batch', function () {
    [$engine, $client] = qbEngine();
    $ledger            = new SyncLedger();

    $batch = $engine->runScheduled($ledger, 'company-uuid', qbSettings(['enabled' => false]), time(), true);

    expect($batch['status'])->toBe('skipped')
        ->and($ledger->batches)->toBe([])
        ->and($ledger->attempts)->toBe([])
        ->and($client->calls)->toBe([]);
});

test('a differing payment is compared and a quickbooks reference still creates a missing payment', function () {
    [$engine, $client]                    = qbEngine();
    $ledger                               = batchLedger();
    $ledger->customers['cust-1']          = customerRow('cust-1');
    $ledger->links[]                      = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer']    = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']            = invoiceFixture('inv-1', 1000, 'paid', 'INV-PAID');
    $ledger->invoices['inv-1']['paid_at'] = '2026-09-20';
    $client->invoices['inv-id']           = ['Id' => 'inv-id', 'SyncToken' => '1', 'DocNumber' => 'INV-PAID', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]                      = linkRow('invoice', 'inv-1', 'inv-id');
    $client->payments['pay-9']            = ['Id' => 'pay-9', 'SyncToken' => '1', 'TotalAmt' => 5, 'TxnDate' => '2026-09-01'];
    $ledger->links[]                      = linkRow('payment', 'inv-1', 'pay-9');
    $ledger->pending[]                    = pendingRow('invoice', 'inv-1');

    $settings = (new SyncSettingsResolver())->resolve(
        [],
        qbSettings([
            'override'         => true,
            'interval_minutes' => 1,
            'payment_conflict' => 'report',
        ]),
        qbSettings()
    );

    expect($settings['payment_conflict'])->toBe('report')
        ->and($settings['payment_direction'])->toBe('off')
        ->and($settings['payment_enabled'])->toBeTrue();

    $reported = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($reported['updated'])->toBe(0)
        ->and($reported['aligned'])->toBe(1)
        ->and($reported['skipped'])->toBe(0)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->payments['pay-9']['TxnDate'])->toBe('2026-09-01')
        ->and($client->payments['pay-9']['TotalAmt'])->toBe(5);

    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $ledger->attempts                                     = [];
    $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_conflict'  => 'fleetbase',
        'payment_reference' => 'fleetbase',
    ]), time());

    expect($client->calls)->toContain('updatePayment')
        ->and($client->payments['pay-9']['TxnDate'])->toBe('2026-09-20')
        ->and($client->payments['pay-9']['TotalAmt'])->toBe('10.00');

    $fresh                             = batchLedger();
    $fresh->customers['cust-1']        = customerRow('cust-1');
    $fresh->links[]                    = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $fresh->invoices['inv-2']          = invoiceFixture('inv-2', 1000, 'paid', 'INV-2');
    $fresh->pending[]                  = pendingRow('invoice', 'inv-2');
    $before                            = count(array_filter($client->calls, fn ($call) => $call === 'createPayment'));
    $engine->runScheduled($fresh, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_reference' => 'quickbooks',
    ]), time());

    expect(count(array_filter($client->calls, fn ($call) => $call === 'createPayment')))->toBe($before + 1);
    $stored = array_values(array_filter(
        $fresh->links,
        fn (array $link): bool => ($link['local_type'] ?? '') === 'payment'
    ));
    expect($stored)->toHaveCount(1)
        ->and($stored[0]['local_uuid'])->toBe($stored[0]['qbo_id'])
        ->and($stored[0]['qbo_id'])->not->toBe('');
});

test('the first fleetbase payment is created when quickbooks has no payment yet', function () {
    [$engine, $client]                 = qbEngine();
    $ledger                            = batchLedger();
    $ledger->customers['cust-1']       = customerRow('cust-1');
    $ledger->links[]                   = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer'] = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']         = invoiceFixture('inv-1', 1000, 'paid', 'INV-PAID');
    $client->invoices['inv-id']        = ['Id' => 'inv-id', 'SyncToken' => '1', 'DocNumber' => 'INV-PAID', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]                   = linkRow('invoice', 'inv-1', 'inv-id');
    $ledger->pending[]                 = pendingRow('invoice', 'inv-1');

    $reported = $engine->runScheduled($ledger, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_conflict'  => 'report',
        'payment_reference' => 'fleetbase',
    ]), time());

    expect($reported['skipped'])->toBe(0)
        ->and($reported['aligned'])->toBe(1)
        ->and($ledger->attempts[0]['error'])->toBeNull()
        ->and($client->calls)->toContain('createPayment')
        ->and($ledger->link('company-uuid', 'realm-1', 'payment', 'pay-1')['qbo_id'])->toBe('pay-1');

    $fresh                      = batchLedger();
    $fresh->customers['cust-1'] = customerRow('cust-1');
    $fresh->links[]             = linkRow('customer', 'cust-1', 'qbo-customer');
    $fresh->invoices['inv-2']   = invoiceFixture('inv-2', 1000, 'paid', 'INV-2');
    $client->invoices['inv-2']  = ['Id' => 'inv-2', 'SyncToken' => '1', 'DocNumber' => 'INV-2', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $fresh->links[]             = linkRow('invoice', 'inv-2', 'inv-2');
    $fresh->pending[]           = pendingRow('invoice', 'inv-2');
    $before                     = count(array_filter($client->calls, fn ($call) => $call === 'createPayment'));

    $adopted = $engine->runScheduled($fresh, 'company-uuid', qbSettings([
        'interval_minutes'  => 1,
        'payment_conflict'  => 'quickbooks',
        'payment_reference' => 'fleetbase',
    ]), time());

    expect($adopted['skipped'])->toBe(0)
        ->and($fresh->attempts[0]['error'])->toBeNull()
        ->and(count(array_filter($client->calls, fn ($call) => $call === 'createPayment')))->toBe($before + 1);
    $stored = array_values(array_filter(
        $fresh->links,
        fn (array $link): bool => ($link['local_type'] ?? '') === 'payment'
    ));
    expect($stored)->toHaveCount(1)
        ->and($stored[0]['local_uuid'])->toBe($stored[0]['qbo_id'])
        ->and($stored[0]['qbo_id'])->not->toBe('');
});

test('a quickbooks payment copied onto the invoice matches on the next sync', function () {
    [$engine, $client]                    = qbEngine();
    $ledger                               = batchLedger();
    $ledger->customers['cust-1']          = customerRow('cust-1');
    $ledger->links[]                      = linkRow('customer', 'cust-1', 'qbo-customer');
    $client->customers['qbo-customer']    = ['Id' => 'qbo-customer', 'SyncToken' => '0', 'DisplayName' => 'Ada', 'PrimaryEmailAddr' => ['Address' => 'ada@example.test']];
    $ledger->invoices['inv-1']            = invoiceFixture('inv-1', 1000, 'paid', 'INV-PAID');
    $ledger->invoices['inv-1']['paid_at'] = '2026-09-20';
    $client->invoices['inv-id']           = ['Id' => 'inv-id', 'SyncToken' => '1', 'DocNumber' => 'INV-PAID', 'TotalAmt' => 10, 'TxnDate' => '2026-09-01', 'DueDate' => '2026-09-15'];
    $ledger->links[]                      = linkRow('invoice', 'inv-1', 'inv-id');
    $client->payments['pay-9']            = ['Id' => 'pay-9', 'SyncToken' => '1', 'TotalAmt' => 5, 'TxnDate' => '2026-09-01'];
    $ledger->links[]                      = linkRow('payment', 'inv-1', 'pay-9');
    $ledger->pending[]                    = pendingRow('invoice', 'inv-1');
    $settings                             = qbSettings([
        'interval_minutes'  => 1,
        'payment_conflict'  => 'quickbooks',
        'payment_reference' => 'fleetbase',
    ]);

    $first = $engine->runScheduled($ledger, 'company-uuid', $settings, time());

    expect($first['updated'])->toBe(1)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(500)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('2026-09-01')
        ->and($ledger->invoices['inv-1']['total'])->toBe(1000)
        ->and($client->calls)->not->toContain('updatePayment');

    unset($ledger->invoices['inv-1']['payment_from_quickbooks']);
    $ledger->connections['company-uuid']['last_batch_at'] = null;
    $ledger->pending[0]['status']                         = 'pending';
    $second                                               = $engine->runScheduled($ledger, 'company-uuid', $settings, time() + 10);

    expect($second['aligned'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($ledger->invoices['inv-1']['amount_paid'])->toBe(500)
        ->and($ledger->invoices['inv-1']['status'])->toBe('partial')
        ->and($ledger->invoices['inv-1']['paid_at'])->toBe('2026-09-01')
        ->and($client->calls)->not->toContain('updatePayment')
        ->and($client->payments['pay-9']['TotalAmt'])->toBe(5);
});

function batchLedger(): SyncLedger
{
    $ledger                              = new SyncLedger();
    $ledger->connections['company-uuid'] = [
        'company_uuid'    => 'company-uuid',
        'realm_id'        => 'realm-1',
        'needs_reauth'    => false,
        'home_currency'   => 'USD',
        'default_item_id' => 'item-1',
    ];

    return $ledger;
}

function customerRow(string $uuid): array
{
    return ['uuid' => $uuid, 'company_uuid' => 'company-uuid', 'name' => 'Ada', 'email' => 'ada@example.test'];
}

function pendingRow(string $type, string $uuid): array
{
    return ['company_uuid' => 'company-uuid', 'local_type' => $type, 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
}

function invoiceFixture(string $uuid, int $total, string $status, string $number): array
{
    return [
        'uuid'          => $uuid,
        'company_uuid'  => 'company-uuid',
        'customer_uuid' => 'cust-1',
        'number'        => $number,
        'date'          => '2026-09-01',
        'due_date'      => '2026-09-15',
        'currency'      => 'USD',
        'tax'           => 0,
        'total'         => $total,
        'status'        => $status,
        'items'         => [['description' => 'Delivery', 'quantity' => 1, 'unit_price' => $total, 'amount' => $total]],
    ];
}

function linkRow(string $type, string $uuid, string $qboId): array
{
    return [
        'company_uuid' => 'company-uuid',
        'realm_id'     => 'realm-1',
        'local_type'   => $type,
        'local_uuid'   => $uuid,
        'qbo_entity'   => ucfirst($type),
        'qbo_id'       => $qboId,
        'sync_token'   => '0',
    ];
}
