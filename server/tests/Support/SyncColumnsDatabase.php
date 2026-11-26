<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Expansions\Request as RequestExpansion;
use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Http\Resources\v1\Contact as ContactResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Ledger\Http\Filter\InvoiceFilter;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Support\SyncColumns;
use Fleetbase\Quickbooks\Support\SyncColumnsTransformer;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The in-memory database of a customer import plus Ledger invoices, with the QuickBooks columns
 * registered the way the service provider does it.
 */
class SyncColumnsDatabase
{
    /**
     * @return callable restores the previous configuration
     */
    public static function open(): callable
    {
        $restore = ImportDatabase::open();
        // Sorting asks the Schema facade whether the table has the column; it must see this database.
        $facade = Schema::getFacadeRoot();
        Schema::swap(DB::connection('sqlite')->getSchemaBuilder());
        DB::connection('sqlite')->getSchemaBuilder()->create('ledger_invoices', function (Blueprint $table): void {
            $table->char('uuid', 36)->primary();
            $table->string('public_id')->nullable();
            $table->char('company_uuid', 36)->nullable();
            $table->string('number')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        // Invoice loads its lines with it.
        DB::connection('sqlite')->getSchemaBuilder()->create('ledger_invoice_items', function (Blueprint $table): void {
            $table->char('uuid', 36)->primary();
            $table->char('invoice_uuid', 36)->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        SyncColumns::register();

        return static function () use ($restore, $facade): void {
            ResourceTransformerRegistry::forget(SyncColumnsTransformer::class);
            Schema::swap($facade);
            $restore();
        };
    }

    public static function invoice(string $uuid, string $number, string $companyUuid = 'company-uuid'): void
    {
        DB::table('ledger_invoices')->insert([
            'uuid'         => $uuid,
            'company_uuid' => $companyUuid,
            'number'       => $number,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public static function link(
        string $localUuid,
        string $qboId,
        string $type = 'customer',
        string $companyUuid = 'company-uuid',
        string $updatedAt = '2026-11-20 10:00:00',
    ): void {
        DB::table('quickbooks_links')->insert([
            'uuid'         => 'link-' . $type . '-' . $companyUuid . '-' . $localUuid,
            'company_uuid' => $companyUuid,
            'realm_id'     => 'realm-1',
            'local_type'   => $type,
            'local_uuid'   => $localUuid,
            'qbo_entity'   => $type === 'invoice' ? 'Invoice' : 'Customer',
            'qbo_id'       => $qboId,
            'sync_token'   => '0',
            'created_at'   => $updatedAt,
            'updated_at'   => $updatedAt,
        ]);
    }

    public static function pending(
        string $localUuid,
        string $status,
        string $type = 'customer',
        string $updatedAt = '2026-11-21 10:00:00',
        string $companyUuid = 'company-uuid',
    ): void {
        DB::table('quickbooks_pending_syncs')->insert([
            'uuid'         => 'pending-' . $status . '-' . $companyUuid . '-' . $localUuid . '-' . $updatedAt,
            'company_uuid' => $companyUuid,
            'local_type'   => $type,
            'local_uuid'   => $localUuid,
            'status'       => $status,
            'created_at'   => $updatedAt,
            'updated_at'   => $updatedAt,
        ]);
    }

    /**
     * A console request to the route that serves a resource list.
     */
    public static function request(string $uri, string $method = 'GET'): Request
    {
        $request = Request::create('/' . $uri, $method);
        $request->setRouteResolver(static fn (): Route => new Route([$method], $uri, []));

        return $request;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     *
     * @return array<int, string> the model's uuids in the order the sort key gives
     */
    public static function sortedUuids(string $model, string $sort): array
    {
        if (Request::hasMacro('inputArray') === false) {
            Request::mixin(new RequestExpansion());
        }
        $request = Request::create('/', 'GET', ['sort' => $sort]);
        $query   = (new $model())->applySorts($request, $model::query());

        return $query->pluck('uuid')->map(static fn (mixed $uuid): string => (string) $uuid)->all();
    }

    /**
     * What the transformer returns for each model, prepared once for the whole list.
     *
     * @param array<int, object> $models
     *
     * @return array<int, array<string, mixed>>
     */
    public static function decorated(array $models, Request $request): array
    {
        $transformer = new SyncColumnsTransformer();
        $context     = new ResourceTransformerContext($request, ResourceTransformerContext::HTTP, true);
        $transformer->prepare(collect($models), $request, $context);

        return array_map(static function (object $model) use ($transformer, $request, $context): array {
            $resource = $model instanceof Invoice ? new InvoiceResource($model) : new ContactResource($model);

            return $transformer->transform(['uuid' => $model->uuid, 'meta' => $model->meta ?? []], $resource, $request, $context);
        }, $models);
    }

    /**
     * The uuids a list returns when its quickbooks_id filter is applied.
     *
     * @param 'contacts'|'invoices' $list
     *
     * @return array<int, string>
     */
    public static function filtered(string $list, string $value): array
    {
        $request = Request::create('/', 'GET', ['quickbooks_id' => $value]);
        $query   = $list === 'contacts'
            ? (new ContactFilter($request))->apply(Contact::query())
            : (new InvoiceFilter($request))->apply(Invoice::query());

        return $query->orderBy('uuid')->pluck('uuid')->map(static fn (mixed $uuid): string => (string) $uuid)->all();
    }
}
