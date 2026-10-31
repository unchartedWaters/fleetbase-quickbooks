<?php

declare(strict_types=1);

/*
 * Run inside the Fleetbase API container after verify-install.php. Saves a Fleet-Ops customer
 * and a Ledger invoice through the release's own models, so the extension's observers and
 * queries meet the real schema, then runs Sync now against the test suite's FakeQuickBooks.
 * Nothing reaches Intuit and no credentials are used. Exits 1 if any check failed.
 */

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Models\Company;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\ReleaseCheck\Report;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
use Fleetbase\Quickbooks\Tests\Support\FakeQuickBooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$app     = require __DIR__ . '/boot.php';
$package = dirname(__DIR__, 2);
require_once __DIR__ . '/Report.php';
// Test support is autoload-dev, which an installed package does not get.
require_once $package . '/server/tests/Support/FakeQuickBooks.php';

$report = new Report();
$fake   = new FakeQuickBooks();
// The id QuickBooks gives the item that invoice lines without a product use.
$fake->serviceItemId = '1';
$app->instance(QuickBooksClient::class, $fake);

$tag     = Str::lower(Str::random(8));
$company = Company::create([
    'name'     => 'Release Check ' . $tag,
    'currency' => 'USD',
    'country'  => 'US',
    'timezone' => 'UTC',
]);
$connection = new Connection();
$connection->forceFill([
    'company_uuid'     => $company->uuid,
    'realm_id'         => '9130000000000001',
    'environment'      => 'sandbox',
    'access_token'     => 'release-check-access',
    'refresh_token'    => 'release-check-refresh',
    'token_expires_at' => now()->addHour(),
    'needs_reauth'     => false,
])->save();

$pending = static fn (): array => DB::table('quickbooks_pending_syncs')
    ->where('company_uuid', $company->uuid)
    ->pluck('local_type', 'local_uuid')
    ->all();

$customer = Contact::create([
    'company_uuid' => $company->uuid,
    'name'         => 'Release Check Customer ' . $tag,
    'email'        => 'release-check-' . $tag . '@example.com',
    'type'         => 'customer',
]);
$queued = static fn (string $uuid, string $type): array => ($pending()[$uuid] ?? null) === $type
    ? []
    : ['no pending row'];
$report->check('saving a Fleet-Ops customer queues it', $queued($customer->uuid, 'customer'));

$invoice = Invoice::create([
    'company_uuid'  => $company->uuid,
    'customer_uuid' => $customer->uuid,
    'customer_type' => Contact::class,
    'number'        => 'RC-' . Str::upper($tag),
    'date'          => now()->toDateString(),
    'due_date'      => now()->addDays(30)->toDateString(),
    'currency'      => 'USD',
    'status'        => 'sent',
    'subtotal'      => 2500,
    'tax'           => 0,
    'total_amount'  => 2500,
    'amount_paid'   => 0,
    'balance'       => 2500,
]);
InvoiceItem::create([
    'invoice_uuid' => $invoice->uuid,
    'description'  => 'Release check delivery',
    'quantity'     => 1,
    'unit_price'   => 2500,
    'amount'       => 2500,
    'tax_rate'     => 0,
    'tax_amount'   => 0,
]);
$report->check('saving a Ledger invoice queues it', $queued($invoice->uuid, 'invoice'));

$result   = $app->make(BatchRunner::class)->run($company->uuid, 'now');
$finished = ($result['status'] ?? '') === 'finished' && ($result['failed'] ?? 1) === 0;
$report->check('Sync now finishes without failures', $finished ? [] : [(string) json_encode($result)]);

$links = DB::table('quickbooks_links')->where('company_uuid', $company->uuid)->pluck('qbo_id', 'local_uuid')->all();
$report->check('customer and invoice are linked to QuickBooks records', array_values(array_filter([
    ($links[$customer->uuid] ?? '') === '' ? 'customer' : null,
    ($links[$invoice->uuid] ?? '') === '' ? 'invoice' : null,
])));

$failed = DB::table('quickbooks_sync_attempts')->where('company_uuid', $company->uuid)->where('outcome', 'failed')
    ->get(['local_type', 'error'])->map(static fn ($row): string => $row->local_type . ' ' . $row->error)->all();
$report->check('Activity has no failed rows', $failed);

$report->check('QuickBooks was asked to create both', array_values(array_diff(
    ['createCustomer', 'createInvoice'],
    $fake->calls
)));

// The connection holds made-up tokens. Remove it so the scheduler and queue steps that follow
// never send them to Intuit.
DB::table('quickbooks_connections')->where('company_uuid', $company->uuid)->delete();

exit($report->exitCode());
