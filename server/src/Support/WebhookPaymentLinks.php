<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Quickbooks\Models\Link;

/**
 * Link discovery for WebhookPaymentInvoices: the payment links, the stored payment-invoice
 * map, the invoice links and the local invoices a webhook delivery needs, each in one query
 * scoped to the company.
 */
class WebhookPaymentLinks
{
    /**
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array<string, array<int, string>>
     */
    public function paymentLinksByKey(string $companyUuid, array $idsByRealm): array
    {
        $paymentIds = [];
        foreach ($idsByRealm as $ids) {
            foreach ($ids as $paymentId) {
                $paymentIds[] = $paymentId;
            }
        }
        $paymentIds = array_values(array_unique($paymentIds));
        $byPayment  = [];
        foreach (Link::query()
            ->where('company_uuid', $companyUuid)
            ->where('qbo_entity', 'Payment')
            ->whereIn('realm_id', array_keys($idsByRealm))
            ->whereIn('qbo_id', $paymentIds)
            ->get(['realm_id', 'qbo_id', 'local_uuid']) as $link) {
            if (is_object($link) === false) {
                continue;
            }
            $key               = (string) $link->realm_id . '|' . (string) $link->qbo_id;
            $byPayment[$key][] = trim((string) $link->local_uuid);
        }

        return $byPayment;
    }

    /**
     * Invoice uuids remembered when the payment link was stored under the payment id.
     *
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array<string, array<int, string>> realm|payment id => invoice uuids
     */
    public function storedPaymentInvoices(string $companyUuid, array $idsByRealm): array
    {
        [$realms, $paymentIds] = $this->storedPaymentKeys($idsByRealm);
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
            $this->rememberStoredPaymentInvoice($link, $mapped);
        }

        return $mapped;
    }

    /**
     * The distinct non-empty realms and payment ids to look up.
     *
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function storedPaymentKeys(array $idsByRealm): array
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
        $paymentIds = array_values(array_unique(array_filter($paymentIds, static fn (string $paymentId): bool => $paymentId !== '')));

        return [$realms, $paymentIds];
    }

    /**
     * @param array<string, array<int, string>> $mapped
     */
    private function rememberStoredPaymentInvoice(mixed $link, array &$mapped): void
    {
        if (is_object($link) === false) {
            return;
        }
        $paymentId   = trim((string) $link->local_uuid);
        $invoiceUuid = trim((string) $link->qbo_id);
        $realm       = trim((string) $link->realm_id);
        if ($realm === '' || $paymentId === '' || $invoiceUuid === '' || $invoiceUuid === $paymentId) {
            return;
        }
        $key = $realm . '|' . $paymentId;
        if (isset($mapped[$key]) === false) {
            $mapped[$key] = [];
        }
        if (in_array($invoiceUuid, $mapped[$key], true) === false) {
            $mapped[$key][] = $invoiceUuid;
        }
    }

    /**
     * @param array<int, string>                $realmIds
     * @param array<string, array<int, string>> $direct
     * @param array<string, array<int, string>> $storedInvoice
     * @param array<string, array<int, string>> $remoteInvoices
     *
     * @return array{by_uuid: array<string, true>, by_qbo: array<string, string>}
     */
    public function invoiceLinkIndex(string $companyUuid, array $realmIds, array $direct, array $storedInvoice, array $remoteInvoices): array
    {
        $candidateUuids = $this->flattenedUuids($direct, $storedInvoice);
        $qboInvoiceIds  = $this->flattenedInvoiceIds($remoteInvoices);
        $linkedByUuid   = [];
        $uuidByQbo      = [];
        if ($candidateUuids === [] && $qboInvoiceIds === []) {
            return ['by_uuid' => $linkedByUuid, 'by_qbo' => $uuidByQbo];
        }

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
            $this->rememberInvoiceLink($link, $linkedByUuid, $uuidByQbo);
        }

        return ['by_uuid' => $linkedByUuid, 'by_qbo' => $uuidByQbo];
    }

    /**
     * @param array<string, true>   $linkedByUuid
     * @param array<string, string> $uuidByQbo
     */
    private function rememberInvoiceLink(mixed $link, array &$linkedByUuid, array &$uuidByQbo): void
    {
        if (is_object($link) === false) {
            return;
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

    /**
     * @param array<string, array<int, string>> $direct
     * @param array<string, array<int, string>> $storedInvoice
     *
     * @return array<int, string>
     */
    private function flattenedUuids(array $direct, array $storedInvoice): array
    {
        $candidateUuids = [];
        foreach ($direct as $uuids) {
            foreach ($uuids as $uuid) {
                $candidateUuids[] = $uuid;
            }
        }
        foreach ($storedInvoice as $uuids) {
            foreach ($uuids as $uuid) {
                $candidateUuids[] = $uuid;
            }
        }

        return array_values(array_unique($candidateUuids));
    }

    /**
     * @param array<string, array<int, string>> $remoteInvoices
     *
     * @return array<int, string>
     */
    private function flattenedInvoiceIds(array $remoteInvoices): array
    {
        $qboInvoiceIds = [];
        foreach ($remoteInvoices as $invoiceIds) {
            foreach ($invoiceIds as $invoiceId) {
                $qboInvoiceIds[] = $invoiceId;
            }
        }

        return array_values(array_unique($qboInvoiceIds));
    }

    /**
     * @param array<string, array<int, string>> $direct
     * @param array<string, array<int, string>> $storedInvoice
     * @param array<string, true>               $linkedByUuid
     *
     * @return array<string, true>
     */
    public function invoicesOnFile(string $companyUuid, array $direct, array $storedInvoice, array $linkedByUuid): array
    {
        $needFile = [];
        foreach ([$direct, $storedInvoice] as $groups) {
            foreach ($groups as $key => $uuids) {
                $realm = explode('|', $key, 2)[0];
                foreach ($uuids as $uuid) {
                    if (isset($linkedByUuid[$realm . '|' . $uuid]) === false) {
                        $needFile[] = $uuid;
                    }
                }
            }
        }
        $onFile = [];
        if ($needFile === [] || class_exists(Invoice::class) === false) {
            return $onFile;
        }
        $found = Invoice::query()
            ->where('company_uuid', $companyUuid)
            ->whereIn('uuid', array_values(array_unique($needFile)))
            ->pluck('uuid');
        foreach ($found as $uuid) {
            $onFile[(string) $uuid] = true;
        }

        return $onFile;
    }
}
