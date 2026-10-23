<?php

namespace Fleetbase\Quickbooks\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Quickbooks\Console\Commands\PruneQuickbooks;
use Fleetbase\Quickbooks\Console\Commands\SyncQuickbooks;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;
use Fleetbase\Quickbooks\Listeners\FlagCustomerListener;
use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Listeners\FlagProductListener;
use Fleetbase\Quickbooks\Listeners\FlagWalletListener;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Observers\FlagCustomerObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceItemObserver;
use Fleetbase\Quickbooks\Observers\FlagInvoiceObserver;
use Fleetbase\Quickbooks\Observers\FlagPlaceObserver;
use Fleetbase\Quickbooks\Observers\FlagProductObserver;
use Fleetbase\Quickbooks\Observers\FlagWalletObserver;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

if (class_exists(CoreServiceProvider::class) === false) {
    throw new \Exception('Extension cannot be loaded without `fleetbase/core-api` installed!');
}

class QuickbooksServiceProvider extends CoreServiceProvider
{
    /**
     * Without an expiry, withoutOverlapping() keeps its lock for 24 hours, so a
     * scheduler killed mid-run would block every scheduled run for a day. A sync
     * run takes seconds and prune minutes, so short expiries are safe.
     */
    public const SYNC_OVERLAP_MINUTES = 10;

    public const PRUNE_OVERLAP_MINUTES = 120;

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
        PruneQuickbooks::class,
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
        $this->app->singleton(FlagProductListener::class);
    }

    public static function scheduleTasks(Schedule $schedule): void
    {
        // everyMinute stays so a 1-minute frequency can still run.
        // when() skips the command entirely unless a connection is active,
        // so schedule:run does not log a sync that has nothing to do.
        $schedule
            ->command('quickbooks:sync')
            ->everyMinute()
            ->withoutOverlapping(self::SYNC_OVERLAP_MINUTES)
            ->name('quickbooks-sync')
            ->when(static fn (): bool => SyncSchedule::shouldRun(time(), ConnectionGate::hasActiveConnection()));
        // History is trimmed whether or not a connection is active, so a disconnected install does not keep growing.
        $schedule
            ->command('quickbooks:prune')
            ->daily()
            ->withoutOverlapping(self::PRUNE_OVERLAP_MINUTES)
            ->name('quickbooks-prune');
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
        $this->scheduleCommands(static fn (Schedule $schedule) => self::scheduleTasks($schedule));
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
        $events = Event::getFacadeRoot();
        if ($events instanceof Dispatcher === true) {
            EnqueueWebhookSync::listen($events);
        }

        $this->observeFleetbase();
    }

    private function observeFleetbase(): void
    {
        // The Fleet-Ops console saves customers as a Contact with type "customer"; the flagger skips other types.
        foreach (['Fleetbase\\FleetOps\\Models\\Customer', 'Fleetbase\\FleetOps\\Models\\Contact'] as $customer) {
            if (class_exists($customer) === true) {
                $customer::observe(FlagCustomerObserver::class);
            }
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

        $product = 'Fleetbase\\Storefront\\Models\\Product';
        if (class_exists($product) === true) {
            $product::observe(FlagProductObserver::class);
        }
    }
}
