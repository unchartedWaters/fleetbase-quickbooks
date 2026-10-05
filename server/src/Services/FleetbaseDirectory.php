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
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        if (!class_exists($class)) {
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

        return is_numeric($cached) ? (int) $cached : null;
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
     * Mark every customer, non-draft invoice, and wallet pending without syncing them.
     * Reconcile does not use this; it claims one page of in-scope invoices.
     */
    public function flagCatalog(string $companyUuid): void
    {
        $this->queueInScope($companyUuid, [
            'customer' => true,
            'invoice'  => true,
            'wallet'   => true,
        ]);
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

        if (!empty($enabled['customer'])) {
            $this->queueCatalog('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, true, 'customer');
        }
        if (!empty($enabled['invoice'])) {
            $this->queueCatalog('Fleetbase\\Ledger\\Models\\Invoice', $companyUuid, false, 'invoice');
            $this->queueInvoiceLinks($companyUuid);
        }
        if (!empty($enabled['wallet'])) {
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
            if (!$wasPaused) {
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

        $loadedByUuid = [];
        foreach ($this->loaded['links'] as $loadedLink) {
            if (!is_array($loadedLink)) {
                continue;
            }
            $loadedUuid = (string) ($loadedLink['uuid'] ?? '');
            if ($loadedUuid !== '') {
                $loadedByUuid[$loadedUuid] = $loadedLink;
            }
        }
        $linkFields   = ['company_uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_entity', 'qbo_id', 'sync_token'];
        $freshLinks   = [];
        $changedLinks = [];
        foreach ($ledger->links as $link) {
            if (!is_array($link) || isset($skipped[(string) ($link['company_uuid'] ?? '')])) {
                continue;
            }
            $key     = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
            $loaded  = $this->loadedLink($key, $link, $loadedByUuid);
            $changed = self::changedColumns($link, is_array($loaded) ? $loaded : null, $linkFields);
            if ($changed === [] && is_array($loaded)) {
                continue;
            }
            if (is_array($loaded)) {
                $uuid = (string) ($loaded['uuid'] ?? '');
                if (isset($changed['local_uuid']) || isset($changed['local_type'])) {
                    // The new local key may already be stored. Free it before this row moves,
                    // or the unique local identity rejects the update and insertOrIgnore keeps the old key.
                    $this->releaseLinkIdentity($link, $uuid);
                }
                if ($uuid === '') {
                    Link::query()->updateOrCreate(
                        [
                            'company_uuid' => $link['company_uuid'],
                            'local_type'   => $link['local_type'],
                            'local_uuid'   => $link['local_uuid'],
                        ],
                        $link
                    );
                    continue;
                }
                $changedLinks[] = ['uuid' => $uuid, 'columns' => $changed];
                continue;
            }
            $freshLinks[] = $link;
        }
        $this->insertLinks($freshLinks);
        $this->updateByUuid(new Link(), $changedLinks, $linkFields);
        $this->dropStaleInvoicePaymentLinks($ledger, $skipped);
        $freshPending   = [];
        $changedPending = [];
        foreach ($ledger->pending as $row) {
            if (isset($skipped[(string) ($row['company_uuid'] ?? '')])) {
                continue;
            }
            if (empty($row['uuid'])) {
                $freshPending[] = $row;
                continue;
            }
            $columns = $this->pendingUpdateColumns($row, $this->loaded['pending'][(string) $row['uuid']] ?? null);
            if ($columns === []) {
                continue;
            }
            $changedPending[] = ['uuid' => (string) $row['uuid'], 'columns' => $columns];
        }
        $this->updateByUuid(new PendingSync(), $changedPending, ['company_uuid', 'local_type', 'local_uuid', 'reason', 'status', 'attempts', 'next_attempt_at']);
        $this->writePendingMany($freshPending);
        $batchUuid = null;
        foreach ($ledger->batches as $batch) {
            if (!empty($batch['uuid'])) {
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
                if (!empty($attempt['uuid']) || !empty($attempt['batch_uuid'])) {
                    continue;
                }
                $ledger->attempts[$index]['batch_uuid'] = $batchUuid;
            }
        }
        $this->insertAttempts($ledger->attempts);
        $this->persistCustomers($ledger, $skipped);
        $this->persistInvoices($ledger, $skipped);
        $this->persistWallets($ledger, $skipped);
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
            if (!self::connectionStillCurrent(
                $this->connection($companyUuid),
                is_array($ledgerConnection) ? $ledgerConnection : null
            )) {
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

        $connection = $this->connection($companyUuid);
        if (!ConnectionGate::hasRealm($connection)) {
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
     * @return array<string, mixed>|null
     */
    public function connection(string $companyUuid): ?array
    {
        $own = $this->storedConnection($companyUuid);
        if ($own !== null) {
            return $this->withEntityCompany($own, $companyUuid);
        }

        $shared = $this->soleConnection();
        if ($shared === null) {
            return null;
        }

        return $this->withEntityCompany($shared, $companyUuid);
    }

    /**
     * The connection this company syncs with. A company without its own row
     * uses the single install-wide connection. A second organization's row
     * is not used when more than one connection is stored.
     *
     * @return array<string, mixed>|null
     */
    private function connectionForLedger(SyncLedger $ledger, string $companyUuid): ?array
    {
        $connection = $ledger->connection($companyUuid);
        if (!is_array($connection)) {
            $connection = $this->soleConnection($ledger);
        }
        if (!is_array($connection)) {
            return null;
        }

        $connection                            = $this->withEntityCompany($connection, $companyUuid);
        $ledger->connections[$companyUuid]     = $connection;

        return $connection;
    }

    /**
     * Links and pending rows belong to the organization that owns the record.
     * A shared connection row keeps the owner's uuid; the working copy does not.
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
     * @return array<string, mixed>|null
     */
    private function soleConnection(?SyncLedger $ledger = null): ?array
    {
        if ($this->memory !== null) {
            $ledger = $this->memory;
        }
        if ($ledger !== null) {
            $connections = [];
            foreach ($ledger->connections as $connection) {
                if (is_array($connection)) {
                    $connections[] = $connection;
                }
            }

            return count($connections) === 1 ? $connections[0] : null;
        }

        $rows = (new Connection())->newQuery()->orderByDesc('updated_at')->limit(2)->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $connection = $rows->first();

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
            if (array_key_exists($field, $row)) {
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
            if (array_key_exists($field, $row) && $row[$field] !== null) {
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
        if (is_numeric($value)) {
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
            if (!array_key_exists($field, $row)) {
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

        return (int) PendingSync::query()
            ->where('company_uuid', $companyUuid)
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Carbon::createFromTimestamp($now));
            })
            ->count();
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function writePending(array $row): void
    {
        try {
            PendingSync::query()->firstOrCreate(
                [
                    'company_uuid' => $row['company_uuid'],
                    'local_type'   => $row['local_type'],
                    'local_uuid'   => $row['local_uuid'],
                    'status'       => 'pending',
                ],
                self::toDates($row, self::PENDING_TIMES)
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            if (!self::isDuplicatePendingWrite($exception)) {
                throw $exception;
            }
        }
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

        return in_array($driverCode, [19, 1555, 2067], true)
            && preg_match('/(?:UNIQUE|PRIMARY KEY) constraint failed/i', $message) === 1;
    }

    private function loadLedger(string $companyUuid, ?int $pendingLimit = null, ?int $now = null): SyncLedger
    {
        $ledger     = new SyncLedger();
        $connection = $this->connection($companyUuid);
        if ($connection !== null) {
            $ledger->connections[$companyUuid] = $connection;
        }
        $pendingQuery = (new PendingSync())->newQuery()->where('company_uuid', $companyUuid)->where('status', 'pending');
        if ($pendingLimit !== null) {
            $moment = Carbon::createFromTimestamp($now ?? time());
            $pendingQuery->where(function ($query) use ($moment): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $moment);
            })->orderBy('next_attempt_at')->orderBy('uuid')->limit($pendingLimit);
        }
        $customerIds     = [];
        $invoiceIds      = [];
        $walletIds       = [];
        $flaggedInvoices = [];
        foreach ($pendingQuery->get() as $row) {
            if (!$row instanceof PendingSync) {
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
        if ($pendingLimit === null) {
            foreach ((new Link())->newQuery()->where('company_uuid', $companyUuid)->get() as $link) {
                if ($link instanceof Link) {
                    $this->rememberLoadedLink($ledger, $link->toArray());
                }
            }
            $ledger->customers = $this->readCustomers($companyUuid);
            $ledger->invoices  = $this->readInvoices($companyUuid, null, $flaggedInvoices);
            $ledger->wallets   = $this->readWallets($companyUuid);
        } else {
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
        $ledger->rebuildIndex();
        $this->loaded['customers'] = $ledger->customers;
        $this->loaded['invoices']  = $ledger->invoices;
        $this->loaded['wallets']   = $ledger->wallets;

        return $ledger;
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
            if (!is_array($entity)) {
                continue;
            }
            $name = $entity['entity'] ?? null;
            if (!is_string($name) || !isset($known[$name])) {
                continue;
            }
            $id = $entity['id'] ?? null;
            if (is_int($id)) {
                $id = (string) $id;
            }
            if (!is_string($id) || $id === '') {
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
                $method = $started ? 'orWhere' : 'where';
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
            if (!is_array($link) || (string) ($link['company_uuid'] ?? '') !== $companyUuid) {
                continue;
            }
            if ($realm !== '' && (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            $entity = (string) ($link['qbo_entity'] ?? '');
            $id     = (string) ($link['qbo_id'] ?? '');
            if (!isset($idsByEntity[$entity][$id])) {
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
                if (is_array($invoice)) {
                    $ledger->invoices[$uuid] = $invoice;
                }
            }
            $customerIds = $this->withInvoiceCustomers($ledger->invoices, $customerIds);
            foreach ($customerIds as $uuid) {
                $customer = $this->memory->customers[$uuid] ?? null;
                if (is_array($customer)) {
                    $ledger->customers[$uuid] = $customer;
                }
            }
            foreach ($walletIds as $uuid) {
                $wallet = $this->memory->wallets[$uuid] ?? null;
                if (is_array($wallet)) {
                    $ledger->wallets[$uuid] = $wallet;
                }
            }
            foreach ($this->memory->links as $link) {
                if (!is_array($link) || !$this->linkMatchesLocalIds($link, $companyUuid, $customerIds, $invoiceIds, $walletIds)) {
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
     */
    private function linkMatchesLocalIds(array $link, string $companyUuid, array $customerIds, array $invoiceIds, array $walletIds): bool
    {
        if ((string) ($link['company_uuid'] ?? '') !== $companyUuid) {
            return false;
        }

        $type = (string) ($link['local_type'] ?? '');
        $uuid = (string) ($link['local_uuid'] ?? '');
        if ($type === 'customer' && in_array($uuid, $customerIds, true)) {
            return true;
        }
        if ($type === 'wallet' && in_array($uuid, $walletIds, true)) {
            return true;
        }

        return ($type === 'invoice' || $type === 'payment') && in_array($uuid, $invoiceIds, true);
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
            if (array_key_exists($field, $connection)) {
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
        if (empty($connection['needs_reauth'])) {
            return $columns;
        }

        unset($columns['access_token'], $columns['refresh_token'], $columns['token_expires_at']);
        $sameRefresh = (string) $storedRefresh === (string) ($connection['refresh_token'] ?? '');
        $sameAccess  = (string) $storedAccess === (string) ($connection['access_token'] ?? '');
        if (!$sameRefresh || !$sameAccess) {
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
            if (!array_key_exists($field, $current)) {
                continue;
            }
            if (is_array($loaded) && ($loaded[$field] ?? null) === $current[$field]) {
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

        $existing = (new Connection())->newQuery()->where('company_uuid', $companyUuid)->first();
        if (!$existing instanceof Connection) {
            $realm = trim((string) ($connection['realm_id'] ?? ''));
            if ($realm !== '') {
                $existing = $this->soleStoredConnection($realm);
            }
        }
        $storedRealm = $existing instanceof Connection ? (string) ($existing->realm_id ?? '') : null;
        $columns     = self::connectionColumns($connection, $storedRealm, $fields);
        if ($columns === null || !$existing instanceof Connection) {
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

        if (!empty($columns['needs_reauth'])) {
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
        if (Cache::has($cacheKey)) {
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
        if (!class_exists(Company::class)) {
            return [];
        }

        $company = Company::query()->where('uuid', $companyUuid)->first();
        if (!$company instanceof Company) {
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
            'started_at'     => isset($batch['started_at']) ? date('Y-m-d H:i:s', (int) $batch['started_at']) : null,
            'finished_at'    => isset($batch['finished_at']) ? date('Y-m-d H:i:s', (int) $batch['finished_at']) : null,
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
        if (!class_exists($class) || $onlyUuids === []) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid)->where('type', 'customer');
        if ($onlyUuids !== null) {
            $query->whereIn('uuid', $onlyUuids);
        }

        $rows = [];
        /** @var Model $customer */
        foreach ($query->get() as $customer) {
            $rows[(string) $customer->uuid] = [
                'uuid'         => (string) $customer->uuid,
                'company_uuid' => $companyUuid,
                'type'         => 'customer',
                'name'         => $customer->name,
                'email'        => $customer->email,
                'phone'        => $customer->phone,
                'notes'        => $customer->notes,
            ];
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
        if (!class_exists($class) || $onlyUuids === []) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid)->with('items');
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
        if (!class_exists($class) || $onlyUuids === []) {
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
            ];
        }

        return $rows;
    }

    private function dateString(mixed $value): string
    {
        if (is_object($value) && method_exists($value, 'toDateString')) {
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
        if (!class_exists($class)) {
            return;
        }

        $fresh = [];
        $uuids = [];
        foreach ($ledger->customers as $customer) {
            if (isset($skipped[(string) ($customer['company_uuid'] ?? '')])) {
                continue;
            }
            $uuid = (string) ($customer['uuid'] ?? '');
            if ($uuid === '' || empty($customer['company_uuid'])) {
                continue;
            }
            $uuids[] = $uuid;
        }
        $present = $this->existingCustomerUuids($class, $uuids);

        foreach ($ledger->customers as $customer) {
            if (isset($skipped[(string) ($customer['company_uuid'] ?? '')])) {
                continue;
            }
            $uuid = (string) ($customer['uuid'] ?? '');
            if ($uuid === '' || empty($customer['company_uuid'])) {
                continue;
            }
            // Only fields the engine changed are written, so an edit during the batch stays.
            $loaded = $this->loaded['customers'][$uuid] ?? null;
            $values = self::changedColumns($customer, $loaded, ['name', 'email', 'phone', 'notes']);
            if (isset($present[$uuid])) {
                if ($values === []) {
                    continue;
                }
                /** @var Model|null $model */
                $model = $class::query()
                    ->where('uuid', $uuid)
                    ->where('company_uuid', $customer['company_uuid'])
                    ->first();
                if ($model === null) {
                    $fresh[] = $customer;
                    continue;
                }
                $model->fill($values);
                if ($model->isDirty()) {
                    $model->save();
                }
                continue;
            }
            if ($values === [] && is_array($loaded)) {
                continue;
            }
            $fresh[] = $customer;
        }

        $this->insertCustomers($class, $fresh);
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistInvoices(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (!class_exists($class)) {
            return;
        }

        foreach ($ledger->invoices as $invoice) {
            if (isset($skipped[(string) ($invoice['company_uuid'] ?? '')])) {
                continue;
            }
            if (empty($invoice['uuid'])) {
                continue;
            }
            $loaded = $this->loaded['invoices'][(string) $invoice['uuid']] ?? null;
            if (!$this->invoiceNeedsWrite($invoice, $loaded)) {
                continue;
            }
            /** @var Model|null $model */
            $model = $class::query()->where('uuid', $invoice['uuid'])->where('company_uuid', $invoice['company_uuid'] ?? '')->first();
            if (!$model instanceof \Fleetbase\Ledger\Models\Invoice) {
                continue;
            }
            $loaded = $this->loaded['invoices'][(string) $invoice['uuid']] ?? null;
            foreach (self::changedColumns($invoice, $loaded, ['number', 'notes', 'date', 'due_date']) as $field => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $model->{$field} = $value;
            }
            if (!empty($invoice['replace_from_quickbooks'])) {
                $paid    = array_key_exists('amount_paid', $invoice)
                    ? (int) $invoice['amount_paid']
                    : self::minorAttribute($model, 'amount_paid');
                $amounts = self::invoiceAmounts((int) ($invoice['total'] ?? 0), (int) ($invoice['tax'] ?? 0), $paid);
                foreach (['tax', 'total_amount', 'subtotal', 'balance'] as $field) {
                    self::writeSignedMinor($model, $field, $amounts[$field]);
                }
                if (!empty($invoice['status'])) {
                    $model->status = (string) $invoice['status'];
                }
                if (!empty($invoice['items_from_quickbooks']) && is_array($invoice['items'] ?? null)) {
                    $this->replaceInvoiceLines($model, $invoice['items']);
                }
            }
            if (!empty($invoice['payment_from_quickbooks'])) {
                if (array_key_exists('amount_paid', $invoice)) {
                    self::writeSignedMinor($model, 'amount_paid', (int) $invoice['amount_paid']);
                }
                if (!empty($invoice['paid_at'])) {
                    $model->paid_at = $invoice['paid_at'];
                }
                if (!empty($invoice['status'])) {
                    $model->status = (string) $invoice['status'];
                }
                $balance = self::invoiceAmounts(
                    self::minorAttribute($model, 'total_amount'),
                    self::minorAttribute($model, 'tax'),
                    self::minorAttribute($model, 'amount_paid')
                )['balance'];
                self::writeSignedMinor($model, 'balance', $balance);
            }
            if ($model->isDirty()) {
                $model->save();
            }
        }
    }

    /**
     * @param array<string, true> $skipped
     */
    private function persistWallets(SyncLedger $ledger, array $skipped = []): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (!class_exists($class)) {
            return;
        }

        foreach ($ledger->wallets as $wallet) {
            if (isset($skipped[(string) ($wallet['company_uuid'] ?? '')])) {
                continue;
            }
            if (empty($wallet['uuid'])) {
                continue;
            }
            $loaded = $this->loaded['wallets'][(string) $wallet['uuid']] ?? null;
            if (self::changedColumns($wallet, $loaded, ['name', 'description', 'currency', 'status', 'meta']) === [] && is_array($loaded)) {
                continue;
            }
            /** @var Model|null $model */
            $model = $class::query()->where('uuid', $wallet['uuid'])->where('company_uuid', $wallet['company_uuid'] ?? '')->first();
            if ($model === null) {
                continue;
            }
            $loaded = $this->loaded['wallets'][(string) $wallet['uuid']] ?? null;
            foreach (self::changedColumns($wallet, $loaded, ['name', 'description', 'currency', 'status', 'meta']) as $field => $value) {
                if (in_array($field, ['currency', 'status'], true) && ($value === '' || $value === null)) {
                    continue;
                }
                $model->{$field} = $value;
            }
            if ($model->isDirty()) {
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
        if (!empty($invoice['replace_from_quickbooks']) || !empty($invoice['payment_from_quickbooks'])) {
            return true;
        }

        return self::changedColumns($invoice, $loaded, ['number', 'notes', 'date', 'due_date', 'status', 'amount_paid', 'paid_at', 'tax', 'total']) !== []
            || !is_array($loaded);
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
        if (class_exists($invoiceClass)) {
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
        if (!class_exists($class)) {
            return [];
        }

        $query = $class::query()->where('company_uuid', $companyUuid);
        if ($customersOnly) {
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

        $query = (new Link())->newQuery()->where('company_uuid', $companyUuid)->where(function ($scope) use ($customerIds, $invoiceIds, $walletIds): void {
            $started = false;
            if ($customerIds !== []) {
                $scope->where(function ($inner) use ($customerIds): void {
                    $inner->where('local_type', 'customer')->whereIn('local_uuid', $customerIds);
                });
                $started = true;
            }
            if ($walletIds !== []) {
                $method = $started ? 'orWhere' : 'where';
                $scope->{$method}(function ($inner) use ($walletIds): void {
                    $inner->where('local_type', 'wallet')->whereIn('local_uuid', $walletIds);
                });
                $started = true;
            }
            if ($invoiceIds !== []) {
                $method = $started ? 'orWhere' : 'where';
                $scope->{$method}(function ($inner) use ($invoiceIds): void {
                    $inner->whereIn('local_type', ['invoice', 'payment'])->whereIn('local_uuid', $invoiceIds);
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
        if ($current === $minor || (is_string($current) && $current === (string) $minor)) {
            return;
        }

        $attributes[$field] = $minor;
        $model->setRawAttributes($attributes);
    }

    private static function minorAttribute(Model $model, string $field): int
    {
        $value = $model->getAttributes()[$field] ?? 0;
        if ($value === null || $value === '' || is_bool($value)) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * The one stored connection for this realm, when the working company has no row of its own.
     */
    private function soleStoredConnection(string $realm): ?Connection
    {
        $rows = (new Connection())->newQuery()->where('realm_id', $realm)->limit(2)->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $connection = $rows->first();

        return $connection instanceof Connection ? $connection : null;
    }

    private function stampOwnedCompanies(SyncLedger $ledger): void
    {
        foreach ($ledger->links as $index => $link) {
            if (!is_array($link)) {
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
            if (!is_array($row)) {
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
        if (!is_array($row)) {
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
        if (is_array($loaded)) {
            return $loaded;
        }
        $uuid = (string) ($link['uuid'] ?? '');
        if ($uuid === '' || !isset($loadedByUuid[$uuid]) || !is_array($loadedByUuid[$uuid])) {
            return null;
        }

        return $loadedByUuid[$uuid];
    }

    /**
     * Delete a different row that already occupies this local identity.
     *
     * @param array<string, mixed> $link
     */
    private function releaseLinkIdentity(array $link, string $keepUuid): void
    {
        $company = (string) ($link['company_uuid'] ?? '');
        $type    = (string) ($link['local_type'] ?? '');
        $local   = (string) ($link['local_uuid'] ?? '');
        if ($company === '' || $type === '' || $local === '') {
            return;
        }

        $query = Link::query()
            ->where('company_uuid', $company)
            ->where('local_type', $type)
            ->where('local_uuid', $local);
        if ($keepUuid !== '') {
            $query->where('uuid', '!=', $keepUuid);
        }
        $query->delete();
    }

    /**
     * One QuickBooks payment id is stored under that id. An older row that still
     * uses the invoice uuid for the same payment is removed once the new key is stored.
     *
     * @param array<string, true> $skipped
     */
    private function dropStaleInvoicePaymentLinks(SyncLedger $ledger, array $skipped): void
    {
        foreach ($ledger->links as $link) {
            if (!is_array($link) || isset($skipped[(string) ($link['company_uuid'] ?? '')])) {
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
            $kept = Link::query()
                ->where('company_uuid', $company)
                ->where('realm_id', $realm)
                ->where('local_type', 'payment')
                ->where('qbo_id', $qboId)
                ->where('local_uuid', $local)
                ->exists();
            if (!$kept) {
                continue;
            }
            Link::query()
                ->where('company_uuid', $company)
                ->where('realm_id', $realm)
                ->where('local_type', 'payment')
                ->where('qbo_id', $qboId)
                ->where('local_uuid', '!=', $local)
                ->delete();
        }
    }

    /**
     * QuickBooks deleted or voided this record. The local invoice is voided and its
     * link dropped. A customer or wallet is retired so catalog and pending sync
     * cannot create the remote record again. A payment link is dropped, and a paid
     * invoice it pointed at is set so the next sync does not create a replacement payment.
     */
    public function releaseRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): void
    {
        if ($companyUuid === '' || $localType === '') {
            return;
        }
        if ($this->memory !== null) {
            $this->releaseMemoryRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $uuids = $this->linkedLocalUuids($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
            if (is_string($localUuid) && $localUuid !== '' && !in_array($localUuid, $uuids, true)) {
                $uuids[] = $localUuid;
            }
            foreach ($uuids as $uuid) {
                $this->applyRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $uuid);
            }
            $this->dropRemoteLinks($companyUuid, $realmId, $localType, $quickbooksId);
        } finally {
            if (!$wasPaused) {
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
                if (is_string($localUuid) && $localUuid !== '') {
                    $method = $started ? 'orWhere' : 'where';
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
            if ($this->isMissingTable($exception)) {
                return [];
            }

            throw $exception;
        }
    }

    private function applyRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, string $localUuid): void
    {
        if ($localType === 'invoice') {
            $this->setInvoiceStatus($companyUuid, $localUuid, 'void');
            $this->dropLocalLinks($companyUuid, $realmId, 'invoice', $localUuid);
            $this->dropLocalLinks($companyUuid, $realmId, 'payment', $localUuid);
            $this->finishPending($companyUuid, 'invoice', $localUuid);

            return;
        }
        if ($localType === 'payment') {
            $this->dropLocalLinks($companyUuid, $realmId, 'payment', $localUuid);
            if ($localUuid === '' || $localUuid === $quickbooksId) {
                return;
            }
            $status = $this->invoiceStatus($companyUuid, $localUuid);
            if ($status === null) {
                return;
            }
            if (in_array(strtolower($status), ['paid', 'partial'], true)) {
                $this->setInvoiceStatus($companyUuid, $localUuid, 'sent');
            }
            $this->finishPending($companyUuid, 'invoice', $localUuid);

            return;
        }
        if ($localType === 'customer') {
            $this->retireLocal('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, $localUuid, []);
            $this->dropLocalLinks($companyUuid, $realmId, 'customer', $localUuid);
            $this->finishPending($companyUuid, 'customer', $localUuid);

            return;
        }
        if ($localType === 'wallet') {
            $this->retireLocal('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, $localUuid, ['status' => 'closed']);
            $this->dropLocalLinks($companyUuid, $realmId, 'wallet', $localUuid);
            $this->finishPending($companyUuid, 'wallet', $localUuid);
        }
    }

    private function setInvoiceStatus(string $companyUuid, string $uuid, string $status): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (!class_exists($class) || $companyUuid === '' || $uuid === '') {
            return;
        }

        try {
            $current = $this->invoiceTable($class, $companyUuid, $uuid)->value('status');
            if (!is_string($current)) {
                return;
            }
            if ($status === 'void' && in_array(strtolower($current), ['void', 'voided', 'cancelled', 'canceled'], true)) {
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
            if ($this->isUnavailableStorage($exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function invoiceStatus(string $companyUuid, string $uuid): ?string
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (!class_exists($class) || $companyUuid === '' || $uuid === '') {
            return null;
        }

        try {
            $status = $this->invoiceTable($class, $companyUuid, $uuid)->value('status');
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception)) {
                return null;
            }

            throw $exception;
        }

        return is_string($status) ? $status : null;
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
        if (!class_exists($class) || $companyUuid === '' || $uuid === '') {
            return;
        }

        try {
            $class::query()->where('company_uuid', $companyUuid)->where('uuid', $uuid)->update(array_merge($extra, [
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception)) {
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
            if ($this->isMissingTable($exception)) {
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
            if ($this->isMissingTable($exception)) {
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
            if ($this->isMissingTable($exception)) {
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

        return str_contains($message, 'no such table')
            || str_contains($message, "doesn't exist")
            || str_contains($message, 'base table or view not found')
            || str_contains($message, 'not configured');
    }

    private function releaseMemoryRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): void
    {
        if ($this->memory === null) {
            return;
        }

        $uuids = [];
        $kept  = [];
        foreach ($this->memory->links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $sameCompany = (string) ($link['company_uuid'] ?? '') === $companyUuid;
            $sameRealm   = $realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId;
            $sameType    = (string) ($link['local_type'] ?? '') === $localType;
            $sameRemote  = $quickbooksId !== '' && (string) ($link['qbo_id'] ?? '') === $quickbooksId;
            $sameLocal   = is_string($localUuid) && $localUuid !== '' && (string) ($link['local_uuid'] ?? '') === $localUuid;
            if ($sameCompany && $sameRealm && $sameType && ($sameRemote || $sameLocal)) {
                $id = (string) ($link['local_uuid'] ?? '');
                if ($id !== '') {
                    $uuids[] = $id;
                }
                continue;
            }
            $kept[] = $link;
        }
        if (is_string($localUuid) && $localUuid !== '') {
            $uuids[] = $localUuid;
        }
        $this->memory->links = $kept;
        foreach (array_values(array_unique($uuids)) as $uuid) {
            if ($localType === 'invoice') {
                if (isset($this->memory->invoices[$uuid]) && is_array($this->memory->invoices[$uuid])) {
                    $this->memory->invoices[$uuid]['status'] = 'void';
                }
                $this->memory->links = array_values(array_filter(
                    $this->memory->links,
                    static function ($link) use ($companyUuid, $realmId, $uuid): bool {
                        if (!is_array($link)) {
                            return false;
                        }
                        $payment = (string) ($link['local_type'] ?? '') === 'payment'
                            && (string) ($link['company_uuid'] ?? '') === $companyUuid
                            && ($realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId)
                            && (string) ($link['local_uuid'] ?? '') === $uuid;

                        return !$payment;
                    }
                ));
            } elseif ($localType === 'payment' && $uuid !== $quickbooksId && isset($this->memory->invoices[$uuid]) && is_array($this->memory->invoices[$uuid])) {
                $status = strtolower((string) ($this->memory->invoices[$uuid]['status'] ?? ''));
                if (in_array($status, ['paid', 'partial'], true)) {
                    $this->memory->invoices[$uuid]['status'] = 'sent';
                }
            } elseif ($localType === 'customer') {
                unset($this->memory->customers[$uuid]);
            } elseif ($localType === 'wallet') {
                unset($this->memory->wallets[$uuid]);
            }
            foreach ($this->memory->pending as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pendingType = $localType === 'payment' ? 'invoice' : $localType;
                if ((string) ($row['company_uuid'] ?? '') === $companyUuid && (string) ($row['local_type'] ?? '') === $pendingType && (string) ($row['local_uuid'] ?? '') === $uuid && (string) ($row['status'] ?? '') === 'pending') {
                    $this->memory->pending[$index]['status'] = 'done';
                }
            }
        }
        $this->memory->rebuildIndex();
    }

    /**
     * @param array<int, array<string, mixed>> $links
     */
    private function insertLinks(array $links): void
    {
        if ($links === []) {
            return;
        }

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
        $existing = [];
        foreach (array_chunk(array_column($rows, 'uuid'), 200) as $chunk) {
            foreach (Link::query()->whereIn('uuid', $chunk)->pluck('uuid') as $uuid) {
                $existing[(string) $uuid] = true;
            }
        }
        $inserts = [];
        $updates = [];
        foreach ($rows as $row) {
            if (!isset($existing[$row['uuid']])) {
                $inserts[] = $row;
                continue;
            }
            $this->releaseLinkIdentity($row, $row['uuid']);
            $updates[] = [
                'uuid'    => $row['uuid'],
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
        $this->updateByUuid(new Link(), $updates, ['company_uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_entity', 'qbo_id', 'sync_token']);
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
                if (!self::isDuplicatePendingWrite($exception)) {
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
     */
    private function updateByUuid(Model $model, array $rows, array $allowed): void
    {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['uuid'] ?? '') !== '' && ($row['columns'] ?? []) !== []
        ));
        if ($rows === []) {
            return;
        }

        $connection = $model->getConnection();
        $grammar    = $connection->getQueryGrammar();
        $table      = $grammar->wrapTable($model->getTable());
        $uuidColumn = $grammar->wrap('uuid');
        foreach (array_chunk($rows, 200) as $chunk) {
            $names = [];
            foreach ($chunk as $row) {
                foreach ($row['columns'] as $column => $value) {
                    if (in_array($column, $allowed, true)) {
                        $names[$column] = true;
                    }
                }
            }
            if ($names === []) {
                continue;
            }

            $assignments = [];
            $bindings    = [];
            foreach (array_keys($names) as $column) {
                $cases = [];
                foreach ($chunk as $row) {
                    if (!array_key_exists($column, $row['columns']) || !in_array($column, $allowed, true)) {
                        continue;
                    }
                    $cases[]    = 'WHEN ? THEN ?';
                    $bindings[] = $row['uuid'];
                    $bindings[] = $this->sqlValue($row['columns'][$column]);
                }
                if ($cases === []) {
                    continue;
                }
                $assignments[] = $grammar->wrap($column) . ' = CASE ' . $uuidColumn . ' ' . implode(' ', $cases) . ' ELSE ' . $grammar->wrap($column) . ' END';
            }
            if ($assignments === []) {
                continue;
            }
            $assignments[] = $grammar->wrap('updated_at') . ' = ?';
            $bindings[]    = Carbon::now()->toDateTimeString();
            $placeholders  = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($chunk as $row) {
                $bindings[] = $row['uuid'];
            }
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $uuidColumn . ' IN (' . $placeholders . ')';
            $connection->update($sql, $bindings);
        }
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
            if (!is_array($attempt) || !empty($attempt['uuid'])) {
                continue;
            }
            $diff = $attempt['diff'] ?? null;
            if (is_array($diff)) {
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
     *
     * @param array<int, mixed> $items
     */
    private function replaceInvoiceLines(Model $model, array $items): void
    {
        $model->items()->delete();
        $now  = Carbon::now()->toDateTimeString();
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rows[] = [
                'uuid'         => (string) Str::uuid(),
                'invoice_uuid' => (string) $model->getAttribute('uuid'),
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

        $model->items()->insert($rows);
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

        $now  = Carbon::now()->toDateTimeString();
        $rows = [];
        foreach ($customers as $customer) {
            $uuid = (string) ($customer['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $name = (string) ($customer['name'] ?? '');
            $slug = $name === '' ? null : Str::slug($name);
            $meta = $customer['meta'] ?? null;
            if (is_array($meta)) {
                $encoded = json_encode($meta);
                $meta    = $encoded === false ? null : $encoded;
            }
            $publicId = 'contact_' . strtolower(Str::random(10));
            $rows[]   = [
                'uuid'         => $uuid,
                'public_id'    => $publicId,
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
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $class::query()->insert($chunk);
        }
    }
}
