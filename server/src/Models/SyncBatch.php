<?php

namespace Fleetbase\Quickbooks\Models;

use Illuminate\Support\Carbon;

/**
 * @property string      $company_uuid
 * @property string      $trigger
 * @property string      $direction
 * @property string      $status
 * @property int         $created_count
 * @property int         $updated_count
 * @property int         $aligned_count
 * @property int         $voided_count
 * @property int         $unmatched_count
 * @property int         $failed_count
 * @property int         $linked_count
 * @property int         $skipped_count
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
class SyncBatch extends QuickbooksModel
{
    protected $table = 'quickbooks_sync_batches';

    /** @var array<string, string> */
    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
