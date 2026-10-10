<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Loads and saves the SyncLedger through Eloquent. The engine compares times as
 * unix seconds, so datetime columns become ints on load and Carbon on save.
 */
class FleetbaseDirectory
{
    public const CONNECTION_TIMES = ['token_expires_at', 'rate_limited_until', 'last_batch_at'];

    public const PENDING_TIMES = ['next_attempt_at'];

    /** The only connection fields a batch writes; the rest belong to connect and disconnect. */
    public const CONNECTION_ENGINE_FIELDS = ['rate_limited_until', 'last_rate_limit_wait', 'last_batch_at', 'home_currency', 'default_item_id', 'needs_reauth'];

    public const CONNECTION_TOKEN_FIELDS = ['access_token', 'refresh_token', 'token_expires_at', 'needs_reauth'];

    public const PENDING_FIELDS = ['reason', 'status', 'attempts', 'next_attempt_at'];

    public const CUSTOMER_FIELDS = ['name', 'email', 'phone', 'notes'];

    public ?SyncLedger $memory = null;

    /** Marks a lease whose row was flagged again while the holding run was sending it. */
    private const REFLAG_PREFIX = 'reflag:';

    /** How long a company without a connection is remembered by flag(). */
    public const UNTRACKED_SECONDS = 30;

    /** @var \WeakMap<object, bool>|null */
    private static ?\WeakMap $claimSupport = null;

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
     * Rows as loadLedger() read them, so save() writes only what the engine changed.
     *
     * @var array{connections: array<string, array<string, mixed>>, pending: array<string, array<string, mixed>>, customers: array<string, array<string, mixed>>, invoices: array<string, array<string, mixed>>, wallets: array<string, array<string, mixed>>, links: array<string, array<string, mixed>>}
     */
    private array $loaded = ['connections' => [], 'pending' => [], 'customers' => [], 'invoices' => [], 'wallets' => [], 'links' => []];

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
        $database = (new Link())->getConnection();
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
    private function writeLinks(SyncLedger $ledger, array $skipped): void
    {
        $loadedByUuid     = $this->loadedLinksByUuid();
        $linkFields       = ['company_uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_entity', 'qbo_id', 'sync_token'];
        $freshLinks       = [];
        $changedLinks     = [];
        $identityReleases = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false || isset($skipped[(string) ($link['company_uuid'] ?? '')]) === true) {
                continue;
            }
            $key     = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
            $loaded  = $this->loadedLink($key, $link, $loadedByUuid);
            $changed = self::changedColumns($link, is_array($loaded) === true ? $loaded : null, $linkFields);
            if ($changed === [] && is_array($loaded) === true) {
                continue;
            }
            if (is_array($loaded) === true) {
                $uuid = (string) ($loaded['uuid'] ?? '');
                if (isset($changed['local_uuid']) === true || isset($changed['local_type']) === true) {
                    // The new local key may already be stored. Free it before this row moves,
                    // or the unique local identity rejects the update and insertOrIgnore keeps the old key.
                    $identityReleases[] = ['link' => $link, 'keep' => $uuid];
                }
                if ($uuid === '') {
                    $this->upsertLinkByLocalKey($link);
                    continue;
                }
                $changedLinks[] = ['uuid' => $uuid, 'columns' => $changed];
                continue;
            }
            $freshLinks[] = $link;
        }
        $inserted = $this->insertLinks($freshLinks);
        $this->releaseLinkIdentities(array_merge($identityReleases, $inserted['releases']));
        $this->updateByUuid(new Link(), $changedLinks, $linkFields);
        $this->updateByUuid(new Link(), $inserted['updates'], $linkFields);
        $this->dropStaleInvoicePaymentLinks($ledger, $skipped);
        $this->rememberPaymentInvoices($ledger, $skipped);
    }

    /**
     * The links loaded for this batch, keyed by their uuid.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadedLinksByUuid(): array
    {
        $loadedByUuid = [];
        foreach ($this->loaded['links'] as $loadedLink) {
            if (is_array($loadedLink) === false) {
                continue;
            }
            $loadedUuid = (string) ($loadedLink['uuid'] ?? '');
            if ($loadedUuid !== '') {
                $loadedByUuid[$loadedUuid] = $loadedLink;
            }
        }

        return $loadedByUuid;
    }

    /**
     * Saves a link that was loaded without a uuid, matched on its local identity.
     *
     * @param array<string, mixed> $link
     */
    private function upsertLinkByLocalKey(array $link): void
    {
        $payload = $link;
        unset($payload['invoice_uuid']);
        Link::query()->updateOrCreate(
            [
                'company_uuid' => $link['company_uuid'],
                'local_type'   => $link['local_type'],
                'local_uuid'   => $link['local_uuid'],
            ],
            $payload
        );
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
        $this->insertBatchAttempts($ledger);
        $this->persistCustomers($ledger, $skipped);
        $this->persistInvoices($ledger, $skipped);
        $this->persistWallets($ledger, $skipped);
    }

    /**
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $changedPending
     */
    private function updatePendingRows(array $changedPending): void
    {
        $pendingColumns = ['company_uuid', 'local_type', 'local_uuid', 'reason', 'status', 'attempts', 'next_attempt_at'];
        if ($this->claimsSupported() === true) {
            // A row flagged again during this run stays pending for the next run, but still records
            // the attempt and backoff, so a row that keeps failing cannot skip its backoff. An
            // expired re-flag mark (its run died) no longer holds the row.
            $now      = Carbon::now()->toDateTimeString();
            $reflag   = self::REFLAG_PREFIX . '%';
            $this->updateByUuid(new PendingSync(), $changedPending, $pendingColumns, [
                '(claimed_by IS NULL OR claimed_by NOT LIKE ? OR claimed_until IS NULL OR claimed_until <= ?)',
                [$reflag, $now],
            ]);
            $this->updateByUuid(new PendingSync(), $changedPending, ['attempts', 'next_attempt_at'], [
                'claimed_by LIKE ? AND claimed_until > ?',
                [$reflag, $now],
            ]);
        } else {
            $this->updateByUuid(new PendingSync(), $changedPending, $pendingColumns);
        }
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
        $note  = 'QuickBooks invoice number ' . $number . ' was not copied because another Fleetbase invoice already uses it.'
            . ($kept === '' ? '' : ' This invoice keeps ' . $kept . '.');
        $noted = false;
        foreach ($ledger->attempts as $index => $attempt) {
            if (empty($attempt['uuid']) === false
                || (string) ($attempt['local_type'] ?? '') !== 'invoice' || (string) ($attempt['local_uuid'] ?? '') !== $uuid) {
                continue;
            }
            $existing                          = trim((string) ($attempt['error'] ?? ''));
            $ledger->attempts[$index]['error'] = $existing === '' ? $note : $existing . ' ' . $note;
            $noted                             = true;
        }
        if ($noted === false) {
            Log::warning('QuickBooks invoice number was not copied.', ['company_uuid' => $companyUuid, 'invoice_uuid' => $uuid, 'note' => $note]);
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
     * Flag a record by writing only its pending row. The callback sees a ledger holding
     * just the company's connection; the full ledger is never loaded or saved here.
     */
    public function flag(callable $callback, string $companyUuid): void
    {
        if ($this->memory !== null) {
            $callback($this->memory);

            return;
        }

        $connection = $this->trackedConnection($companyUuid);
        if ($connection === null) {
            return;
        }

        $ledger                            = new SyncLedger();
        $ledger->connections[$companyUuid] = $connection;
        $callback($ledger);
        foreach ($ledger->pending as $row) {
            $this->writePending($row);
        }
    }

    /**
     * Whether saves for this organization should be flagged at all: it has a connection
     * with a realm. The observers on Fleetbase's own models ask this before any other work.
     */
    public function tracks(string $companyUuid): bool
    {
        return $this->memory !== null || $this->trackedConnection($companyUuid) !== null;
    }

    /**
     * The company's connection when it has a realm. A company without one is remembered
     * for UNTRACKED_SECONDS, so the many saves of an organization that does not use
     * QuickBooks cost one lookup now and then, not one per save. A company that connects
     * is picked up within that time, and connecting queues its existing records anyway.
     *
     * @return array<string, mixed>|null
     */
    private function trackedConnection(string $companyUuid): ?array
    {
        $now = time();
        if (($this->untracked[$companyUuid] ?? 0) > $now) {
            return null;
        }

        $connection = $this->connection($companyUuid);
        if (ConnectionGate::hasRealm($connection) === false) {
            $this->untracked[$companyUuid] = $now + self::UNTRACKED_SECONDS;

            return null;
        }
        unset($this->untracked[$companyUuid]);

        return $connection;
    }

    /**
     * The connection owned by this organization. Another organization's row is not used.
     *
     * @return array<string, mixed>|null
     */
    public function connection(string $companyUuid): ?array
    {
        $own = $this->storedConnection($companyUuid);
        if ($own === null) {
            return null;
        }

        return $this->withEntityCompany($own, $companyUuid);
    }

    /**
     * The connection this company syncs with. A company without its own row
     * does not use another organization's connection.
     *
     * @return array<string, mixed>|null
     */
    private function connectionForLedger(SyncLedger $ledger, string $companyUuid): ?array
    {
        $connection = $ledger->connection($companyUuid);
        if (is_array($connection) === false) {
            return null;
        }

        $connection                        = $this->withEntityCompany($connection, $companyUuid);
        $ledger->connections[$companyUuid] = $connection;

        return $connection;
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

    /**
     * @return array<string, mixed>|null
     */
    private function storedConnection(string $companyUuid): ?array
    {
        if ($this->memory !== null) {
            return $this->memory->connection($companyUuid);
        }

        $connection = (new Connection())->newQuery()->where('company_uuid', $companyUuid)->first();

        return $connection instanceof Connection ? self::connectionToArray($connection) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionToArray(Connection $connection): array
    {
        return self::toTimestamps($connection->toArray(), self::CONNECTION_TIMES);
    }

    /**
     * Record a skipped batch without loading or saving the rest of the ledger.
     */
    public function saveSkipped(string $companyUuid, string $trigger, string $direction, string $message): void
    {
        $ledger = $this->memory ?? new SyncLedger();
        $ledger->skip($companyUuid, $trigger, $direction, $message, time());
        $this->save($ledger);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $fields
     *
     * @return array<string, mixed>
     */
    public static function toTimestamps(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) === true) {
                $row[$field] = self::timestamp($row[$field]);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $fields
     *
     * @return array<string, mixed>
     */
    public static function toDates(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) === true && $row[$field] !== null) {
                $row[$field] = Carbon::createFromTimestamp((int) self::timestamp($row[$field]));
            }
        }

        return $row;
    }

    public static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value) === true) {
            return (int) $value;
        }
        $time = strtotime((string) $value);

        return $time === false ? null : $time;
    }

    /**
     * Columns that differ on a pending row the batch already loaded.
     * A missing row is not inserted, so a uuid from an earlier load cannot
     * collide with a row created since then.
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $loaded
     *
     * @return array<string, mixed>
     */
    private function pendingUpdateColumns(array $row, ?array $loaded): array
    {
        $uuid = (string) ($row['uuid'] ?? '');
        if ($uuid === '') {
            return [];
        }

        $columns = [];
        foreach (self::PENDING_FIELDS as $field) {
            if (array_key_exists($field, $row) === false) {
                continue;
            }
            if ($loaded !== null && ($loaded[$field] ?? null) === $row[$field]) {
                continue;
            }
            $columns[$field] = $row[$field];
        }
        $companyUuid = trim((string) ($row['company_uuid'] ?? ''));
        if ($companyUuid !== '' && ($loaded === null || (string) ($loaded['company_uuid'] ?? '') !== $companyUuid)) {
            $columns['company_uuid'] = $companyUuid;
        }
        if ($columns === []) {
            return [];
        }

        return self::toDates($columns, self::PENDING_TIMES);
    }

    public function hasDuePending(string $companyUuid, int $now): bool
    {
        return $this->countDuePending($companyUuid, $now) > 0;
    }

    /**
     * Due pending rows for one company. One count, without loading the ledger.
     */
    public function countDuePending(string $companyUuid, int $now): int
    {
        if ($this->memory !== null) {
            $count = 0;
            foreach ($this->memory->pending as $row) {
                if (($row['company_uuid'] ?? '') !== $companyUuid || ($row['status'] ?? '') !== 'pending') {
                    continue;
                }
                $next = $row['next_attempt_at'] ?? null;
                if ($next === null || (int) $next <= $now) {
                    $count++;
                }
            }

            return $count;
        }

        $query = PendingSync::query()
            ->where('company_uuid', $companyUuid)
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Carbon::createFromTimestamp($now));
            });
        // A row another run holds is not due work for this one.
        if ($this->claimsSupported() === true) {
            $this->excludeClaimed($query, Carbon::createFromTimestamp($now));
        }

        return (int) $query->count();
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function writePending(array $row): void
    {
        try {
            $pending = PendingSync::query()->firstOrCreate(
                [
                    'company_uuid' => $row['company_uuid'],
                    'local_type'   => $row['local_type'],
                    'local_uuid'   => $row['local_uuid'],
                    'status'       => 'pending',
                ],
                self::toDates($row, self::PENDING_TIMES)
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            if (self::isDuplicatePendingWrite($exception) === false) {
                throw $exception;
            }

            return;
        }
        if ($pending instanceof PendingSync === true && $pending->wasRecentlyCreated === false) {
            $this->markReflagged($pending);
        }
    }

    /**
     * A running sync holds this row and may already have read the record. Mark the lease
     * so that run leaves the row pending instead of marking it done; the lease itself is
     * unchanged, so no other run takes the row meanwhile.
     */
    private function markReflagged(PendingSync $pending): void
    {
        $holder = (string) ($pending->claimed_by ?? '');
        if ($holder === '' || str_starts_with($holder, self::REFLAG_PREFIX) === true || $this->claimsSupported() === false) {
            return;
        }

        PendingSync::query()
            ->where('uuid', (string) $pending->uuid)
            ->where('claimed_by', $holder)
            ->where('claimed_until', '>', Carbon::now())
            ->update(['claimed_by' => self::REFLAG_PREFIX . $holder]);
    }

    /**
     * Only an open-pending identity race is harmless. SQLSTATE 23000 also
     * covers foreign-key, not-null, and check failures on MySQL and SQLite.
     */
    private static function isDuplicatePendingWrite(\Illuminate\Database\QueryException $exception): bool
    {
        $state      = strtoupper((string) ($exception->errorInfo[0] ?? ''));
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message    = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if ($state === '23505') {
            return true;
        }

        if ($state !== '23000') {
            return false;
        }

        if ($driverCode === 1062) {
            return true;
        }

        return in_array($driverCode, [19, 1555, 2067], true) === true
            && preg_match('/(?:UNIQUE|PRIMARY KEY) constraint failed/i', $message) === 1;
    }

    /**
     * Whether quickbooks_pending_syncs has the lease columns. A deploy that has not run
     * the migration yet keeps working, unclaimed. Only a positive answer is remembered,
     * per connection object, so a purged connection is asked again.
     */
    private function claimsSupported(): bool
    {
        $model              = new PendingSync();
        $connection         = $model->getConnection();
        $supported          = self::$claimSupport ?? new \WeakMap();
        self::$claimSupport = $supported;
        if (($supported[$connection] ?? false) === true) {
            return true;
        }

        try {
            $schema = $connection->getSchemaBuilder();
            $has    = $schema->hasColumn($model->getTable(), 'claimed_until') === true && $schema->hasColumn($model->getTable(), 'claimed_by') === true;
        } catch (\Throwable) {
            return false;
        }
        if ($has === true) {
            $supported[$connection] = true;
        }

        return $has;
    }

    private function claimSeconds(): int
    {
        return max(60, (int) config('quickbooks.sync.claim_seconds', 900));
    }

    /**
     * Rows with no lease, or a lease that has run out.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     */
    private function excludeClaimed($query, Carbon $moment): void
    {
        $query->where(function ($claim) use ($moment): void {
            $claim->whereNull('claimed_until')->orWhere('claimed_until', '<=', $moment);
        });
    }

    /**
     * Lease the loaded rows to this run. The update repeats the lease check, so a row
     * another run took since the read is not taken twice; only rows this run really
     * holds are returned. The rows stay status 'pending', so the open-identity index
     * and the flag upsert behave as before.
     *
     * @param iterable<mixed> $rows
     *
     * @return array<int, mixed>
     */
    private function claimPending(iterable $rows, int $now): array
    {
        $rows  = is_array($rows) === true ? $rows : iterator_to_array($rows, false);
        $uuids = [];
        foreach ($rows as $row) {
            if ($row instanceof PendingSync) {
                $uuids[] = (string) $row->uuid;
            }
        }
        if ($uuids === []) {
            return $rows;
        }

        $token  = (string) Str::uuid();
        $moment = Carbon::createFromTimestamp($now);
        $until  = Carbon::createFromTimestamp($now + $this->claimSeconds());
        foreach (array_chunk($uuids, 500) as $chunk) {
            $query = PendingSync::query()->whereIn('uuid', $chunk)->where('status', 'pending');
            $this->excludeClaimed($query, $moment);
            $query->update(['claimed_by' => $token, 'claimed_until' => $until]);
        }
        $this->claimTokens[] = $token;

        $mine = array_flip(array_map(static fn (mixed $uuid): string => (string) $uuid, PendingSync::query()->where('claimed_by', $token)->pluck('uuid')->all()));

        return array_values(array_filter(
            $rows,
            static fn ($row): bool => $row instanceof PendingSync && isset($mine[(string) $row->uuid]) === true
        ));
    }

    /**
     * Give back the rows this directory leased. Call it after the batch is saved; a
     * lease that could not be given back expires on its own after claim_seconds.
     */
    public function releaseClaims(): void
    {
        $tokens            = $this->claimTokens;
        $this->claimTokens = [];
        if ($tokens === [] || $this->memory !== null) {
            return;
        }

        try {
            $held = $tokens;
            foreach ($tokens as $token) {
                $held[] = self::REFLAG_PREFIX . $token;
            }
            PendingSync::query()->whereIn('claimed_by', $held)->update(['claimed_by' => null, 'claimed_until' => null]);
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks could not release its pending-row lease; it will expire on its own.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Local ids of the given type whose pending row another run holds right now.
     * The customer catalog leaves these to that run.
     *
     * @param array<int, string> $uuids
     *
     * @return array<int, string>
     */
    public function claimedLocalUuids(string $companyUuid, string $localType, array $uuids, int $now): array
    {
        if ($uuids === [] || $this->memory !== null || $this->claimsSupported() === false) {
            return [];
        }

        $held = [];
        foreach (array_chunk($uuids, 500) as $chunk) {
            $rows = PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('status', 'pending')
                ->whereIn('local_uuid', $chunk)
                ->where('claimed_until', '>', Carbon::createFromTimestamp($now))
                ->pluck('local_uuid');
            foreach ($rows as $uuid) {
                $held[] = (string) $uuid;
            }
        }

        return $held;
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
            $this->loadFullLedger($ledger, $companyUuid, $flaggedInvoices);
        } else {
            $this->loadLimitedLedger($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds, $flaggedInvoices);
        }
        $ledger->rebuildIndex();
        $this->loaded['customers'] = $ledger->customers;
        $this->loaded['invoices']  = $ledger->invoices;
        $this->loaded['wallets']   = $ledger->wallets;

        return $ledger;
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
     */
    private function loadFullLedger(SyncLedger $ledger, string $companyUuid, array $flaggedInvoices): void
    {
        foreach ((new Link())->newQuery()->where('company_uuid', $companyUuid)->get() as $link) {
            if ($link instanceof Link) {
                $this->rememberLoadedLink($ledger, $link->toArray());
            }
        }
        $ledger->customers = $this->readCustomers($companyUuid);
        $ledger->invoices  = $this->readInvoices($companyUuid, null, $flaggedInvoices);
        $ledger->wallets   = $this->readWallets($companyUuid);
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
        foreach ($ledger->invoices as $invoice) {
            $customerId = (string) ($invoice['customer_uuid'] ?? '');
            if ($customerId !== '') {
                $customerIds[] = $customerId;
            }
        }
        $customerIds       = array_values(array_unique($customerIds));
        $ledger->customers = $this->readCustomers($companyUuid, $customerIds);
        $ledger->wallets   = $this->readWallets($companyUuid, $walletIds);
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
        })->orderBy('next_attempt_at')->orderBy('uuid')->limit($pendingLimit);
        if ($this->claimsSupported() === false) {
            return $pendingQuery->get();
        }
        $this->excludeClaimed($pendingQuery, $moment);

        return $this->claimPending($pendingQuery->get(), $now ?? time());
    }

    /**
     * @param array<int, array{entity?: mixed, id?: mixed, operation?: mixed}> $entities
     */
    private function linkedLedger(string $companyUuid, array $entities): SyncLedger
    {
        $ledger     = new SyncLedger();
        $connection = $this->connection($companyUuid);
        if ($connection === null) {
            return $ledger;
        }

        $ledger->connections[$companyUuid] = $connection;
        $this->loaded['pending']           = [];
        $this->loaded['links']             = [];
        $idsByEntity                       = $this->remoteIdsByEntity($entities);
        $realm                             = (string) ($connection['realm_id'] ?? '');
        $matched                           = $this->memory !== null
            ? $this->memoryLinksForRemoteIds($companyUuid, $realm, $idsByEntity)
            : $this->linksForRemoteIds($companyUuid, $realm, $idsByEntity);
        [$customerIds, $invoiceIds, $walletIds] = $this->localIdsFromLinks($matched);
        $this->attachLinkedRows($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);
        $ledger->rebuildIndex();
        $this->loaded['customers'] = $ledger->customers;
        $this->loaded['invoices']  = $ledger->invoices;
        $this->loaded['wallets']   = $ledger->wallets;

        return $ledger;
    }

    /**
     * QuickBooks entity name to the ids named by this delivery.
     *
     * @param array<int, mixed> $entities
     *
     * @return array<string, array<string, true>>
     */
    private function remoteIdsByEntity(array $entities): array
    {
        $known = [
            'Customer' => true,
            'Invoice'  => true,
            'Payment'  => true,
            'Account'  => true,
        ];
        $ids = [];
        foreach ($entities as $entity) {
            if (is_array($entity) === false) {
                continue;
            }
            $name = $entity['entity'] ?? null;
            if (is_string($name) === false || isset($known[$name]) === false) {
                continue;
            }
            $id = $entity['id'] ?? null;
            if (is_int($id) === true) {
                $id = (string) $id;
            }
            if (is_string($id) === false || $id === '') {
                continue;
            }
            $ids[$name][$id] = true;
        }

        return $ids;
    }

    /**
     * @param array<string, array<string, true>> $idsByEntity
     *
     * @return array<int, array<string, mixed>>
     */
    private function linksForRemoteIds(string $companyUuid, string $realm, array $idsByEntity): array
    {
        if ($idsByEntity === []) {
            return [];
        }

        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid);
        if ($realm !== '') {
            $query->where('realm_id', $realm);
        }
        $query->where(function ($scope) use ($idsByEntity): void {
            $started = false;
            foreach ($idsByEntity as $entity => $ids) {
                $idList = array_keys($ids);
                $method = $started === true ? 'orWhere' : 'where';
                $scope->{$method}(function ($inner) use ($entity, $idList): void {
                    $inner->where('qbo_entity', $entity)->whereIn('qbo_id', $idList);
                });
                $started = true;
            }
        });

        $rows = [];
        foreach ($query->get() as $link) {
            if ($link instanceof Link) {
                $rows[] = $link->toArray();
            }
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, true>> $idsByEntity
     *
     * @return array<int, array<string, mixed>>
     */
    private function memoryLinksForRemoteIds(string $companyUuid, string $realm, array $idsByEntity): array
    {
        if ($this->memory === null || $idsByEntity === []) {
            return [];
        }

        $rows = [];
        foreach ($this->memory->links as $link) {
            if (is_array($link) === false || (string) ($link['company_uuid'] ?? '') !== $companyUuid) {
                continue;
            }
            if ($realm !== '' && (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            $entity = (string) ($link['qbo_entity'] ?? '');
            $id     = (string) ($link['qbo_id'] ?? '');
            if (isset($idsByEntity[$entity][$id]) === false) {
                continue;
            }
            $rows[] = $link;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $links
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    private function localIdsFromLinks(array $links): array
    {
        $customerIds = [];
        $invoiceIds  = [];
        $walletIds   = [];
        foreach ($links as $link) {
            $uuid = (string) ($link['local_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $type = (string) ($link['local_type'] ?? '');
            if ($type === 'customer') {
                $customerIds[] = $uuid;
            } elseif ($type === 'wallet') {
                $walletIds[] = $uuid;
            } elseif ($type === 'invoice' || $type === 'payment') {
                $invoiceIds[] = $uuid;
            }
        }

        return [$customerIds, $invoiceIds, $walletIds];
    }

    /**
     * Invoices for the linked ids, their customers, and the links for those rows.
     * A pending batch loads this same neighborhood once it knows the local ids.
     *
     * @param array<int, string> $customerIds
     * @param array<int, string> $invoiceIds
     * @param array<int, string> $walletIds
     */
    private function attachLinkedRows(SyncLedger $ledger, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds): void
    {
        $invoiceIds = array_values(array_unique(array_filter($invoiceIds, static fn (string $id): bool => $id !== '')));
        $walletIds  = array_values(array_unique(array_filter($walletIds, static fn (string $id): bool => $id !== '')));
        if ($this->memory !== null) {
            foreach ($invoiceIds as $uuid) {
                $invoice = $this->memory->invoices[$uuid] ?? null;
                if (is_array($invoice) === true) {
                    $ledger->invoices[$uuid] = $invoice;
                }
            }
            $customerIds = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
            foreach ($customerIds as $uuid) {
                $customer = $this->memory->customers[$uuid] ?? null;
                if (is_array($customer) === true) {
                    $ledger->customers[$uuid] = $customer;
                }
            }
            foreach ($walletIds as $uuid) {
                $wallet = $this->memory->wallets[$uuid] ?? null;
                if (is_array($wallet) === true) {
                    $ledger->wallets[$uuid] = $wallet;
                }
            }
            $paymentIds = $this->paymentIdsRememberedFor($this->memory->links, $companyUuid, $invoiceIds);
            foreach ($this->memory->links as $link) {
                if (is_array($link) === false || $this->linkMatchesLocalIds($link, $companyUuid, $customerIds, $invoiceIds, $walletIds, $paymentIds) === false) {
                    continue;
                }
                $this->rememberLoadedLink($ledger, $link);
            }

            return;
        }

        $ledger->invoices  = $this->readInvoices($companyUuid, $invoiceIds, $invoiceIds);
        $customerIds       = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
        $ledger->customers = $this->readCustomers($companyUuid, $customerIds);
        $ledger->wallets   = $this->readWallets($companyUuid, $walletIds);
        $this->readLinksFor($ledger, $companyUuid, $customerIds, $invoiceIds, $walletIds);
    }

    /**
     * @param array<string, array<string, mixed>> $invoices
     * @param array<int, string>                  $customerIds
     *
     * @return array<int, string>
     */
    private function withInvoiceCustomers(array $invoices, array $customerIds): array
    {
        foreach ($invoices as $invoice) {
            $customerId = (string) ($invoice['customer_uuid'] ?? '');
            if ($customerId !== '') {
                $customerIds[] = $customerId;
            }
        }

        return array_values(array_unique(array_filter($customerIds, static fn (string $id): bool => $id !== '')));
    }

    /**
     * @param array<string, mixed> $link
     * @param array<int, string>   $customerIds
     * @param array<int, string>   $invoiceIds
     * @param array<int, string>   $walletIds
     * @param array<int, string>   $paymentIds  QuickBooks payment ids remembered for $invoiceIds
     */
    private function linkMatchesLocalIds(array $link, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds, array $paymentIds = []): bool
    {
        if ((string) ($link['company_uuid'] ?? '') !== $companyUuid) {
            return false;
        }

        $type = (string) ($link['local_type'] ?? '');
        $uuid = (string) ($link['local_uuid'] ?? '');
        if ($type === 'customer' && in_array($uuid, $customerIds, true) === true) {
            return true;
        }
        if ($type === 'wallet' && in_array($uuid, $walletIds, true) === true) {
            return true;
        }
        if ($type === 'payment-invoice' && in_array(trim((string) ($link['qbo_id'] ?? '')), $invoiceIds, true) === true) {
            return true;
        }
        if ($type === 'payment' && $paymentIds !== [] && in_array($uuid, $paymentIds, true) === true) {
            return true;
        }

        return ($type === 'invoice' || $type === 'payment') && in_array($uuid, $invoiceIds, true) === true;
    }

    /**
     * payment-invoice.local_uuid is the QuickBooks payment id and qbo_id is the Fleetbase invoice.
     *
     * @param array<int, array<string, mixed>> $links
     * @param array<int, string>               $invoiceIds
     *
     * @return array<int, string>
     */
    private function paymentIdsRememberedFor(array $links, string $companyUuid, array $invoiceIds): array
    {
        if ($invoiceIds === [] || $links === []) {
            return [];
        }

        $wanted = array_fill_keys($invoiceIds, true);
        $ids    = [];
        foreach ($links as $link) {
            if (is_array($link) === false || (string) ($link['company_uuid'] ?? '') !== $companyUuid) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment-invoice') {
                continue;
            }
            $invoiceUuid = trim((string) ($link['qbo_id'] ?? ''));
            $paymentId   = trim((string) ($link['local_uuid'] ?? ''));
            if ($paymentId === '' || $invoiceUuid === '' || isset($wanted[$invoiceUuid]) === false) {
                continue;
            }
            $ids[] = $paymentId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $link
     */
    private function rememberLoadedLink(SyncLedger $ledger, array $link): void
    {
        $ledger->links[]             = $link;
        $key                         = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
        $this->loaded['links'][$key] = $link;
    }

    /**
     * Persist a connection right away, e.g. after its tokens were rotated.
     *
     * @param array<string, mixed> $connection
     */
    public function saveConnection(array $connection): void
    {
        if ($this->memory !== null) {
            $this->memory->connections[(string) ($connection['company_uuid'] ?? '')] = $connection;

            return;
        }

        $this->updateConnection($connection, self::CONNECTION_TOKEN_FIELDS);
    }

    /**
     * Persist engine fields onto the connection that is stored now.
     * A sync that loaded an earlier row must not insert a deleted connection,
     * or write that row onto a realm the user has since reconnected.
     *
     * @param array<string, mixed> $connection
     */
    private function writeConnection(array $connection): void
    {
        $this->updateConnection($connection, self::CONNECTION_ENGINE_FIELDS);
    }

    /**
     * Columns from $connection that $fields allows onto a stored connection.
     * Null when no row exists, or the stored realm is set and no longer matches.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $fields
     *
     * @return array<string, mixed>|null
     */
    public static function connectionColumns(array $connection, ?string $storedRealm, array $fields): ?array
    {
        if ($storedRealm === null) {
            return null;
        }
        if ($storedRealm !== '' && $storedRealm !== (string) ($connection['realm_id'] ?? '')) {
            return null;
        }

        $columns = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $connection) === true) {
                $columns[$field] = $connection[$field];
            }
        }

        return $columns;
    }

    /**
     * A refused refresh must not write its old tokens over a rotation that already
     * saved, and must not mark that newer connection as needing reconnect.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    public static function reauthColumns(array $connection, array $columns, ?string $storedRefresh, ?string $storedAccess): array
    {
        if (empty($connection['needs_reauth']) === true) {
            return $columns;
        }

        unset($columns['access_token'], $columns['refresh_token'], $columns['token_expires_at']);
        $sameRefresh = (string) $storedRefresh === (string) ($connection['refresh_token'] ?? '');
        $sameAccess  = (string) $storedAccess === (string) ($connection['access_token'] ?? '');
        if ($sameRefresh === false || $sameAccess === false) {
            unset($columns['needs_reauth']);
        }

        return $columns;
    }

    /**
     * Fields the engine changed since load. A missing snapshot writes every listed field.
     *
     * @param array<string, mixed>      $current
     * @param array<string, mixed>|null $loaded
     * @param array<int, string>        $fields
     *
     * @return array<string, mixed>
     */
    public static function changedColumns(array $current, ?array $loaded, array $fields): array
    {
        $columns = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $current) === false) {
                continue;
            }
            if (is_array($loaded) === true && ($loaded[$field] ?? null) === $current[$field]) {
                continue;
            }
            $columns[$field] = $current[$field];
        }

        return $columns;
    }

    /**
     * Whether rows loaded for a company at batch start may still be written.
     * A missing stored connection, or a non-empty realm that no longer matches
     * the ledger, means the company disconnected or reconnected during the batch.
     * An empty stored realm still matches, the same way connectionColumns() allows it.
     *
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed>|null $ledgerConnection
     */
    public static function connectionStillCurrent(?array $stored, ?array $ledgerConnection): bool
    {
        if ($stored === null) {
            return false;
        }

        $storedRealm = (string) ($stored['realm_id'] ?? '');

        return $storedRealm === '' || $storedRealm === (string) ($ledgerConnection['realm_id'] ?? '');
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $fields
     */
    private function updateConnection(array $connection, array $fields): void
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        if ($companyUuid === '') {
            return;
        }

        // Only this company's own row. When it is gone (disconnected mid-batch) nothing is
        // written; another organization's row on the same realm is never used.
        $existing = (new Connection())->newQuery()->where('company_uuid', $companyUuid)->first();
        if ($existing instanceof Connection === false) {
            SafeLog::debug('QuickBooks connection update skipped: the company has no connection.', ['company_uuid' => $companyUuid]);

            return;
        }
        $storedRealm = (string) ($existing->realm_id ?? '');
        $columns     = self::connectionColumns($connection, $storedRealm, $fields);
        if ($columns === null) {
            return;
        }

        $columns = self::reauthColumns(
            $connection,
            $columns,
            (string) ($existing->refresh_token ?? ''),
            (string) ($existing->access_token ?? '')
        );
        if ($columns !== []) {
            $existing->fill(self::toDates($columns, self::CONNECTION_TIMES));
            $existing->save();
        }

        if (empty($columns['needs_reauth']) === false) {
            $this->notifyReauth($connection);
        }
    }

    /**
     * Tell the company, at most once an hour, that QuickBooks must be reconnected.
     *
     * @param array<string, mixed> $connection
     */
    protected function notifyReauth(array $connection): void
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        $cacheKey    = 'quickbooks.reauth-notified.' . $companyUuid;
        if (Cache::has($cacheKey) === true) {
            return;
        }

        $recipients = $this->reauthRecipients($companyUuid);
        if ($recipients === []) {
            return;
        }

        Notification::send($recipients, new QuickbooksNeedsReauth($companyUuid, (string) ($connection['realm_id'] ?? '')));
        Cache::put($cacheKey, true, 3600);
    }

    /**
     * The company owner is the one recipient every company reliably has.
     *
     * @return array<int, User>
     */
    protected function reauthRecipients(string $companyUuid): array
    {
        if (class_exists(Company::class) === false) {
            return [];
        }

        $company = Company::query()->where('uuid', $companyUuid)->first();
        if ($company instanceof Company === false) {
            return [];
        }

        $owner = $company->owner()->getResults();

        return $owner instanceof User ? [$owner] : [];
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
     * @param array<int, string>|null $onlyUuids null loads every customer of the company
     *
     * @return array<string, array<string, mixed>>
     */
    private function readCustomers(string $companyUuid, ?array $onlyUuids = null): array
    {
        $class = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid)->where('type', 'customer');
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
        // This read copies line items only; those three relations stay unloaded.
        $query = $class::query()->where('company_uuid', $companyUuid)->withOnly('items');
        if ($onlyUuids !== null) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->whereIn('uuid', $onlyUuids);
        } elseif ($flaggedUuids !== []) {
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->where(function ($scope) use ($flaggedUuids): void {
                $scope->whereNull('deleted_at')->orWhereIn('uuid', $flaggedUuids);
            });
        }

        $rows = [];
        /** @var Model $invoice */
        foreach ($query->get() as $invoice) {
            $items = [];
            /** @var iterable<Model> $lines */
            $lines = $invoice->items;
            foreach ($lines as $item) {
                $items[] = [
                    'description' => $item->description,
                    'quantity'    => (int) $item->quantity,
                    'unit_price'  => (int) $item->unit_price,
                    'amount'      => (int) $item->amount,
                ];
            }
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
                'paid_at'       => $this->dateString($invoice->paid_at),
                'deleted_at'    => $invoice->deleted_at,
                'items'         => $items,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, string>|null $onlyUuids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readWallets(string $companyUuid, ?array $onlyUuids = null): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($class) === false || $onlyUuids === []) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid);
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
        }

        return $rows;
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
                $created = $this->updateExistingCustomer($class, $customer, $uuid, $values, $address);
                if ($created !== null) {
                    $fresh[] = $created;
                }
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
     *
     * @return array<string, mixed>|null
     */
    private function updateExistingCustomer(string $class, array $customer, string $uuid, array $values, ?array $address): ?array
    {
        if ($values === [] && $address === null) {
            return null;
        }
        /** @var Model|null $model */
        $model = $class::query()
            ->where('uuid', $uuid)
            ->where('company_uuid', $customer['company_uuid'])
            ->first();
        if ($model === null) {
            return $customer;
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

        return null;
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

        foreach ($ledger->invoices as $invoice) {
            $this->persistInvoice($class, $invoice, $skipped);
        }
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, true>  $skipped
     */
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
        if (empty($invoice['items_from_quickbooks']) === false && is_array($invoice['items'] ?? null) === true) {
            $this->replaceInvoiceLines($uuid, $invoice['items']);
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
        $after    = '';
        $pageSize = $this->catalogPageSize();
        while (true) {
            try {
                $query = (new Link())->newQuery()
                    ->where('company_uuid', $companyUuid)
                    ->where('local_type', 'invoice')
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
                $rows[] = $this->catalogPending($companyUuid, 'invoice', $uuid);
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
        }
        if (is_array($row) === false) {
            return $fallback;
        }
        $companyUuid = trim((string) ($row['company_uuid'] ?? ''));

        return $companyUuid !== '' ? $companyUuid : $fallback;
    }

    /**
     * A rekeyed payment keeps its link uuid and changes local_uuid from the invoice
     * to the QuickBooks payment id. insertOrIgnore of that same primary key would
     * leave the invoice-uuid row in place.
     *
     * @param array<string, mixed>                $link
     * @param array<string, array<string, mixed>> $loadedByUuid
     *
     * @return array<string, mixed>|null
     */
    private function loadedLink(string $key, array $link, array $loadedByUuid): ?array
    {
        $loaded = $this->loaded['links'][$key] ?? null;
        if (is_array($loaded) === true) {
            return $loaded;
        }
        $uuid = (string) ($link['uuid'] ?? '');
        if ($uuid === '' || isset($loadedByUuid[$uuid]) === false || is_array($loadedByUuid[$uuid]) === false) {
            return null;
        }

        return $loadedByUuid[$uuid];
    }

    /**
     * Delete every other row that already occupies one of these local identities.
     * One statement for the chunk, instead of a delete per payment.
     *
     * @param array<int, array{link: array<string, mixed>, keep: string}> $releases
     */
    private function releaseLinkIdentities(array $releases): void
    {
        $groups = [];
        foreach ($releases as $release) {
            $link    = $release['link'];
            $company = (string) ($link['company_uuid'] ?? '');
            $type    = (string) ($link['local_type'] ?? '');
            $local   = (string) ($link['local_uuid'] ?? '');
            if ($company === '' || $type === '' || $local === '') {
                continue;
            }
            $groups[] = [
                'company' => $company,
                'type'    => $type,
                'local'   => $local,
                'keep'    => (string) ($release['keep'] ?? ''),
            ];
        }
        if ($groups === []) {
            return;
        }

        foreach (array_chunk($groups, 200) as $chunk) {
            Link::query()->where(function ($query) use ($chunk): void {
                foreach ($chunk as $index => $group) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($inner) use ($group): void {
                        $inner->where('company_uuid', $group['company'])
                            ->where('local_type', $group['type'])
                            ->where('local_uuid', $group['local']);
                        if ($group['keep'] !== '') {
                            $inner->where('uuid', '!=', $group['keep']);
                        }
                    });
                }
            })->delete();
        }
    }

    /**
     * One QuickBooks payment id is stored under that id. An older row that still
     * uses the invoice uuid for the same payment is removed once the new key is stored.
     *
     * @param array<string, true> $skipped
     */
    private function dropStaleInvoicePaymentLinks(SyncLedger $ledger, array $skipped): void
    {
        $groups = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false || isset($skipped[(string) ($link['company_uuid'] ?? '')]) === true) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment') {
                continue;
            }
            $local   = (string) ($link['local_uuid'] ?? '');
            $qboId   = (string) ($link['qbo_id'] ?? '');
            $company = (string) ($link['company_uuid'] ?? '');
            $realm   = (string) ($link['realm_id'] ?? '');
            if ($local === '' || $qboId === '' || $local !== $qboId || $company === '' || $realm === '') {
                continue;
            }
            $groups[$company . '|' . $realm . '|' . $qboId] = [
                'company' => $company,
                'realm'   => $realm,
                'qbo_id'  => $qboId,
                'local'   => $local,
            ];
        }
        if ($groups === []) {
            return;
        }

        // The kept payment-id rows were inserted or updated above. One delete removes
        // every invoice-uuid row for those payments.
        foreach (array_chunk(array_values($groups), 200) as $chunk) {
            Link::query()->where(function ($query) use ($chunk): void {
                foreach ($chunk as $index => $group) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($inner) use ($group): void {
                        $inner->where('company_uuid', $group['company'])
                            ->where('realm_id', $group['realm'])
                            ->where('local_type', 'payment')
                            ->where('qbo_id', $group['qbo_id'])
                            ->where('local_uuid', '!=', $group['local']);
                    });
                }
            })->delete();
        }
    }

    /**
     * A payment stored under the QuickBooks payment id remembers the Fleetbase invoice.
     * A rekey still has that invoice on the older link. A first save whose line already
     * names the invoice carries invoice_uuid, because a later delete cannot read the payment.
     *
     * @param array<string, true> $skipped
     */
    private function rememberPaymentInvoices(SyncLedger $ledger, array $skipped): void
    {
        $rows = $this->paymentInvoiceRows($ledger, $skipped, $this->invoiceUuidByPaymentLink($skipped));
        if ($rows === []) {
            return;
        }
        $this->storePaymentInvoiceRows($rows);
    }

    /**
     * @param array<string, true> $skipped
     *
     * @return array<string, string>
     */
    private function invoiceUuidByPaymentLink(array $skipped): array
    {
        $invoiceByPayment = [];
        foreach ($this->loaded['links'] as $loaded) {
            $parts = $this->usablePaymentLink($loaded, $skipped, false);
            if ($parts === null) {
                continue;
            }
            [$local, $qboId, $company, $realm]                        = $parts;
            $invoiceByPayment[$company . '|' . $realm . '|' . $qboId] = $local;
        }

        return $invoiceByPayment;
    }

    /**
     * @param array<string, mixed>|mixed $link
     * @param array<string, true>        $skipped
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private function usablePaymentLink(mixed $link, array $skipped, bool $localMatchesRemote): ?array
    {
        if (is_array($link) === false || (string) ($link['local_type'] ?? '') !== 'payment') {
            return null;
        }
        $local   = (string) ($link['local_uuid'] ?? '');
        $qboId   = (string) ($link['qbo_id'] ?? '');
        $company = (string) ($link['company_uuid'] ?? '');
        $realm   = (string) ($link['realm_id'] ?? '');
        $same    = $local === $qboId;
        if ($local === '' || $qboId === '' || $same !== $localMatchesRemote || $company === '' || $realm === '' || isset($skipped[$company]) === true) {
            return null;
        }

        return [$local, $qboId, $company, $realm];
    }

    /**
     * @param array<string, true>   $skipped
     * @param array<string, string> $invoiceByPayment
     *
     * @return array<string, array{company_uuid: string, realm_id: string, local_uuid: string, qbo_id: string}>
     */
    private function paymentInvoiceRows(SyncLedger $ledger, array $skipped, array $invoiceByPayment): array
    {
        $rows = [];
        foreach ($ledger->links as $link) {
            $parts = $this->usablePaymentLink($link, $skipped, true);
            if ($parts === null) {
                continue;
            }
            [, $qboId, $company, $realm] = $parts;
            $invoice                     = trim((string) ($link['invoice_uuid'] ?? ''));
            if ($invoice === '' || $invoice === $qboId) {
                $invoice = $invoiceByPayment[$company . '|' . $realm . '|' . $qboId] ?? '';
            }
            if ($invoice === '' || $invoice === $qboId) {
                continue;
            }
            $rows[$company . '|' . $realm . '|' . $qboId] = [
                'company_uuid' => $company,
                'realm_id'     => $realm,
                'local_uuid'   => $qboId,
                'qbo_id'       => $invoice,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array{company_uuid: string, realm_id: string, local_uuid: string, qbo_id: string}> $rows
     */
    private function storePaymentInvoiceRows(array $rows): void
    {
        $now      = Carbon::now()->toDateTimeString();
        $existing = [];
        foreach (array_chunk(array_column($rows, 'local_uuid'), 200) as $chunk) {
            foreach (Link::query()->where('local_type', 'payment-invoice')->whereIn('local_uuid', $chunk)->get(['uuid', 'company_uuid', 'realm_id', 'local_uuid', 'qbo_id']) as $link) {
                if (is_object($link) === false) {
                    continue;
                }
                $existing[(string) $link->company_uuid . '|' . (string) $link->realm_id . '|' . (string) $link->local_uuid] = [
                    'uuid'   => (string) $link->uuid,
                    'qbo_id' => (string) $link->qbo_id,
                ];
            }
        }
        $inserts = [];
        $updates = [];
        foreach ($rows as $key => $row) {
            $stored = $existing[$key] ?? null;
            if (is_array($stored) === true) {
                if ($stored['qbo_id'] !== $row['qbo_id']) {
                    $updates[] = ['uuid' => $stored['uuid'], 'columns' => ['qbo_id' => $row['qbo_id']]];
                }
                continue;
            }
            $inserts[] = [
                'uuid'         => (string) Str::uuid(),
                'company_uuid' => $row['company_uuid'],
                'realm_id'     => $row['realm_id'],
                'local_type'   => 'payment-invoice',
                'local_uuid'   => $row['local_uuid'],
                'qbo_entity'   => 'PaymentInvoice',
                'qbo_id'       => $row['qbo_id'],
                'sync_token'   => '0',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        foreach (array_chunk($inserts, 200) as $chunk) {
            Link::query()->insert($chunk);
        }
        $this->updateByUuid(new Link(), $updates, ['qbo_id']);
    }

    /**
     * QuickBooks deleted or voided this record. The local invoice is voided and its
     * link dropped. A customer or wallet is retired so catalog and pending sync
     * cannot create the remote record again. A payment link is dropped, and a paid
     * invoice it pointed at is set so the next sync does not create a replacement payment.
     *
     * @param array<int, string> $relatedInvoiceUuids Invoices a payment-id link applied to
     */
    public function releaseRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid, array $relatedInvoiceUuids = []): void
    {
        if ($companyUuid === '' || $localType === '') {
            return;
        }
        if ($this->memory !== null) {
            $this->releaseMemoryRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $localUuid, $relatedInvoiceUuids);

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $uuids = $this->linkedLocalUuids($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
            if (is_string($localUuid) === true && $localUuid !== '' && in_array($localUuid, $uuids, true) === false) {
                $uuids[] = $localUuid;
            }
            foreach ($uuids as $uuid) {
                $this->applyRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $uuid, $relatedInvoiceUuids);
            }
            if ($uuids === [] && $localType === 'payment') {
                $this->applyRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, (string) $localUuid, $relatedInvoiceUuids);
            }
            $this->dropRemoteLinks($companyUuid, $realmId, $localType, $quickbooksId);
            if ($localType === 'payment' && $quickbooksId !== '') {
                $this->dropPaymentInvoiceMap($companyUuid, $realmId, $quickbooksId);
            }
        } finally {
            if ($wasPaused === false) {
                SyncSuppressor::resume();
            }
        }
    }

    /**
     * Retire every delete or void in one delivery. One link select, one invoice
     * status update, one link delete, and one pending update. A payment id is not
     * applied to an invoice, customer, or wallet that merely shares that id.
     *
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>, invoices_from_payment?: bool}> $deletions
     */
    public function releaseRemoteDeletes(string $companyUuid, array $deletions): void
    {
        if ($companyUuid === '' || $deletions === []) {
            return;
        }
        if ($this->memory !== null) {
            foreach ($deletions as $deletion) {
                if (is_array($deletion) === false) {
                    continue;
                }
                $this->releaseRemoteDelete(
                    $companyUuid,
                    (string) ($deletion['realm_id'] ?? ''),
                    (string) ($deletion['local_type'] ?? ''),
                    (string) ($deletion['qbo_id'] ?? ''),
                    isset($deletion['local_uuid']) === true ? (string) $deletion['local_uuid'] : null,
                    is_array($deletion['invoice_uuids'] ?? null) === true ? $deletion['invoice_uuids'] : []
                );
            }

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $this->retireDeleteBatch($companyUuid, $deletions);
        } finally {
            if ($wasPaused === false) {
                SyncSuppressor::resume();
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function linkedLocalUuids(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): array
    {
        if ($quickbooksId === '' && ($localUuid === null || $localUuid === '')) {
            return [];
        }

        try {
            $query = Link::query()->where('company_uuid', $companyUuid)->where('local_type', $localType);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->where(function ($inner) use ($quickbooksId, $localUuid): void {
                $started = false;
                if ($quickbooksId !== '') {
                    $inner->where('qbo_id', $quickbooksId);
                    $started = true;
                }
                if (is_string($localUuid) === true && $localUuid !== '') {
                    $method = $started === true ? 'orWhere' : 'where';
                    $inner->{$method}('local_uuid', $localUuid);
                }
            });
            $ids = [];
            foreach ($query->pluck('local_uuid') as $uuid) {
                $id = (string) $uuid;
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     */
    private function applyRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, string $localUuid, array $relatedInvoiceUuids = []): void
    {
        if ($localType === 'invoice') {
            $this->setInvoiceStatus($companyUuid, $localUuid, 'void');
            $this->dropLocalLinks($companyUuid, $realmId, 'invoice', $localUuid);
            $this->dropLocalLinks($companyUuid, $realmId, 'payment', $localUuid);
            $this->finishPending($companyUuid, 'invoice', $localUuid);

            return;
        }
        if ($localType === 'payment') {
            if ($localUuid !== '') {
                $this->dropLocalLinks($companyUuid, $realmId, 'payment', $localUuid);
            }
            foreach ($this->paymentInvoiceTargets($companyUuid, $realmId, $quickbooksId, $localUuid, $relatedInvoiceUuids) as $invoiceUuid) {
                $this->settleInvoiceAfterPaymentRemoved($companyUuid, $invoiceUuid);
            }

            return;
        }
        if ($localType === 'customer') {
            $this->retireLocal('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, $localUuid, []);
            $this->dropLocalLinks($companyUuid, $realmId, 'customer', $localUuid);
            $this->finishPending($companyUuid, 'customer', $localUuid);

            return;
        }
        if ($localType === 'wallet') {
            if ($this->walletsHoldingBalance($companyUuid, [$localUuid]) !== []) {
                $this->finishPending($companyUuid, 'wallet', $localUuid);

                return;
            }
            $this->retireLocal('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, $localUuid, ['status' => 'closed']);
            $this->dropLocalLinks($companyUuid, $realmId, 'wallet', $localUuid);
            $this->finishPending($companyUuid, 'wallet', $localUuid);
        }
    }

    /**
     * Invoice uuids a removed payment applied to. A link stored under the QuickBooks
     * payment id is not itself the invoice.
     *
     * @param array<int, string> $relatedInvoiceUuids
     *
     * @return array<int, string>
     */
    private function paymentInvoiceTargets(string $companyUuid, string $realmId, string $quickbooksId, string $localUuid, array $relatedInvoiceUuids): array
    {
        $targets = [];
        if ($localUuid !== '' && $localUuid !== $quickbooksId) {
            $targets[] = $localUuid;
        }
        if ($localUuid === '' || $localUuid === $quickbooksId) {
            foreach ($relatedInvoiceUuids as $invoiceUuid) {
                $invoiceUuid = (string) $invoiceUuid;
                if ($invoiceUuid !== '' && $invoiceUuid !== $quickbooksId) {
                    $targets[] = $invoiceUuid;
                }
            }
            foreach ($this->storedPaymentInvoices($companyUuid, $realmId, $quickbooksId) as $invoiceUuid) {
                $targets[] = $invoiceUuid;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @return array<int, string>
     */
    private function storedPaymentInvoices(string $companyUuid, string $realmId, string $paymentId): array
    {
        if ($companyUuid === '' || $paymentId === '' || $this->memory !== null) {
            return [];
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', 'payment-invoice')
                ->where('local_uuid', $paymentId);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $ids = [];
            foreach ($query->pluck('qbo_id') as $id) {
                $invoiceUuid = (string) $id;
                if ($invoiceUuid !== '' && $invoiceUuid !== $paymentId) {
                    $ids[] = $invoiceUuid;
                }
            }

            return array_values(array_unique($ids));
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    private function settleInvoiceAfterPaymentRemoved(string $companyUuid, string $invoiceUuid): void
    {
        $status = $this->invoiceStatus($companyUuid, $invoiceUuid);
        if ($status === null) {
            return;
        }
        if (in_array(strtolower($status), ['paid', 'partial'], true) === true) {
            $this->setInvoiceStatus($companyUuid, $invoiceUuid, 'sent');
        }
        $this->finishPending($companyUuid, 'invoice', $invoiceUuid);
    }

    private function dropPaymentInvoiceMap(string $companyUuid, string $realmId, string $paymentId): void
    {
        if ($companyUuid === '' || $paymentId === '') {
            return;
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', 'payment-invoice')
                ->where('local_uuid', $paymentId);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>, invoices_from_payment?: bool}> $deletions
     */
    private function retireDeleteBatch(string $companyUuid, array $deletions): void
    {
        $state = [
            'void_invoices'  => [],
            'sent_invoices'  => [],
            'customers'      => [],
            'wallets'        => [],
            'link_uuids'     => [],
            'wallet_links'   => [],
            'void_realms'    => [],
            'named_payments' => [],
        ];
        $this->collectDeletionRecords($deletions, $state);
        $this->collectDeletionLinks($this->linksForDeletions($companyUuid, $deletions), $deletions, $state);
        $this->applyDeletionBatch($companyUuid, $state);
    }

    /**
     * @param array<int, array<string, mixed>> $deletions
     * @param array<string, mixed>             $state
     */
    private function collectDeletionRecords(array $deletions, array &$state): void
    {
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false) {
                continue;
            }
            $type  = (string) ($deletion['local_type'] ?? '');
            $realm = (string) ($deletion['realm_id'] ?? '');
            $qbo   = (string) ($deletion['qbo_id'] ?? '');
            $local = (string) ($deletion['local_uuid'] ?? '');
            if ($type === 'invoice' && $local !== '') {
                $state['void_invoices'][]             = $local;
                $state['void_realms'][$realm][$local] = true;
            }
            if ($type === 'customer' && $local !== '') {
                $state['customers'][] = $local;
            }
            if ($type === 'wallet' && $local !== '') {
                $state['wallets'][] = $local;
            }
            if ($type === 'payment') {
                $this->collectPaymentDeletion($deletion, $realm, $qbo, $local, $state);
            }
        }
    }

    /**
     * @param array<string, mixed> $deletion
     * @param array<string, mixed> $state
     */
    private function collectPaymentDeletion(array $deletion, string $realm, string $qbo, string $local, array &$state): void
    {
        if (empty($deletion['invoices_from_payment']) === false && $qbo !== '') {
            // The payment was read. Its lines name every invoice to unmark.
            // A stored fallback for a different invoice must not be applied.
            $state['named_payments'][$realm . '|' . $qbo] = true;
        }
        if ($local !== '' && $local !== $qbo) {
            $state['sent_invoices'][] = $local;

            return;
        }
        $named = is_array($deletion['invoice_uuids'] ?? null) === true ? $deletion['invoice_uuids'] : [];
        foreach ($named as $invoiceUuid) {
            $invoiceUuid = (string) $invoiceUuid;
            if ($invoiceUuid !== '' && $invoiceUuid !== $qbo) {
                $state['sent_invoices'][] = $invoiceUuid;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>>                                                                                                         $links
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>}> $deletions
     * @param array<string, mixed>                                                                                                                     $state
     */
    private function collectDeletionLinks(array $links, array $deletions, array &$state): void
    {
        foreach ($links as $link) {
            $type  = (string) ($link['local_type'] ?? '');
            $local = (string) ($link['local_uuid'] ?? '');
            $qbo   = (string) ($link['qbo_id'] ?? '');
            $realm = (string) ($link['realm_id'] ?? '');
            $uuid  = (string) ($link['uuid'] ?? '');
            if ($type === 'payment-invoice') {
                $this->collectPaymentInvoiceLink($uuid, $realm, $local, $qbo, $state);
                continue;
            }
            if ($this->deletionMatchesLink($deletions, $type, $realm, $qbo, $local) === false) {
                continue;
            }
            $this->collectMatchedDeletionLink($type, $realm, $qbo, $local, $uuid, $state);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collectPaymentInvoiceLink(string $uuid, string $realm, string $local, string $qbo, array &$state): void
    {
        if ($uuid !== '') {
            $state['link_uuids'][] = $uuid;
        }
        if (isset($state['named_payments'][$realm . '|' . $local]) === false && $qbo !== '' && $qbo !== $local) {
            $state['sent_invoices'][] = $qbo;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collectMatchedDeletionLink(string $type, string $realm, string $qbo, string $local, string $uuid, array &$state): void
    {
        if ($uuid !== '' && $type === 'wallet' && $local !== '') {
            // Dropped below only when the wallet is really closed.
            $state['wallet_links'][$local][] = $uuid;
        } elseif ($uuid !== '') {
            $state['link_uuids'][] = $uuid;
        }
        if ($local === '') {
            return;
        }
        $this->recordDeletionTarget($type, $realm, $qbo, $local, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordDeletionTarget(string $type, string $realm, string $qbo, string $local, array &$state): void
    {
        if ($type === 'invoice') {
            $state['void_invoices'][]             = $local;
            $state['void_realms'][$realm][$local] = true;

            return;
        }
        if ($type === 'payment' && $local !== $qbo) {
            $state['sent_invoices'][] = $local;

            return;
        }
        if ($type === 'customer') {
            $state['customers'][] = $local;

            return;
        }
        if ($type === 'wallet') {
            $state['wallets'][] = $local;
        }
    }

    /**
     * @param array{
     *     void_invoices: array<int, string>,
     *     sent_invoices: array<int, string>,
     *     customers: array<int, string>,
     *     wallets: array<int, string>,
     *     link_uuids: array<int, string>,
     *     wallet_links: array<string, array<int, string>>,
     *     void_realms: array<string, array<string, true>>,
     *     named_payments: array<string, true>
     * } $state
     */
    private function applyDeletionBatch(string $companyUuid, array $state): void
    {
        $voidInvoices = array_values(array_unique($state['void_invoices']));
        $sentInvoices = array_values(array_unique(array_filter(
            $state['sent_invoices'],
            static fn (string $uuid): bool => $uuid !== '' && in_array($uuid, $voidInvoices, true) === false
        )));
        $customers = array_values(array_unique($state['customers']));
        // Every deleted wallet's pending row is finished, held or not: pushing a held wallet
        // would reactivate the account that was deleted in QuickBooks.
        $settled   = array_values(array_unique($state['wallets']));
        $wallets   = array_values(array_diff($settled, $this->walletsHoldingBalance($companyUuid, $settled)));
        $linkUuids = $state['link_uuids'];
        foreach ($wallets as $wallet) {
            foreach ($state['wallet_links'][$wallet] ?? [] as $walletLink) {
                $linkUuids[] = $walletLink;
            }
        }
        $linkUuids = array_values(array_unique($linkUuids));

        $this->updateInvoiceStatuses($companyUuid, $voidInvoices, $sentInvoices);
        $this->retireMany('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, $customers, []);
        $this->retireMany('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, $wallets, ['status' => 'closed']);
        $this->deleteRetiredLinks($companyUuid, $linkUuids, $state['void_realms']);
        $this->finishPendingMany($companyUuid, [
            'invoice'  => array_values(array_unique(array_merge($voidInvoices, $sentInvoices))),
            'customer' => $customers,
            'wallet'   => $settled,
        ]);
    }

    /**
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>}> $deletions
     */
    private function deletionMatchesLink(array $deletions, string $type, string $realm, string $qbo, string $local): bool
    {
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false || (string) ($deletion['local_type'] ?? '') !== $type) {
                continue;
            }
            $deletionRealm = (string) ($deletion['realm_id'] ?? '');
            if ($deletionRealm !== '' && $realm !== $deletionRealm) {
                continue;
            }
            $deletionQbo   = (string) ($deletion['qbo_id'] ?? '');
            $deletionLocal = (string) ($deletion['local_uuid'] ?? '');
            if ($deletionQbo !== '' && $qbo === $deletionQbo) {
                return true;
            }
            if ($deletionLocal !== '' && $local === $deletionLocal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>}> $deletions
     *
     * @return array<int, array<string, mixed>>
     */
    private function linksForDeletions(string $companyUuid, array $deletions): array
    {
        [$clauses, $paymentIds] = $this->deletionLookup($deletions);
        if ($clauses === [] && $paymentIds === []) {
            return [];
        }

        try {
            $links = $this->queryDeletionLinks($companyUuid, $clauses, $paymentIds);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }

        return $this->deletionLinkRows($links);
    }

    /**
     * @param array<int, array<string, mixed>> $deletions
     *
     * @return array{0: array<int, array{type: string, realm: string, qbo: string, local: string}>, 1: array<int, string>}
     */
    private function deletionLookup(array $deletions): array
    {
        $paymentIds = [];
        $clauses    = [];
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false) {
                continue;
            }
            $type  = (string) ($deletion['local_type'] ?? '');
            $realm = (string) ($deletion['realm_id'] ?? '');
            $qbo   = (string) ($deletion['qbo_id'] ?? '');
            $local = (string) ($deletion['local_uuid'] ?? '');
            if ($type === '' || ($qbo === '' && $local === '')) {
                continue;
            }
            if ($type === 'payment' && $qbo !== '') {
                $paymentIds[] = $qbo;
            }
            $clauses[] = ['type' => $type, 'realm' => $realm, 'qbo' => $qbo, 'local' => $local];
        }

        return [$clauses, array_values(array_unique($paymentIds))];
    }

    /**
     * @param array<int, array{type: string, realm: string, qbo: string, local: string}> $clauses
     * @param array<int, string>                                                         $paymentIds
     *
     * @return iterable<Model|\Illuminate\Database\Eloquent\Builder>
     */
    private function queryDeletionLinks(string $companyUuid, array $clauses, array $paymentIds): iterable
    {
        return Link::query()
            ->where('company_uuid', $companyUuid)
            ->where(function ($scope) use ($clauses, $paymentIds): void {
                $this->constrainDeletionScope($scope, $clauses, $paymentIds);
            })
            ->get(['uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_id']);
    }

    /**
     * @param array<int, array{type: string, realm: string, qbo: string, local: string}> $clauses
     * @param array<int, string>                                                         $paymentIds
     */
    private function constrainDeletionScope(\Illuminate\Database\Eloquent\Builder $scope, array $clauses, array $paymentIds): void
    {
        $started = false;
        foreach ($clauses as $clause) {
            $method = $started === true ? 'orWhere' : 'where';
            $scope->{$method}(function ($inner) use ($clause): void {
                $this->constrainDeletionClause($inner, $clause);
            });
            $started = true;
        }
        if ($paymentIds !== []) {
            $method = $started === true ? 'orWhere' : 'where';
            $scope->{$method}(function ($inner) use ($paymentIds): void {
                $inner->where('local_type', 'payment-invoice')->whereIn('local_uuid', $paymentIds);
            });
        }
    }

    /**
     * @param array{type: string, realm: string, qbo: string, local: string} $clause
     */
    private function constrainDeletionClause(\Illuminate\Database\Eloquent\Builder $inner, array $clause): void
    {
        $inner->where('local_type', $clause['type']);
        if ($clause['realm'] !== '') {
            $inner->where('realm_id', $clause['realm']);
        }
        $inner->where(function ($match) use ($clause): void {
            $this->constrainDeletionMatch($match, $clause);
        });
    }

    /**
     * @param array{type: string, realm: string, qbo: string, local: string} $clause
     */
    private function constrainDeletionMatch(\Illuminate\Database\Eloquent\Builder $match, array $clause): void
    {
        $open = false;
        if ($clause['qbo'] !== '') {
            $match->where('qbo_id', $clause['qbo']);
            $open = true;
        }
        if ($clause['local'] !== '') {
            $way = $open === true ? 'orWhere' : 'where';
            $match->{$way}('local_uuid', $clause['local']);
        }
    }

    /**
     * @param iterable<Model|\Illuminate\Database\Eloquent\Builder> $links
     *
     * @return array<int, array<string, mixed>>
     */
    private function deletionLinkRows(iterable $links): array
    {
        $rows = [];
        foreach ($links as $link) {
            if (is_object($link) === true) {
                $rows[] = [
                    'uuid'       => (string) $link->uuid,
                    'realm_id'   => (string) $link->realm_id,
                    'local_type' => (string) $link->local_type,
                    'local_uuid' => (string) $link->local_uuid,
                    'qbo_id'     => (string) $link->qbo_id,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     */
    private function updateInvoiceStatuses(string $companyUuid, array $voidUuids, array $sentUuids): void
    {
        if (($voidUuids === [] && $sentUuids === []) || $companyUuid === '') {
            return;
        }
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return;
        }

        try {
            $model      = new $class();
            $connection = $model->getConnection();
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
        $grammar                      = $connection->getQueryGrammar();
        [$cases, $filters, $bindings] = $this->invoiceStatusCases($grammar, $voidUuids, $sentUuids);
        $this->runInvoiceStatusUpdate($connection, $grammar, $model->getTable(), $companyUuid, $voidUuids, $sentUuids, $cases, $filters, $bindings);
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, mixed>}
     */
    private function invoiceStatusCases(\Illuminate\Database\Grammar $grammar, array $voidUuids, array $sentUuids): array
    {
        $uuidColumn = $grammar->wrap('uuid');
        $status     = $grammar->wrap('status');
        $cases      = [];
        $bindings   = [];
        $filters    = [];
        if ($voidUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($voidUuids), '?'));
            $cases[]      = 'WHEN ' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") NOT IN ('void', 'voided', 'cancelled', 'canceled') THEN 'void'";
            $filters[]    = '(' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") NOT IN ('void', 'voided', 'cancelled', 'canceled'))";
            foreach ($voidUuids as $uuid) {
                $bindings[] = $uuid;
            }
        }
        if ($sentUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($sentUuids), '?'));
            $cases[]      = 'WHEN ' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") IN ('paid', 'partial') THEN 'sent'";
            $filters[]    = '(' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") IN ('paid', 'partial'))";
            foreach ($sentUuids as $uuid) {
                $bindings[] = $uuid;
            }
        }

        return [$cases, $filters, $bindings];
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     * @param array<int, string> $cases
     * @param array<int, string> $filters
     * @param array<int, mixed>  $bindings
     */
    private function runInvoiceStatusUpdate(\Illuminate\Database\Connection $connection, \Illuminate\Database\Grammar $grammar, string $tableName, string $companyUuid, array $voidUuids, array $sentUuids, array $cases, array $filters, array $bindings): void
    {
        $table           = $grammar->wrapTable($tableName);
        $uuidColumn      = $grammar->wrap('uuid');
        $status          = $grammar->wrap('status');
        $whereBindings   = $bindings;
        $bindings[]      = Carbon::now()->toDateTimeString();
        $bindings[]      = $companyUuid;
        $all             = array_values(array_unique(array_merge($voidUuids, $sentUuids)));
        $allPlaceholders = implode(', ', array_fill(0, count($all), '?'));
        foreach ($all as $uuid) {
            $bindings[] = $uuid;
        }
        foreach ($whereBindings as $binding) {
            $bindings[] = $binding;
        }
        $sql = 'UPDATE ' . $table
            . ' SET ' . $status . ' = CASE ' . implode(' ', $cases) . ' ELSE ' . $status . ' END, '
            . $grammar->wrap('updated_at') . ' = ?'
            . ' WHERE ' . $grammar->wrap('company_uuid') . ' = ? AND ' . $grammar->wrap('deleted_at') . ' IS NULL'
            . ' AND ' . $uuidColumn . ' IN (' . $allPlaceholders . ')'
            . ' AND (' . implode(' OR ', $filters) . ')';
        try {
            $connection->update($sql, $bindings);
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Wallets that still hold money are not closed when QuickBooks deletes their account:
     * closing them would hide a balance. They stay open and linked, and the log says why.
     * When the balance cannot be read, every wallet is kept.
     *
     * @param array<int, string> $uuids
     *
     * @return array<int, string>
     */
    private function walletsHoldingBalance(string $companyUuid, array $uuids): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => $uuid !== ''));
        if ($uuids === [] || $companyUuid === '' || class_exists($class) === false) {
            return [];
        }

        try {
            $held = array_map(
                static fn (mixed $uuid): string => (string) $uuid,
                $class::query()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->where('balance', '!=', 0)->pluck('uuid')->all()
            );
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks deleted a wallet account, but the wallet balance could not be read, so the wallet stays open.', [
                'company_uuid' => $companyUuid,
                'wallet_uuids' => $uuids,
                'exception'    => $exception::class,
            ]);

            return $uuids;
        }
        if ($held !== []) {
            SafeLog::warning('QuickBooks deleted a wallet account, but the wallet still holds a balance, so it stays open and linked.', [
                'company_uuid' => $companyUuid,
                'wallet_uuids' => $held,
            ]);
        }

        return $held;
    }

    /**
     * @param class-string         $class
     * @param array<int, string>   $uuids
     * @param array<string, mixed> $extra
     */
    private function retireMany(string $class, string $companyUuid, array $uuids, array $extra): void
    {
        if ($uuids === [] || $companyUuid === '' || class_exists($class) === false) {
            return;
        }

        try {
            $class::query()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->update(array_merge($extra, [
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string>                 $linkUuids
     * @param array<string, array<string, true>> $voidInvoicesByRealm
     */
    private function deleteRetiredLinks(string $companyUuid, array $linkUuids, array $voidInvoicesByRealm): void
    {
        if ($companyUuid === '' || ($linkUuids === [] && $voidInvoicesByRealm === [])) {
            return;
        }

        try {
            Link::query()->where('company_uuid', $companyUuid)->where(function ($query) use ($linkUuids, $voidInvoicesByRealm): void {
                $started = false;
                if ($linkUuids !== []) {
                    $query->whereIn('uuid', $linkUuids);
                    $started = true;
                }
                foreach ($voidInvoicesByRealm as $realm => $ids) {
                    $invoiceUuids = array_keys($ids);
                    if ($invoiceUuids === []) {
                        continue;
                    }
                    $method = $started === true ? 'orWhere' : 'where';
                    $query->{$method}(function ($inner) use ($realm, $invoiceUuids): void {
                        $inner->where('local_type', 'payment')->whereIn('local_uuid', $invoiceUuids);
                        if ($realm !== '') {
                            $inner->where('realm_id', $realm);
                        }
                    });
                    $started = true;
                }
            })->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, array<int, string>> $uuidsByType
     */
    private function finishPendingMany(string $companyUuid, array $uuidsByType): void
    {
        $pairs = [];
        foreach ($uuidsByType as $type => $uuids) {
            foreach ($uuids as $uuid) {
                if ($uuid !== '') {
                    $pairs[] = [(string) $type, $uuid];
                }
            }
        }
        if ($companyUuid === '' || $pairs === []) {
            return;
        }

        try {
            PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('status', 'pending')
                ->where(function ($query) use ($pairs): void {
                    foreach ($pairs as $index => $pair) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}(function ($inner) use ($pair): void {
                            $inner->where('local_type', $pair[0])->where('local_uuid', $pair[1]);
                        });
                    }
                })
                ->update([
                    'status'     => 'done',
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function setInvoiceStatus(string $companyUuid, string $uuid, string $status): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false || $companyUuid === '' || $uuid === '') {
            return;
        }

        try {
            $current = $this->invoiceTable($class, $companyUuid, $uuid)->value('status');
            if (is_string($current) === false) {
                return;
            }
            if ($status === 'void' && in_array(strtolower($current), ['void', 'voided', 'cancelled', 'canceled'], true) === true) {
                return;
            }
            if ($current === $status) {
                return;
            }
            // The invoice model eager-loads lines. A table update changes status only and leaves integer money alone.
            $this->invoiceTable($class, $companyUuid, $uuid)->update([
                'status'     => $status,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function invoiceStatus(string $companyUuid, string $uuid): ?string
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false || $companyUuid === '' || $uuid === '') {
            return null;
        }

        try {
            $status = $this->invoiceTable($class, $companyUuid, $uuid)->value('status');
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return null;
            }

            throw $exception;
        }

        return is_string($status) === true ? $status : null;
    }

    /**
     * @param class-string $class
     */
    private function invoiceTable(string $class, string $companyUuid, string $uuid): \Illuminate\Database\Query\Builder
    {
        $model = new $class();

        return $model->getConnection()->table($model->getTable())
            ->where('company_uuid', $companyUuid)
            ->where('uuid', $uuid)
            ->whereNull('deleted_at');
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $extra
     */
    private function retireLocal(string $class, string $companyUuid, string $uuid, array $extra): void
    {
        if (class_exists($class) === false || $companyUuid === '' || $uuid === '') {
            return;
        }

        try {
            $class::query()->where('company_uuid', $companyUuid)->where('uuid', $uuid)->update(array_merge($extra, [
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function dropLocalLinks(string $companyUuid, string $realmId, string $localType, string $localUuid): void
    {
        if ($companyUuid === '' || $localType === '' || $localUuid === '') {
            return;
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('local_uuid', $localUuid);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function dropRemoteLinks(string $companyUuid, string $realmId, string $localType, string $quickbooksId): void
    {
        if ($companyUuid === '' || $localType === '' || $quickbooksId === '') {
            return;
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('qbo_id', $quickbooksId);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function finishPending(string $companyUuid, string $localType, string $localUuid): void
    {
        if ($companyUuid === '' || $localType === '' || $localUuid === '') {
            return;
        }

        try {
            PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('local_uuid', $localUuid)
                ->where('status', 'pending')
                ->update([
                    'status'     => 'done',
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function isMissingTable(\Illuminate\Database\QueryException $exception): bool
    {
        return $this->isUnavailableStorage($exception);
    }

    private function isUnavailableStorage(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'no such table') === true
            || str_contains($message, "doesn't exist") === true
            || str_contains($message, 'base table or view not found') === true
            || str_contains($message, 'not configured') === true;
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     */
    private function releaseMemoryRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid, array $relatedInvoiceUuids = []): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }

        $mappedInvoices = $this->mappedMemoryInvoices($companyUuid, $realmId, $localType, $quickbooksId);
        [$uuids, $kept] = $this->droppedMemoryLinks($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
        $memory->links  = $kept;
        foreach (array_values(array_unique($uuids)) as $uuid) {
            $this->applyMemoryRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $uuid, $relatedInvoiceUuids, $mappedInvoices);
        }
        $memory->rebuildIndex();
    }

    /**
     * @return array<int, string>
     */
    private function mappedMemoryInvoices(string $companyUuid, string $realmId, string $localType, string $quickbooksId): array
    {
        $mappedInvoices = [];
        $memory         = $this->memory;
        if ($localType !== 'payment' || $quickbooksId === '' || $memory === null) {
            return $mappedInvoices;
        }
        foreach ($memory->links as $link) {
            if ($this->memoryPaymentInvoiceLink($link, $companyUuid, $realmId, $quickbooksId) === false) {
                continue;
            }
            $invoiceUuid = (string) ($link['qbo_id'] ?? '');
            if ($invoiceUuid !== '' && $invoiceUuid !== $quickbooksId) {
                $mappedInvoices[] = $invoiceUuid;
            }
        }

        return $mappedInvoices;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    private function droppedMemoryLinks(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): array
    {
        $uuids  = [];
        $kept   = [];
        $memory = $this->memory;
        if ($memory === null) {
            return [$uuids, $kept];
        }
        foreach ($memory->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            $paymentMap = $this->memoryPaymentInvoiceLink($link, $companyUuid, $realmId, $quickbooksId);
            $matched    = $paymentMap === true || $this->memoryLinkMatches($link, $companyUuid, $realmId, $localType, $quickbooksId, $localUuid) === true;
            if ($matched === true) {
                $localId = (string) ($link['local_uuid'] ?? '');
                if ($localId !== '' && $paymentMap === false) {
                    $uuids[] = $localId;
                }
                continue;
            }
            $kept[] = $link;
        }
        if (is_string($localUuid) === true && $localUuid !== '') {
            $uuids[] = $localUuid;
        }

        return [$uuids, $kept];
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     * @param array<int, string> $mappedInvoices
     */
    private function applyMemoryRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, string $uuid, array $relatedInvoiceUuids, array $mappedInvoices): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }
        $pendingUuids = [$uuid];
        if ($localType === 'invoice') {
            $this->voidMemoryInvoice($companyUuid, $realmId, $uuid);
        } elseif ($localType === 'payment') {
            $pendingUuids = $this->unpayMemoryInvoices($companyUuid, $realmId, $quickbooksId, $uuid, $relatedInvoiceUuids, $mappedInvoices);
        } elseif ($localType === 'customer') {
            unset($memory->customers[$uuid]);
        } elseif ($localType === 'wallet') {
            unset($memory->wallets[$uuid]);
        }
        $this->finishMemoryPending($companyUuid, $localType, $quickbooksId, $pendingUuids);
    }

    private function memoryPaymentInvoiceLink(mixed $link, string $companyUuid, string $realmId, string $quickbooksId): bool
    {
        if (is_array($link) === false) {
            return false;
        }

        return (string) ($link['local_type'] ?? '') === 'payment-invoice'
            && (string) ($link['company_uuid'] ?? '') === $companyUuid
            && ($realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId)
            && (string) ($link['local_uuid'] ?? '') === $quickbooksId;
    }

    /**
     * @param array<string, mixed> $link
     */
    private function memoryLinkMatches(array $link, string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): bool
    {
        $sameCompany = (string) ($link['company_uuid'] ?? '') === $companyUuid;
        $sameRealm   = $realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId;
        $sameType    = (string) ($link['local_type'] ?? '') === $localType;
        $sameRemote  = $quickbooksId !== '' && (string) ($link['qbo_id'] ?? '') === $quickbooksId;
        $sameLocal   = is_string($localUuid) === true && $localUuid !== '' && (string) ($link['local_uuid'] ?? '') === $localUuid;

        return $sameCompany === true && $sameRealm === true && $sameType === true && ($sameRemote === true || $sameLocal === true);
    }

    private function voidMemoryInvoice(string $companyUuid, string $realmId, string $uuid): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }
        if (isset($memory->invoices[$uuid]) === true && is_array($memory->invoices[$uuid]) === true) {
            $memory->invoices[$uuid]['status'] = 'void';
        }
        $memory->links = array_values(array_filter(
            $memory->links,
            function ($link) use ($companyUuid, $realmId, $uuid): bool {
                return $this->keepMemoryLink($link, $companyUuid, $realmId, $uuid);
            }
        ));
    }

    private function keepMemoryLink(mixed $link, string $companyUuid, string $realmId, string $uuid): bool
    {
        if (is_array($link) === false) {
            return false;
        }
        $payment = (string) ($link['local_type'] ?? '') === 'payment'
            && (string) ($link['company_uuid'] ?? '') === $companyUuid
            && ($realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId)
            && (string) ($link['local_uuid'] ?? '') === $uuid;

        return $payment === false;
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     * @param array<int, string> $mappedInvoices
     *
     * @return array<int, string>
     */
    private function unpayMemoryInvoices(string $companyUuid, string $realmId, string $quickbooksId, string $uuid, array $relatedInvoiceUuids, array $mappedInvoices): array
    {
        $pendingUuids = $this->paymentInvoiceTargets($companyUuid, $realmId, $quickbooksId, $uuid, array_merge($relatedInvoiceUuids, $mappedInvoices));
        $memory       = $this->memory;
        if ($memory === null) {
            return $pendingUuids;
        }
        foreach ($pendingUuids as $invoiceUuid) {
            if (isset($memory->invoices[$invoiceUuid]) === false || is_array($memory->invoices[$invoiceUuid]) === false) {
                continue;
            }
            $status = strtolower((string) ($memory->invoices[$invoiceUuid]['status'] ?? ''));
            if (in_array($status, ['paid', 'partial'], true) === true) {
                $memory->invoices[$invoiceUuid]['status'] = 'sent';
            }
        }

        return $pendingUuids;
    }

    /**
     * @param array<int, string> $pendingUuids
     */
    private function finishMemoryPending(string $companyUuid, string $localType, string $quickbooksId, array $pendingUuids): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }
        $pendingType = $localType === 'payment' ? 'invoice' : $localType;
        foreach ($pendingUuids as $pendingUuid) {
            if ($localType === 'payment' && $pendingUuid === $quickbooksId) {
                continue;
            }
            foreach ($memory->pending as $index => $row) {
                if ($this->pendingRowMatches($row, $companyUuid, $pendingType, $pendingUuid) === true) {
                    $memory->pending[$index]['status'] = 'done';
                }
            }
        }
    }

    private function pendingRowMatches(mixed $row, string $companyUuid, string $pendingType, string $pendingUuid): bool
    {
        if (is_array($row) === false) {
            return false;
        }

        return (string) ($row['company_uuid'] ?? '') === $companyUuid
            && (string) ($row['local_type'] ?? '') === $pendingType
            && (string) ($row['local_uuid'] ?? '') === $pendingUuid
            && (string) ($row['status'] ?? '') === 'pending';
    }

    /**
     * Insert new link rows. Updates of an existing uuid are returned so the
     * identity delete for the whole chunk can run before those updates.
     *
     * @param array<int, array<string, mixed>> $links
     *
     * @return array{releases: array<int, array{link: array<string, mixed>, keep: string}>, updates: array<int, array{uuid: string, columns: array<string, mixed>}>}
     */
    private function insertLinks(array $links): array
    {
        if ($links === []) {
            return ['releases' => [], 'updates' => []];
        }

        $rows     = $this->linkRows($links);
        $existing = $this->existingLinkUuids($rows);

        return $this->partitionLinkRows($rows, $existing);
    }

    /**
     * @param array<int, array<string, mixed>> $links
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkRows(array $links): array
    {
        $now  = Carbon::now()->toDateTimeString();
        $rows = [];
        foreach ($links as $link) {
            $rows[] = [
                'uuid'         => (string) (($link['uuid'] ?? '') !== '' ? $link['uuid'] : Str::uuid()),
                'company_uuid' => $link['company_uuid'] ?? null,
                'realm_id'     => (string) ($link['realm_id'] ?? ''),
                'local_type'   => (string) ($link['local_type'] ?? ''),
                'local_uuid'   => (string) ($link['local_uuid'] ?? ''),
                'qbo_entity'   => (string) ($link['qbo_entity'] ?? ''),
                'qbo_id'       => (string) ($link['qbo_id'] ?? ''),
                'sync_token'   => (string) ($link['sync_token'] ?? '0'),
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, true>
     */
    private function existingLinkUuids(array $rows): array
    {
        $existing = [];
        foreach (array_chunk(array_column($rows, 'uuid'), 200) as $chunk) {
            foreach (Link::query()->whereIn('uuid', $chunk)->pluck('uuid') as $uuid) {
                $existing[(string) $uuid] = true;
            }
        }

        return $existing;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, true>              $existing
     *
     * @return array{releases: array<int, array{link: array<string, mixed>, keep: string}>, updates: array<int, array{uuid: string, columns: array<string, mixed>}>}
     */
    private function partitionLinkRows(array $rows, array $existing): array
    {
        $inserts  = [];
        $updates  = [];
        $releases = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['uuid']]) === false) {
                $inserts[] = $row;
                continue;
            }
            $releases[] = ['link' => $row, 'keep' => (string) $row['uuid']];
            $updates[]  = [
                'uuid'    => (string) $row['uuid'],
                'columns' => [
                    'company_uuid' => $row['company_uuid'],
                    'realm_id'     => $row['realm_id'],
                    'local_type'   => $row['local_type'],
                    'local_uuid'   => $row['local_uuid'],
                    'qbo_entity'   => $row['qbo_entity'],
                    'qbo_id'       => $row['qbo_id'],
                    'sync_token'   => $row['sync_token'],
                ],
            ];
        }
        foreach (array_chunk($inserts, 200) as $chunk) {
            Link::query()->insertOrIgnore($chunk);
        }

        return ['releases' => $releases, 'updates' => $updates];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writePendingMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now     = Carbon::now()->toDateTimeString();
        $payload = [];
        foreach ($rows as $row) {
            $companyUuid = (string) ($row['company_uuid'] ?? '');
            $localType   = (string) ($row['local_type'] ?? '');
            $localUuid   = (string) ($row['local_uuid'] ?? '');
            if ($companyUuid === '' || $localType === '' || $localUuid === '') {
                continue;
            }
            $payload[] = [
                'uuid'            => (string) (($row['uuid'] ?? '') !== '' ? $row['uuid'] : Str::uuid()),
                'company_uuid'    => $companyUuid,
                'local_type'      => $localType,
                'local_uuid'      => $localUuid,
                'reason'          => $row['reason'] ?? null,
                'status'          => 'pending',
                'attempts'        => (int) ($row['attempts'] ?? 0),
                'next_attempt_at' => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }
        foreach (array_chunk($payload, 200) as $chunk) {
            try {
                PendingSync::query()->insertOrIgnore($chunk);
            } catch (\Illuminate\Database\QueryException $exception) {
                if (self::isDuplicatePendingWrite($exception) === false) {
                    throw $exception;
                }
                foreach ($chunk as $row) {
                    $this->writePending($row);
                }
            }
        }
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
     *
     * @param array<int, mixed> $items
     */
    private function replaceInvoiceLines(string $invoiceUuid, array $items): void
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
            $rows[] = [
                'uuid'         => (string) Str::uuid(),
                'invoice_uuid' => $invoiceUuid,
                'description'  => (string) ($item['description'] ?? ''),
                'quantity'     => (int) ($item['quantity'] ?? 1),
                'unit_price'   => (int) ($item['unit_price'] ?? 0),
                'amount'       => (int) ($item['amount'] ?? 0),
                'tax_rate'     => 0,
                'tax_amount'   => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        if ($rows === []) {
            return;
        }

        $model->getConnection()->table($model->getTable())->insert($rows);
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
            return null;
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
