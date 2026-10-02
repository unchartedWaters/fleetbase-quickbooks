<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\SyncSchedule;
use Fleetbase\Quickbooks\Support\SyncSuppressor;

class SyncFlagger
{
    public function flag(SyncLedger $ledger, string $companyUuid, string $localType, string $localUuid, string $reason, ?string $status = null): void
    {
        if (SyncSuppressor::paused()) {
            return;
        }
        if (!ConnectionGate::hasRealm($ledger->connection($companyUuid))) {
            return;
        }
        if ($localType === 'invoice' && ($status === null || $status === 'draft')) {
            return;
        }

        $ledger->upsertPending($companyUuid, $localType, $localUuid, $reason);
        SyncSchedule::wake();
    }

    public function fromInvoiceEvent(SyncLedger $ledger, object $invoice, string $reason): void
    {
        $companyUuid = (string) ($invoice->company_uuid ?? '');
        $uuid        = (string) ($invoice->uuid ?? '');
        $status      = isset($invoice->status) ? (string) $invoice->status : null;
        if ($companyUuid === '' || $uuid === '') {
            return;
        }

        $this->flag($ledger, $companyUuid, 'invoice', $uuid, $reason, $status);
    }

    public function fromCustomerEvent(SyncLedger $ledger, object $customer): void
    {
        $companyUuid = (string) ($customer->company_uuid ?? '');
        $uuid        = (string) ($customer->uuid ?? '');
        $type        = (string) ($customer->type ?? 'customer');
        if ($companyUuid === '' || $uuid === '' || $type !== 'customer') {
            return;
        }

        $this->flag($ledger, $companyUuid, 'customer', $uuid, 'saved');
    }

    public function fromWalletEvent(SyncLedger $ledger, object $wallet): void
    {
        $companyUuid = (string) ($wallet->company_uuid ?? '');
        $uuid        = (string) ($wallet->uuid ?? '');
        if ($companyUuid === '' || $uuid === '') {
            return;
        }

        $this->flag($ledger, $companyUuid, 'wallet', $uuid, 'saved');
    }
}
