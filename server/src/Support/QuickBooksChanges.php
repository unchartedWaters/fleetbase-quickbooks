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
 *
 * A first link that left Fleetbase values QuickBooks lacks stores a provisional token (the
 * token behind PROVISIONAL). It still shows whether QuickBooks changed the record, but it is
 * no baseline: a QuickBooks change copied meanwhile empties nothing in Fleetbase.
 */
class QuickBooksChanges
{
    public const PROVISIONAL = '~';

    /**
     * @param array<string, mixed>|null $link   the link as it was before this sync
     * @param array<string, mixed>      $remote the QuickBooks record as read now
     */
    public static function since(?array $link, array $remote): bool
    {
        if ($link === null || trim((string) ($link['qbo_id'] ?? '')) !== trim((string) ($remote['Id'] ?? ''))) {
            return true;
        }
        $stored  = self::stored($link);
        $current = trim((string) ($remote['SyncToken'] ?? ''));

        return $stored === '' || $current === '' || $stored !== $current;
    }

    /**
     * Whether the link holds a token for this QuickBooks record, that is a sync once left both
     * sides equal. A link without one, or with a provisional one, gives no baseline: the next
     * sync merges.
     *
     * @param array<string, mixed>|null $link
     * @param array<string, mixed>      $remote
     */
    public static function known(?array $link, array $remote): bool
    {
        $token = trim((string) ($link['sync_token'] ?? ''));

        return $link !== null
            && trim((string) ($link['qbo_id'] ?? '')) === trim((string) ($remote['Id'] ?? ''))
            && $token !== ''
            && str_starts_with($token, self::PROVISIONAL) === false;
    }

    /**
     * Whether the link stores a provisional token: a first link whose Fleetbase values were not
     * all sent yet.
     *
     * @param array<string, mixed>|null $link
     */
    public static function provisionalLink(?array $link): bool
    {
        $token = $link === null ? '' : trim((string) ($link['sync_token'] ?? ''));

        return str_starts_with($token, self::PROVISIONAL) === true;
    }

    /**
     * The token a first link stores while Fleetbase still holds values QuickBooks lacks.
     */
    public static function provisional(string $token): string
    {
        return self::PROVISIONAL . $token;
    }

    /**
     * The QuickBooks token a link stores, provisional or not.
     *
     * @param array<string, mixed> $link
     */
    private static function stored(array $link): string
    {
        $token = trim((string) ($link['sync_token'] ?? ''));

        return str_starts_with($token, self::PROVISIONAL) === true ? substr($token, strlen(self::PROVISIONAL)) : $token;
    }
}
