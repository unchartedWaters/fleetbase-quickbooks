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
     * realm|payment id => true for a payment with no link at all, such as one made in QuickBooks.
     *
     * @var array<string, true>
     */
    public array $unlinked = [];

    /**
     * realm|payment id => Fleetbase invoices the payment paid before, but no longer pays in the
     * QuickBooks payment just read, each with its QuickBooks invoice id ('' when unlinked).
     *
     * @var array<string, array<string, string>>
     */
    public array $previous = [];

    /**
     * realm|payment id => the invoice its stored payment-invoice row should name now: the first
     * invoice the payment pays in QuickBooks, or '' when it pays none that Fleetbase links.
     * Only payments whose stored invoice they no longer pay are listed.
     *
     * @var array<string, string>
     */
    public array $staleStored = [];

    /**
     * One payment-link query, one invoice-link query, and one local-invoice query
     * for the delivery. A link whose local_uuid is the QuickBooks payment id is not
     * the Fleetbase invoice; the invoice id comes from the payment's lines.
     *
     * @see EnqueueWebhookSync::quickbooksInvoiceIdsForPayments()
     *
     * A payment made in QuickBooks has no link at all. With $readUnlinked its invoices are read
     * from QuickBooks like a keyed payment's; deletes never are (there is nothing to read).
     *
     * @param array<int, QuickBooksEntityChanged>                                                         $events
     * @param (\Closure(string, string, array<int, string>): array<string, array<int, string>|null>)|null $readInvoiceIds null defers keyed payments
     *
     * @return array<string, array{invoice: string, invoices: array<int, string>, keyed_by_payment: bool, quickbooks_invoices: array<int, string>}>
     */
    public function resolve(
        string $companyUuid,
        array $events,
        bool $allowDelete,
        ?\Closure $readInvoiceIds,
        bool $readUnlinked = false,
    ): array {
        $this->reads       = [];
        $this->unlinked    = [];
        $this->previous    = [];
        $this->staleStored = [];
        $idsByRealm        = $this->paymentIdsByRealm($events, $allowDelete);
        if ($idsByRealm === []) {
            return [];
        }

        $links            = new WebhookPaymentLinks();
        $byPayment        = $links->paymentLinksByKey($companyUuid, $idsByRealm);
        $unlinked         = $allowDelete === false && $readUnlinked === true;
        [$direct, $keyed] = $this->directAndKeyedPayments($idsByRealm, $byPayment, $unlinked);
        $remote           = new WebhookPaymentReads($readInvoiceIds);
        try {
            [$remoteInvoices, $missingByRealm] = $remote->remoteInvoicesForKeyed($companyUuid, $keyed, $allowDelete);
        } finally {
            $this->reads = $remote->reads;
            $this->deferred += $remote->deferred;
        }
        [$direct, $moved] = $this->directAfterReads($direct, $remote->deferred);
        $storedInvoice    = $allowDelete === true ? $links->storedPaymentInvoices($companyUuid, $missingByRealm) : [];
        // An update can move a payment off an invoice. The invoices it paid before are re-read.
        $stored     = $allowDelete === false ? $this->storedForReadPayments($links, $companyUuid, $idsByRealm) : [];
        $previous   = self::mergeLists($stored, $moved);
        $candidates = self::mergeLists($storedInvoice, $previous);
        $realms     = array_keys($idsByRealm);
        $linked     = $links->invoiceLinkIndex($companyUuid, $realms, $direct, $candidates, $remoteInvoices);
        $onFile     = $links->invoicesOnFile($companyUuid, $direct, $candidates, $linked['by_uuid']);
        $targets    = new WebhookPaymentTargets();
        $resolved   = $targets->resolvePaymentTargets($direct, $remoteInvoices, $storedInvoice, $linked, $onFile);

        $this->previous    = $targets->previousInvoices($previous, $resolved, $linked, $onFile);
        $this->staleStored = $targets->staleStoredInvoices($stored, $resolved);

        return $resolved;
    }

    /**
     * The invoices each payment was last known to pay, read from its links without reading
     * QuickBooks: invoices it is linked to straight, and its stored invoice. Only invoices
     * linked to QuickBooks are listed, each with its QuickBooks invoice id.
     *
     * @param array<int, QuickBooksEntityChanged> $events
     *
     * @return array<string, array<string, string>> realm|payment id => invoice uuid => QuickBooks id
     */
    public function lastKnownInvoices(string $companyUuid, array $events): array
    {
        $idsByRealm = $this->paymentIdsByRealm($events, true);
        if ($idsByRealm === []) {
            return [];
        }

        $links  = new WebhookPaymentLinks();
        $direct = [];
        foreach ($links->paymentLinksByKey($companyUuid, $idsByRealm) as $key => $locals) {
            $paymentId    = explode('|', (string) $key, 2)[1] ?? '';
            $direct[$key] = array_values(array_filter(
                $locals,
                static fn (string $uuid): bool => $uuid !== '' && $uuid !== $paymentId
            ));
        }
        $known  = self::mergeLists($direct, $links->storedPaymentInvoices($companyUuid, $idsByRealm));
        $linked = $links->invoiceLinkIndex($companyUuid, array_keys($idsByRealm), [], $known, []);

        return (new WebhookPaymentTargets())->previousInvoices($known, [], $linked, []);
    }

    /**
     * The stored invoice of each payment QuickBooks just returned. None is read when no
     * payment was read, as in the webhook request.
     *
     * @param array<string, array<int, string>> $idsByRealm
     *
     * @return array<string, array<int, string>>
     */
    private function storedForReadPayments(WebhookPaymentLinks $links, string $companyUuid, array $idsByRealm): array
    {
        $read = array_filter($this->reads, static fn (string $state): bool => $state === 'found');
        if ($read === []) {
            return [];
        }

        return array_intersect_key($links->storedPaymentInvoices($companyUuid, $idsByRealm), $read);
    }

    /**
     * A direct payment that is read from QuickBooks takes its invoices from that read; its
     * linked invoices it no longer pays are the moved ones. A deferred one is left to the job.
     * One whose read gave nothing keeps its linked invoices.
     *
     * @param array<string, array<int, string>> $direct
     * @param array<string, true>               $deferred
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function directAfterReads(array $direct, array $deferred): array
    {
        $moved = [];
        foreach ($direct as $key => $uuids) {
            if (isset($deferred[$key]) === true) {
                unset($direct[$key]);
            } elseif (($this->reads[$key] ?? '') === 'found') {
                $moved[$key] = $uuids;
                unset($direct[$key]);
            }
        }

        return [$direct, $moved];
    }

    /**
     * @param array<string, array<int, string>> $first
     * @param array<string, array<int, string>> $second
     *
     * @return array<string, array<int, string>>
     */
    private static function mergeLists(array $first, array $second): array
    {
        foreach ($second as $key => $uuids) {
            $first[$key] = array_values(array_unique(array_merge($first[$key] ?? [], $uuids)));
        }

        return $first;
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
    private function directAndKeyedPayments(array $idsByRealm, array $byPayment, bool $readUnlinked): array
    {
        // When payments take QuickBooks values, a payment linked straight to invoices is read
        // too: QuickBooks may have moved it to another invoice.
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
                }
                if (($invoices === [] && $locals !== []) || $readUnlinked === true) {
                    $keyed[$realmId][] = (string) $paymentId;
                }
                if ($locals === []) {
                    $this->unlinked[$key] = true;
                }
            }
        }

        return [$direct, $keyed];
    }
}
