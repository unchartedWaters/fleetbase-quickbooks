<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Support\SyncSuppressor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FleetbaseDirectory's handling of QuickBooks deletes and merges: retiring local records and
 * links, invoice status after a lost payment, and the in-memory directory's version of the same.
 * It shares the directory's state, so it lives here only to keep FleetbaseDirectory.php readable.
 *
 * @phpstan-import-type RemoteDelete from FleetbaseDirectory
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
    public function releaseRemoteDelete(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $quickbooksId,
        ?string $localUuid,
        array $relatedInvoiceUuids = [],
    ): void {
        if ($companyUuid === '' || $localType === '') {
            return;
        }
        if ($this->memory !== null) {
            $this->releaseMemoryRemoteDelete(
                $companyUuid,
                $realmId,
                $localType,
                $quickbooksId,
                $localUuid,
                $relatedInvoiceUuids
            );

            return;
        }

        $wasPaused = SyncSuppressor::paused();
        SyncSuppressor::pause();
        try {
            $uuids = $this->deletedLocalUuids($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
            foreach ($uuids as $uuid) {
                $this->applyRemoteDelete(
                    $companyUuid,
                    $realmId,
                    $localType,
                    $quickbooksId,
                    $uuid,
                    $relatedInvoiceUuids
                );
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
     * The local records a QuickBooks delete reaches: the linked ones and the named one.
     * A payment with no local record still runs once, under the given id.
     *
     * @return array<int, string>
     */
    private function deletedLocalUuids(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $quickbooksId,
        ?string $localUuid,
    ): array {
        $uuids = $this->linkedLocalUuids($companyUuid, $realmId, $localType, $quickbooksId, $localUuid);
        if (is_string($localUuid) === true && $localUuid !== '' && in_array($localUuid, $uuids, true) === false) {
            $uuids[] = $localUuid;
        }
        if ($uuids === [] && $localType === 'payment') {
            $uuids = [(string) $localUuid];
        }

        return $uuids;
    }

    /**
     * Retire every delete or void in one delivery. One link select, one invoice
     * status update, one link delete, and one pending update. A payment id is not
     * applied to an invoice, customer, or wallet that merely shares that id.
     *
     * @param array<int, RemoteDelete> $deletions
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
                foreach ($found as $invoiceId) {
                    $ids[] = (string) $invoiceId;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $invoiceId): bool => $invoiceId !== '')));
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
     * @param array<int, string> $relatedInvoiceUuids
     */
    private function applyRemoteDelete(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $quickbooksId,
        string $localUuid,
        array $relatedInvoiceUuids = [],
    ): void {
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
            $targets = $this->paymentInvoiceTargets(
                $companyUuid,
                $realmId,
                $quickbooksId,
                $localUuid,
                $relatedInvoiceUuids
            );
            foreach ($targets as $invoiceUuid) {
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
    private function paymentInvoiceTargets(
        string $companyUuid,
        string $realmId,
        string $quickbooksId,
        string $localUuid,
        array $relatedInvoiceUuids,
    ): array {
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
            if (
                $status === 'void'
                && in_array(strtolower($current), ['void', 'voided', 'cancelled', 'canceled'], true) === true
            ) {
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
     * @param class-string<Model> $class
     */
    private function invoiceTable(string $class, string $companyUuid, string $uuid): QueryBuilder
    {
        $model = new $class();

        return $model->getConnection()->table($model->getTable())
            ->where('company_uuid', $companyUuid)
            ->where('uuid', $uuid)
            ->whereNull('deleted_at');
    }

    /**
     * @param class-string<Model>  $class
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

    private function dropRemoteLinks(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $quickbooksId,
    ): void {
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
}
