<?php

namespace Fleetbase\Quickbooks\Models;

class SyncAttempt extends QuickbooksModel
{
    protected $table = 'quickbooks_sync_attempts';

    /** @var array<string, string> */
    protected $casts = [
        'diff' => 'array',
    ];
}
