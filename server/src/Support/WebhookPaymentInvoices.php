<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Events\QuickBooksEntityChanged;
use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;

/**
 * Finds the Fleetbase invoices that the payments in a webhook delivery apply to, for
 * EnqueueWebhookSync. One payment-link query, one invoice-link query and one local-invoice
 * query per delivery; a payment whose invoices can only be known from QuickBooks is read
 * there, or deferred when no reader is given. Link discovery is in WebhookPaymentLinks,
 * remote reads in WebhookPaymentReads and target resolution in WebhookPaymentTargets.
 */
class WebhookPaymentInvoices
{
    /**
     * realm|payment id => found when QuickBooks returned that payment, missing when the read was null.
     * A throw, reauth skip, realm mismatch, or batch fault leaves the key unset.
     *
     * @var array<string, string>
     */
    public array $reads = [];

    /**
     * realm|payment id => true for a payment whose invoices can only be known by reading it
     * from QuickBooks. The webhook request leaves it to ResolveWebhookPayments.
     *
     * @var array<string, true>
     */
    public array $deferred = [];

    /**
     * One payment-link query, one invoice-link query, and one local-invoice query
     * for the delivery. A link whose local_uuid is the QuickBooks payment id is not
     * the Fleetbase invoice; the invoice id comes from the payment's lines.
     *
     * @see EnqueueWebhookSync::quickbooksInvoiceIdsForPayments()
     *
     * @param array<int, QuickBooksEntityChanged>                                                         $events
     * @param (\Closure(string, string, array<int, string>): array<string, array<int, string>|null>)|null $readInvoiceIds null defers keyed payments
     *
     * @return array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    public function resolve(string $companyUuid, array $events, bool $allowDelete, ?\Closure $readInvoiceIds): array
    {
        $this->reads = [];
        $idsByRealm  = $this->paymentIdsByRealm($events, $allowDelete);
        if ($idsByRealm === []) {
            return [];
        }

        $links            = new WebhookPaymentLinks();
        $byPayment        = $links->paymentLinksByKey($companyUuid, $idsByRealm);
        [$direct, $keyed] = $this->directAndKeyedPayments($idsByRealm, $byPayment);
        $remote           = new WebhookPaymentReads($readInvoiceIds);
        try {
            [$remoteInvoices, $missingByRealm] = $remote->remoteInvoicesForKeyed($companyUuid, $keyed, $allowDelete);
        } finally {
            $this->reads = $remote->reads;
            $this->deferred += $remote->deferred;
        }
        $storedInvoice = $allowDelete === true ? $links->storedPaymentInvoices($companyUuid, $missingByRealm) : [];
        $linked        = $links->invoiceLinkIndex($companyUuid, array_keys($idsByRealm), $direct, $storedInvoice, $remoteInvoices);
        $onFile        = $links->invoicesOnFile($companyUuid, $direct, $storedInvoice, $linked['by_uuid']);

        return (new WebhookPaymentTargets())->resolvePaymentTargets($direct, $remoteInvoices, $storedInvoice, $linked, $onFile);
    }

    /**
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array<string, array<int, string>>
     */
    private function paymentIdsByRealm(array $events, bool $allowDelete): array
    {
        $idsByRealm = [];
        foreach ($events as $event) {
            if ($event->entityType !== 'payment' || ($allowDelete === false && $event->operation === 'delete')) {
                continue;
            }
            $idsByRealm[$event->realmId][] = $event->quickbooksId;
        }

        return $idsByRealm;
    }

    /**
     * @param array<string, array<int, string>> $idsByRealm
     * @param array<string, array<int, string>> $byPayment
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function directAndKeyedPayments(array $idsByRealm, array $byPayment): array
    {
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

        return [$direct, $keyed];
    }
}
