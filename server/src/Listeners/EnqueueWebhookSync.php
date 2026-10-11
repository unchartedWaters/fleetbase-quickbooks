<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Jobs\ResolveWebhookPayments;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\QuickBooksPaymentReader;
use Fleetbase\Quickbooks\Support\WebhookPaymentInvoices;

class EnqueueWebhookSync
{
    /** @var array<string, array<int, QuickBooksEntityChanged>> */
    private array $queued = [];

    /**
     * True only in the queued job that resolves payments. The webhook request itself never
     * calls QuickBooks: it cannot wait on Intuit, and its stored access token may have expired.
     */
    private bool $readsQuickBooks = false;

    /**
     * Finds the Fleetbase invoices each payment applies to. It also keeps which payments
     * QuickBooks returned (reads) and which this request leaves to ResolveWebhookPayments (deferred).
     */
    private ?WebhookPaymentInvoices $paymentLookup = null;

    /** @var array<int, QuickBooksEntityChanged> */
    private array $deferred = [];

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Let this run read payments from QuickBooks. Used by the queued job, never by the request.
     */
    public function readingQuickBooks(): static
    {
        $this->readsQuickBooks = true;

        return $this;
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
            $this->flushCompany($store, (string) $companyUuid, $events);
        }
    }

    /**
     * @param array<int, QuickBooksEntityChanged> $events
     */
    private function flushCompany(SettingsStore $store, string $companyUuid, array $events): void
    {
        $this->paymentLookup()->deferred = [];
        $this->deferred                  = [];

        $settings = $this->settings->resolveSync(
            [],
            $store->adminSync(),
            $store->defaultSync()
        );
        $invoices = $this->knownInvoices($companyUuid, $events);
        $payable  = $this->payableEvents($settings, $events);
        $payments = $this->paymentInvoices($companyUuid, $payable);
        $this->rememberDeferredPayments($payable);
        [$records, $inbound, $deletes] = $this->collectFlushEvents($settings, $events, $invoices, $payments);
        $this->dispatchFlush($companyUuid, $records, $inbound, $deletes);
    }

    /**
     * @param array<string, mixed>                $settings
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array<int, QuickBooksEntityChanged>
     */
    private function payableEvents(array $settings, array $events): array
    {
        $payable = [];
        foreach ($events as $event) {
            if ($event->operation === 'delete' || $event->entityType !== 'payment') {
                continue;
            }
            if ($this->allows($settings, $event->entityType) === true) {
                $payable[] = $event;
            }
        }

        return $payable;
    }

    /**
     * @param array<int, QuickBooksEntityChanged> $payable
     */
    private function rememberDeferredPayments(array $payable): void
    {
        foreach ($payable as $event) {
            if ($this->isDeferredPayment($event->realmId . '|' . $event->quickbooksId) === true) {
                $this->deferred[] = $event;
            }
        }
    }

    /**
     * @param array<string, mixed>                                                                                                                 $settings
     * @param array<int, QuickBooksEntityChanged>                                                                                                  $events
     * @param array<string, mixed>                                                                                                                 $invoices
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $payments
     *
     * @return array{0: array<string, array{local_type: string, local_uuid: string}>, 1: array<int, array{entity: string, id: string, operation: string}>, 2: array<int, QuickBooksEntityChanged>}
     */
    private function collectFlushEvents(array $settings, array $events, array $invoices, array $payments): array
    {
        $records = [];
        $inbound = [];
        $deletes = [];
        foreach ($events as $event) {
            $this->collectFlushEvent($settings, $event, $invoices, $payments, $records, $inbound, $deletes);
        }

        return [$records, $inbound, $deletes];
    }

    /**
     * Delete and void both arrive as operation "delete". A pending row would
     * run the outbound sync and create the remote record again. Retire the
     * local row here. Do not hand the delete to SyncEngine or to a pending create.
     * A delete changes Fleetbase from QuickBooks, so it needs the same permission
     * as an inbound update: the type is on, the direction takes QuickBooks changes,
     * and QuickBooks is the side that wins. Otherwise Fleetbase keeps its record.
     *
     * @param array<string, mixed>                                                                                                                 $settings
     * @param array<string, mixed>                                                                                                                 $invoices
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $payments
     * @param array<string, array{local_type: string, local_uuid: string}>                                                                         $records
     * @param array<int, array{entity: string, id: string, operation: string}>                                                                     $inbound
     * @param array<int, QuickBooksEntityChanged>                                                                                                  $deletes
     */
    private function collectFlushEvent(array $settings, QuickBooksEntityChanged $event, array $invoices, array $payments, array &$records, array &$inbound, array &$deletes): void
    {
        if ($event->operation === 'delete') {
            if ($this->quickbooksSupplies($settings, $event->entityType) === true) {
                $deletes[] = $event;
            }

            return;
        }
        if ($this->allows($settings, $event->entityType) === false) {
            return;
        }
        if ($event->entityType === 'payment') {
            $this->collectPaymentEvent($settings, $event, $payments, $records, $inbound);

            return;
        }
        $this->collectLocalEvent($settings, $event, $invoices, $records, $inbound);
    }

    /**
     * @param array<string, mixed>                                                                                                                 $settings
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $payments
     * @param array<string, array{local_type: string, local_uuid: string}>                                                                         $records
     * @param array<int, array{entity: string, id: string, operation: string}>                                                                     $inbound
     */
    private function collectPaymentEvent(array $settings, QuickBooksEntityChanged $event, array $payments, array &$records, array &$inbound): void
    {
        $target = $payments[$event->realmId . '|' . $event->quickbooksId] ?? null;
        if (is_array($target) === false) {
            return;
        }
        $this->recordPaymentInvoices($records, $target);
        if ($this->quickbooksSupplies($settings, 'payment') === false) {
            return;
        }
        $inbound[] = [
            'entity'    => 'Payment',
            'id'        => $event->quickbooksId,
            'operation' => $event->operation,
        ];
        // A link stored under the QuickBooks payment id does not point loadLinked
        // at the invoice. Name the invoice too so the inbound job loads it.
        if ($target['keyed_by_payment'] === true) {
            foreach ($target['quickbooks_invoices'] as $invoiceId) {
                $inbound[] = [
                    'entity'    => 'Invoice',
                    'id'        => $invoiceId,
                    'operation' => 'update',
                ];
            }
        }
    }

    /**
     * @param array<string, array{local_type: string, local_uuid: string}>                                                          $records
     * @param array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>} $target
     */
    private function recordPaymentInvoices(array &$records, array $target): void
    {
        $invoiceUuids = is_array($target['invoices'] ?? null) === true ? $target['invoices'] : [];
        if ($invoiceUuids === [] && is_string($target['invoice'] ?? null) === true && $target['invoice'] !== '') {
            $invoiceUuids = [$target['invoice']];
        }
        foreach ($invoiceUuids as $invoiceUuid) {
            $invoiceUuid = (string) $invoiceUuid;
            if ($invoiceUuid === '') {
                continue;
            }
            $records['invoice|' . $invoiceUuid] = [
                'local_type' => 'invoice',
                'local_uuid' => $invoiceUuid,
            ];
        }
    }

    /**
     * @param array<string, mixed>                                             $settings
     * @param array<string, mixed>                                             $invoices
     * @param array<string, array{local_type: string, local_uuid: string}>     $records
     * @param array<int, array{entity: string, id: string, operation: string}> $inbound
     */
    private function collectLocalEvent(array $settings, QuickBooksEntityChanged $event, array $invoices, array &$records, array &$inbound): void
    {
        $localUuid = $this->localUuidForEvent($event, $invoices);
        if ($localUuid === null) {
            return;
        }
        $records[$event->entityType . '|' . $localUuid] = [
            'local_type' => $event->entityType,
            'local_uuid' => $localUuid,
        ];
        $remote = $this->remoteEntity($event->entityType);
        if ($remote !== null && $this->quickbooksSupplies($settings, $event->entityType) === true) {
            $inbound[] = [
                'entity'    => $remote,
                'id'        => $event->quickbooksId,
                'operation' => $event->operation,
            ];
        }
    }

    /**
     * @param array<string, mixed> $invoices
     */
    private function localUuidForEvent(QuickBooksEntityChanged $event, array $invoices): ?string
    {
        $localUuid = $event->localUuid;
        if ($event->entityType === 'invoice') {
            $localUuid = $this->invoiceEventUuid($event, $invoices, $localUuid);
            if ($localUuid === false) {
                return null;
            }
        }
        if (is_string($localUuid) === false || $localUuid === '') {
            return null;
        }

        return $localUuid;
    }

    /**
     * False when this invoice event should be ignored. Otherwise the local uuid to queue.
     *
     * @param array<string, mixed> $invoices
     */
    private function invoiceEventUuid(QuickBooksEntityChanged $event, array $invoices, mixed $localUuid): mixed
    {
        $realmLinks = $invoices['qbo'][$event->realmId] ?? [];
        $fromLink   = $realmLinks[$event->quickbooksId] ?? null;
        if (is_string($fromLink) === true && $fromLink !== '') {
            $localUuid = $fromLink;
        }
        $linked = isset($realmLinks[$event->quickbooksId]) === true
            || (is_string($event->localUuid) === true && $event->localUuid !== '');
        $onFile = is_string($localUuid) === true && $localUuid !== '' && isset($invoices['local'][$localUuid]) === true;
        if ($linked === false && $onFile === false) {
            return false;
        }

        return $localUuid;
    }

    /**
     * @param array<string, array{local_type: string, local_uuid: string}>     $records
     * @param array<int, array{entity: string, id: string, operation: string}> $inbound
     * @param array<int, QuickBooksEntityChanged>                              $deletes
     */
    private function dispatchFlush(string $companyUuid, array $records, array $inbound, array $deletes): void
    {
        if ($deletes !== []) {
            $this->retireDeletes($companyUuid, $deletes);
        }
        if ($this->deferred !== []) {
            ResolveWebhookPayments::dispatch($companyUuid, $this->deferredPayload());
        }
        $inbound = $this->uniqueInbound($inbound);
        if ($inbound !== []) {
            ApplyRemoteChange::dispatch($companyUuid, $inbound);
        }
        if ($records === []) {
            return;
        }
        SyncWebhookBatch::dispatch($companyUuid, array_values($records));
    }

    /**
     * paymentInvoices() marks the payments it leaves to ResolveWebhookPayments.
     */
    private function isDeferredPayment(string $key): bool
    {
        return isset($this->paymentLookup()->deferred[$key]);
    }

    /**
     * @return array<int, array{realm_id: string, entity_type: string, id: string, operation: string, local_uuid: string|null}>
     */
    private function deferredPayload(): array
    {
        $payload = [];
        foreach ($this->deferred as $event) {
            $payload[] = [
                'realm_id'    => $event->realmId,
                'entity_type' => $event->entityType,
                'id'          => $event->quickbooksId,
                'operation'   => $event->operation,
                'local_uuid'  => $event->localUuid,
            ];
        }

        return $payload;
    }

    /**
     * One link select and bulk status, link, and pending writes for the delivery.
     *
     * @param array<int, QuickBooksEntityChanged> $events
     */
    private function retireDeletes(string $companyUuid, array $events): void
    {
        $directory = $this->deleteDirectory();
        if ($directory === null) {
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
                $key = $event->realmId . '|' . $event->quickbooksId;
                if (isset($this->paymentLookup()->deferred[$key]) === true) {
                    $this->deferred[] = $event;
                    continue;
                }
                $invoiceUuids = $this->resolvedInvoiceUuids($resolved[$key] ?? null);
                // The body counts as read only when QuickBooks returned that payment.
                // Null, a throw, reauth, a realm mismatch, and a batch fault keep the stored invoice.
                $fromPayment = ($this->paymentLookup()->reads[$key] ?? '') === 'found';
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

    private function deleteDirectory(): ?FleetbaseDirectory
    {
        try {
            $directory = app(FleetbaseDirectory::class);
        } catch (\Throwable) {
            return null;
        }

        return $directory instanceof FleetbaseDirectory === true ? $directory : null;
    }

    /**
     * The Fleetbase invoices a resolved payment applied to: its invoice list, or its one invoice.
     *
     * @return array<int, string>
     */
    private function resolvedInvoiceUuids(mixed $target): array
    {
        if (is_array($target) === false) {
            return [];
        }
        $named = is_array($target['invoices'] ?? null) === true ? $target['invoices'] : [];
        if ($named === [] && is_string($target['invoice'] ?? null) === true && $target['invoice'] !== '') {
            $named = [$target['invoice']];
        }
        $invoiceUuids = [];
        foreach ($named as $invoiceUuid) {
            $invoiceUuid = (string) $invoiceUuid;
            if ($invoiceUuid !== '') {
                $invoiceUuids[] = $invoiceUuid;
            }
        }

        return $invoiceUuids;
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
        if ($this->entityEnabled($settings, $entityType) === false) {
            return false;
        }

        $direction = (string) ($settings[$entityType . '_direction'] ?? 'both');

        return $direction !== 'outbound' && $direction !== 'off';
    }

    /**
     * Copy QuickBooks onto Fleetbase only when QuickBooks wins, or when the
     * direction does not push Fleetbase back. Primary Fleetbase keeps its row.
     *
     * @param array<string, mixed> $settings
     */
    private function quickbooksSupplies(array $settings, string $entityType): bool
    {
        if ($this->allows($settings, $entityType) === false) {
            return false;
        }
        if ((string) ($settings[$entityType . '_conflict'] ?? 'fleetbase') === 'quickbooks') {
            return true;
        }

        return (string) ($settings[$entityType . '_direction'] ?? 'both') === 'inbound';
    }

    /**
     * Missing or blank means the entity is synced. An explicit false skips the webhook.
     *
     * @param array<string, mixed> $settings
     */
    private function entityEnabled(array $settings, string $entityType): bool
    {
        $key = $entityType . '_enabled';
        if (array_key_exists($key, $settings) === false) {
            return true;
        }
        $value = $settings[$key];
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value) === true) {
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
            if (is_string($event->localUuid) === true && $event->localUuid !== '') {
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
                if (is_object($link) === false) {
                    continue;
                }
                $qbo[$realmId][(string) $link->qbo_id] = (string) $link->local_uuid;
                $uuids[]                               = (string) $link->local_uuid;
            }
        }

        $local = [];
        if ($uuids !== [] && class_exists(Invoice::class) === true) {
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
     * Only the queued job reads payments from QuickBooks. In the request, a payment whose
     * invoices need that read is marked deferred instead.
     *
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    private function paymentInvoices(string $companyUuid, array $events, bool $allowDelete = false): array
    {
        $readInvoiceIds = $this->readsQuickBooks === true
            ? fn (string $company, string $realmId, array $paymentIds): array => $this->quickbooksInvoiceIdsForPayments($company, $realmId, $paymentIds)
            : null;

        return $this->paymentLookup()->resolve($companyUuid, $events, $allowDelete, $readInvoiceIds);
    }

    private function paymentLookup(): WebhookPaymentInvoices
    {
        return $this->paymentLookup ??= new WebhookPaymentInvoices();
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
        return (new QuickBooksPaymentReader())->invoiceIds($companyUuid, $realmId, $paymentIds);
    }
}
