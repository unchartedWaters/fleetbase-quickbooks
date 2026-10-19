<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagWalletListener;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;

class FlagWalletObserver
{
    private const WATCHED = ['name', 'description', 'currency', 'status', 'public_id'];

    public function saved(object $wallet): void
    {
        if (method_exists($wallet, 'wasChanged') === false || $wallet->wasChanged(self::WATCHED) === false) {
            return;
        }

        $this->flag($wallet);
    }

    /**
     * A soft delete fires neither saved nor updated. Flagging lets the engine make the
     * QuickBooks account inactive.
     */
    public function deleted(object $wallet): void
    {
        $this->flag($wallet);
    }

    private function flag(object $wallet): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        // A failure here must never fail the wallet save or delete.
        FlagGuard::run(static function () use ($wallet): void {
            $listener = Container::getInstance()->make(FlagWalletListener::class);
            if ($listener instanceof FlagWalletListener === true) {
                $listener->handle((object) ['wallet' => $wallet]);
            }
        }, 'wallet', ['company_uuid' => (string) ($wallet->company_uuid ?? ''), 'uuid' => (string) ($wallet->uuid ?? '')]);
    }
}
