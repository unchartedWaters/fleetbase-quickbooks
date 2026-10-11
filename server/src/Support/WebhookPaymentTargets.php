<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Target resolution for WebhookPaymentInvoices: turns the discovered links and remote reads
 * into the Fleetbase invoices each payment applies to.
 */
class WebhookPaymentTargets
{
    /**
     * @param array<string, array<int, string>>                                  $direct
     * @param array<string, array<int, string>>                                  $remoteInvoices
     * @param array<string, array<int, string>>                                  $storedInvoice
     * @param array{by_uuid: array<string, true>, by_qbo: array<string, string>} $linked
     * @param array<string, true>                                                $onFile
     *
     * @return array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    public function resolvePaymentTargets(array $direct, array $remoteInvoices, array $storedInvoice, array $linked, array $onFile): array
    {
        $resolved = [];
        $this->resolveDirectTargets($direct, $linked['by_uuid'], $onFile, $resolved);
        $this->resolveRemoteTargets($remoteInvoices, $linked['by_qbo'], $resolved);
        $this->resolveStoredTargets($storedInvoice, $linked['by_uuid'], $onFile, $resolved);

        return $resolved;
    }

    /**
     * @param array<string, array<int, string>>                                                                                                    $direct
     * @param array<string, true>                                                                                                                  $linkedByUuid
     * @param array<string, true>                                                                                                                  $onFile
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     */
    private function resolveDirectTargets(array $direct, array $linkedByUuid, array $onFile, array &$resolved): void
    {
        foreach ($direct as $key => $uuids) {
            $realm = explode('|', $key, 2)[0];
            foreach ($uuids as $uuid) {
                if (isset($linkedByUuid[$realm . '|' . $uuid]) === true || isset($onFile[$uuid]) === true) {
                    $this->addResolvedInvoice($resolved, $key, $uuid, false, []);
                }
            }
        }
    }

    /**
     * @param array<string, array<int, string>>                                                                                                    $remoteInvoices
     * @param array<string, string>                                                                                                                $uuidByQbo
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     */
    private function resolveRemoteTargets(array $remoteInvoices, array $uuidByQbo, array &$resolved): void
    {
        foreach ($remoteInvoices as $key => $invoiceIds) {
            $realm = explode('|', $key, 2)[0];
            foreach ($invoiceIds as $invoiceId) {
                $uuid = $uuidByQbo[$realm . '|' . $invoiceId] ?? null;
                if (is_string($uuid) === false || $uuid === '') {
                    continue;
                }
                $this->addResolvedInvoice($resolved, $key, $uuid, true, $invoiceIds);
            }
        }
    }

    /**
     * @param array<string, array<int, string>>                                                                                                    $storedInvoice
     * @param array<string, true>                                                                                                                  $linkedByUuid
     * @param array<string, true>                                                                                                                  $onFile
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     */
    private function resolveStoredTargets(array $storedInvoice, array $linkedByUuid, array $onFile, array &$resolved): void
    {
        foreach ($storedInvoice as $key => $uuids) {
            if (isset($resolved[$key]) === true) {
                continue;
            }
            $realm = explode('|', $key, 2)[0];
            foreach ($uuids as $uuid) {
                if (isset($linkedByUuid[$realm . '|' . $uuid]) === true || isset($onFile[$uuid]) === true) {
                    $this->addResolvedInvoice($resolved, $key, $uuid, true, []);
                }
            }
        }
    }

    /**
     * Every Fleetbase invoice a resolved payment applies to. The first uuid stays
     * in invoice for callers that still read that field.
     *
     * @param array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}> $resolved
     * @param array<int, string>                                                                                                                   $quickbooksInvoices
     */
    private function addResolvedInvoice(array &$resolved, string $key, string $uuid, bool $keyedByPayment, array $quickbooksInvoices): void
    {
        if ($uuid === '') {
            return;
        }
        if (isset($resolved[$key]) === false) {
            $resolved[$key] = [
                'invoice'             => $uuid,
                'invoices'            => [],
                'keyed_by_payment'    => $keyedByPayment,
                'quickbooks_invoices' => $quickbooksInvoices,
            ];
        }
        if (in_array($uuid, $resolved[$key]['invoices'], true) === false) {
            $resolved[$key]['invoices'][] = $uuid;
        }
    }
}
