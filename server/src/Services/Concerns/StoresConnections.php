<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\SafeLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * FleetbaseDirectory's connection storage: reading and saving the QuickBooks connection row,
 * token and rate-limit columns, and the reconnect notice. It shares the directory's state, so it
 * lives here only to keep FleetbaseDirectory.php readable.
 */
trait StoresConnections
{
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
     * Record a run that stopped before it synced anything as failed, with its reason.
     */
    public function saveFailed(string $companyUuid, string $trigger, string $direction, string $message): void
    {
        $ledger = $this->memory ?? new SyncLedger();
        $ledger->fail($companyUuid, $trigger, $direction, $message, time());
        $this->save($ledger);
    }

    /**
     * Persist the rate limit QuickBooks asked for on this connection right away.
     *
     * @param array<string, mixed> $connection
     */
    public function saveRateLimit(array $connection): void
    {
        $fields = ['rate_limited_until', 'last_rate_limit_wait'];
        if ($this->memory !== null) {
            $companyUuid = (string) ($connection['company_uuid'] ?? '');
            if (isset($this->memory->connections[$companyUuid]) === true) {
                $limit   = array_intersect_key($connection, array_flip($fields));
                $current = $this->memory->connections[$companyUuid];

                $this->memory->connections[$companyUuid] = array_merge($current, $limit);
            }

            return;
        }

        $this->updateConnection($connection, $fields);
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
        $cacheKey    = self::reauthNoticeKey($companyUuid);
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
     * A successful connect or reconnect forgets the hourly notice, so a new failure after
     * it tells the company again instead of waiting out the hour.
     */
    public static function forgetReauthNotice(string $companyUuid): void
    {
        Cache::forget(self::reauthNoticeKey($companyUuid));
    }

    private static function reauthNoticeKey(string $companyUuid): string
    {
        return 'quickbooks.reauth-notified.' . $companyUuid;
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
}
