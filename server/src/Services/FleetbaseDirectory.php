<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\Concerns\AppliesMemoryDeletes;
use Fleetbase\Quickbooks\Services\Concerns\AppliesRemoteDeletes;
use Fleetbase\Quickbooks\Services\Concerns\FindsClaimedRecords;
use Fleetbase\Quickbooks\Services\Concerns\FindsDeletedLinks;
use Fleetbase\Quickbooks\Services\Concerns\PersistsCustomerPlaces;
use Fleetbase\Quickbooks\Services\Concerns\PersistsRecords;
use Fleetbase\Quickbooks\Services\Concerns\QueuesPending;
use Fleetbase\Quickbooks\Services\Concerns\ReadsRecords;
use Fleetbase\Quickbooks\Services\Concerns\RetiresDeleteBatches;
use Fleetbase\Quickbooks\Services\Concerns\StoresConnections;
use Fleetbase\Quickbooks\Services\Concerns\StoresLinks;
use Fleetbase\Quickbooks\Services\Concerns\StoresPaymentInvoiceRows;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Loads and saves the SyncLedger through Eloquent. The engine compares times as
 * unix seconds, so datetime columns become ints on load and Carbon on save.
 *
 * @phpstan-type RemoteDelete array{
 *     realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null,
 *     invoice_uuids?: array<int, string>, invoices_from_payment?: bool
 * }
 * @phpstan-type LoadedCompany array{
 *     ledger: SyncLedger, connection: array<string, mixed>, customers: array<int, array<string, mixed>>
 * }
 * @phpstan-type LinkWrites array{
 *     releases: array<int, array{link: array<string, mixed>, keep: string}>,
 *     updates: array<int, array{uuid: string, columns: array<string, mixed>}>
 * }
 */
class FleetbaseDirectory
{
    use AppliesMemoryDeletes;
    use AppliesRemoteDeletes;
    use FindsClaimedRecords;
    use FindsDeletedLinks;
    use PersistsCustomerPlaces;
    use PersistsRecords;
    use QueuesPending;
    use ReadsRecords;
    use RetiresDeleteBatches;
    use StoresConnections;
    use StoresLinks;
    use StoresPaymentInvoiceRows;

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
     * @var array{
     *     connections: array<string, array<string, mixed>>,
     *     pending: array<string, array<string, mixed>>,
     *     customers: array<string, array<string, mixed>>,
     *     invoices: array<string, array<string, mixed>>,
     *     wallets: array<string, array<string, mixed>>,
     *     links: array<string, array<string, mixed>>
     * }
     */
    private array $loaded = [
        'connections' => [],
        'pending'     => [],
        'customers'   => [],
        'invoices'    => [],
        'wallets'     => [],
        'links'       => [],
    ];

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
     * @return LoadedCompany|null
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
     * @return LoadedCompany|null
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
     * @return LoadedCompany|null
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
            $key                         = self::linkKey($link);
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
            $taken = isset($claimed[$number]) === true || $model->getConnection()->table($model->getTable())
                ->where('number', $number)
                ->where('uuid', '!=', $uuid)
                ->exists() === true;
            if ($taken === false) {
                $claimed[$number] = $uuid;
                continue;
            }

            $ledger->invoices[$key]['number'] = $kept;
            $ledger->rememberInvoice($uuid, $ledger->invoices[$key]);
            $this->noteInvoiceNumberCollision(
                $ledger,
                (string) ($invoice['company_uuid'] ?? ''),
                $uuid,
                $number,
                $kept
            );
        }
    }

    private function noteInvoiceNumberCollision(
        SyncLedger $ledger,
        string $companyUuid,
        string $uuid,
        string $number,
        string $kept,
    ): void {
        $note = Translation::encode('quickbooks.messages.invoice-number-taken', ['number' => $number]);
        if ($kept !== '') {
            $keeps = Translation::encode('quickbooks.messages.invoice-number-kept', ['number' => $kept]);
            $note  = Translation::append($note, $keeps);
        }
        $noted = false;
        foreach ($ledger->attempts as $index => $attempt) {
            if (
                empty($attempt['uuid']) === false
                || (string) ($attempt['local_type'] ?? '') !== 'invoice'
                || (string) ($attempt['local_uuid'] ?? '') !== $uuid
            ) {
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
            $stored           = is_array($ledgerConnection) === true ? $ledgerConnection : null;
            $current          = self::connectionStillCurrent($this->connection($companyUuid), $stored);
            if ($current === false) {
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
        [$customerIds, $invoiceIds, $walletIds, $flaggedInvoices] = $this->collectPendingIds(
            $ledger,
            $companyUuid,
            $pendingLimit,
            $now
        );
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
    private function loadLimitedLedger(
        SyncLedger $ledger,
        string $companyUuid,
        array $customerIds,
        array $invoiceIds,
        array $walletIds,
        array $flaggedInvoices,
    ): void {
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
        $pendingQuery = (new PendingSync())->newQuery()->where('company_uuid', $companyUuid)->where(
            'status',
            'pending'
        );
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
     * A batch time in unix seconds as the batch table stores it, or null when unset.
     */
    private static function batchTime(mixed $time): ?string
    {
        return $time === null ? null : date('Y-m-d H:i:s', (int) $time);
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
            'started_at'     => self::batchTime($batch['started_at'] ?? null),
            'finished_at'    => self::batchTime($batch['finished_at'] ?? null),
        ];
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
            $query = $invoiceClass::query()
                ->where('company_uuid', $companyUuid)
                ->where('status', '!=', 'draft')
                ->orderBy('uuid');
            if ($afterUuid !== '') {
                $query->where('uuid', '>', $afterUuid);
            }
            foreach ($query->limit($limit)->pluck('uuid') as $uuid) {
                $ids[] = (string) $uuid;
            }
        }

        $linkQuery = (new Link())->newQuery()
            ->where('company_uuid', $companyUuid)
            ->where('local_type', 'invoice')
            ->orderBy('local_uuid');
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
    private function readLinksFor(
        SyncLedger $ledger,
        string $companyUuid,
        array $customerIds,
        array $invoiceIds,
        array $walletIds,
    ): void {
        if ($customerIds === [] && $invoiceIds === [] && $walletIds === []) {
            return;
        }

        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid);
        $query->where(function ($scope) use ($companyUuid, $customerIds, $invoiceIds, $walletIds): void {
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
                    self::scopeInvoiceLinks($inner, $invoiceIds, $companyUuid);
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
     * Links of these invoices: their invoice and payment rows, their payment-invoice rows,
     * and the payment links those rows point at.
     *
     * @param array<int, string> $invoiceIds
     */
    private static function scopeInvoiceLinks(Builder $inner, array $invoiceIds, string $companyUuid): void
    {
        $inner->where(function ($typed) use ($invoiceIds): void {
            $typed->whereIn('local_type', ['invoice', 'payment'])->whereIn('local_uuid', $invoiceIds);
        })->orWhere(function ($mapped) use ($invoiceIds): void {
            // qbo_id holds the Fleetbase invoice on a payment-invoice row.
            $mapped->where('local_type', 'payment-invoice')->whereIn('qbo_id', $invoiceIds);
        })->orWhere(function ($payments) use ($invoiceIds, $companyUuid): void {
            // The payment link itself is stored under the QuickBooks payment id.
            $payments->where('local_type', 'payment');
            $payments->whereIn('local_uuid', function ($sub) use ($invoiceIds, $companyUuid): void {
                $sub->select('local_uuid')
                    ->from((new Link())->getTable())
                    ->where('company_uuid', $companyUuid)
                    ->where('local_type', 'payment-invoice')
                    ->whereIn('qbo_id', $invoiceIds);
            });
        });
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

    private function entityCompanyFor(
        SyncLedger $ledger,
        string $localType,
        string $localUuid,
        string $fallback,
    ): string {
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
     * @param array{0: string, 1: array<int, mixed>}|null                    $guard   extra WHERE and its bindings
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
            $statement = $this->chunkUpdate(
                $connection->getQueryGrammar(),
                $model->getTable(),
                $chunk,
                $allowed,
                $guard
            );
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
    private function chunkUpdate(
        \Illuminate\Database\Grammar $grammar,
        string $tableName,
        array $chunk,
        array $allowed,
        ?array $guard,
    ): ?array {
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
            $wrapped       = $grammar->wrap($column);
            $assignments[] = $wrapped . ' = CASE ' . $uuidColumn . ' ' . implode(' ', $cases)
                . ' ELSE ' . $wrapped . ' END';
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
}
