<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Support\SafeLog;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's handling of QuickBooks deletes and merges: retiring local records and
 * links, invoice status after a lost payment, and the in-memory directory's version of the same.
 * It shares the directory's state, so it lives here only to keep FleetbaseDirectory.php readable.
 */
trait AppliesRemoteDeletes
{
    /**
     * QuickBooks deleted or voided this record. The local invoice is voided and its
     * link dropped. A customer or wallet is retired so catalog and pending sync
     * cannot create the remote record again. A payment link is dropped, and a paid
     * invoice it pointed at is set so the next sync does not create a replacement payment.
     *
     * @param array<int, string> $relatedInvoiceUuids Invoices a payment-id link applied to
     */
    public function releaseRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid, array $relatedInvoiceUuids = []): void
    {
        if ($companyUuid === '' || $localType === '') {
            return;
        }
        if ($this->memory !== null) {
            $this->releaseMemoryRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $localUuid, $relatedInvoiceUuids);

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $uuids = $this->linkedLocalUuids($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
            if (is_string($localUuid) === true && $localUuid !== '' && in_array($localUuid, $uuids, true) === false) {
                $uuids[] = $localUuid;
            }
            foreach ($uuids as $uuid) {
                $this->applyRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $uuid, $relatedInvoiceUuids);
            }
            if ($uuids === [] && $localType === 'payment') {
                $this->applyRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, (string) $localUuid, $relatedInvoiceUuids);
            }
            $this->dropRemoteLinks($companyUuid, $realmId, $localType, $quickbooksId);
            if ($localType === 'payment' && $quickbooksId !== '') {
                $this->dropPaymentInvoiceMap($companyUuid, $realmId, $quickbooksId);
            }
        } finally {
            if ($wasPaused === false) {
                SyncSuppressor::resume();
            }
        }
    }

    /**
     * Retire every delete or void in one delivery. One link select, one invoice
     * status update, one link delete, and one pending update. A payment id is not
     * applied to an invoice, customer, or wallet that merely shares that id.
     *
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>, invoices_from_payment?: bool}> $deletions
     */
    public function releaseRemoteDeletes(string $companyUuid, array $deletions): void
    {
        if ($companyUuid === '' || $deletions === []) {
            return;
        }
        if ($this->memory !== null) {
            foreach ($deletions as $deletion) {
                if (is_array($deletion) === false) {
                    continue;
                }
                $this->releaseRemoteDelete(
                    $companyUuid,
                    (string) ($deletion['realm_id'] ?? ''),
                    (string) ($deletion['local_type'] ?? ''),
                    (string) ($deletion['qbo_id'] ?? ''),
                    isset($deletion['local_uuid']) === true ? (string) $deletion['local_uuid'] : null,
                    is_array($deletion['invoice_uuids'] ?? null) === true ? $deletion['invoice_uuids'] : []
                );
            }

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $this->retireDeleteBatch($companyUuid, $deletions);
        } finally {
            if ($wasPaused === false) {
                SyncSuppressor::resume();
            }
        }
    }

    /**
     * QuickBooks ids of the invoices that lost a payment to a delete since the last call, so their
     * amount paid can be read again. Each id is returned once.
     *
     * @return array<int, string>
     */
    public function takePaymentRemovedInvoiceIds(string $companyUuid): array
    {
        $uuids = array_map('strval', array_keys($this->paymentRemoved[$companyUuid] ?? []));
        unset($this->paymentRemoved[$companyUuid]);
        if ($uuids === []) {
            return [];
        }
        $ids = [];
        if ($this->memory !== null) {
            foreach ($this->memory->links as $link) {
                $ours = is_array($link) === true
                    && ($link['company_uuid'] ?? '') === $companyUuid
                    && ($link['local_type'] ?? '') === 'invoice';
                if ($ours === true && in_array((string) ($link['local_uuid'] ?? ''), $uuids, true) === true) {
                    $ids[] = (string) ($link['qbo_id'] ?? '');
                }
            }
        } else {
            foreach (array_chunk($uuids, 500) as $chunk) {
                $found = Link::query()
                    ->where('company_uuid', $companyUuid)
                    ->where('local_type', 'invoice')
                    ->whereIn('local_uuid', $chunk)
                    ->pluck('qbo_id');
                foreach ($found as $id) {
                    $ids[] = (string) $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * Writes one inbound webhook batch to Activity with a skipped attempt per note,
     * for QuickBooks deletes that Fleetbase did not apply.
     *
     * @param array<int, array{local_type: string, local_uuid: string, error: string, outcome?: string}> $notes
     */
    public function noteRemoteDeletes(string $companyUuid, array $notes): void
    {
        $notes = $this->withoutRetiredLocally($companyUuid, $notes);
        if ($companyUuid === '' || $notes === []) {
            return;
        }
        $now   = time();
        $batch = [
            'company_uuid' => $companyUuid,
            'trigger'      => 'webhook',
            'direction'    => 'inbound',
            'status'       => 'finished',
            'skipped'      => 0,
            'linked'       => 0,
            'started_at'   => $now,
            'finished_at'  => $now,
        ];
        $attempts = [];
        foreach ($notes as $note) {
            $outcome = ($note['outcome'] ?? 'skipped') === 'linked' ? 'linked' : 'skipped';
            $batch[$outcome]++;
            $attempts[] = [
                'company_uuid' => $companyUuid,
                'local_type'   => $note['local_type'],
                'local_uuid'   => $note['local_uuid'],
                'outcome'      => $outcome,
                'error'        => $note['error'],
            ];
        }
        $this->saveNoteBatch($batch, $attempts);
    }

    /**
     * @param array<string, mixed>             $batch
     * @param array<int, array<string, mixed>> $attempts
     */
    private function saveNoteBatch(array $batch, array $attempts): void
    {
        if ($this->memory !== null) {
            $this->memory->batches[] = $batch;
            foreach ($attempts as $attempt) {
                $this->memory->attempts[] = $attempt;
            }

            return;
        }

        try {
            $record       = new SyncBatch();
            $record->uuid = (string) Str::uuid();
            $record->fill($this->batchAttributes($batch));
            $record->save();
            foreach (array_keys($attempts) as $index) {
                $attempts[$index]['batch_uuid'] = (string) $record->uuid;
            }
            $this->insertAttempts($attempts);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Points the links of a merged-away QuickBooks record at the record it went into.
     * True when a link moved, false when this company already links the surviving record
     * (both links are kept), null when nothing links the merged-away record.
     */
    public function followMergedRecord(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $fromId,
        string $intoId,
    ): ?bool {
        if ($companyUuid === '' || $localType === '' || $fromId === '' || $intoId === '' || $fromId === $intoId) {
            return null;
        }
        if ($this->memory !== null) {
            return $this->followMemoryMerge($companyUuid, $realmId, $localType, $fromId, $intoId);
        }

        try {
            $scope = fn (string $id) => Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('realm_id', $realmId)
                ->where('local_type', $localType)
                ->where('qbo_id', $id);
            if ($scope($fromId)->exists() === false) {
                return null;
            }
            if ($scope($intoId)->exists() === true) {
                return false;
            }
            // No SyncToken: the next sync lets QuickBooks, as Primary, win on the surviving record.
            $scope($fromId)->update(['qbo_id' => $intoId, 'sync_token' => '', 'updated_at' => Carbon::now()]);

            return true;
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return null;
            }

            throw $exception;
        }
    }

    private function followMemoryMerge(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $fromId,
        string $intoId,
    ): ?bool {
        $memory = $this->memory;
        if ($memory === null) {
            return null;
        }
        $from = null;
        foreach ($memory->links as $index => $link) {
            $other = ($link['company_uuid'] ?? '') !== $companyUuid
                || ($link['realm_id'] ?? '') !== $realmId
                || ($link['local_type'] ?? '') !== $localType;
            if ($other === true) {
                continue;
            }
            if ((string) ($link['qbo_id'] ?? '') === $intoId) {
                return false;
            }
            if ((string) ($link['qbo_id'] ?? '') === $fromId) {
                $from = $index;
            }
        }
        if ($from === null) {
            return null;
        }
        $memory->links[$from]['qbo_id']     = $intoId;
        $memory->links[$from]['sync_token'] = '';
        $memory->rebuildIndex();

        return true;
    }

    /**
     * A delete that echoes a void, delete or close Fleetbase already made needs no note.
     *
     * @param array<int, array{local_type: string, local_uuid: string, error: string, outcome?: string}> $notes
     *
     * @return array<int, array{local_type: string, local_uuid: string, error: string, outcome?: string}>
     */
    private function withoutRetiredLocally(string $companyUuid, array $notes): array
    {
        if ($this->memory !== null || $notes === []) {
            return $notes;
        }
        $classes = [
            'invoice'  => 'Fleetbase\\Ledger\\Models\\Invoice',
            'customer' => 'Fleetbase\\FleetOps\\Models\\Customer',
            'wallet'   => 'Fleetbase\\Ledger\\Models\\Wallet',
        ];
        $byType = [];
        foreach ($notes as $note) {
            if (isset($classes[$note['local_type']]) === true) {
                $byType[$note['local_type']][] = $note['local_uuid'];
            }
        }
        $retired = [];
        foreach ($byType as $type => $uuids) {
            foreach ($this->retiredUuids($classes[$type], $companyUuid, $type, $uuids) as $uuid) {
                $retired[$type . '|' . $uuid] = true;
            }
        }

        return array_values(array_filter(
            $notes,
            static fn (array $note): bool => isset($retired[$note['local_type'] . '|' . $note['local_uuid']]) === false
        ));
    }

    /**
     * @param class-string       $class
     * @param array<int, string> $uuids
     *
     * @return array<int, string>
     */
    private function retiredUuids(string $class, string $companyUuid, string $type, array $uuids): array
    {
        if (class_exists($class) === false) {
            return [];
        }

        try {
            $model = new $class();
            if ($model instanceof Model === false) {
                return [];
            }
            $query = $model->getConnection()->table($model->getTable())
                ->where('company_uuid', $companyUuid)
                ->whereIn('uuid', $uuids)
                ->where(function ($retired) use ($type): void {
                    $retired->whereNotNull('deleted_at');
                    if ($type === 'invoice') {
                        $retired->orWhereRaw("lower(status) in ('void', 'voided', 'cancelled', 'canceled')");
                    }
                    if ($type === 'wallet') {
                        $retired->orWhere('status', 'closed');
                    }
                });

            return array_map(static fn (mixed $uuid): string => (string) $uuid, $query->pluck('uuid')->all());
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    private function linkedLocalUuids(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): array
    {
        if ($quickbooksId === '' && ($localUuid === null || $localUuid === '')) {
            return [];
        }

        try {
            $query = Link::query()->where('company_uuid', $companyUuid)->where('local_type', $localType);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->where(function ($inner) use ($quickbooksId, $localUuid): void {
                $started = false;
                if ($quickbooksId !== '') {
                    $inner->where('qbo_id', $quickbooksId);
                    $started = true;
                }
                if (is_string($localUuid) === true && $localUuid !== '') {
                    $method = $started === true ? 'orWhere' : 'where';
                    $inner->{$method}('local_uuid', $localUuid);
                }
            });
            $ids = [];
            foreach ($query->pluck('local_uuid') as $uuid) {
                $id = (string) $uuid;
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     */
    private function applyRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, string $localUuid, array $relatedInvoiceUuids = []): void
    {
        if ($localType === 'invoice') {
            $this->setInvoiceStatus($companyUuid, $localUuid, 'void');
            $this->dropLocalLinks($companyUuid, $realmId, 'invoice', $localUuid);
            $this->dropLocalLinks($companyUuid, $realmId, 'payment', $localUuid);
            $this->finishPending($companyUuid, 'invoice', $localUuid);

            return;
        }
        if ($localType === 'payment') {
            if ($localUuid !== '') {
                $this->dropLocalLinks($companyUuid, $realmId, 'payment', $localUuid);
            }
            foreach ($this->paymentInvoiceTargets($companyUuid, $realmId, $quickbooksId, $localUuid, $relatedInvoiceUuids) as $invoiceUuid) {
                $this->settleInvoiceAfterPaymentRemoved($companyUuid, $invoiceUuid);
            }

            return;
        }
        if ($localType === 'customer') {
            $this->retireLocal('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, $localUuid, []);
            $this->dropLocalLinks($companyUuid, $realmId, 'customer', $localUuid);
            $this->finishPending($companyUuid, 'customer', $localUuid);

            return;
        }
        if ($localType === 'wallet') {
            if ($this->walletsHoldingBalance($companyUuid, [$localUuid]) !== []) {
                $this->finishPending($companyUuid, 'wallet', $localUuid);

                return;
            }
            $this->retireLocal('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, $localUuid, ['status' => 'closed']);
            $this->dropLocalLinks($companyUuid, $realmId, 'wallet', $localUuid);
            $this->finishPending($companyUuid, 'wallet', $localUuid);
        }
    }

    /**
     * Invoice uuids a removed payment applied to. A link stored under the QuickBooks
     * payment id is not itself the invoice.
     *
     * @param array<int, string> $relatedInvoiceUuids
     *
     * @return array<int, string>
     */
    private function paymentInvoiceTargets(string $companyUuid, string $realmId, string $quickbooksId, string $localUuid, array $relatedInvoiceUuids): array
    {
        $targets = [];
        if ($localUuid !== '' && $localUuid !== $quickbooksId) {
            $targets[] = $localUuid;
        }
        if ($localUuid === '' || $localUuid === $quickbooksId) {
            foreach ($relatedInvoiceUuids as $invoiceUuid) {
                $invoiceUuid = (string) $invoiceUuid;
                if ($invoiceUuid !== '' && $invoiceUuid !== $quickbooksId) {
                    $targets[] = $invoiceUuid;
                }
            }
            foreach ($this->storedPaymentInvoices($companyUuid, $realmId, $quickbooksId) as $invoiceUuid) {
                $targets[] = $invoiceUuid;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @return array<int, string>
     */
    private function storedPaymentInvoices(string $companyUuid, string $realmId, string $paymentId): array
    {
        if ($companyUuid === '' || $paymentId === '' || $this->memory !== null) {
            return [];
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', 'payment-invoice')
                ->where('local_uuid', $paymentId);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $ids = [];
            foreach ($query->pluck('qbo_id') as $id) {
                $invoiceUuid = (string) $id;
                if ($invoiceUuid !== '' && $invoiceUuid !== $paymentId) {
                    $ids[] = $invoiceUuid;
                }
            }

            return array_values(array_unique($ids));
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    private function settleInvoiceAfterPaymentRemoved(string $companyUuid, string $invoiceUuid): void
    {
        $status = $this->invoiceStatus($companyUuid, $invoiceUuid);
        if ($status === null) {
            return;
        }
        if (in_array(strtolower($status), ['paid', 'partial'], true) === true) {
            $this->setInvoiceStatus($companyUuid, $invoiceUuid, 'sent');
        }
        $this->finishPending($companyUuid, 'invoice', $invoiceUuid);
        $this->paymentRemoved[$companyUuid][$invoiceUuid] = true;
    }

    private function dropPaymentInvoiceMap(string $companyUuid, string $realmId, string $paymentId): void
    {
        if ($companyUuid === '' || $paymentId === '') {
            return;
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', 'payment-invoice')
                ->where('local_uuid', $paymentId);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>, invoices_from_payment?: bool}> $deletions
     */
    private function retireDeleteBatch(string $companyUuid, array $deletions): void
    {
        $state = [
            'void_invoices'   => [],
            'sent_invoices'   => [],
            'customers'       => [],
            'wallets'         => [],
            'link_uuids'      => [],
            'wallet_links'    => [],
            'void_realms'     => [],
            'named_payments'  => [],
            'wallet_accounts' => [],
        ];
        $this->collectDeletionRecords($deletions, $state);
        $this->collectDeletionLinks($this->linksForDeletions($companyUuid, $deletions), $deletions, $state);
        $this->applyDeletionBatch($companyUuid, $state);
    }

    /**
     * @param array<int, array<string, mixed>> $deletions
     * @param array<string, mixed>             $state
     */
    private function collectDeletionRecords(array $deletions, array &$state): void
    {
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false) {
                continue;
            }
            $type  = (string) ($deletion['local_type'] ?? '');
            $realm = (string) ($deletion['realm_id'] ?? '');
            $qbo   = (string) ($deletion['qbo_id'] ?? '');
            $local = (string) ($deletion['local_uuid'] ?? '');
            if ($type === 'invoice' && $local !== '') {
                $state['void_invoices'][]             = $local;
                $state['void_realms'][$realm][$local] = true;
            }
            if ($type === 'customer' && $local !== '') {
                $state['customers'][] = $local;
            }
            if ($type === 'wallet' && $local !== '') {
                $state['wallets'][] = $local;
                $state['wallet_accounts'][$local] ??= $qbo;
            }
            if ($type === 'payment') {
                $this->collectPaymentDeletion($deletion, $realm, $qbo, $local, $state);
            }
        }
    }

    /**
     * @param array<string, mixed> $deletion
     * @param array<string, mixed> $state
     */
    private function collectPaymentDeletion(array $deletion, string $realm, string $qbo, string $local, array &$state): void
    {
        if (empty($deletion['invoices_from_payment']) === false && $qbo !== '') {
            // The payment was read. Its lines name every invoice to unmark.
            // A stored fallback for a different invoice must not be applied.
            $state['named_payments'][$realm . '|' . $qbo] = true;
        }
        if ($local !== '' && $local !== $qbo) {
            $state['sent_invoices'][] = $local;

            return;
        }
        $named = is_array($deletion['invoice_uuids'] ?? null) === true ? $deletion['invoice_uuids'] : [];
        foreach ($named as $invoiceUuid) {
            $invoiceUuid = (string) $invoiceUuid;
            if ($invoiceUuid !== '' && $invoiceUuid !== $qbo) {
                $state['sent_invoices'][] = $invoiceUuid;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>>                                                                                                         $links
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>}> $deletions
     * @param array<string, mixed>                                                                                                                     $state
     */
    private function collectDeletionLinks(array $links, array $deletions, array &$state): void
    {
        foreach ($links as $link) {
            $type  = (string) ($link['local_type'] ?? '');
            $local = (string) ($link['local_uuid'] ?? '');
            $qbo   = (string) ($link['qbo_id'] ?? '');
            $realm = (string) ($link['realm_id'] ?? '');
            $uuid  = (string) ($link['uuid'] ?? '');
            if ($type === 'payment-invoice') {
                $this->collectPaymentInvoiceLink($uuid, $realm, $local, $qbo, $state);
                continue;
            }
            if ($this->deletionMatchesLink($deletions, $type, $realm, $qbo, $local) === false) {
                continue;
            }
            $this->collectMatchedDeletionLink($type, $realm, $qbo, $local, $uuid, $state);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collectPaymentInvoiceLink(string $uuid, string $realm, string $local, string $qbo, array &$state): void
    {
        if ($uuid !== '') {
            $state['link_uuids'][] = $uuid;
        }
        if (isset($state['named_payments'][$realm . '|' . $local]) === false && $qbo !== '' && $qbo !== $local) {
            $state['sent_invoices'][] = $qbo;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collectMatchedDeletionLink(string $type, string $realm, string $qbo, string $local, string $uuid, array &$state): void
    {
        if ($uuid !== '' && $type === 'wallet' && $local !== '') {
            // Dropped below only when the wallet is really closed.
            $state['wallet_links'][$local][]  = $uuid;
            $state['wallet_accounts'][$local] = $qbo;
        } elseif ($uuid !== '') {
            $state['link_uuids'][] = $uuid;
        }
        if ($local === '') {
            return;
        }
        $this->recordDeletionTarget($type, $realm, $qbo, $local, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordDeletionTarget(string $type, string $realm, string $qbo, string $local, array &$state): void
    {
        if ($type === 'invoice') {
            $state['void_invoices'][]             = $local;
            $state['void_realms'][$realm][$local] = true;

            return;
        }
        if ($type === 'payment' && $local !== $qbo) {
            $state['sent_invoices'][] = $local;

            return;
        }
        if ($type === 'customer') {
            $state['customers'][] = $local;

            return;
        }
        if ($type === 'wallet') {
            $state['wallets'][] = $local;
        }
    }

    /**
     * @param array{
     *     void_invoices: array<int, string>,
     *     sent_invoices: array<int, string>,
     *     customers: array<int, string>,
     *     wallets: array<int, string>,
     *     link_uuids: array<int, string>,
     *     wallet_links: array<string, array<int, string>>,
     *     void_realms: array<string, array<string, true>>,
     *     named_payments: array<string, true>,
     *     wallet_accounts: array<string, string>
     * } $state
     */
    private function applyDeletionBatch(string $companyUuid, array $state): void
    {
        $voidInvoices = array_values(array_unique($state['void_invoices']));
        $sentInvoices = array_values(array_unique(array_filter(
            $state['sent_invoices'],
            static fn (string $uuid): bool => $uuid !== '' && in_array($uuid, $voidInvoices, true) === false
        )));
        $customers = array_values(array_unique($state['customers']));
        // Every deleted wallet's pending row is finished, held or not: pushing a held wallet
        // would reactivate the account that was deleted in QuickBooks.
        $settled   = array_values(array_unique($state['wallets']));
        $held      = $this->walletsHoldingBalance($companyUuid, $settled);
        $wallets   = array_values(array_diff($settled, $held));
        $linkUuids = $state['link_uuids'];
        foreach ($wallets as $wallet) {
            foreach ($state['wallet_links'][$wallet] ?? [] as $walletLink) {
                $linkUuids[] = $walletLink;
            }
        }
        $linkUuids = array_values(array_unique($linkUuids));

        $this->updateInvoiceStatuses($companyUuid, $voidInvoices, $sentInvoices);
        foreach ($sentInvoices as $invoiceUuid) {
            $this->paymentRemoved[$companyUuid][$invoiceUuid] = true;
        }
        $this->retireMany('Fleetbase\\FleetOps\\Models\\Customer', $companyUuid, $customers, []);
        $this->retireMany('Fleetbase\\Ledger\\Models\\Wallet', $companyUuid, $wallets, ['status' => 'closed']);
        $this->deleteRetiredLinks($companyUuid, $linkUuids, $state['void_realms']);
        $this->finishPendingMany($companyUuid, [
            'invoice'  => array_values(array_unique(array_merge($voidInvoices, $sentInvoices))),
            'customer' => $customers,
            'wallet'   => $settled,
        ]);
        $notes = [];
        foreach ($held as $wallet) {
            $account = (string) ($state['wallet_accounts'][$wallet] ?? '');
            $notes[] = [
                'local_type' => 'wallet',
                'local_uuid' => $wallet,
                'error'      => Translation::encode(
                    'quickbooks.messages.remote-delete-wallet-balance',
                    ['id' => $account]
                ),
            ];
        }
        $this->noteRemoteDeletes($companyUuid, $notes);
    }

    /**
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>}> $deletions
     */
    private function deletionMatchesLink(array $deletions, string $type, string $realm, string $qbo, string $local): bool
    {
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false || (string) ($deletion['local_type'] ?? '') !== $type) {
                continue;
            }
            $deletionRealm = (string) ($deletion['realm_id'] ?? '');
            if ($deletionRealm !== '' && $realm !== $deletionRealm) {
                continue;
            }
            $deletionQbo   = (string) ($deletion['qbo_id'] ?? '');
            $deletionLocal = (string) ($deletion['local_uuid'] ?? '');
            if ($deletionQbo !== '' && $qbo === $deletionQbo) {
                return true;
            }
            if ($deletionLocal !== '' && $local === $deletionLocal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{realm_id?: string, local_type?: string, qbo_id?: string, local_uuid?: string|null, invoice_uuids?: array<int, string>}> $deletions
     *
     * @return array<int, array<string, mixed>>
     */
    private function linksForDeletions(string $companyUuid, array $deletions): array
    {
        [$clauses, $paymentIds] = $this->deletionLookup($deletions);
        if ($clauses === [] && $paymentIds === []) {
            return [];
        }

        try {
            $links = $this->queryDeletionLinks($companyUuid, $clauses, $paymentIds);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }

        return $this->deletionLinkRows($links);
    }

    /**
     * @param array<int, array<string, mixed>> $deletions
     *
     * @return array{0: array<int, array{type: string, realm: string, qbo: string, local: string}>, 1: array<int, string>}
     */
    private function deletionLookup(array $deletions): array
    {
        $paymentIds = [];
        $clauses    = [];
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false) {
                continue;
            }
            $type  = (string) ($deletion['local_type'] ?? '');
            $realm = (string) ($deletion['realm_id'] ?? '');
            $qbo   = (string) ($deletion['qbo_id'] ?? '');
            $local = (string) ($deletion['local_uuid'] ?? '');
            if ($type === '' || ($qbo === '' && $local === '')) {
                continue;
            }
            if ($type === 'payment' && $qbo !== '') {
                $paymentIds[] = $qbo;
            }
            $clauses[] = ['type' => $type, 'realm' => $realm, 'qbo' => $qbo, 'local' => $local];
        }

        return [$clauses, array_values(array_unique($paymentIds))];
    }

    /**
     * @param array<int, array{type: string, realm: string, qbo: string, local: string}> $clauses
     * @param array<int, string>                                                         $paymentIds
     *
     * @return iterable<Model|\Illuminate\Database\Eloquent\Builder>
     */
    private function queryDeletionLinks(string $companyUuid, array $clauses, array $paymentIds): iterable
    {
        return Link::query()
            ->where('company_uuid', $companyUuid)
            ->where(function ($scope) use ($clauses, $paymentIds): void {
                $this->constrainDeletionScope($scope, $clauses, $paymentIds);
            })
            ->get(['uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_id']);
    }

    /**
     * @param array<int, array{type: string, realm: string, qbo: string, local: string}> $clauses
     * @param array<int, string>                                                         $paymentIds
     */
    private function constrainDeletionScope(\Illuminate\Database\Eloquent\Builder $scope, array $clauses, array $paymentIds): void
    {
        $started = false;
        foreach ($clauses as $clause) {
            $method = $started === true ? 'orWhere' : 'where';
            $scope->{$method}(function ($inner) use ($clause): void {
                $this->constrainDeletionClause($inner, $clause);
            });
            $started = true;
        }
        if ($paymentIds !== []) {
            $method = $started === true ? 'orWhere' : 'where';
            $scope->{$method}(function ($inner) use ($paymentIds): void {
                $inner->where('local_type', 'payment-invoice')->whereIn('local_uuid', $paymentIds);
            });
        }
    }

    /**
     * @param array{type: string, realm: string, qbo: string, local: string} $clause
     */
    private function constrainDeletionClause(\Illuminate\Database\Eloquent\Builder $inner, array $clause): void
    {
        $inner->where('local_type', $clause['type']);
        if ($clause['realm'] !== '') {
            $inner->where('realm_id', $clause['realm']);
        }
        $inner->where(function ($match) use ($clause): void {
            $this->constrainDeletionMatch($match, $clause);
        });
    }

    /**
     * @param array{type: string, realm: string, qbo: string, local: string} $clause
     */
    private function constrainDeletionMatch(\Illuminate\Database\Eloquent\Builder $match, array $clause): void
    {
        $open = false;
        if ($clause['qbo'] !== '') {
            $match->where('qbo_id', $clause['qbo']);
            $open = true;
        }
        if ($clause['local'] !== '') {
            $way = $open === true ? 'orWhere' : 'where';
            $match->{$way}('local_uuid', $clause['local']);
        }
    }

    /**
     * @param iterable<Model|\Illuminate\Database\Eloquent\Builder> $links
     *
     * @return array<int, array<string, mixed>>
     */
    private function deletionLinkRows(iterable $links): array
    {
        $rows = [];
        foreach ($links as $link) {
            if (is_object($link) === true) {
                $rows[] = [
                    'uuid'       => (string) $link->uuid,
                    'realm_id'   => (string) $link->realm_id,
                    'local_type' => (string) $link->local_type,
                    'local_uuid' => (string) $link->local_uuid,
                    'qbo_id'     => (string) $link->qbo_id,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     */
    private function updateInvoiceStatuses(string $companyUuid, array $voidUuids, array $sentUuids): void
    {
        if (($voidUuids === [] && $sentUuids === []) || $companyUuid === '') {
            return;
        }
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false) {
            return;
        }

        try {
            $model      = new $class();
            $connection = $model->getConnection();
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
        $grammar                      = $connection->getQueryGrammar();
        [$cases, $filters, $bindings] = $this->invoiceStatusCases($grammar, $voidUuids, $sentUuids);
        $this->runInvoiceStatusUpdate($connection, $grammar, $model->getTable(), $companyUuid, $voidUuids, $sentUuids, $cases, $filters, $bindings);
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, mixed>}
     */
    private function invoiceStatusCases(\Illuminate\Database\Grammar $grammar, array $voidUuids, array $sentUuids): array
    {
        $uuidColumn = $grammar->wrap('uuid');
        $status     = $grammar->wrap('status');
        $cases      = [];
        $bindings   = [];
        $filters    = [];
        if ($voidUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($voidUuids), '?'));
            $cases[]      = 'WHEN ' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") NOT IN ('void', 'voided', 'cancelled', 'canceled') THEN 'void'";
            $filters[]    = '(' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") NOT IN ('void', 'voided', 'cancelled', 'canceled'))";
            foreach ($voidUuids as $uuid) {
                $bindings[] = $uuid;
            }
        }
        if ($sentUuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($sentUuids), '?'));
            $cases[]      = 'WHEN ' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") IN ('paid', 'partial') THEN 'sent'";
            $filters[]    = '(' . $uuidColumn . ' IN (' . $placeholders . ') AND lower(' . $status . ") IN ('paid', 'partial'))";
            foreach ($sentUuids as $uuid) {
                $bindings[] = $uuid;
            }
        }

        return [$cases, $filters, $bindings];
    }

    /**
     * @param array<int, string> $voidUuids
     * @param array<int, string> $sentUuids
     * @param array<int, string> $cases
     * @param array<int, string> $filters
     * @param array<int, mixed>  $bindings
     */
    private function runInvoiceStatusUpdate(\Illuminate\Database\Connection $connection, \Illuminate\Database\Grammar $grammar, string $tableName, string $companyUuid, array $voidUuids, array $sentUuids, array $cases, array $filters, array $bindings): void
    {
        $table           = $grammar->wrapTable($tableName);
        $uuidColumn      = $grammar->wrap('uuid');
        $status          = $grammar->wrap('status');
        $whereBindings   = $bindings;
        $bindings[]      = Carbon::now()->toDateTimeString();
        $bindings[]      = $companyUuid;
        $all             = array_values(array_unique(array_merge($voidUuids, $sentUuids)));
        $allPlaceholders = implode(', ', array_fill(0, count($all), '?'));
        foreach ($all as $uuid) {
            $bindings[] = $uuid;
        }
        foreach ($whereBindings as $binding) {
            $bindings[] = $binding;
        }
        $sql = 'UPDATE ' . $table
            . ' SET ' . $status . ' = CASE ' . implode(' ', $cases) . ' ELSE ' . $status . ' END, '
            . $grammar->wrap('updated_at') . ' = ?'
            . ' WHERE ' . $grammar->wrap('company_uuid') . ' = ? AND ' . $grammar->wrap('deleted_at') . ' IS NULL'
            . ' AND ' . $uuidColumn . ' IN (' . $allPlaceholders . ')'
            . ' AND (' . implode(' OR ', $filters) . ')';
        try {
            $connection->update($sql, $bindings);
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Wallets that still hold money are not closed when QuickBooks deletes their account:
     * closing them would hide a balance. They stay open and linked, and the log says why.
     * When the balance cannot be read, every wallet is kept.
     *
     * @param array<int, string> $uuids
     *
     * @return array<int, string>
     */
    private function walletsHoldingBalance(string $companyUuid, array $uuids): array
    {
        $class = 'Fleetbase\\Ledger\\Models\\Wallet';
        $uuids = array_values(array_filter($uuids, static fn (string $uuid): bool => $uuid !== ''));
        if ($uuids === [] || $companyUuid === '' || class_exists($class) === false) {
            return [];
        }

        try {
            $held = array_map(
                static fn (mixed $uuid): string => (string) $uuid,
                $class::query()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->where('balance', '!=', 0)->pluck('uuid')->all()
            );
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks deleted a wallet account, but the wallet balance could not be read, so the wallet stays open.', [
                'company_uuid' => $companyUuid,
                'wallet_uuids' => $uuids,
                'exception'    => $exception::class,
            ]);

            return $uuids;
        }
        if ($held !== []) {
            SafeLog::warning('QuickBooks deleted a wallet account, but the wallet still holds a balance, so it stays open and linked.', [
                'company_uuid' => $companyUuid,
                'wallet_uuids' => $held,
            ]);
        }

        return $held;
    }

    /**
     * @param class-string         $class
     * @param array<int, string>   $uuids
     * @param array<string, mixed> $extra
     */
    private function retireMany(string $class, string $companyUuid, array $uuids, array $extra): void
    {
        if ($uuids === [] || $companyUuid === '' || class_exists($class) === false) {
            return;
        }

        try {
            $class::query()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->update(array_merge($extra, [
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string>                 $linkUuids
     * @param array<string, array<string, true>> $voidInvoicesByRealm
     */
    private function deleteRetiredLinks(string $companyUuid, array $linkUuids, array $voidInvoicesByRealm): void
    {
        if ($companyUuid === '' || ($linkUuids === [] && $voidInvoicesByRealm === [])) {
            return;
        }

        try {
            Link::query()->where('company_uuid', $companyUuid)->where(function ($query) use ($linkUuids, $voidInvoicesByRealm): void {
                $started = false;
                if ($linkUuids !== []) {
                    $query->whereIn('uuid', $linkUuids);
                    $started = true;
                }
                foreach ($voidInvoicesByRealm as $realm => $ids) {
                    $invoiceUuids = array_keys($ids);
                    if ($invoiceUuids === []) {
                        continue;
                    }
                    $method = $started === true ? 'orWhere' : 'where';
                    $query->{$method}(function ($inner) use ($realm, $invoiceUuids): void {
                        $inner->where('local_type', 'payment')->whereIn('local_uuid', $invoiceUuids);
                        if ($realm !== '') {
                            $inner->where('realm_id', $realm);
                        }
                    });
                    $started = true;
                }
            })->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, array<int, string>> $uuidsByType
     */
    private function finishPendingMany(string $companyUuid, array $uuidsByType): void
    {
        $pairs = [];
        foreach ($uuidsByType as $type => $uuids) {
            foreach ($uuids as $uuid) {
                if ($uuid !== '') {
                    $pairs[] = [(string) $type, $uuid];
                }
            }
        }
        if ($companyUuid === '' || $pairs === []) {
            return;
        }

        try {
            PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('status', 'pending')
                ->where(function ($query) use ($pairs): void {
                    foreach ($pairs as $index => $pair) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}(function ($inner) use ($pair): void {
                            $inner->where('local_type', $pair[0])->where('local_uuid', $pair[1]);
                        });
                    }
                })
                ->update([
                    'status'     => 'done',
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function setInvoiceStatus(string $companyUuid, string $uuid, string $status): void
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false || $companyUuid === '' || $uuid === '') {
            return;
        }

        try {
            $current = $this->invoiceTable($class, $companyUuid, $uuid)->value('status');
            if (is_string($current) === false) {
                return;
            }
            if ($status === 'void' && in_array(strtolower($current), ['void', 'voided', 'cancelled', 'canceled'], true) === true) {
                return;
            }
            if ($current === $status) {
                return;
            }
            // The invoice model eager-loads lines. A table update changes status only and leaves integer money alone.
            $this->invoiceTable($class, $companyUuid, $uuid)->update([
                'status'     => $status,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function invoiceStatus(string $companyUuid, string $uuid): ?string
    {
        $class = 'Fleetbase\\Ledger\\Models\\Invoice';
        if (class_exists($class) === false || $companyUuid === '' || $uuid === '') {
            return null;
        }

        try {
            $status = $this->invoiceTable($class, $companyUuid, $uuid)->value('status');
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return null;
            }

            throw $exception;
        }

        return is_string($status) === true ? $status : null;
    }

    /**
     * @param class-string $class
     */
    private function invoiceTable(string $class, string $companyUuid, string $uuid): \Illuminate\Database\Query\Builder
    {
        $model = new $class();

        return $model->getConnection()->table($model->getTable())
            ->where('company_uuid', $companyUuid)
            ->where('uuid', $uuid)
            ->whereNull('deleted_at');
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $extra
     */
    private function retireLocal(string $class, string $companyUuid, string $uuid, array $extra): void
    {
        if (class_exists($class) === false || $companyUuid === '' || $uuid === '') {
            return;
        }

        try {
            $class::query()->where('company_uuid', $companyUuid)->where('uuid', $uuid)->update(array_merge($extra, [
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function dropLocalLinks(string $companyUuid, string $realmId, string $localType, string $localUuid): void
    {
        if ($companyUuid === '' || $localType === '' || $localUuid === '') {
            return;
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('local_uuid', $localUuid);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function dropRemoteLinks(string $companyUuid, string $realmId, string $localType, string $quickbooksId): void
    {
        if ($companyUuid === '' || $localType === '' || $quickbooksId === '') {
            return;
        }

        try {
            $query = Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('qbo_id', $quickbooksId);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    private function finishPending(string $companyUuid, string $localType, string $localUuid): void
    {
        if ($companyUuid === '' || $localType === '' || $localUuid === '') {
            return;
        }

        try {
            PendingSync::query()
                ->where('company_uuid', $companyUuid)
                ->where('local_type', $localType)
                ->where('local_uuid', $localUuid)
                ->where('status', 'pending')
                ->update([
                    'status'     => 'done',
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     */
    private function releaseMemoryRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid, array $relatedInvoiceUuids = []): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }

        $mappedInvoices = $this->mappedMemoryInvoices($companyUuid, $realmId, $localType, $quickbooksId);
        [$uuids, $kept] = $this->droppedMemoryLinks($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
        $memory->links  = $kept;
        foreach (array_values(array_unique($uuids)) as $uuid) {
            $this->applyMemoryRemoteDelete($companyUuid, $realmId, $localType, $quickbooksId, $uuid, $relatedInvoiceUuids, $mappedInvoices);
        }
        $memory->rebuildIndex();
    }

    /**
     * @return array<int, string>
     */
    private function mappedMemoryInvoices(string $companyUuid, string $realmId, string $localType, string $quickbooksId): array
    {
        $mappedInvoices = [];
        $memory         = $this->memory;
        if ($localType !== 'payment' || $quickbooksId === '' || $memory === null) {
            return $mappedInvoices;
        }
        foreach ($memory->links as $link) {
            if ($this->memoryPaymentInvoiceLink($link, $companyUuid, $realmId, $quickbooksId) === false) {
                continue;
            }
            $invoiceUuid = (string) ($link['qbo_id'] ?? '');
            if ($invoiceUuid !== '' && $invoiceUuid !== $quickbooksId) {
                $mappedInvoices[] = $invoiceUuid;
            }
        }

        return $mappedInvoices;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    private function droppedMemoryLinks(string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): array
    {
        $uuids  = [];
        $kept   = [];
        $memory = $this->memory;
        if ($memory === null) {
            return [$uuids, $kept];
        }
        foreach ($memory->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            $dropped = $this->droppedMemoryLinkUuid($link, $companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
            if ($dropped === null) {
                $kept[] = $link;
                continue;
            }
            if ($dropped !== '') {
                $uuids[] = $dropped;
            }
        }
        if (is_string($localUuid) === true && $localUuid !== '') {
            $uuids[] = $localUuid;
        }

        return [$uuids, $kept];
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     * @param array<int, string> $mappedInvoices
     */
    private function applyMemoryRemoteDelete(string $companyUuid, string $realmId, string $localType, string $quickbooksId, string $uuid, array $relatedInvoiceUuids, array $mappedInvoices): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }
        $pendingUuids = [$uuid];
        if ($localType === 'invoice') {
            $this->voidMemoryInvoice($companyUuid, $realmId, $uuid);
        } elseif ($localType === 'payment') {
            $pendingUuids = $this->unpayMemoryInvoices($companyUuid, $realmId, $quickbooksId, $uuid, $relatedInvoiceUuids, $mappedInvoices);
        } elseif ($localType === 'customer') {
            unset($memory->customers[$uuid]);
        } elseif ($localType === 'wallet') {
            unset($memory->wallets[$uuid]);
        }
        $this->finishMemoryPending($companyUuid, $localType, $quickbooksId, $pendingUuids);
    }

    /**
     * Null keeps the link. Otherwise the link is dropped, and a non-empty value is the local uuid it
     * pointed at. A payment's invoice map is dropped without a uuid, and only on a payment delete:
     * QuickBooks numbers each entity type separately, so an invoice can share a payment's id.
     *
     * @param array<string, mixed> $link
     */
    private function droppedMemoryLinkUuid(array $link, string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): ?string
    {
        if ($localType === 'payment' && $this->memoryPaymentInvoiceLink($link, $companyUuid, $realmId, $quickbooksId) === true) {
            return '';
        }
        if ($this->memoryLinkMatches($link, $companyUuid, $realmId, $localType, $quickbooksId, $localUuid) === false) {
            return null;
        }

        return (string) ($link['local_uuid'] ?? '');
    }

    private function memoryPaymentInvoiceLink(mixed $link, string $companyUuid, string $realmId, string $quickbooksId): bool
    {
        if (is_array($link) === false) {
            return false;
        }

        return (string) ($link['local_type'] ?? '') === 'payment-invoice'
            && (string) ($link['company_uuid'] ?? '') === $companyUuid
            && ($realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId)
            && (string) ($link['local_uuid'] ?? '') === $quickbooksId;
    }

    /**
     * @param array<string, mixed> $link
     */
    private function memoryLinkMatches(array $link, string $companyUuid, string $realmId, string $localType, string $quickbooksId, ?string $localUuid): bool
    {
        $sameCompany = (string) ($link['company_uuid'] ?? '') === $companyUuid;
        $sameRealm   = $realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId;
        $sameType    = (string) ($link['local_type'] ?? '') === $localType;
        $sameRemote  = $quickbooksId !== '' && (string) ($link['qbo_id'] ?? '') === $quickbooksId;
        $sameLocal   = is_string($localUuid) === true && $localUuid !== '' && (string) ($link['local_uuid'] ?? '') === $localUuid;

        return $sameCompany === true && $sameRealm === true && $sameType === true && ($sameRemote === true || $sameLocal === true);
    }

    private function voidMemoryInvoice(string $companyUuid, string $realmId, string $uuid): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }
        if (isset($memory->invoices[$uuid]) === true && is_array($memory->invoices[$uuid]) === true) {
            $memory->invoices[$uuid]['status'] = 'void';
        }
        $memory->links = array_values(array_filter(
            $memory->links,
            function ($link) use ($companyUuid, $realmId, $uuid): bool {
                return $this->keepMemoryLink($link, $companyUuid, $realmId, $uuid);
            }
        ));
    }

    private function keepMemoryLink(mixed $link, string $companyUuid, string $realmId, string $uuid): bool
    {
        if (is_array($link) === false) {
            return false;
        }
        $payment = (string) ($link['local_type'] ?? '') === 'payment'
            && (string) ($link['company_uuid'] ?? '') === $companyUuid
            && ($realmId === '' || (string) ($link['realm_id'] ?? '') === $realmId)
            && (string) ($link['local_uuid'] ?? '') === $uuid;

        return $payment === false;
    }

    /**
     * @param array<int, string> $relatedInvoiceUuids
     * @param array<int, string> $mappedInvoices
     *
     * @return array<int, string>
     */
    private function unpayMemoryInvoices(string $companyUuid, string $realmId, string $quickbooksId, string $uuid, array $relatedInvoiceUuids, array $mappedInvoices): array
    {
        $pendingUuids = $this->paymentInvoiceTargets($companyUuid, $realmId, $quickbooksId, $uuid, array_merge($relatedInvoiceUuids, $mappedInvoices));
        $memory       = $this->memory;
        if ($memory === null) {
            return $pendingUuids;
        }
        foreach ($pendingUuids as $invoiceUuid) {
            if (isset($memory->invoices[$invoiceUuid]) === false || is_array($memory->invoices[$invoiceUuid]) === false) {
                continue;
            }
            $status = strtolower((string) ($memory->invoices[$invoiceUuid]['status'] ?? ''));
            if (in_array($status, ['paid', 'partial'], true) === true) {
                $memory->invoices[$invoiceUuid]['status'] = 'sent';
            }
            $this->paymentRemoved[$companyUuid][$invoiceUuid] = true;
        }

        return $pendingUuids;
    }

    /**
     * @param array<int, string> $pendingUuids
     */
    private function finishMemoryPending(string $companyUuid, string $localType, string $quickbooksId, array $pendingUuids): void
    {
        $memory = $this->memory;
        if ($memory === null) {
            return;
        }
        $pendingType = $localType === 'payment' ? 'invoice' : $localType;
        foreach ($pendingUuids as $pendingUuid) {
            if ($localType === 'payment' && $pendingUuid === $quickbooksId) {
                continue;
            }
            foreach ($memory->pending as $index => $row) {
                if ($this->pendingRowMatches($row, $companyUuid, $pendingType, $pendingUuid) === true) {
                    $memory->pending[$index]['status'] = 'done';
                }
            }
        }
    }

    private function pendingRowMatches(mixed $row, string $companyUuid, string $pendingType, string $pendingUuid): bool
    {
        if (is_array($row) === false) {
            return false;
        }

        return (string) ($row['company_uuid'] ?? '') === $companyUuid
            && (string) ($row['local_type'] ?? '') === $pendingType
            && (string) ($row['local_uuid'] ?? '') === $pendingUuid
            && (string) ($row['status'] ?? '') === 'pending';
    }
}
