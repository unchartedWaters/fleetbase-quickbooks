<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\QuickBooksClient;
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
                [],
                $store->adminSync(),
                $store->defaultSync()
            );
            $invoices = $this->knownInvoices($companyUuid, $events);
            $payable  = [];
            foreach ($events as $event) {
                if ($event->operation === 'delete' || $event->entityType !== 'payment') {
                    continue;
                }
                if ($this->allows($settings, $event->entityType)) {
                    $payable[] = $event;
                }
            }
            $payments = $this->paymentInvoices($companyUuid, $payable);
            $records  = [];
            $inbound  = [];
            $deletes  = [];
            foreach ($events as $event) {
                // Delete and void both arrive as operation "delete". A pending row would
                // run the outbound sync and create the remote record again. Retire the
                // local row here. Do not hand the delete to SyncEngine or to a pending create.
                if ($event->operation === 'delete') {
                    $deletes[] = $event;
                    continue;
                }
                if (!$this->allows($settings, $event->entityType)) {
                    continue;
                }
                if ($event->entityType === 'payment') {
                    $target = $payments[$event->realmId . '|' . $event->quickbooksId] ?? null;
                    if (!is_array($target)) {
                        continue;
                    }
                    $records['invoice|' . $target['invoice']] = [
                        'local_type' => 'invoice',
                        'local_uuid' => $target['invoice'],
                    ];
                    $inbound[] = [
                        'entity'    => 'Payment',
                        'id'        => $event->quickbooksId,
                        'operation' => $event->operation,
                    ];
                    // A link stored under the QuickBooks payment id does not point loadLinked
                    // at the invoice. Name the invoice too so the inbound job loads it.
                    if ($target['keyed_by_payment']) {
                        foreach ($target['quickbooks_invoices'] as $invoiceId) {
                            $inbound[] = [
                                'entity'    => 'Invoice',
                                'id'        => $invoiceId,
                                'operation' => 'update',
                            ];
                        }
                    }
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
                $remote = $this->remoteEntity($event->entityType);
                if ($remote !== null) {
                    $inbound[] = [
                        'entity'    => $remote,
                        'id'        => $event->quickbooksId,
                        'operation' => $event->operation,
                    ];
                }
            }
            if ($deletes !== []) {
                $this->retireDeletes($companyUuid, $deletes);
            }
            $inbound = $this->uniqueInbound($inbound);
            if ($inbound !== []) {
                ApplyRemoteChange::dispatch($companyUuid, $inbound);
            }
            if ($records === []) {
                continue;
            }
            SyncWebhookBatch::dispatch($companyUuid, array_values($records));
        }
    }

    /**
     * One link select and bulk status, link, and pending writes for the delivery.
     *
     * @param array<int, QuickBooksEntityChanged> $events
     */
    private function retireDeletes(string $companyUuid, array $events): void
    {
        try {
            $directory = app(FleetbaseDirectory::class);
        } catch (\Throwable) {
            return;
        }
        if (!$directory instanceof FleetbaseDirectory) {
            return;
        }

        $payments = [];
        foreach ($events as $event) {
            if ($event->entityType === 'payment') {
                $payments[] = $event;
            }
        }
        $resolved  = $payments === [] ? [] : $this->paymentInvoices($companyUuid, $payments, true);
        $deletions = [];
        foreach ($events as $event) {
            $invoiceUuids = [];
            if ($event->entityType === 'payment') {
                $target = $resolved[$event->realmId . '|' . $event->quickbooksId] ?? null;
                if (is_array($target) && $target['invoice'] !== '') {
                    $invoiceUuids[] = $target['invoice'];
                }
            }
            $deletions[] = [
                'realm_id'      => $event->realmId,
                'local_type'    => $event->entityType,
                'qbo_id'        => $event->quickbooksId,
                'local_uuid'    => $event->localUuid,
                'invoice_uuids' => $invoiceUuids,
            ];
        }
        $directory->releaseRemoteDeletes($companyUuid, $deletions);
    }

    private function remoteEntity(string $entityType): ?string
    {
        return match ($entityType) {
            'customer' => 'Customer',
            'invoice'  => 'Invoice',
            'payment'  => 'Payment',
            'wallet'   => 'Account',
            default    => null,
        };
    }

    /**
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     *
     * @return array<int, array{entity: string, id: string, operation: string}>
     */
    private function uniqueInbound(array $entities): array
    {
        $unique = [];
        foreach ($entities as $entity) {
            $unique[$entity['entity'] . '|' . $entity['id']] = $entity;
        }

        return array_values($unique);
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

    /**
     * One payment-link query, one invoice-link query, and one local-invoice query
     * for the delivery. A link whose local_uuid is the QuickBooks payment id is not
     * the Fleetbase invoice; the invoice id comes from the payment's lines.
     *
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array<string, array{invoice: string, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    private function paymentInvoices(string $companyUuid, array $events, bool $allowDelete = false): array
    {
        $idsByRealm = [];
        foreach ($events as $event) {
            if ($event->entityType !== 'payment' || (!$allowDelete && $event->operation === 'delete')) {
                continue;
            }
            $idsByRealm[$event->realmId][] = $event->quickbooksId;
        }
        if ($idsByRealm === []) {
            return [];
        }

        $realmIds   = array_keys($idsByRealm);
        $paymentIds = [];
        foreach ($idsByRealm as $ids) {
            foreach ($ids as $id) {
                $paymentIds[] = $id;
            }
        }
        $paymentIds = array_values(array_unique($paymentIds));

        $byPayment = [];
        foreach (Link::query()
            ->where('company_uuid', $companyUuid)
            ->where('qbo_entity', 'Payment')
            ->whereIn('realm_id', $realmIds)
            ->whereIn('qbo_id', $paymentIds)
            ->get(['realm_id', 'qbo_id', 'local_uuid']) as $link) {
            if (!is_object($link)) {
                continue;
            }
            $key                 = (string) $link->realm_id . '|' . (string) $link->qbo_id;
            $byPayment[$key][]   = trim((string) $link->local_uuid);
        }

        $direct = [];
        $keyed  = [];
        foreach ($idsByRealm as $realmId => $ids) {
            foreach (array_values(array_unique($ids)) as $paymentId) {
                $key      = $realmId . '|' . $paymentId;
                $locals   = $byPayment[$key] ?? [];
                $invoices = [];
                foreach ($locals as $uuid) {
                    if ($uuid !== '' && $uuid !== (string) $paymentId) {
                        $invoices[] = $uuid;
                    }
                }
                if ($invoices !== []) {
                    $direct[$key] = $invoices;
                } elseif ($locals !== []) {
                    $keyed[$realmId][] = (string) $paymentId;
                }
            }
        }

        $remoteInvoices = [];
        foreach ($keyed as $realmId => $ids) {
            $found = $this->quickbooksInvoiceIdsForPayments($companyUuid, (string) $realmId, array_values(array_unique($ids)));
            foreach ($found as $paymentId => $invoiceIds) {
                if ($invoiceIds !== []) {
                    $remoteInvoices[$realmId . '|' . $paymentId] = $invoiceIds;
                }
            }
        }

        $candidateUuids = [];
        foreach ($direct as $uuids) {
            foreach ($uuids as $uuid) {
                $candidateUuids[] = $uuid;
            }
        }
        $qboInvoiceIds = [];
        foreach ($remoteInvoices as $invoiceIds) {
            foreach ($invoiceIds as $invoiceId) {
                $qboInvoiceIds[] = $invoiceId;
            }
        }
        $qboInvoiceIds  = array_values(array_unique($qboInvoiceIds));
        $candidateUuids = array_values(array_unique($candidateUuids));

        $linkedByUuid = [];
        $uuidByQbo    = [];
        if ($candidateUuids !== [] || $qboInvoiceIds !== []) {
            $invoiceLinks = Link::query()
                ->where('company_uuid', $companyUuid)
                ->whereIn('realm_id', $realmIds)
                ->where('local_type', 'invoice')
                ->where(function ($query) use ($candidateUuids, $qboInvoiceIds): void {
                    if ($candidateUuids !== []) {
                        $query->whereIn('local_uuid', $candidateUuids);
                    }
                    if ($qboInvoiceIds !== []) {
                        $method = $candidateUuids !== [] ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('qbo_id', $qboInvoiceIds);
                    }
                })
                ->get(['realm_id', 'local_uuid', 'qbo_id']);
            foreach ($invoiceLinks as $link) {
                if (!is_object($link)) {
                    continue;
                }
                $realm = (string) $link->realm_id;
                $uuid  = trim((string) $link->local_uuid);
                $qboId = trim((string) $link->qbo_id);
                if ($uuid !== '') {
                    $linkedByUuid[$realm . '|' . $uuid] = true;
                }
                if ($realm !== '' && $qboId !== '' && $uuid !== '') {
                    $uuidByQbo[$realm . '|' . $qboId] = $uuid;
                }
            }
        }

        $needFile = [];
        foreach ($direct as $key => $uuids) {
            $realm = explode('|', $key, 2)[0];
            foreach ($uuids as $uuid) {
                if (!isset($linkedByUuid[$realm . '|' . $uuid])) {
                    $needFile[] = $uuid;
                }
            }
        }
        $onFile = [];
        if ($needFile !== [] && class_exists(Invoice::class)) {
            $found = Invoice::query()
                ->where('company_uuid', $companyUuid)
                ->whereIn('uuid', array_values(array_unique($needFile)))
                ->pluck('uuid');
            foreach ($found as $uuid) {
                $onFile[(string) $uuid] = true;
            }
        }

        $resolved = [];
        foreach ($direct as $key => $uuids) {
            $realm = explode('|', $key, 2)[0];
            foreach ($uuids as $uuid) {
                if (isset($linkedByUuid[$realm . '|' . $uuid]) || isset($onFile[$uuid])) {
                    $resolved[$key] = [
                        'invoice'              => $uuid,
                        'keyed_by_payment'     => false,
                        'quickbooks_invoices'  => [],
                    ];
                    break;
                }
            }
        }
        foreach ($remoteInvoices as $key => $invoiceIds) {
            if (isset($resolved[$key])) {
                continue;
            }
            $realm = explode('|', $key, 2)[0];
            foreach ($invoiceIds as $invoiceId) {
                $uuid = $uuidByQbo[$realm . '|' . $invoiceId] ?? null;
                if (!is_string($uuid) || $uuid === '') {
                    continue;
                }
                $resolved[$key] = [
                    'invoice'             => $uuid,
                    'keyed_by_payment'    => true,
                    'quickbooks_invoices' => $invoiceIds,
                ];
                break;
            }
        }

        return $resolved;
    }

    /**
     * QuickBooks invoice ids applied by these payments. Empty when the payment
     * cannot be read. A failure here skips the payment; it does not fail the delivery.
     *
     * @param array<int, string> $paymentIds
     *
     * @return array<string, array<int, string>>
     */
    protected function quickbooksInvoiceIdsForPayments(string $companyUuid, string $realmId, array $paymentIds): array
    {
        if ($paymentIds === [] || $realmId === '') {
            return [];
        }

        try {
            $directory = app(FleetbaseDirectory::class);
            $client    = app(QuickBooksClient::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$directory instanceof FleetbaseDirectory || !$client instanceof QuickBooksClient) {
            return [];
        }

        try {
            $connection = $directory->connection($companyUuid);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($connection) || !empty($connection['needs_reauth'])) {
            return [];
        }
        if ((string) ($connection['realm_id'] ?? '') !== $realmId) {
            return [];
        }

        try {
            $remotes = $this->readPayments($client, $connection, $paymentIds);
        } catch (\Throwable) {
            return [];
        }

        $mapped = [];
        foreach ($remotes as $paymentId => $remote) {
            $invoiceIds = $this->invoiceQboIdsOnPayment($remote);
            if ($invoiceIds !== []) {
                $mapped[(string) $paymentId] = $invoiceIds;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $paymentIds
     *
     * @return array<string, array<string, mixed>>
     */
    private function readPayments(QuickBooksClient $client, array $connection, array $paymentIds): array
    {
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn (string $id): bool => $id !== '')));
        if ($paymentIds === []) {
            return [];
        }
        if (count($paymentIds) === 1) {
            $remote = $client->getPayment($connection, $paymentIds[0]);

            return is_array($remote) ? [$paymentIds[0] => $remote] : [];
        }

        $mapped = [];
        foreach (array_chunk($paymentIds, QuickBooksClient::BATCH_LIMIT) as $index => $chunk) {
            $bId     = 'webhook-payments-' . $index;
            $results = $client->batch($connection, [[
                'bId'   => $bId,
                'query' => 'select * from Payment where Id IN (' . QuickBooksClient::quotedList($chunk) . ')',
            ]]);
            $result = $results[$bId] ?? null;
            if (!is_array($result) || empty($result['ok']) || !is_array($result['rows'] ?? null)) {
                continue;
            }
            foreach ($result['rows'] as $remote) {
                if (!is_array($remote)) {
                    continue;
                }
                $id = trim((string) ($remote['Id'] ?? ''));
                if ($id !== '') {
                    $mapped[$id] = $remote;
                }
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $remote
     *
     * @return array<int, string>
     */
    private function invoiceQboIdsOnPayment(array $remote): array
    {
        $lines = $remote['Line'] ?? null;
        if (!is_array($lines)) {
            return [];
        }

        $ids = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $txns = $line['LinkedTxn'] ?? [];
            if (!is_array($txns)) {
                continue;
            }
            foreach ($txns as $txn) {
                if (!is_array($txn)) {
                    continue;
                }
                $type = (string) ($txn['TxnType'] ?? '');
                if ($type !== '' && $type !== 'Invoice') {
                    continue;
                }
                $id = trim((string) ($txn['TxnId'] ?? ''));
                if ($id !== '' && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
