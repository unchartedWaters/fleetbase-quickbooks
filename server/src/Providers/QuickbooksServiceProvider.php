<?php

namespace Fleetbase\Quickbooks\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Listeners\FlagWalletListener;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Observers\FlagCustomerObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceItemObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Observers\FlagPlaceObserver;
use Fleetbase\Quickbooks\Observers\FlagWalletObserver;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Support\Facades\Event;

if (class_exists(CoreServiceProvider::class) === false) {
    throw new \Exception('Extension cannot be loaded without `fleetbase/core-api` installed!');
}

class QuickbooksServiceProvider extends CoreServiceProvider
{
    /**
     * Core observers stay registered by CoreServiceProvider. Leaving this
     * inherited would register them again from this extension.
     *
     * @var array<string, class-string>
     */
    public $observers = [];

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
        // One instance each, so the directory inside remembers which organizations have no
        // connection between saves instead of asking the database on every one.
        $this->app->singleton(FlagCustomerListener::class);
        $this->app->singleton(FlagInvoiceListener::class);
        $this->app->singleton(FlagWalletListener::class);
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
            // when() skips the command entirely unless a connection is active,
            // so schedule:run does not log a sync that has nothing to do.
            $schedule
                ->command('quickbooks:sync')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('quickbooks-sync')
                ->when(static fn (): bool => SyncSchedule::shouldRun(time(), ConnectionGate::hasActiveConnection()));
        });
        $this->registerObservers();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        if (class_exists(NotificationRegistry::class) === true) {
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
        if (class_exists($customer) === true) {
            $customer::observe(FlagCustomerObserver::class);
        }

        $place = 'Fleetbase\\FleetOps\\Models\\Place';
        if (class_exists($place) === true) {
            $place::observe(FlagPlaceObserver::class);
        }

        $invoice = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($invoice) === true) {
            $invoice::observe(FlagInvoiceObserver::class);
        }

        $item = 'Fleetbase\\Ledger\\Models\\InvoiceItem';
        if (class_exists($item) === true) {
            $item::observe(FlagInvoiceItemObserver::class);
        }

        $wallet = 'Fleetbase\\Ledger\\Models\\Wallet';
        if (class_exists($wallet) === true) {
            $wallet::observe(FlagWalletObserver::class);
        }
    }
}
