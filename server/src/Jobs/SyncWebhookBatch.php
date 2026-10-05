<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Services\BatchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SyncWebhookBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    /**
     * @param array<int, array{local_type: string, local_uuid: string}> $records
     */
    public function __construct(public string $companyUuid, public array $records)
    {
    }

    /**
     * @param array<int, array{local_type: string, local_uuid: string}> $records
     */
    public static function dispatch(string $companyUuid, array $records): void
    {
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher) {
            $dispatcher->dispatch(new self($companyUuid, $records));
        }
    }

    public function handle(): void
    {
        if (!$this->organizationIsConnected()) {
            return;
        }

        $this->insertPending();
        SyncCompanyBatch::dispatch($this->companyUuid, 'now');
    }

    /**
     * This organization can sync when it has its own usable connection, or when
     * the install has exactly one usable connection and this organization shares it.
     * A second connection row is not borrowed.
     */
    private function organizationIsConnected(): bool
    {
        $own = Connection::query()->where('company_uuid', $this->companyUuid)->first();
        if ($own instanceof Connection) {
            return $this->connectionIsUsable($own);
        }

        $rows = Connection::query()->limit(2)->get();
        if ($rows->count() !== 1) {
            return false;
        }
        $shared = $rows->first();

        return $shared instanceof Connection && $this->connectionIsUsable($shared);
    }

    private function connectionIsUsable(Connection $connection): bool
    {
        $realm = $connection->realm_id;

        return $connection->needs_reauth !== true && is_string($realm) && $realm !== '';
    }

    private function insertPending(): void
    {
        $wanted = [];
        foreach ($this->records as $record) {
            $type = $record['local_type'] ?? '';
            $uuid = $record['local_uuid'] ?? '';
            if (!is_string($type) || $type === '' || !is_string($uuid) || $uuid === '') {
                continue;
            }
            $wanted[$type . '|' . $uuid] = ['local_type' => $type, 'local_uuid' => $uuid];
        }
        if ($wanted === []) {
            return;
        }

        $existing = PendingSync::query()
            ->where('company_uuid', $this->companyUuid)
            ->where('status', 'pending')
            ->whereIn('local_uuid', array_values(array_unique(array_column($wanted, 'local_uuid'))))
            ->get(['local_type', 'local_uuid']);

        foreach ($existing as $row) {
            unset($wanted[(string) $row->local_type . '|' . (string) $row->local_uuid]);
        }
        if ($wanted === []) {
            return;
        }

        $now  = now();
        $rows = [];
        foreach ($wanted as $record) {
            $rows[] = [
                'uuid'            => (string) Str::uuid(),
                'company_uuid'    => $this->companyUuid,
                'local_type'      => $record['local_type'],
                'local_uuid'      => $record['local_uuid'],
                'reason'          => 'webhook',
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        try {
            PendingSync::query()->insert($rows);
        } catch (QueryException $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            // One duplicate rolls the whole statement back. Save the other rows,
            // and ignore only a duplicate-key failure on each of them.
            foreach ($rows as $row) {
                try {
                    PendingSync::query()->insert($row);
                } catch (QueryException $rowException) {
                    $this->ignoreDuplicateOrThrow($rowException);
                }
            }
        }
    }

    private function ignoreDuplicateOrThrow(QueryException $exception): void
    {
        if (!$this->isDuplicateKey($exception)) {
            throw $exception;
        }
    }

    /**
     * Unique-key races on the open pending-sync identity are harmless.
     * Other integrity violations must fail the job so they can be investigated.
     */
    private function isDuplicateKey(QueryException $exception): bool
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

        // SQLite uses 19 for generic constraints and 1555/2067 for the
        // primary-key/unique extended codes exposed by some PDO versions.
        return in_array($driverCode, [19, 1555, 2067], true)
            && preg_match('/(?:UNIQUE|PRIMARY KEY) constraint failed/i', $message) === 1;
    }
}
