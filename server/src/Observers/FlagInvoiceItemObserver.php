<?php

namespace Fleetbase\Quickbooks\Observers;

use Fleetbase\Quickbooks\Listeners\FlagInvoiceListener;
use Fleetbase\Quickbooks\Support\FlagGuard;
use Fleetbase\Quickbooks\Support\ProductMapper;
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
    private const WATCHED = ['description', 'quantity', 'unit_price', 'amount', 'tax_rate', 'tax_amount'];

    /**
     * Invoice uuids already flagged in this HTTP request or queue job.
     *
     * @var array<string, true>
     */
    private static array $flaggedInvoices = [];

    /**
     * Invoice uuids already checked in this HTTP request or queue job, mapped to their
     * organization, or to '' when that organization does not use QuickBooks.
     *
     * @var array<string, string>
     */
    private static array $checkedInvoices = [];

    private static bool $requestHooked = false;

    private static bool $jobHooked = false;

    /**
     * Drops uuids remembered for the current request or job.
     * Tests call this when the process has no HTTP kernel.
     */
    public static function forget(): void
    {
        self::$flaggedInvoices = [];
        self::$checkedInvoices = [];
    }

    public function created(object $item): void
    {
        $this->flagParent($item);
    }

    public function updated(object $item): void
    {
        if (method_exists($item, 'wasChanged') === false) {
            return;
        }
        if ($item->wasChanged(self::WATCHED) === false && self::productChanged($item) === false) {
            return;
        }

        $this->flagParent($item);
    }

    public function deleted(object $item): void
    {
        $this->flagParent($item);
    }

    /**
     * Whether a meta change named another product for the line (ProductMapper::metaProduct), which
     * moves its QuickBooks line to that product.
     */
    private static function productChanged(object $item): bool
    {
        if (method_exists($item, 'wasChanged') === false || $item->wasChanged('meta') === false) {
            return false;
        }
        $before = method_exists($item, 'getRawOriginal') === true ? $item->getRawOriginal('meta') : null;

        return self::productKey($before) !== self::productKey($item->meta ?? null);
    }

    private static function productKey(mixed $meta): string
    {
        $meta    = is_string($meta) === true ? json_decode($meta, true) : $meta;
        $product = ProductMapper::metaProduct($meta);

        return $product === null ? '' : ProductMapper::key((string) $product['name']);
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

            // Untracked organizations stop here, before the full invoice and its relations are read.
            // The listener is a singleton, so its directory remembers untracked organizations.
            $listener = Container::getInstance()->make(FlagInvoiceListener::class);
            if (
                $listener instanceof FlagInvoiceListener === false
                || $this->tracked($item, $invoiceUuid, $listener) === false
            ) {
                return;
            }

            $invoice = $this->loadParent($invoiceUuid);
            if (is_object($invoice) === false) {
                return;
            }

            self::$flaggedInvoices[$invoiceUuid] = true;
            $listener->handle((object) ['invoice' => $invoice]);
        }, 'invoice line item', ['invoice_uuid' => (string) ($item->invoice_uuid ?? '')]);
    }

    /**
     * PHP-FPM, Octane and the queue worker reuse this process, so the set is cleared
     * when a request is handled or terminates and when a job finishes.
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
        // Octane keeps this dispatcher across requests, but the terminating hook above reaches only the first one.
        $events->listen('Illuminate\\Foundation\\Http\\Events\\RequestHandled', $forget);
        self::$jobHooked = true;
    }

    /**
     * Whether the line's organization uses QuickBooks. Lines of one invoice are looked up once.
     */
    private function tracked(object $item, string $invoiceUuid, FlagInvoiceListener $listener): bool
    {
        if (isset(self::$checkedInvoices[$invoiceUuid]) === true) {
            return self::$checkedInvoices[$invoiceUuid] !== '';
        }

        $companyUuid = (string) ($item->company_uuid ?? '');
        if ($companyUuid === '') {
            $companyUuid = $this->companyOf($invoiceUuid);
        }
        $tracked                              = $companyUuid !== '' && $listener->tracks($companyUuid) === true;
        self::$checkedInvoices[$invoiceUuid]  = $tracked === true ? $companyUuid : '';

        return $tracked;
    }

    protected function companyOf(string $invoiceUuid): string
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return '';
        }

        return (string) $class::withoutEagerLoads()->where('uuid', $invoiceUuid)->value('company_uuid');
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
