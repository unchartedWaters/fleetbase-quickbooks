<?php

namespace Fleetbase\Quickbooks\Models;

class PendingSync extends QuickbooksModel
{
    protected $table = 'quickbooks_pending_syncs';

    /** @var array<string, string> */
    protected $casts = [
        'next_attempt_at' => 'datetime',
        'attempts'        => 'integer',
    ];
}
