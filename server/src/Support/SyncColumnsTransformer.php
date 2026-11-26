<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Contracts\PreparesResourceTransformation;
use Fleetbase\Contracts\ResourceTransformer;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adds a QuickBooks summary to the console's Customers and Invoices rows, in meta.quickbooks, where a
 * table column reads it: status (synced, waiting, failed, not_synced), QuickBooks ID and last sync time.
 * One query for the links and one for the queue cover the whole page.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") It ties Fleetbase's contracts to the plugin's tables.
 */
class SyncColumnsTransformer implements ResourceTransformer, PreparesResourceTransformation
{
    private const SUMMARIES = 'quickbooks.sync-columns';

    public static function target(): array
    {
        return array_keys(SyncColumns::MODELS);
    }

    /**
     * The console only. The public API, webhooks and broadcasts keep the payloads they had.
     *
     * @return array{only: string, contexts: array<int, string>}
     */
    public static function options(): array
    {
        return ['only' => ResourceTransformerRegistry::ONLY_INTERNAL, 'contexts' => [ResourceTransformerContext::HTTP]];
    }

    public function prepare(Collection $models, Request $request, ResourceTransformerContext $context): void
    {
        $byType = [];
        if ($this->allowed($request) === true) {
            foreach ($models as $model) {
                $serves = $model instanceof Model && SyncColumns::servesRequest($request, $model) === true;
                $type   = $serves === true ? SyncColumns::localType($model) : null;
                if ($type !== null) {
                    $byType[$type][] = $model;
                }
            }
        }

        $summaries = [];
        foreach ($byType as $type => $rows) {
            $summaries += $this->summaries((string) $type, $rows);
        }

        $context->set(self::SUMMARIES, $summaries);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") The ResourceTransformer contract fixes the signature.
     */
    public function transform(
        array $data,
        JsonResource $resource,
        Request $request,
        ResourceTransformerContext $context,
    ): array {
        $model     = $resource->resource;
        $summaries = $context->get(self::SUMMARIES, []);
        $type      = $model instanceof Model ? SyncColumns::localType($model) : null;
        if ($model instanceof Model === false || $type === null || is_array($summaries) === false) {
            return $data;
        }

        $summary = $summaries[self::key($model, $type)] ?? null;
        if ($summary === null) {
            return $data;
        }

        $meta                        = $data['meta'] ?? [];
        $meta                        = is_object($meta) === true ? (array) $meta : $meta;
        $meta                        = is_array($meta) === true ? $meta : [];
        $meta[SyncColumns::META_KEY] = $summary;
        $data['meta']                = $meta;

        return $data;
    }

    private function allowed(Request $request): bool
    {
        $authorizer = Container::getInstance()->make(Authorizer::class);

        return $authorizer instanceof Authorizer && $authorizer->can('quickbooks see extension', $request);
    }

    /**
     * @param array<int, Model> $models
     *
     * @return array<string, array{status: string, id: string|null, synced_at: string|null}>
     */
    private function summaries(string $type, array $models): array
    {
        $uuids     = array_map(static fn (Model $model): string => (string) $model->uuid, $models);
        $companies = array_values(array_unique(
            array_map(static fn (Model $model): string => (string) $model->company_uuid, $models)
        ));
        $links     = Link::query()->toBase()
            ->where('local_type', $type)->whereIn('local_uuid', $uuids)->whereIn('company_uuid', $companies)
            ->get(['company_uuid', 'local_uuid', 'qbo_id', 'updated_at']);
        // The latest queue row of a record says whether it waits, failed or was sent.
        $queue = PendingSync::query()->toBase()
            ->where('local_type', $type)->whereIn('local_uuid', $uuids)->whereIn('company_uuid', $companies)
            ->orderBy('updated_at')->get(['company_uuid', 'local_uuid', 'status']);

        $linked = [];
        foreach ($links as $link) {
            $linked[self::record($link->company_uuid, $link->local_uuid)] = $link;
        }
        $state = [];
        foreach ($queue as $row) {
            $record = self::record($row->company_uuid, $row->local_uuid);
            // A waiting row stays the answer; otherwise the latest row wins.
            $state[$record] = ($state[$record] ?? '') === 'pending' ? 'pending' : (string) $row->status;
        }

        $summaries = [];
        foreach ($models as $model) {
            $key  = self::record($model->company_uuid, $model->uuid);
            $link = $linked[$key] ?? null;

            $summaries[self::key($model, $type)] = [
                'status'    => $this->status($state[$key] ?? null, $link !== null),
                'id'        => $link === null ? null : (string) $link->qbo_id,
                'synced_at' => $link?->updated_at === null
                    ? null
                    : Carbon::parse((string) $link->updated_at)->toIso8601String(),
            ];
        }

        return $summaries;
    }

    private function status(?string $queued, bool $linked): string
    {
        return match (true) {
            $queued === 'pending' => 'waiting',
            $queued === 'failed'  => 'failed',
            $linked               => 'synced',
            default               => 'not_synced',
        };
    }

    private static function record(mixed $company, mixed $uuid): string
    {
        return (string) $company . '|' . (string) $uuid;
    }

    private static function key(Model $model, string $type): string
    {
        return $type . '|' . (string) $model->uuid;
    }
}
