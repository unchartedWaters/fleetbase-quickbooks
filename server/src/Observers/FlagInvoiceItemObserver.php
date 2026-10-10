<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;

/**
 * Line-item edits do not touch the invoice row, so the invoice observer never sees them.
 */
class FlagInvoiceItemObserver
{
    private const WATCHED = ['description', 'quantity', 'unit_price', 'amount'];

    /**
     * Invoice uuids already flagged in this HTTP request or queue job.
     *
     * @var array<string, true>
     */
    private static array $flaggedInvoices = [];

    private static bool $requestHooked = false;

    private static bool $jobHooked = false;

    /**
     * Drops uuids remembered for the current request or job.
     * Tests call this when the process has no HTTP kernel.
     */
    public static function forget(): void
    {
        self::$flaggedInvoices = [];
    }

    public function created(object $item): void
    {
        $this->flagParent($item);
    }

    public function updated(object $item): void
    {
        if (method_exists($item, 'wasChanged') === false || $item->wasChanged(self::WATCHED) === false) {
            return;
        }

        $this->flagParent($item);
    }

    public function deleted(object $item): void
    {
        $this->flagParent($item);
    }

    private function flagParent(object $item): void
    {
        if (SyncSuppressor::paused() === true) {
            return;
        }

        // A failure here (the parent lookup included) must never fail the line-item save.
        FlagGuard::run(function () use ($item): void {
            self::listenForEndOfScope();

            $invoiceUuid = (string) ($item->invoice_uuid ?? '');
            if ($invoiceUuid === '' || isset(self::$flaggedInvoices[$invoiceUuid]) === true) {
                return;
            }

            $invoice = $this->loadParent($invoiceUuid);
            if (is_object($invoice) === false) {
                return;
            }

            self::$flaggedInvoices[$invoiceUuid] = true;

            $listener = Container::getInstance()->make(FlagInvoiceListener::class);
            if ($listener instanceof FlagInvoiceListener === true) {
                $listener->handle((object) ['invoice' => $invoice]);
            }
        }, 'invoice line item', ['invoice_uuid' => (string) ($item->invoice_uuid ?? '')]);
    }

    /**
     * PHP-FPM and the queue worker reuse this process, so the set is cleared
     * when the request terminates and when a job finishes.
     */
    private static function listenForEndOfScope(): void
    {
        if (self::$requestHooked === true && self::$jobHooked === true) {
            return;
        }

        $container = Container::getInstance();

        if (self::$requestHooked === false && method_exists($container, 'terminating') === true) {
            $terminating = 'terminating';
            $container->{$terminating}(static function (): void {
                self::forget();
            });
            self::$requestHooked = true;
        }

        if (self::$jobHooked === true || $container->bound('events') === false) {
            return;
        }

        $events = $container->make('events');
        if ($events instanceof Dispatcher === false) {
            return;
        }

        // Looping is dispatched with until(), which stops the worker on a non-null return.
        $forget = static function (mixed ...$payload): void {
            self::forget();
        };
        $events->listen(JobProcessed::class, $forget);
        $events->listen(JobFailed::class, $forget);
        $events->listen(Looping::class, $forget);
        self::$jobHooked = true;
    }

    protected function loadParent(string $invoiceUuid): ?object
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return null;
        }

        $invoice = $class::query()->where('uuid', $invoiceUuid)->first();

        return is_object($invoice) === true ? $invoice : null;
    }
}
