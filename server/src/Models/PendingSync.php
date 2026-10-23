<?php

namespace Fleetbase\Quickbooks\Models;

use Illuminate\Database\Query\Builder;

class PendingSync extends QuickbooksModel
{
    protected $table = 'quickbooks_pending_syncs';

    /** @var array<string, string> */
    protected $casts = [
        'next_attempt_at' => 'datetime',
        'attempts'        => 'integer',
    ];

    /**
     * Records of this company that could not be sent. Failed rows are kept, so a record
     * flagged again after it failed (now waiting, or sent since) is not counted.
     */
    public static function failedRecordCount(string $companyUuid): int
    {
        $table = (new self())->getTable();

        return self::query()
            ->where('company_uuid', $companyUuid)
            ->where('status', 'failed')
            ->whereNotExists(static function (Builder $later) use ($table): void {
                $later->selectRaw('1')
                    ->from($table . ' as later')
                    ->whereColumn('later.company_uuid', $table . '.company_uuid')
                    ->whereColumn('later.local_type', $table . '.local_type')
                    ->whereColumn('later.local_uuid', $table . '.local_uuid')
                    ->whereIn('later.status', ['pending', 'done'])
                    ->whereColumn('later.created_at', '>=', $table . '.updated_at');
            })
            ->distinct()
            ->count('local_uuid');
    }
}
