<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\Ledger\Http\Filter\InvoiceFilter;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * What the Fleet-Ops Customers and Ledger Invoices tables need from this plugin to show QuickBooks
 * columns: sorting and search through the plugin's own link table, and a summary of each row. The
 * console registers the columns themselves. The data stays in the plugin's tables.
 */
final class SyncColumns
{
    /** Where the summary sits in a row's meta, as {status, id, synced_at}. */
    public const META_KEY = 'quickbooks';

    public const SORT_ID = 'quickbooks_id';

    public const SORT_SYNCED_AT = 'quickbooks_synced_at';

    public const SEARCH_RELATION = 'quickbooksLink';

    /**
     * Model class => [link type, list endpoint, filter class]. Classes are names, so the plugin still
     * loads without Fleet-Ops or Ledger. Each class is listed: a sort handler or search column belongs
     * to the exact class that is queried. Customer is the Contact subclass.
     */
    public const MODELS = [
        Contact::class  => ['customer', 'contacts', ContactFilter::class],
        Customer::class => ['customer', 'contacts', ContactFilter::class],
        Invoice::class  => ['invoice', 'invoices', InvoiceFilter::class],
    ];

    public static function register(): void
    {
        foreach (self::MODELS as $class => [$type, , $filter]) {
            if (class_exists($class) === false) {
                continue;
            }

            $class::addSortHandler(self::SORT_ID, self::sorter($class, $type, 'qbo_id'));
            $class::addSortHandler(self::SORT_SYNCED_AT, self::sorter($class, $type, 'updated_at'));
            // Fleetbase models run their own __call, which skips Eloquent's resolveRelationUsing; expand() is theirs.
            $class::expand(self::SEARCH_RELATION, self::relation($type));
            $class::addSearchableColumns([self::SEARCH_RELATION . '.qbo_id']);
            // The invoice list's search box looks only at its own columns,
            // so the QuickBooks ID is also a filter (?quickbooks_id=).
            if (class_exists($filter) === true) {
                $filter::expand('quickbooksId', self::idFilter());
            }
            // The console sends a row's meta back when it saves the row. The summary is output only.
            $class::saving(static function (Model $model): void {
                self::withoutSummary($model);
            });
        }

        ResourceTransformerRegistry::register(SyncColumnsTransformer::class);
    }

    /**
     * The link type of a row the columns describe: a customer, not a plain contact, or an invoice.
     * Null for anything else.
     */
    public static function localType(Model $model): ?string
    {
        foreach (self::MODELS as $class => [$type]) {
            if ($model instanceof $class) {
                return $type === 'customer' && (string) ($model->type ?? 'customer') !== 'customer' ? null : $type;
            }
        }

        return null;
    }

    /**
     * Whether the request is for this row's own list or record, not for a resource that embeds it
     * (an order's customer): embedded rows are serialized one at a time and must cost no queries.
     */
    public static function servesRequest(Request $request, Model $model): bool
    {
        $endpoint = null;
        foreach (self::MODELS as $class => [, $path]) {
            if ($model instanceof $class) {
                $endpoint = $path;
                break;
            }
        }

        $uri = (string) $request->route()?->uri();

        return $endpoint !== null && preg_match('#(?:^|/)' . $endpoint . '(?:/\{[^/}]+\})?$#', $uri) === 1;
    }

    /**
     * The closure Expandable binds to a model row, so `$this` is that row.
     *
     * @SuppressWarnings("PHPMD.UndefinedVariable") Expandable rebinds the closure, so `$this` exists when it runs.
     */
    private static function relation(string $type): \Closure
    {
        return function () use ($type) {
            /** @var Model $model */
            $model = $this; // @phpstan-ignore variable.undefined

            return $model->hasOne(Link::class, 'local_uuid', 'uuid')
                ->where('quickbooks_links.local_type', $type)
                ->whereColumn('quickbooks_links.company_uuid', $model->qualifyColumn('company_uuid'));
        };
    }

    /**
     * The closure Expandable binds to a list filter, so `$this` is that filter: rows whose QuickBooks ID
     * contains the value.
     *
     * @SuppressWarnings("PHPMD.UndefinedVariable") Expandable rebinds the closure, so `$this` exists when it runs.
     */
    private static function idFilter(): \Closure
    {
        // Not self::, which means the filter class once Expandable rebinds the closure.
        $relation = self::SEARCH_RELATION;

        return function (mixed $value) use ($relation) {
            /** @var object{builder: EloquentBuilder<Model>} $filter */
            $filter = $this; // @phpstan-ignore variable.undefined
            $text   = is_array($value) === true ? ($value[0] ?? '') : $value;
            $like   = '%' . (is_scalar($text) === true ? (string) $text : '') . '%';

            $filter->builder->whereHas(
                $relation,
                static fn (EloquentBuilder $links) => $links->where('qbo_id', 'like', $like)
            );
        };
    }

    /**
     * Orders by one column of the row's own link, in the row's own organization.
     *
     * @param class-string<Model> $class
     */
    private static function sorter(string $class, string $type, string $column): \Closure
    {
        return static function (EloquentBuilder|QueryBuilder $builder, string $direction) use (
            $class,
            $type,
            $column
        ): void {
            $table = (new $class())->getTable();
            $links = (new Link())->getTable();
            $builder->orderBy(
                Link::query()->select($column)
                    ->where($links . '.local_type', $type)
                    ->whereColumn($links . '.local_uuid', $table . '.uuid')
                    ->whereColumn($links . '.company_uuid', $table . '.company_uuid')
                    ->limit(1),
                $direction
            );
        };
    }

    private static function withoutSummary(Model $model): void
    {
        $meta = $model->getAttribute('meta');
        if (is_array($meta) === true && array_key_exists(self::META_KEY, $meta) === true) {
            unset($meta[self::META_KEY]);
            $model->setAttribute('meta', $meta);
        }
    }
}
