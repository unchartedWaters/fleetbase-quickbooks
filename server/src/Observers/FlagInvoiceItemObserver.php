<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
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
        if (!method_exists($item, 'wasChanged') || !$item->wasChanged(self::WATCHED)) {
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
        if (SyncSuppressor::paused()) {
            return;
        }

        self::listenForEndOfScope();

        $invoiceUuid = (string) ($item->invoice_uuid ?? '');
        if ($invoiceUuid === '' || isset(self::$flaggedInvoices[$invoiceUuid])) {
            return;
        }

        $invoice = $this->loadParent($invoiceUuid);
        if (!is_object($invoice)) {
            return;
        }

        self::$flaggedInvoices[$invoiceUuid] = true;

        $listener = Container::getInstance()->make(FlagInvoiceListener::class);
        if ($listener instanceof FlagInvoiceListener) {
            $listener->handle((object) ['invoice' => $invoice]);
        }
    }

    /**
     * PHP-FPM and the queue worker reuse this process, so the set is cleared
     * when the request terminates and when a job finishes.
     */
    private static function listenForEndOfScope(): void
    {
        if (self::$requestHooked && self::$jobHooked) {
            return;
        }

        $container = Container::getInstance();

        if (!self::$requestHooked && method_exists($container, 'terminating')) {
            $terminating = 'terminating';
            $container->{$terminating}(static function (): void {
                self::forget();
            });
            self::$requestHooked = true;
        }

        if (self::$jobHooked || !$container->bound('events')) {
            return;
        }

        $events = $container->make('events');
        if (!$events instanceof Dispatcher) {
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
        if (!class_exists($class)) {
            return null;
        }

        $invoice = $class::query()->where('uuid', $invoiceUuid)->first();

        return is_object($invoice) ? $invoice : null;
    }
}
