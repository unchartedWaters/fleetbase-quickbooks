<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Open reconcile rows (priority 1) in the test database, and the update statements a callback runs.
 */
class ReconcileBacklog
{
    /**
     * @param array<int, string> $localUuids
     */
    public static function add(string $localType, array $localUuids, string $companyUuid = 'company-uuid'): void
    {
        foreach ($localUuids as $uuid) {
            DB::table('quickbooks_pending_syncs')->insert([
                'uuid'         => 'rec-' . $uuid,
                'company_uuid' => $companyUuid,
                'local_type'   => $localType,
                'local_uuid'   => $uuid,
                'reason'       => 'reconcile',
                'status'       => 'pending',
                'priority'     => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    /**
     * @return array<string, int> each open row's priority, by local uuid
     */
    public static function priorities(): array
    {
        $rows = DB::table('quickbooks_pending_syncs')->where('status', 'pending')->orderBy('local_uuid')->get();
        $out  = [];
        foreach ($rows as $row) {
            $out[(string) $row->local_uuid] = (int) $row->priority;
        }

        return $out;
    }

    /**
     * @return array<int, string> the update statements the callback ran
     */
    public static function updatesDuring(callable $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $callback();
        } finally {
            $connection->disableQueryLog();
        }
        $sql = array_column($connection->getQueryLog(), 'query');

        return array_values(array_filter($sql, static fn (string $query): bool => str_starts_with(
            strtolower(ltrim($query)),
            'update'
        )));
    }
}
