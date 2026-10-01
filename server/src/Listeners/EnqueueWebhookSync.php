<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;

class EnqueueWebhookSync
{
    /** @var array<string, array<int, QuickBooksEntityChanged>> */
    private array $queued = [];

    public function __construct(private SettingsService $settings)
    {
    }

    public function handle(QuickBooksEntityChanged $event): void
    {
        $this->queued[$event->companyUuid][] = $event;
    }

    public function flush(SettingsStore $store): void
    {
        $queued       = $this->queued;
        $this->queued = [];

        foreach ($queued as $companyUuid => $events) {
            $settings = $this->settings->resolveSync(
                $store->companySync($companyUuid),
                [],
                $store->defaultSync()
            );
            $invoices = $this->knownInvoices($companyUuid, $events);
            $records  = [];
            foreach ($events as $event) {
                if (!$this->allows($settings, $event->entityType)) {
                    continue;
                }
                $localUuid = $event->localUuid;
                if ($event->entityType === 'invoice') {
                    $realmLinks = $invoices['qbo'][$event->realmId] ?? [];
                    $fromLink   = $realmLinks[$event->quickbooksId] ?? null;
                    if (is_string($fromLink) && $fromLink !== '') {
                        $localUuid = $fromLink;
                    }
                    $linked = isset($realmLinks[$event->quickbooksId])
                        || (is_string($event->localUuid) && $event->localUuid !== '');
                    $onFile = is_string($localUuid) && $localUuid !== '' && isset($invoices['local'][$localUuid]);
                    if (!$linked && !$onFile) {
                        continue;
                    }
                }
                if (!is_string($localUuid) || $localUuid === '') {
                    continue;
                }
                $records[$event->entityType . '|' . $localUuid] = [
                    'local_type' => $event->entityType,
                    'local_uuid' => $localUuid,
                ];
            }
            if ($records === []) {
                continue;
            }
            SyncWebhookBatch::dispatch($companyUuid, array_values($records));
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function allows(array $settings, string $entityType): bool
    {
        if (!$this->entityEnabled($settings, $entityType)) {
            return false;
        }

        $direction = (string) ($settings[$entityType . '_direction'] ?? 'both');

        return $direction !== 'outbound' && $direction !== 'off';
    }

    /**
     * Missing or blank means the entity is synced. An explicit false skips the webhook.
     *
     * @param array<string, mixed> $settings
     */
    private function entityEnabled(array $settings, string $entityType): bool
    {
        $key = $entityType . '_enabled';
        if (!array_key_exists($key, $settings)) {
            return true;
        }
        $value = $settings[$key];
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? true;
    }

    /**
     * Links are loaded per realm; local invoices are loaded once for the delivery.
     *
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array{qbo: array<string, array<string, string>>, local: array<string, true>}
     */
    protected function knownInvoices(string $companyUuid, array $events): array
    {
        $idsByRealm = [];
        $uuids      = [];
        foreach ($events as $event) {
            if ($event->entityType !== 'invoice') {
                continue;
            }
            $idsByRealm[$event->realmId][] = $event->quickbooksId;
            if (is_string($event->localUuid) && $event->localUuid !== '') {
                $uuids[] = $event->localUuid;
            }
        }

        $qbo = [];
        foreach ($idsByRealm as $realmId => $ids) {
            $links = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('realm_id', $realmId)
                ->where('qbo_entity', 'Invoice')
                ->whereIn('qbo_id', array_values(array_unique($ids)))
                ->get(['qbo_id', 'local_uuid']);
            foreach ($links as $link) {
                if (!is_object($link)) {
                    continue;
                }
                $qbo[$realmId][(string) $link->qbo_id] = (string) $link->local_uuid;
                $uuids[]                               = (string) $link->local_uuid;
            }
        }

        $local = [];
        if ($uuids !== [] && class_exists(Invoice::class)) {
            $found = Invoice::query()
                ->where('company_uuid', $companyUuid)
                ->whereIn('uuid', array_values(array_unique($uuids)))
                ->pluck('uuid');
            foreach ($found as $uuid) {
                $local[(string) $uuid] = true;
            }
        }

        return ['qbo' => $qbo, 'local' => $local];
    }
}
