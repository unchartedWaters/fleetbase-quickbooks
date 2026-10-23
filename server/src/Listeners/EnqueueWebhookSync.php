<?php

namespace Fleetbase\Quickbooks\Listeners;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Jobs\ResolveWebhookPayments;
use Fleetbase\Quickbooks\Jobs\SyncWebhookBatch;
use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Support\QuickBooksPaymentReader;
use Fleetbase\Quickbooks\Support\Translation;
use Fleetbase\Quickbooks\Support\WebhookPaymentInvoices;
use Fleetbase\Quickbooks\Support\WebhookPaymentLinks;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * @phpstan-type PaymentTarget array{
 *     invoice: string,
 *     invoices: array<int, string>,
 *     keyed_by_payment: bool,
 *     quickbooks_invoices: array<int, string>
 * }
 */
class EnqueueWebhookSync
{
    /** Activity notes for a QuickBooks delete that Fleetbase kept, by record type. */
    private const DELETE_KEPT = [
        'customer' => 'quickbooks.messages.remote-delete-kept.customer',
        'invoice'  => 'quickbooks.messages.remote-delete-kept.invoice',
        'payment'  => 'quickbooks.messages.remote-delete-kept.payment',
        'wallet'   => 'quickbooks.messages.remote-delete-kept.wallet',
    ];

    /** Activity notes for a QuickBooks merge, by whether the link moved and by record type. */
    private const MERGE_NOTES = [
        'moved' => [
            'customer' => 'quickbooks.messages.remote-merge-moved.customer',
            'wallet'   => 'quickbooks.messages.remote-merge-moved.wallet',
        ],
        'kept' => [
            'customer' => 'quickbooks.messages.remote-merge-kept.customer',
            'wallet'   => 'quickbooks.messages.remote-merge-kept.wallet',
        ],
    ];

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

    /**
     * QuickBooks customers this delivery reported as created that Fleetbase has no link for.
     *
     * @var array<string, array<int, string>> ids by realm
     */
    private array $createdCustomers = [];

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Under Octane each request runs on a copy of the container, but the dispatcher keeps the one it
     * was built with. Resolve the listener from the current container, which WebhookController flushes.
     */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(QuickBooksEntityChanged::class, static function (QuickBooksEntityChanged $event): void {
            $listener = Container::getInstance()->make(self::class);
            if ($listener instanceof self === true) {
                $listener->handle($event);
            }
        });
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
        $this->createdCustomers          = [];

        $settings = $this->settings->resolveSync(
            $store->companySync($companyUuid),
            $store->adminSync(),
            $store->defaultSync()
        );
        [$events, $late] = $this->withoutStaleDeletes($events);
        $invoices        = $this->knownInvoices($companyUuid, $events);
        $payable         = $this->payableEvents($settings, $events);
        // A payment made in QuickBooks has no link yet. Its invoices are worth reading only when
        // payments take QuickBooks values; otherwise the read would change nothing.
        $readUnlinked = $this->quickbooksSupplies($settings, 'payment');
        $payments     = $this->paymentInvoices($companyUuid, $payable, false, $readUnlinked);
        $this->rememberDeferredPayments($payable);
        $this->repointMovedPayments($companyUuid, $settings);
        [$records, $inbound, $deletes, $kept, $merges] = $this->collectFlushEvents(
            $settings,
            $events,
            $invoices,
            $payments
        );
        [$deletes, $kept, $merges] = $this->onConnectedRealm($companyUuid, [$deletes, $kept, $merges]);
        [$notes, $refresh]         = $this->followMerges($companyUuid, $settings, $merges);
        [$lateNotes, $lateReads]   = $this->staleDeleteChanges($companyUuid, $settings, $late);
        $this->dispatchFlush($companyUuid, $records, array_merge($inbound, $refresh, $lateReads), $deletes);
        $this->noteKeptDeletes($companyUuid, $kept, array_merge($notes, $lateNotes));
        $this->importCreatedCustomers($companyUuid);
    }

    /**
     * A delivery that arrived too late keeps its deletes out of the usual path: they are
     * never applied from the payload. See WebhookController::staleDelete().
     *
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array{0: array<int, QuickBooksEntityChanged>, 1: array<int, QuickBooksEntityChanged>}
     */
    private function withoutStaleDeletes(array $events): array
    {
        $current = [];
        $late    = [];
        foreach ($events as $event) {
            if (in_array($event->operation, ['refresh', 'stale-delete'], true) === true) {
                $late[] = $event;
            } else {
                $current[] = $event;
            }
        }

        return [$current, $late];
    }

    /**
     * A late payment delete or void reads the amount paid of the invoices it paid again from
     * their QuickBooks balance, when payments take QuickBooks values. Settings that keep the
     * payment note it as for any delete. Every other late delete of a linked record is noted.
     *
     * @param array<string, mixed>                $settings
     * @param array<int, QuickBooksEntityChanged> $late
     *
     * @return array{
     *     0: array<int, array{local_type: string, local_uuid: string, error: string}>,
     *     1: array<int, array{entity: string, id: string, operation: string}>
     * }
     */
    private function staleDeleteChanges(string $companyUuid, array $settings, array $late): array
    {
        [$late]   = $late === [] ? [[]] : $this->onConnectedRealm($companyUuid, [$late]);
        $notes    = [];
        $payments = [];
        foreach ($late as $event) {
            $kept = $this->keepsDelete($settings, $event);
            if ($event->operation === 'refresh' && $kept === false) {
                if ($this->quickbooksSupplies($settings, 'payment') === true) {
                    $payments[] = $event;
                }
                continue;
            }
            $note = $this->staleDeleteNote($settings, $event, $kept);
            if ($note !== null) {
                $notes[] = $note;
            }
        }
        $reads = [];
        foreach ($this->paymentLookup()->lastKnownInvoices($companyUuid, $payments) as $invoices) {
            array_push($reads, ...$this->invoicePaymentReads($invoices));
        }

        return [$notes, $reads];
    }

    /**
     * The Activity note for a late delete of a linked record that was not applied.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{local_type: string, local_uuid: string, error: string}|null
     */
    private function staleDeleteNote(array $settings, QuickBooksEntityChanged $event, bool $kept): ?array
    {
        $linked = is_string($event->localUuid) === true && $event->localUuid !== '';
        if ($linked === false || $this->entityEnabled($settings, $event->entityType) === false) {
            return null;
        }
        $error = Translation::encode('quickbooks.messages.remote-delete-late');
        if ($kept === true) {
            $key = self::DELETE_KEPT[$event->entityType] ?? null;
            if ($key === null) {
                return null;
            }
            $error = Translation::encode($key, ['id' => $event->quickbooksId]);
        }

        return ['local_type' => $event->entityType, 'local_uuid' => (string) $event->localUuid, 'error' => $error];
    }

    /**
     * A payment QuickBooks moved to another invoice no longer counts on the invoice stored for
     * it. Only when payments take QuickBooks values: otherwise Fleetbase still holds the old one.
     *
     * @param array<string, mixed> $settings
     */
    private function repointMovedPayments(string $companyUuid, array $settings): void
    {
        $stale = $this->paymentLookup()->staleStored;
        if ($stale !== [] && $this->quickbooksSupplies($settings, 'payment') === true) {
            (new WebhookPaymentLinks())->repointStoredPaymentInvoices($companyUuid, $stale);
        }
    }

    /**
     * Customers created in QuickBooks are imported like the customer import on connect, but
     * only the ones named here and only from the QuickBooks company this organization uses now.
     */
    private function importCreatedCustomers(string $companyUuid): void
    {
        if ($this->createdCustomers === []) {
            return;
        }
        $connection = $this->deleteDirectory()?->connection($companyUuid);
        $realm      = is_array($connection) === true ? trim((string) ($connection['realm_id'] ?? '')) : '';
        $ids        = $realm === '' ? [] : ($this->createdCustomers[$realm] ?? []);
        if ($ids !== []) {
            ImportCustomers::dispatchCustomers($companyUuid, $realm, $ids);
        }
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
     * @return array{
     *     0: array<string, array{local_type: string, local_uuid: string}>,
     *     1: array<int, array{entity: string, id: string, operation: string}>,
     *     2: array<int, QuickBooksEntityChanged>,
     *     3: array<int, QuickBooksEntityChanged>,
     *     4: array<int, QuickBooksEntityChanged>
     * }
     */
    private function collectFlushEvents(array $settings, array $events, array $invoices, array $payments): array
    {
        $records    = [];
        $inbound    = [];
        $deletes    = [];
        $kept       = [];
        $merges     = [];
        $mergedAway = [];
        foreach ($events as $event) {
            if ($event->operation === 'merge') {
                $mergedAway[$this->recordKey($event)] = true;
                if ($this->followsMerge($settings, $event) === true) {
                    $merges[] = $event;
                }
            }
        }
        foreach ($events as $event) {
            // A merge is not a delete, even if Intuit also reports the merged-away record as deleted.
            $mergedDelete = $event->operation === 'delete' && isset($mergedAway[$this->recordKey($event)]) === true;
            if ($event->operation === 'merge' || $mergedDelete === true) {
                continue;
            }
            if ($event->operation === 'delete' && $this->keepsDelete($settings, $event) === true) {
                $kept[] = $event;
                continue;
            }
            $this->collectFlushEvent($settings, $event, $invoices, $payments, $records, $inbound, $deletes);
        }

        return [$records, $inbound, $deletes, $kept, $merges];
    }

    /**
     * Deletes and merges change Fleetbase records and Activity, so they apply only to the
     * QuickBooks company this organization is connected to now. Its links outlive a disconnect,
     * and another organization on the same realm can still route the delivery here.
     *
     * @param array<int, array<int, QuickBooksEntityChanged>> $lists
     *
     * @return array<int, array<int, QuickBooksEntityChanged>>
     */
    private function onConnectedRealm(string $companyUuid, array $lists): array
    {
        if ($lists === [[], [], []]) {
            return $lists;
        }
        $connection = $this->deleteDirectory()?->connection($companyUuid);
        $realm      = is_array($connection) === true ? trim((string) ($connection['realm_id'] ?? '')) : '';
        foreach ($lists as $index => $events) {
            $lists[$index] = array_values(array_filter(
                $events,
                static fn (QuickBooksEntityChanged $event): bool => $realm !== '' && $event->realmId === $realm
            ));
        }

        return $lists;
    }

    /**
     * Customers and accounts are the records QuickBooks merges. Only a linked one matters.
     *
     * @param array<string, mixed> $settings
     */
    private function followsMerge(array $settings, QuickBooksEntityChanged $event): bool
    {
        return in_array($event->entityType, ['customer', 'wallet'], true) === true
            && is_string($event->localUuid) === true && $event->localUuid !== ''
            && is_string($event->mergedInto) === true && $event->mergedInto !== ''
            && $this->entityEnabled($settings, $event->entityType) === true;
    }

    /**
     * The Fleetbase record of a merged-away QuickBooks record now syncs with the record it went
     * into. When Fleetbase already links that record to another one of its own, both stay as
     * they are. Neither case deletes anything in Fleetbase.
     *
     * @param array<string, mixed>                $settings
     * @param array<int, QuickBooksEntityChanged> $merges
     *
     * @return array{
     *     0: array<int, array{local_type: string, local_uuid: string, error: string, outcome: string}>,
     *     1: array<int, array{entity: string, id: string, operation: string}>
     * }
     */
    private function followMerges(string $companyUuid, array $settings, array $merges): array
    {
        $directory = $merges === [] ? null : $this->deleteDirectory();
        if ($directory === null) {
            return [[], []];
        }
        $notes   = [];
        $refresh = [];
        foreach ($merges as $event) {
            $into  = (string) $event->mergedInto;
            $type  = $event->entityType;
            $moved = $directory->followMergedRecord($companyUuid, $event->realmId, $type, $event->quickbooksId, $into);
            $key   = self::MERGE_NOTES[$moved === true ? 'moved' : 'kept'][$type] ?? null;
            if ($moved === null || $key === null) {
                continue;
            }
            $notes[] = [
                'local_type' => $type,
                'local_uuid' => (string) $event->localUuid,
                'error'      => Translation::encode($key, ['id' => $event->quickbooksId, 'into' => $into]),
                'outcome'    => $moved === true ? 'linked' : 'skipped',
            ];
            $remote   = $this->remoteEntity($event->entityType);
            $supplied = $this->quickbooksSupplies($settings, $event->entityType);
            if ($moved === true && $remote !== null && $supplied === true) {
                $refresh[] = ['entity' => $remote, 'id' => $into, 'operation' => 'update'];
            }
        }

        return [$notes, $refresh];
    }

    /**
     * A delete of a linked record that the sync settings do not let QuickBooks apply.
     * Fleetbase keeps the record, and Activity says so.
     *
     * @param array<string, mixed> $settings
     */
    private function keepsDelete(array $settings, QuickBooksEntityChanged $event): bool
    {
        if (is_string($event->localUuid) === false || $event->localUuid === '') {
            return false;
        }

        return $this->entityEnabled($settings, $event->entityType) === true
            && $this->quickbooksSupplies($settings, $event->entityType) === false;
    }

    /**
     * @param array<int, QuickBooksEntityChanged>                                                        $kept
     * @param array<int, array{local_type: string, local_uuid: string, error: string, outcome?: string}> $notes
     */
    private function noteKeptDeletes(string $companyUuid, array $kept, array $notes = []): void
    {
        if ($kept === [] && $notes === []) {
            return;
        }
        $directory = $this->deleteDirectory();
        if ($directory === null) {
            return;
        }
        foreach ($kept as $event) {
            $key = self::DELETE_KEPT[$event->entityType] ?? null;
            if ($key === null) {
                continue;
            }
            $notes[] = [
                'local_type' => $event->entityType,
                'local_uuid' => (string) $event->localUuid,
                'error'      => Translation::encode($key, ['id' => $event->quickbooksId]),
            ];
        }
        $directory->noteRemoteDeletes($companyUuid, $notes);
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
        $key    = $event->realmId . '|' . $event->quickbooksId;
        $target = $payments[$key] ?? null;
        if (is_array($target) === true) {
            $this->collectPaymentTarget($settings, $event, $target, $records, $inbound);
        }
        $this->collectPreviousInvoices($settings, $this->paymentLookup()->previous[$key] ?? [], $records, $inbound);
    }

    /**
     * Invoices a payment no longer pays in QuickBooks are synced again, and with payments
     * from QuickBooks their amount paid is read again from the QuickBooks balance.
     *
     * @param array<string, mixed>                                             $settings
     * @param array<string, string>                                            $previous invoice uuid => QuickBooks id
     * @param array<string, array{local_type: string, local_uuid: string}>     $records
     * @param array<int, array{entity: string, id: string, operation: string}> $inbound
     */
    private function collectPreviousInvoices(array $settings, array $previous, array &$records, array &$inbound): void
    {
        foreach (array_keys($previous) as $uuid) {
            $records['invoice|' . $uuid] = ['local_type' => 'invoice', 'local_uuid' => (string) $uuid];
        }
        if ($previous !== [] && $this->quickbooksSupplies($settings, 'payment') === true) {
            array_push($inbound, ...$this->invoicePaymentReads($previous));
        }
    }

    /**
     * Reads of the amount paid from each linked invoice's QuickBooks balance.
     *
     * @param array<string, string> $invoices invoice uuid => QuickBooks id
     *
     * @return array<int, array{entity: string, id: string, operation: string}>
     */
    private function invoicePaymentReads(array $invoices): array
    {
        $reads = [];
        foreach ($invoices as $invoiceId) {
            if ($invoiceId !== '') {
                $reads[] = ['entity' => SyncEngine::INVOICE_PAYMENTS, 'id' => $invoiceId, 'operation' => 'update'];
            }
        }

        return $reads;
    }

    /**
     * @param array<string, mixed>                                             $settings
     * @param PaymentTarget                                                    $target
     * @param array<string, array{local_type: string, local_uuid: string}>     $records
     * @param array<int, array{entity: string, id: string, operation: string}> $inbound
     */
    private function collectPaymentTarget(
        array $settings,
        QuickBooksEntityChanged $event,
        array $target,
        array &$records,
        array &$inbound,
    ): void {
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
        // A payment made in QuickBooks has no link to apply, so the amount paid is read from
        // each invoice's QuickBooks balance, and Activity shows it as a change from QuickBooks.
        if (isset($this->paymentLookup()->unlinked[$event->realmId . '|' . $event->quickbooksId]) === true) {
            foreach ($target['quickbooks_invoices'] as $invoiceId) {
                $inbound[] = [
                    'entity'    => SyncEngine::INVOICE_PAYMENTS,
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
            if ($event->entityType === 'customer' && $event->operation === 'create') {
                $this->createdCustomers[$event->realmId][] = $event->quickbooksId;
            }

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
        // A removed payment can leave another one, or nothing, on the invoice. Read the amount paid
        // again from QuickBooks in a queued job; the request never calls QuickBooks.
        $refresh = [];
        foreach ($directory->takePaymentRemovedInvoiceIds($companyUuid) as $id) {
            $refresh[] = ['entity' => SyncEngine::INVOICE_PAYMENTS, 'id' => $id, 'operation' => 'update'];
        }
        if ($refresh !== []) {
            ApplyRemoteChange::dispatch($companyUuid, $refresh);
        }
    }

    private function recordKey(QuickBooksEntityChanged $event): string
    {
        return $event->entityType . '|' . $event->realmId . '|' . $event->quickbooksId;
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
    private function paymentInvoices(
        string $companyUuid,
        array $events,
        bool $allowDelete = false,
        bool $readUnlinked = false,
    ): array {
        $readInvoiceIds = $this->readsQuickBooks === true
            ? fn (string $company, string $realmId, array $paymentIds): array => $this->quickbooksInvoiceIdsForPayments($company, $realmId, $paymentIds)
            : null;

        return $this->paymentLookup()->resolve($companyUuid, $events, $allowDelete, $readInvoiceIds, $readUnlinked);
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
