<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Listeners\EnqueueWebhookSync;

/**
 * Remote reads for WebhookPaymentInvoices: reads the QuickBooks invoice ids of payments whose
 * invoices can only be known from QuickBooks, or defers them when no reader is given.
 */
class WebhookPaymentReads
{
    /**
     * realm|payment id => found when QuickBooks returned that payment, missing when the read was null.
     *
     * @var array<string, string>
     */
    public array $reads = [];

    /**
     * realm|payment id => true for a payment left to ResolveWebhookPayments.
     *
     * @var array<string, true>
     */
    public array $deferred = [];

    /**
     * Reads QuickBooks invoice ids for payments, or null to defer those payments instead.
     *
     * @see EnqueueWebhookSync::quickbooksInvoiceIdsForPayments()
     *
     * @param (\Closure(string, string, array<int, string>): array<string, array<int, string>|null>)|null $readInvoiceIds
     */
    public function __construct(private ?\Closure $readInvoiceIds)
    {
    }

    /**
     * @param array<string, array<int, string>> $keyed
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    public function remoteInvoicesForKeyed(string $companyUuid, array $keyed, bool $allowDelete): array
    {
        $remoteInvoices = [];
        $missingByRealm = [];
        foreach ($keyed as $realmId => $ids) {
            $this->readKeyedRealm($companyUuid, (string) $realmId, $ids, $allowDelete, $remoteInvoices, $missingByRealm);
        }

        return [$remoteInvoices, $missingByRealm];
    }

    /**
     * @param array<int, string>                $ids
     * @param array<string, array<int, string>> $remoteInvoices
     * @param array<string, array<int, string>> $missingByRealm
     */
    private function readKeyedRealm(string $companyUuid, string $realmId, array $ids, bool $allowDelete, array &$remoteInvoices, array &$missingByRealm): void
    {
        $ids = array_values(array_unique($ids));
        if ($this->readInvoiceIds === null) {
            foreach ($ids as $paymentId) {
                $this->deferred[$realmId . '|' . $paymentId] = true;
            }

            return;
        }

        $found = ($this->readInvoiceIds)($companyUuid, $realmId, $ids);
        foreach ($ids as $paymentId) {
            $this->rememberKeyedPayment($realmId, (string) $paymentId, $found, $allowDelete, $remoteInvoices, $missingByRealm);
        }
    }

    /**
     * @param array<string, array<int, string>|null> $found
     * @param array<string, array<int, string>>      $remoteInvoices
     * @param array<string, array<int, string>>      $missingByRealm
     */
    private function rememberKeyedPayment(string $realmId, string $paymentId, array $found, bool $allowDelete, array &$remoteInvoices, array &$missingByRealm): void
    {
        $key = $realmId . '|' . $paymentId;
        if (array_key_exists($paymentId, $found) === false) {
            return;
        }
        $invoiceIds = $found[$paymentId];
        if ($invoiceIds === null) {
            $this->reads[$key] = 'missing';
            if ($allowDelete === true) {
                $missingByRealm[$realmId][] = $paymentId;
            }

            return;
        }
        $this->reads[$key] = 'found';
        if (is_array($invoiceIds) === true && $invoiceIds !== []) {
            $remoteInvoices[$key] = $invoiceIds;
        }
    }
}
