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

    /**
     * realm|payment id => found when QuickBooks returned that payment, missing when the read was null.
     * A throw, reauth skip, realm mismatch, or batch fault leaves the key unset.
     *
     * @var array<string, string>
     */
    private array $paymentReads = [];

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
            $fromPayment  = true;
            if ($event->entityType === 'payment') {
                $key    = $event->realmId . '|' . $event->quickbooksId;
                $target = $resolved[$key] ?? null;
                if (is_array($target) && $target['invoice'] !== '') {
                    $invoiceUuids[] = $target['invoice'];
                }
                // The body counts as read only when QuickBooks returned that payment.
                // Null, a throw, reauth, a realm mismatch, and a batch fault keep the stored invoice.
                $fromPayment = ($this->paymentReads[$key] ?? '') === 'found';
            }
            $deletions[] = [
                'realm_id'              => $event->realmId,
                'local_type'            => $event->entityType,
                'qbo_id'                => $event->quickbooksId,
                'local_uuid'            => $event->localUuid,
                'invoice_uuids'         => $invoiceUuids,
                'invoices_from_payment' => $fromPayment,
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
        $this->paymentReads = [];
        $idsByRealm         = [];
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
        $missingByRealm = [];
        foreach ($keyed as $realmId => $ids) {
            $found = $this->quickbooksInvoiceIdsForPayments($companyUuid, (string) $realmId, array_values(array_unique($ids)));
            foreach (array_values(array_unique($ids)) as $paymentId) {
                $key = $realmId . '|' . $paymentId;
                if (!array_key_exists($paymentId, $found)) {
                    continue;
                }
                $invoiceIds = $found[$paymentId];
                if ($invoiceIds === null) {
                    $this->paymentReads[$key] = 'missing';
                    if ($allowDelete) {
                        $missingByRealm[$realmId][] = (string) $paymentId;
                    }
                    continue;
                }
                $this->paymentReads[$key] = 'found';
                if (is_array($invoiceIds) && $invoiceIds !== []) {
                    $remoteInvoices[$key] = $invoiceIds;
                }
            }
        }

        $storedInvoice = $allowDelete ? $this->storedPaymentInvoices($companyUuid, $missingByRealm) : [];

        $candidateUuids = [];
        foreach ($direct as $uuids) {
            foreach ($uuids as $uuid) {
                $candidateUuids[] = $uuid;
            }
        }
        foreach ($storedInvoice as $uuid) {
            $candidateUuids[] = $uuid;
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
        foreach ($storedInvoice as $key => $uuid) {
            $realm = explode('|', $key, 2)[0];
            if (!isset($linkedByUuid[$realm . '|' . $uuid])) {
                $needFile[] = $uuid;
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
        foreach ($storedInvoice as $key => $uuid) {
            if (isset($resolved[$key])) {
                continue;
            }
            $realm = explode('|', $key, 2)[0];
            if (isset($linkedByUuid[$realm . '|' . $uuid]) || isset($onFile[$uuid])) {
                $resolved[$key] = [
                    'invoice'             => $uuid,
                    'keyed_by_payment'    => true,
                    'quickbooks_invoices' => [],
                ];
            }
        }

        return $resolved;
    }

    /**
     * Invoice uuids remembered when the payment link was stored under the payment id.
     *
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array<string, string> realm|payment id => invoice uuid
     */
    private function storedPaymentInvoices(string $companyUuid, array $idsByRealm): array
    {
        $realms     = [];
        $paymentIds = [];
        foreach ($idsByRealm as $realmId => $ids) {
            $realms[] = (string) $realmId;
            foreach ($ids as $id) {
                $paymentIds[] = (string) $id;
            }
        }
        $realms     = array_values(array_unique(array_filter($realms, static fn (string $realm): bool => $realm !== '')));
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn (string $id): bool => $id !== '')));
        if ($companyUuid === '' || $realms === [] || $paymentIds === []) {
            return [];
        }

        $mapped = [];
        foreach (Link::query()
            ->where('company_uuid', $companyUuid)
            ->where('local_type', 'payment-invoice')
            ->whereIn('realm_id', $realms)
            ->whereIn('local_uuid', $paymentIds)
            ->get(['realm_id', 'local_uuid', 'qbo_id']) as $link) {
            if (!is_object($link)) {
                continue;
            }
            $paymentId   = trim((string) $link->local_uuid);
            $invoiceUuid = trim((string) $link->qbo_id);
            $realm       = trim((string) $link->realm_id);
            if ($realm === '' || $paymentId === '' || $invoiceUuid === '' || $invoiceUuid === $paymentId) {
                continue;
            }
            $mapped[$realm . '|' . $paymentId] = $invoiceUuid;
        }

        return $mapped;
    }

    /**
     * QuickBooks invoice ids applied by these payments. Null means that payment
     * read returned nothing. A failure here skips the payment; it does not fail the delivery.
     *
     * @param array<int, string> $paymentIds
     *
     * @return array<string, array<int, string>|null> null when that payment read returned nothing
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
            $read = $this->readPayments($client, $connection, $paymentIds);
        } catch (\Throwable) {
            return [];
        }

        $mapped = [];
        foreach ($read['found'] as $paymentId => $remote) {
            $mapped[(string) $paymentId] = $this->invoiceQboIdsOnPayment($remote);
        }
        foreach ($read['missing'] as $paymentId => $missing) {
            if ($missing && !array_key_exists((string) $paymentId, $mapped)) {
                $mapped[(string) $paymentId] = null;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $paymentIds
     *
     * @return array{found: array<string, array<string, mixed>>, missing: array<string, true>}
     */
    private function readPayments(QuickBooksClient $client, array $connection, array $paymentIds): array
    {
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn (string $id): bool => $id !== '')));
        if ($paymentIds === []) {
            return ['found' => [], 'missing' => []];
        }
        if (count($paymentIds) === 1) {
            $remote = $client->getPayment($connection, $paymentIds[0]);
            if (!is_array($remote)) {
                return ['found' => [], 'missing' => [$paymentIds[0] => true]];
            }

            return ['found' => [$paymentIds[0] => $remote], 'missing' => []];
        }

        $found   = [];
        $missing = [];
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
            $seen = [];
            foreach ($result['rows'] as $remote) {
                if (!is_array($remote)) {
                    continue;
                }
                $id = trim((string) ($remote['Id'] ?? ''));
                if ($id !== '') {
                    $found[$id] = $remote;
                    $seen[$id]  = true;
                }
            }
            foreach ($chunk as $id) {
                if (!isset($seen[$id])) {
                    $missing[$id] = true;
                }
            }
        }

        return ['found' => $found, 'missing' => $missing];
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
