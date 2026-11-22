<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\Batch;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\SafeLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's pending queue: flagging records, due counts, claims and leases, and
 * recovering a failed save. It shares the directory's state, so it lives here only to keep
 * FleetbaseDirectory.php readable.
 */
trait QueuesPending
{
    /**
     * @param array<int, array{uuid: string, columns: array<string, mixed>}> $changedPending
     */
    private function updatePendingRows(array $changedPending): void
    {
        $pendingColumns = [
            'company_uuid', 'local_type', 'local_uuid', 'reason', 'status', 'attempts', 'next_attempt_at',
        ];
        // A row flagged again during this run stays pending for the next run, but still records
        // the attempt and backoff, so a row that keeps failing cannot skip its backoff. An
        // expired re-flag mark (its run died) no longer holds the row.
        $now    = Carbon::now()->toDateTimeString();
        $reflag = self::REFLAG_PREFIX . '%';
        $this->updateByUuid(new PendingSync(), $changedPending, $pendingColumns, [
            '(claimed_by IS NULL OR claimed_by NOT LIKE ? OR claimed_until IS NULL OR claimed_until <= ?)',
            [$reflag, $now],
        ]);
        $this->updateByUuid(new PendingSync(), $changedPending, ['attempts', 'next_attempt_at'], [
            'claimed_by LIKE ? AND claimed_until > ?',
            [$reflag, $now],
        ]);
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
                $query->whereNull('next_attempt_at')->orWhere(
                    'next_attempt_at',
                    '<=',
                    Carbon::createFromTimestamp($now)
                );
            });
        // A row another run holds is not due work for this one.
        $this->excludeClaimed($query, Carbon::createFromTimestamp($now));

        return (int) $query->count();
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function writePending(array $row): void
    {
        $values             = self::toDates($row, self::PENDING_TIMES);
        $priority           = SyncLedger::pendingPriority($row);
        $values['priority'] = $priority;
        try {
            $pending = PendingSync::query()->firstOrCreate(
                [
                    'company_uuid' => $row['company_uuid'],
                    'local_type'   => $row['local_type'],
                    'local_uuid'   => $row['local_uuid'],
                    'status'       => 'pending',
                ],
                $values
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            if (self::isDuplicatePendingWrite($exception) === false) {
                throw $exception;
            }

            return;
        }
        if ($pending instanceof PendingSync === true && $pending->wasRecentlyCreated === false) {
            $this->markReflagged($pending);
            $this->raisePriority($pending, $priority);
        }
    }

    /**
     * An edit to a record already waiting in the reconcile backlog moves it ahead of that backlog.
     */
    private function raisePriority(PendingSync $pending, ?int $priority): void
    {
        if ($priority === null || (int) ($pending->priority ?? 0) <= $priority) {
            return;
        }

        PendingSync::query()->where('uuid', (string) $pending->uuid)->update(['priority' => $priority]);
    }

    /**
     * A running sync holds this row and may already have read the record. Mark the lease
     * so that run leaves the row pending instead of marking it done; the lease itself is
     * unchanged, so no other run takes the row meanwhile.
     */
    private function markReflagged(PendingSync $pending): void
    {
        $holder = (string) ($pending->claimed_by ?? '');
        if (
            $holder === ''
            || str_starts_with($holder, self::REFLAG_PREFIX) === true
        ) {
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

    private function claimSeconds(): int
    {
        return max(60, (int) config('quickbooks.sync.claim_seconds', 900));
    }

    /**
     * Rows with no lease, or a lease that has run out.
     *
     * @param Builder<Model>|QueryBuilder $query
     */
    private function excludeClaimed($query, Carbon $moment): void
    {
        $query->where(function (Builder|QueryBuilder $claim) use ($moment): void {
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

        $claimed = PendingSync::query()->where('claimed_by', $token)->pluck('uuid')->all();
        $mine    = array_flip(array_map(static fn (mixed $uuid): string => (string) $uuid, $claimed));

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
            SafeLog::warning(
                'QuickBooks could not release its pending-row lease; it will expire on its own.',
                ['exception' => $exception::class]
            );
        }
    }

    /**
     * save() threw, so nothing of this run's pending rows, attempts or batch was stored. Give
     * the rows this run leased one more attempt and the normal backoff, release them, and
     * record a failed batch. Without this a save error that repeats would send the same rows
     * again each time the lease expires, with no attempt count and nothing in Activity.
     * Each step is a separate small write, and none of them throws.
     *
     * @param array<string, mixed> $settings resolved sync settings (retry_limit, default_backoff_seconds)
     */
    public function recoverFailedSave(string $companyUuid, string $trigger, array $settings, int $now): void
    {
        if ($this->memory !== null) {
            $this->releaseClaims();

            return;
        }

        $deferred = 0;
        try {
            $deferred = $this->deferClaimedRows($settings, $now);
        } catch (\Throwable $exception) {
            SafeLog::warning(
                'QuickBooks could not back off pending rows after a failed save.',
                ['company_uuid' => $companyUuid, 'exception' => $exception::class]
            );
        }
        $this->releaseClaims();

        try {
            $this->recordFailedSave($companyUuid, $trigger, $deferred, $now);
        } catch (\Throwable $exception) {
            SafeLog::warning(
                'QuickBooks could not record a failed save in Activity.',
                ['company_uuid' => $companyUuid, 'exception' => $exception::class]
            );
        }
    }

    /**
     * One more attempt and the normal backoff for each row this directory leased, as a failed
     * send gets. A row past retry_limit stops. A row flagged again while leased keeps its status
     * so the new change is still sent. Returns how many rows were backed off.
     *
     * @param array<string, mixed> $settings
     */
    private function deferClaimedRows(array $settings, int $now): int
    {
        if ($this->claimTokens === []) {
            return 0;
        }

        $held = $this->claimTokens;
        foreach ($this->claimTokens as $token) {
            $held[] = self::REFLAG_PREFIX . $token;
        }
        $backoff = new BackoffPolicy();
        $base    = (int) ($settings['default_backoff_seconds'] ?? 30);
        $limit   = (int) ($settings['retry_limit'] ?? 5);
        $groups  = [];
        foreach (PendingSync::query()->whereIn('claimed_by', $held)->get(['uuid', 'attempts', 'claimed_by']) as $row) {
            /** @var PendingSync $row */
            $attempts  = (int) $row->attempts + 1;
            $reflagged = str_starts_with((string) $row->claimed_by, self::REFLAG_PREFIX);
            $columns   = [
                'attempts'        => $attempts,
                'next_attempt_at' => Carbon::createFromTimestamp($now + $backoff->defaultWait($base, $attempts)),
            ];
            if ($reflagged === false && $attempts > $limit) {
                $columns['status'] = 'failed';
            }
            $key                     = $attempts . '|' . ($reflagged === true ? 'reflag' : 'held');
            $groups[$key]['columns'] = $columns;
            $groups[$key]['uuids'][] = (string) $row->uuid;
        }

        $count = 0;
        foreach ($groups as $group) {
            PendingSync::query()->whereIn('uuid', $group['uuids'])->update($group['columns']);
            $count += count($group['uuids']);
        }

        return $count;
    }

    private function recordFailedSave(string $companyUuid, string $trigger, int $deferred, int $now): void
    {
        $ledger             = new SyncLedger();
        $batch              = Batch::forCompany($companyUuid, $trigger, 'failed', 'outbound', $now, $now);
        $batch->failed      = $deferred;
        $ledger->batches[]  = $batch;
        $ledger->attempts[] = [
            'company_uuid' => $companyUuid,
            'local_type'   => 'connection',
            'local_uuid'   => $companyUuid,
            'outcome'      => 'failed',
            'error'        => 'quickbooks.messages.save-failed',
        ];
        $this->insertBatchAttempts($ledger);
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
        if ($uuids === [] || $this->memory !== null) {
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

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writePendingMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now      = Carbon::now()->toDateTimeString();
        $payload  = [];
        foreach ($rows as $row) {
            $companyUuid = (string) ($row['company_uuid'] ?? '');
            $localType   = (string) ($row['local_type'] ?? '');
            $localUuid   = (string) ($row['local_uuid'] ?? '');
            if ($companyUuid === '' || $localType === '' || $localUuid === '') {
                continue;
            }
            $insert = [
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
                'priority'        => SyncLedger::pendingPriority($row),
            ];
            $payload[] = $insert;
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
        $this->raiseOpenRows(array_filter($payload, static fn (array $row): bool => $row['priority'] === 0));
    }

    /**
     * An insert ignored for a record that already has an open row leaves that row's priority.
     * An edit (priority 0) that reaches a reconcile row moves it ahead of the backlog, in one
     * update for the whole call.
     *
     * @param array<int, array<string, mixed>> $edits
     */
    private function raiseOpenRows(array $edits): void
    {
        $groups = [];
        foreach ($edits as $row) {
            $groups[$row['company_uuid'] . '|' . $row['local_type']][] = (string) $row['local_uuid'];
        }
        if ($groups === []) {
            return;
        }

        PendingSync::query()
            ->where('status', 'pending')
            ->where('priority', '>', 0)
            ->where(function ($query) use ($groups): void {
                foreach ($groups as $key => $uuids) {
                    [$companyUuid, $localType] = explode('|', $key, 2);
                    $query->orWhere(function ($group) use ($companyUuid, $localType, $uuids): void {
                        $group->where('company_uuid', $companyUuid)
                            ->where('local_type', $localType)
                            ->whereIn('local_uuid', $uuids);
                    });
                }
            })
            ->update(['priority' => 0]);
    }
}
