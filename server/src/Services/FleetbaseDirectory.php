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
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

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
        $connection = $ledger->connection($companyUuid);
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
        $connection = $ledger->connection($companyUuid);
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

        $ids = $this->inScopeInvoiceIds($companyUuid, max(1, $limit), $afterUuid);
        foreach ($ids as $uuid) {
            $this->writePending($this->catalogPending($companyUuid, 'invoice', $uuid));
        }

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
            $key = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
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
        if ($this->memory !== null) {
            return;
        }

        foreach ($this->catalogIds('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, true) as $uuid) {
            $this->writePending($this->catalogPending($companyUuid, 'customer', $uuid));
        }
        foreach ($this->catalogIds('Fleetbase\\Ledger\\Models\\Invoice', $companyUuid, false) as $uuid) {
            $this->writePending($this->catalogPending($companyUuid, 'invoice', $uuid));
        }
        foreach ($this->catalogIds('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, false) as $uuid) {
            $this->writePending($this->catalogPending($companyUuid, 'wallet', $uuid));
        }
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
        foreach ($ledger->connections as $connection) {
            $this->writeConnection($connection);
        }

        // A disconnect or reconnect during the batch must not mark the old
        // pending rows done or point links at the realm loaded at batch start.
        // writeConnection already no-ops in those cases; these rows do not.
        $skipped = $this->companiesSkippingLedgerWrites($ledger);

        foreach ($ledger->links as $link) {
            if (isset($skipped[(string) ($link['company_uuid'] ?? '')])) {
                continue;
            }
            $key = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
            if (self::changedColumns($link, $this->loaded['links'][$key] ?? null, ['realm_id', 'qbo_entity', 'qbo_id', 'sync_token']) === []
                && isset($this->loaded['links'][$key])) {
                continue;
            }
            Link::query()->updateOrCreate(
                [
                    'company_uuid' => $link['company_uuid'],
                    'local_type'   => $link['local_type'],
                    'local_uuid'   => $link['local_uuid'],
                ],
                $link
            );
        }
        foreach ($ledger->pending as $row) {
            if (isset($skipped[(string) ($row['company_uuid'] ?? '')])) {
                continue;
            }
            if (empty($row['uuid'])) {
                $this->writePending($row);
                continue;
            }
            $this->writeLoadedPending($row, $this->loaded['pending'][(string) $row['uuid']] ?? null);
        }
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
        foreach ($ledger->attempts as $attempt) {
            if (!empty($attempt['uuid'])) {
                continue;
            }
            $record = new SyncAttempt();
            $record->fill($attempt);
            $record->save();
        }
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
     * @return array<string, mixed>|null
     */
    public function connection(string $companyUuid): ?array
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
     * @param array<string, mixed> $row
     */
    /**
     * Update a pending row the batch already loaded. A missing row is not inserted,
     * so a uuid from an earlier load cannot collide with a row created since then.
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $loaded
     */
    private function writeLoadedPending(array $row, ?array $loaded): void
    {
        $uuid = (string) ($row['uuid'] ?? '');
        if ($uuid === '') {
            return;
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
        if ($columns === []) {
            return;
        }

        PendingSync::query()->whereKey($uuid)->update(self::toDates($columns, self::PENDING_TIMES));
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
            $pending = self::toTimestamps($row->toArray(), self::PENDING_TIMES);
            $ledger->pending[] = $pending;
            $this->loaded['pending'][(string) $row->uuid] = $pending;
            $localUuid = (string) $row->local_uuid;
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
     * @param array<string, mixed> $link
     */
    private function rememberLoadedLink(SyncLedger $ledger, array $link): void
    {
        $ledger->links[] = $link;
        $key = (string) ($link['company_uuid'] ?? '') . '|' . (string) ($link['local_type'] ?? '') . '|' . (string) ($link['local_uuid'] ?? '');
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
     * @param string|null          $storedRealm
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

        $existing    = (new Connection())->newQuery()->where('company_uuid', $companyUuid)->first();
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
     * @param array<int, string>     $flaggedUuids
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

        foreach ($ledger->customers as $customer) {
            if (isset($skipped[(string) ($customer['company_uuid'] ?? '')])) {
                continue;
            }
            if (empty($customer['uuid']) || empty($customer['company_uuid'])) {
                continue;
            }
            // Only fields the engine changed are written, so an edit during the batch stays.
            $values = self::changedColumns($customer, $this->loaded['customers'][(string) $customer['uuid']] ?? null, ['name', 'email', 'phone', 'notes']);
            if ($values === []) {
                continue;
            }
            /** @var Model|null $model */
            $model = $class::query()
                ->where('uuid', $customer['uuid'])
                ->where('company_uuid', $customer['company_uuid'])
                ->first();
            if ($model === null) {
                $model = new $class();
                $model->forceFill([
                    'uuid'         => $customer['uuid'],
                    'company_uuid' => $customer['company_uuid'],
                    'type'         => 'customer',
                    'meta'         => $customer['meta'] ?? null,
                ]);
            }
            $model->fill($values);
            if (!$model->exists || $model->isDirty()) {
                $model->save();
            }
        }
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
                $model->tax          = (int) ($invoice['tax'] ?? 0);
                $model->total_amount = (int) ($invoice['total'] ?? 0);
                $model->subtotal     = max(0, (int) $model->total_amount - (int) $model->tax);
                $model->balance      = (int) $model->total_amount - (int) ($invoice['amount_paid'] ?? $model->amount_paid);
                if (!empty($invoice['status'])) {
                    $model->status = (string) $invoice['status'];
                }
                if (!empty($invoice['items_from_quickbooks']) && is_array($invoice['items'] ?? null)) {
                    foreach ($model->items()->get() as $existing) {
                        if ($existing instanceof Model) {
                            $existing->delete();
                        }
                    }
                    foreach ($invoice['items'] as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $model->items()->create([
                            'description' => (string) ($item['description'] ?? ''),
                            'quantity'    => (int) ($item['quantity'] ?? 1),
                            'unit_price'  => (int) ($item['unit_price'] ?? 0),
                            'amount'      => (int) ($item['amount'] ?? 0),
                        ]);
                    }
                }
            }
            if (!empty($invoice['payment_from_quickbooks'])) {
                if (array_key_exists('amount_paid', $invoice)) {
                    $model->amount_paid = (int) $invoice['amount_paid'];
                }
                if (!empty($invoice['paid_at'])) {
                    $model->paid_at = $invoice['paid_at'];
                }
                if (!empty($invoice['status'])) {
                    $model->status = (string) $invoice['status'];
                }
                $model->balance = (int) $model->total_amount - (int) $model->amount_paid;
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
     * @return array<int, string>
     */
    private function catalogIds(string $class, string $companyUuid, bool $customersOnly): array
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

        $ids = [];
        foreach ($query->pluck('uuid') as $uuid) {
            $ids[] = (string) $uuid;
        }

        return $ids;
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
}
