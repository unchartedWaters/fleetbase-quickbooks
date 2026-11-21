<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;

/**
 * The in-memory directory's version of QuickBooks deletes: the same retiring, voiding and
 * unpaying on its arrays. Split from AppliesRemoteDeletes to keep each file readable.
 */
trait AppliesMemoryDeletes
{
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
