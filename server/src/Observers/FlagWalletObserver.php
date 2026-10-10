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
        if (SyncSuppressor::paused() === true || method_exists($wallet, 'wasChanged') === false || $wallet->wasChanged(self::WATCHED) === false) {
            return;
        }

        // A failure here must never fail the wallet save.
        FlagGuard::run(static function () use ($wallet): void {
            $listener = Container::getInstance()->make(FlagWalletListener::class);
            if ($listener instanceof FlagWalletListener === true) {
                $listener->handle((object) ['wallet' => $wallet]);
            }
        }, 'wallet', ['company_uuid' => (string) ($wallet->company_uuid ?? ''), 'uuid' => (string) ($wallet->uuid ?? '')]);
    }
}
