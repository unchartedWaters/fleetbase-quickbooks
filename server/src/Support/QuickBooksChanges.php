<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Whether QuickBooks changed a record since the last sync. Every QuickBooks write raises the
 * record's SyncToken, and each sync stores the token it left both sides equal with on the
 * link, so a different token means the record was changed in QuickBooks meanwhile.
 *
 * A link that stores no token (an imported match, a QuickBooks merge), or that points at
 * another QuickBooks record, gives no baseline. That counts as a QuickBooks change, so
 * QuickBooks as Primary keeps winning there.
 */
class QuickBooksChanges
{
    /**
     * @param array<string, mixed>|null $link   the link as it was before this sync
     * @param array<string, mixed>      $remote the QuickBooks record as read now
     */
    public static function since(?array $link, array $remote): bool
    {
        if ($link === null || trim((string) ($link['qbo_id'] ?? '')) !== trim((string) ($remote['Id'] ?? ''))) {
            return true;
        }
        $stored  = trim((string) ($link['sync_token'] ?? ''));
        $current = trim((string) ($remote['SyncToken'] ?? ''));

        return $stored === '' || $current === '' || $stored !== $current;
    }
}
