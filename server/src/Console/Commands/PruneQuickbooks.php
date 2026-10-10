<?php

namespace Fleetbase\Quickbooks\Console\Commands;

use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PruneQuickbooks extends Command
{
    /** Rows deleted per statement, so a large backlog never holds one long lock. */
    private const CHUNK = 1000;

    protected $signature = 'quickbooks:prune';

    protected $description = 'Delete old QuickBooks sync history: attempts, finished batches without attempts, and done pending rows.';

    public function handle(): int
    {
        $now      = Carbon::now();
        $attempts = $this->prune(SyncAttempt::class, $this->cutoff('attempt_days', 90, $now), null);
        $batches  = $this->prune(SyncBatch::class, $this->cutoff('batch_days', 180, $now), function ($query): void {
            $query->where('status', 'finished')
                ->whereNotExists(function ($attempts): void {
                    $attempts->selectRaw('1')
                        ->from((new SyncAttempt())->getTable())
                        ->whereColumn((new SyncAttempt())->getTable() . '.batch_uuid', (new SyncBatch())->getTable() . '.uuid');
                });
        });
        $pending = $this->prune(PendingSync::class, $this->cutoff('pending_days', 30, $now), function ($query): void {
            $query->where('status', 'done');
        }, 'updated_at');

        $this->info('Pruned ' . $attempts . ' attempts, ' . $batches . ' batches, and ' . $pending . ' done pending rows.');

        return self::SUCCESS;
    }

    /**
     * A retention of 0 or less keeps that history forever.
     */
    private function cutoff(string $key, int $default, Carbon $now): ?Carbon
    {
        $days = config('quickbooks.retention.' . $key, $default);
        $days = is_numeric($days) === true ? (int) $days : $default;

        return $days > 0 ? $now->copy()->subDays($days) : null;
    }

    /**
     * @param class-string<Model>          $class
     * @param (callable(mixed): void)|null $scope
     */
    private function prune(string $class, ?Carbon $cutoff, ?callable $scope, string $column = 'created_at'): int
    {
        if ($cutoff === null) {
            return 0;
        }

        $deleted = 0;
        do {
            $query = $class::query()->where($column, '<', $cutoff);
            if ($scope !== null) {
                $scope($query);
            }
            $uuids = $query->limit(self::CHUNK)->pluck('uuid')->all();
            if ($uuids === []) {
                break;
            }
            $deleted += $class::query()->whereIn('uuid', $uuids)->delete();
        } while (count($uuids) === self::CHUNK);

        return $deleted;
    }
}
