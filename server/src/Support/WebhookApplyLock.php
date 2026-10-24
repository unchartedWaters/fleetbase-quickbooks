<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Services\BatchRunner;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * One webhook apply per company at a time. The company lock is released for every QuickBooks
 * call, so without this two applies could each read QuickBooks and the older read could be
 * saved over the newer one. Separate from the company lock, which batches also take.
 */
class WebhookApplyLock
{
    private const PREFIX = 'quickbooks.webhook-apply.';

    /**
     * Null when no cache store with locks is bound; the apply then runs as before.
     */
    public static function for(string $companyUuid): ?Lock
    {
        try {
            $cache = Cache::getFacadeRoot();
        } catch (\Throwable) {
            return null;
        }
        if ($cache instanceof Factory) {
            $cache = $cache->store();
        }
        $store = is_object($cache) === true && method_exists($cache, 'getStore') === true ? $cache->getStore() : null;
        if ($store instanceof LockProvider) {
            return $store->lock(self::PREFIX . $companyUuid, BatchRunner::LOCK_SECONDS);
        }

        return null;
    }
}
