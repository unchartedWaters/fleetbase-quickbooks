<?php

namespace Fleetbase\Quickbooks\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Observers\FlagCustomerObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceItemObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Observers\FlagWalletObserver;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Support\Facades\Event;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Extension cannot be loaded without `fleetbase/core-api` installed!');
}

class QuickbooksServiceProvider extends CoreServiceProvider
{
    /**
     * @var array<int, class-string>
     */
    public $commands = [
        SyncQuickbooks::class,
    ];

    /**
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
        $this->mergeConfigFrom(__DIR__ . '/../../config/quickbooks.php', 'quickbooks');
        $this->app->singleton(Authorizer::class);
    }

    /**
     * @return void
     */
    public function boot()
    {
        // registerCommands and scheduleCommands are what publish the batch command.
        // CoreServiceProvider::boot reloads core routes and migrations, so this provider
        // follows the Fleet-Ops pattern instead of calling it a second time.
        $this->registerCommands();
        $this->scheduleCommands(function ($schedule) {
            // everyMinute stays so a 1-minute frequency can still run.
            // when() is what keeps an idle minute from starting the command.
            $schedule
                ->command('quickbooks:sync')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('quickbooks-sync')
                ->when(static fn (): bool => SyncSchedule::shouldStart(time()));
        });
        $this->registerObservers();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        if (class_exists(NotificationRegistry::class)) {
            // The registry's parameter is annotated `sarray`, which PHPStan cannot resolve.
            /* @phpstan-ignore argument.type */
            NotificationRegistry::register(QuickbooksNeedsReauth::class);
        }

        // Listeners only flag a pending row. Ledger events are strings so the
        // extension still loads when Ledger is absent.
        Event::listen('Fleetbase\\Ledger\\Events\\InvoiceCreated', [FlagInvoiceListener::class, 'handle']);
        Event::listen('Fleetbase\\Ledger\\Events\\InvoicePaid', [FlagInvoiceListener::class, 'handle']);
        $this->app->singleton(EnqueueWebhookSync::class);
        Event::listen(QuickBooksEntityChanged::class, [EnqueueWebhookSync::class, 'handle']);

        $this->observeFleetbase();
    }

    private function observeFleetbase(): void
    {
        $customer = 'Fleetbase\\FleetOps\\Models\\Customer';
        if (class_exists($customer)) {
            $customer::observe(FlagCustomerObserver::class);
        }

        $invoice = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($invoice)) {
            $invoice::observe(FlagInvoiceObserver::class);
        }

        $item = 'Fleetbase\\Ledger\\Models\\InvoiceItem';
        if (class_exists($item)) {
            $item::observe(FlagInvoiceItemObserver::class);
        }

        $wallet = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($wallet)) {
            $wallet::observe(FlagWalletObserver::class);
        }
    }
}
