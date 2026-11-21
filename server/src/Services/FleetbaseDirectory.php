<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\Concerns\AppliesRemoteDeletes;
use Fleetbase\Quickbooks\Services\Concerns\QueuesPending;
use Fleetbase\Quickbooks\Services\Concerns\StoresConnections;
use Fleetbase\Quickbooks\Services\Concerns\StoresLinks;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\LedgerPaymentDates;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Loads and saves the SyncLedger through Eloquent. The engine compares times as
 * unix seconds, so datetime columns become ints on load and Carbon on save.
 */
class FleetbaseDirectory
{
    use AppliesRemoteDeletes;
    use QueuesPending;
    use StoresConnections;
    use StoresLinks;

    public const CONNECTION_TIMES = ['token_expires_at', 'rate_limited_until', 'last_batch_at'];

    public const PENDING_TIMES = ['next_attempt_at'];

    /** The only connection fields a batch writes; the rest belong to connect and disconnect. */
    public const CONNECTION_ENGINE_FIELDS = [
        'rate_limited_until',
        'last_rate_limit_wait',
        'last_batch_at',
        'home_currency',
        'default_item_id',
        'default_item_account',
        'needs_reauth',
    ];

    public const CONNECTION_TOKEN_FIELDS = ['access_token', 'refresh_token', 'token_expires_at', 'needs_reauth'];

    public const PENDING_FIELDS = ['reason', 'status', 'attempts', 'next_attempt_at'];

    public const CUSTOMER_FIELDS = ['name', 'email', 'phone', 'notes'];

    public ?SyncLedger $memory = null;

    /** Marks a lease whose row was flagged again while the holding run was sending it. */
    private const REFLAG_PREFIX = 'reflag:';

    /** The Storefront product model. Products are read only, and only when Storefront is installed. */
    public const PRODUCT_CLASS = 'Fleetbase\\Storefront\\Models\\Product';

    /** The Storefront product columns the product sync reads. */
    private const PRODUCT_COLUMNS = [
        'uuid',
        'company_uuid',
        'public_id',
        'name',
        'description',
        'sku',
        'price',
        'currency',
        'is_service',
        'status',
        'deleted_at',
    ];

    /** Invoice item meta key holding the QuickBooks line the item was synced to. */
    private const LINE_META = 'quickbooks_line';

    /** How long a company without a connection is remembered by flag(). */
    public const UNTRACKED_SECONDS = 30;

    /** @var \WeakMap<object, bool>|null */
    private static ?\WeakMap $claimSupport = null;

    /** @var \WeakMap<object, bool>|null */
    private static ?\WeakMap $prioritySupport = null;

    /**
     * Companies flag() found without a connection, mapped to the time the answer expires.
     *
     * @var array<string, int>
     */
    private array $untracked = [];

    /**
     * Lease tokens this directory took in loadPending(), given back by releaseClaims().
     *
     * @var array<int, string>
     */
    private array $claimTokens = [];

    /**
     * Set by saveImport(): links and records commit in one transaction.
     */
    private bool $linksWithRecords = false;

    /**
     * Customers this save links to a QuickBooks customer for the first time (or to another one),
     * by company. Their invoices that failed while they had no QuickBooks customer are queued again.
     *
     * @var array<string, array<int, string>>
     */
    private array $linkedCustomers = [];

    /**
     * The QuickBooks customers this save links a Fleetbase customer to, by company and realm.
     * Invoices kept on one of them (kept_customer) are queued, so they follow it.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $linkedRemoteCustomers = [];

    /**
     * Rows as loadLedger() read them, so save() writes only what the engine changed.
     *
     * @var array{connections: array<string, array<string, mixed>>, pending: array<string, array<string, mixed>>, customers: array<string, array<string, mixed>>, invoices: array<string, array<string, mixed>>, wallets: array<string, array<string, mixed>>, links: array<string, array<string, mixed>>}
     */
    private array $loaded = ['connections' => [], 'pending' => [], 'customers' => [], 'invoices' => [], 'wallets' => [], 'links' => []];

    /**
     * Item meta as read, per invoice, so a stored QuickBooks line id is merged into it.
     *
     * @var array<string, array{
     *     live: array<string, array<string, mixed>|null>,
     *     trashed: array<string, array<string, mixed>|null>
     * }>
     */
    private array $lineMeta = [];

    /**
     * Fleetbase invoices that lost a payment to a QuickBooks delete in this request, by company.
     *
     * @var array<string, array<string, true>>
     */
    private array $paymentRemoved = [];

    /**
     * @return array{ledger: SyncLedger, connection: array<string, mixed>, customers: array<int, array<string, mixed>>}|null
     */
    public function load(string $companyUuid): ?array
    {
        $ledger     = $this->memory ?? $this->loadLedger($companyUuid);
        $connection = $this->connectionForLedger($ledger, $companyUuid);
        if ($connection === null) {
            return null;
        }

        return [
            'ledger'     => $ledger,
            'connection' => $connection,
            'customers'  => array_values(array_filter(
                $ledger->customers,
                static fn (array $customer): bool => ($customer['company_uuid'] ?? null) === $companyUuid
            )),
        ];
    }

    /**
     * Scheduled and Sync now load only the due pending rows, up to batch_size,
     * plus the customers, invoices, wallets, and links those rows need.
     *
     * @return array{ledger: SyncLedger, connection: array<string, mixed>, customers: array<int, array<string, mixed>>}|null
     */
    public function loadPending(string $companyUuid, int $limit, int $now): ?array
    {
        $ledger     = $this->memory ?? $this->loadLedger($companyUuid, max(1, $limit), $now);
        $connection = $this->connectionForLedger($ledger, $companyUuid);
        if ($connection === null) {
            return null;
        }

        return [
            'ledger'     => $ledger,
            'connection' => $connection,
            'customers'  => array_values(array_filter(
                $ledger->customers,
                static fn (array $customer): bool => ($customer['company_uuid'] ?? null) === $companyUuid
            )),
        ];
    }

    /**
     * Webhook ids, not the company catalog. The same shape as load(), but only:
     * the connection; links whose QuickBooks entity and id are in $entities for
     * this company and realm; the customer, invoice, or wallet each of those
     * links points at (a payment link points at its invoice); the customer on
     * each of those invoices; and the links for those local rows, so a payment
     * id also brings the invoice link on that same invoice. Soft-deleted
     * invoices in that id set are included. Pending rows and every other
     * customer, invoice, wallet, and link stay out.
     *
     * @param array<int, array{entity?: mixed, id?: mixed, operation?: mixed}> $entities
     *
     * @return array{ledger: SyncLedger, connection: array<string, mixed>, customers: array<int, array<string, mixed>>}|null
     */
    public function loadLinked(string $companyUuid, array $entities): ?array
    {
        $ledger     = $this->linkedLedger($companyUuid, $entities);
        $connection = $this->connectionForLedger($ledger, $companyUuid);
        if ($connection === null) {
            return null;
        }

        return [
            'ledger'     => $ledger,
            'connection' => $connection,
            'customers'  => array_values(array_filter(
                $ledger->customers,
                static fn (array $customer): bool => ($customer['company_uuid'] ?? null) === $companyUuid
            )),
        ];
    }

    /**
     * In-scope invoices for reconcile: a local non-draft invoice for this company,
     * or a quickbooks_links row for this company whose local type is invoice.
     * At most $limit ids are returned. Pending rows are written only for that page,
     * after the cap, not for every invoice in the company.
     */
    public function claimInScopeInvoices(string $companyUuid, int $limit, string $afterUuid = ''): string
    {
        if ($this->memory !== null) {
            return '';
        }

        $ids  = $this->inScopeInvoiceIds($companyUuid, max(1, $limit), $afterUuid);
        $rows = [];
        foreach ($ids as $uuid) {
            $rows[] = $this->catalogPending($companyUuid, 'invoice', $uuid);
        }
        $this->writePendingMany($rows);

        if ($ids === []) {
            return '';
        }

        return (string) $ids[array_key_last($ids)];
    }

    /**
     * One page of customer ids for the periodic catalog. The fast pending path does not call this.
     *
     * @return array<int, string>
     */
    public function customerIdsPage(string $companyUuid, int $limit, string $afterUuid = ''): array
    {
        if ($this->memory !== null) {
            $ids = [];
            foreach ($this->memory->customers as $uuid => $customer) {
                if (($customer['company_uuid'] ?? '') !== $companyUuid) {
                    continue;
                }
                if ($afterUuid !== '' && (string) $uuid <= $afterUuid) {
                    continue;
                }
                $ids[] = (string) $uuid;
            }
            sort($ids);

            return array_slice($ids, 0, max(1, $limit));
        }

        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid)->where('type', 'customer')->orderBy('uuid');
        if ($afterUuid !== '') {
            $query->where('uuid', '>', $afterUuid);
        }

        $ids = [];
        foreach ($query->limit(max(1, $limit))->pluck('uuid') as $uuid) {
            $ids[] = (string) $uuid;
        }

        return $ids;
    }

    /**
     * Load one customer block with whereIn, not a query per customer.
     *
     * @param array<int, string> $uuids
     *
     * @return array{ledger: SyncLedger, connection: array<string, mixed>}|null
     */
    public function loadCustomerBlock(string $companyUuid, array $uuids): ?array
    {
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => $uuid !== ''));
        if ($uuids === []) {
            return null;
        }
        if ($this->memory !== null) {
            return $this->load($companyUuid);
        }

        $connection = $this->connection($companyUuid);
        if ($connection === null) {
            return null;
        }

        $ledger                            = new SyncLedger();
        $ledger->connections[$companyUuid] = $connection;
        $ledger->customers                 = $this->readCustomers($companyUuid, $uuids);
        $this->readLinksFor($ledger, $companyUuid, $uuids, [], []);
        $ledger->rebuildIndex();
        $this->loaded['customers'] = $ledger->customers;
        $this->loaded['links']     = [];
        foreach ($ledger->links as $link) {
            $key                         = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
            $this->loaded['links'][$key] = $link;
        }

        return ['ledger' => $ledger, 'connection' => $connection];
    }

    public function customerCatalogStamp(string $companyUuid): ?int
    {
        if ($this->memory !== null) {
            $value = $this->memory->connections[$companyUuid]['last_customer_catalog_at'] ?? null;

            return $value === null || $value === '' ? null : (int) $value;
        }

        $cached = Cache::get('quickbooks.customer-catalog-at.' . $companyUuid);

        return is_numeric($cached) === true ? (int) $cached : null;
    }

    public function rememberCustomerCatalogStamp(string $companyUuid, int $now): void
    {
        if ($this->memory !== null) {
            $this->memory->connections[$companyUuid]['last_customer_catalog_at'] = $now;

            return;
        }

        Cache::put('quickbooks.customer-catalog-at.' . $companyUuid, $now, 86400 * 60);
    }

    /**
     * Queue in-scope customers, non-draft invoices, invoice links, and wallets.
     * Disabled entities are left alone. Already-pending rows are not inserted again.
     *
     * @param array{customer?: bool, invoice?: bool, wallet?: bool} $enabled
     */
    public function queueInScope(string $companyUuid, array $enabled): void
    {
        if ($this->memory !== null || $companyUuid === '') {
            return;
        }

        if (empty($enabled['customer']) === false) {
            $this->queueCatalog('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, true, 'customer');
        }
        if (empty($enabled['invoice']) === false) {
            $this->queueCatalog('Fleetbase\\Ledger\\Models\\Invoice', $companyUuid, false, 'invoice');
            $this->queueInvoiceLinks($companyUuid);
            $product = self::productClass();
            if ($product !== null) {
                $this->queueCatalog($product, $companyUuid, false, 'product');
            }
            $this->queueLinksOfType($companyUuid, 'item');
        }
        if (empty($enabled['wallet']) === false) {
            $this->queueCatalog('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, false, 'wallet');
        }
    }

    protected function catalogPageSize(): int
    {
        return 200;
    }

    public function save(SyncLedger $ledger): void
    {
        if ($this->memory !== null) {
            $this->memory = $ledger;

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $this->write($ledger);
        } finally {
            if ($wasPaused === false) {
                SyncSuppressor::resume();
            }
        }
    }

    /**
     * Save a customer import. Its links point at Fleetbase customers created by this same
     * save, not at records this run created in QuickBooks, so they commit together with
     * those customers: a failed customer write leaves no link to a customer that does not
     * exist (which would show that QuickBooks customer as linked for good).
     */
    public function saveImport(SyncLedger $ledger): void
    {
        $this->linksWithRecords = true;
        try {
            $this->save($ledger);
        } finally {
            $this->linksWithRecords = false;
        }
    }

    private function write(SyncLedger $ledger): void
    {
        $this->stampOwnedCompanies($ledger);

        foreach ($ledger->connections as $connection) {
            $this->writeConnection($connection);
        }

        // A disconnect or reconnect during the batch must not mark the old
        // pending rows done or point links at the realm loaded at batch start.
        // writeConnection already no-ops in those cases; these rows do not.
        $skipped = $this->companiesSkippingLedgerWrites($ledger);

        // Links are committed first, on their own. They record QuickBooks records that already
        // exist, so losing them would make the next run create those records a second time.
        // Pending rows, attempts and the Fleetbase rows then land together or not at all: a
        // failure leaves the rows pending, and the retry updates through the kept links.
        // Connection rows above stay outside: tokens and rate-limit state are saved either way.
        // A customer import has created nothing in QuickBooks, so see saveImport().
        $database = (new Link())->getConnection();
        if ($this->linksWithRecords === true) {
            $database->transaction(function () use ($ledger, $skipped): void {
                $this->writeLinks($ledger, $skipped);
                $this->writeRecords($ledger, $skipped);
            });

            return;
        }
        $database->transaction(function () use ($ledger, $skipped): void {
            $this->writeLinks($ledger, $skipped);
        });
        $database->transaction(function () use ($ledger, $skipped): void {
            $this->writeRecords($ledger, $skipped);
        });
    }

    /**
     * @param array<string, true> $skipped
     */
    private function writeRecords(SyncLedger $ledger, array $skipped): void
    {
        $this->keepUnclaimedInvoiceNumbers($ledger, $skipped);

        $freshPending   = [];
        $changedPending = [];
        foreach ($ledger->pending as $row) {
            if (isset($skipped[(string) ($row['company_uuid'] ?? '')]) === true) {
                continue;
            }
            if (empty($row['uuid']) === true) {
                $freshPending[] = $row;
                continue;
            }
            $columns = $this->pendingUpdateColumns($row, $this->loaded['pending'][(string) $row['uuid']] ?? null);
            if ($columns === []) {
                continue;
            }
            $changedPending[] = ['uuid' => (string) $row['uuid'], 'columns' => $columns];
        }
        $this->updatePendingRows($changedPending);
        $this->writePendingMany($freshPending);
        $this->requeueInvoicesOfLinkedCustomers();
        $this->insertBatchAttempts($ledger);
        $this->persistCustomers($ledger, $skipped);
        $this->persistInvoices($ledger, $skipped);
        $this->persistWallets($ledger, $skipped);
    }

    /**
     * Saves this run's batch record and its attempts, pointing new attempts at the batch.
     */
    private function insertBatchAttempts(SyncLedger $ledger): void
    {
        $batchUuid = null;
        foreach ($ledger->batches as $batch) {
            if (empty($batch['uuid']) === false) {
                $batchUuid = (string) $batch['uuid'];
                continue;
            }
            $record = new SyncBatch();
            $record->fill($this->batchAttributes($batch));
            $record->save();
            $batchUuid = (string) $record->uuid;
        }
        if ($batchUuid !== null) {
            foreach ($ledger->attempts as $index => $attempt) {
                if (empty($attempt['uuid']) === false || empty($attempt['batch_uuid']) === false) {
                    continue;
                }
                $ledger->attempts[$index]['batch_uuid'] = $batchUuid;
            }
        }
        $this->insertAttempts($ledger->attempts);
    }

    /**
     * A customer link this save adds or points at another QuickBooks customer: its invoices are
     * queued again (requeueInvoicesOfLinkedCustomers).
     *
     * @param array<string, mixed> $link
     */
    private function noteLinkedCustomer(array $link): void
    {
        $company = (string) ($link['company_uuid'] ?? '');
        $realm   = (string) ($link['realm_id'] ?? '');

        $this->linkedCustomers[$company][]               = (string) ($link['local_uuid'] ?? '');
        $this->linkedRemoteCustomers[$company][$realm][] = (string) ($link['qbo_id'] ?? '');
    }

    /**
     * An invoice fails for good when its customer cannot be created in QuickBooks. Once that
     * customer is linked, its failed invoices are queued again, with their retries reset.
     */
    private function requeueInvoicesOfLinkedCustomers(): void
    {
        $this->requeueInvoicesKeptOnLinkedCustomers();
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if ($this->linkedCustomers === [] || class_exists($class) === false) {
            return;
        }
        foreach ($this->linkedCustomers as $companyUuid => $customerUuids) {
            $customers = array_flip(array_filter($customerUuids));
            if ($customers === []) {
                continue;
            }
            // Failed invoice rows are few, so they are read first and only their invoices are checked.
            $failed = PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', 'invoice')
                ->where('status', 'failed')
                ->pluck('local_uuid')
                ->map(static fn (mixed $uuid): string => (string) $uuid)
                ->all();
            foreach (array_chunk(array_values(array_unique($failed)), 500) as $chunk) {
                $owners = $class::query()
                    ->toBase()
                    ->where('company_uuid', $companyUuid)
                    ->whereIn('uuid', $chunk)
                    ->pluck('customer_uuid', 'uuid')
                    ->all();
                $requeue = array_keys(array_filter(
                    $owners,
                    static fn (mixed $customerUuid): bool => isset($customers[(string) $customerUuid]) === true
                ));
                if ($requeue === []) {
                    continue;
                }
                $this->reopenInvoices((string) $companyUuid, array_map('strval', $requeue));
            }
        }
        $this->linkedCustomers = [];
    }

    /**
     * An invoice QuickBooks moved to a QuickBooks customer no Fleetbase customer was linked to
     * keeps that customer on its link. Once this save links it, the invoice is queued, so the
     * next run moves it to the Fleetbase customer (SyncEngine::followKeptCustomer).
     */
    private function requeueInvoicesKeptOnLinkedCustomers(): void
    {
        foreach ($this->linkedRemoteCustomers as $companyUuid => $realms) {
            foreach ($realms as $realm => $remoteIds) {
                $remoteIds = array_values(array_unique(array_filter($remoteIds)));
                foreach (array_chunk($remoteIds, 500) as $chunk) {
                    $invoices = Link::query()
                        ->where('company_uuid', $companyUuid)
                        ->where('realm_id', $realm)
                        ->where('local_type', 'invoice')
                        ->whereIn('kept_customer', $chunk)
                        ->pluck('local_uuid')
                        ->map(static fn (mixed $uuid): string => (string) $uuid)
                        ->all();
                    if ($invoices !== []) {
                        $this->reopenInvoices((string) $companyUuid, array_values(array_unique($invoices)));
                    }
                }
            }
        }
        $this->linkedRemoteCustomers = [];
    }

    /**
     * One open row per invoice: an invoice that already has one keeps it (the insert is
     * ignored, as the open-identity index allows only one), and is sent on the next run.
     * The failed rows are then retired, so a later customer link does not read them again.
     *
     * @param array<int, string> $invoiceUuids
     */
    private function reopenInvoices(string $companyUuid, array $invoiceUuids): void
    {
        $this->writePendingMany(array_map(static fn (string $uuid): array => [
            'company_uuid' => $companyUuid,
            'local_type'   => 'invoice',
            'local_uuid'   => $uuid,
            'reason'       => 'customer-linked',
            'attempts'     => 0,
        ], $invoiceUuids));
        $invoices = PendingSync::query()
            ->where('company_uuid', $companyUuid)
            ->where('local_type', 'invoice')
            ->whereIn('local_uuid', $invoiceUuids);
        (clone $invoices)->where('status', 'failed')->delete();
        $waiting = (clone $invoices)->where('status', 'pending')->whereNotNull('next_attempt_at');
        if ($this->claimsSupported() === true) {
            $waiting->whereNull('claimed_by');
        }
        $waiting->update(['attempts' => 0, 'next_attempt_at' => null, 'updated_at' => now()]);
    }

    /**
     * ledger_invoices.number is unique across every company. A QuickBooks DocNumber that another
     * Fleetbase invoice already holds is not copied: the local number stays, and this batch's
     * attempt for the invoice says why. The rest of the batch still saves.
     *
     * @param array<string, true> $skipped
     */
    private function keepUnclaimedInvoiceNumbers(SyncLedger $ledger, array $skipped): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return;
        }

        $model   = new $class();
        $claimed = [];
        foreach (array_keys($ledger->invoices) as $key) {
            $invoice = $ledger->invoices[$key];
            $uuid    = (string) ($invoice['uuid'] ?? '');
            if ($uuid === '' || isset($skipped[(string) ($invoice['company_uuid'] ?? '')]) === true) {
                continue;
            }
            $loaded = $this->loaded['invoices'][$uuid] ?? null;
            $kept   = is_array($loaded) === true ? trim((string) ($loaded['number'] ?? '')) : '';
            $number = trim((string) ($invoice['number'] ?? ''));
            if ($number === '' || $number === $kept) {
                continue;
            }
            if (isset($claimed[$number]) === false && $model->getConnection()->table($model->getTable())->where('number', $number)->where('uuid', '!=', $uuid)->exists() === false) {
                $claimed[$number] = $uuid;
                continue;
            }

            $ledger->invoices[$key]['number'] = $kept;
            $ledger->rememberInvoice($uuid, $ledger->invoices[$key]);
            $this->noteInvoiceNumberCollision($ledger, (string) ($invoice['company_uuid'] ?? ''), $uuid, $number, $kept);
        }
    }

    private function noteInvoiceNumberCollision(SyncLedger $ledger, string $companyUuid, string $uuid, string $number, string $kept): void
    {
        $note = Translation::encode('quickbooks.messages.invoice-number-taken', ['number' => $number]);
        if ($kept !== '') {
            $keeps = Translation::encode('quickbooks.messages.invoice-number-kept', ['number' => $kept]);
            $note  = Translation::append($note, $keeps);
        }
        $noted = false;
        foreach ($ledger->attempts as $index => $attempt) {
            if (empty($attempt['uuid']) === false
                || (string) ($attempt['local_type'] ?? '') !== 'invoice' || (string) ($attempt['local_uuid'] ?? '') !== $uuid) {
                continue;
            }
            $existing                          = trim((string) ($attempt['error'] ?? ''));
            $ledger->attempts[$index]['error'] = $existing === '' ? $note : Translation::append($existing, $note);
            $noted                             = true;
        }
        if ($noted === false) {
            Log::warning('QuickBooks invoice number was not copied.', [
                'company_uuid' => $companyUuid,
                'invoice_uuid' => $uuid,
                'note'         => Translation::render($note, Translation::FALLBACK_LOCALE),
            ]);
        }
    }

    /**
     * Companies whose links, pending rows, customers, invoices, and wallets
     * must stay as stored. An in-memory ledger is the test double and always writes.
     *
     * @return array<string, true>
     */
    private function companiesSkippingLedgerWrites(SyncLedger $ledger): array
    {
        if ($this->memory !== null) {
            return [];
        }

        $companyUuids = [];
        foreach ([$ledger->links, $ledger->pending, $ledger->customers, $ledger->invoices, $ledger->wallets] as $rows) {
            foreach ($rows as $row) {
                $companyUuid = (string) ($row['company_uuid'] ?? '');
                if ($companyUuid !== '') {
                    $companyUuids[$companyUuid] = true;
                }
            }
        }

        $skipped = [];
        foreach (array_keys($companyUuids) as $companyUuid) {
            $ledgerConnection = $ledger->connections[$companyUuid] ?? null;
            if (self::connectionStillCurrent(
                $this->connection($companyUuid),
                is_array($ledgerConnection) === true ? $ledgerConnection : null
            ) === false) {
                $skipped[$companyUuid] = true;
            }
        }

        return $skipped;
    }

    /**
     * Links and pending rows belong to the organization that owns the record.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function withEntityCompany(array $connection, string $companyUuid): array
    {
        $connection['company_uuid'] = $companyUuid;

        return $connection;
    }

    private function loadLedger(string $companyUuid, ?int $pendingLimit = null, ?int $now = null): SyncLedger
    {
        $ledger     = new SyncLedger();
        $connection = $this->connection($companyUuid);
        if ($connection !== null) {
            $ledger->connections[$companyUuid] = $connection;
        }
        [$customerIds, $invoiceIds, $walletIds, $flaggedInvoices] = $this->collectPendingIds($ledger, $companyUuid, $pendingLimit, $now);
        if ($pendingLimit === null) {
            $this->loadFullLedger($ledger, $companyUuid, $flaggedInvoices, $customerIds, $walletIds);
        } else {
            $this->loadLimitedLedger($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds, $flaggedInvoices);
            if ($this->needsProducts($ledger) === true) {
                $this->readItemLinks($ledger, $companyUuid);
            }
        }
        $this->readProducts($ledger, $companyUuid);
        $ledger->rebuildIndex();
        $this->loaded['customers'] = $ledger->customers;
        $this->loaded['invoices']  = $ledger->invoices;
        $this->loaded['wallets']   = $ledger->wallets;
        // Only rows this run needs are loaded. Whether a QuickBooks record already belongs to
        // another Fleetbase record, of any organization on that company, is read when it is asked.
        $ledger->remoteLinkReader = function (
            string $realm,
            string $entity,
            array $ids,
            string $localUuid,
        ) use ($companyUuid): array {
            return $this->claimedRemoteIds($companyUuid, $realm, $entity, $ids, $localUuid);
        };
        $ledger->customerLinkReader = $this->customerLinkReader($companyUuid);

        return $ledger;
    }

    /**
     * Reads one customer link of this organization that the run did not load, so a
     * QuickBooks invoice moved to another customer finds that customer. The memory
     * directory loads every link, so it has no reader.
     */
    private function customerLinkReader(string $companyUuid): ?\Closure
    {
        if ($this->memory !== null) {
            return null;
        }

        return static function (string $realm, string $qboId) use ($companyUuid): ?array {
            $link = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('realm_id', $realm)
                ->where('local_type', 'customer')
                ->where('qbo_entity', 'Customer')
                ->where('qbo_id', $qboId)
                ->first();
            $class = 'Fleetbase\\FleetOps\\Models\\Customer';
            if ($link instanceof Link === false || class_exists($class) === false) {
                return null;
            }
            // The default scope leaves out a deleted customer.
            $exists = $class::query()
                ->where('uuid', (string) $link->local_uuid)
                ->where('company_uuid', $companyUuid)
                ->exists();

            return $exists === true ? $link->toArray() : null;
        };
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>}
     */
    private function collectPendingIds(SyncLedger $ledger, string $companyUuid, ?int $pendingLimit, ?int $now): array
    {
        $customerIds     = [];
        $invoiceIds      = [];
        $walletIds       = [];
        $flaggedInvoices = [];
        foreach ($this->loadPendingRows($companyUuid, $pendingLimit, $now) as $row) {
            if ($row instanceof PendingSync === false) {
                continue;
            }
            $pending                                      = self::toTimestamps($row->toArray(), self::PENDING_TIMES);
            $ledger->pending[]                            = $pending;
            $this->loaded['pending'][(string) $row->uuid] = $pending;
            $localUuid                                    = (string) $row->local_uuid;
            if ($row->local_type === 'customer') {
                $customerIds[] = $localUuid;
            } elseif ($row->local_type === 'wallet') {
                $walletIds[] = $localUuid;
            } elseif ($row->local_type === 'invoice') {
                $invoiceIds[]      = $localUuid;
                $flaggedInvoices[] = $localUuid;
            }
        }

        return [$customerIds, $invoiceIds, $walletIds, $flaggedInvoices];
    }

    /**
     * Products matter to invoice, product and product name rows only.
     */
    private function needsProducts(SyncLedger $ledger): bool
    {
        foreach ($ledger->pending as $row) {
            if (in_array($row['local_type'] ?? '', ['invoice', 'product', 'item'], true) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The company's QuickBooks product links: one per product name, so few rows. Every one is
     * loaded, so an invoice line knows which QuickBooks products Fleetbase manages.
     */
    private function readItemLinks(SyncLedger $ledger, string $companyUuid): void
    {
        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid)->where('local_type', 'item');
        foreach ($query->get() as $link) {
            if ($link instanceof Link) {
                $this->rememberLoadedLink($ledger, $link->toArray());
            }
        }
    }

    /**
     * The Storefront products this run needs: those of the pending product rows (deleted or a
     * draft too), and every live product sharing a name with them, with a pending product name
     * row or with an invoice line. Names are compared as QuickBooks compares them
     * (ProductMapper::key), so the company's live product names are read first. Without
     * Storefront there are no products; a failed read marks them unavailable, so no product
     * counts as gone.
     */
    private function readProducts(SyncLedger $ledger, string $companyUuid): void
    {
        $productIds = [];
        $keys       = [];
        foreach ($ledger->pending as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if (($row['local_type'] ?? '') === 'product') {
                $productIds[] = $uuid;
            } elseif (($row['local_type'] ?? '') === 'item') {
                $link = $ledger->link(
                    $companyUuid,
                    (string) ($ledger->connections[$companyUuid]['realm_id'] ?? ''),
                    'item',
                    $uuid
                );
                $name = is_array($link) === true ? trim((string) ($link['name'] ?? '')) : '';
                if ($name !== '') {
                    $keys[ProductMapper::key($name)] = true;
                }
            }
        }
        foreach ($ledger->invoices as $invoice) {
            foreach (is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [] as $item) {
                $description = is_array($item) === true ? trim((string) ($item['description'] ?? '')) : '';
                $named       = is_array($item) === true ? ProductMapper::lineProduct($item) : null;
                if ($named !== null || $description !== '') {
                    $keys[ProductMapper::key($named ?? $description)] = true;
                }
            }
        }
        $class = self::productClass();
        if (($productIds === [] && $keys === []) || $class === null) {
            return;
        }

        try {
            $pendingIds = array_values(array_unique($productIds));
            foreach ($this->productRows($class, $companyUuid, $pendingIds, true) as $product) {
                $ledger->products[$product['uuid']]                          = $product;
                $keys[ProductMapper::key((string) ($product['name'] ?? ''))] = true;
            }
            $live = [];
            foreach ($this->productQuery($class, $companyUuid, false)->get(['uuid', 'name', 'status']) as $row) {
                $row = (array) $row;
                $key = ProductMapper::key((string) ($row['name'] ?? ''));
                if (isset($keys[$key]) === true && strtolower(trim((string) ($row['status'] ?? ''))) !== 'draft') {
                    $live[] = (string) ($row['uuid'] ?? '');
                }
            }
            foreach ($this->productRows($class, $companyUuid, array_values(array_unique($live)), false) as $product) {
                $ledger->products[$product['uuid']] = $product;
            }
        } catch (\Throwable $exception) {
            if ($this->isAbsentTable($exception) === true) {
                // Storefront is installed but its tables are not there: no products.
                $ledger->products        = [];
                $ledger->productsMissing = true;

                return;
            }
            $ledger->productsUnavailable = true;
            SafeLog::warning('QuickBooks could not read Storefront products; product rows are tried again later.', [
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * The Storefront product model, or null without Storefront.
     *
     * @return class-string<Model>|null
     */
    private static function productClass(): ?string
    {
        $class = self::PRODUCT_CLASS;
        if (class_exists($class) === false || is_subclass_of($class, Model::class) === false) {
            return null;
        }

        return $class;
    }

    /**
     * The company's products, on Storefront's own database connection.
     *
     * @param class-string<Model> $class
     */
    private function productQuery(
        string $class,
        string $companyUuid,
        bool $withTrashed,
    ): \Illuminate\Database\Query\Builder {
        $query       = (new $class())->newQuery();
        $softDeletes = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true);
        if ($withTrashed === true && $softDeletes === true) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
        }

        return $query->toBase()->where('company_uuid', $companyUuid);
    }

    /**
     * @param class-string<Model> $class
     * @param array<int, string>  $uuids
     *
     * @return array<int, array<string, mixed>>
     */
    private function productRows(string $class, string $companyUuid, array $uuids, bool $withTrashed): array
    {
        $rows  = [];
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => $uuid !== ''));
        foreach (array_chunk($uuids, 500) as $chunk) {
            $query = $this->productQuery($class, $companyUuid, $withTrashed)->whereIn('uuid', $chunk);
            foreach ($query->get(self::PRODUCT_COLUMNS) as $row) {
                $row    = (array) $row;
                $rows[] = [
                    'uuid'         => (string) ($row['uuid'] ?? ''),
                    'company_uuid' => (string) ($row['company_uuid'] ?? ''),
                    'public_id'    => (string) ($row['public_id'] ?? ''),
                    'name'         => (string) ($row['name'] ?? ''),
                    'description'  => (string) ($row['description'] ?? ''),
                    'sku'          => (string) ($row['sku'] ?? ''),
                    'price'        => (int) ($row['price'] ?? 0),
                    'currency'     => (string) ($row['currency'] ?? ''),
                    'is_service'   => (bool) ($row['is_service'] ?? false),
                    'status'       => (string) ($row['status'] ?? ''),
                    'deleted_at'   => $row['deleted_at'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, string> $flaggedInvoices
     * @param array<int, string> $flaggedCustomers
     * @param array<int, string> $flaggedWallets
     */
    private function loadFullLedger(
        SyncLedger $ledger,
        string $companyUuid,
        array $flaggedInvoices,
        array $flaggedCustomers = [],
        array $flaggedWallets = [],
    ): void {
        foreach ((new Link())->newQuery()->where('company_uuid', $companyUuid)->get() as $link) {
            if ($link instanceof Link) {
                $this->rememberLoadedLink($ledger, $link->toArray());
            }
        }
        $ledger->customers = $this->readCustomers($companyUuid, null, $flaggedCustomers);
        $ledger->invoices  = $this->readInvoices($companyUuid, null, $flaggedInvoices);
        $ledger->wallets   = $this->readWallets($companyUuid, null, $flaggedWallets);
    }

    /**
     * @param array<int, string> $customerIds
     * @param array<int, string> $invoiceIds
     * @param array<int, string> $walletIds
     * @param array<int, string> $flaggedInvoices
     */
    private function loadLimitedLedger(SyncLedger $ledger, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds, array $flaggedInvoices): void
    {
        $ledger->invoices = $this->readInvoices($companyUuid, $invoiceIds, $flaggedInvoices);
        $flaggedCustomers = $customerIds;
        foreach ($ledger->invoices as $invoice) {
            $customerId = (string) ($invoice['customer_uuid'] ?? '');
            if ($customerId !== '') {
                $customerIds[] = $customerId;
            }
        }
        $customerIds       = array_values(array_unique($customerIds));
        $ledger->customers = $this->readCustomers($companyUuid, $customerIds, $flaggedCustomers);
        $ledger->wallets   = $this->readWallets($companyUuid, $walletIds, $walletIds);
        $this->readLinksFor($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);
    }

    /**
     * The company's pending rows. A batch (a limit is given) takes only due rows, skips rows
     * another run holds and leases the rows it takes, in the same lock-protected read, so the
     * company lock can be released for HTTP. Without a limit every pending row is read.
     *
     * @return iterable<mixed>
     */
    private function loadPendingRows(string $companyUuid, ?int $pendingLimit, ?int $now): iterable
    {
        $pendingQuery = (new PendingSync())->newQuery()->where('company_uuid', $companyUuid)->where('status', 'pending');
        if ($pendingLimit === null) {
            return $pendingQuery->get();
        }

        $moment = Carbon::createFromTimestamp($now ?? time());
        $pendingQuery->where(function ($query) use ($moment): void {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $moment);
        });
        // Edits first, then the reconcile and catalog backlog; each by due time, then uuid.
        if ($this->prioritySupported() === true) {
            $pendingQuery->orderBy('priority');
        }
        $pendingQuery->orderBy('next_attempt_at')->orderBy('uuid')->limit($pendingLimit);
        if ($this->claimsSupported() === false) {
            return $pendingQuery->get();
        }
        $this->excludeClaimed($pendingQuery, $moment);

        return $this->claimPending($pendingQuery->get(), $now ?? time());
    }

    /**
     * The soft-deleted Fleet-Ops customers among these uuids, as keys.
     *
     * @param array<int, mixed> $uuids
     *
     * @return array<string, true>
     */
    private function deletedCustomerUuids(array $uuids): array
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        $uuids = array_values(array_unique(array_map(self::uuidString(...), $uuids)));
        if ($uuids === [] || class_exists($class) === false) {
            return [];
        }
        $deleted = $class::onlyTrashed()->whereIn('uuid', $uuids)->pluck('uuid')->all();

        return array_fill_keys(array_map(self::uuidString(...), $deleted), true);
    }

    private static function uuidString(mixed $uuid): string
    {
        return is_scalar($uuid) === true ? (string) $uuid : '';
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return array<string, mixed>
     */
    private function batchAttributes(array $batch): array
    {
        return [
            'company_uuid'   => $batch['company_uuid'] ?? null,
            'trigger'        => $batch['trigger'] ?? 'scheduled',
            'direction'      => $batch['direction'] ?? 'outbound',
            'status'         => $batch['status'] ?? 'finished',
            'created_count'  => $batch['created'] ?? 0,
            'updated_count'  => $batch['updated'] ?? 0,
            'aligned_count'  => $batch['aligned'] ?? 0,
            'voided_count'   => $batch['voided'] ?? 0,
            'unmatched_count'=> $batch['unmatched'] ?? 0,
            'failed_count'   => $batch['failed'] ?? 0,
            'linked_count'   => $batch['linked'] ?? 0,
            'skipped_count'  => $batch['skipped'] ?? 0,
            'started_at'     => isset($batch['started_at']) === true ? date('Y-m-d H:i:s', (int) $batch['started_at']) : null,
            'finished_at'    => isset($batch['finished_at']) === true ? date('Y-m-d H:i:s', (int) $batch['finished_at']) : null,
        ];
    }

    /**
     * Deleted customers are only read when flagged, so the engine can make the QuickBooks copy inactive.
     *
     * @param array<int, string>|null $onlyUuids    null loads every customer of the company
     * @param array<int, string>      $flaggedUuids deleted customers to read too
     *
     * @return array<string, array<string, mixed>>
     */
    private function readCustomers(string $companyUuid, ?array $onlyUuids = null, array $flaggedUuids = []): array
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        $query = $this->withFlaggedTrashed($class::query(), $flaggedUuids)
            ->where('company_uuid', $companyUuid)
            ->where('type', 'customer');
        if ($onlyUuids !== null) {
            $query->whereIn('uuid', $onlyUuids);
        }

        $models = $query->get();
        $places = $this->placesByUuid($this->customerPlaceIds($models));
        $mapper = new CustomerMapper();
        $rows   = [];
        /** @var Model $customer */
        foreach ($models as $customer) {
            $row = [
                'uuid'         => (string) $customer->uuid,
                'company_uuid' => $companyUuid,
                'type'         => 'customer',
                'name'         => $customer->name,
                'email'        => $customer->email,
                'phone'        => $customer->phone,
                'notes'        => $customer->notes,
            ];
            if (empty($customer->deleted_at) === false) {
                $row['deleted_at'] = $customer->deleted_at;
            }
            $placeUuid = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
            if ($placeUuid !== '' && isset($places[$placeUuid]) === true) {
                $address = $mapper->addressFromPlace($places[$placeUuid]);
                if ($address !== null) {
                    $row['address'] = $address;
                }
            }
            $rows[(string) $customer->uuid] = $row;
        }

        return $rows;
    }

    /**
     * Deleted invoices are only read when flagged, so the engine can void them once.
     *
     * @param array<int, string>|null $onlyUuids
     * @param array<int, string>      $flaggedUuids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readInvoices(string $companyUuid, ?array $onlyUuids, array $flaggedUuids): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        // The invoice model also eager-loads customer, template, and order.trackingNumber.
        // This read copies line items only; those three relations stay unloaded. Deleted items
        // that carry meta are read too: one holding a QuickBooks line id is a line Fleetbase removed.
        $this->lineMeta = [];
        $withItems      = static function ($items): void {
            $items->withTrashed()->where(static function ($scope): void {
                $scope->whereNull('deleted_at')->orWhereNotNull('meta->' . self::LINE_META);
            });
        };
        $query = $class::query()->where('company_uuid', $companyUuid)->withOnly(['items' => $withItems]);
        if ($onlyUuids !== null) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->whereIn('uuid', $onlyUuids);
        } elseif ($flaggedUuids !== []) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->where(function ($scope) use ($flaggedUuids): void {
                $scope->whereNull('deleted_at')->orWhereIn('uuid', $flaggedUuids);
            });
        }

        $rows  = [];
        $dates = new LedgerPaymentDates();
        $db    = (new $class())->getConnection();
        /** @var Model $invoice */
        foreach ($query->get() as $invoice) {
            [$items, $removed]             = $this->readInvoiceItems($invoice);
            $rows[(string) $invoice->uuid] = [
                'uuid'          => (string) $invoice->uuid,
                'company_uuid'  => $companyUuid,
                'customer_uuid' => (string) $invoice->customer_uuid,
                'customer_type' => (string) $invoice->customer_type,
                'number'        => $invoice->number,
                'date'          => $this->dateString($invoice->date),
                'due_date'      => $this->dateString($invoice->due_date),
                'notes'         => $invoice->notes,
                'currency'      => $invoice->currency,
                'tax'           => (int) $invoice->tax,
                'total'         => (int) $invoice->total_amount,
                'amount_paid'   => (int) $invoice->amount_paid,
                'status'        => (string) $invoice->status,
                'paid_at'       => $dates->day($db, $companyUuid, $invoice->paid_at),
                'deleted_at'    => $invoice->deleted_at,
                'items'         => $items,
            ];
            if ($removed !== []) {
                $rows[(string) $invoice->uuid]['removed_lines'] = $removed;
            }
        }

        return $this->withRecordedPaymentDays($rows, $dates, $db, $companyUuid);
    }

    /**
     * Ledger sets no paid date on a part-paid invoice. The day of its last recorded payment
     * dates the QuickBooks payment instead, and stays the same until another payment is made.
     *
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function withRecordedPaymentDays(
        array $rows,
        LedgerPaymentDates $dates,
        ConnectionInterface $db,
        string $companyUuid,
    ): array {
        $undated = [];
        foreach ($rows as $uuid => $row) {
            if ($row['paid_at'] === '' && $row['amount_paid'] > 0) {
                $undated[] = (string) $uuid;
            }
        }
        foreach ($dates->lastPaymentDays($db, $companyUuid, $undated) as $uuid => $day) {
            if (isset($rows[$uuid]) === true) {
                $rows[$uuid]['payment_date'] = $day;
            }
        }
        // A new QuickBooks payment carries the reference and method of the last Ledger payment.
        $paid = array_keys(array_filter($rows, static fn (array $row): bool => $row['amount_paid'] > 0));
        foreach ($dates->lastPaymentDetails($db, $companyUuid, array_map('strval', $paid)) as $uuid => $detail) {
            if (isset($rows[$uuid]) === true) {
                $rows[$uuid]['payment_reference'] = $detail['reference'];
                $rows[$uuid]['payment_method']    = $detail['method'];
            }
        }

        return $rows;
    }

    /**
     * Live items with their uuid and stored QuickBooks line, and the stored lines of deleted items.
     *
     * @return array{
     *     0: array<int, array<string, mixed>>,
     *     1: array<int, array{
     *         uuid: string, realm: string, invoice: string, id: string, description: string, amount: int
     *     }>
     * }
     */
    private function readInvoiceItems(Model $invoice): array
    {
        $items   = [];
        $removed = [];
        $known   = ['live' => [], 'trashed' => []];
        /** @var iterable<Model> $lines */
        $lines = $invoice->items;
        foreach ($lines as $item) {
            $uuid   = (string) $item->uuid;
            $meta   = is_array($item->meta) === true ? $item->meta : null;
            $stored = self::storedLine($meta);
            if (empty($item->deleted_at) === false) {
                $known['trashed'][$uuid] = $meta;
                if ($stored !== null) {
                    $removed[] = ['uuid' => $uuid] + $stored + [
                        'description' => (string) $item->description,
                        'amount'      => (int) $item->amount,
                    ];
                }
                continue;
            }
            $known['live'][$uuid] = $meta;
            $row                  = [
                'description' => $item->description,
                'quantity'    => (int) $item->quantity,
                'unit_price'  => (int) $item->unit_price,
                'amount'      => (int) $item->amount,
                'tax_rate'    => InvoiceMapper::taxRate($item->tax_rate),
                'tax_amount'  => (int) $item->tax_amount,
                'uuid'        => $uuid,
            ];
            if ($stored !== null) {
                $row['qbo_line'] = $stored;
            }
            $product = ProductMapper::metaProduct($meta);
            if ($product !== null) {
                $row['product'] = $product;
            }
            $items[] = $row;
        }
        $this->lineMeta[(string) $invoice->uuid] = $known;

        return [$items, $removed];
    }

    /**
     * @param array<string, mixed>|null $meta
     *
     * @return array{realm: string, invoice: string, id: string}|null
     */
    private static function storedLine(?array $meta): ?array
    {
        $line = $meta[self::LINE_META] ?? null;
        if (is_array($line) === false) {
            return null;
        }
        $stored = [];
        foreach (['realm', 'invoice', 'id'] as $key) {
            $value = $line[$key] ?? null;
            if ((is_string($value) === false && is_int($value) === false) || trim((string) $value) === '') {
                return null;
            }
            $stored[$key] = trim((string) $value);
        }

        return ['realm' => $stored['realm'], 'invoice' => $stored['invoice'], 'id' => $stored['id']];
    }

    /**
     * Item meta writes for one saved invoice: the QuickBooks line of each item after a sync matched
     * or pushed it, and no stored line on items whose line is gone from QuickBooks.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private function lineMetaRows(array $invoice): array
    {
        $known = $this->lineMeta[(string) ($invoice['uuid'] ?? '')] ?? null;
        if ($known === null) {
            return [];
        }
        $rows = [];
        $map  = is_array($invoice['line_map'] ?? null) === true ? $invoice['line_map'] : null;
        if (self::replacesLines($invoice) === true) {
            foreach ($known['live'] as $itemUuid => $meta) {
                $rows[] = self::withoutStoredLine((string) $itemUuid, $meta);
            }
        } elseif ($map !== null) {
            $items = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
            $rows  = self::mappedLineRows($items, $map, $known['live']);
        }
        foreach (is_array($invoice['cleared_lines'] ?? null) === true ? $invoice['cleared_lines'] : [] as $itemUuid) {
            if (array_key_exists((string) $itemUuid, $known['trashed']) === true) {
                $rows[] = self::withoutStoredLine((string) $itemUuid, $known['trashed'][(string) $itemUuid]);
            }
        }

        return array_values(array_filter($rows));
    }

    /**
     * Line tax writes for an invoice whose line taxes QuickBooks worked out (SyncEngine::copyQuickBooksTax).
     * Lines QuickBooks replaced are written whole instead (replaceInvoiceLines).
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private function lineTaxRows(array $invoice): array
    {
        $live = $this->lineMeta[(string) ($invoice['uuid'] ?? '')]['live'] ?? null;
        $copy = empty($invoice['line_taxes_from_quickbooks']) === false && self::replacesLines($invoice) === false;
        if ($copy === false || is_array($live) === false) {
            return [];
        }
        $rows = [];
        foreach (is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [] as $item) {
            $itemUuid = is_array($item) === true ? (string) ($item['uuid'] ?? '') : '';
            if ($itemUuid === '' || array_key_exists($itemUuid, $live) === false) {
                continue;
            }
            $rows[] = ['uuid' => $itemUuid, 'columns' => [
                'tax_rate'   => InvoiceMapper::taxRate($item['tax_rate'] ?? 0),
                'tax_amount' => (int) ($item['tax_amount'] ?? 0),
            ]];
        }

        return $rows;
    }

    /**
     * One write per item: the columns of rows for the same item are merged.
     *
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $rows
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private static function rowsByUuid(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $columns              = $row['columns'] + ($merged[$row['uuid']]['columns'] ?? []);
            $merged[$row['uuid']] = ['uuid' => $row['uuid'], 'columns' => $columns];
        }

        return array_values($merged);
    }

    /**
     * Meta writes for live items whose QuickBooks line is new or changed.
     *
     * @param array<mixed>                             $items
     * @param array<mixed>                             $map
     * @param array<string, array<string, mixed>|null> $live
     *
     * @return array<int, array{uuid: string, columns: array<string, mixed>}>
     */
    private static function mappedLineRows(array $items, array $map, array $live): array
    {
        $rows = [];
        foreach ($items as $item) {
            $itemUuid = is_array($item) === true ? (string) ($item['uuid'] ?? '') : '';
            $id       = is_array($item) === true ? trim((string) ($item['qbo_line_id'] ?? '')) : '';
            if ($id === '' || array_key_exists($itemUuid, $live) === false) {
                continue;
            }
            $meta  = $live[$itemUuid] ?? [];
            $value = [
                'realm'   => (string) ($map['realm'] ?? ''),
                'invoice' => (string) ($map['invoice'] ?? ''),
                'id'      => $id,
            ];
            // A json column can return keys in another order, so compare the normalised line.
            if (self::storedLine($meta) === $value) {
                continue;
            }
            $meta[self::LINE_META] = $value;
            $rows[]                = ['uuid' => $itemUuid, 'columns' => ['meta' => json_encode($meta)]];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $meta
     *
     * @return array{uuid: string, columns: array<string, mixed>}|null
     */
    private static function withoutStoredLine(string $itemUuid, ?array $meta): ?array
    {
        if (is_array($meta) === false || array_key_exists(self::LINE_META, $meta) === false) {
            return null;
        }
        unset($meta[self::LINE_META]);

        return ['uuid' => $itemUuid, 'columns' => ['meta' => $meta === [] ? null : json_encode($meta)]];
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private static function replacesLines(array $invoice): bool
    {
        return empty($invoice['replace_from_quickbooks']) === false
            && empty($invoice['items_from_quickbooks']) === false
            && is_array($invoice['items'] ?? null) === true;
    }

    /**
     * @param array<int, string>|null $onlyUuids
     * @param array<int, string>      $flaggedUuids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readWallets(string $companyUuid, ?array $onlyUuids = null, array $flaggedUuids = []): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        $query = $this->withFlaggedTrashed($class::query(), $flaggedUuids)->where('company_uuid', $companyUuid);
        if ($onlyUuids !== null) {
            $query->whereIn('uuid', $onlyUuids);
        }

        $rows = [];
        /** @var Model $wallet */
        foreach ($query->get() as $wallet) {
            $rows[(string) $wallet->uuid] = [
                'uuid'         => (string) $wallet->uuid,
                'company_uuid' => $companyUuid,
                'public_id'    => (string) $wallet->public_id,
                'name'         => $wallet->name,
                'description'  => $wallet->description,
                'currency'     => (string) $wallet->currency,
                'status'       => (string) $wallet->status,
                'meta'         => $wallet->meta,
                // Read only: QuickBooks never changes a wallet balance, and it is not saved back.
                'balance'      => (int) ($wallet->balance ?? 0),
            ];
            if (empty($wallet->deleted_at) === false) {
                $rows[(string) $wallet->uuid]['deleted_at'] = $wallet->deleted_at;
            }
        }

        return $rows;
    }

    /**
     * Also reads the soft-deleted rows among these uuids, so a flagged delete reaches QuickBooks.
     *
     * @param array<int, string> $flaggedUuids
     */
    private function withFlaggedTrashed(
        \Illuminate\Database\Eloquent\Builder $query,
        array $flaggedUuids,
    ): \Illuminate\Database\Eloquent\Builder {
        $flaggedUuids = array_values(array_filter($flaggedUuids, static fn (string $uuid): bool => $uuid !== ''));
        if ($flaggedUuids === []) {
            return $query;
        }

        return $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->where(function ($scope) use ($flaggedUuids): void {
                $scope->whereNull('deleted_at')->orWhereIn('uuid', $flaggedUuids);
            });
    }

    private function dateString(mixed $value): string
    {
        if (is_object($value) === true && method_exists($value, 'toDateString') === true) {
            return (string) $value->toDateString();
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistCustomers(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false) {
            return;
        }

        $present = $this->existingCustomerUuids($class, $this->customerUuidsToPersist($ledger, $skipped));
        $this->insertCustomers($class, $this->saveExistingCustomers($class, $ledger, $skipped, $present));
    }

    /**
     * @param array<string, true> $skipped
     *
     * @return array<int, string>
     */
    private function customerUuidsToPersist(SyncLedger $ledger, array $skipped): array
    {
        $uuids = [];
        foreach ($ledger->customers as $customer) {
            $uuid = $this->persistableCustomerUuid($customer, $skipped);
            if ($uuid !== null) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, true>  $skipped
     */
    private function persistableCustomerUuid(array $customer, array $skipped): ?string
    {
        if (isset($skipped[(string) ($customer['company_uuid'] ?? '')]) === true) {
            return null;
        }
        $uuid = (string) ($customer['uuid'] ?? '');
        if ($uuid === '' || empty($customer['company_uuid']) === true) {
            return null;
        }

        return $uuid;
    }

    /**
     * @param array<string, true> $skipped
     * @param array<string, true> $present
     *
     * @return array<int, array<string, mixed>>
     */
    private function saveExistingCustomers(string $class, SyncLedger $ledger, array $skipped, array $present): array
    {
        $fresh = [];
        foreach ($ledger->customers as $customer) {
            $uuid = $this->persistableCustomerUuid($customer, $skipped);
            if ($uuid === null) {
                continue;
            }
            // Only fields the engine changed are written, so an edit during the batch stays.
            $loaded  = $this->loaded['customers'][$uuid] ?? null;
            $loaded  = is_array($loaded) === true ? $loaded : null;
            $values  = self::changedColumns($customer, $loaded, self::CUSTOMER_FIELDS);
            $address = $this->changedBillingAddress($customer, $loaded);
            if (isset($present[$uuid]) === true) {
                $this->updateExistingCustomer($class, $customer, $uuid, $values, $address);
                continue;
            }
            if ($values === [] && $address === null && $loaded !== null) {
                continue;
            }
            $fresh[] = $customer;
        }

        return $fresh;
    }

    /**
     * @param array<string, mixed>      $customer
     * @param array<string, mixed>      $values
     * @param array<string, mixed>|null $address
     */
    private function updateExistingCustomer(
        string $class,
        array $customer,
        string $uuid,
        array $values,
        ?array $address,
    ): void {
        if ($values === [] && $address === null) {
            return;
        }
        // A customer deleted while this run was busy is left deleted. Its uuid is taken,
        // so inserting it again would fail the whole save.
        /** @var Model|null $model */
        $model = $class::query()
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->where('uuid', $uuid)
            ->where('company_uuid', $customer['company_uuid'])
            ->first();
        if ($model === null || $model->getAttribute('deleted_at') !== null) {
            return;
        }
        if ($values !== []) {
            $model->fill($values);
            if ($model->isDirty() === true) {
                $model->save();
            }
        }
        if ($address !== null) {
            $this->writeBillingAddress($model, $address);
        }
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistInvoices(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return;
        }

        $metaRows = [];
        foreach ($ledger->invoices as $invoice) {
            $this->persistInvoice($class, $invoice, $skipped);
            if (isset($skipped[(string) ($invoice['company_uuid'] ?? '')]) === false) {
                $metaRows = array_merge($metaRows, $this->lineMetaRows($invoice), $this->lineTaxRows($invoice));
            }
        }
        $itemClass = 'Fleetbase\\Ledger\\Models\\InvoiceItem';
        if ($metaRows !== [] && class_exists($itemClass) === true) {
            $this->updateByUuid(new $itemClass(), self::rowsByUuid($metaRows), ['meta', 'tax_rate', 'tax_amount']);
        }
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $invoice
     * @param array<string, true>  $skipped
     */
    private function persistInvoice(string $class, array $invoice, array $skipped): void
    {
        if (isset($skipped[(string) ($invoice['company_uuid'] ?? '')]) === true || empty($invoice['uuid']) === true) {
            return;
        }
        $loaded = $this->loaded['invoices'][(string) $invoice['uuid']] ?? null;
        $loaded = is_array($loaded) === true ? $loaded : null;
        if ($this->invoiceNeedsWrite($invoice, $loaded) === false) {
            return;
        }
        $companyUuid = (string) ($invoice['company_uuid'] ?? '');
        $uuid        = (string) $invoice['uuid'];
        $updates     = $this->invoiceColumnUpdates($invoice, $loaded);
        if (empty($invoice['replace_from_quickbooks']) === false) {
            $updates = $this->applyQuickBooksInvoiceReplacement($invoice, $loaded, $uuid, $updates);
        }
        if (empty($invoice['payment_from_quickbooks']) === false) {
            $updates = $this->applyQuickBooksPayment($invoice, $loaded, $updates);
        }
        if ($updates === []) {
            return;
        }
        $updates['updated_at'] = Carbon::now()->toDateTimeString();
        // The invoice model eager-loads customer, items, template, and order. A table update writes the integers as stored.
        $this->invoiceTable($class, $companyUuid, $uuid)->update($updates);
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     *
     * @return array<string, mixed>
     */
    private function invoiceColumnUpdates(array $invoice, ?array $loaded): array
    {
        $updates = [];
        foreach (self::changedColumns($invoice, $loaded, ['number', 'notes', 'date', 'due_date', 'customer_uuid', 'currency']) as $field => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $updates[$field] = $value;
        }

        return $updates;
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     * @param array<string, mixed>      $updates
     *
     * @return array<string, mixed>
     */
    private function applyQuickBooksInvoiceReplacement(array $invoice, ?array $loaded, string $uuid, array $updates): array
    {
        $paid = array_key_exists('amount_paid', $invoice) === true
            ? (int) $invoice['amount_paid']
            : (int) (is_array($loaded) === true ? ($loaded['amount_paid'] ?? 0) : 0);
        $amounts = self::invoiceAmounts((int) ($invoice['total'] ?? 0), (int) ($invoice['tax'] ?? 0), $paid);
        foreach (['tax' => 'tax', 'total_amount' => 'total_amount', 'subtotal' => 'subtotal', 'balance' => 'balance'] as $column => $key) {
            $updates[$column] = $amounts[$key];
        }
        if (empty($invoice['status']) === false) {
            $updates['status'] = (string) $invoice['status'];
        }
        // An edit in QuickBooks can leave a paid invoice owing money. Only a fully paid one keeps a paid date.
        if (in_array((string) ($invoice['status'] ?? ''), ['partial', 'sent'], true) === true) {
            $updates['paid_at'] = null;
        }
        if (empty($invoice['items_from_quickbooks']) === false && is_array($invoice['items'] ?? null) === true) {
            $lineMap = is_array($invoice['line_map'] ?? null) === true ? $invoice['line_map'] : null;
            $this->replaceInvoiceLines($uuid, $invoice['items'], $lineMap);
        }

        return $updates;
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     * @param array<string, mixed>      $updates
     *
     * @return array<string, mixed>
     */
    private function applyQuickBooksPayment(array $invoice, ?array $loaded, array $updates): array
    {
        if (array_key_exists('amount_paid', $invoice) === true) {
            $updates['amount_paid'] = (int) $invoice['amount_paid'];
        }
        if (empty($invoice['paid_at']) === false) {
            $updates['paid_at'] = $invoice['paid_at'];
        }
        if (empty($invoice['status']) === false) {
            $updates['status'] = (string) $invoice['status'];
        }
        // Only a fully paid invoice keeps a paid date in Ledger.
        if (in_array((string) ($invoice['status'] ?? ''), ['partial', 'sent'], true) === true) {
            $updates['paid_at'] = null;
        }
        $updates['balance'] = self::invoiceAmounts(
            $this->storedInvoiceAmount($updates, $invoice, $loaded, 'total_amount', 'total'),
            $this->storedInvoiceAmount($updates, $invoice, $loaded, 'tax', 'tax'),
            $this->storedInvoiceAmount($updates, $invoice, $loaded, 'amount_paid', 'amount_paid')
        )['balance'];

        return $updates;
    }

    /**
     * @param array<string, mixed>      $updates
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     */
    private function storedInvoiceAmount(array $updates, array $invoice, ?array $loaded, string $updateKey, string $invoiceKey): int
    {
        if (array_key_exists($updateKey, $updates) === true) {
            return (int) $updates[$updateKey];
        }
        if ($invoiceKey === 'amount_paid') {
            return (int) (is_array($loaded) === true ? ($loaded['amount_paid'] ?? 0) : 0);
        }

        return (int) ($invoice[$invoiceKey] ?? (is_array($loaded) === true ? ($loaded[$invoiceKey] ?? 0) : 0));
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistWallets(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($class) === false) {
            return;
        }

        foreach ($ledger->wallets as $wallet) {
            if (isset($skipped[(string) ($wallet['company_uuid'] ?? '')]) === true) {
                continue;
            }
            if (empty($wallet['uuid']) === true) {
                continue;
            }
            $loaded = $this->loaded['wallets'][(string) $wallet['uuid']] ?? null;
            if (self::changedColumns($wallet, $loaded, ['name', 'description', 'currency', 'status', 'meta']) === [] && is_array($loaded) === true) {
                continue;
            }
            /** @var Model|null $model */
            $model = $class::query()->where('uuid', $wallet['uuid'])->where('company_uuid', $wallet['company_uuid'] ?? '')->first();
            if ($model === null) {
                continue;
            }
            $loaded = $this->loaded['wallets'][(string) $wallet['uuid']] ?? null;
            foreach (self::changedColumns($wallet, $loaded, ['name', 'description', 'currency', 'status', 'meta']) as $field => $value) {
                if (in_array($field, ['currency', 'status'], true) === true && ($value === '' || $value === null)) {
                    continue;
                }
                $model->{$field} = $value;
            }
            if ($model->isDirty() === true) {
                $model->save();
            }
        }
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $loaded
     */
    private function invoiceNeedsWrite(array $invoice, ?array $loaded): bool
    {
        if (empty($invoice['replace_from_quickbooks']) === false || empty($invoice['payment_from_quickbooks']) === false) {
            return true;
        }

        return self::changedColumns($invoice, $loaded, ['number', 'notes', 'date', 'due_date', 'status', 'amount_paid', 'paid_at', 'tax', 'total', 'customer_uuid', 'currency']) !== []
            || is_array($loaded) === false;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogPending(string $companyUuid, string $localType, string $localUuid): array
    {
        return [
            'company_uuid'    => $companyUuid,
            'local_type'      => $localType,
            'local_uuid'      => $localUuid,
            'reason'          => 'reconcile',
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => null,
        ];
    }

    /**
     * Local non-draft invoices for this company, plus invoice links for this company.
     * Each source is one query. The merged page is at most $limit ids.
     *
     * @return array<int, string>
     */
    private function inScopeInvoiceIds(string $companyUuid, int $limit, string $afterUuid): array
    {
        $ids          = [];
        $invoiceClass = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($invoiceClass) === true) {
            $query = $invoiceClass::query()->where('company_uuid', $companyUuid)->where('status', '!=', 'draft')->orderBy('uuid');
            if ($afterUuid !== '') {
                $query->where('uuid', '>', $afterUuid);
            }
            foreach ($query->limit($limit)->pluck('uuid') as $uuid) {
                $ids[] = (string) $uuid;
            }
        }

        $linkQuery = (new Link())->newQuery()->where('company_uuid', $companyUuid)->where('local_type', 'invoice')->orderBy('local_uuid');
        if ($afterUuid !== '') {
            $linkQuery->where('local_uuid', '>', $afterUuid);
        }
        foreach ($linkQuery->limit($limit)->pluck('local_uuid') as $uuid) {
            $ids[] = (string) $uuid;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    /**
     * One page of catalog ids, ordered by uuid, after the previous page.
     *
     * @param class-string $class
     *
     * @return array<int, string>
     */
    private function catalogIdPage(string $class, string $companyUuid, bool $customersOnly, string $afterUuid): array
    {
        if (class_exists($class) === false) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid);
        if ($customersOnly === true) {
            $query->where('type', 'customer');
        } elseif ($class === 'Fleetbase\\Ledger\\Models\\Invoice') {
            $query->where('status', '!=', 'draft');
        } elseif ($class === self::productClass()) {
            $query->where(static function ($live): void {
                $live->whereNull('status')->orWhere('status', '!=', 'draft');
            });
        }
        $query->orderBy('uuid');
        if ($afterUuid !== '') {
            $query->where('uuid', '>', $afterUuid);
        }

        $ids = [];
        foreach ($query->limit($this->catalogPageSize())->pluck('uuid') as $uuid) {
            $ids[] = (string) $uuid;
        }

        return $ids;
    }

    /**
     * @param class-string $class
     */
    private function queueCatalog(string $class, string $companyUuid, bool $customersOnly, string $localType): void
    {
        $after    = '';
        $pageSize = $this->catalogPageSize();
        while (true) {
            try {
                $ids = $this->catalogIdPage($class, $companyUuid, $customersOnly, $after);
            } catch (\Throwable) {
                return;
            }
            if ($ids === []) {
                return;
            }
            $rows = [];
            foreach ($ids as $uuid) {
                $rows[] = $this->catalogPending($companyUuid, $localType, $uuid);
            }
            $this->writePendingMany($rows);
            $after = (string) $ids[array_key_last($ids)];
            if (count($ids) < $pageSize) {
                return;
            }
        }
    }

    private function queueInvoiceLinks(string $companyUuid): void
    {
        $this->queueLinksOfType($companyUuid, 'invoice');
    }

    /**
     * Queue the local record of every link of one type, a page at a time.
     */
    private function queueLinksOfType(string $companyUuid, string $localType): void
    {
        $after    = '';
        $pageSize = $this->catalogPageSize();
        while (true) {
            try {
                $query = (new Link())->newQuery()
                    ->where('company_uuid', $companyUuid)
                    ->where('local_type', $localType)
                    ->orderBy('local_uuid');
                if ($after !== '') {
                    $query->where('local_uuid', '>', $after);
                }
                $ids = [];
                foreach ($query->limit($pageSize)->pluck('local_uuid') as $uuid) {
                    $ids[] = (string) $uuid;
                }
            } catch (\Throwable) {
                return;
            }
            if ($ids === []) {
                return;
            }
            $rows = [];
            foreach ($ids as $uuid) {
                $rows[] = $this->catalogPending($companyUuid, $localType, $uuid);
            }
            $this->writePendingMany($rows);
            $after = (string) $ids[array_key_last($ids)];
            if (count($ids) < $pageSize) {
                return;
            }
        }
    }

    /**
     * @param array<int, string> $customerIds
     * @param array<int, string> $invoiceIds
     * @param array<int, string> $walletIds
     */
    private function readLinksFor(SyncLedger $ledger, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds): void
    {
        if ($customerIds === [] && $invoiceIds === [] && $walletIds === []) {
            return;
        }

        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid)->where(function ($scope) use ($companyUuid, $customerIds, $invoiceIds, $walletIds): void {
            $started = false;
            if ($customerIds !== []) {
                $scope->where(function ($inner) use ($customerIds): void {
                    $inner->where('local_type', 'customer')->whereIn('local_uuid', $customerIds);
                });
                $started = true;
            }
            if ($walletIds !== []) {
                $method = $started === true ? 'orWhere' : 'where';
                $scope->{$method}(function ($inner) use ($walletIds): void {
                    $inner->where('local_type', 'wallet')->whereIn('local_uuid', $walletIds);
                });
                $started = true;
            }
            if ($invoiceIds !== []) {
                $method = $started === true ? 'orWhere' : 'where';
                $scope->{$method}(function ($inner) use ($invoiceIds, $companyUuid): void {
                    $inner->where(function ($typed) use ($invoiceIds): void {
                        $typed->whereIn('local_type', ['invoice', 'payment'])->whereIn('local_uuid', $invoiceIds);
                    })->orWhere(function ($mapped) use ($invoiceIds): void {
                        // qbo_id holds the Fleetbase invoice on a payment-invoice row.
                        $mapped->where('local_type', 'payment-invoice')->whereIn('qbo_id', $invoiceIds);
                    })->orWhere(function ($payments) use ($invoiceIds, $companyUuid): void {
                        // The payment link itself is stored under the QuickBooks payment id.
                        $payments->where('local_type', 'payment')->whereIn('local_uuid', function ($sub) use ($invoiceIds, $companyUuid): void {
                            $sub->select('local_uuid')
                                ->from((new Link())->getTable())
                                ->where('company_uuid', $companyUuid)
                                ->where('local_type', 'payment-invoice')
                                ->whereIn('qbo_id', $invoiceIds);
                        });
                    });
                });
            }
        });

        foreach ($query->get() as $link) {
            if ($link instanceof Link) {
                $this->rememberLoadedLink($ledger, $link->toArray());
            }
        }
    }

    /**
     * Minor units stored as signed integers. The Money cast strips a leading minus.
     *
     * @return array{tax: int, total_amount: int, subtotal: int, balance: int}
     */
    public static function invoiceAmounts(int $total, int $tax, int $amountPaid): array
    {
        return [
            'tax'          => $tax,
            'total_amount' => $total,
            'subtotal'     => $total - $tax,
            'balance'      => $total - $amountPaid,
        ];
    }

    /**
     * Write a signed minor-unit integer without the Money cast.
     */
    public static function writeSignedMinor(Model $model, string $field, int $minor): void
    {
        $attributes = $model->getAttributes();
        $current    = $attributes[$field] ?? null;
        if ($current === $minor || (is_string($current) === true && $current === (string) $minor)) {
            return;
        }

        $attributes[$field] = $minor;
        $model->setRawAttributes($attributes);
    }

    private static function minorAttribute(Model $model, string $field): int
    {
        $value = $model->getAttributes()[$field] ?? 0;
        if ($value === null || $value === '' || is_bool($value) === true) {
            return 0;
        }
        if (is_int($value) === true) {
            return $value;
        }
        if (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private function stampOwnedCompanies(SyncLedger $ledger): void
    {
        foreach ($ledger->links as $index => $link) {
            if (is_array($link) === false) {
                continue;
            }
            $link['company_uuid']   = $this->entityCompanyFor(
                $ledger,
                (string) ($link['local_type'] ?? ''),
                (string) ($link['local_uuid'] ?? ''),
                (string) ($link['company_uuid'] ?? '')
            );
            $ledger->links[$index] = $link;
        }
        foreach ($ledger->pending as $index => $row) {
            if (is_array($row) === false) {
                continue;
            }
            $row['company_uuid']      = $this->entityCompanyFor(
                $ledger,
                (string) ($row['local_type'] ?? ''),
                (string) ($row['local_uuid'] ?? ''),
                (string) ($row['company_uuid'] ?? '')
            );
            $ledger->pending[$index] = $row;
        }
    }

    private function entityCompanyFor(SyncLedger $ledger, string $localType, string $localUuid, string $fallback): string
    {
        $row = null;
        if ($localType === 'customer') {
            $row = $ledger->customers[$localUuid] ?? null;
        } elseif ($localType === 'wallet') {
            $row = $ledger->wallets[$localUuid] ?? null;
        } elseif ($localType === 'invoice' || $localType === 'payment') {
            $row = $ledger->invoices[$localUuid] ?? null;
        } elseif ($localType === 'product') {
            $row = $ledger->products[$localUuid] ?? null;
        }
        if (is_array($row) === false) {
            return $fallback;
        }
        $companyUuid = trim((string) ($row['company_uuid'] ?? ''));

        return $companyUuid !== '' ? $companyUuid : $fallback;
    }

    private function isMissingTable(\Illuminate\Database\QueryException $exception): bool
    {
        return $this->isUnavailableStorage($exception);
    }

    /**
     * A table that is not there. A database connection that is not configured is not one of these.
     */
    private function isAbsentTable(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'no such table') === true
            || str_contains($message, "doesn't exist") === true
            || str_contains($message, 'base table or view not found') === true;
    }

    private function isUnavailableStorage(\Throwable $exception): bool
    {
        return $this->isAbsentTable($exception) === true
            || str_contains(strtolower($exception->getMessage()), 'not configured') === true;
    }

    /**
     * One update for a chunk of rows that already exist. Each row carries only
     * the columns that changed. New rows stay on the insert path.
     *
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $rows
     * @param array<int, string>                                             $allowed
     * @param array{0: string, 1: array<int, mixed>}|null                    $guard   an extra WHERE condition and its bindings
     */
    private function updateByUuid(Model $model, array $rows, array $allowed, ?array $guard = null): void
    {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['uuid'] ?? '') !== '' && ($row['columns'] ?? []) !== []
        ));
        if ($rows === []) {
            return;
        }

        $connection = $model->getConnection();
        foreach (array_chunk($rows, 200) as $chunk) {
            $statement = $this->chunkUpdate($connection->getQueryGrammar(), $model->getTable(), $chunk, $allowed, $guard);
            if ($statement !== null) {
                $connection->update($statement[0], $statement[1]);
            }
        }
    }

    /**
     * One UPDATE for a chunk: each changed, allowed column is set with a CASE on the uuid.
     * Null when no row in the chunk changes an allowed column.
     *
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $chunk
     * @param array<int, string>                                             $allowed
     * @param array{0: string, 1: array<int, mixed>}|null                    $guard
     *
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    private function chunkUpdate(\Illuminate\Database\Grammar $grammar, string $tableName, array $chunk, array $allowed, ?array $guard): ?array
    {
        $uuidColumn  = $grammar->wrap('uuid');
        $assignments = [];
        $bindings    = [];
        foreach ($this->chunkColumns($chunk, $allowed) as $column) {
            $cases = [];
            foreach ($chunk as $row) {
                if (array_key_exists($column, $row['columns']) === false) {
                    continue;
                }
                $cases[]    = 'WHEN ? THEN ?';
                $bindings[] = $row['uuid'];
                $bindings[] = $this->sqlValue($row['columns'][$column]);
            }
            $assignments[] = $grammar->wrap($column) . ' = CASE ' . $uuidColumn . ' ' . implode(' ', $cases) . ' ELSE ' . $grammar->wrap($column) . ' END';
        }
        if ($assignments === []) {
            return null;
        }
        $assignments[] = $grammar->wrap('updated_at') . ' = ?';
        $bindings[]    = Carbon::now()->toDateTimeString();
        foreach ($chunk as $row) {
            $bindings[] = $row['uuid'];
        }
        $sql = 'UPDATE ' . $grammar->wrapTable($tableName) . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $uuidColumn . ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')';
        if ($guard !== null) {
            $sql .= ' AND ' . $guard[0];
            foreach ($guard[1] as $binding) {
                $bindings[] = $binding;
            }
        }

        return [$sql, $bindings];
    }

    /**
     * The allowed columns that at least one row of the chunk changes, in first-seen order.
     *
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $chunk
     * @param array<int, string>                                             $allowed
     *
     * @return array<int, string>
     */
    private function chunkColumns(array $chunk, array $allowed): array
    {
        $names = [];
        foreach ($chunk as $row) {
            foreach (array_keys($row['columns']) as $column) {
                if (in_array($column, $allowed, true) === true) {
                    $names[$column] = true;
                }
            }
        }

        return array_map(static fn (int|string $column): string => (string) $column, array_keys($names));
    }

    private function sqlValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $attempts
     */
    private function insertAttempts(array $attempts): void
    {
        $now  = Carbon::now()->toDateTimeString();
        $rows = [];
        foreach ($attempts as $attempt) {
            if (is_array($attempt) === false || empty($attempt['uuid']) === false) {
                continue;
            }
            $diff = $attempt['diff'] ?? null;
            if (is_array($diff) === true) {
                $encoded = json_encode($diff);
                $diff    = $encoded === false ? null : $encoded;
            }
            $rows[] = [
                'uuid'         => (string) Str::uuid(),
                'batch_uuid'   => $attempt['batch_uuid'] ?? null,
                'company_uuid' => $attempt['company_uuid'] ?? null,
                'local_type'   => (string) ($attempt['local_type'] ?? ''),
                'local_uuid'   => (string) ($attempt['local_uuid'] ?? ''),
                'outcome'      => (string) ($attempt['outcome'] ?? ''),
                'error'        => $attempt['error'] ?? null,
                'diff'         => $diff,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            SyncAttempt::query()->insert($chunk);
        }
    }

    /**
     * Replace every current line for one invoice with the inbound lines.
     * One delete of the current lines, then one insert. Integers are written
     * on the table so a negative amount does not pass through the Money cast.
     * With a line map, each new line stores the QuickBooks line it came from, and each keeps the
     * product it names.
     *
     * @param array<int, mixed>                            $items
     * @param array{realm?: string, invoice?: string}|null $lineMap
     */
    private function replaceInvoiceLines(string $invoiceUuid, array $items, ?array $lineMap = null): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\InvoiceItem';
        if (class_exists($class) === false || $invoiceUuid === '') {
            return;
        }

        $model = new $class();
        $now   = Carbon::now()->toDateTimeString();
        $model->getConnection()->table($model->getTable())
            ->where('invoice_uuid', $invoiceUuid)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        $rows = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }
            $row = [
                'uuid'         => (string) Str::uuid(),
                'invoice_uuid' => $invoiceUuid,
                'description'  => (string) ($item['description'] ?? ''),
                'quantity'     => (int) ($item['quantity'] ?? 1),
                'unit_price'   => (int) ($item['unit_price'] ?? 0),
                'amount'       => (int) ($item['amount'] ?? 0),
                'tax_rate'     => InvoiceMapper::taxRate($item['tax_rate'] ?? 0),
                'tax_amount'   => (int) ($item['tax_amount'] ?? 0),
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
            $row['meta'] = self::replacedLineMeta($item, $lineMap);
            $rows[]      = $row;
        }
        if ($rows === []) {
            return;
        }

        $model->getConnection()->table($model->getTable())->insert($rows);
    }

    /**
     * The meta of a line replaced from QuickBooks: the QuickBooks line it came from (with a line
     * map), and the product it named before the copy (InvoiceMapper::withLineProducts).
     *
     * @param array<string, mixed>                         $item
     * @param array{realm?: string, invoice?: string}|null $lineMap
     */
    private static function replacedLineMeta(array $item, ?array $lineMap): ?string
    {
        $meta   = [];
        $lineId = $lineMap !== null ? trim((string) ($item['qbo_line_id'] ?? '')) : '';
        if ($lineId !== '') {
            $meta[self::LINE_META] = [
                'realm'   => (string) ($lineMap['realm'] ?? ''),
                'invoice' => (string) ($lineMap['invoice'] ?? ''),
                'id'      => $lineId,
            ];
        }
        $product = ProductMapper::metaProduct(['product' => $item['product'] ?? null]);
        if ($product !== null) {
            $meta['product'] = $product;
        }

        return $meta === [] ? null : (string) json_encode($meta);
    }

    /**
     * @param class-string       $class
     * @param array<int, string> $uuids
     *
     * @return array<string, true>
     */
    private function existingCustomerUuids(string $class, array $uuids): array
    {
        $present = [];
        if ($uuids === []) {
            return $present;
        }

        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach ($class::query()->withoutGlobalScopes()->whereIn('uuid', $chunk)->pluck('uuid') as $uuid) {
                $present[(string) $uuid] = true;
            }
        }

        return $present;
    }

    /**
     * @param class-string                     $class
     * @param array<int, array<string, mixed>> $customers
     */
    private function insertCustomers(string $class, array $customers): void
    {
        if ($customers === []) {
            return;
        }

        $rows = $this->customerInsertRows($class, $customers);
        $this->fillMissingPlaceUuids($rows);
        foreach (array_chunk($rows, 200) as $chunk) {
            $class::query()->insert($chunk);
        }
    }

    /**
     * @param class-string                     $class
     * @param array<int, array<string, mixed>> $customers
     *
     * @return array<int, array<string, mixed>>
     */
    private function customerInsertRows(string $class, array $customers): array
    {
        $now      = Carbon::now()->toDateTimeString();
        $rows     = [];
        $store    = $this->placeTable();
        $canPlace = $store !== null && $this->customerColumnExists($class, 'place_uuid') === true;
        foreach ($customers as $customer) {
            $row = $this->customerInsertRow($customer, $now, $canPlace, $store);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>                                                   $customer
     * @param array{connection: \Illuminate\Database\Connection, table: string}|null $store
     *
     * @return array<string, mixed>|null
     */
    private function customerInsertRow(array $customer, string $now, bool $canPlace, ?array $store): ?array
    {
        $uuid = (string) ($customer['uuid'] ?? '');
        if ($uuid === '') {
            return null;
        }
        $name = (string) ($customer['name'] ?? '');
        $slug = $name === '' ? null : Str::slug($name);
        $meta = $customer['meta'] ?? null;
        if (is_array($meta) === true) {
            $encoded = json_encode($meta);
            $meta    = $encoded === false ? null : $encoded;
        }
        $row = [
            'uuid'         => $uuid,
            'public_id'    => 'contact_' . strtolower(Str::random(10)),
            'internal_id'  => (string) random_int(100000, 999999),
            'company_uuid' => $customer['company_uuid'],
            'name'         => $name !== '' ? $name : null,
            'email'        => $customer['email'] ?? null,
            'phone'        => $customer['phone'] ?? null,
            'type'         => 'customer',
            'notes'        => $customer['notes'] ?? null,
            'meta'         => $meta,
            'slug'         => $slug === '' ? null : $slug,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $placeUuid = $this->customerPlaceUuid($store, $canPlace, $customer, $uuid);
        if ($placeUuid !== null) {
            $row['place_uuid'] = $placeUuid;
        }

        return $row;
    }

    /**
     * @param array{connection: \Illuminate\Database\Connection, table: string}|null $store
     * @param array<string, mixed>                                                   $customer
     */
    private function customerPlaceUuid(?array $store, bool $canPlace, array $customer, string $uuid): ?string
    {
        $address = $customer['address'] ?? null;
        if ($canPlace === false || $store === null || is_array($address) === false || $this->billingAddressHasContent($address) === false) {
            return null;
        }

        return $this->insertCustomerPlace($store, (string) $customer['company_uuid'], $uuid, $address);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function fillMissingPlaceUuids(array &$rows): void
    {
        $withPlace = false;
        foreach ($rows as $row) {
            if (array_key_exists('place_uuid', $row) === true) {
                $withPlace = true;
                break;
            }
        }
        if ($withPlace === false) {
            return;
        }
        foreach ($rows as $index => $row) {
            if (array_key_exists('place_uuid', $row) === false) {
                $rows[$index]['place_uuid'] = null;
            }
        }
    }

    /**
     * @param iterable<Model> $customers
     *
     * @return array<int, string>
     */
    private function customerPlaceIds(iterable $customers): array
    {
        $ids = [];
        foreach ($customers as $customer) {
            $id = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<int, string> $uuids
     *
     * @return array<string, object>
     */
    private function placesByUuid(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $store = $this->placeTable();
        if ($store === null) {
            return [];
        }

        $places = [];
        foreach (array_chunk($uuids, 500) as $chunk) {
            foreach ($store['connection']->table($store['table'])->whereIn('uuid', $chunk)->get() as $place) {
                $places[(string) $place->uuid] = $place;
            }
        }

        return $places;
    }

    /**
     * The billing address to write. An address with no content is written only to empty one the
     * customer had: QuickBooks, as Primary, emptied it.
     *
     * @param array<string, mixed>      $customer
     * @param array<string, mixed>|null $loaded
     *
     * @return array<string, mixed>|null
     */
    private function changedBillingAddress(array $customer, ?array $loaded): ?array
    {
        if (isset($customer['address']) === false || is_array($customer['address']) === false) {
            return null;
        }

        $previous = $loaded['address'] ?? null;
        if ($previous === $customer['address']) {
            return null;
        }
        if ($this->billingAddressHasContent($customer['address']) === false) {
            $emptied = is_array($previous) === true && $this->billingAddressHasContent($previous) === true;

            return $emptied === true ? $customer['address'] : null;
        }

        return $customer['address'];
    }

    /**
     * @param array<string, mixed> $address
     */
    private function billingAddressHasContent(array $address): bool
    {
        foreach (['line1', 'line2', 'city', 'state', 'postal_code', 'country'] as $field) {
            if (($address[$field] ?? null) !== null && $address[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function writeBillingAddress(Model $customer, array $address): void
    {
        $store = $this->placeTable();
        if ($store === null) {
            return;
        }

        $columns               = (new CustomerMapper())->placeColumns($address);
        $columns['updated_at'] = Carbon::now()->toDateTimeString();
        $placeUuid             = trim((string) ($customer->getAttributes()['place_uuid'] ?? ''));
        if ($placeUuid !== '') {
            $updated = $store['connection']->table($store['table'])->where('uuid', $placeUuid)->update($columns);
            if ($updated > 0) {
                return;
            }
        }
        // An emptied address empties the place it had; no place is made for no address.
        if ($this->billingAddressHasContent($address) === false) {
            return;
        }

        $created = $this->insertCustomerPlace($store, (string) $customer->company_uuid, (string) $customer->uuid, $address);
        if ($created === null || $this->customerColumnExists($customer::class, 'place_uuid') === false) {
            return;
        }

        $customer->place_uuid = $created;
        $customer->save();
    }

    /**
     * @param array{connection: \Illuminate\Database\Connection, table: string} $store
     * @param array<string, mixed>                                              $address
     */
    private function insertCustomerPlace(array $store, string $companyUuid, string $ownerUuid, array $address): ?string
    {
        $now  = Carbon::now()->toDateTimeString();
        $uuid = (string) Str::uuid();
        $row  = array_merge((new CustomerMapper())->placeColumns($address), [
            'uuid'         => $uuid,
            'public_id'    => 'place_' . strtolower(Str::random(10)),
            'company_uuid' => $companyUuid,
            'owner_uuid'   => $ownerUuid,
            'owner_type'   => 'Fleetbase\\FleetOps\\Models\\Customer',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $connection = $store['connection'];
        if ($connection->getDriverName() === 'mysql' && $connection->getSchemaBuilder()->hasColumn($store['table'], 'location') === true) {
            $row['location'] = $connection->raw("(ST_PointFromText('POINT(0 0)', 0, 'axis-order=long-lat'))");
        }
        $connection->table($store['table'])->insert($row);

        return $uuid;
    }

    /**
     * @return array{connection: \Illuminate\Database\Connection, table: string}|null
     */
    private function placeTable(): ?array
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Place';
        if (class_exists($class) === false) {
            return null;
        }

        try {
            $model      = new $class();
            $table      = $model->getTable();
            $connection = $model->getConnection();
            if ($connection->getSchemaBuilder()->hasTable($table) === false) {
                return null;
            }

            return ['connection' => $connection, 'table' => $table];
        } catch (\Throwable) {
            return null;
        }
    }

    private function customerColumnExists(string $class, string $column): bool
    {
        if (class_exists($class) === false) {
            return false;
        }

        try {
            $model = new $class();

            return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
